# TokKit 菜单栏应用

TokKit Menu 是一个原生 macOS 菜单栏应用。它只负责展示和触发更新，统计、扫描、预算和数据口径仍由 TokKit CLI 与 `~/.tokkit/usage.sqlite` 负责。

## 构建

```bash
bash scripts/build_menubar_app.sh
```

构建产物：

```text
macos/TokKitMenu/dist/TokKitMenu.app
```

构建脚本会把当前 TokKit 仓库路径写入 app bundle 的 `Resources/tokkit-root.txt`。因此本地开发时不要求先把 `tok` 安装到全局 PATH，应用会优先通过当前源码运行 `python3 -m tokkit.tok`。

## 运行

```bash
open macos/TokKitMenu/dist/TokKitMenu.app
```

打开后右上角菜单栏会出现 TokKit 状态项。展开后可查看：

- 今日用量与今日预算
- 近 7 天 token 趋势
- 近 7 天终端和模型排行
- 近 7 日明细
- 一键更新
- 打开 HTML 报告

## 数据刷新

常规展开只读取：

```bash
tok snapshot --json --last 7
```

点击“更新”会执行：

```bash
tok scan all
```

扫描完成后应用会重新读取 snapshot。已有 `launchd` 自动扫描仍然保留，菜单栏应用不替代后台任务。
