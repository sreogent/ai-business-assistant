@echo off
chcp 65001 >nul
title AI Business Assistant — Запуск

set "APP_DIR=%~dp0"
set "DESKTOP_DIR=%~dp0desktop"
set "ELECTRON=%~dp0desktop\node_modules\electron\dist\electron.exe"

echo.
echo  ====================================
echo   AI Business Assistant — Запуск
echo  ====================================
echo.

if not exist "%ELECTRON%" (
    echo [ОШИБКА] Electron не найден: %ELECTRON%
    echo Убедитесь что npm install выполнен в папке desktop\
    pause
    exit /b 1
)

echo [OK] Electron найден: %ELECTRON%
echo [>>] Запуск приложения...
echo.

cd /d "%DESKTOP_DIR%"
start "" "%ELECTRON%" "%DESKTOP_DIR%"
exit /b 0
