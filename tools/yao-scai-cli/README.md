# Diskoala 磁盘考拉

**Diskoala** (Disk + Koala, formerly *Scai*) is a friendly disk space advisor by **Koding Studio**: CLI for decisions, TUI for exploration, GUI for human-driven cleanup.

> **Native upgrade in progress**: `../diskoala-native` contains the Rust + Tauri rewrite (CLI 614 KB, GUI 9 MB, byte-compatible CLI output, same web frontend). This Python version remains the maintenance line.

Tool folder: `tools/yao-scai-cli`  
Project: `Diskoala`  
Command: `diskoala` (legacy aliases: `scai` / `bf` / `scan`)

Diskoala is not meant to be only another `du` wrapper. The goal is to scan disk usage, classify what matters, explain cleanup risk, and generate safe reclaim plans.

## Features

- CLI-first Space Brief as the default experience.
- Dynamic scan progress in interactive terminals.
- Top 50 file details in the default brief, with `diskoala more` for longer lists.
- TUI browser for interactive exploration.
- GUI companion (`diskoala gui` / `diskoala-gui.exe`): scan, risk-colored suggestions, checkbox selection, and one-click cleanup that moves items to the Recycle Bin with a full audit log.
- Rule analysis engine for caches, build artifacts, archives, media, backups, data files, and risky system paths.
- Cleanup plan generation with no deletion side effects.
- Optional AI diagnosis through the official `codex exec` CLI when available.
- Terminal-friendly AI Markdown rendering for headings, emphasis, lists, links, and code blocks.
- Plain table output for largest files and folders.
- No third-party runtime dependency on macOS/Linux; the TUI uses Python standard library `curses`.
- Windows support: CLI works with stock Python; TUI optionally needs `windows-curses`.
- Windows-aware risk rules for `C:\Windows`, `Program Files`, `AppData`, Temp caches, installers, `pagefile.sys` / `hiberfil.sys`, and `Windows.old`.

## Install

### macOS / Linux

```bash
./install.sh
```

The installer creates or updates these commands in `$HOME/bin`:

- `diskoala`: main command.
- `scai` / `bf` / `scan`: legacy aliases.
- `bf`: legacy alias.
- `scan`: compatibility alias, defaults to table-style top files.

Make sure `$HOME/bin` is in your `PATH`.

### Windows

Requirements: Python 3 on `PATH` (`python` or `py -3`).

From this tool directory in PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File .\install.ps1
```

Or double-click / run:

```bat
install.cmd
```

This creates `%USERPROFILE%\bin\diskoala.cmd` (plus `scai.cmd` / `bf.cmd` / `scan.cmd` legacy aliases) and adds `%USERPROFILE%\bin` to your user `PATH` if needed. Open a **new** terminal after install.

Optional TUI support:

```powershell
python -m pip install windows-curses
```

Without install, you can always run:

```powershell
python .\scai.py --help
python .\scai.py $env:USERPROFILE
python .\scai.py plan 20g $env:USERPROFILE
```

### Build a standalone exe (optional)

To package Diskoala into portable executables that need no Python on the target machine:

```powershell
python .\build_exe.py
```

The build script creates an isolated `.venv-build`, installs PyInstaller, `windows-curses` and `pywebview`, and produces two fully portable single-file executables: `dist\diskoala.exe` (console CLI/TUI, about 8 MB) and `dist\diskoala-gui.exe` (Web GUI rendered with the system WebView2 runtime, about 18 MB, double-click to launch; falls back to the legacy tkinter UI if WebView2 is missing). No installation or Python is needed on the target machine — copy them to any folder (or a USB stick) and run. Optionally put them on `PATH`, e.g. `%USERPROFILE%\bin`.

If the default PyPI is slow, point the build at a mirror:

```powershell
$env:SCAI_PIP_INDEX_URL = "https://pypi.tuna.tsinghua.edu.cn/simple"; python .\build_exe.py
```

Build outputs (`build/`, `dist/`, `*.spec`, `.venv-build/`) are gitignored; rebuild any time with the same command.

## Core Commands

```bash
diskoala              # Space Brief for the current directory
diskoala all          # safe full-computer scan (C:\ on Windows, / elsewhere)
diskoala top          # largest files
diskoala more         # show more largest files, default Top 100
diskoala more 200     # show Top 200 largest files
diskoala dirs         # largest folders
diskoala tui          # open TUI browser
diskoala explain PATH # explain one file or folder
diskoala plan 20g     # generate a reclaim plan
diskoala ai           # ask Codex CLI to analyze the scan summary
diskoala gui          # open the GUI (scan, advice, safe cleanup)
```

### Windows cleanup examples

```powershell
diskoala C:\Users\你的用户名
diskoala top C:\Users\你的用户名 --limit 50
diskoala dirs C:\Users\你的用户名 --max-depth 2
diskoala plan 20g C:\Users\你的用户名
diskoala explain C:\Users\你的用户名\Downloads\big-setup.exe
diskoala all
```

`diskoala all` on Windows scans the system drive (`C:\` by default) while skipping system-managed trees such as `Windows`, `Program Files`, and `ProgramData`. The CLI is read-only — it explains risk and builds reclaim plans; deletion happens only in the GUI (Recycle Bin by default, permanent delete as an explicit opt-in).

Short forms still work:

```bash
diskoala 50
diskoala d
diskoala all
diskoala more
diskoala ~/Downloads
diskoala --plain ~/Downloads 30
```

## Default Brief

`scai` scans the current directory by default and prints a high-signal CLI overview instead of opening the TUI. In an interactive terminal it shows a live scanning spinner with elapsed time before printing results. Use `scai all` for a safe full-computer scan from `/`; use `--all` only when you explicitly want to disable default exclusions.

```text
Scai Space Brief

主要占用:
  1. Downloads                                  42.1 GB
  2. Projects                                   31.4 GB

可安全关注:
  - 开发缓存/构建产物: 约 8.2 GB

需要确认:
  - 历史备份/归档: 约 12.4 GB
  - 大媒体文件: 约 21.8 GB

Top 50 文件明细:
  编号          大小  风险        分类              文件
   1      3.2 GB  需要确认      大媒体文件           videos/demo.mov
   2      1.8 GB  需要确认      压缩包/镜像          Downloads/archive.zip

显示更多:
  - scai more        显示 Top 100 文件
  - scai more 200    显示 Top 200 文件

下一步:
  - scai top          查看最大文件
  - scai dirs         查看最大文件夹
  - scai tui          进入交互浏览
  - scai plan 20g     生成释放空间方案
  - scai ai           生成 AI 诊断
```

## TUI

Use the TUI when you want to browse and compare results interactively:

```bash
diskoala tui
diskoala tui ~/Downloads
diskoala tui ~/Projects --mode dirs
```

TUI keys:

- `q`: quit.
- `j/k` or `up/down`: move selection.
- `PageUp/PageDown`: scroll faster.
- `r`: rescan.
- `f`: switch to file mode.
- `d`: switch to directory mode.
- `/`: enter a new scan path.
- `c`: scan from `/`.
- `h`: return to the directory where Scai started.
- `.`: scan the current working directory.
- `+/-`: adjust the result limit.
- `a`: toggle default exclusions.
- `[` / `]`: adjust `max-depth` in directory mode.
- `?`: show or hide help.

The bottom panel follows the current selection and shows available metadata such as name, type, size, extension, modified/access/created times, permissions, owner/group, inode, relative path, absolute path, parent folder, risk category, and the safer next action.

## GUI

`diskoala gui` (or double-click `diskoala-gui.exe`) opens a modern windowed interface for human-driven cleanup, rebuilt 1:1 from the high-fidelity mockups in `docs/ui参考/` (pywebview + local HTML; falls back to the legacy tkinter UI when WebView2 is unavailable):

- **Empty state**: welcome screen with the last scanned directory prefilled and the last scan date.
- **Scanning state**: live directory/file counters, elapsed time, and a cancel button.
- **Result state**: three overview cards (root total / safely reclaimable / needs review), risk filter tabs with live counts, a file table with pill-style risk badges (high-risk rows show a lock and cannot be checked), a clickable treemap of directory sizes, and a detail panel explaining every judgement.
- **Decision bar**: deduplicated selection summary (with safe/review breakdown), target-based auto-check (e.g. `20g`), AI prompt window (copy a diagnosis prompt with the JSON summary to any AI chat — the GUI never calls an AI service), open-location, open-log, clear-selection, and the primary cleanup action. Stat cards are clickable to filter. Keyboard: Enter starts a scan from the path box, Esc closes dialogs.
- **Two delete modes** (owner decision, 2026-08-28): the confirmation dialog offers **移到回收站** (default, restorable) and **直接删除** (permanent, irreversible). Permanent delete uses a two-step armed confirmation with a red warning and auto-disarms after 5 seconds.
- **Safety model**: risky items are locked server-side and client-side; the server re-filters risky and overlapping paths on every delete call; every action — `move_to_trash` or `delete_permanent` — is appended to `~/.scai/cleanup-log.jsonl`.
- Light/dark themes; the window is fully offline.

```bash
diskoala gui
diskoala gui ~/Downloads
```

## Agent Skill

Ship Scai to agent environments as a skill plus portable binaries:

```powershell
python .\build_exe.py   # produces dist\scai.exe / dist\scai-gui.exe
python .\build_zip.py   # produces dist\scai-skill.zip (~22 MB)
```

The zip contains `diskoala-skill/` with `SKILL.md` (teaches agents the scai workflow: scan → explain → plan → hand deletion to the user, never delete permanently), `INSTALL.md`, one-click installers (`install-skill.cmd` / `install-skill.sh`), and `bin/` holding both portable exes. The installer places the skill into `~/.agents/skills/diskoala/` and the exes onto `PATH`; SKILL.md source lives in `skill/` in this repo.

## Rule Analysis

Scai classifies scan results into risk levels:

- `safe`: likely rebuildable or low-risk, such as `node_modules`, `.next`, `dist`, `target`, and cache folders.
- `review`: needs human confirmation, such as archives, downloads, media files, backups, data files, and unknown large items.
- `risky`: system or application-managed paths that should not be removed directly.

The first version is intentionally conservative. It explains why an item was classified and what action is safer.

## Reclaim Plans

`scai plan` produces a plan only; it never deletes files:

```bash
diskoala plan 10g
diskoala plan 500m ~/Downloads
diskoala plan 20g all
diskoala plan 20g ~/Projects --all
```

Plans prefer `safe` candidates first, then `review` candidates. Future cleanup execution should default to moving items to Trash and logging every action.

## AI Diagnosis

`scai ai` summarizes local scan results and passes only that JSON summary to the official `codex exec` CLI. Scai does not read file contents, does not copy local login credentials, and does not read Codex tokens. Authentication is handled by the already-installed local Codex CLI and its existing login state.

The AI prompt contains paths, sizes, formats, rule categories, risk labels, and suggested actions. It does not include file contents. Codex runs with `--sandbox read-only`, so the AI diagnosis step is analysis-only.

AI responses are rendered for terminals: Markdown headings, bold text, lists, links, and code blocks are cleaned up so raw markers like `**text**` or `* item` do not dominate the output.

```bash
diskoala ai
diskoala ai ~/Downloads --timeout 240
```

If Codex is unavailable or times out, Scai falls back to the local rule-based Space Brief.

## Compatibility

`bf` remains a legacy alias. `scan` remains a table-first compatibility entry:

```bash
bf
scan
scan dirs ~/Downloads --limit 30
scan --tui ~/Downloads
```

`scai --plain PATH 30` maps to the old table-style top-file output.

---

Diskoala is built by **Koding Studio**. Homepage and social links: coming soon (fill `APP_HOMEPAGE` in `scai.py`; the About dialog and empty-state footer link automatically). Version: `diskoala --version` / in-app 关于 dialog.
