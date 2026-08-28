//! Diskoala 核心引擎：扫描、风险分类、回收方案、清理执行。
//! 与 Python 版（tools/yao-scai-cli）行为对齐；品牌常量为唯一事实来源的镜像。

pub mod ai;
pub mod classify;
pub mod format;
pub mod plan;
pub mod scan;
pub mod state;
pub mod trash;

use std::path::PathBuf;
use std::sync::atomic::AtomicBool;
use std::sync::Arc;
use std::time::Instant;

pub const APP_NAME: &str = "Diskoala";
pub const APP_NAME_ZH: &str = "磁盘考拉";
pub const APP_VERSION: &str = "2.0.0-alpha.1";
pub const APP_MAKER: &str = "Koding Studio";
pub const APP_HOMEPAGE: &str = "";

pub const DEFAULT_LIMIT: usize = 20;
pub const DEFAULT_BRIEF_LIMIT: usize = 50;
pub const DEFAULT_MORE_LIMIT: usize = 100;
pub const DEFAULT_ANALYSIS_LIMIT: usize = 80;

pub type CancelFlag = Arc<AtomicBool>;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Risk {
    Safe,
    Review,
    Risky,
}

impl Risk {
    pub fn key(&self) -> &'static str {
        match self {
            Risk::Safe => "safe",
            Risk::Review => "review",
            Risk::Risky => "risky",
        }
    }
    pub fn label(&self) -> &'static str {
        match self {
            Risk::Safe => "可安全关注",
            Risk::Review => "需要确认",
            Risk::Risky => "高风险",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Kind {
    File,
    Dir,
}

impl Kind {
    pub fn zh(&self) -> &'static str {
        match self {
            Kind::File => "文件",
            Kind::Dir => "文件夹",
        }
    }
}

#[derive(Debug, Clone)]
pub struct FileRecord {
    pub size: u64,
    pub mtime: f64,
    pub path: PathBuf,
}

#[derive(Debug, Clone)]
pub struct DirRecord {
    pub size: u64,
    pub mtime: f64,
    pub file_count: u64,
    pub path: PathBuf,
}

#[derive(Debug, Clone, Default)]
pub struct ScanStats {
    pub scanned_files: u64,
    pub scanned_dirs: u64,
    pub skipped_dirs: u64,
    pub skipped_entries: u64,
    pub root_size: u64,
    pub root_file_count: u64,
}

#[derive(Debug, Clone)]
pub struct Insight {
    pub path: PathBuf,
    pub size: u64,
    pub kind: Kind,
    pub risk: Risk,
    pub category: String,
    pub reason: String,
    pub action: String,
}

#[derive(Debug, Clone)]
pub struct Analysis {
    pub root: PathBuf,
    pub files: Vec<FileRecord>,
    pub dirs: Vec<DirRecord>,
    pub dir_stats: ScanStats,
    pub file_stats: ScanStats,
    pub elapsed: f64,
    pub insights: Vec<Insight>,
}

pub type ProgressFn<'a> = dyn Fn(&ScanStats, &'static str) + Send + Sync + 'a;

/// 扫描选项：进度回调（带 "dirs"/"files" 相位）与取消标志。
pub struct ScanOptions<'a> {
    pub progress: Option<&'a ProgressFn<'a>>,
    pub cancel: Option<CancelFlag>,
    pub parallel: bool,
}

impl Default for ScanOptions<'_> {
    fn default() -> Self {
        ScanOptions { progress: None, cancel: None, parallel: true }
    }
}

#[derive(Debug)]
pub struct ScanCancelled;

fn cancelled(cancel: Option<&CancelFlag>) -> Result<(), ScanCancelled> {
    if let Some(flag) = cancel {
        if flag.load(std::sync::atomic::Ordering::Relaxed) {
            return Err(ScanCancelled);
        }
    }
    Ok(())
}

/// 与 Python create_space_analysis 对齐：目录聚合（默认 max_depth=1）与文件 Top-N，可并行。
pub fn create_space_analysis(
    root: &std::path::Path,
    limit: usize,
    include_all: bool,
    max_depth: Option<usize>,
    options: ScanOptions<'_>,
) -> Result<Analysis, ScanCancelled> {
    let start = Instant::now();
    let dir_limit = limit.max(8);
    let file_limit = limit.max(DEFAULT_ANALYSIS_LIMIT);

    let run_dirs = || scan::scan_top_dirs(root, dir_limit, include_all, max_depth, options.progress, options.cancel.as_ref());
    let run_files = || scan::scan_top_files(root, file_limit, include_all, options.progress, options.cancel.as_ref());

    let (dirs, dir_stats, files, file_stats) = if options.parallel {
        let dirs_result: Option<Result<(Vec<DirRecord>, ScanStats), ScanCancelled>> = None;
        let files_result: Option<Result<(Vec<FileRecord>, ScanStats), ScanCancelled>> = None;
        let (dirs_slot, files_slot) = (std::sync::Mutex::new(dirs_result), std::sync::Mutex::new(files_result));
        std::thread::scope(|scope| {
            let hd = scope.spawn(|| {
                let value = run_dirs();
                *dirs_slot.lock().unwrap() = Some(value);
            });
            let hf = scope.spawn(|| {
                let value = run_files();
                *files_slot.lock().unwrap() = Some(value);
            });
            let _ = hd.join();
            let _ = hf.join();
        });
        match dirs_slot.into_inner().unwrap() {
            Some(Ok((dirs, ds))) => match files_slot.into_inner().unwrap() {
                Some(Ok((files, fs))) => (dirs, ds, files, fs),
                Some(Err(err)) => return Err(err),
                None => return Err(ScanCancelled),
            },
            Some(Err(err)) => return Err(err),
            None => return Err(ScanCancelled),
        }
    } else {
        let (dirs, ds) = run_dirs()?;
        let (files, fs) = run_files()?;
        (dirs, ds, files, fs)
    };

    let mut insights = classify::build_insights(&dirs, &files);
    insights.sort_by(|a, b| b.size.cmp(&a.size));

    Ok(Analysis {
        root: root.to_path_buf(),
        files,
        dirs,
        dir_stats,
        file_stats,
        elapsed: start.elapsed().as_secs_f64(),
        insights,
    })
}
