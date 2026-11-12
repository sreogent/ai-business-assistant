@echo off
chcp 65001 >nul 2>&1

set "APP_DIR=%~dp0"
set "APP_DIR=%APP_DIR:~0,-1%"

set "ELECTRON=%APP_DIR%\desktop\node_modules\electron\dist\electron.exe"
set "DESKTOP_DIR=%APP_DIR%\desktop"

if exist "%ELECTRON%" (
    cd /d "%DESKTOP_DIR%"
    start "" "%ELECTRON%" "%DESKTOP_DIR%"
    exit /b 0
) else (
    echo Electron не найден. Открываем в браузере...
    start "" "%APP_DIR%\run_app.bat"
)
