@echo off
chcp 65001 >nul
title AI Business Assistant

set "APP_DIR=%~dp0"
set "APP_DIR=%APP_DIR:~0,-1%"

if not defined PHP_EXE (
    if exist "C:\xampp\php\php.exe" (
        set "PHP_EXE=C:\xampp\php\php.exe"
    ) else (
        set "PHP_EXE=php"
    )
)

if not defined MYSQLD_EXE (
    if exist "C:\xampp\mysql\bin\mysqld.exe" (
        set "MYSQLD_EXE=C:\xampp\mysql\bin\mysqld.exe"
        set "MYSQL_INI=C:\xampp\mysql\bin\my.ini"
    )
)
set "ELECTRON_EXE=%APP_DIR%\desktop\node_modules\electron\dist\electron.exe"
set "DESKTOP_DIR=%APP_DIR%\desktop"

echo [1/3] Проверка службы базы данных MySQL...
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if "%ERRORLEVEL%"=="0" (
    echo [OK] MySQL уже работает.
) else (
    echo Запуск встроенного MySQL сервера...
    start "" /B "%MYSQLD_EXE%" --defaults-file="%MYSQL_INI%" --standalone
    timeout /t 2 /nobreak >nul
)

echo [2/3] Запуск локального сервера приложения...
netstat -ano | findstr /R /C:":8000 " >nul
if "%ERRORLEVEL%"=="0" (
    echo [OK] Сервер уже запущен на порту 8000.
) else (
    start "" /B "%PHP_EXE%" -d extension=zip -S 127.0.0.1:8000 -t "%APP_DIR%"
    timeout /t 2 /nobreak >nul
)

echo [3/3] Запуск приложения AI Business Assistant...
if exist "%ELECTRON_EXE%" (
    cd /d "%DESKTOP_DIR%"
    start "" "%ELECTRON_EXE%" "%DESKTOP_DIR%"
    exit
)

set "EDGE_EXE=C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
if not exist "%EDGE_EXE%" (
    set "EDGE_EXE=C:\Program Files\Microsoft\Edge\Application\msedge.exe"
)

if exist "%EDGE_EXE%" (
    start "" "%EDGE_EXE%" --app="http://127.0.0.1:8000" --window-size=1366,820
) else (
    start http://127.0.0.1:8000
)

exit
