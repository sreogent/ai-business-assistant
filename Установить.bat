@echo off
chcp 65001 >nul
title Установка AI Business Assistant
color 0A

set "APP_DIR=%~dp0"
if "%APP_DIR:~-1%"=="\" set "APP_DIR=%APP_DIR:~0,-1%"

set "ELECTRON=%APP_DIR%\desktop\node_modules\electron\dist\electron.exe"
set "DESKTOP_DIR=%APP_DIR%\desktop"
set "ICO=%APP_DIR%\app.ico"

cls
echo ========================================================
echo        УСТАНОВКА AI BUSINESS ASSISTANT
echo ========================================================
echo.
echo Папка приложения: "%APP_DIR%"
echo.

:: 1. Проверяем Electron
if not exist "%ELECTRON%" (
    echo [ОШИБКА] Не найден Electron: "%ELECTRON%"
    echo Убедитесь, что файлы проекта распакованы полностью.
    echo.
    pause
    exit /b 1
)

:: 2. Создаем системные папки
if not exist "%APP_DIR%\uploads" mkdir "%APP_DIR%\uploads"
if not exist "%APP_DIR%\uploads\voice_messages" mkdir "%APP_DIR%\uploads\voice_messages"
if not exist "%APP_DIR%\uploads\chat_files" mkdir "%APP_DIR%\uploads\chat_files"
if not exist "%APP_DIR%\scratch" mkdir "%APP_DIR%\scratch"

:: 3. Файл конфигурации .env
if not exist "%APP_DIR%\.env" (
    if exist "%APP_DIR%\.env.example" (
        copy /y "%APP_DIR%\.env.example" "%APP_DIR%\.env" >nul
    )
)

echo [1/2] Создание ярлыка приложения...

:: Создаем скрипт VBS для добавления ярлыка (Windows не ругается антивирусом)
set "VBS_SCRIPT=%TEMP%\create_shortcut_%RANDOM%.vbs"
(
echo Set ws = WScript.CreateObject("WScript.Shell"^)
echo sDesk = ws.SpecialFolders("Desktop"^)
echo Set oLnk = ws.CreateShortcut(sDesk ^& "\AI Business Assistant.lnk"^)
echo oLnk.TargetPath = "%ELECTRON%"
echo oLnk.Arguments = """%DESKTOP_DIR%"""
echo oLnk.WorkingDirectory = "%APP_DIR%"
echo oLnk.Description = "AI Business Assistant"
echo If ws.CreateObject("Scripting.FileSystemObject"^).FileExists("%ICO%"^) Then oLnk.IconLocation = "%ICO%"
echo oLnk.Save
echo sPrograms = ws.SpecialFolders("Programs"^)
echo Set fso = CreateObject("Scripting.FileSystemObject"^)
echo If Not fso.FolderExists(sPrograms ^& "\AI Business Assistant"^) Then fso.CreateFolder(sPrograms ^& "\AI Business Assistant"^)
echo Set oLnk2 = ws.CreateShortcut(sPrograms ^& "\AI Business Assistant\AI Business Assistant.lnk"^)
echo oLnk2.TargetPath = "%ELECTRON%"
echo oLnk2.Arguments = """%DESKTOP_DIR%"""
echo oLnk2.WorkingDirectory = "%APP_DIR%"
echo oLnk2.Description = "AI Business Assistant"
echo If fso.FileExists("%ICO%"^) Then oLnk2.IconLocation = "%ICO%"
echo oLnk2.Save
) > "%VBS_SCRIPT%"

wscript.exe "%VBS_SCRIPT%"
del /f /q "%VBS_SCRIPT%" 2>nul

echo [2/2] Ярлык успешно создан на Рабочем столе и в меню Пуск!
echo.
echo ========================================================
echo        УСТАНОВКА УСПЕШНО ЗАВЕРШЕНА!
echo ========================================================
echo.
echo Нажмите любую клавишу, чтобы сразу запустить приложение...
pause >nul

start "" "%ELECTRON%" "%DESKTOP_DIR%"
exit /b 0
