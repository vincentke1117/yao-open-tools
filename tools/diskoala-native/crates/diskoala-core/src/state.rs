//! 状态与审计日志：~/.diskoala/{gui-state.json, cleanup-log.jsonl}，自动迁移旧 ~/.sca。

use crate::format::home_dir;
use serde_json::{json, Value};
use std::path::PathBuf;

pub fn data_dir() -> PathBuf {
    home_dir().join(".diskoala")
}

pub fn legacy_data_dir() -> PathBuf {
    home_dir().join(".scai")
}

pub fn log_file() -> PathBuf {
    data_dir().join("cleanup-log.jsonl")
}

pub fn state_file() -> PathBuf {
    data_dir().join("gui-state.json")
}

/// 创建数据目录并做一次性迁移（旧 ~/.scai 的日志与状态）。
pub fn ensure_data_dir() {
    let dir = data_dir();
    if std::fs::create_dir_all(&dir).is_err() {
        return;
    }
    for name in ["cleanup-log.jsonl", "gui-state.json"] {
        let src = legacy_data_dir().join(name);
        let dst = dir.join(name);
        if src.is_file() && !dst.exists() {
            let _ = std::fs::copy(&src, &dst);
        }
    }
}

pub fn load_state() -> Value {
    ensure_data_dir();
    std::fs::read_to_string(state_file())
        .ok()
        .and_then(|text| serde_json::from_str(&text).ok())
        .unwrap_or_else(|| json!({}))
}

pub fn save_state(patch: &Value) {
    let mut state = load_state();
    if let (Some(map), Some(patch_map)) = (state.as_object_mut(), patch.as_object()) {
        for (key, value) in patch_map {
            map.insert(key.clone(), value.clone());
        }
    }
    let _ = std::fs::write(state_file(), serde_json::to_string(&state).unwrap_or_default());
}

pub fn save_last_root(root: &std::path::Path) {
    save_state(&json!({ "last_root": root.to_string_lossy() }));
}

pub fn load_last_root() -> Option<PathBuf> {
    let root = load_state().get("last_root")?.as_str()?.to_string();
    let path = PathBuf::from(&root);
    if path.is_dir() {
        Some(path)
    } else {
        None
    }
}

/// 追加清理审计日志（每行一个 JSON）。
pub fn append_cleanup_log(records: &[Value]) {
    use std::io::Write;
    ensure_data_dir();
    let Ok(mut handle) = std::fs::OpenOptions::new().create(true).append(true).open(log_file()) else {
        return;
    };
    for record in records {
        let mut entry = json!({ "time": now_local(), "action": "move_to_trash" });
        if let (Some(map), Some(rec)) = (entry.as_object_mut(), record.as_object()) {
            for (key, value) in rec {
                map.insert(key.clone(), value.clone());
            }
        }
        let _ = writeln!(handle, "{}", serde_json::to_string(&entry).unwrap_or_default());
    }
}

/// 读取最近 limit 条日志（新→旧）。
pub fn read_log(limit: usize) -> Vec<Value> {
    let text = std::fs::read_to_string(log_file()).unwrap_or_default();
    let mut entries = Vec::new();
    for line in text.lines().rev() {
        let line = line.trim();
        if line.is_empty() {
            continue;
        }
        if let Ok(value) = serde_json::from_str::<Value>(line) {
            entries.push(value);
            if entries.len() >= limit {
                break;
            }
        }
    }
    entries
}

fn now_local() -> String {
    let secs = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs_f64())
        .unwrap_or(0.0);
    let text = crate::format::format_mtime(secs);
    text.replace(' ', "T")
}
