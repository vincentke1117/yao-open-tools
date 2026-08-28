---
name: diskoala
description: Disk space advisor — scan usage with the diskoala CLI (formerly scai), classify cleanup risk, explain items, generate reclaim plans, and hand deletion to the user.
---

# Diskoala 磁盘空间分析与清理（磁盘考拉）

Diskoala（曾用名 Scai，`scai`/`bf`/`scan` 旧命令仍可用）是磁盘空间扫描与清理顾问：CLI 输出概览与方案，GUI 供用户自己勾选清理。**核心安全原则：agent 永不基于分析结果直接永久删除文件；删除默认进回收站，永久删除只属于用户的显式选择。**

## 何时使用

- 用户说磁盘空间不足 / C 盘红了 / 想清理垃圾 / 找大文件大目录
- 需要评估"这个文件/目录能不能删"
- 需要生成"释放 N GB"的清理方案

## 前置检查

```bash
diskoala --help       # 已安装则直接可用
command -v diskoala   # 或 Windows: where diskoala
```

不可用时按顺序尝试：zip 包 `bin/diskoala.exe` 复制到 PATH；仓库 `tools/yao-scai-cli` 下 `python scai.py ...`（脚本参数与 `diskoala` 完全一致）。

## 命令速查

| 命令 | 用途 |
|---|---|
| `diskoala` / `diskoala PATH` | 空间简报：主要占用 + 风险分类 + Top 50 文件 |
| `diskoala all` | 全盘安全扫描（Windows 扫系统盘但跳过 Windows/Program Files 等受管目录） |
| `diskoala top [N] PATH` | 最大文件列表（`--limit N` 控制条数） |
| `diskoala dirs PATH --max-depth 1` | 最大目录（聚合大小） |
| `diskoala explain PATH` | 解释单个文件/目录：大小、风险、分类、原因、建议 |
| `diskoala plan 20g PATH` | 生成释放 20 GB 的方案（safe 优先、父子去重、只列不删） |
| `diskoala ai PATH` | 调用本机 Codex CLI 做 AI 诊断（缺失/超时自动回退本地摘要） |
| `diskoala gui PATH` | 打开图形界面给**用户**操作删除（默认回收站，可选永久删除） |
| `diskoala --version` | 查看版本（by Koding Studio） |

注意：`diskoala all`（安全全盘）和 `--all`（不跳过默认排除目录）含义相反，不要混用。

## 标准工作流

1. **概览**：`diskoala`（当前目录）或 `diskoala all` / `diskoala ~/Downloads`。全盘扫描可能要几分钟，先告知用户。
2. **下钻**：`diskoala dirs <root> --max-depth 2` 找大目录，`diskoala top <root> --limit 50` 找大文件。
3. **解释**：对可疑大项运行 `diskoala explain <path>`，拿到风险等级和原因。
4. **方案**：`diskoala plan <目标> <root>`（如 `diskoala plan 20g ~/Projects`）。
5. **汇报**：按风险分组向用户呈现方案——先列 safe（可重建缓存/构建产物），再列 review（需用户确认：归档、媒体、数据、下载、AppData），**绝不**把 risky（系统目录、`pagefile.sys` 等）列为可清理项。
6. **执行清理**（按优先级）：
   - **首选**：建议用户运行 `diskoala gui`，自己勾选删除（默认进回收站、GUI 内可选永久删除但需两步确认、审计日志 `~/.diskoala/cleanup-log.jsonl`）。
   - 用户明确要求 agent 代为清理时：逐项列出并取得用户**明确确认**后，只允许移入回收站（Windows 可用 PowerShell `Shell.Application` 的 `MoveHere(..., 16+1024)`），**拒绝任何永久删除**（`rm`、`Remove-Item` 无回收站参数、`del /f` 等）。
7. **复查**：清理后重新 `diskoala dirs` 对比空间变化；需要审计时读取 `~/.diskoala/cleanup-log.jsonl`（旧数据可能在 `~/.scai/cleanup-log.jsonl`，新版会自动迁移）。

## 风险等级（输出中的三级）

- `safe` 可安全关注：`node_modules`、`.next`、`dist`、`target`、各类缓存目录——可重建，确认项目不在运行后可清理。
- `review` 需要确认：压缩包/安装包、大媒体、数据/数据库文件、下载目录残留、AppData、`Windows.old`——必须用户确认。
- `risky` 高风险：`C:\Windows`、Program Files、`pagefile.sys`/`hiberfil.sys` 等系统受管路径——只建议通过系统设置/磁盘清理处理，永不建议删除。

## agent 注意事项

- agent 运行环境通常非交互终端：TUI（`diskoala tui`）不可用，只用 CLI 子命令；GUI（`diskoala gui`）是给用户的，agent 不要自己启动。
- 输出为纯文本表格，可直接解析；大扫描用 `--limit` 控制输出规模。
- 扫描是只读操作，可放心重复运行；删除类操作永远先确认再执行，并优先交给 GUI。
- AI 诊断（`diskoala ai`）依赖本机已登录的 `codex` CLI，超时默认 180 秒（`--timeout` 可调）；GUI 的「AI 提示词」只生成可复制提示词，不调用 AI。
