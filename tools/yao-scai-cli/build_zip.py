#!/usr/bin/env python3
"""把 agent skill(skill/)与便携 exe(dist/*.exe)打包为 dist/diskoala-skill.zip。

用法:
    python build_zip.py

zip 结构:
    diskoala-skill/SKILL.md            agent skill 本体
    diskoala-skill/INSTALL.md          安装说明
    diskoala-skill/install-skill.cmd   Windows 一键安装
    diskoala-skill/install-skill.sh    macOS/Linux 一键安装
    diskoala-skill/bin/diskoala.exe    便携 CLI/TUI
    diskoala-skill/bin/diskoala-gui.exe 便携 GUI

需先运行 python build_exe.py 生成 exe。
"""
from __future__ import annotations

import sys
import zipfile
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
SKILL_DIR = SCRIPT_DIR / "skill"
DIST_DIR = SCRIPT_DIR / "dist"
ZIP_PATH = DIST_DIR / "diskoala-skill.zip"
SKILL_FILES = ("SKILL.md", "INSTALL.md", "install-skill.cmd", "install-skill.sh")
EXE_NAMES = ("diskoala.exe", "diskoala-gui.exe")


def note(message: str) -> None:
    print(f"[build_zip] {message}")


def main() -> int:
    try:
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
        sys.stderr.reconfigure(encoding="utf-8", errors="replace")
    except (AttributeError, OSError):
        pass

    skill_files = [SKILL_DIR / name for name in SKILL_FILES]
    missing_skills = [path for path in skill_files if not path.exists()]
    if missing_skills:
        note(f"缺少 skill 文件: {', '.join(str(path.name) for path in missing_skills)}")
        return 1
    exes = [DIST_DIR / name for name in EXE_NAMES]
    missing_exes = [path for path in exes if not path.exists()]
    if missing_exes:
        note(f"缺少 exe: {', '.join(path.name for path in missing_exes)}; 请先运行: python build_exe.py")
        return 1

    DIST_DIR.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(ZIP_PATH, "w", zipfile.ZIP_DEFLATED) as archive:
        for path in skill_files:
            archive.write(path, f"diskoala-skill/{path.name}")
        for path in exes:
            archive.write(path, f"diskoala-skill/bin/{path.name}")
    note(f"完成: {ZIP_PATH} ({ZIP_PATH.stat().st_size / 1024 / 1024:.1f} MB)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
