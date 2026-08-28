//! 格式化与解析：human_size / parse_size / 时间格式 / 路径显示名。

use std::path::{Path, PathBuf};

pub fn human_size(num_bytes: u64) -> String {
    const UNITS: [&str; 6] = ["B", "KB", "MB", "GB", "TB", "PB"];
    let mut value = num_bytes as f64;
    let mut unit_idx = 0usize;
    while unit_idx < UNITS.len() {
        if value < 1024.0 || unit_idx == UNITS.len() - 1 {
            if unit_idx == 0 {
                return format!("{} {}", num_bytes, UNITS[0]);
            }
            return format!("{:.1} {}", value, UNITS[unit_idx]);
        }
        value /= 1024.0;
        unit_idx += 1;
    }
    format!("{} B", num_bytes)
}

/// 与 Python parse_size 对齐："20g"、"500m"、"1.5gb"…
pub fn parse_size(text: &str) -> Result<u64, String> {
    let lowered = text.trim().to_lowercase();
    let mut number = String::new();
    let mut unit = String::new();
    for ch in lowered.chars() {
        if ch.is_ascii_digit() || ch == '.' {
            number.push(ch);
        } else if !ch.is_whitespace() {
            unit.push(ch);
        }
    }
    if number.is_empty() {
        return Err("size must include a number".to_string());
    }
    let multiplier: u64 = match unit.as_str() {
        "" | "b" => 1,
        "k" | "kb" => 1024,
        "m" | "mb" => 1024u64.pow(2),
        "g" | "gb" => 1024u64.pow(3),
        "t" | "tb" => 1024u64.pow(4),
        other => return Err(format!("unsupported size unit: {}", other)),
    };
    let value: f64 = number.parse().map_err(|_| "invalid number".to_string())?;
    Ok((value * multiplier as f64) as u64)
}

/// 与 Python display_name 对齐：相对根显示，失败显示绝对路径。
pub fn display_name(path: &Path, root: &Path) -> String {
    match path.strip_prefix(root) {
        Ok(rel) if !rel.as_os_str().is_empty() => rel.to_string_lossy().to_string(),
        _ => path.to_string_lossy().to_string(),
    }
}

/// "%Y-%m-%d %H:%M:%S" 本地时间（Windows 通过 GetTimeZoneInformation 取真实时区与夏令时）。
pub fn format_mtime(timestamp: f64) -> String {
    if timestamp <= 0.0 {
        return "-".to_string();
    }
    let secs = timestamp as i64 + local_utc_offset_secs();
    let (year, month, day) = civil_from_days(secs.div_euclid(86400));
    let tod = secs.rem_euclid(86400);
    format!(
        "{:04}-{:02}-{:02} {:02}:{:02}:{:02}",
        year, month, day,
        tod / 3600,
        (tod % 3600) / 60,
        tod % 60
    )
}

#[cfg(windows)]
fn local_utc_offset_secs() -> i64 {
    use std::sync::OnceLock;
    static OFFSET: OnceLock<i64> = OnceLock::new();
    *OFFSET.get_or_init(|| {
        #[repr(C)]
        struct SYSTEMTIME { w_year: u16, w_month: u16, _w_dow: u16, w_day: u16, w_hour: u16, w_minute: u16, w_second: u16, _ms: u16 }
        #[repr(C)]
        struct TZI { bias: i32, _standard_name: [u16; 32], _standard_date: SYSTEMTIME, standard_bias: i32, _daylight_name: [u16; 32], _daylight_date: SYSTEMTIME, daylight_bias: i32 }
        #[link(name = "kernel32")]
        extern "system" {
            fn GetTimeZoneInformation(tzi: *mut TZI) -> u32;
        }
        let mut tzi = unsafe { std::mem::zeroed::<TZI>() };
        let state = unsafe { GetTimeZoneInformation(&mut tzi) };
        let extra_bias = match state {
            2 => tzi.daylight_bias, // 当前处于夏令时
            _ => tzi.standard_bias,
        };
        -((tzi.bias + extra_bias) as i64) * 60
    })
}

#[cfg(not(windows))]
fn local_utc_offset_secs() -> i64 {
    // 非 Windows：读取 TZ 环境变量失败时按 UTC 处理（保守兜底）
    0
}

fn civil_from_days(z: i64) -> (i64, u32, u32) {
    let z = z + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z.rem_euclid(146_097);
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146096) / 365;
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = (doy - (153 * mp + 2) / 5 + 1) as u32;
    let m = if mp < 10 { mp + 3 } else { mp - 9 } as u32;
    (if m <= 2 { y + 1 } else { y }, m, d)
}

/// 中部截断（与 Python truncate_middle 一致）。
pub fn truncate_middle(text: &str, width: usize) -> String {
    let chars: Vec<char> = text.chars().collect();
    if chars.len() <= width {
        return text.to_string();
    }
    if width <= 3 {
        return chars.into_iter().take(width).collect();
    }
    let left = (width - 1) / 2;
    let right = width - left - 1;
    let head: String = chars[..left].iter().collect();
    let tail: String = chars[chars.len() - right..].iter().collect();
    format!("{}…{}", head, tail)
}

pub fn home_dir() -> PathBuf {
    std::env::var_os("USERPROFILE")
        .or_else(|| std::env::var_os("HOME"))
        .map(PathBuf::from)
        .unwrap_or_else(|| PathBuf::from("."))
}
