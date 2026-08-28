//! 风险分类规则引擎（与 Python classify_path 逐条对齐）。

use crate::scan::{default_computer_scan_root, normalize_path_key, path_matches_any_prefix};
use crate::{DirRecord, FileRecord, Insight, Kind, Risk};
use std::path::{Path, PathBuf};

const COMPRESSED_SUFFIXES: &[&str] = &["gz", "bz2", "xz", "zip", "zst"];
const ARCHIVE_SUFFIXES: &[&str] = &["7z", "bz2", "dmg", "gz", "iso", "rar", "tar", "tgz", "xz", "zip", "zst", "cab", "msix", "appx"];
const MEDIA_SUFFIXES: &[&str] = &["avi", "m4a", "m4v", "mov", "mp3", "mp4", "mpeg", "mpg", "wav", "webm", "mkv", "heic", "jpg", "jpeg", "png", "psd"];
const DOCUMENT_SUFFIXES: &[&str] = &["doc", "docx", "key", "numbers", "pages", "pdf", "ppt", "pptx", "xls", "xlsx"];
const DATA_SUFFIXES: &[&str] = &["csv", "db", "dump", "json", "parquet", "sqlite", "sql"];
const INSTALLER_SUFFIXES: &[&str] = &["msi", "msix", "appx", "exe", "msu", "cab"];
const VIRTUAL_DISK_SUFFIXES: &[&str] = &["vhdx", "vhd", "vmdk", "qcow2", "wim"];

const DEV_CACHE_NAMES: &[&str] = &[
    ".cache", ".gradle", ".next", ".nuxt", ".pytest_cache", ".ruff_cache", ".turbo", ".venv", "__pycache__",
    "build", "coverage", "dist", "node_modules", "target", "npm-cache", "pip", "pip-cache", "nuget",
    "nugetcache", "yarn", "pnpm-store", ".pnpm-store", "bower", "cypress", "electron", "gradle", "caches",
];
const SAFE_CACHE_DIR_NAMES: &[&str] = &[
    "temp", "tmp", "cache", "caches", "cacheddata", "code cache", "gpucache", "shadercache", "crashdumps",
    "temporary internet files", "inetcache", "webcache", "package cache", "deliveryoptimization",
];
const BACKUP_MARKERS: &[&str] = &["backup", "backups", "bak", "old", "archive", "archives", "备份", "归档", "windows.old"];
const DOWNLOAD_MARKERS: &[&str] = &["download", "downloads", "下载"];

fn risky_system_file_names() -> Vec<String> {
    if cfg!(windows) {
        ["pagefile.sys", "hiberfil.sys", "swapfile.sys", "dumpstack.log.tmp"]
            .iter().map(|s| s.to_string()).collect()
    } else {
        Vec::new()
    }
}

fn risky_system_prefixes() -> Vec<String> {
    if !cfg!(windows) {
        return ["/System", "/Library", "/Applications", "/dev", "/bin", "/sbin", "/usr", "/opt", "/cores"]
            .iter().map(|s| s.to_string()).collect();
    }
    let root = default_computer_scan_root();
    ["Windows", "Program Files", "Program Files (x86)", "ProgramData", "$Recycle.Bin",
     "System Volume Information", "Recovery", "PerfLogs", "Boot", "WindowsApps"]
        .iter().map(|p| root.join(p).to_string_lossy().to_string()).collect()
}

fn suffix_lower(path: &Path) -> String {
    path.extension().map(|e| e.to_string_lossy().to_lowercase()).unwrap_or_default()
}

fn name_lower(path: &Path) -> String {
    path.file_name().map(|n| n.to_string_lossy().to_lowercase()).unwrap_or_default()
}

fn path_parts_lower(path: &Path) -> Vec<String> {
    path.components().map(|c| c.as_os_str().to_string_lossy().to_lowercase()).collect()
}

fn path_lower(path: &Path) -> String {
    path.to_string_lossy().to_lowercase()
}

fn looks_like_backup(path: &Path) -> bool {
    let lowered = path_lower(path);
    let name = name_lower(path);
    BACKUP_MARKERS.iter().any(|m| lowered.contains(m)) || name.ends_with(".bak") || name.ends_with(".old") || name.ends_with(".backup")
}

fn looks_like_windows_temp_or_cache(path: &Path, parts: &[String]) -> bool {
    if !cfg!(windows) {
        return false;
    }
    let name = name_lower(path);
    if SAFE_CACHE_DIR_NAMES.contains(&name.as_str()) || parts.iter().any(|p| SAFE_CACHE_DIR_NAMES.contains(&p.as_str())) {
        return true;
    }
    let joined = path.to_string_lossy().replace('/', "\\").to_lowercase();
    let padded = format!("\\{}\\", joined.trim_matches('\\'));
    const MARKERS: &[&str] = &[
        "\\temp\\", "\\tmp\\", "\\cache\\", "\\caches\\", "\\code cache\\", "\\gpucache\\", "\\shadercache\\",
        "\\crashdumps\\", "\\inetcache\\", "\\webcache\\", "\\package cache\\", "\\deliveryoptimization\\",
        "\\temporary internet files\\",
    ];
    MARKERS.iter().any(|m| padded.contains(m))
}

fn looks_like_installer_package(path: &Path) -> bool {
    let suffix = suffix_lower(path);
    if INSTALLER_SUFFIXES.contains(&suffix.as_str()) {
        return true;
    }
    let name = name_lower(path);
    (name.ends_with(".exe") || name.ends_with(".msi"))
        && ["setup", "install", "installer", "update", "patch", "runtime", "redistributable"]
            .iter().any(|t| name.contains(t))
}

fn insight(path: PathBuf, size: u64, kind: Kind, risk: Risk, category: &str, reason: &str, action: &str) -> Insight {
    Insight {
        path,
        size,
        kind,
        risk,
        category: category.to_string(),
        reason: reason.to_string(),
        action: action.to_string(),
    }
}

/// 与 Python classify_path 相同的判定顺序与文案。
pub fn classify_path(path: &Path, size: u64, kind: Kind) -> Insight {
    let suffix = suffix_lower(path);
    let parts = path_parts_lower(path);
    let name = name_lower(path);
    let owned = path.to_path_buf();

    if risky_system_file_names().iter().any(|n| *n == name) {
        return insight(owned, size, kind, Risk::Risky, "系统虚拟内存/休眠文件",
            "pagefile/hiberfil/swapfile 由 Windows 管理，直接删除会导致系统异常。",
            "不要手动删除；如需释放空间，通过系统设置调整虚拟内存或关闭休眠。");
    }

    if path_matches_any_prefix(path, &risky_system_prefixes()) {
        return insight(owned, size, kind, Risk::Risky, "系统或受管目录",
            "路径位于系统、应用或受管区域，清理风险高。",
            "不要直接删除；只通过系统设置、应用自带卸载或磁盘清理工具管理。");
    }

    if parts.iter().any(|p| p == "windows.old") {
        return insight(owned, size, kind, Risk::Review, "Windows 旧系统残留",
            "Windows.old 是升级后的旧系统备份，通常很大，确认新系统稳定后可通过磁盘清理删除。",
            "确认当前 Windows 运行正常后，用「磁盘清理 → 以前的 Windows 安装」删除，不要手动乱删。");
    }

    if parts.iter().any(|p| DEV_CACHE_NAMES.contains(&p.as_str())) {
        return insight(owned, size, kind, Risk::Safe, "开发缓存/构建产物",
            "命中常见可重建目录，例如 node_modules、.next、dist、target 或缓存目录。",
            "确认项目不在运行后，可优先清理或通过包管理器重建。");
    }

    if looks_like_windows_temp_or_cache(path, &parts) {
        return insight(owned, size, kind, Risk::Safe, "临时文件/应用缓存",
            "命中 Temp、Cache、CrashDumps 等常见可清理区域。",
            "关闭相关应用后清理；优先用系统「磁盘清理」或应用内清理，避免删正在使用的文件。");
    }

    if looks_like_backup(path) {
        return insight(owned, size, kind, Risk::Review, "历史备份/归档",
            "名称看起来像备份、旧版本或归档文件。",
            "确认是否已有更新备份，再移动到回收站或外置存储。");
    }

    if VIRTUAL_DISK_SUFFIXES.contains(&suffix.as_str()) {
        return insight(owned, size, kind, Risk::Review, "虚拟磁盘/容器镜像",
            "VHDX/VHD 等通常是 WSL、虚拟机或应用沙箱磁盘，体积大且删错会丢环境。",
            "确认对应发行版/虚拟机是否还在用；WSL 可用 wsl --unregister，虚拟机请在管理器中删除。");
    }

    if looks_like_installer_package(path) || ARCHIVE_SUFFIXES.contains(&suffix.as_str()) {
        return insight(owned, size, kind, Risk::Review, "压缩包/安装包/镜像",
            "大压缩包、安装包或镜像通常是下载残留、安装介质或一次性传输文件。",
            "确认来源、是否已安装/解压使用，再决定是否清理。");
    }

    if MEDIA_SUFFIXES.contains(&suffix.as_str()) {
        return insight(owned, size, kind, Risk::Review, "大媒体文件",
            "图片、视频或音频文件通常体积大，但可能是个人素材。",
            "人工确认后归档到外置盘或云端，不建议自动删除。");
    }

    if DATA_SUFFIXES.contains(&suffix.as_str()) {
        return insight(owned, size, kind, Risk::Review, "数据/数据库文件",
            "数据文件、数据库或导出文件可能承载业务内容。",
            "确认是否可再生成或已备份，再处理。");
    }

    if DOCUMENT_SUFFIXES.contains(&suffix.as_str()) {
        return insight(owned, size, kind, Risk::Review, "文档资料",
            "文档可能包含人工产出或业务资料。",
            "人工确认价值后再归档或删除。");
    }

    if parts.iter().any(|p| DOWNLOAD_MARKERS.contains(&p.as_str())) {
        return insight(owned, size, kind, Risk::Review, "下载目录残留",
            "下载目录常见临时安装包、素材和传输文件。",
            "按文件名和修改时间确认是否仍需要。");
    }

    if cfg!(windows) && ["appdata", "local", "locallow", "roaming"].iter().any(|m| parts.iter().any(|p| p == m)) {
        return insight(owned, size, kind, Risk::Review, "应用数据(AppData)",
            "AppData 存放应用配置、缓存与本地数据，体积大但删错会导致应用重置或丢失数据。",
            "先确认所属应用；优先在应用内清理缓存，不要整目录删除。");
    }

    insight(owned, size, kind, Risk::Review, "未分类大项",
        "Diskoala 还不能可靠判断用途。",
        "先查看来源、修改时间和所属项目，再决定是否处理。")
}

pub fn build_insights(dirs: &[DirRecord], files: &[FileRecord]) -> Vec<Insight> {
    let mut insights: Vec<Insight> = dirs
        .iter()
        .map(|d| classify_path(&d.path, d.size, Kind::Dir))
        .chain(files.iter().map(|f| classify_path(&f.path, f.size, Kind::File)))
        .collect();
    insights.sort_by(|a, b| b.size.cmp(&a.size));
    insights
}

/// 按风险聚合：(category, total_size)，按大小降序。
pub fn aggregate_insights(insights: &[Insight], risk: Option<Risk>) -> Vec<(String, u64)> {
    let mut totals: std::collections::HashMap<String, u64> = std::collections::HashMap::new();
    for insight in insights {
        if let Some(r) = risk {
            if insight.risk != r {
                continue;
            }
        }
        *totals.entry(insight.category.clone()).or_insert(0) += insight.size;
    }
    let mut items: Vec<(String, u64)> = totals.into_iter().collect();
    items.sort_by(|a, b| b.1.cmp(&a.1));
    items
}

/// 与 Python infer_format 对齐（用于表格“格式”列）。
pub fn infer_format(path: &Path) -> String {
    let suffixes: Vec<String> = path
        .components()
        .last()
        .map(|c| {
            let name = c.as_os_str().to_string_lossy().to_string();
            name.split('.').skip(1).map(|s| s.to_lowercase()).collect::<Vec<_>>()
        })
        .unwrap_or_default();
    if suffixes.is_empty() {
        return "无扩展名".to_string();
    }
    if suffixes.len() >= 2 && COMPRESSED_SUFFIXES.contains(&suffixes[suffixes.len() - 1].as_str()) {
        return suffixes[suffixes.len() - 2..].join(".");
    }
    suffixes.last().unwrap().clone()
}

/// Windows 路径归一化比较键（父子关系判断用）。
pub fn norm_cmp_key(path: &Path) -> String {
    normalize_path_key(path)
}
