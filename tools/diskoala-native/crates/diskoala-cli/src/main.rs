//! Diskoala CLI（原生版）：与 Python 版命令、别名、输出格式对齐。

use diskoala_core::ai;
use diskoala_core::classify::{aggregate_insights, classify_path, infer_format};
use diskoala_core::format::{display_name, format_mtime, human_size, parse_size, truncate_middle};
use diskoala_core::scan::{default_computer_scan_root, scan_path_summary, scan_top_dirs, scan_top_files};
use diskoala_core::plan::select_plan_items;
use diskoala_core::{create_space_analysis, Analysis, Kind, Risk, ScanOptions, APP_MAKER, APP_NAME, APP_NAME_ZH, APP_VERSION, DEFAULT_BRIEF_LIMIT, DEFAULT_LIMIT, DEFAULT_MORE_LIMIT};
use std::path::{Path, PathBuf};
use std::process::exit;

fn main() {
    let args: Vec<String> = std::env::args().skip(1).collect();
    let code = run(&args);
    exit(code);
}

struct Options {
    command: String,
    root: PathBuf,
    limit: usize,
    include_all: bool,
    computer: bool,
    max_depth: Option<usize>,
    timeout: u64,
    target: Option<String>,
    explain_path: Option<PathBuf>,
}

fn resolve_root(explicit: Option<&str>, computer: bool) -> PathBuf {
    if computer {
        return default_computer_scan_root();
    }
    match explicit {
        Some(p) => shellexpand_tilde(p),
        None => std::env::current_dir().unwrap_or_else(|_| PathBuf::from(".")),
    }
}

fn shellexpand_tilde(p: &str) -> PathBuf {
    if p == "~" || p.starts_with("~/") || p.starts_with("~\\") {
        let home = diskoala_core::format::home_dir();
        return home.join(p.trim_start_matches('~').trim_start_matches(['/', '\\']));
    }
    PathBuf::from(p)
}

const COMMAND_ALIASES: &[(&str, &str)] = &[
    ("brief", "brief"), ("b", "brief"),
    ("top", "top"), ("file", "top"), ("files", "top"), ("f", "top"),
    ("dir", "dirs"), ("dirs", "dirs"), ("d", "dirs"),
    ("explain", "explain"), ("why", "explain"), ("x", "explain"),
    ("plan", "plan"), ("p", "plan"),
    ("more", "more"), ("m", "more"),
    ("ai", "ai"),
    ("gui", "gui"), ("g", "gui"),
    ("tui", "tui"), ("t", "tui"), ("ui", "tui"),
];

const COMPUTER_ROOT_ALIASES: &[&str] = &[
    "all", "c", "computer", "mac", "root", "全盘", "电脑", "根目录", "c:", "c:\\", "c:/", "c盘", "c 盘", "系统盘",
];

fn usage() -> String {
    format!(
        "Diskoala ({APP_NAME_ZH}) - CLI 磁盘空间扫描与清理顾问 v{APP_VERSION}\n\
\n用法: diskoala <命令> [路径] [选项]\n\
\n命令:\n  brief [PATH]     空间简报（默认）\n  top [N] [PATH]   最大文件\n  more [N] [PATH]  更多最大文件（默认 Top {more}）\n  dirs [PATH]      最大文件夹\n  explain PATH     解释单个文件/目录\n  plan TARGET [PATH] 生成释放空间方案\n  ai [PATH]        Codex AI 诊断\n  gui              打开图形界面（diskoala-gui）\n\
\n选项:\n  --limit N        输出前 N 条\n  --max-depth N    目录层级上限\n  --all            不跳过默认排除目录（注意: diskoala all 是全盘安全扫描，含义相反）\n  --computer       从电脑根目录安全扫描\n  --timeout N      AI 超时秒数（默认 180）\n  --version        版本\n  -h, --help       帮助\n\
\n旧命令 scai / bf / scan 为兼容别名（Python 版维护线）。\n",
        more = DEFAULT_MORE_LIMIT,
    )
}

fn parse_args(args: &[String]) -> Options {
    let mut command = String::from("brief");
    let mut root_explicit: Option<String> = None;
    let mut limit: Option<usize> = None;
    let mut include_all = false;
    let mut computer = false;
    let mut max_depth: Option<usize> = None;
    let mut timeout = 180u64;
    let mut target: Option<String> = None;
    let mut explain_path: Option<PathBuf> = None;

    let mut positional: Vec<&String> = Vec::new();
    let mut i = 0usize;
    while i < args.len() {
        let arg = args[i].as_str();
        let lowered = arg.to_lowercase();
        match lowered.as_str() {
            "-h" | "--help" | "help" | "h" if positional.is_empty() && args.len() == 1 => {
                print!("{}", usage());
                exit(0);
            }
            "-v" | "--version" | "version" if args.len() == 1 => {
                println!("{APP_NAME} {APP_VERSION} (by {APP_MAKER})");
                exit(0);
            }
            "--limit" | "--max-depth" | "--timeout" => {
                let value = args.get(i + 1).and_then(|v| v.parse::<u64>().ok());
                match lowered.as_str() {
                    "--limit" => limit = value.map(|v| v as usize),
                    "--max-depth" => max_depth = value.map(|v| v as usize),
                    _ => {
                        if let Some(v) = value {
                            timeout = v;
                        }
                    }
                }
                i += 2;
                continue;
            }
            "--all" => include_all = true,
            "--computer" => computer = true,
            "--plain" | "--tui" => {}
            _ => positional.push(&args[i]),
        }
        i += 1;
    }

    // 第一个位置参数解析为命令（或数字 limit / 全盘别名 / 路径）
    let mut rest = positional.as_slice();
    if let Some(first) = rest.first() {
        let lowered = first.to_lowercase();
        if args.len() == 1 && (lowered == "-h" || lowered == "--help" || lowered == "help") {
            print!("{}", usage());
            exit(0);
        }
        if let Some(cmd) = COMMAND_ALIASES.iter().find(|(alias, _)| *alias == lowered).map(|(_, cmd)| cmd.to_string()) {
            command = cmd;
            rest = &rest[1..];
        } else if COMPUTER_ROOT_ALIASES.contains(&lowered.as_str()) {
            computer = true;
            rest = &rest[1..];
        } else if first.chars().all(|c| c.is_ascii_digit()) && !first.is_empty() {
            if let Ok(n) = first.parse::<usize>() {
                limit = Some(n);
            }
            rest = &rest[1..];
        }
    }

    // 剩余位置参数
    let mut leftovers: Vec<&String> = rest.to_vec();
    if command == "plan" {
        if let Some(t) = leftovers.first() {
            target = Some(t.to_string());
            leftovers.remove(0);
        }
    } else if command == "explain" {
        if let Some(p) = leftovers.first() {
            explain_path = Some(shellexpand_tilde(p));
            leftovers.remove(0);
        }
    }
    if let Some(p) = leftovers.first() {
        root_explicit = Some(p.to_string());
    }

    let default_limit = match command.as_str() {
        "brief" => DEFAULT_BRIEF_LIMIT,
        "more" => DEFAULT_MORE_LIMIT,
        _ => DEFAULT_LIMIT,
    };
    Options {
        command,
        root: resolve_root(root_explicit.as_deref(), computer),
        limit: limit.unwrap_or(default_limit).max(1),
        include_all,
        computer,
        max_depth,
        timeout,
        target,
        explain_path,
    }
}

fn run(args: &[String]) -> i32 {
    if args.is_empty() {
        print!("{}", usage());
        return 0;
    }
    let opts = parse_args(args);
    if !opts.computer && !opts.root.exists() {
        eprintln!("错误: 路径不存在: {}", opts.root.display());
        return 2;
    }
    match opts.command.as_str() {
        "brief" => cmd_brief(&opts),
        "top" | "more" => cmd_top(&opts),
        "dirs" => cmd_dirs(&opts),
        "explain" => cmd_explain(&opts),
        "plan" => cmd_plan(&opts),
        "ai" => cmd_ai(&opts),
        "gui" => {
            println!("图形界面请运行 diskoala-gui（Tauri 版）或 Python 版: python scai.py gui");
            0
        }
        "tui" => {
            println!("原生版暂未内置 TUI；请使用 CLI 子命令或图形界面。");
            0
        }
        _ => {
            eprintln!("未知命令: {}", opts.command);
            2
        }
    }
}

fn analysis(opts: &Options, max_depth: Option<usize>) -> Analysis {
    match create_space_analysis(&opts.root, opts.limit, opts.include_all, max_depth, ScanOptions::default()) {
        Ok(analysis) => analysis,
        Err(_) => {
            eprintln!("扫描已取消");
            exit(1);
        }
    }
}

fn cmd_brief(opts: &Options) -> i32 {
    let a = analysis(opts, Some(1));
    println!("Diskoala {APP_VERSION} 空间简报");
    println!();
    println!("扫描范围: {}", a.root.display());
    println!("扫描用时: {:.2}s", a.elapsed);
    println!(
        "统计信息: 目录 {} 个, 文件 {} 个, 跳过目录 {} 个",
        a.dir_stats.scanned_dirs, a.file_stats.scanned_files,
        a.dir_stats.skipped_dirs + a.file_stats.skipped_dirs
    );
    println!();

    println!("主要占用:");
    let primary: Vec<(String, u64)> = if !a.dirs.is_empty() {
        a.dirs.iter().map(|d| (display_name(&d.path, &a.root), d.size)).collect()
    } else {
        a.files.iter().map(|f| (display_name(&f.path, &a.root), f.size)).collect()
    };
    if primary.is_empty() {
        println!("  暂无记录");
    }
    for (index, (name, size)) in primary.iter().take(5).enumerate() {
        println!("  {}. {:<44} {:>10}", index + 1, truncate_middle(name, 44), human_size(*size));
    }
    println!();

    println!("可安全关注:");
    print_aggregate(&aggregate_insights(&a.insights, Some(Risk::Safe)), "暂未发现明显可重建缓存或构建产物");
    println!();
    println!("需要确认:");
    print_aggregate(&aggregate_insights(&a.insights, Some(Risk::Review)), "暂未发现需要人工确认的大项");
    println!();

    let shown = a.files.len().min(opts.limit);
    println!("Top {} 文件明细:", shown);
    if a.files.is_empty() {
        println!("  暂无文件记录");
    } else {
        println!("  {:>4}  {:>10}  {:<8}  {:<14}  文件", "编号", "大小", "风险", "分类");
        let width = 78;
        println!("{}", "-".repeat(60 + width.min(78)));
        for (index, record) in a.files.iter().take(opts.limit).enumerate() {
            let insight = classify_path(&record.path, record.size, Kind::File);
            println!(
                "  {:>4}  {:>10}  {:<8}  {:<14}  {}",
                index + 1,
                human_size(record.size),
                truncate_middle(insight.risk.label(), 8),
                truncate_middle(&insight.category, 14),
                truncate_middle(&display_name(&record.path, &a.root), 60)
            );
        }
    }
    println!();
    let risky: Vec<_> = a.insights.iter().filter(|i| i.risk == Risk::Risky).take(3).collect();
    if !risky.is_empty() {
        println!("高风险项:");
        for item in risky {
            println!("  - {}: {}", truncate_middle(&display_name(&item.path, &a.root), 44), item.reason);
        }
        println!();
    }
    println!("下一步:");
    println!("  - diskoala top          查看最大文件");
    println!("  - diskoala dirs         查看最大文件夹");
    println!("  - diskoala plan 20g     生成释放空间方案");
    println!("  - diskoala ai           生成 AI 诊断");
    println!("  - diskoala gui          打开图形界面安全清理");
    0
}

fn print_aggregate(items: &[(String, u64)], empty_text: &str) {
    if items.is_empty() {
        println!("  - {empty_text}");
        return;
    }
    for (category, size) in items.iter().take(5) {
        println!("  - {}: 约 {}", category, human_size(*size));
    }
}

fn cmd_top(opts: &Options) -> i32 {
    let (records, stats) = scan_top_files(&opts.root, opts.limit, opts.include_all, None, None).unwrap_or_default();
    print_scan_summary(&opts.root, &stats, opts.include_all);
    if records.is_empty() {
        println!("没有找到可展示的文件。");
        return 0;
    }
    // 与 Python 非交互终端列宽公式一致（fixed 54 / terminal 120 → 66）
    let name_width = 66usize;
    let header = format!(
        "{:>4}  {:<width$}  {:<8}  {:>10}  {:<19}",
        "编号", "文件名", "格式", "大小", "最近修改时间", width = name_width
    );
    println!("{}", header);
    println!("{}", "-".repeat(header.chars().count()));
    for (index, record) in records.iter().enumerate() {
        println!(
            "{:>4}  {:<width$}  {:<8}  {:>10}  {}",
            index + 1,
            truncate_middle(&display_name(&record.path, &opts.root), name_width),
            truncate_middle(&infer_format(&record.path), 8),
            human_size(record.size),
            format_mtime(record.mtime),
            width = name_width
        );
    }
    0
}

fn cmd_dirs(opts: &Options) -> i32 {
    let (records, stats) = scan_top_dirs(&opts.root, opts.limit, opts.include_all, opts.max_depth, None, None).unwrap_or_default();
    print_scan_summary(&opts.root, &stats, opts.include_all);
    if records.is_empty() {
        println!("没有找到可展示的文件夹。");
        return 0;
    }
    // Python 公式：fixed 66 / terminal 130 → 64
    let name_width = 64usize;
    let header = format!(
        "{:>4}  {:<width$}  {:>10}  {:>6}  {:<19}",
        "编号", "文件夹", "总大小", "文件数", "最近修改时间", width = name_width
    );
    println!("{}", header);
    println!("{}", "-".repeat(header.chars().count()));
    for (index, record) in records.iter().enumerate() {
        println!(
            "{:>4}  {:<width$}  {:>10}  {:>6}  {}",
            index + 1,
            truncate_middle(&display_name(&record.path, &opts.root), name_width),
            human_size(record.size),
            record.file_count,
            format_mtime(record.mtime),
            width = name_width
        );
    }
    0
}

fn print_scan_summary(root: &Path, stats: &diskoala_core::ScanStats, _include_all: bool) {
    println!("扫描范围: {}", root.display());
    println!(
        "统计信息: 目录 {} 个, 文件 {} 个, 跳过目录 {} 个, 其他跳过 {} 个",
        stats.scanned_dirs, stats.scanned_files, stats.skipped_dirs, stats.skipped_entries
    );
    println!();
}

fn cmd_explain(opts: &Options) -> i32 {
    let Some(path) = opts.explain_path.clone() else {
        eprintln!("explain 需要一个路径参数");
        return 2;
    };
    if !path.exists() {
        eprintln!("错误: 路径不存在: {}", path.display());
        return 2;
    }
    let is_dir = path.is_dir();
    let summary = scan_path_summary(&path, opts.include_all);
    let insight = classify_path(&path, summary.size, if is_dir { Kind::Dir } else { Kind::File });
    println!("Diskoala Explain");
    println!();
    println!("路径: {}", insight.path.display());
    println!("类型: {}", if is_dir { "文件夹" } else { "文件" });
    println!("大小: {}", human_size(insight.size));
    if is_dir {
        println!("内容: 目录 {} 个, 文件 {} 个", summary.dir_count, summary.file_count);
    }
    println!("风险: {}", insight.risk.label());
    println!("分类: {}", insight.category);
    println!("判断: {}", insight.reason);
    println!("建议: {}", insight.action);
    0
}

fn cmd_plan(opts: &Options) -> i32 {
    let Some(target_text) = opts.target.clone() else {
        eprintln!("plan 需要目标大小参数，例如: diskoala plan 20g");
        return 2;
    };
    let Ok(target) = parse_size(&target_text) else {
        eprintln!("目标大小无效: {}", target_text);
        return 2;
    };
    let a = analysis(opts, None);
    let (selected, total): (Vec<_>, u64) = select_plan_items(&a.insights, target, &a.root);
    println!("Diskoala 回收方案: {}", human_size(target));
    println!();
    println!("扫描范围: {}", a.root.display());
    println!("模式: 只生成计划，不删除任何文件。");
    println!();
    if selected.is_empty() {
        println!("没有找到可用于生成计划的候选项。");
        return 0;
    }
    for (index, item) in selected.iter().enumerate() {
        println!(
            "{}. [{}] {:>10}  {}",
            index + 1,
            item.risk.label(),
            human_size(item.size),
            truncate_middle(&display_name(&item.path, &a.root), 58)
        );
        println!("   分类: {}", item.category);
        println!("   原因: {}", item.reason);
        println!("   建议: {}", item.action);
    }
    println!();
    println!("预计可处理空间: {}", human_size(total));
    if total < target {
        println!("提示: 当前候选项不足以达到目标，可以扩大扫描范围或使用 --all。");
    }
    let trash_name = if cfg!(windows) { "回收站" } else { "废纸篓" };
    println!("安全策略: 默认移动到{trash_name}并记录操作日志；界面内可选永久删除。本命令只生成方案，不删除任何文件。");
    0
}

fn cmd_ai(opts: &Options) -> i32 {
    let a = analysis(opts, Some(1));
    let prompt = ai::build_ai_prompt(&a);
    let (status, message) = ai::invoke_codex_diagnosis(&prompt, opts.timeout);
    match status.as_str() {
        "ok" => {
            println!("{}", ai::render_markdown_plain(&message));
            0
        }
        "missing" => {
            println!("未找到 codex CLI。先输出本地规则分析摘要:");
            println!();
            cmd_brief(opts)
        }
        "timeout" => {
            eprintln!("Codex AI 分析超时，下面是本地规则分析摘要。");
            println!();
            cmd_brief(opts)
        }
        _ => {
            eprintln!("Codex AI 分析失败，下面是本地规则分析摘要。");
            if !message.is_empty() {
                eprintln!("{}", message);
            }
            println!();
            cmd_brief(opts)
        }
    }
}
