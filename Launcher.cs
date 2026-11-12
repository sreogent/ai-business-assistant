using System;
using System.Diagnostics;
using System.IO;

namespace AIAssistantLauncher
{
    class Program
    {
        static void Main(string[] args)
        {
            try
            {
                string baseDir = AppDomain.CurrentDomain.BaseDirectory.TrimEnd('\\', '/');
                if (Path.GetFileName(baseDir).Equals("desktop", StringComparison.OrdinalIgnoreCase))
                {
                    DirectoryInfo parent = Directory.GetParent(baseDir);
                    if (parent != null) baseDir = parent.FullName;
                }
                string appDir = Path.Combine(baseDir, "desktop");
                string electronExe = Path.Combine(appDir, @"node_modules\electron\dist\electron.exe");
                string electronCli = Path.Combine(appDir, @"node_modules\electron\cli.js");
                string nodeExe = @"C:\Program Files\nodejs\node.exe";

                if (File.Exists(electronExe))
                {
                    ProcessStartInfo psi = new ProcessStartInfo();
                    psi.WorkingDirectory = appDir;
                    psi.UseShellExecute = false;
                    psi.CreateNoWindow = true;
                    psi.FileName = electronExe;
                    psi.Arguments = "\"" + appDir + "\"";
                    Process.Start(psi);
                    return;
                }
                else if (File.Exists(nodeExe) && File.Exists(electronCli))
                {
                    ProcessStartInfo psi = new ProcessStartInfo();
                    psi.WorkingDirectory = appDir;
                    psi.UseShellExecute = false;
                    psi.CreateNoWindow = true;
                    psi.FileName = nodeExe;
                    psi.Arguments = "\"" + electronCli + "\" \"" + appDir + "\"";
                    Process.Start(psi);
                    return;
                }

                // Фоллбэк: запуск через встроенный run_app.bat (MySQL + PHP + Edge Web App)
                string runBat = Path.Combine(baseDir, "run_app.bat");
                if (File.Exists(runBat))
                {
                    ProcessStartInfo psi = new ProcessStartInfo();
                    psi.WorkingDirectory = baseDir;
                    psi.FileName = "cmd.exe";
                    psi.Arguments = "/c \"" + runBat + "\"";
                    psi.WindowStyle = ProcessWindowStyle.Hidden;
                    psi.CreateNoWindow = true;
                    psi.UseShellExecute = false;
                    Process.Start(psi);
                    return;
                }

                System.Windows.Forms.MessageBox.Show("Файл запуска run_app.bat не найден в каталоге: " + baseDir, "AI Business Assistant", System.Windows.Forms.MessageBoxButtons.OK, System.Windows.Forms.MessageBoxIcon.Error);
            }
            catch (Exception ex)
            {
                System.Windows.Forms.MessageBox.Show("Ошибка запуска приложения: " + ex.Message, "AI Business Assistant", System.Windows.Forms.MessageBoxButtons.OK, System.Windows.Forms.MessageBoxIcon.Error);
            }
        }
    }
}
