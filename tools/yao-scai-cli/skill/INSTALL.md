# Scai Agent Skill 安装说明

本包（`scai-skill`）包含：

- `SKILL.md` — agent skill 本体：教 agent 用 scai 做磁盘分析、风险解释、生成清理方案并安全移交删除操作。
- `bin/scai.exe`、`bin/scai-gui.exe` — Windows 便携版（单文件，无需安装 Python）。`scai.exe` 是 CLI/TUI，`scai-gui.exe` 双击打开图形界面。
- 安装脚本（install-skill.cmd / install-skill.sh）。

## Windows

双击 `install-skill.cmd`，或在命令行运行：

```bat
install-skill.cmd
```

脚本会把 skill 安装到 `%USERPROFILE%\.agents\skills\scai\`，并把 `bin\` 下的 exe 复制到 `%USERPROFILE%\bin\`（如已构建）。新开终端后 `scai --help` 验证。

## macOS / Linux

```bash
chmod +x install-skill.sh && ./install-skill.sh
```

skill 安装到 `~/.agents/skills/scai/`。注意：本包内的 exe 仅适用于 Windows；macOS/Linux 请用仓库 `tools/yao-scai-cli/install.sh` 安装，或直接 `python scai.py ...`。

## 手动安装（任意 agent 环境）

1. 把 `SKILL.md`（或整个 `scai-skill` 目录）复制到你的 agent skills 目录——常见位置：`~/.agents/skills/scai/`、`~/.zcode/skills/scai/`、`~/.claude/skills/scai/`，以所用 agent 的约定为准。
2. 把 `bin/` 下的可执行文件放到 PATH 中的目录（或保持仓库方式 `python scai.py`）。
3. 新开会话后对 agent 说“用 scai 看看磁盘空间”即可触发。

## 安全要点

scai 与本 skill 的铁律：**扫描只读、删除交给用户**。skill 会引导 agent 生成方案并把删除操作移交用户（GUI 进回收站 + 审计日志 `~/.scai/cleanup-log.jsonl`），agent 永不永久删除文件。
