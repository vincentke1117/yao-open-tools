//! 回收站 / 永久删除（Windows SHFileOperationW 直连 FFI，零第三方依赖）。

use std::path::Path;

#[cfg(windows)]
mod win {
    use std::ffi::OsStr;
    use std::os::windows::ffi::OsStrExt;
    use std::path::Path;

    #[repr(C)]
    struct SHFILEOPSTRUCTW {
        hwnd: *mut core::ffi::c_void,
        w_func: u32,
        p_from: *mut u16,
        p_to: *mut u16,
        f_flags: u16,
        any_ops_aborted: i32,
        name_mappings: *mut core::ffi::c_void,
        progress_title: *mut u16,
    }

    const FO_DELETE: u32 = 3;
    const FOF_ALLOWUNDO: u16 = 0x40;
    const FOF_NOCONFIRMATION: u16 = 0x10;
    const FOF_SILENT: u16 = 0x4;
    const FOF_NOERRORUI: u16 = 0x400;

    #[link(name = "shell32")]
    extern "system" {
        fn SHFileOperationW(lpfileop: *mut SHFILEOPSTRUCTW) -> i32;
    }

    /// 移入回收站。返回 (ok, error)。
    pub fn move_to_trash(path: &Path) -> (bool, String) {
        if !path.exists() {
            return (false, "路径已不存在".to_string());
        }
        // pFrom 需要双 \0 结尾
        let mut wide: Vec<u16> = OsStr::new(path).encode_wide().collect();
        wide.push(0);
        wide.push(0);
        let p_from = wide.as_mut_ptr();
        let mut op = SHFILEOPSTRUCTW {
            hwnd: std::ptr::null_mut(),
            w_func: FO_DELETE,
            p_from,
            p_to: std::ptr::null_mut(),
            f_flags: FOF_ALLOWUNDO | FOF_NOCONFIRMATION | FOF_SILENT | FOF_NOERRORUI,
            any_ops_aborted: 0,
            name_mappings: std::ptr::null_mut(),
            progress_title: std::ptr::null_mut(),
        };
        let result = unsafe { SHFileOperationW(&mut op) };
        if op.any_ops_aborted != 0 {
            return (false, "操作被取消".to_string());
        }
        if result != 0 {
            return (false, format!("SHFileOperation 错误码 {:#x}", result));
        }
        (true, String::new())
    }
}

/// 移入回收站（Windows：SHFileOperation；其他平台：freedesktop 垃圾箱的简化实现）。
pub fn move_to_trash(path: &Path) -> (bool, String) {
    if !path.exists() {
        return (false, "路径已不存在".to_string());
    }
    #[cfg(windows)]
    {
        return win::move_to_trash(path);
    }
    #[cfg(not(windows))]
    {
        // macOS 走 Finder osascript
        if cfg!(target_os = "macos") {
            let escaped = path.to_string_lossy().replace('\\', "\\\\").replace('"', "\\\"");
            let script = format!("tell application \"Finder\" to delete POSIX file \"{}\"", escaped);
            let status = std::process::Command::new("osascript")
                .arg("-e").arg(&script)
                .output();
            return match status {
                Ok(out) if out.status.success() => (true, String::new()),
                Ok(out) => (false, String::from_utf8_lossy(&out.stderr).to_string()),
                Err(e) => (false, e.to_string()),
            };
        }
        // Linux: freedesktop Trash
        let home = std::env::var("HOME").unwrap_or_else(|_| ".".into());
        let files_dir = std::path::Path::new(&home).join(".local/share/Trash/files");
        let info_dir = std::path::Path::new(&home).join(".local/share/Trash/info");
        if let Err(e) = std::fs::create_dir_all(&files_dir).and_then(|_| std::fs::create_dir_all(&info_dir)) {
            return (false, e.to_string());
        }
        let name = path.file_name().map(|n| n.to_string_lossy().to_string()).unwrap_or_else(|| "item".into());
        let mut dest = files_dir.join(&name);
        if dest.exists() {
            dest = files_dir.join(format!("{}__{}", name, std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0)));
        }
        let info = format!(
            "[Trash Info]\nPath={}\nDeletionDate={}\n",
            percent_encode(&path.to_string_lossy()),
            now_iso()
        );
        if let Err(e) = std::fs::write(info_dir.join(format!("{}.trashinfo", dest.file_name().unwrap().to_string_lossy())), info) {
            return (false, e.to_string());
        }
        match std::fs::rename(path, &dest) {
            Ok(_) => (true, String::new()),
            Err(e) => (false, e.to_string()),
        }
    }
}

/// 永久删除（不可恢复）。仅由用户在界面显式选择。
pub fn delete_permanently(path: &Path) -> (bool, String) {
    if !path.exists() {
        return (false, "路径已不存在".to_string());
    }
    let result = if path.is_dir() && !path.is_symlink() {
        std::fs::remove_dir_all(path)
    } else {
        std::fs::remove_file(path)
    };
    match result {
        Ok(_) => (true, String::new()),
        Err(e) => (false, e.to_string()),
    }
}

#[cfg(not(windows))]
fn percent_encode(text: &str) -> String {
    let mut out = String::new();
    for b in text.bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' | b'/' => out.push(b as char),
            _ => out.push_str(&format!("%{:02X}", b)),
        }
    }
    out
}

#[cfg(not(windows))]
fn now_iso() -> String {
    crate::format::format_mtime(std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH).map(|d| d.as_secs_f64()).unwrap_or(0.0))
}

#[cfg(test)]
mod tests {
    use super::*;

    // 真实回收站集成测试：设置 DISKOALA_TRASH_TEST=1 时才运行
    #[test]
    fn trash_roundtrip() {
        if std::env::var("DISKOALA_TRASH_TEST").unwrap_or_default() != "1" {
            return;
        }
        let dir = std::env::temp_dir().join(format!("dk-trash-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let file = dir.join("a.txt");
        std::fs::write(&file, "hello").unwrap();

        let (ok, err) = move_to_trash(&file);
        assert!(ok, "recycle failed: {err}");
        assert!(!file.exists());

        let inner = dir.join("sub");
        std::fs::create_dir_all(&inner).unwrap();
        std::fs::write(inner.join("b.txt"), "x").unwrap();
        let (ok, err) = move_to_trash(&dir);
        assert!(ok, "recycle dir failed: {err}");
        assert!(!dir.exists());

        let p = std::env::temp_dir().join(format!("dk-perm-{}", std::process::id()));
        std::fs::write(&p, "bye").unwrap();
        let (ok, err) = delete_permanently(&p);
        assert!(ok, "permanent failed: {err}");
        assert!(!p.exists());
    }
}
