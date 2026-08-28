//! AI 诊断：扫描摘要 JSON → 提示词构建 / codex exec 调用 / 终端友好的 Markdown 转纯文本。

use crate::{format, Analysis, Insight, APP_NAME, APP_NAME_ZH};
use serde_json::{json, Value};
use std::io::Write;
use std::path::{Path, PathBuf};

/// 与 Python analysis_payload 对齐。
pub fn analysis_payload(analysis: &Analysis) -> Value {
    let root = &analysis.root;
    let top_dirs: Vec<Value> = analysis
        .dirs
        .iter()
        .take(12)
        .map(|r| {
            json!({
                "path": format::display_name(&r.path, root),
                "size": r.size,
                "human_size": format::human_size(r.size),
            })
        })
        .collect();
    let top_files: Vec<Value> = analysis
        .files
        .iter()
        .take(20)
        .map(|r| {
            json!({
                "path": format::display_name(&r.path, root),
                "size": r.size,
                "human_size": format::human_size(r.size),
                "format": crate::classify::infer_format(&r.path),
            })
        })
        .collect();
    let insights: Vec<Value> = analysis
        .insights
        .iter()
        .take(40)
        .map(|item| insight_json(item, root))
        .collect();
    json!({
        "root": root.to_string_lossy(),
        "elapsed_seconds": (analysis.elapsed * 100.0).round() / 100.0,
        "top_dirs": top_dirs,
        "top_files": top_files,
        "insights": insights,
    })
}

fn insight_json(item: &Insight, root: &Path) -> Value {
    json!({
        "path": format::display_name(&item.path, root),
        "size": item.size,
        "human_size": format::human_size(item.size),
        "risk": item.risk.key(),
        "category": item.category,
        "reason": item.reason,
        "action": item.action,
    })
}

pub fn build_ai_prompt(analysis: &Analysis) -> String {
    format!(
        "你是 {}({}) 的磁盘空间顾问。只根据下面 JSON 扫描摘要分析，不读取文件内容，\
不要建议直接永久删除。请用中文输出：空间概览、主要占用、可安全关注、需要确认、不要碰、下一步建议。\
可以使用 Markdown 标题、加粗、列表和代码块，不要使用 Markdown 表格。\n\n{}",
        APP_NAME,
        APP_NAME_ZH,
        serde_json::to_string_pretty(&analysis_payload(analysis)).unwrap_or_default()
    )
}

/// 调用本机 codex exec。返回 (状态, 消息)：ok / missing / timeout / error。
pub fn invoke_codex_diagnosis(prompt: &str, timeout_secs: u64) -> (String, String) {
    let Some(codex) = which_codex() else {
        return ("missing".into(), String::new());
    };
    let output_path = std::env::temp_dir().join(format!("diskoala-ai-{}.txt", std::process::id()));
    let mut child = match std::process::Command::new(&codex)
        .args([
            "exec", "--skip-git-repo-check", "--sandbox", "read-only",
            "--output-last-message", output_path.to_string_lossy().as_ref(), "-",
        ])
        .stdin(std::process::Stdio::piped())
        .stdout(std::process::Stdio::piped())
        .stderr(std::process::Stdio::piped())
        .spawn()
    {
        Ok(child) => child,
        Err(e) => return ("error".into(), e.to_string()),
    };
    if let Some(stdin) = child.stdin.as_mut() {
        let _ = stdin.write_all(prompt.as_bytes());
    }
    drop(child.stdin.take());

    let deadline = std::time::Instant::now() + std::time::Duration::from_secs(timeout_secs);
    loop {
        match child.try_wait() {
            Ok(Some(status)) => {
                if !status.success() {
                    let stderr = child.wait_with_output().map(|o| String::from_utf8_lossy(&o.stderr).to_string()).unwrap_or_default();
                    let _ = std::fs::remove_file(&output_path);
                    return ("error".into(), stderr.trim().to_string());
                }
                let message = std::fs::read_to_string(&output_path)
                    .map(|t| t.trim().to_string())
                    .unwrap_or_default();
                let _ = std::fs::remove_file(&output_path);
                let stdout = child.wait_with_output().map(|o| String::from_utf8_lossy(&o.stdout).trim().to_string()).unwrap_or_default();
                let message = if message.is_empty() {
                    if stdout.is_empty() { "Codex 没有返回分析内容。".to_string() } else { stdout }
                } else {
                    message
                };
                return ("ok".into(), message);
            }
            Ok(None) => {
                if std::time::Instant::now() >= deadline {
                    let _ = child.kill();
                    let _ = std::fs::remove_file(&output_path);
                    return ("timeout".into(), String::new());
                }
                std::thread::sleep(std::time::Duration::from_millis(100));
            }
            Err(e) => {
                let _ = std::fs::remove_file(&output_path);
                return ("error".into(), e.to_string());
            }
        }
    }
}

fn which_codex() -> Option<PathBuf> {
    let name = if cfg!(windows) { "codex.exe" } else { "codex" };
    let path_env = std::env::var_os("PATH")?;
    for dir in std::env::split_paths(&path_env) {
        let candidate = dir.join(name);
        if candidate.is_file() {
            return Some(candidate);
        }
    }
    None
}

/// 终端友好的 Markdown 转纯文本（与 Python render_markdown_for_terminal 的无色模式对齐）。
pub fn render_markdown_plain(markdown: &str) -> String {
    let mut rendered: Vec<String> = Vec::new();
    let mut in_code_block = false;
    for raw in markdown.replace("\r\n", "\n").replace('\r', "\n").split('\n') {
        let stripped = raw.trim();
        if stripped.starts_with("```") || stripped.starts_with("~~~") {
            in_code_block = !in_code_block;
            if !rendered.is_empty() && !rendered.last().map(|s| s.is_empty()).unwrap_or(false) {
                rendered.push(String::new());
            }
            continue;
        }
        if in_code_block {
            rendered.push(format!("    {}", raw.trim_end()));
            continue;
        }
        if stripped.is_empty() {
            if !rendered.is_empty() && !rendered.last().map(|s| s.is_empty()).unwrap_or(false) {
                rendered.push(String::new());
            }
            continue;
        }
        if let Some(heading) = stripped.strip_prefix("# ") {
            rendered.push(strip_inline(heading.trim()));
            continue;
        }
        if stripped.starts_with("## ") || stripped.starts_with("### ") || stripped.starts_with("#### ") {
            let title = stripped.trim_start_matches('#').trim();
            rendered.push(strip_inline(title));
            continue;
        }
        if let Some(rest) = stripped.strip_prefix("- ").or_else(|| stripped.strip_prefix("* ")).or_else(|| stripped.strip_prefix("+ ")) {
            rendered.push(format!("  - {}", strip_inline(rest.trim())));
            continue;
        }
        rendered.push(strip_inline(raw.trim_end()));
    }
    rendered.join("\n").trim().to_string()
}

fn strip_inline(text: &str) -> String {
    let mut out = text.to_string();
    // 行内代码 -> 裸文本；图片 -> alt；链接 -> label (url)
    out = replace_links(&out);
    out.replace("**", "").replace("__", "").replace('`', "")
}

fn replace_links(text: &str) -> String {
    let mut out = String::new();
    let mut rest = text;
    while let Some(start) = rest.find('[') {
        out.push_str(&rest[..start]);
        rest = &rest[start + 1..];
        if let Some(mid) = rest.find("](") {
            let label = &rest[..mid];
            let after = &rest[mid + 2..];
            if let Some(end) = after.find(')') {
                let url = &after[..end];
                if label == url {
                    out.push_str(url);
                } else {
                    out.push_str(&format!("{} ({})", label, url));
                }
                rest = &after[end + 1..];
                continue;
            }
        }
        out.push('[');
    }
    out.push_str(rest);
    out
}

#[allow(dead_code)]
fn unused(_p: &Path) {}
