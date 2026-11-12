@echo off
chcp 65001 >nul
echo Остановка фоновых процессов AI Business Assistant...
taskkill /F /IM php.exe /T 2>nul
echo Готово! Локальный веб-сервер остановлен.
pause
