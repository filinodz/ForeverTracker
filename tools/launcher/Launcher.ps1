param([switch]$Check, [switch]$Smoke, [string]$Screenshot = '', [string]$Lang = '')
# ForeverTracker launcher (Windows): finds PHP and Python, starts the website with PHP's built-in server, opens the
# setup wizard on the first run, and updates the data. Started by ForeverTracker.bat; settings in data\launcher.json.
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()

$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$Data = Join-Path $Root 'data'
$SettingsPath = Join-Path $Data 'launcher.json'
$ServerLog = Join-Path $Data 'launcher-server.log'
New-Item -ItemType Directory -Force $Data | Out-Null
$Version = '?'
$boot = Get-Content (Join-Path $Root 'app\bootstrap.php') -Raw -ErrorAction SilentlyContinue
if ($boot -match "FT_VERSION = '([^']+)'") { $Version = $Matches[1] }
$French = if ($Lang) { $Lang -eq 'fr' } else { (Get-Culture).TwoLetterISOLanguageName -eq 'fr' }
function T($en, $fr) { if ($French) { $fr } else { $en } }

# ------------------------------------------------------------------ settings
$Settings = @{ php = ''; php_ini = ''; port = 8090; keep = $false }
if (Test-Path $SettingsPath) {
    try { $saved = Get-Content $SettingsPath -Raw | ConvertFrom-Json; foreach ($p in $saved.PSObject.Properties) { $Settings[$p.Name] = $p.Value } } catch { }
}
function Save-Settings { [IO.File]::WriteAllText($SettingsPath, ($Settings | ConvertTo-Json), (New-Object Text.UTF8Encoding $false)) }

# ------------------------------------------------------------------ detection
function Invoke-Quiet($exe, [string[]]$arguments) {
    try {
        $info = New-Object Diagnostics.ProcessStartInfo
        $info.FileName = $exe; $info.Arguments = ($arguments -join ' '); $info.UseShellExecute = $false
        $info.RedirectStandardOutput = $true; $info.RedirectStandardError = $true; $info.CreateNoWindow = $true
        $p = [Diagnostics.Process]::Start($info)
        $out = $p.StandardOutput.ReadToEnd() + $p.StandardError.ReadToEnd()
        if (-not $p.WaitForExit(15000)) { $p.Kill() }
        return $out
    } catch { return '' }
}

# PHP: version and extensions with an optional ini; returns @{ ok; version; mysqli; gmp }
function Test-Php($php, $ini) {
    $phpArgs = @()
    if ($ini) { $phpArgs += @('-c', ('"' + $ini + '"')) }
    $phpArgs += @('-r', '"echo PHP_VERSION, chr(124), (int)extension_loaded(chr(109).chr(121).chr(115).chr(113).chr(108).chr(105)), chr(124), (int)extension_loaded(chr(103).chr(109).chr(112));"')
    $out = (Invoke-Quiet $php $phpArgs).Trim()
    $line = ($out -split "`n" | Where-Object { $_ -match '^\d+\.\d+\.\d+\|' } | Select-Object -Last 1)
    if (-not $line) { return @{ ok = $false } }
    $parts = $line.Trim().Split('|')
    return @{ ok = ([version]($parts[0] -replace '[^\d.].*$', '') -ge [version]'8.0'); version = $parts[0]; mysqli = $parts[1] -eq '1'; gmp = $parts[2] -eq '1' }
}

# A php.ini next to the data that loads the extensions a portable PHP ships but does not enable.
function New-PhpIni($php) {
    $ext = Join-Path (Split-Path -Parent $php) 'ext'
    if (-not (Test-Path (Join-Path $ext 'php_mysqli.dll'))) { return '' }
    $lines = @(('; Written by the ForeverTracker launcher for ' + $php), ('extension_dir = "' + ($ext -replace '\\', '/') + '"'))
    foreach ($name in 'mysqli', 'gmp', 'mbstring', 'openssl', 'curl') { if (Test-Path (Join-Path $ext "php_$name.dll")) { $lines += "extension = $name" } }
    $lines += @('memory_limit = 256M', 'display_errors = Off', 'log_errors = On')
    $ini = Join-Path $Data 'php-launcher.ini'
    [IO.File]::WriteAllText($ini, ($lines -join "`r`n") + "`r`n", (New-Object Text.UTF8Encoding $false))
    return $ini
}

function Find-Php {
    $candidates = New-Object Collections.Generic.List[string]
    if ($Settings.php) { $candidates.Add($Settings.php) }
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { $candidates.Add($cmd.Source) }
    foreach ($c in 'C:\php\php.exe', 'C:\xampp\php\php.exe', 'D:\xampp\php\php.exe', 'C:\tools\php\php.exe', "$env:ProgramFiles\PHP\php.exe", 'C:\laragon\bin\php\php.exe') { $candidates.Add($c) }
    foreach ($d in @("$env:ProgramFiles\PHP", 'C:\laragon\bin\php', 'C:\tools')) {
        if (Test-Path $d) { Get-ChildItem $d -Directory -ErrorAction SilentlyContinue | ForEach-Object { $candidates.Add((Join-Path $_.FullName 'php.exe')) } }
    }
    # servers and repacks next to ForeverTracker often bring their own PHP (cms\php, web\php…)
    $parent = Split-Path -Parent $Root
    Get-ChildItem $parent -Directory -ErrorAction SilentlyContinue | ForEach-Object {
        foreach ($sub in 'php', 'cms\php', 'web\php', 'tools\php', 'ForeverHub\cms\php') {
            $candidates.Add((Join-Path $_.FullName "$sub\php.exe"))
        }
    }
    foreach ($php in ($candidates | Select-Object -Unique)) {
        if (-not $php -or -not (Test-Path $php)) { continue }
        $ini = ''
        if ($Settings.php -eq $php -and $Settings.php_ini -and (Test-Path $Settings.php_ini)) { $ini = $Settings.php_ini }
        $test = Test-Php $php $ini
        if ($test.ok -and -not $test.mysqli) {
            $made = New-PhpIni $php
            if ($made) { $ini = $made; $test = Test-Php $php $ini }
        }
        if ($test.ok -and $test.mysqli) { return @{ path = $php; ini = $ini; version = $test.version; gmp = $test.gmp } }
    }
    return $null
}

function Find-Python {
    foreach ($c in @(@('python', '--version'), @('py', '-3 --version'), @('python3', '--version'))) {
        $out = Invoke-Quiet $c[0] @($c[1])
        if ($out -match 'Python 3\.(\d+)\.\d+' -and [int]$Matches[1] -ge 9) { return @{ command = $c[0]; version = $Matches[0] } }
    }
    return $null
}

function Test-Port($port) {
    $client = New-Object Net.Sockets.TcpClient
    try { $task = $client.ConnectAsync('127.0.0.1', [int]$port); return ($task.Wait(400) -and $client.Connected) } catch { return $false } finally { $client.Close() }
}

# -Check: prints what the launcher finds, without the window (for support requests)
if ($Check) {
    $php = Find-Php; $python = Find-Python
    'ForeverTracker ' + $Version
    'PHP:    ' + $(if ($php) { $php.version + ' ' + $php.path + $(if ($php.ini) { ' -c ' + $php.ini } else { '' }) + ' gmp=' + $php.gmp } else { 'not found' })
    'Python: ' + $(if ($python) { $python.version + ' (' + $python.command + ')' } else { 'not found' })
    'Config: ' + (Test-Path (Join-Path $Root 'config.ini'))
    'Port ' + $Settings.port + ': ' + $(if (Test-Port $Settings.port) { 'answers' } else { 'free' })
    return
}

# ------------------------------------------------------------------ window
$Bg =[Drawing.Color]::FromArgb(14, 15, 19); $Surface = [Drawing.Color]::FromArgb(21, 22, 27); $ColorLine = [Drawing.Color]::FromArgb(38, 40, 48)
$ColorText = [Drawing.Color]::FromArgb(231, 232, 235); $Muted = [Drawing.Color]::FromArgb(149, 153, 163); $Accent = [Drawing.Color]::FromArgb(214, 162, 62)
$Ok = [Drawing.Color]::FromArgb(63, 185, 80); $Warn = [Drawing.Color]::FromArgb(210, 153, 34); $Bad = [Drawing.Color]::FromArgb(248, 81, 73)
$FontUi = New-Object Drawing.Font('Segoe UI', 9.5)
$FontBold = New-Object Drawing.Font('Segoe UI Semibold', 10)

$form = New-Object Windows.Forms.Form
$form.Text = 'ForeverTracker'
$form.ClientSize = New-Object Drawing.Size(640, 520)
$form.StartPosition = 'CenterScreen'
$form.FormBorderStyle = 'FixedSingle'
$form.MaximizeBox = $false
$form.BackColor = $Bg
$form.ForeColor = $ColorText
$form.Font = $FontUi
$iconPath = Join-Path $Root 'tools\launcher\ForeverTracker.ico'
if (Test-Path $iconPath) { $form.Icon = New-Object Drawing.Icon($iconPath) }

$title = New-Object Windows.Forms.Label
$title.Text = 'ForeverTracker'; $title.Font = New-Object Drawing.Font('Segoe UI Semibold', 17); $title.AutoSize = $true; $title.Location = New-Object Drawing.Point(24, 18)
$form.Controls.Add($title)
$subtitle = New-Object Windows.Forms.Label
$subtitle.Text = (T 'Launcher' 'Lanceur') + " · v$Version · " + (T 'official content vs. your server' 'contenu officiel vs votre serveur')
$subtitle.ForeColor = $Muted; $subtitle.AutoSize = $true; $subtitle.Location = New-Object Drawing.Point(27, 54)
$form.Controls.Add($subtitle)

# status rows
$rows = @{}
$y = 92
foreach ($key in 'php', 'python', 'config', 'site') {
    $panel = New-Object Windows.Forms.Panel
    $panel.Location = New-Object Drawing.Point(24, $y); $panel.Size = New-Object Drawing.Size(592, 50); $panel.BackColor = $Surface
    $dot = New-Object Windows.Forms.Label
    $dot.Text = [char]0x25CF; $dot.Font = New-Object Drawing.Font('Segoe UI', 12); $dot.ForeColor = $Muted; $dot.AutoSize = $true; $dot.Location = New-Object Drawing.Point(12, 12)
    $name = New-Object Windows.Forms.Label
    $name.Font = $FontBold; $name.AutoSize = $true; $name.Location = New-Object Drawing.Point(38, 7)
    $detail = New-Object Windows.Forms.Label
    $detail.ForeColor = $Muted; $detail.AutoSize = $false; $detail.Size = New-Object Drawing.Size(540, 18); $detail.Location = New-Object Drawing.Point(38, 27); $detail.AutoEllipsis = $true
    $panel.Controls.AddRange(@($dot, $name, $detail))
    $form.Controls.Add($panel)
    $rows[$key] = @{ dot = $dot; name = $name; detail = $detail }
    $y += 56
}
function Set-Row($key, $state, $name, $detail) {
    $r = $rows[$key]
    $r.name.Text = $name; $r.detail.Text = $detail
    $r.dot.ForeColor = switch ($state) { 'ok' { $Ok } 'warn' { $Warn } 'bad' { $Bad } default { $Muted } }
}

function New-Button($label, $x, $width, $primary) {
    $b = New-Object Windows.Forms.Button
    $b.Text = $label; $b.Location = New-Object Drawing.Point($x, 330); $b.Size = New-Object Drawing.Size($width, 34)
    $b.FlatStyle = 'Flat'; $b.FlatAppearance.BorderColor = $ColorLine; $b.Cursor = 'Hand'
    if ($primary) { $b.BackColor = $Accent; $b.ForeColor = [Drawing.Color]::FromArgb(20, 16, 8); $b.Font = $FontBold; $b.FlatAppearance.BorderColor = $Accent }
    else { $b.BackColor = $Surface; $b.ForeColor = $ColorText }
    $form.Controls.Add($b)
    return $b
}
$startButton = New-Button (T 'Start the website' 'Démarrer le site') 24 150 $true
$openButton = New-Button (T 'Open' 'Ouvrir') 182 86 $false
$setupButton = New-Button (T 'Setup wizard' 'Assistant') 276 110 $false
$updateButton = New-Button (T 'Update data' 'Mettre à jour') 394 118 $false
$folderButton = New-Button (T 'Folder' 'Dossier') 520 96 $false

$portLabel = New-Object Windows.Forms.Label
$portLabel.Text = 'Port'; $portLabel.ForeColor = $Muted; $portLabel.AutoSize = $true; $portLabel.Location = New-Object Drawing.Point(24, 382)
$form.Controls.Add($portLabel)
$portBox = New-Object Windows.Forms.NumericUpDown
$portBox.Minimum = 1024; $portBox.Maximum = 65535; $portBox.Value = [int]$Settings.port; $portBox.Location = New-Object Drawing.Point(60, 379); $portBox.Width = 80
$portBox.BackColor = $Surface; $portBox.ForeColor = $ColorText; $portBox.BorderStyle = 'FixedSingle'
$form.Controls.Add($portBox)
$keep = New-Object Windows.Forms.CheckBox
$keep.Text = T 'Keep the website running when this window closes' 'Garder le site actif à la fermeture de cette fenêtre'
$keep.Checked = [bool]$Settings.keep; $keep.AutoSize = $true; $keep.Location = New-Object Drawing.Point(160, 380); $keep.ForeColor = $Muted
$form.Controls.Add($keep)

$log = New-Object Windows.Forms.TextBox
$log.Multiline = $true; $log.ReadOnly = $true; $log.ScrollBars = 'Vertical'; $log.Location = New-Object Drawing.Point(24, 414); $log.Size = New-Object Drawing.Size(592, 86)
$log.BackColor = $Surface; $log.ForeColor = $Muted; $log.BorderStyle = 'FixedSingle'; $log.Font = New-Object Drawing.Font('Consolas', 8.5)
$form.Controls.Add($log)
function Write-Log($line) { $log.AppendText((Get-Date -Format 'HH:mm:ss') + '  ' + $line + "`r`n") }

# ------------------------------------------------------------------ state
$script:Php = $null; $script:Python = $null; $script:Server = $null
function Get-Url { "http://127.0.0.1:$([int]$portBox.Value)/" }
function Test-Configured { Test-Path (Join-Path $Root 'config.ini') }

function Update-Status {
    if ($script:Php) {
        $extra = if ($script:Php.gmp) { 'mysqli, gmp' } else { 'mysqli · ' + (T 'gmp missing: game-account logins off' 'gmp absent : connexion par compte de jeu désactivée') }
        Set-Row 'php' 'ok' ('PHP ' + $script:Php.version) ($script:Php.path + ' · ' + $extra)
    } else { Set-Row 'php' 'bad' (T 'PHP not found' 'PHP introuvable') (T 'Install PHP 8 with mysqli (windows.php.net) or point the launcher to php.exe (Folder > data\launcher.json).' 'Installez PHP 8 avec mysqli (windows.php.net) ou indiquez php.exe (Dossier > data\launcher.json).') }
    if ($script:Python) { Set-Row 'python' 'ok' $script:Python.version ((T 'command: ' 'commande : ') + $script:Python.command) }
    else { Set-Row 'python' 'warn' (T 'Python 3 not found' 'Python 3 introuvable') (T 'Needed for the analysis and the locator: python.org, tick "Add python.exe to PATH".' 'Nécessaire pour l''analyse et le localisateur : python.org, cochez « Add python.exe to PATH ».') }
    if (Test-Configured) { Set-Row 'config' 'ok' (T 'Configured' 'Configuré') (Join-Path $Root 'config.ini') }
    else { Set-Row 'config' 'warn' (T 'Not configured yet' 'Pas encore configuré') (T 'Start the website: the setup wizard finds your server and game client.' 'Démarrez le site : l''assistant trouve votre serveur et votre client de jeu.') }
    $running = Test-Port ([int]$portBox.Value)
    if ($running) { Set-Row 'site' 'ok' (T 'Website running' 'Site en marche') (Get-Url) }
    else { Set-Row 'site' 'idle' (T 'Website stopped' 'Site arrêté') ((T 'Will listen on ' 'Écoutera sur ') + (Get-Url)) }
    $ours = $script:Server -and -not $script:Server.HasExited
    if ($ours) { $startButton.Text = T 'Stop the website' 'Arrêter le site' }
    elseif ($running) { $startButton.Text = T 'Already running' 'Déjà en marche' }
    else { $startButton.Text = T 'Start the website' 'Démarrer le site' }
    $startButton.Enabled = [bool]$script:Php -and ($ours -or -not $running)
    $openButton.Enabled = $running
    $setupButton.Enabled = $running
    $updateButton.Enabled = [bool]$script:Python -and (Test-Configured)
}

function Start-Site {
    $port = [int]$portBox.Value
    if (Test-Port $port) { Write-Log ((T 'Port ' 'Le port ') + $port + (T ' already answers: the website may already be running.' ' répond déjà : le site tourne peut-être déjà.')); return }
    $Settings.port = $port; Save-Settings
    $iniArg = ''
    if ($script:Php.ini) { $iniArg = '-c "' + $script:Php.ini + '" ' }
    $line = '"' + $script:Php.path + '" ' + $iniArg + '-S 127.0.0.1:' + $port + ' -t "' + (Join-Path $Root 'public') + '" "' + (Join-Path $Root 'public\router.php') + '" >> "' + $ServerLog + '" 2>&1'
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName = $env:ComSpec; $info.Arguments = '/c "' + $line + '"'; $info.WorkingDirectory = $Root; $info.CreateNoWindow = $true; $info.UseShellExecute = $false
    $script:Server = [Diagnostics.Process]::Start($info)
    Write-Log ((T 'Website started on ' 'Site démarré sur ') + (Get-Url))
    Start-Sleep -Milliseconds 700
    if (Test-Configured) { Start-Process (Get-Url) } else { Start-Process ((Get-Url) + 'setup') }
}
function Stop-Site {
    if ($script:Server -and -not $script:Server.HasExited) {
        & taskkill.exe /PID $script:Server.Id /T /F | Out-Null
        Write-Log (T 'Website stopped.' 'Site arrêté.')
    }
    $script:Server = $null
}

$startButton.Add_Click({ if ($script:Server -and -not $script:Server.HasExited) { Stop-Site } else { Start-Site }; Update-Status })
$openButton.Add_Click({ Start-Process (Get-Url) })
$setupButton.Add_Click({ Start-Process ((Get-Url) + 'setup') })
$folderButton.Add_Click({ Start-Process explorer.exe $Root })
$updateButton.Add_Click({
    $py = $script:Python.command
    $cmdLine = 'title ForeverTracker - ' + (T 'update' 'mise à jour') + ' & cd /d "' + $Root + '" & ' + $py + ' tools\tracker.py analyse && ' + $py + ' tools\tracker.py locate & echo. & pause'
    Start-Process $env:ComSpec -ArgumentList '/c', $cmdLine
    Write-Log (T 'Analysis and locator started in a new window.' 'Analyse et localisateur lancés dans une nouvelle fenêtre.')
})
$keep.Add_CheckedChanged({ $Settings.keep = $keep.Checked; Save-Settings })
$portBox.Add_ValueChanged({ Update-Status })
$form.Add_FormClosing({ if (-not $keep.Checked) { Stop-Site } })

$timer = New-Object Windows.Forms.Timer
$timer.Interval = 3000
$timer.Add_Tick({ Update-Status })

$form.Add_Shown({
    $form.Refresh()
    Write-Log (T 'Looking for PHP and Python…' 'Recherche de PHP et de Python…')
    $script:Php = Find-Php
    if ($script:Php) { $Settings.php = $script:Php.path; $Settings.php_ini = $script:Php.ini; Save-Settings; Write-Log ('PHP: ' + $script:Php.path + $(if ($script:Php.ini) { ' (ini: ' + $script:Php.ini + ')' } else { '' })) }
    else { Write-Log (T 'No PHP 8 with mysqli found.' 'Aucun PHP 8 avec mysqli trouvé.') }
    $script:Python = Find-Python
    if ($script:Python) { Write-Log ('Python: ' + $script:Python.version) }
    Update-Status
    $timer.Start()
    if ($script:Php -and -not (Test-Configured) -and -not (Test-Port ([int]$portBox.Value))) {
        Write-Log (T 'First run: starting the website and the setup wizard.' 'Premier lancement : démarrage du site et de l''assistant.')
        Start-Site; Update-Status
    }
})
if ($Screenshot) {
    # shows the window off-screen, lets it detect PHP and Python, and saves it as a picture (documentation)
    $form.StartPosition = 'Manual'; $form.Location = New-Object Drawing.Point(-4000, -4000); $form.ShowInTaskbar = $false
    $form.Show()
    for ($i = 0; $i -lt 60 -and -not $script:Php; $i++) { [Windows.Forms.Application]::DoEvents(); Start-Sleep -Milliseconds 100 }
    # example paths, not this computer's
    $rows.php.detail.Text = 'C:\php\php.exe · mysqli, gmp'; $rows.config.detail.Text = 'C:\ForeverTracker\config.ini'
    $log.Clear(); Write-Log (T 'Looking for PHP and Python…' 'Recherche de PHP et de Python…'); Write-Log ('PHP: C:\php\php.exe'); Write-Log ('Python: ' + $script:Python.version); Write-Log ((T 'Website started on ' 'Site démarré sur ') + (Get-Url))
    for ($i = 0; $i -lt 10; $i++) { [Windows.Forms.Application]::DoEvents(); Start-Sleep -Milliseconds 50 }
    $bmp = New-Object Drawing.Bitmap($form.Width, $form.Height); $form.DrawToBitmap($bmp, (New-Object Drawing.Rectangle(0, 0, $form.Width, $form.Height))); $bmp.Save($Screenshot, [Drawing.Imaging.ImageFormat]::Png); $bmp.Dispose()
    $timer.Stop(); $form.Close(); return
}
if ($Smoke) {
    # builds the window and its status without showing it (tests)
    $script:Php = Find-Php; $script:Python = Find-Python; Update-Status
    foreach ($k in 'php', 'python', 'config', 'site') { $k + ': ' + $rows[$k].name.Text + ' | ' + $rows[$k].detail.Text }
    'start button: ' + $startButton.Text + ' enabled=' + $startButton.Enabled
    $form.Dispose(); return
}
[void]$form.ShowDialog()
