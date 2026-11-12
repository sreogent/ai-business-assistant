$AppDir = 'C:\Users\matve\.gemini\antigravity\scratch\ai-business-assistant'
$ElectronExe = $AppDir + '\desktop\node_modules\electron\dist\electron.exe'
$DesktopDir = $AppDir + '\desktop'
$AppIco = $AppDir + '\app.ico'
$ws = New-Object -ComObject WScript.Shell
$lnkPath = [Environment]::GetFolderPath('Desktop') + '\AI Business Assistant.lnk'
$lnk = $ws.CreateShortcut($lnkPath)
$lnk.TargetPath = $ElectronExe
$lnk.Arguments = '"' + $DesktopDir + '"'
$lnk.WorkingDirectory = $AppDir
$lnk.Description = 'AI Business Assistant'
if (Test-Path $AppIco) { $lnk.IconLocation = $AppIco + ',0' }
$lnk.Save()
Write-Host 'OK: Desktop shortcut created'
