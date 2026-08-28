# Scai Windows installer
# Usage: powershell -ExecutionPolicy Bypass -File .\install.ps1

$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$BinDir = Join-Path $env:USERPROFILE "bin"
$ScaiPy = Join-Path $ScriptDir "scai.py"

if (-not (Test-Path $ScaiPy)) {
    Write-Error "找不到 scai.py: $ScaiPy"
}

New-Item -ItemType Directory -Force -Path $BinDir | Out-Null

function Resolve-PythonCommand {
    $pyLauncher = Get-Command py -ErrorAction SilentlyContinue
    if ($pyLauncher) {
        return "py -3"
    }
    $python = Get-Command python -ErrorAction SilentlyContinue
    if ($python) {
        return "python"
    }
    Write-Error "未找到 python。请先安装 Python 3 并加入 PATH。"
}

$PythonCmd = Resolve-PythonCommand

function Write-CmdWrapper {
    param(
        [string]$Name,
        [string]$Prog
    )
    $target = Join-Path $BinDir "$Name.cmd"
    $content = @"
@echo off
setlocal
set DISKOALA_PROG=$Prog
$PythonCmd "$ScaiPy" %*
"@
    Set-Content -Path $target -Value $content -Encoding ASCII
    Write-Host "已安装: $target"
}

Write-CmdWrapper -Name "diskoala" -Prog "diskoala"
Write-CmdWrapper -Name "scai" -Prog "scai"
Write-CmdWrapper -Name "bf" -Prog "bf"
Write-CmdWrapper -Name "scan" -Prog "scan"

# Ensure %USERPROFILE%\bin is on user PATH
$userPath = [Environment]::GetEnvironmentVariable("Path", "User")
if (-not $userPath) {
    $userPath = ""
}
$pathParts = $userPath -split ";" | Where-Object { $_ -and $_.Trim() -ne "" }
$already = $pathParts | Where-Object { $_.TrimEnd("\") -ieq $BinDir.TrimEnd("\") }
if (-not $already) {
    $newPath = if ($userPath.Trim()) { "$userPath;$BinDir" } else { $BinDir }
    [Environment]::SetEnvironmentVariable("Path", $newPath, "User")
    $env:Path = "$env:Path;$BinDir"
    Write-Host "已将 $BinDir 加入用户 PATH（新开终端生效）"
} else {
    Write-Host "$BinDir 已在用户 PATH 中"
}

# Optional TUI support check
cmd /c "$PythonCmd -c \"import curses\"" >$null 2>&1
if ($LASTEXITCODE -eq 0) {
    Write-Host "curses 可用，TUI (scai tui) 已就绪"
} else {
    Write-Host "可选: 安装 TUI 支持 -> $PythonCmd -m pip install windows-curses"
}

Write-Host ""
Write-Host "安装完成。新开一个终端后运行:"
Write-Host "  diskoala --help"
Write-Host "  diskoala `$env:USERPROFILE"
Write-Host "  diskoala all"
Write-Host "  diskoala plan 20g `$env:USERPROFILE"
Write-Host ""
Write-Host "兼容别名 scai / bf / scan 仍可用。当前会话也可直接:"
Write-Host "  $PythonCmd `"$ScaiPy`" --help"
