@echo off
setlocal
set "SRC=%~dp0"
set "SKILLDIR=%USERPROFILE%\.agents\skills\diskoala"
mkdir "%SKILLDIR%" 2>nul
copy /y "%SRC%SKILL.md" "%SKILLDIR%\SKILL.md" >nul
echo Skill installed: %SKILLDIR%
if exist "%SRC%bin\diskoala.exe" (
  if not exist "%USERPROFILE%\bin" mkdir "%USERPROFILE%\bin"
  copy /y "%SRC%bin\diskoala.exe" "%USERPROFILE%\bin\diskoala.exe" >nul
  copy /y "%SRC%bin\diskoala-gui.exe" "%USERPROFILE%\bin\diskoala-gui.exe" >nul
  echo Exes copied to %%USERPROFILE%%\bin: diskoala.exe / diskoala-gui.exe
) else (
  echo bin\diskoala.exe not found, skipped exe copy. Build first with: python build_exe.py
)
echo.
echo Done. Open a new terminal and a new agent session, then verify with: diskoala --help
