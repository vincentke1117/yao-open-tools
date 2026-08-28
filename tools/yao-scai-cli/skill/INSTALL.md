# Diskoala Agent Skill 安装说明

本包（`diskoala-skill`）包含：

- `SKILL.md` — agent skill 本体：教 agent 用 diskoala 做磁盘分析、风险解释、生成清理方案并安全移交删除操作。
- `bin/diskoala.exe`、`bin/diskoala-gui.exe` — Windows 便携版（单文件，无需安装 Python）。`diskoala.exe` 是 CLI/TUI，`diskoala-gui.exe` 双击打开图形界面。
- 安装脚本（install-skill.cmd / install-skill.sh）。

曾用名 Scai：旧命令 `scai` / `bf` / `scan` 仍作为兼容别名。

## Windows

双击 `install-skill.cmd`，或在命令行运行：

```bat
install-skill.cmd
```

脚本会把 skill 安装到 `%USERPROFILE%\.agents\skills\diskoala\`，并把 `bin\` 下的 exe 复制到 `%USERPROFILE%\bin\`（如已构建）。新开终端后 `diskoala --help` 验证。

## macOS / Linux

```bash
chmod +x install-skill.sh && ./install-skill.sh
```

skill 安装到 `~/.agents/skills/diskoala/`。注意：本包内的 exe 仅适用于 Windows；macOS/Linux 请用仓库 `tools/yao-scai-cli/install.sh` 安装，或直接 `python scai.py ...`。

## 手动安装（任意 agent 环境）

1. 把 `SKILL.md`（或整个 `diskoala-skill` 目录）复制到你的 agent skills 目录——常见位置：`~/.agents/skills/diskoala/`、`~/.zcode/skills/diskoala/`、`~/.claude/skills/diskoala/`，以所用 agent 的约定为准。
2. 把 `bin/` 下的可执行文件放到 PATH 中的目录（或保持仓库方式 `python scai.py`）。
3. 新开会话后对 agent 说"用 diskoala 看看磁盘空间"即可触发。

## 安全要点

本 skill 的铁律：**扫描只读；agent 永不永久删除**。删除默认交给用户在 GUI 完成（回收站可恢复 + 审计日志 `~/.diskoala/cleanup-log.jsonl`）；用户明确要求代删时也只允许移入回收站。

Diskoala 由 Koding Studio 制作。
