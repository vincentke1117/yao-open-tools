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

cat > "${bin_dir}/scai" <<EOF
#!/bin/zsh
SCAI_PROG=scai exec ${python_cmd} "${script_dir}/scai.py" "\$@"
EOF

cat > "${bin_dir}/bf" <<EOF
#!/bin/zsh
SCAI_PROG=bf exec ${python_cmd} "${script_dir}/scai.py" "\$@"
EOF

cat > "${bin_dir}/scan" <<EOF
#!/bin/zsh
SCAI_PROG=scan exec ${python_cmd} "${script_dir}/scai.py" "\$@"
EOF

chmod +x "${bin_dir}/scai" "${bin_dir}/bf" "${bin_dir}/scan"

echo "已安装 scai: ${bin_dir}/scai"
echo "旧别名 bf: ${bin_dir}/bf"
echo "表格兼容入口 scan: ${bin_dir}/scan"
echo "Python: ${python_cmd}"
echo "运行 scai --help 查看用法。"
echo "Windows 请改用: powershell -ExecutionPolicy Bypass -File .\\install.ps1"
