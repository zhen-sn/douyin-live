@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0"

set "PHP="
for %%P in (php "D:\BtSoft\php\82\php.exe" "C:\php\php.exe") do (
    %%~P -r "exit(extension_loaded('pdo_sqlite') && extension_loaded('curl') ? 0 : 1);" >nul 2>nul
    if !errorlevel! == 0 (
        set "PHP=%%~P"
        goto :found
    )
)

echo 未找到同时启用 pdo_sqlite 与 curl 的 PHP。
echo 请在 php.ini 中取消注释 extension=pdo_sqlite / extension=curl 后重试。
pause
exit /b 1

:found
echo 使用 PHP: %PHP%
echo 开播雷达面板: http://127.0.0.1:8080/
start "" http://127.0.0.1:8080/
"%PHP%" -S 127.0.0.1:8080 -t "%~dp0."
