#!/bin/zsh
set -euo pipefail

script_dir="${0:A:h}"
skill_dir="${HOME}/.agents/skills/scai"

mkdir -p "$skill_dir"
cp "${script_dir}/SKILL.md" "${skill_dir}/SKILL.md"
echo "skill 已安装: ${skill_dir}"

if [ -d "${script_dir}/bin" ] && [ -n "$(ls "${script_dir}/bin" 2>/dev/null)" ]; then
  mkdir -p "${HOME}/bin"
  cp "${script_dir}/bin/"* "${HOME}/bin/"
  echo "已复制可执行文件到 ${HOME}/bin"
fi

echo ""
echo "完成。新开一个终端和 agent 会话后生效，验证: scai --help"
echo "注意: 包内 exe 仅适用于 Windows; macOS/Linux 请用仓库 tools/yao-scai-cli/install.sh。"
