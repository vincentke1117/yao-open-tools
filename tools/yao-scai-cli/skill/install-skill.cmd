@echo off
setlocal
set "SRC=%~dp0"
set "SKILLDIR=%USERPROFILE%\.agents\skills\scai"
mkdir "%SKILLDIR%" 2>nul
copy /y "%SRC%SKILL.md" "%SKILLDIR%\SKILL.md" >nul
echo Skill installed: %SKILLDIR%
if exist "%SRC%bin\scai.exe" (
  if not exist "%USERPROFILE%\bin" mkdir "%USERPROFILE%\bin"
  copy /y "%SRC%bin\scai.exe" "%USERPROFILE%\bin\scai.exe" >nul
  copy /y "%SRC%bin\scai-gui.exe" "%USERPROFILE%\bin\scai-gui.exe" >nul
  echo Exes copied to %%USERPROFILE%%\bin: scai.exe / scai-gui.exe
) else (
  echo bin\scai.exe not found, skipped exe copy. Build first with: python build_exe.py
)
echo.
echo Done. Open a new terminal and a new agent session, then verify with: scai --help
