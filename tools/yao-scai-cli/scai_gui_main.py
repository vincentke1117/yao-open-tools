#!/usr/bin/env python3
"""scai-gui 独立启动入口：无控制台直接打开 Scai 图形界面。

用法:
    scai-gui [PATH] [--all]

优先启动 Web 界面（pywebview/WebView2）；运行时不可用时回退旧 tkinter 界面。
"""
from __future__ import annotations

import sys
from pathlib import Path


def main() -> int:
    args = sys.argv[1:]
    include_all = "--all" in args
    positional = [arg for arg in args if not arg.startswith("-")]

    from pathlib import Path as _P

    explicit = None
    if positional:
        candidate = _P(positional[0]).expanduser()
        if candidate.exists():
            explicit = candidate.resolve()

    try:
        import scai_gui_web

        if explicit is not None:
            code = scai_gui_web.launch(root_path=explicit, include_all=include_all)
        else:
            code = scai_gui_web.launch()
    except Exception:
        code = 3

    if code == 3:
        # 回退旧 tkinter 界面
        import scai_gui

        if explicit is not None:
            return scai_gui.launch(root_path=explicit, include_all=include_all, auto_scan=True)
        last = scai_gui.load_last_root()
        return scai_gui.launch(root_path=last or Path.home(), include_all=include_all, auto_scan=False)
    return code


if __name__ == "__main__":
    sys.exit(main())
