#!/usr/bin/env bash
# 启动本地开发服务器，自动跳过被占用的端口。
#
#   bash tools/serve.sh          从 7317 开始找一个空闲端口
#   bash tools/serve.sh 9001     指定从 9001 开始找
set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

START="${1:-7317}"
HOST=127.0.0.1

port_free() {
  # 端口没人监听时返回 0
  if command -v lsof >/dev/null 2>&1; then
    ! lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1
  else
    ! (exec 3<>"/dev/tcp/$HOST/$1") >/dev/null 2>&1
  fi
}

PORT=""
for p in $(seq "$START" $((START + 40))); do
  if port_free "$p"; then PORT="$p"; break; fi
done

if [ -z "$PORT" ]; then
  echo "从 $START 起连续 40 个端口都被占用了，换个起始端口试试：bash tools/serve.sh 9001"
  exit 1
fi

[ "$PORT" != "$START" ] && echo "（$START 被占用，改用 $PORT）"

if [ ! -f config.php ]; then
  cp config.php.example config.php
  echo "已生成 config.php；后台密码尚未设置，请先按 README 生成 admin_password_hash"
fi

echo
echo "  前台  http://$HOST:$PORT/"
echo "  后台  http://$HOST:$PORT/admin.php   密码使用 config.php 中的 admin_password_hash"
echo
echo "  停止服务按 Ctrl+C"
echo

exec php -S "$HOST:$PORT" -t public
