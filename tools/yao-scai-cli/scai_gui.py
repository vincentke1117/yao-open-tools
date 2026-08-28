#!/usr/bin/env python3
"""Scai GUI: 图形界面版磁盘空间清理顾问。

流程: 选择目录 -> 扫描 -> 按风险分级给出建议 -> 用户勾选 -> 移动到回收站并记录日志。

安全策略:
- 高风险(系统/受管)项目锁定, 不可勾选删除。
- 删除一律移动到回收站/废纸篓, 可恢复, 不做永久删除。
- 每次操作写入 ~/.scai/cleanup-log.jsonl 供审计。
"""
from __future__ import annotations

import ctypes
import json
import os
import queue
import subprocess
import sys
import threading
import time
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from urllib.parse import quote

import tkinter as tk
from tkinter import filedialog, font as tkfont, messagebox, ttk

import scai

IS_WINDOWS = os.name == "nt"
IS_MACOS = sys.platform == "darwin"

CHECK_ON = "☑"
CHECK_OFF = "☐"
CHECK_LOCKED = "·"
RISK_TAG_COLORS = {
    "safe": "#107c10",
    "review": "#9a6700",
    "risky": "#c42b1c",
}
FILTER_LABELS = (
    ("all", "全部"),
    ("safe", "可清理"),
    ("review", "需确认"),
    ("risky", "高风险"),
)
DEFAULT_GUI_LIMIT = 200
LOG_DIR = Path.home() / ".scai"
LOG_FILE = LOG_DIR / "cleanup-log.jsonl"
STATE_FILE = LOG_DIR / "gui-state.json"


# ---------------------------------------------------------------- 回收站/废纸篓


class _SHFILEOPSTRUCTW(ctypes.Structure):
    _fields_ = [
        ("hwnd", ctypes.c_void_p),
        ("wFunc", ctypes.c_uint),
        ("pFrom", ctypes.c_wchar_p),
        ("pTo", ctypes.c_wchar_p),
        ("fFlags", ctypes.c_ushort),
        ("fAnyOperationsAborted", ctypes.c_int),
        ("hNameMappings", ctypes.c_void_p),
        ("lpszProgressTitle", ctypes.c_wchar_p),
    ]


FO_DELETE = 3
FOF_ALLOWUNDO = 0x40
FOF_NOCONFIRMATION = 0x10
FOF_SILENT = 0x4
FOF_NOERRORUI = 0x400


def move_to_trash_windows(path: Path) -> tuple[bool, str]:
    # pFrom 需要双 \0 结尾: 字符串自带一个, ctypes 再补终止符
    op = _SHFILEOPSTRUCTW()
    op.hwnd = None
    op.wFunc = FO_DELETE
    op.pFrom = str(path) + "\0"
    op.pTo = None
    op.fFlags = FOF_ALLOWUNDO | FOF_NOCONFIRMATION | FOF_SILENT | FOF_NOERRORUI
    try:
        result = ctypes.windll.shell32.SHFileOperationW(ctypes.byref(op))
    except OSError as exc:
        return False, str(exc)
    if op.fAnyOperationsAborted:
        return False, "操作被取消"
    if result != 0:
        return False, f"SHFileOperation 错误码 {result:#x}"
    return True, ""


def move_to_trash_macos(path: Path) -> tuple[bool, str]:
    escaped = str(path).replace("\\", "\\\\").replace('"', '\\"')
    script = f'tell application "Finder" to delete POSIX file "{escaped}"'
    try:
        completed = subprocess.run(
            ["osascript", "-e", script],
            capture_output=True,
            text=True,
            timeout=30,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        return False, str(exc)
    if completed.returncode != 0:
        return False, (completed.stderr or "osascript 执行失败").strip()
    return True, ""


def move_to_trash_linux(path: Path) -> tuple[bool, str]:
    # freedesktop.org 垃圾箱规范: ~/.local/share/Trash/{files,info}
    base = Path(os.environ.get("XDG_DATA_HOME", str(Path.home() / ".local" / "share")))
    files_dir = base / "Trash" / "files"
    info_dir = base / "Trash" / "info"
    try:
        files_dir.mkdir(parents=True, exist_ok=True)
        info_dir.mkdir(parents=True, exist_ok=True)
        dest = files_dir / path.name
        if dest.exists():
            dest = files_dir / f"{path.name}__{datetime.now().strftime('%Y%m%d%H%M%S')}"
        info_file = info_dir / f"{dest.name}.trashinfo"
        info_file.write_text(
            "[Trash Info]\n"
            f"Path={quote(str(path))}\n"
            f"DeletionDate={datetime.now().isoformat(timespec='seconds')}\n",
            encoding="utf-8",
        )
        os.rename(path, dest)
    except OSError as exc:
        return False, str(exc)
    return True, ""


def move_to_trash(path: Path) -> tuple[bool, str]:
    if not path.exists():
        return False, "路径已不存在"
    if IS_WINDOWS:
        return move_to_trash_windows(path)
    if IS_MACOS:
        return move_to_trash_macos(path)
    return move_to_trash_linux(path)


def append_cleanup_log(records: list[dict[str, object]]) -> None:
    try:
        LOG_DIR.mkdir(parents=True, exist_ok=True)
        stamp = datetime.now().isoformat(timespec="seconds")
        with LOG_FILE.open("a", encoding="utf-8") as handle:
            for record in records:
                entry = {"time": stamp, "action": "move_to_trash", **record}
                handle.write(json.dumps(entry, ensure_ascii=False) + "\n")
    except OSError:
        pass


def open_log_location() -> None:
    try:
        LOG_DIR.mkdir(parents=True, exist_ok=True)
        if not LOG_FILE.exists():
            LOG_FILE.write_text("", encoding="utf-8")
        if IS_WINDOWS:
            os.startfile(str(LOG_FILE))  # type: ignore[attr-defined]
        elif IS_MACOS:
            subprocess.run(["open", str(LOG_FILE)], check=False)
        else:
            subprocess.run(["xdg-open", str(LOG_FILE)], check=False)
    except OSError:
        pass


def load_last_root() -> Path | None:
    try:
        data = json.loads(STATE_FILE.read_text(encoding="utf-8"))
        root = Path(str(data.get("last_root", "")))
        if root.is_dir():
            return root
    except (OSError, ValueError, TypeError):
        pass
    return None


def save_last_root(root: Path) -> None:
    try:
        STATE_FILE.parent.mkdir(parents=True, exist_ok=True)
        STATE_FILE.write_text(json.dumps({"last_root": str(root)}, ensure_ascii=False), encoding="utf-8")
    except OSError:
        pass


# ---------------------------------------------------------------- GUI


@dataclass
class GuiRow:
    path: Path
    size: int
    kind: str
    risk: str
    category: str
    reason: str
    action: str
    mtime: float
    checked: bool = False

    @property
    def deletable(self) -> bool:
        return self.risk != "risky"


class ScaiGuiApp:
    def __init__(
        self,
        root_path: Path,
        include_all: bool = False,
        limit: int = DEFAULT_GUI_LIMIT,
        auto_scan: bool = True,
    ) -> None:
        self.scan_root = root_path
        self.include_all = include_all
        self.limit = limit
        self.auto_scan = auto_scan
        self.rows: list[GuiRow] = []
        self.filter = "all"
        self.analysis = None
        self.scan_thread: threading.Thread | None = None
        self.scan_started_at = 0.0
        self.events: queue.Queue[tuple[str, object]] = queue.Queue()

        self.root = tk.Tk()
        self.root.title("Scai — 磁盘空间清理顾问")
        self.root.minsize(860, 520)
        self.root.geometry("1080x680")

        self.path_var = tk.StringVar(value=str(root_path))
        self.filter_var = tk.StringVar(value="all")
        self.status_var = tk.StringVar(value="选择目录后点击「扫描」")
        self.selection_var = tk.StringVar(value="未选择项目")
        self.target_var = tk.StringVar(value="20g")

        self._build_widgets()
        if self.auto_scan:
            self.root.after(50, self.start_scan)
        else:
            self.status_var.set(f"目录已就绪（{root_path}），点击「扫描」开始分析")

    # ---------------------------------------------------------------- 界面搭建

    def _build_widgets(self) -> None:
        top = ttk.Frame(self.root, padding=(8, 8, 8, 4))
        top.pack(fill=tk.X)
        ttk.Label(top, text="目录:").pack(side=tk.LEFT)
        self.path_entry = ttk.Entry(top, textvariable=self.path_var)
        self.path_entry.pack(side=tk.LEFT, fill=tk.X, expand=True, padx=6)
        ttk.Button(top, text="浏览…", command=self.browse_directory).pack(side=tk.LEFT)
        self.scan_button = ttk.Button(top, text="扫描", command=self.start_scan)
        self.scan_button.pack(side=tk.LEFT, padx=(6, 0))
        ttk.Button(top, text="全盘扫描", command=self.scan_computer).pack(side=tk.LEFT, padx=(6, 0))

        toolbar = ttk.Frame(self.root, padding=(8, 0, 8, 4))
        toolbar.pack(fill=tk.X)
        for value, label in FILTER_LABELS:
            ttk.Radiobutton(
                toolbar,
                text=label,
                value=value,
                variable=self.filter_var,
                command=self.refresh_tree,
            ).pack(side=tk.LEFT)
        self.include_all_var = tk.BooleanVar(value=self.include_all)
        ttk.Checkbutton(
            toolbar,
            text="显示默认排除目录",
            variable=self.include_all_var,
        ).pack(side=tk.LEFT, padx=(16, 0))
        ttk.Label(toolbar, text="目标释放:").pack(side=tk.LEFT, padx=(16, 0))
        self.target_entry = ttk.Entry(toolbar, textvariable=self.target_var, width=8)
        self.target_entry.pack(side=tk.LEFT, padx=(4, 0))
        self.auto_check_button = ttk.Button(toolbar, text="按目标勾选", command=self.auto_check_by_target)
        self.auto_check_button.pack(side=tk.LEFT, padx=(4, 0))

        self.progress = ttk.Progressbar(self.root, mode="indeterminate")
        self.progress.pack(fill=tk.X, padx=8)

        columns = ("check", "kind", "size", "risk", "category", "path", "mtime")
        self.tree = ttk.Treeview(self.root, columns=columns, show="headings", selectmode="browse")
        headers = {
            "check": ("选择", 44, tk.W),
            "kind": ("类型", 52, tk.W),
            "size": ("大小", 90, tk.E),
            "risk": ("风险", 90, tk.W),
            "category": ("分类", 150, tk.W),
            "path": ("路径", 420, tk.W),
            "mtime": ("修改时间", 150, tk.W),
        }
        for key, (text, width, anchor) in headers.items():
            self.tree.heading(key, text=text)
            self.tree.column(key, width=width, anchor=anchor, stretch=(key == "path"))
        for risk, color in RISK_TAG_COLORS.items():
            self.tree.tag_configure(risk, foreground=color)
        scrollbar = ttk.Scrollbar(self.root, orient=tk.VERTICAL, command=self.tree.yview)
        self.tree.configure(yscrollcommand=scrollbar.set)
        self.tree.pack(fill=tk.BOTH, expand=True, padx=(8, 0), pady=(4, 0))
        scrollbar.pack(side=tk.RIGHT, fill=tk.Y, pady=(4, 0))

        self.tree.bind("<Button-1>", self.on_tree_click)
        self.tree.bind("<space>", self.on_tree_space)
        self.tree.bind("<<TreeviewSelect>>", self.on_tree_select)
        self.tree.bind("<Double-1>", lambda _event: self.reveal_location())

        detail_label = ttk.Label(self.root, text="选中项详情与建议:", padding=(8, 6, 8, 0))
        detail_label.pack(fill=tk.X)
        self.detail = tk.Text(self.root, height=6, wrap=tk.WORD, state=tk.DISABLED)
        self.detail.pack(fill=tk.X, padx=8)

        bottom = ttk.Frame(self.root, padding=(8, 6, 8, 8))
        bottom.pack(fill=tk.X)
        self.selection_label = ttk.Label(bottom, textvariable=self.selection_var)
        self.selection_label.pack(side=tk.LEFT)
        ttk.Button(bottom, text="打开日志", command=open_log_location).pack(side=tk.RIGHT)
        self.delete_button = ttk.Button(bottom, text="删除所选（移到回收站）", command=self.delete_selected, state=tk.DISABLED)
        self.delete_button.pack(side=tk.RIGHT, padx=(0, 8))
        self.reveal_button = ttk.Button(bottom, text="打开位置", command=self.reveal_location)
        self.reveal_button.pack(side=tk.RIGHT, padx=(0, 8))
        self.ai_button = ttk.Button(bottom, text="AI 提示词", command=self.show_ai_prompt)
        self.ai_button.pack(side=tk.RIGHT, padx=(0, 8))

        statusbar = ttk.Label(self.root, textvariable=self.status_var, relief=tk.SUNKEN, padding=(6, 2))
        statusbar.pack(fill=tk.X, side=tk.BOTTOM)

    # ---------------------------------------------------------------- 扫描

    def browse_directory(self) -> None:
        chosen = filedialog.askdirectory(initialdir=self.path_var.get() or str(Path.home()))
        if chosen:
            self.path_var.set(chosen)

    def scan_computer(self) -> None:
        self.path_var.set(str(scai.COMPUTER_SCAN_ROOT))
        self.start_scan()

    def start_scan(self) -> None:
        if self.scan_thread is not None and self.scan_thread.is_alive():
            return
        target = Path(self.path_var.get()).expanduser()
        resolved = target.resolve()
        if not resolved.exists():
            messagebox.showwarning("Scai", f"路径不存在: {resolved}")
            return
        self.scan_root = resolved
        self.include_all = self.include_all_var.get()
        save_last_root(resolved)
        self.rows = []
        self.refresh_tree()
        self.set_detail("")
        self.scan_button.state(["disabled"])
        self.delete_button.state(["disabled"])
        self.progress.start(12)
        self.scan_started_at = time.time()
        self.status_var.set(f"正在扫描 {resolved} …")
        root, include_all, limit = resolved, self.include_all, self.limit

        def worker() -> None:
            start = time.time()
            try:
                analysis = scai.create_space_analysis(root=root, limit=limit, include_all=include_all, max_depth=1)
            except Exception as exc:  # 扫描线程里的任何异常都回传主线程展示
                self.events.put(("error", str(exc)))
                return
            self.events.put(("done", (analysis, time.time() - start)))

        self.scan_thread = threading.Thread(target=worker, daemon=True)
        self.scan_thread.start()
        self.root.after(100, self.poll_scan)

    def poll_scan(self) -> None:
        if self.scan_thread is not None and self.scan_thread.is_alive():
            elapsed = time.time() - self.scan_started_at
            self.status_var.set(f"正在扫描 {self.scan_root} … 已用时 {elapsed:.1f}s")
            try:
                self.root.after(100, self.poll_scan)
            except tk.TclError:
                pass
            return
        try:
            kind, payload = self.events.get_nowait()
        except queue.Empty:
            self.finish_scan(None, 0.0)
            return
        if kind == "error":
            self.finish_scan(None, 0.0)
            messagebox.showerror("Scai", f"扫描失败: {payload}")
            return
        analysis, elapsed = payload  # type: ignore[misc]
        self.finish_scan(analysis, elapsed)

    def finish_scan(self, analysis, elapsed: float) -> None:
        self.progress.stop()
        self.scan_button.state(["!disabled"])
        if analysis is None:
            self.status_var.set("扫描未完成")
            return
        self.analysis = analysis
        self.rows = []
        for record in analysis.dirs:
            insight = scai.classify_path(record.path, record.size, "dir")
            self.rows.append(
                GuiRow(
                    path=record.path,
                    size=record.size,
                    kind="文件夹",
                    risk=insight.risk,
                    category=insight.category,
                    reason=insight.reason,
                    action=insight.action,
                    mtime=record.mtime,
                )
            )
        for record in analysis.files:
            insight = scai.classify_path(record.path, record.size, "file")
            self.rows.append(
                GuiRow(
                    path=record.path,
                    size=record.size,
                    kind="文件",
                    risk=insight.risk,
                    category=insight.category,
                    reason=insight.reason,
                    action=insight.action,
                    mtime=record.mtime,
                )
            )
        self.rows.sort(key=lambda row: row.size, reverse=True)
        stats = analysis.dir_stats
        file_stats = analysis.file_stats
        self.status_var.set(
            f"扫描完成: 目录 {stats.scanned_dirs} 个, 文件 {file_stats.scanned_files} 个, "
            f"跳过 {stats.skipped_dirs + file_stats.skipped_dirs} 个, 用时 {elapsed:.1f}s"
        )
        self.refresh_tree()

    # ---------------------------------------------------------------- 列表展示

    def refresh_tree(self) -> None:
        self.filter = self.filter_var.get()
        self.tree.delete(*self.tree.get_children())
        for row in self.rows:
            if self.filter != "all" and row.risk != self.filter:
                continue
            check = CHECK_LOCKED if row.risk == "risky" else (CHECK_ON if row.checked else CHECK_OFF)
            self.tree.insert(
                "",
                tk.END,
                values=(
                    check,
                    row.kind,
                    scai.human_size(row.size),
                    scai.risk_label(row.risk),
                    row.category,
                    scai.display_name(row.path, self.scan_root),
                    scai.format_mtime(row.mtime),
                ),
                tags=(row.risk,),
            )
        self.update_selection_summary()

    def row_at(self, item_id: str) -> GuiRow | None:
        visible = [row for row in self.rows if self.filter == "all" or row.risk == self.filter]
        children = self.tree.get_children()
        try:
            index = children.index(item_id)
        except ValueError:
            return None
        return visible[index] if 0 <= index < len(visible) else None

    def on_tree_click(self, event: tk.Event) -> None:
        if self.tree.identify_region(event.x, event.y) != "cell":
            return
        if self.tree.identify_column(event.x) != "#1":
            return
        item_id = self.tree.identify_row(event.y)
        row = self.row_at(item_id)
        if row is None or not row.deletable:
            return
        row.checked = not row.checked
        values = list(self.tree.item(item_id, "values"))
        values[0] = CHECK_ON if row.checked else CHECK_OFF
        self.tree.item(item_id, values=values)
        self.update_selection_summary()

    def on_tree_space(self, _event: tk.Event) -> None:
        selection = self.tree.selection()
        if not selection:
            return
        row = self.row_at(selection[0])
        if row is None or not row.deletable:
            return
        row.checked = not row.checked
        self.refresh_tree()

    def on_tree_select(self, _event: tk.Event) -> None:
        selection = self.tree.selection()
        if not selection:
            return
        row = self.row_at(selection[0])
        if row is not None:
            self.set_detail(
                f"路径: {row.path}\n"
                f"类型: {row.kind} | 大小: {scai.human_size(row.size)} | 风险: {scai.risk_label(row.risk)} | 分类: {row.category}\n"
                f"判断: {row.reason}\n"
                f"建议: {row.action}"
            )

    def set_detail(self, text: str) -> None:
        self.detail.config(state=tk.NORMAL)
        self.detail.delete("1.0", tk.END)
        self.detail.insert("1.0", text)
        self.detail.config(state=tk.DISABLED)

    # ---------------------------------------------------------------- 选择与删除

    def auto_check_by_target(self) -> None:
        if not self.rows:
            messagebox.showinfo("Scai", "请先完成一次扫描。")
            return
        try:
            target = scai.parse_size(self.target_var.get().strip())
        except ValueError as exc:
            messagebox.showwarning("Scai", f"目标大小无效: {exc}\n示例: 20g、500m")
            return
        insights = [
            scai.Insight(
                path=row.path,
                size=row.size,
                kind="dir" if row.kind == "文件夹" else "file",
                risk=row.risk,
                category=row.category,
                reason=row.reason,
                action=row.action,
            )
            for row in self.rows
        ]
        selected, total = scai.select_plan_items(insights, target, root=self.scan_root)
        selected_paths = {str(item.path) for item in selected}
        for row in self.rows:
            row.checked = str(row.path) in selected_paths and row.deletable
        self.refresh_tree()
        self.status_var.set(
            f"已按目标 {scai.human_size(target)} 勾选 {len(selected)} 项, 共约 {scai.human_size(total)}; 请人工复核后再删除。"
        )

    def reveal_location(self) -> None:
        selection = self.tree.selection()
        if not selection:
            messagebox.showinfo("Scai", "请先在列表中选择一个项目。")
            return
        row = self.row_at(selection[0])
        if row is None:
            return
        if not row.path.exists():
            messagebox.showinfo("Scai", f"路径已不存在:\n{row.path}")
            return
        try:
            if IS_WINDOWS:
                subprocess.run(["explorer", "/select,", str(row.path)], check=False)
            elif IS_MACOS:
                subprocess.run(["open", "-R", str(row.path)], check=False)
            else:
                subprocess.run(["xdg-open", str(row.path.parent)], check=False)
        except OSError:
            pass

    # ---------------------------------------------------------------- AI 提示词

    def show_ai_prompt(self) -> None:
        """生成包含扫描摘要的诊断提示词, 供用户复制给任意 AI; 本程序不调用任何 AI 服务。"""
        if self.analysis is None:
            messagebox.showinfo("Scai AI 提示词", "请先完成一次扫描, 再生成 AI 提示词。")
            return
        prompt = scai.build_ai_prompt(self.analysis)
        window = tk.Toplevel(self.root)
        window.title("Scai AI 诊断提示词")
        window.geometry("880x620")
        window.transient(self.root)
        intro = (
            "以下提示词已包含本次扫描摘要(JSON)。点击「复制全部」后粘贴到任意 AI 对话"
            "(ChatGPT / Claude / Gemini / Codex 等)即可获得磁盘清理诊断。Scai 不会调用任何 AI 服务。"
        )
        ttk.Label(window, text=intro, wraplength=840, padding=(8, 8, 8, 4)).pack(fill=tk.X)
        frame = ttk.Frame(window)
        frame.pack(fill=tk.BOTH, expand=True, padx=8)
        text = tk.Text(frame, wrap=tk.WORD, font=tkfont.nametofont("TkFixedFont"))
        scrollbar = ttk.Scrollbar(frame, orient=tk.VERTICAL, command=text.yview)
        text.configure(yscrollcommand=scrollbar.set)
        scrollbar.pack(side=tk.RIGHT, fill=tk.Y)
        text.pack(fill=tk.BOTH, expand=True)
        text.insert("1.0", prompt)
        text.config(state=tk.DISABLED)

        def copy_prompt() -> None:
            self.root.clipboard_clear()
            self.root.clipboard_append(prompt)
            self.status_var.set("AI 提示词已复制到剪贴板, 可直接粘贴给任意 AI。")

        buttons = ttk.Frame(window, padding=(0, 6))
        buttons.pack()
        ttk.Button(buttons, text="复制全部", command=copy_prompt).pack(side=tk.LEFT, padx=4)
        ttk.Button(buttons, text="关闭", command=window.destroy).pack(side=tk.LEFT, padx=4)
        window.bind("<Escape>", lambda _event: window.destroy())
        window.grab_set()

    def checked_rows(self) -> list[GuiRow]:
        return [row for row in self.rows if row.checked and row.deletable]

    def dedupe_overlaps(self, rows: list[GuiRow]) -> list[GuiRow]:
        # 同时勾选父目录和其中的文件时, 只处理更大的父项, 避免重复计数和删除冲突
        taken: list[GuiRow] = []
        for row in sorted(rows, key=lambda item: item.size, reverse=True):
            if any(scai.paths_overlap(row.path, kept.path) for kept in taken):
                continue
            taken.append(row)
        return taken

    def update_selection_summary(self) -> None:
        selected = self.dedupe_overlaps(self.checked_rows())
        total = sum(row.size for row in selected)
        if not selected:
            self.selection_var.set("未选择项目")
            self.delete_button.state(["disabled"])
        else:
            self.selection_var.set(f"已选 {len(selected)} 项, 共约 {scai.human_size(total)}")
            self.delete_button.state(["!disabled"])

    def delete_selected(self) -> None:
        selected = self.dedupe_overlaps(self.checked_rows())
        if not selected:
            return
        total = sum(row.size for row in selected)
        preview = "\n".join(
            f"  {scai.human_size(row.size):>10}  {row.path}" for row in selected[:8]
        )
        if len(selected) > 8:
            preview += f"\n  …… 等共 {len(selected)} 项"
        confirmed = messagebox.askyesno(
            "Scai 确认删除",
            f"将把以下 {len(selected)} 个项目移动到回收站, 共约 {scai.human_size(total)}:\n\n{preview}\n\n"
            "回收站中的内容可以恢复。确定继续吗?",
            icon="warning",
        )
        if not confirmed:
            return

        self.delete_button.state(["disabled"])
        success: list[GuiRow] = []
        log_records: list[dict[str, object]] = []
        for row in selected:
            ok, error = move_to_trash(row.path)
            log_records.append(
                {
                    "path": str(row.path),
                    "size": row.size,
                    "kind": row.kind,
                    "risk": row.risk,
                    "category": row.category,
                    "ok": ok,
                    "error": error or None,
                }
            )
            if ok:
                success.append(row)
            elif not row.path.exists():
                self.status_var.set(f"跳过已消失的路径: {row.path}")
        append_cleanup_log(log_records)

        failed = len(selected) - len(success)
        freed = sum(row.size for row in success)
        for row in success:
            self.rows.remove(row)
        self.refresh_tree()
        self.set_detail("")
        self.status_var.set(f"已移动 {len(success)} 项到回收站 (约 {scai.human_size(freed)}); 建议重新扫描获得最新空间分布。")
        if failed:
            messagebox.showwarning(
                "Scai 清理结果",
                f"成功 {len(success)} 项, 失败 {failed} 项。\n失败原因已写入日志, 可点击「打开日志」查看。",
            )
        else:
            messagebox.showinfo(
                "Scai 清理结果",
                f"已成功移动 {len(success)} 项到回收站, 共约 {scai.human_size(freed)}。",
            )

    # ---------------------------------------------------------------- 运行

    def run(self) -> None:
        smoke_ms = os.environ.get("SCAI_GUI_SMOKE_MS")
        if smoke_ms and smoke_ms.isdigit():
            self.root.after(int(smoke_ms), self.root.destroy)
        self.root.mainloop()


def launch(
    root_path: Path,
    include_all: bool = False,
    limit: int = DEFAULT_GUI_LIMIT,
    auto_scan: bool = True,
) -> int:
    try:
        app = ScaiGuiApp(root_path=root_path, include_all=include_all, limit=limit, auto_scan=auto_scan)
    except tk.TclError as exc:
        print(f"无法创建图形界面: {exc}", file=sys.stderr)
        return 2
    app.run()
    return 0


def main(argv: list[str] | None = None) -> int:
    args = sys.argv[1:] if argv is None else argv
    include_all = "--all" in args
    positional = [arg for arg in args if not arg.startswith("-")]
    if positional:
        candidate = Path(positional[0]).expanduser()
        if candidate.exists():
            return launch(root_path=candidate.resolve(), include_all=include_all, auto_scan=True)
    # 无参数启动(双击 exe): 恢复上次扫描目录或用户主目录, 不自动扫描 exe 所在目录
    last = load_last_root()
    return launch(root_path=last or Path.home(), include_all=include_all, auto_scan=False)


if __name__ == "__main__":
    sys.exit(main())
