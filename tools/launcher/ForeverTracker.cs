// ForeverTracker launcher (Windows): finds PHP and Python, starts the website with PHP's built-in server, opens the
// setup wizard on the first run, and updates the data. Settings in data\launcher.json.
// Built into ForeverTracker.exe by tools\launcher\build-exe.ps1 with the C# compiler of the .NET Framework 4 that
// Windows ships (C# 5, no SDK needed). By FilinoDZ, MIT, part of https://github.com/filinodz/ForeverTracker
//
//   ForeverTracker.exe                     the window
//   ForeverTracker.exe --check             prints what it finds (PHP, Python, configuration, port)
//   ForeverTracker.exe --screenshot a.png  saves the window as a picture, with example paths (documentation)
//   --lang en|fr                           interface language (default: Windows' language)
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Imaging;
using System.Globalization;
using System.IO;
using System.Net.Sockets;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Web.Script.Serialization;
using System.Windows.Forms;

[assembly: AssemblyTitle("ForeverTracker")]
[assembly: AssemblyProduct("ForeverTracker")]
[assembly: AssemblyDescription("ForeverTracker launcher: official WoW Forever content vs. your server")]
[assembly: AssemblyCompany("FilinoDZ")]
[assembly: AssemblyCopyright("FilinoDZ, MIT License")]
[assembly: AssemblyVersion("1.1.1.0")]
[assembly: AssemblyFileVersion("1.1.1.0")]

namespace ForeverTrackerLauncher
{
    class PhpInfo { public string Path; public string Ini; public string Version; public bool Gmp; }
    class PythonInfo { public string Command; public string Version; }

    static class Env
    {
        public static string Root, Data, SettingsPath, ServerLog, Version = "?";
        public static bool French;
        public static Dictionary<string, object> Settings = new Dictionary<string, object>();

        public static string T(string en, string fr) { return French ? fr : en; }

        public static void Init(string lang)
        {
            string exeDir = Path.GetDirectoryName(Application.ExecutablePath);
            Root = exeDir;
            // the exe sits at the root of ForeverTracker; tolerate being started from tools\launcher
            for (int i = 0; i < 3 && !File.Exists(Path.Combine(Root, "app", "bootstrap.php")); i++)
            {
                string parent = Path.GetDirectoryName(Root);
                if (parent == null) break;
                Root = parent;
            }
            if (!File.Exists(Path.Combine(Root, "app", "bootstrap.php"))) Root = exeDir;
            Data = Path.Combine(Root, "data");
            Directory.CreateDirectory(Data);
            SettingsPath = Path.Combine(Data, "launcher.json");
            ServerLog = Path.Combine(Data, "launcher-server.log");
            try
            {
                Match m = Regex.Match(File.ReadAllText(Path.Combine(Root, "app", "bootstrap.php")), "FT_VERSION = '([^']+)'");
                if (m.Success) Version = m.Groups[1].Value;
            }
            catch (Exception) { }
            French = lang != "" ? lang == "fr" : CultureInfo.CurrentUICulture.TwoLetterISOLanguageName == "fr";
            Settings["php"] = ""; Settings["php_ini"] = ""; Settings["port"] = 8090; Settings["keep"] = false;
            try
            {
                if (File.Exists(SettingsPath))
                {
                    var saved = new JavaScriptSerializer().Deserialize<Dictionary<string, object>>(File.ReadAllText(SettingsPath));
                    foreach (var pair in saved) Settings[pair.Key] = pair.Value;
                }
            }
            catch (Exception) { }
        }

        public static string Str(string key) { object v; return Settings.TryGetValue(key, out v) && v != null ? v.ToString() : ""; }
        public static int Port() { int p; return int.TryParse(Str("port"), out p) && p > 1023 && p < 65536 ? p : 8090; }
        public static bool Keep() { return Str("keep").ToLowerInvariant() == "true"; }
        public static void Save()
        {
            try { File.WriteAllText(SettingsPath, new JavaScriptSerializer().Serialize(Settings), new UTF8Encoding(false)); } catch (Exception) { }
        }
        public static bool Configured() { return File.Exists(Path.Combine(Root, "config.ini")); }
    }

    static class Detect
    {
        public static string Run(string exe, string arguments)
        {
            try
            {
                var info = new ProcessStartInfo(exe, arguments);
                info.UseShellExecute = false; info.CreateNoWindow = true;
                info.RedirectStandardOutput = true; info.RedirectStandardError = true;
                using (var p = Process.Start(info))
                {
                    var err = p.StandardError.ReadToEndAsync();
                    string output = p.StandardOutput.ReadToEnd();
                    if (!p.WaitForExit(15000)) { try { p.Kill(); } catch (Exception) { } }
                    return output + "\n" + err.Result;
                }
            }
            catch (Exception) { return ""; }
        }

        // PHP version and extensions, with an optional ini
        static string[] TestPhp(string php, string ini)
        {
            string args = (ini != "" ? "-c \"" + ini + "\" " : "") +
                "-r \"echo PHP_VERSION, chr(124), (int)extension_loaded(chr(109).chr(121).chr(115).chr(113).chr(108).chr(105)), chr(124), (int)extension_loaded(chr(103).chr(109).chr(112)), chr(10);\"";
            Match m = Regex.Match(Run(php, args), @"(?m)^(\d+)\.(\d+)\.(\d+)[^|\r\n]*\|(\d)\|(\d)");
            if (!m.Success || int.Parse(m.Groups[1].Value) < 8) return null;
            return new[] { m.Groups[1].Value + "." + m.Groups[2].Value + "." + m.Groups[3].Value, m.Groups[4].Value, m.Groups[5].Value };
        }

        // a php.ini that loads the extensions a portable PHP ships without enabling them
        static string WriteIni(string php)
        {
            string ext = Path.Combine(Path.GetDirectoryName(php), "ext");
            if (!File.Exists(Path.Combine(ext, "php_mysqli.dll"))) return "";
            var lines = new List<string> { "; Written by the ForeverTracker launcher for " + php, "extension_dir = \"" + ext.Replace('\\', '/') + "\"" };
            foreach (string name in new[] { "mysqli", "gmp", "mbstring", "openssl", "curl" })
                if (File.Exists(Path.Combine(ext, "php_" + name + ".dll"))) lines.Add("extension = " + name);
            lines.AddRange(new[] { "memory_limit = 256M", "display_errors = Off", "log_errors = On" });
            string ini = Path.Combine(Env.Data, "php-launcher.ini");
            File.WriteAllText(ini, string.Join("\r\n", lines) + "\r\n", new UTF8Encoding(false));
            return ini;
        }

        public static PhpInfo FindPhp()
        {
            var candidates = new List<string>();
            if (Env.Str("php") != "") candidates.Add(Env.Str("php"));
            foreach (string dir in (Environment.GetEnvironmentVariable("PATH") ?? "").Split(';'))
            {
                try { if (dir.Trim() != "") candidates.Add(Path.Combine(dir.Trim().Trim('"'), "php.exe")); } catch (Exception) { }
            }
            string pf = Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles);
            candidates.AddRange(new[] { @"C:\php\php.exe", @"C:\xampp\php\php.exe", @"D:\xampp\php\php.exe", @"C:\tools\php\php.exe", Path.Combine(pf, @"PHP\php.exe"), @"C:\laragon\bin\php\php.exe" });
            foreach (string d in new[] { Path.Combine(pf, "PHP"), @"C:\laragon\bin\php", @"C:\tools" })
            {
                try { if (Directory.Exists(d)) foreach (string sub in Directory.GetDirectories(d)) candidates.Add(Path.Combine(sub, "php.exe")); } catch (Exception) { }
            }
            // servers and repacks next to ForeverTracker often bring their own PHP
            try
            {
                foreach (string sibling in Directory.GetDirectories(Path.GetDirectoryName(Env.Root)))
                    foreach (string sub in new[] { "php", @"cms\php", @"web\php", @"tools\php" })
                        candidates.Add(Path.Combine(sibling, sub, "php.exe"));
            }
            catch (Exception) { }
            var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            foreach (string php in candidates)
            {
                if (!seen.Add(php) || !File.Exists(php)) continue;
                string ini = php == Env.Str("php") && Env.Str("php_ini") != "" && File.Exists(Env.Str("php_ini")) ? Env.Str("php_ini") : "";
                string[] test = TestPhp(php, ini);
                if (test != null && test[1] != "1")
                {
                    string made = WriteIni(php);
                    if (made != "") { ini = made; test = TestPhp(php, ini); }
                }
                if (test != null && test[1] == "1") return new PhpInfo { Path = php, Ini = ini, Version = test[0], Gmp = test[2] == "1" };
            }
            return null;
        }

        public static PythonInfo FindPython()
        {
            foreach (string[] c in new[] { new[] { "python", "--version" }, new[] { "py", "-3 --version" }, new[] { "python3", "--version" } })
            {
                Match m = Regex.Match(Run(c[0], c[1]), @"Python 3\.(\d+)\.\d+");
                if (m.Success && int.Parse(m.Groups[1].Value) >= 9) return new PythonInfo { Command = c[0], Version = m.Value };
            }
            return null;
        }

        public static bool PortAnswers(int port)
        {
            using (var client = new TcpClient())
            {
                try { return client.ConnectAsync("127.0.0.1", port).Wait(400) && client.Connected; }
                catch (Exception) { return false; }
            }
        }
    }

    class Row
    {
        public Label Dot, Name, Detail;
    }

    class LauncherForm : Form
    {
        static readonly Color Bg = Color.FromArgb(14, 15, 19), Surface = Color.FromArgb(21, 22, 27), Line = Color.FromArgb(38, 40, 48);
        static readonly Color Ink = Color.FromArgb(231, 232, 235), Muted = Color.FromArgb(149, 153, 163), Accent = Color.FromArgb(214, 162, 62);
        static readonly Color Ok = Color.FromArgb(63, 185, 80), Warn = Color.FromArgb(210, 153, 34), Bad = Color.FromArgb(248, 81, 73);

        readonly Dictionary<string, Row> rows = new Dictionary<string, Row>();
        readonly Button startButton, openButton, setupButton, updateButton, folderButton;
        readonly NumericUpDown portBox;
        readonly CheckBox keepBox;
        readonly TextBox log;
        readonly System.Windows.Forms.Timer timer = new System.Windows.Forms.Timer();
        public PhpInfo Php;
        public PythonInfo Python;
        Process server;
        public bool Demo;  // example paths for screenshots

        public LauncherForm()
        {
            Text = "ForeverTracker";
            ClientSize = new Size(640, 520);
            StartPosition = FormStartPosition.CenterScreen;
            FormBorderStyle = FormBorderStyle.FixedSingle;
            MaximizeBox = false;
            BackColor = Bg; ForeColor = Ink;
            Font = new Font("Segoe UI", 9.5f);
            try { Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); } catch (Exception) { }

            var title = new Label { Text = "ForeverTracker", Font = new Font("Segoe UI Semibold", 17f), AutoSize = true, Location = new Point(24, 18) };
            var subtitle = new Label
            {
                Text = Env.T("Launcher", "Lanceur") + " · v" + Env.Version + " · " + Env.T("official content vs. your server", "contenu officiel vs votre serveur"),
                ForeColor = Muted, AutoSize = true, Location = new Point(27, 54)
            };
            Controls.Add(title); Controls.Add(subtitle);

            int y = 92;
            foreach (string key in new[] { "php", "python", "config", "site" })
            {
                var panel = new Panel { Location = new Point(24, y), Size = new Size(592, 50), BackColor = Surface };
                var row = new Row
                {
                    Dot = new Label { Text = "\u25CF", Font = new Font("Segoe UI", 12f), ForeColor = Muted, AutoSize = true, Location = new Point(12, 12) },
                    Name = new Label { Font = new Font("Segoe UI Semibold", 10f), AutoSize = true, Location = new Point(38, 7) },
                    Detail = new Label { ForeColor = Muted, AutoSize = false, Size = new Size(540, 18), Location = new Point(38, 27), AutoEllipsis = true },
                };
                panel.Controls.AddRange(new Control[] { row.Dot, row.Name, row.Detail });
                Controls.Add(panel);
                rows[key] = row;
                y += 56;
            }

            startButton = MakeButton(Env.T("Start the website", "Démarrer le site"), 24, 150, true);
            openButton = MakeButton(Env.T("Open", "Ouvrir"), 182, 86, false);
            setupButton = MakeButton(Env.T("Setup wizard", "Assistant"), 276, 110, false);
            updateButton = MakeButton(Env.T("Update data", "Mettre à jour"), 394, 118, false);
            folderButton = MakeButton(Env.T("Folder", "Dossier"), 520, 96, false);

            Controls.Add(new Label { Text = "Port", ForeColor = Muted, AutoSize = true, Location = new Point(24, 382) });
            portBox = new NumericUpDown { Minimum = 1024, Maximum = 65535, Value = Env.Port(), Location = new Point(60, 379), Width = 80, BackColor = Surface, ForeColor = Ink, BorderStyle = BorderStyle.FixedSingle };
            Controls.Add(portBox);
            keepBox = new CheckBox
            {
                Text = Env.T("Keep the website running when this window closes", "Garder le site actif à la fermeture de cette fenêtre"),
                Checked = Env.Keep(), AutoSize = true, Location = new Point(160, 380), ForeColor = Muted
            };
            Controls.Add(keepBox);
            log = new TextBox
            {
                Multiline = true, ReadOnly = true, ScrollBars = ScrollBars.Vertical, Location = new Point(24, 414), Size = new Size(592, 86),
                BackColor = Surface, ForeColor = Muted, BorderStyle = BorderStyle.FixedSingle, Font = new Font("Consolas", 8.5f)
            };
            Controls.Add(log);

            startButton.Click += delegate { if (Ours()) StopSite(); else StartSite(); UpdateStatus(); };
            openButton.Click += delegate { Open(Url()); };
            setupButton.Click += delegate { Open(Url() + "setup"); };
            folderButton.Click += delegate { Process.Start("explorer.exe", "\"" + Env.Root + "\""); };
            updateButton.Click += delegate { RunUpdate(); };
            keepBox.CheckedChanged += delegate { Env.Settings["keep"] = keepBox.Checked; Env.Save(); };
            portBox.ValueChanged += delegate { UpdateStatus(); };
            FormClosing += delegate { if (!keepBox.Checked) StopSite(); };
            timer.Interval = 3000;
            timer.Tick += delegate { UpdateStatus(); };
            Shown += delegate { Startup(); };
        }

        Button MakeButton(string label, int x, int width, bool primary)
        {
            var b = new Button { Text = label, Location = new Point(x, 330), Size = new Size(width, 34), FlatStyle = FlatStyle.Flat, Cursor = Cursors.Hand };
            b.FlatAppearance.BorderColor = primary ? Accent : Line;
            if (primary) { b.BackColor = Accent; b.ForeColor = Color.FromArgb(20, 16, 8); b.Font = new Font("Segoe UI Semibold", 10f); }
            else { b.BackColor = Surface; b.ForeColor = Ink; }
            Controls.Add(b);
            return b;
        }

        public void Log(string line) { log.AppendText(DateTime.Now.ToString("HH:mm:ss") + "  " + line + "\r\n"); }
        string Url() { return "http://127.0.0.1:" + (int)portBox.Value + "/"; }
        bool Ours() { return server != null && !server.HasExited; }
        static void Open(string url) { try { Process.Start(url); } catch (Exception) { } }

        void SetRow(string key, string state, string name, string detail)
        {
            Row r = rows[key];
            r.Name.Text = name; r.Detail.Text = detail;
            r.Dot.ForeColor = state == "ok" ? Ok : state == "warn" ? Warn : state == "bad" ? Bad : Muted;
        }

        public void UpdateStatus()
        {
            if (Php != null)
                SetRow("php", "ok", "PHP " + Php.Version, (Demo ? @"C:\php\php.exe" : Php.Path) + " · " +
                    (Php.Gmp ? "mysqli, gmp" : "mysqli · " + Env.T("gmp missing: game-account logins off", "gmp absent : connexion par compte de jeu désactivée")));
            else
                SetRow("php", "bad", Env.T("PHP not found", "PHP introuvable"), Env.T("Install PHP 8 with mysqli (windows.php.net), or set \"php\" in data\\launcher.json.", "Installez PHP 8 avec mysqli (windows.php.net), ou renseignez « php » dans data\\launcher.json."));
            if (Python != null) SetRow("python", "ok", Python.Version, Env.T("command: ", "commande : ") + Python.Command);
            else SetRow("python", "warn", Env.T("Python 3 not found", "Python 3 introuvable"), Env.T("Needed for the analysis and the locator: python.org, tick \"Add python.exe to PATH\".", "Nécessaire pour l’analyse et le localisateur : python.org, cochez « Add python.exe to PATH »."));
            if (Env.Configured()) SetRow("config", "ok", Env.T("Configured", "Configuré"), Demo ? @"C:\ForeverTracker\config.ini" : Path.Combine(Env.Root, "config.ini"));
            else SetRow("config", "warn", Env.T("Not configured yet", "Pas encore configuré"), Env.T("Start the website: the setup wizard finds your server and game client.", "Démarrez le site : l’assistant trouve votre serveur et votre client de jeu."));
            bool running = Detect.PortAnswers((int)portBox.Value);
            if (running) SetRow("site", "ok", Env.T("Website running", "Site en marche"), Url());
            else SetRow("site", "idle", Env.T("Website stopped", "Site arrêté"), Env.T("Will listen on ", "Écoutera sur ") + Url());
            bool ours = Ours();
            startButton.Text = ours ? Env.T("Stop the website", "Arrêter le site") : running ? Env.T("Already running", "Déjà en marche") : Env.T("Start the website", "Démarrer le site");
            startButton.Enabled = Php != null && (ours || !running);
            openButton.Enabled = running;
            setupButton.Enabled = running;
            updateButton.Enabled = Python != null && Env.Configured();
        }

        void StartSite()
        {
            int port = (int)portBox.Value;
            if (Detect.PortAnswers(port)) { Log(Env.T("Port " + port + " already answers: the website may already be running.", "Le port " + port + " répond déjà : le site tourne peut-être déjà.")); return; }
            Env.Settings["port"] = port; Env.Save();
            string ini = Php.Ini != "" ? "-c \"" + Php.Ini + "\" " : "";
            string line = "\"" + Php.Path + "\" " + ini + "-S 127.0.0.1:" + port + " -t \"" + Path.Combine(Env.Root, "public") + "\" \"" +
                Path.Combine(Env.Root, @"public\router.php") + "\" >> \"" + Env.ServerLog + "\" 2>&1";
            var info = new ProcessStartInfo(Environment.GetEnvironmentVariable("ComSpec") ?? "cmd.exe", "/c \"" + line + "\"");
            info.WorkingDirectory = Env.Root; info.CreateNoWindow = true; info.UseShellExecute = false;
            server = Process.Start(info);
            Log(Env.T("Website started on ", "Site démarré sur ") + Url());
            for (int i = 0; i < 20 && !Detect.PortAnswers(port); i++) Thread.Sleep(150);
            Open(Env.Configured() ? Url() : Url() + "setup");
        }

        void StopSite()
        {
            if (Ours())
            {
                try
                {
                    var kill = new ProcessStartInfo("taskkill.exe", "/PID " + server.Id + " /T /F");
                    kill.CreateNoWindow = true; kill.UseShellExecute = false;
                    Process.Start(kill).WaitForExit(5000);
                    Log(Env.T("Website stopped.", "Site arrêté."));
                }
                catch (Exception) { }
            }
            server = null;
        }

        void RunUpdate()
        {
            string py = Python.Command;
            string cmd = "title ForeverTracker - " + Env.T("update", "mise à jour") + " & cd /d \"" + Env.Root + "\" & " + py + " -X utf8 tools\\tracker.py analyse && " +
                py + " -X utf8 tools\\tracker.py locate & echo. & pause";
            Process.Start(Environment.GetEnvironmentVariable("ComSpec") ?? "cmd.exe", "/c \"" + cmd + "\"");
            Log(Env.T("Analysis and locator started in a new window.", "Analyse et localisateur lancés dans une nouvelle fenêtre."));
        }

        public void Detection()
        {
            Php = Detect.FindPhp();
            if (Php != null) { Env.Settings["php"] = Php.Path; Env.Settings["php_ini"] = Php.Ini; Env.Save(); }
            Python = Detect.FindPython();
        }

        void Startup()
        {
            if (Demo) return;
            Log(Env.T("Looking for PHP and Python…", "Recherche de PHP et de Python…"));
            Cursor = Cursors.WaitCursor;
            Refresh();
            Detection();
            Cursor = Cursors.Default;
            if (Php != null) Log("PHP: " + Php.Path + (Php.Ini != "" ? " (ini: " + Php.Ini + ")" : ""));
            else Log(Env.T("No PHP 8 with mysqli found.", "Aucun PHP 8 avec mysqli trouvé."));
            if (Python != null) Log("Python: " + Python.Version);
            UpdateStatus();
            timer.Start();
            if (Php != null && !Env.Configured() && !Detect.PortAnswers((int)portBox.Value))
            {
                Log(Env.T("First run: starting the website and the setup wizard.", "Premier lancement : démarrage du site et de l’assistant."));
                StartSite();
                UpdateStatus();
            }
        }
    }

    static class Program
    {
        [DllImport("kernel32.dll")] static extern bool AttachConsole(int pid);

        static string Arg(string[] args, string name)
        {
            for (int i = 0; i < args.Length - 1; i++) if (args[i] == name) return args[i + 1];
            return "";
        }

        [STAThread]
        static int Main(string[] args)
        {
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            Env.Init(Arg(args, "--lang"));

            if (Array.IndexOf(args, "--check") >= 0)
            {
                AttachConsole(-1);
                PhpInfo php = Detect.FindPhp();
                PythonInfo python = Detect.FindPython();
                var text = new StringBuilder();
                text.AppendLine();
                text.AppendLine("ForeverTracker " + Env.Version + " (" + Env.Root + ")");
                text.AppendLine("PHP:    " + (php != null ? php.Version + " " + php.Path + (php.Ini != "" ? " -c " + php.Ini : "") + " gmp=" + php.Gmp : "not found"));
                text.AppendLine("Python: " + (python != null ? python.Version + " (" + python.Command + ")" : "not found"));
                text.AppendLine("Config: " + Env.Configured());
                text.AppendLine("Port " + Env.Port() + ": " + (Detect.PortAnswers(Env.Port()) ? "answers" : "free"));
                Console.Write(text.ToString());
                File.WriteAllText(Path.Combine(Env.Data, "launcher-check.txt"), text.ToString(), new UTF8Encoding(false));
                return 0;
            }

            var form = new LauncherForm();
            string shot = Arg(args, "--screenshot");
            if (shot != "")
            {
                // shown off-screen, with example paths, then saved as a picture
                form.Demo = true;
                form.StartPosition = FormStartPosition.Manual;
                form.Location = new Point(-4000, -4000);
                form.ShowInTaskbar = false;
                form.Show();
                form.Detection();
                form.UpdateStatus();
                form.Log(Env.T("Looking for PHP and Python…", "Recherche de PHP et de Python…"));
                form.Log(@"PHP: C:\php\php.exe");
                if (form.Python != null) form.Log("Python: " + form.Python.Version);
                form.Log(Env.T("Website started on ", "Site démarré sur ") + "http://127.0.0.1:" + Env.Port() + "/");
                for (int i = 0; i < 10; i++) { Application.DoEvents(); Thread.Sleep(50); }
                using (var bitmap = new Bitmap(form.Width, form.Height))
                {
                    form.DrawToBitmap(bitmap, new Rectangle(0, 0, form.Width, form.Height));
                    bitmap.Save(shot, ImageFormat.Png);
                }
                form.Close();
                return 0;
            }
            Application.Run(form);
            return 0;
        }
    }
}
