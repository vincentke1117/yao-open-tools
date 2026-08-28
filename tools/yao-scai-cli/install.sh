#!/bin/zsh
set -euo pipefail

script_dir="${0:A:h}"
bin_dir="${HOME}/bin"

if command -v python3 >/dev/null 2>&1; then
  python_cmd="python3"
elif command -v python >/dev/null 2>&1; then
  python_cmd="python"
else
  echo "未找到 python3/python，请先安装 Python 3。" >&2
  exit 1
fi

mkdir -p "$bin_dir"

# 主命令 diskoala；scai/bf/scan 为兼容别名（曾用名 Scai）
for prog in diskoala scai bf scan; do
  cat > "${bin_dir}/${prog}" <<EOF
#!/bin/zsh
DISKOALA_PROG=${prog} exec ${python_cmd} "${script_dir}/scai.py" "\$@"
EOF
done

chmod +x "${bin_dir}/diskoala" "${bin_dir}/scai" "${bin_dir}/bf" "${bin_dir}/scan"

echo "已安装 diskoala: ${bin_dir}/diskoala"
echo "兼容别名: scai / bf / scan"
echo "Python: ${python_cmd}"
echo "运行 diskoala --help 查看用法。"
echo "Windows 请改用: powershell -ExecutionPolicy Bypass -File .\\install.ps1"
