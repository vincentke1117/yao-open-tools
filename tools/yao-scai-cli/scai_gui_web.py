#!/usr/bin/env python3
"""Scai Web GUI：pywebview + 本地 HTML 界面（设计稿 1:1 还原版）。

- 扫描/回收站/日志/状态全部复用 Python 既有实现，前端只做展示与交互。
- 桥层安全兜底：do_trash 服务端再次过滤高风险项并做父子去重，前端不可绕过。
- WebView2 运行时缺失时 launch() 返回 3，由调用方回退旧 tkinter GUI。

品牌配置（后续宣传用）:
    APP_MAKER / APP_HOMEPAGE —— 填入主页或社交媒体链接后，空态页脚自动变为可点击链接。
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
import threading
import time
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import scai
from scai import APP_HOMEPAGE, APP_MAKER, APP_NAME, APP_NAME_ZH, APP_VERSION
from scai_gui import (
    IS_MACOS,
    IS_WINDOWS,
    LOG_DIR,
    LOG_FILE,
    STATE_FILE,
    append_cleanup_log,
    delete_permanently,
    ensure_data_dir,
    load_last_root,
    move_to_trash,
    save_last_root,
)

# 品牌常量统一来自 scai.py（APP_NAME / APP_VERSION / APP_MAKER / APP_HOMEPAGE）
WEB_DIR = Path(__file__).resolve().parent / "web"
DEFAULT_WEB_LIMIT = 500


def load_state() -> dict:
    try:
        data = json.loads(STATE_FILE.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except (OSError, ValueError):
        return {}


def save_state(patch: dict) -> dict:
    state = load_state()
    state.update(patch)
    try:
        STATE_FILE.parent.mkdir(parents=True, exist_ok=True)
        STATE_FILE.write_text(json.dumps(state, ensure_ascii=False), encoding="utf-8")
    except OSError:
        pass
    return state


def reveal_in_explorer(path: Path) -> tuple[bool, str]:
    if not path.exists():
        return False, "missing"
    try:
        if IS_WINDOWS:
            subprocess.run(["explorer", "/select,", str(path)], check=False)
        elif IS_MACOS:
            subprocess.run(["open", "-R", str(path)], check=False)
        else:
            subprocess.run(["xdg-open", str(path.parent)], check=False)
    except OSError as exc:
        return False, str(exc)
    return True, ""


class ScanJob:
    """一次扫描任务的状态。"""

    def __init__(self, root: Path, include_all: bool, limit: int) -> None:
        self.root = root
        self.include_all = include_all
        self.limit = limit
        self.started_at = time.time()
        self.finished_at: float | None = None
        self.phase = "running"  # running / done / cancelled / error
        self.error = ""
        self.analysis = None
        self.cancel_event = threading.Event()
        self._progress_lock = threading.Lock()
        self._progress = {"dirs": None, "files": None}

    def update_progress(self, phase: str, stats) -> None:
        with self._progress_lock:
            self._progress[phase] = stats

    def snapshot(self) -> dict:
        with self._progress_lock:
            slots = dict(self._progress)
        dirs = sum(getattr(s, "scanned_dirs", 0) for s in slots.values() if s)
        files = sum(getattr(s, "scanned_files", 0) for s in slots.values() if s)
        return {
            "running": self.phase == "running",
            "phase": self.phase,
            "elapsed": (self.finished_at or time.time()) - self.started_at,
            "dirs": dirs,
            "files": files,
            "error": self.error,
        }


class Api:
    """暴露给前端 JS 的桥接口（pywebview js_api）。"""

    def __init__(self, window=None) -> None:
        self._window = window
        self._lock = threading.Lock()
        self._job: ScanJob | None = None
        self._thread: threading.Thread | None = None
        ensure_data_dir()

    # ---------- 桥层小工具 ----------

    def _rows_from_analysis(self, analysis: scai.SpaceAnalysis) -> list[dict]:
        rows: list[dict] = []
        for record in analysis.dirs:
            insight = scai.classify_path(record.path, record.size, "dir")
            rows.append(self._row(record.path, record.size, "文件夹", insight, record.mtime, analysis.root))
        for record in analysis.files:
            insight = scai.classify_path(record.path, record.size, "file")
            rows.append(self._row(record.path, record.size, "文件", insight, record.mtime, analysis.root))
        rows.sort(key=lambda r: r["size"], reverse=True)
        return rows

    def _row(self, path: Path, size: int, kind: str, insight: scai.Insight, mtime: float, root: Path) -> dict:
        return {
            "key": str(path),
            "display": scai.display_name(path, root),
            "kind": kind,
            "size": size,
            "human": scai.human_size(size),
            "risk": insight.risk,
            "category": insight.category,
            "reason": insight.reason,
            "action": insight.action,
            "mtime": mtime,
            "mtimeText": scai.format_mtime(mtime),
        }

    def _analysis_rows(self) -> list[dict]:
        job = self._job
        if job is None or job.analysis is None:
            return []
        return self._rows_from_analysis(job.analysis)

    # ---------- 状态与偏好 ----------

    def get_initial_state(self) -> dict:
        state = load_state()
        return {
            "ok": True,
            "app_name": APP_NAME,
            "app_name_zh": APP_NAME_ZH,
            "version": APP_VERSION,
            "maker": APP_MAKER,
            "homepage": APP_HOMEPAGE,
            "computer_root": str(scai.COMPUTER_SCAN_ROOT),
            "last_root": str(load_last_root() or Path.home()),
            "last_scan_at": state.get("last_scan_at", ""),
            "theme": state.get("theme", "light"),
        }

    def get_log(self, limit: int = 200) -> dict:
        """读取最近的清理日志（新→旧），供应用内日志查看器展示。"""
        try:
            lines = LOG_FILE.read_text(encoding="utf-8").splitlines()
        except OSError:
            lines = []
        entries = []
        for line in reversed(lines):
            line = line.strip()
            if not line:
                continue
            try:
                entry = json.loads(line)
            except ValueError:
                continue
            action = entry.get("action", "move_to_trash")
            mode_label = "永久删除" if action == "delete_permanent" else "回收站"
            ok = bool(entry.get("ok"))
            entries.append(
                {
                    "time": entry.get("time", ""),
                    "mode": mode_label,
                    "mode_kind": "permanent" if action == "delete_permanent" else "recycle",
                    "path": entry.get("path", ""),
                    "size": entry.get("size", 0),
                    "human": scai.human_size(int(entry.get("size", 0) or 0)),
                    "ok": ok,
                    "error": entry.get("error") or "",
                }
            )
            if len(entries) >= max(1, min(int(limit or 200), 1000)):
                break
        return {"ok": True, "entries": entries}

    def save_prefs(self, prefs: dict) -> dict:
        patch = {}
        if isinstance(prefs, dict):
            if prefs.get("theme") in ("light", "dark"):
                patch["theme"] = prefs["theme"]
        save_state(patch)
        return {"ok": True}

    # ---------- 扫描 ----------

    def start_scan(self, options: dict) -> dict:
        options = options or {}
        raw_path = str(options.get("path", "")).strip()
        if not raw_path:
            return {"ok": False, "error": "路径为空"}
        target = Path(raw_path).expanduser()
        if not target.exists():
            return {"ok": False, "error": f"路径不存在: {target}"}
        resolved = target.resolve()
        if not resolved.is_dir():
            return {"ok": False, "error": "请选择目录而不是文件"}
        with self._lock:
            if self._thread is not None and self._thread.is_alive():
                return {"ok": False, "error": "扫描仍在进行中"}
            try:
                limit = int(options.get("limit") or DEFAULT_WEB_LIMIT)
            except (TypeError, ValueError):
                limit = DEFAULT_WEB_LIMIT
            limit = max(20, min(5000, limit))
            job = ScanJob(resolved, bool(options.get("include_all")), limit)
            self._job = job
            save_last_root(resolved)
            self._thread = threading.Thread(target=self._run_scan, args=(job,), daemon=True)
            self._thread.start()
        return {"ok": True}

    def _run_scan(self, job: ScanJob) -> None:
        try:
            analysis = scai.create_space_analysis(
                root=job.root,
                limit=job.limit,
                include_all=job.include_all,
                max_depth=1,
                progress_cb=lambda stats, phase: job.update_progress(phase, stats),
                cancel_check=job.cancel_event.is_set,
            )
            job.analysis = analysis
            job.phase = "done"
            save_state({"last_scan_at": time.strftime("%Y-%m-%d %H:%M:%S")})
        except scai.ScanCancelled:
            job.phase = "cancelled"
        except Exception as exc:  # 任何扫描异常都回传前端展示
            job.phase = "error"
            job.error = str(exc)
        finally:
            job.finished_at = time.time()

    def get_progress(self) -> dict:
        with self._lock:
            if self._job is None:
                return {"running": False, "phase": "idle", "dirs": 0, "files": 0, "elapsed": 0, "error": ""}
            return self._job.snapshot()

    def cancel_scan(self) -> dict:
        with self._lock:
            if self._job is not None and self._job.phase == "running":
                self._job.cancel_event.set()
        return {"ok": True}

    def get_results(self) -> dict:
        with self._lock:
            job = self._job
            if job is None or job.phase != "done" or job.analysis is None:
                return {"ok": False, "error": "尚无扫描结果"}
            rows = self._rows_from_analysis(job.analysis)
            dir_stats = job.analysis.dir_stats
            return {
                "ok": True,
                "data": {
                    "root": str(job.root),
                    "elapsed": round(job.analysis.elapsed, 2),
                    "limit": job.limit,
                    "can_more": len(rows) >= job.limit,
                    "total_bytes": dir_stats.root_size,
                    "scanned_dirs": dir_stats.scanned_dirs,
                    "scanned_files": job.analysis.file_stats.scanned_files,
                    "rows": rows,
                    "version": APP_VERSION,
                    "maker": APP_MAKER,
                },
            }

    # ---------- 选择与清理 ----------

    def _plan_items(self, keys: list[str]) -> tuple[list[dict], int, int]:
        """父子去重 + 过滤高风险 + 过滤已消失项。返回 (items, dropped, risky_dropped)。"""
        rows = {r["key"]: r for r in self._analysis_rows()}
        candidates = [rows[k] for k in keys if k in rows]
        dropped = len(keys) - len(candidates)
        before_risky = len(candidates)
        candidates = [r for r in candidates if r["risk"] != "risky"]
        risky_dropped = before_risky - len(candidates)
        # 父子去重（大项优先，与服务端 paths_overlap 同规则）
        taken: list[dict] = []
        for row in sorted(candidates, key=lambda r: r["size"], reverse=True):
            path = Path(row["key"])
            if any(scai.paths_overlap(path, Path(k["key"])) for k in taken):
                continue
            taken.append(row)
        return taken, dropped, risky_dropped

    def plan_trash(self, keys) -> dict:
        keys = [str(k) for k in (keys or [])]
        if not keys:
            return {"ok": False, "error": "未选择项目"}
        with self._lock:
            items, dropped, risky_dropped = self._plan_items(keys)
        total = sum(i["size"] for i in items)
        return {
            "ok": True,
            "items": [
                {
                    "key": i["key"], "display": i["display"], "kind": i["kind"],
                    "size": i["size"], "human": i["human"], "risk": i["risk"],
                }
                for i in items
            ],
            "total_human": scai.human_size(total),
            "total": total,
            "dropped_missing": dropped,
            "dropped_risky": risky_dropped,
        }

    def do_trash(self, keys, mode: str = "recycle") -> dict:
        """执行清理。mode: recycle（默认，进回收站可恢复）| permanent（永久删除，不可恢复）。"""
        keys = [str(k) for k in (keys or [])]
        if mode not in ("recycle", "permanent"):
            mode = "recycle"
        if not keys:
            return {"ok": False, "error": "未选择项目"}
        with self._lock:
            items, _dropped, _risky = self._plan_items(keys)
            if not items:
                return {"ok": False, "error": "没有可清理的项目（高风险项已自动排除）"}
        moved: list[str] = []
        freed = 0
        failures: list[dict] = []
        logs: list[dict] = []
        for item in items:
            path = Path(item["key"])
            if mode == "permanent":
                ok, error = delete_permanently(path)
            else:
                ok, error = move_to_trash(path)
            logs.append(
                {
                    "path": item["key"], "size": item["size"], "kind": item["kind"],
                    "risk": item["risk"], "category": item["category"],
                    "mode": mode, "action": "delete_permanent" if mode == "permanent" else "move_to_trash",
                    "ok": ok, "error": error or None,
                }
            )
            if ok:
                moved.append(item["key"])
                freed += item["size"]
            else:
                failures.append({"key": item["key"], "error": error})
        append_cleanup_log(logs)
        return {
            "ok": True,
            "mode": mode,
            "moved": moved,
            "freed": freed,
            "freed_human": scai.human_size(freed),
            "failures": failures,
        }

    def auto_plan(self, target: str) -> dict:
        try:
            target_bytes = scai.parse_size(str(target))
        except ValueError as exc:
            return {"ok": False, "error": str(exc)}
        with self._lock:
            rows = self._analysis_rows()
            if not rows:
                return {"ok": False, "error": "请先扫描"}
            insights = [
                scai.Insight(
                    path=Path(r["key"]), size=r["size"], kind="dir" if r["kind"] == "文件夹" else "file",
                    risk=r["risk"], category=r["category"], reason=r["reason"], action=r["action"],
                )
                for r in rows
            ]
            root = self._job.root if self._job else Path.cwd()
            selected, total = scai.select_plan_items(insights, target_bytes, root=root)
        paths = [str(i.path) for i in selected if Path(str(i.path)).exists()]
        return {
            "ok": True,
            "paths": paths,
            "count": len(paths),
            "total": total,
            "total_human": scai.human_size(total),
            "target_human": scai.human_size(target_bytes),
        }

    # ---------- 其他 ----------

    def browse(self) -> dict:
        if self._window is None:
            return {"ok": False, "error": "窗口不可用"}
        result = self._window.create_file_dialog(webview_folder_dialog())
        if not result:
            return {"ok": False, "error": "cancel"}
        return {"ok": True, "path": result[0] if isinstance(result, (list, tuple)) else str(result)}

    def reveal(self, key: str) -> dict:
        ok, error = reveal_in_explorer(Path(str(key)))
        return {"ok": ok, "error": error}

    def open_log(self) -> dict:
        try:
            LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
            if not LOG_FILE.exists():
                LOG_FILE.write_text("", encoding="utf-8")
            if IS_WINDOWS:
                os.startfile(str(LOG_FILE))  # type: ignore[attr-defined]
            elif IS_MACOS:
                subprocess.run(["open", str(LOG_FILE)], check=False)
            else:
                subprocess.run(["xdg-open", str(LOG_FILE)], check=False)
            return {"ok": True}
        except OSError as exc:
            return {"ok": False, "error": str(exc)}

    def ai_prompt(self) -> dict:
        with self._lock:
            job = self._job
            if job is None or job.analysis is None:
                return {"ok": False, "error": "no_analysis"}
            prompt = scai.build_ai_prompt(job.analysis)
        return {"ok": True, "prompt": prompt}

    # ---------- 冒烟 ----------

    def smoke_done(self, report) -> dict:
        self._smoke_report = report
        if self._window is not None:
            try:
                self._window.destroy()
            except Exception:
                pass
        return {"ok": True}


def webview_folder_dialog():
    """延迟导入 pywebview，返回目录对话框常量。"""
    import webview

    return webview.FOLDER_DIALOG


def _start_http_server() -> tuple[ThreadingHTTPServer, str]:
    class QuietHandler(SimpleHTTPRequestHandler):
        def __init__(self, *args, **kwargs):
            super().__init__(*args, directory=str(WEB_DIR), **kwargs)

        def log_message(self, *args) -> None:
            pass

    server = ThreadingHTTPServer(("127.0.0.1", 0), QuietHandler)
    port = server.server_address[1]
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return server, f"http://127.0.0.1:{port}/index.html"


def launch(root_path: Path | None = None, include_all: bool = False, limit: int = DEFAULT_WEB_LIMIT) -> int:
    """打开 Web GUI。

    返回码: 0 正常退出; 3 pywebview/WebView2 不可用（调用方应回退 tkinter GUI）。
    环境变量 SCAI_GUI_SMOKE_MS / SCAI_GUI_SMOKE_DIR 用于自动冒烟测试。
    """
    try:
        import webview
    except Exception as exc:  # ImportError 或其依赖缺失
        print(f"Web GUI 不可用（{exc}），回退到基础界面。", file=sys.stderr)
        return 3

    api = Api()
    server = None
    try:
        server, url = _start_http_server()
    except OSError as exc:
        print(f"本地服务启动失败（{exc}），回退到基础界面。", file=sys.stderr)
        return 3

    params: list[str] = []
    smoke_ms = 0
    smoke_env = os.environ.get("SCAI_GUI_SMOKE_MS", "")
    if smoke_env.isdigit():
        smoke_ms = int(smoke_env)
        params.append("smoke=1")
        smoke_dir = os.environ.get("SCAI_GUI_SMOKE_DIR", "")
        if smoke_dir:
            from urllib.parse import quote

            params.append("dir=" + quote(smoke_dir))
    if params:
        url += "?" + "&".join(params)

    window = webview.create_window(
        f"{APP_NAME} {APP_NAME_ZH} · 磁盘空间清理顾问",
        url,
        js_api=api,
        width=1120,
        height=720,
        min_size=(960, 640),
    )
    api._window = window

    def _watchdog() -> None:
        time.sleep(max(3.0, smoke_ms / 1000 + 5))
        try:
            window.destroy()
        except Exception:
            pass

    if smoke_ms:
        threading.Thread(target=_watchdog, daemon=True).start()

    try:
        webview.start()
    except Exception as exc:
        print(f"WebView2 运行时不可用（{exc}），回退到基础界面。", file=sys.stderr)
        return 3
    finally:
        if server is not None:
            server.shutdown()

    if smoke_ms:
        report = getattr(api, "_smoke_report", None)
        print("SMOKE-REPORT " + json.dumps(report, ensure_ascii=False))
        return 0 if report and report.get("ok") else 1
    return 0


def _main(argv: list[str] | None = None) -> int:
    args = sys.argv[1:] if argv is None else argv
    positional = [arg for arg in args if not arg.startswith("-")]
    if positional:
        candidate = Path(positional[0]).expanduser()
        if candidate.exists():
            return launch(root_path=candidate.resolve())
    return launch()


if __name__ == "__main__":
    sys.exit(_main())
