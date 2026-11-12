@echo off
chcp 65001 >nul
echo Удаление AI Business Assistant...
if exist "C:\Users\matve\Desktop\AI Business Assistant.lnk" del /f /q "C:\Users\matve\Desktop\AI Business Assistant.lnk"
if exist "C:\Users\matve\AppData\Roaming\Microsoft\Windows\Start Menu\Programs\AI Business Assistant" rmdir /s /q "C:\Users\matve\AppData\Roaming\Microsoft\Windows\Start Menu\Programs\AI Business Assistant"
echo Готово.
timeout /t 3 >nul
