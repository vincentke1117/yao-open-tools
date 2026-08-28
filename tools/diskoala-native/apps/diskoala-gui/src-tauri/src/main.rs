//! Diskoala GUI（Tauri 版）：命令桥与 Python 版 scaiala_gui_web.Api 一一对应，
//! web 前端经 bridge.js（pywebview 兼容层）零改动复用。

#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use diskoala_core::ai;
use diskoala_core::classify::classify_path;
use diskoala_core::format::{display_name, format_mtime, human_size, parse_size};
use diskoala_core::plan::{paths_overlap, select_plan_items};
use diskoala_core::scan::default_computer_scan_root;
use diskoala_core::state;
use diskoala_core::trash::{delete_permanently, move_to_trash};
use diskoala_core::{create_space_analysis, Analysis, Insight, Kind, ScanOptions, APP_HOMEPAGE, APP_MAKER, APP_NAME, APP_NAME_ZH, APP_VERSION};
use serde_json::{json, Value};
use std::path::PathBuf;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use tauri::{AppHandle, Manager, State};

const DEFAULT_WEB_LIMIT: usize = 500;

#[derive(Clone)]
struct JobState {
    phase: String, // running / done / cancelled / error
    error: String,
    analysis: Option<Arc<Analysis>>,
    root: PathBuf,
    limit: usize,
    started_at: std::time::Instant,
    cancel: Arc<AtomicBool>,
    dirs: u64,
    files: u64,
}

#[derive(Default)]
struct Manager_ {
    job: Mutex<Option<Arc<Mutex<JobState>>>>,
}

fn row_json(path: &std::path::Path, size: u64, kind: Kind, insight: &Insight, mtime: f64, root: &std::path::Path) -> Value {
    json!({
        "key": path.to_string_lossy(),
        "display": display_name(path, root),
        "kind": kind.zh(),
        "size": size,
        "human": human_size(size),
        "risk": insight.risk.key(),
        "category": insight.category,
        "reason": insight.reason,
        "action": insight.action,
        "mtime": mtime,
        "mtimeText": format_mtime(mtime),
    })
}

fn rows_from_analysis(analysis: &Analysis) -> Vec<Value> {
    let root = &analysis.root;
    let mut rows: Vec<Value> = analysis
        .dirs
        .iter()
        .map(|d| {
            let insight = classify_path(&d.path, d.size, Kind::Dir);
            row_json(&d.path, d.size, Kind::Dir, &insight, d.mtime, root)
        })
        .chain(analysis.files.iter().map(|f| {
            let insight = classify_path(&f.path, f.size, Kind::File);
            row_json(&f.path, f.size, Kind::File, &insight, f.mtime, root)
        }))
        .collect();
    rows.sort_by(|a, b| b["size"].as_u64().unwrap_or(0).cmp(&a["size"].as_u64().unwrap_or(0)));
    rows
}

fn snapshot_job(mgr: &State<'_, Manager_>) -> Option<JobState> {
    mgr.job.lock().unwrap().as_ref().map(|job| job.lock().unwrap().clone())
}

// ---------------- 状态与偏好 ----------------

#[tauri::command]
fn get_initial_state() -> Value {
    state::ensure_data_dir();
    let saved = state::load_state();
    let last_root = state::load_last_root()
        .map(|p| p.to_string_lossy().to_string())
        .unwrap_or_else(|| diskoala_core::format::home_dir().to_string_lossy().to_string());
    json!({
        "ok": true,
        "app_name": APP_NAME,
        "app_name_zh": APP_NAME_ZH,
        "version": APP_VERSION,
        "maker": APP_MAKER,
        "homepage": APP_HOMEPAGE,
        "computer_root": default_computer_scan_root().to_string_lossy(),
        "last_root": last_root,
        "last_scan_at": saved.get("last_scan_at").and_then(|v| v.as_str()).unwrap_or(""),
        "theme": saved.get("theme").and_then(|v| v.as_str()).unwrap_or("light"),
    })
}

#[tauri::command]
fn save_prefs(prefs: Value) -> Value {
    let mut patch = json!({});
    if let Some(theme) = prefs.get("theme").and_then(|v| v.as_str()) {
        if theme == "light" || theme == "dark" {
            patch["theme"] = json!(theme);
        }
    }
    state::save_state(&patch);
    json!({ "ok": true })
}

// ---------------- 扫描 ----------------

#[tauri::command]
fn start_scan(options: Value, mgr: State<'_, Manager_>) -> Value {
    let path_text = options.get("path").and_then(|v| v.as_str()).unwrap_or("").trim().to_string();
    if path_text.is_empty() {
        return json!({ "ok": false, "error": "路径为空" });
    }
    let target = PathBuf::from(&path_text);
    if !target.exists() {
        return json!({ "ok": false, "error": format!("路径不存在: {}", target.display()) });
    }
    let resolved = target.canonicalize().unwrap_or(target);
    if !resolved.is_dir() {
        return json!({ "ok": false, "error": "请选择目录而不是文件" });
    }
    let include_all = options.get("include_all").and_then(|v| v.as_bool()).unwrap_or(false);
    let limit = options
        .get("limit")
        .and_then(|v| v.as_u64())
        .unwrap_or(DEFAULT_WEB_LIMIT as u64)
        .clamp(20, 5000) as usize;

    state::save_last_root(&resolved);
    let cancel = Arc::new(AtomicBool::new(false));
    let job = Arc::new(Mutex::new(JobState {
        phase: "running".into(),
        error: String::new(),
        analysis: None,
        root: resolved.clone(),
        limit,
        started_at: std::time::Instant::now(),
        cancel: cancel.clone(),
        dirs: 0,
        files: 0,
    }));
    *mgr.job.lock().unwrap() = Some(job.clone());

    std::thread::spawn(move || {
        let progress_job = job.clone();
        let result = create_space_analysis(
            &resolved,
            limit,
            include_all,
            Some(1),
            ScanOptions {
                progress: Some(&move |stats: &diskoala_core::ScanStats, phase: &str| {
                    if let Ok(mut state) = progress_job.lock() {
                        // 两路并行遍历，计数取各路累计（工作进度语义）
                        state.dirs = state.dirs.max(stats.scanned_dirs);
                        state.files = state.files.max(stats.scanned_files);
                        let _ = phase;
                    }
                }),
                cancel: Some(cancel.clone()),
                parallel: true,
            },
        );
        let mut state = job.lock().unwrap();
        match result {
            Ok(analysis) => {
                state.phase = "done".into();
                state.analysis = Some(Arc::new(analysis));
                drop(state);
                state::save_state(&json!({ "last_scan_at": now_compact() }));
            }
            Err(_) => {
                if cancel.load(Ordering::Relaxed) {
                    state.phase = "cancelled".into();
                } else {
                    state.phase = "error".into();
                    state.error = "扫描失败".into();
                }
            }
        }
    });
    json!({ "ok": true })
}

fn now_compact() -> String {
    let secs = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs_f64())
        .unwrap_or(0.0);
    format_mtime(secs).replace(' ', "T")
}

#[tauri::command]
fn get_progress(mgr: State<'_, Manager_>) -> Value {
    let Some(job) = snapshot_job(&mgr) else {
        return json!({ "running": false, "phase": "idle", "dirs": 0, "files": 0, "elapsed": 0, "error": "" });
    };
    json!({
        "running": job.phase == "running",
        "phase": job.phase,
        "elapsed": job.started_at.elapsed().as_secs_f64(),
        "dirs": job.dirs,
        "files": job.files,
        "error": job.error,
    })
}

#[tauri::command]
fn cancel_scan(mgr: State<'_, Manager_>) -> Value {
    if let Some(job) = snapshot_job(&mgr) {
        if job.phase == "running" {
            job.cancel.store(true, Ordering::Relaxed);
        }
    }
    json!({ "ok": true })
}

#[tauri::command]
fn get_results(mgr: State<'_, Manager_>) -> Value {
    let Some(job) = snapshot_job(&mgr) else {
        return json!({ "ok": false, "error": "尚无扫描结果" });
    };
    let Some(analysis) = job.analysis.as_ref() else {
        return json!({ "ok": false, "error": "尚无扫描结果" });
    };
    if job.phase != "done" {
        return json!({ "ok": false, "error": "尚无扫描结果" });
    }
    let rows = rows_from_analysis(analysis);
    let can_more = rows.len() >= job.limit;
    json!({
        "ok": true,
        "data": {
            "root": job.root.to_string_lossy(),
            "elapsed": (analysis.elapsed * 100.0).round() / 100.0,
            "limit": job.limit,
            "can_more": can_more,
            "total_bytes": analysis.dir_stats.root_size,
            "scanned_dirs": analysis.dir_stats.scanned_dirs,
            "scanned_files": analysis.file_stats.scanned_files,
            "rows": rows,
            "version": APP_VERSION,
            "maker": APP_MAKER,
        }
    })
}

// ---------------- 选择与清理 ----------------

fn current_rows(mgr: &State<'_, Manager_>) -> Vec<Value> {
    match snapshot_job(mgr).and_then(|job| job.analysis) {
        Some(analysis) => rows_from_analysis(&analysis),
        None => Vec::new(),
    }
}

fn plan_items_from(rows: &[Value], keys: &[String]) -> (Vec<Value>, usize, usize) {
    let by_key: std::collections::HashMap<&str, &Value> =
        rows.iter().map(|r| (r["key"].as_str().unwrap_or(""), r)).collect();
    let mut candidates: Vec<&Value> = keys.iter().filter_map(|k| by_key.get(k.as_str()).copied()).collect();
    let dropped = keys.len().saturating_sub(candidates.len());
    let before = candidates.len();
    candidates.retain(|r| r["risk"].as_str() != Some("risky"));
    let risky_dropped = before - candidates.len();

    candidates.sort_by(|a, b| b["size"].as_u64().unwrap_or(0).cmp(&a["size"].as_u64().unwrap_or(0)));
    // 父子去重：大项优先，与已选中项重叠则跳过
    let mut taken_keys: Vec<String> = Vec::new();
    let items: Vec<Value> = candidates
        .into_iter()
        .filter(|row| {
            let path = PathBuf::from(row["key"].as_str().unwrap_or(""));
            let overlaps = taken_keys
                .iter()
                .any(|tk| paths_overlap(&path, &PathBuf::from(tk)));
            if !overlaps {
                taken_keys.push(row["key"].as_str().unwrap_or("").to_string());
            }
            !overlaps
        })
        .map(|r| {
            json!({
                "key": r["key"], "display": r["display"], "kind": r["kind"],
                "size": r["size"], "human": r["human"], "risk": r["risk"],
            })
        })
        .collect();
    (items, dropped, risky_dropped)
}

#[tauri::command]
fn plan_trash(keys: Vec<String>, mgr: State<'_, Manager_>) -> Value {
    if keys.is_empty() {
        return json!({ "ok": false, "error": "未选择项目" });
    }
    let rows = current_rows(&mgr);
    let (items, dropped, risky_dropped) = plan_items_from(&rows, &keys);
    let total: u64 = items.iter().map(|i| i["size"].as_u64().unwrap_or(0)).sum();
    json!({
        "ok": true,
        "items": items,
        "total": total,
        "total_human": human_size(total),
        "dropped_missing": dropped,
        "dropped_risky": risky_dropped,
    })
}

#[tauri::command]
fn do_trash(keys: Vec<String>, mode: String, mgr: State<'_, Manager_>) -> Value {
    let mode = if mode == "permanent" { "permanent" } else { "recycle" }.to_string();
    if keys.is_empty() {
        return json!({ "ok": false, "error": "未选择项目" });
    }
    let rows = current_rows(&mgr);
    let (items, _dropped, _risky) = plan_items_from(&rows, &keys);
    if items.is_empty() {
        return json!({ "ok": false, "error": "没有可清理的项目（高风险项已自动排除）" });
    }
    let mut moved: Vec<String> = Vec::new();
    let mut freed = 0u64;
    let mut failures: Vec<Value> = Vec::new();
    let mut logs: Vec<Value> = Vec::new();
    for item in &items {
        let path = PathBuf::from(item["key"].as_str().unwrap_or(""));
        let (ok, error) = if mode == "permanent" {
            delete_permanently(&path)
        } else {
            move_to_trash(&path)
        };
        logs.push(json!({
            "path": item["key"], "size": item["size"], "kind": item["kind"],
            "risk": item["risk"], "category": "",
            "mode": mode, "action": if mode == "permanent" { "delete_permanent" } else { "move_to_trash" },
            "ok": ok, "error": if error.is_empty() { Value::Null } else { json!(error) },
        }));
        if ok {
            moved.push(item["key"].as_str().unwrap_or("").to_string());
            freed += item["size"].as_u64().unwrap_or(0);
        } else {
            failures.push(json!({ "key": item["key"], "error": error }));
        }
    }
    state::append_cleanup_log(&logs);
    json!({
        "ok": true,
        "mode": mode,
        "moved": moved,
        "freed": freed,
        "freed_human": human_size(freed),
        "failures": failures,
    })
}

#[tauri::command]
fn auto_plan(target: String, mgr: State<'_, Manager_>) -> Value {
    let Ok(target_bytes) = parse_size(&target) else {
        return json!({ "ok": false, "error": "目标大小无效" });
    };
    let Some(job) = snapshot_job(&mgr) else {
        return json!({ "ok": false, "error": "请先扫描" });
    };
    let Some(analysis) = job.analysis else {
        return json!({ "ok": false, "error": "请先扫描" });
    };
    let (selected, total) = select_plan_items(&analysis.insights, target_bytes, &job.root);
    let paths: Vec<String> = selected
        .iter()
        .filter(|i| i.path.exists())
        .map(|i| i.path.to_string_lossy().to_string())
        .collect();
    let count = paths.len();
    json!({
        "ok": true,
        "paths": paths,
        "count": count,
        "total": total,
        "total_human": human_size(total),
        "target_human": human_size(target_bytes),
    })
}

// ---------------- 其他 ----------------

#[tauri::command]
fn browse(app: AppHandle) -> Value {
    use tauri_plugin_dialog::DialogExt;
    let picked = app.dialog().file().blocking_pick_folder();
    match picked {
        Some(path) => json!({ "ok": true, "path": path.to_string() }),
        None => json!({ "ok": false, "error": "cancel" }),
    }
}

#[tauri::command]
fn reveal(key: String) -> Value {
    let path = PathBuf::from(&key);
    if !path.exists() {
        return json!({ "ok": false, "error": "missing" });
    }
    let _ = if cfg!(windows) {
        std::process::Command::new("explorer").arg("/select,").arg(&path).spawn()
    } else if cfg!(target_os = "macos") {
        std::process::Command::new("open").arg("-R").arg(&path).spawn()
    } else {
        std::process::Command::new("xdg-open").arg(path.parent().unwrap_or(&path)).spawn()
    };
    json!({ "ok": true, "error": "" })
}

#[tauri::command]
fn open_log() -> Value {
    state::ensure_data_dir();
    let log = state::log_file();
    if !log.exists() {
        let _ = std::fs::write(&log, "");
    }
    let ok = if cfg!(windows) {
        std::process::Command::new("explorer").arg(&log).spawn().is_ok()
    } else if cfg!(target_os = "macos") {
        std::process::Command::new("open").arg(&log).spawn().is_ok()
    } else {
        std::process::Command::new("xdg-open").arg(&log).spawn().is_ok()
    };
    json!({ "ok": ok })
}

#[tauri::command]
fn ai_prompt(mgr: State<'_, Manager_>) -> Value {
    match snapshot_job(&mgr).and_then(|job| job.analysis) {
        Some(analysis) => json!({ "ok": true, "prompt": ai::build_ai_prompt(&analysis) }),
        None => json!({ "ok": false, "error": "no_analysis" }),
    }
}

#[tauri::command]
fn get_log(limit: Option<u64>) -> Value {
    let limit = limit.unwrap_or(200).clamp(1, 1000) as usize;
    let entries: Vec<Value> = state::read_log(limit)
        .into_iter()
        .map(|entry| {
            let action = entry.get("action").and_then(|v| v.as_str()).unwrap_or("move_to_trash");
            let size = entry.get("size").and_then(|v| v.as_u64()).unwrap_or(0);
            json!({
                "time": entry.get("time").cloned().unwrap_or(json!("")),
                "mode": if action == "delete_permanent" { "永久删除" } else { "回收站" },
                "mode_kind": if action == "delete_permanent" { "permanent" } else { "recycle" },
                "path": entry.get("path").cloned().unwrap_or(json!("")),
                "size": size,
                "human": human_size(size),
                "ok": entry.get("ok").and_then(|v| v.as_bool()).unwrap_or(false),
                "error": entry.get("error").cloned().unwrap_or(json!("")),
            })
        })
        .collect();
    json!({ "ok": true, "entries": entries })
}

#[tauri::command]
fn smoke_done(report: Value, app: AppHandle) -> Value {
    let path = std::env::temp_dir().join("diskoala-smoke-report.json");
    let _ = std::fs::write(&path, serde_json::to_string(&report).unwrap_or_default());
    app.exit(0);
    json!({ "ok": true })
}

fn main() {
    let builder = tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .manage(Manager_::default())
        .invoke_handler(tauri::generate_handler![
            get_initial_state,
            save_prefs,
            start_scan,
            get_progress,
            cancel_scan,
            get_results,
            plan_trash,
            do_trash,
            auto_plan,
            browse,
            reveal,
            open_log,
            ai_prompt,
            get_log,
            smoke_done
        ]);

    if let Ok(smoke) = std::env::var("SCAI_GUI_SMOKE_MS") {
        if let Ok(ms) = smoke.parse::<u64>() {
            // 冒烟模式：页面就绪后 eval 启动自测脚本；超时看门狗兜底退出
            let smoke_dir = std::env::var("SCAI_GUI_SMOKE_DIR").unwrap_or_default();
            let watchdog = ms + 15000;
            let configured = builder.setup(move |app| {
                let handle = app.handle().clone();
                let window = handle.get_webview_window("main").expect("主窗口缺失");
                let dir_js = serde_json::to_string(&smoke_dir).unwrap_or_else(|_| "\"\"".into());
                std::thread::spawn(move || {
                    std::thread::sleep(std::time::Duration::from_millis(1200));
                    let _ = window.eval(&format!("window.__runSmoke && window.__runSmoke({})", dir_js));
                });
                std::thread::spawn(move || {
                    std::thread::sleep(std::time::Duration::from_millis(watchdog));
                    handle.exit(1);
                });
                Ok(())
            });
            configured
                .run(tauri::generate_context!())
                .expect("Diskoala GUI 启动失败");
            return;
        }
    }

    builder
        .run(tauri::generate_context!())
        .expect("Diskoala GUI 启动失败");
}
