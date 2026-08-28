//! 回收方案：父子去重、过粗目录过滤、safe 优先选取。

use crate::classify::norm_cmp_key;
use crate::Insight;
use std::path::Path;

/// 与 Python paths_overlap 对齐（Windows 大小写不敏感）。
pub fn paths_overlap(left: &Path, right: &Path) -> bool {
    let a = norm_cmp_key(left);
    let b = norm_cmp_key(right);
    let sep = if cfg!(windows) { '\\' } else { '/' };
    let a2 = format!("{}{}", a, sep);
    let b2 = format!("{}{}", b, sep);
    a2.starts_with(&b2) || b2.starts_with(&a2) || a == b
}

/// 过滤过粗目录，避免方案建议删整个 AppData/用户目录。
pub fn is_too_coarse_for_plan(path: &Path, root: &Path) -> bool {
    let rel = path.strip_prefix(root).map(|r| r.to_path_buf()).unwrap_or_else(|_| path.to_path_buf());
    let parts: Vec<String> = rel
        .components()
        .map(|c| c.as_os_str().to_string_lossy().to_string())
        .collect();
    if parts.is_empty() {
        return true;
    }
    if parts.len() <= 1 {
        return true;
    }
    if cfg!(windows) {
        let lower: Vec<String> = parts.iter().map(|p| p.to_lowercase()).collect();
        if lower[0] == "appdata" && lower.len() <= 2 {
            return true;
        }
        if (lower[0] == "local settings" || lower[0] == "application data") && lower.len() <= 2 {
            return true;
        }
    }
    false
}

/// safe 优先 → 按大小降序，父子去重，凑够 target 为止。返回 (选中, 累计)。
pub fn select_plan_items<'a>(insights: &'a [Insight], target_bytes: u64, root: &Path) -> (Vec<&'a Insight>, u64) {
    let mut candidates: Vec<&Insight> = insights
        .iter()
        .filter(|item| item.risk != crate::Risk::Risky)
        .filter(|item| !is_too_coarse_for_plan(&item.path, root))
        .collect();
    candidates.sort_by(|a, b| {
        let ra = if a.risk == crate::Risk::Safe { 0 } else { 1 };
        let rb = if b.risk == crate::Risk::Safe { 0 } else { 1 };
        ra.cmp(&rb).then_with(|| b.size.cmp(&a.size))
    });

    let mut selected: Vec<&Insight> = Vec::new();
    let mut total = 0u64;
    for item in candidates {
        if selected.iter().any(|s| paths_overlap(&item.path, &s.path)) {
            continue;
        }
        let size = item.size;
        selected.push(item);
        total += size;
        if total >= target_bytes {
            break;
        }
    }
    (selected, total)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{Insight, Kind, Risk};
    use std::path::PathBuf;

    fn mk(path: &str, size: u64, risk: Risk) -> Insight {
        Insight {
            path: PathBuf::from(path),
            size,
            kind: Kind::File,
            risk,
            category: "test".into(),
            reason: String::new(),
            action: String::new(),
        }
    }

    #[test]
    fn overlap_dedupe() {
        let root = Path::new("C:\\base");
        let items = vec![
            mk("C:\\base\\x\\big", 1000, Risk::Safe),
            mk("C:\\base\\x\\big\\nested.bin", 600, Risk::Safe),
            mk("C:\\base\\y\\small.bin", 300, Risk::Safe),
        ];
        let (selected, total) = select_plan_items(&items, 1200, root);
        assert_eq!(selected.len(), 2);
        assert_eq!(total, 1300);
    }

    #[test]
    fn root_level_items_are_too_coarse() {
        // 与 Python 一致：扫描根下一级整体太粗，不进方案
        let root = Path::new("C:\\base");
        let items = vec![mk("C:\\base\\bigdir", 5000, Risk::Safe)];
        let (selected, _total) = select_plan_items(&items, 1000, root);
        assert!(selected.is_empty());
    }

    #[test]
    fn parse_size_units() {
        assert_eq!(crate::format::parse_size("20g").unwrap(), 20 * 1024u64.pow(3));
        assert_eq!(crate::format::parse_size("500m").unwrap(), 500 * 1024u64.pow(2));
        assert_eq!(crate::format::parse_size("10").unwrap(), 10);
        assert!(crate::format::parse_size("abc").is_err());
    }

    #[test]
    fn human_size_format() {
        assert_eq!(crate::format::human_size(0), "0 B");
        assert_eq!(crate::format::human_size(1024), "1.0 KB");
        assert_eq!(crate::format::human_size(1024u64 * 1024 * 97), "97.0 MB");
    }
}
