$appDir = "C:\Users\matve\.gemini\antigravity\scratch\ai-business-assistant"
$php = "C:\xampp\php\php.exe"
$mysql = "C:\xampp\mysql\bin\mysqld.exe"
$myIni = "C:\xampp\mysql\bin\my.ini"

# 1. Запуск MySQL
$mysqlProc = Get-Process -Name "mysqld" -ErrorAction SilentlyContinue
if (-not $mysqlProc -and (Test-Path $mysql)) {
    Start-Process -FilePath $mysql -ArgumentList "--defaults-file=$myIni", "--standalone" -WindowStyle Hidden
    Start-Sleep -Milliseconds 800
}

# 2. Запуск PHP Built-in Server на 127.0.0.1:8000
$phpProc = Get-Process -Name "php" -ErrorAction SilentlyContinue
if (-not $phpProc -and (Test-Path $php)) {
    Start-Process -FilePath $php -ArgumentList "-d", "extension=zip", "-S", "127.0.0.1:8000", "-t", $appDir -WorkingDirectory $appDir -WindowStyle Hidden
    Start-Sleep -Milliseconds 800
}

# 3. Открытие в браузере по умолчанию
Start-Process "http://127.0.0.1:8000"
Write-Output "SERVER_RUNNING_ON_PORT_8000"
