# Diskoala Native（Rust + Tauri 升级版）

Diskoala 的原生升级版：同一品牌与行为语义，Rust 核心 + Tauri 图形界面。Python 版（`../yao-scai-cli`）保留为维护线，两版共用 `~/.diskoala` 数据目录与审计日志。

## 结构

```
Cargo.toml                      # workspace（release 优化：lto/strip）
crates/diskoala-core/           # 引擎：扫描/分类/方案/回收站/状态/AI（纯 Rust，零重量级依赖）
crates/diskoala-cli/            # diskoala CLI（brief/top/more/dirs/explain/plan/ai + --version）
apps/diskoala-gui/
  src-tauri/                    # Tauri 2 命令桥（与 Python 版 scai_gui_web.Api 一一对应）
  web-app/                      # web 前端（自 Python 版 web/ 平移 + bridge.js pywebview 兼容层）
```

## 与 Python 版的关系

- **输出对齐**：`top` / `dirs` / `explain` 与 Python 版逐字节一致（除行尾 `\n` vs `\r\n`），agent skill 的解析逻辑无需改动；
- **命令兼容**：`diskoala <子命令>`、`all` 全盘别名、数字 limit、短别名（b/f/d/x/p/m/g）均支持；
- **GUI 前端零改动复用**：`web-app/bridge.js` 把 `window.pywebview.api` 适配到 Tauri `invoke`；
- **数据互认**：`~/.diskoala/`（gui-state.json / cleanup-log.jsonl），首次运行自动从旧 `~/.scai` 迁移；
- **体积**：CLI 614 KB（Python 版 8.5 MB）；GUI 基于 WebView2。

## 构建

```bash
cargo build --release                          # 全部（CLI + GUI）
cargo build --release -p diskoala-cli          # 仅 CLI
cargo build --release -p diskoala-gui          # 仅 GUI（首次编译较久）
cargo test --release                           # 单元测试
```

网络不佳时使用镜像（仅命令级）：

```bash
cargo build --release --config "source.crates-io.replace-with='rsproxy'" \
  --config "source.rsproxy.registry='sparse+https://rsproxy.cn/index/'"
```

产物：`target/release/diskoala.exe`、`target/release/diskoala-gui.exe`。

## GUI 冒烟测试

```bash
SCAI_GUI_SMOKE_MS=60000 SCAI_GUI_SMOKE_DIR="C:\some\dir" ./target/release/diskoala-gui.exe
# 结束时输出 SMOKE-REPORT {json}，退出码 0=全部通过
```

## 待办（后续迭代）

- [ ] `build_native.py`：打包 GUI（图标/版本资源/便携 zip）并入 skill 分发包
- [ ] CLI 对齐缺口：交互式 spinner（当前非交互输出一致即可）
- [ ] magika 深度识别（Rust 原生集成，识别无扩展名大文件）
- [ ] MFT 直读扫描（Windows 管理员模式下的极速全盘）
- [ ] CI：tag 触发构建并挂 Release（与 Python 版共用 release-diskoala.yml 或独立）
