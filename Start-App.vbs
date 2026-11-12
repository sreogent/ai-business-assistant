Set FSO = CreateObject("Scripting.FileSystemObject")
ScriptDir = FSO.GetParentFolderName(WScript.ScriptFullName)

ElectronExe = ScriptDir & "\desktop\node_modules\electron\dist\electron.exe"
DesktopDir  = ScriptDir & "\desktop"

Set WshShell = CreateObject("WScript.Shell")

If FSO.FileExists(ElectronExe) Then
    WshShell.CurrentDirectory = DesktopDir
    WshShell.Run """" & ElectronExe & """ """ & DesktopDir & """", 1, False
Else
    WshShell.CurrentDirectory = ScriptDir
    WshShell.Run "cmd /c """ & ScriptDir & "\run_app.bat""", 0, False
End If
