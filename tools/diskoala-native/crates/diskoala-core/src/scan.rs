//! 磁盘扫描：Top-N 文件（最小堆）与目录聚合（自底向上）。

use crate::{cancelled, CancelFlag, DirRecord, FileRecord, ProgressFn, ScanCancelled, ScanStats};
use std::cmp::Reverse;
use std::collections::BinaryHeap;
use std::path::{Path, PathBuf};

pub fn default_computer_scan_root() -> PathBuf {
    if cfg!(windows) {
        let drive = std::env::var("SystemDrive").unwrap_or_else(|_| "C:".into());
        let drive = drive.trim_end_matches(['\\', '/']);
        PathBuf::from(format!("{}\\", drive))
    } else {
        PathBuf::from("/")
    }
}

const DEFAULT_EXCLUDED_DIR_NAMES: &[&str] = &[
    // macOS / Unix
    ".Trash", ".Spotlight-V100", ".fseventsd", ".TemporaryItems", ".DocumentRevisions-V100", "Library",
    // 开发缓存（两平台共用）
    ".cache", ".npm", ".pnpm-store", ".yarn", ".bun", ".rustup", ".cargo", ".nvm", ".codex", ".gradle",
    "node_modules", ".git", "__pycache__", ".next", ".turbo",
    // Windows 系统与受管目录名
    "Windows", "WinSxS", "$Recycle.Bin", "System Volume Information", "Recovery", "PerfLogs", "Boot",
    "Documents and Settings", "Program Files", "Program Files (x86)", "ProgramData", "Config.Msi",
    "MSOCache", "Intel", "AMD", "NVIDIA", "WindowsApps",
];

fn system_root_prefixes() -> Vec<String> {
    if !cfg!(windows) {
        return ["/System", "/Library", "/Applications", "/private", "/Volumes", "/dev", "/bin", "/sbin", "/usr", "/opt", "/cores"]
            .iter().map(|s| s.to_string()).collect();
    }
    let root = default_computer_scan_root();
    ["Windows", "Program Files", "Program Files (x86)", "ProgramData", "$Recycle.Bin",
     "System Volume Information", "Recovery", "PerfLogs", "Boot", "Documents and Settings"]
        .iter().map(|p| root.join(p).to_string_lossy().to_string()).collect()
}

pub fn normalize_path_key(path: &Path) -> String {
    let text = path.to_string_lossy().replace('/', "\\");
    if cfg!(windows) {
        text.trim_end_matches('\\').to_lowercase()
    } else {
        text.trim_end_matches('/').to_string()
    }
}

pub fn path_matches_any_prefix(path: &Path, prefixes: &[String]) -> bool {
    let key = normalize_path_key(path);
    let sep = if cfg!(windows) { "\\" } else { "/" };
    for prefix in prefixes {
        let pk = normalize_path_key(Path::new(prefix));
        if pk.is_empty() {
            continue;
        }
        if key == pk || key.starts_with(&format!("{}{}", pk, sep)) {
            return true;
        }
    }
    false
}

fn should_skip_dir(path: &Path, root: &Path, include_all: bool) -> bool {
    if include_all || path == root {
        return false;
    }
    let root_is_computer = normalize_path_key(root) == normalize_path_key(&default_computer_scan_root());
    if root_is_computer && path_matches_any_prefix(path, &system_root_prefixes()) {
        return true;
    }
    let name = path.file_name().map(|n| n.to_string_lossy().to_string()).unwrap_or_default();
    if cfg!(windows) {
        DEFAULT_EXCLUDED_DIR_NAMES.iter().any(|ex| name.to_lowercase() == ex.to_lowercase())
    } else {
        DEFAULT_EXCLUDED_DIR_NAMES.contains(&name.as_str())
    }
}

/// Top-N 堆内排序：与 Python (size, mtime, sort_path) 对齐。
#[derive(Debug, Clone)]
struct HeapRec {
    size: u64,
    mtime: f64,
    sort_path: String,
    path: PathBuf,
}

impl PartialEq for HeapRec {
    fn eq(&self, other: &Self) -> bool {
        self.size == other.size && self.mtime == other.mtime && self.sort_path == other.sort_path
    }
}
impl Eq for HeapRec {}
impl PartialOrd for HeapRec {
    fn partial_cmp(&self, other: &Self) -> Option<std::cmp::Ordering> {
        Some(self.cmp(other))
    }
}
impl Ord for HeapRec {
    fn cmp(&self, other: &Self) -> std::cmp::Ordering {
        self.size.cmp(&other.size)
            .then_with(|| self.mtime.partial_cmp(&other.mtime).unwrap_or(std::cmp::Ordering::Equal))
            .then_with(|| self.sort_path.cmp(&other.sort_path))
    }
}

pub fn scan_top_files(
    root: &Path,
    limit: usize,
    include_all: bool,
    progress: Option<&ProgressFn<'_>>,
    cancel: Option<&CancelFlag>,
) -> Result<(Vec<FileRecord>, ScanStats), ScanCancelled> {
    let mut stats = ScanStats::default();
    let mut heap: BinaryHeap<Reverse<HeapRec>> = BinaryHeap::new();

    if root.is_file() {
        if let Ok(meta) = std::fs::symlink_metadata(root) {
            if !meta.file_type().is_symlink() {
                stats.root_size = meta.len();
                stats.root_file_count = 1;
                heap.push(Reverse(HeapRec {
                    size: meta.len(),
                    mtime: mtime_f64(&meta),
                    sort_path: root.to_string_lossy().to_string(),
                    path: root.to_path_buf(),
                }));
                stats.scanned_files = 1;
            }
        }
        return Ok((into_file_records(heap), stats));
    }

    let mut stack = vec![root.to_path_buf()];
    while let Some(current) = stack.pop() {
        cancelled(cancel)?;
        let rd = match std::fs::read_dir(&current) {
            Ok(rd) => rd,
            Err(_) => {
                stats.skipped_entries += 1;
                continue;
            }
        };
        stats.scanned_dirs += 1;
        for entry in rd.flatten() {
            let Ok(ft) = entry.file_type() else {
                stats.skipped_entries += 1;
                continue;
            };
            if ft.is_symlink() {
                stats.skipped_entries += 1;
                continue;
            }
            let path = entry.path();
            if ft.is_dir() {
                if should_skip_dir(&path, root, include_all) {
                    stats.skipped_dirs += 1;
                    continue;
                }
                stack.push(path);
            } else if ft.is_file() {
                match entry.metadata() {
                    Ok(meta) => {
                        let rec = HeapRec {
                            size: meta.len(),
                            mtime: mtime_f64(&meta),
                            sort_path: path.to_string_lossy().to_string(),
                            path,
                        };
                        if heap.len() < limit {
                            heap.push(Reverse(rec));
                        } else if let Some(Reverse(top)) = heap.peek() {
                            if rec > *top {
                                heap.pop();
                                heap.push(Reverse(rec));
                            }
                        }
                        stats.scanned_files += 1;
                    }
                    Err(_) => stats.skipped_entries += 1,
                }
            } else {
                stats.skipped_entries += 1;
            }
        }
        if let Some(cb) = progress {
            cb(&stats, "files");
        }
    }
    Ok((into_file_records(heap), stats))
}

fn into_file_records(heap: BinaryHeap<Reverse<HeapRec>>) -> Vec<FileRecord> {
    let mut records: Vec<FileRecord> = heap
        .into_iter()
        .map(|Reverse(rec)| FileRecord { size: rec.size, mtime: rec.mtime, path: rec.path })
        .collect();
    records.sort_by(|a, b| cmp_size_desc(a.size, a.mtime, &a.path, b.size, b.mtime, &b.path));
    records
}

fn cmp_size_desc(a_size: u64, a_mtime: f64, a_path: &Path, b_size: u64, b_mtime: f64, b_path: &Path) -> std::cmp::Ordering {
    b_size.cmp(&a_size)
        .then_with(|| b_mtime.partial_cmp(&a_mtime).unwrap_or(std::cmp::Ordering::Equal))
        .then_with(|| path_str(b_path).cmp(&path_str(a_path)))
}

fn path_str(p: &Path) -> String {
    p.to_string_lossy().to_string()
}

fn mtime_f64(meta: &std::fs::Metadata) -> f64 {
    meta.modified()
        .ok()
        .and_then(|t| t.duration_since(std::time::UNIX_EPOCH).ok())
        .map(|d| d.as_secs_f64())
        .unwrap_or(0.0)
}

struct DirNode {
    path: PathBuf,
    depth: usize,
    children: Vec<usize>,
    direct_size: u64,
    direct_mtime: f64,
    direct_files: u64,
    size: u64,
    mtime: f64,
    file_count: u64,
}

pub fn scan_top_dirs(
    root: &Path,
    limit: usize,
    include_all: bool,
    max_depth: Option<usize>,
    progress: Option<&ProgressFn<'_>>,
    cancel: Option<&CancelFlag>,
) -> Result<(Vec<DirRecord>, ScanStats), ScanCancelled> {
    let mut stats = ScanStats::default();
    let mut visit_order: Vec<usize> = Vec::new();
    let mut nodes: Vec<DirNode> = Vec::new();

    let mut stack = vec![(root.to_path_buf(), 0usize)];
    while let Some((current, depth)) = stack.pop() {
        cancelled(cancel)?;
        let node_idx = nodes.len();
        visit_order.push(node_idx);
        nodes.push(DirNode {
            path: current.clone(),
            depth,
            children: Vec::new(),
            direct_size: 0,
            direct_mtime: 0.0,
            direct_files: 0,
            size: 0,
            mtime: 0.0,
            file_count: 0,
        });

        let rd = match std::fs::read_dir(&current) {
            Ok(rd) => rd,
            Err(_) => {
                stats.skipped_entries += 1;
                if let Some(cb) = progress {
                    cb(&stats, "dirs");
                }
                continue;
            }
        };
        stats.scanned_dirs += 1;
        for entry in rd.flatten() {
            let Ok(ft) = entry.file_type() else {
                stats.skipped_entries += 1;
                continue;
            };
            if ft.is_symlink() {
                stats.skipped_entries += 1;
                continue;
            }
            let path = entry.path();
            if ft.is_dir() {
                if should_skip_dir(&path, root, include_all) {
                    stats.skipped_dirs += 1;
                    continue;
                }
                let child_idx = nodes.len();
                nodes[node_idx].children.push(child_idx);
                stack.push((path, depth + 1));
            } else if ft.is_file() {
                match entry.metadata() {
                    Ok(meta) => {
                        nodes[node_idx].direct_size += meta.len();
                        nodes[node_idx].direct_mtime = nodes[node_idx].direct_mtime.max(mtime_f64(&meta));
                        nodes[node_idx].direct_files += 1;
                        stats.scanned_files += 1;
                    }
                    Err(_) => stats.skipped_entries += 1,
                }
            } else {
                stats.skipped_entries += 1;
            }
        }
        if let Some(cb) = progress {
            cb(&stats, "dirs");
        }
    }

    // 自底向上聚合：visit_order 为出栈序，反序保证子先于父
    for &idx in visit_order.iter().rev() {
        let (mut size, mut mtime, mut files) = (nodes[idx].direct_size, nodes[idx].direct_mtime, nodes[idx].direct_files);
        for &child in &nodes[idx].children {
            size += nodes[child].size;
            mtime = mtime.max(nodes[child].mtime);
            files += nodes[child].file_count;
        }
        nodes[idx].size = size;
        nodes[idx].mtime = mtime;
        nodes[idx].file_count = files;
        if idx == 0 {
            stats.root_size = size;
            stats.root_file_count = files;
        }
    }

    // Top-N（与 Python 堆语义等价：深度过滤后按 size/mtime/path 取前 N）
    let mut candidates: Vec<&DirNode> = nodes
        .iter()
        .enumerate()
        .filter(|(idx, _)| *idx != 0)
        .filter(|(_, n)| max_depth.map_or(true, |md| n.depth <= md))
        .map(|(_, n)| n)
        .collect();
    candidates.sort_by(|a, b| {
        b.size.cmp(&a.size)
            .then_with(|| b.mtime.partial_cmp(&a.mtime).unwrap_or(std::cmp::Ordering::Equal))
            .then_with(|| path_str(&b.path).cmp(&path_str(&a.path)))
    });
    candidates.truncate(limit);

    let mut records: Vec<DirRecord> = candidates
        .into_iter()
        .map(|n| DirRecord { size: n.size, mtime: n.mtime, file_count: n.file_count, path: n.path.clone() })
        .collect();
    records.sort_by(|a, b| cmp_size_desc(a.size, a.mtime, &a.path, b.size, b.mtime, &b.path));
    Ok((records, stats))
}

/// explain 用：整个路径的汇总（大小/文件数/目录数/最新 mtime）。
pub struct PathSummary {
    pub size: u64,
    pub file_count: u64,
    pub dir_count: u64,
    pub mtime: f64,
}

pub fn scan_path_summary(root: &Path, include_all: bool) -> PathSummary {
    if root.is_file() {
        if let Ok(meta) = std::fs::symlink_metadata(root) {
            return PathSummary { size: meta.len(), file_count: 1, dir_count: 0, mtime: mtime_f64(&meta) };
        }
    }
    let mut summary = PathSummary { size: 0, file_count: 0, dir_count: 0, mtime: 0.0 };
    let mut stack = vec![root.to_path_buf()];
    while let Some(current) = stack.pop() {
        let Ok(rd) = std::fs::read_dir(&current) else { continue };
        summary.dir_count += 1;
        for entry in rd.flatten() {
            let Ok(ft) = entry.file_type() else { continue };
            if ft.is_symlink() {
                continue;
            }
            let path = entry.path();
            if ft.is_dir() {
                if should_skip_dir(&path, root, include_all) {
                    continue;
                }
                stack.push(path);
            } else if ft.is_file() {
                if let Ok(meta) = entry.metadata() {
                    summary.size += meta.len();
                    summary.file_count += 1;
                    summary.mtime = summary.mtime.max(mtime_f64(&meta));
                }
            }
        }
    }
    summary
}
