#!/usr/bin/env python3
"""把 Scai 打包成独立可执行文件（Windows 下为 dist/scai.exe 与 dist/scai-gui.exe）。

用法:
    python build_exe.py

    网络不佳时可指定 PyPI 镜像，例如:
    SCAI_PIP_INDEX_URL=https://pypi.tuna.tsinghua.edu.cn/simple python build_exe.py   (bash)
    set SCAI_PIP_INDEX_URL=https://pypi.tuna.tsinghua.edu.cn/simple && python build_exe.py   (cmd)

流程:
    1. 在 .venv-build 中准备独立构建环境（不污染用户 Python）。
    2. 安装 PyInstaller；Windows 上额外安装 windows-curses，让 exe 的 TUI 开箱即用。
    3. 单文件模式打包 scai（控制台 CLI/TUI）与 scai-gui（无控制台图形界面）。

构建产物 build/、dist/、*.spec、.venv-build/ 均不进入版本库。
"""
from __future__ import annotations

import os
import subprocess
import sys
import venv
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
VENV_DIR = SCRIPT_DIR / ".venv-build"
IS_WINDOWS = os.name == "nt"


def note(message: str) -> None:
    print(f"[build_exe] {message}")


def run(command: list[str]) -> None:
    subprocess.run(command, check=True)


def pip_install(venv_python: Path, *packages: str) -> None:
    command = [str(venv_python), "-m", "pip", "install", "--disable-pip-version-check", "--quiet"]
    index_url = os.environ.get("SCAI_PIP_INDEX_URL")
    if index_url:
        command += ["-i", index_url]
    run(command + list(packages))


def ensure_build_env() -> Path:
    venv_python = VENV_DIR / ("Scripts/python.exe" if IS_WINDOWS else "bin/python")
    if not venv_python.exists():
        note(f"创建构建虚拟环境: {VENV_DIR}")
        venv.create(VENV_DIR, with_pip=True)
    return venv_python


def main() -> int:
    try:
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
        sys.stderr.reconfigure(encoding="utf-8", errors="replace")
    except (AttributeError, OSError):
        pass

    venv_python = ensure_build_env()

    note("安装 PyInstaller")
    pip_install(venv_python, "pyinstaller")
    if IS_WINDOWS:
        note("安装 windows-curses（打包进 exe，保证 TUI 可用）")
        pip_install(venv_python, "windows-curses")

    targets = [
        ("scai", SCRIPT_DIR / "scai.py", []),
        # scai-gui 双击直接进图形界面，不附带控制台窗口
        ("scai-gui", SCRIPT_DIR / "scai_gui_main.py", ["--noconsole"]),
    ]
    for name, entry, extra_flags in targets:
        note(f"开始打包 {name}")
        run(
            [
                str(venv_python),
                "-m",
                "PyInstaller",
                "--noconfirm",
                "--clean",
                "--onefile",
                "--name",
                name,
                *extra_flags,
                str(entry),
            ]
        )

    note("完成:")
    for name, _, _ in targets:
        output = SCRIPT_DIR / "dist" / (f"{name}.exe" if IS_WINDOWS else name)
        note(f"  {output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
