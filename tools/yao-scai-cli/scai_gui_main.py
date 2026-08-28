#!/usr/bin/env python3
"""scai-gui 独立启动入口：无控制台直接打开 Scai 图形界面。

用法:
    scai-gui [PATH] [--all]
"""
from __future__ import annotations

import sys

from scai_gui import main

if __name__ == "__main__":
    sys.exit(main())
