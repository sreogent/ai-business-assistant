@echo off
chcp 65001 >nul
title Установка AI Business Assistant
color 0B

set "APP_DIR=%~dp0"
set "APP_DIR=%APP_DIR:~0,-1%"

echo ======================================================================
echo          МАСТЕР УСТАНОВКИ — AI BUSINESS ASSISTANT v2.0
echo ======================================================================
echo.
echo Каталог установки: "%APP_DIR%"
echo.

echo [1/5] Проверка и создание системных каталогов...
if not exist "%APP_DIR%\uploads" mkdir "%APP_DIR%\uploads"
if not exist "%APP_DIR%\uploads\voice_messages" mkdir "%APP_DIR%\uploads\voice_messages"
if not exist "%APP_DIR%\uploads\chat_files" mkdir "%APP_DIR%\uploads\chat_files"
if not exist "%APP_DIR%\uploads\call_recordings" mkdir "%APP_DIR%\uploads\call_recordings"
if not exist "%APP_DIR%\scratch" mkdir "%APP_DIR%\scratch"
echo [OK] Каталоги подготовлены.
echo.

echo [2/5] Проверка конфигурации окружения (.env)...
if not exist "%APP_DIR%\.env" (
    if exist "%APP_DIR%\.env.example" (
        copy "%APP_DIR%\.env.example" "%APP_DIR%\.env" >nul
        echo [OK] Создан файл .env на основе шаблона.
    ) else (
        echo [INFO] Файл .env уже существует.
    )
) else (
    echo [OK] Файл .env готов к работе.
)
echo.

echo [3/5] Проверка локального окружения PHP и MySQL...
if exist "C:\xampp\php\php.exe" (
    echo [OK] PHP найден: C:\xampp\php\php.exe
) else (
    echo [!] XAMPP PHP не найден по умолчанию, будет использован системный PHP.
)
if exist "C:\xampp\mysql\bin\mysqld.exe" (
    echo [OK] MySQL найден: C:\xampp\mysql\bin\mysqld.exe
) else (
    echo [!] XAMPP MySQL не найден, будет использована системная служба MySQL.
)
echo.

echo [4/5] Создание ярлыков на Рабочем столе и в меню «Пуск»...
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$ws = New-Object -ComObject WScript.Shell; " ^
    "$desk = [Environment]::GetFolderPath('Desktop'); " ^
    "$electron = (Join-Path '%APP_DIR%' 'desktop\node_modules\electron\dist\electron.exe'); " ^
    "$desktopDir = (Join-Path '%APP_DIR%' 'desktop'); " ^
    "$ico = (Join-Path '%APP_DIR%' 'app.ico'); " ^
    "$link = $ws.CreateShortcut((Join-Path $desk 'AI Business Assistant.lnk')); " ^
    "if (Test-Path $electron) { " ^
    "    $link.TargetPath = $electron; " ^
    "    $link.Arguments = '\"' + $desktopDir + '\"'; " ^
    "    $link.WorkingDirectory = $desktopDir; " ^
    "} else { " ^
    "    $link.TargetPath = (Join-Path '%APP_DIR%' 'Start-App.vbs'); " ^
    "    $link.WorkingDirectory = '%APP_DIR%'; " ^
    "}; " ^
    "$link.Description = 'AI Business Assistant — Корпоративная платформа'; " ^
    "if (Test-Path $ico) { $link.IconLocation = $ico }; " ^
    "$link.Save(); " ^
    "$sm = Join-Path ([Environment]::GetFolderPath('Programs')) 'AI Business Assistant'; " ^
    "if (!(Test-Path $sm)) { New-Item -ItemType Directory -Path $sm | Out-Null }; " ^
    "$link2 = $ws.CreateShortcut((Join-Path $sm 'AI Business Assistant.lnk')); " ^
    "if (Test-Path $electron) { " ^
    "    $link2.TargetPath = $electron; " ^
    "    $link2.Arguments = '\"' + $desktopDir + '\"'; " ^
    "    $link2.WorkingDirectory = $desktopDir; " ^
    "} else { " ^
    "    $link2.TargetPath = (Join-Path '%APP_DIR%' 'Start-App.vbs'); " ^
    "    $link2.WorkingDirectory = '%APP_DIR%'; " ^
    "}; " ^
    "if (Test-Path $ico) { $link2.IconLocation = $ico }; " ^
    "$link2.Save();"
echo [OK] Ярлык на Рабочем столе и в меню «Пуск» успешно создан!
echo.

echo [5/5] Создание скрипта удаления (Uninstall.bat)...
(
echo @echo off
echo chcp 65001 ^>nul
echo echo Удаление ярлыков AI Business Assistant...
echo powershell -Command "$desk = [Environment]::GetFolderPath('Desktop'); Remove-Item -Path (Join-Path $desk 'AI Business Assistant.lnk') -ErrorAction SilentlyContinue; $sm = Join-Path ([Environment]::GetFolderPath('Programs')) 'AI Business Assistant'; Remove-Item -Recurse -Force -Path $sm -ErrorAction SilentlyContinue;"
echo echo Ярлыки успешно удалены.
echo timeout /t 2 ^>nul
) > "%APP_DIR%\Uninstall.bat"
echo [OK] Деинсталлятор создан.
echo.

echo ======================================================================
echo             УСТАНОВКА УСПЕШНО ЗАВЕРШЕНА!
echo ======================================================================
echo.
echo Ярлык «AI Business Assistant» появился на вашем Рабочем столе.
echo.
set /p LAUNCH="Запустить приложение прямо сейчас? (Y/N, Enter = Да): "
if /I "%LAUNCH%"=="" set LAUNCH=Y
if /I "%LAUNCH%"=="Y" (
    echo Запуск приложения...
    if exist "%APP_DIR%\desktop\node_modules\electron\dist\electron.exe" (
        cd /d "%APP_DIR%\desktop"
        start "" "%APP_DIR%\desktop\node_modules\electron\dist\electron.exe" "%APP_DIR%\desktop"
    ) else (
        start "" wscript "%APP_DIR%\Start-App.vbs"
    )
)
exit /b 0
