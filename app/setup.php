<?php
// Setup wizard: finds the server, its databases and the game client on this computer, writes config.ini, creates the
// administrator, then stores the client build and runs the first analysis and the locator, with live progress.
//   /setup        the wizard (from this computer only while there is no config.ini; administrators afterwards)
//   /api/setup    its JSON actions

function setup_route(array $parts): void
{
    $configured = is_file(config_path());
    if (!$configured && !setup_allowed_here()) {
        fail_page('Setup', 'The setup wizard only answers on the computer that runs ForeverTracker: open http://127.0.0.1:<port>/setup there.', 403);
    }
    if ($configured && !is_admin()) redirect(url('login', ['next' => url('setup')]));
    if ($parts === ['api', 'setup']) { setup_api(); return; }
    require FT_APP . '/pages/setup.php';
}

// ------------------------------------------------------------------ detection
/** Folders that hold worldserver.conf / .build.info, found under the drives' first levels within a time budget. */
function setup_scan(float $budget = 8.0): array
{
    $skip = array_flip(array_map('strtolower', ['Windows', '$Recycle.Bin', 'System Volume Information', 'ProgramData', 'AppData', 'node_modules', '.git', 'WindowsApps',
        'Recovery', 'PerfLogs', 'Microsoft', 'Packages', 'cache', 'Cache', 'Logs', 'logs', 'Interface', 'WTF', 'Data', 'data', 'dbc', 'maps', 'vmaps', 'mmaps', 'cameras',
        'buildings', 'sql', 'src', 'vendor', '__pycache__', 'Steam', 'steamapps', 'Temp', 'tmp', 'Windows.old', 'Intel', 'AMD', 'NVIDIA', 'build', 'deps',
        'backups', 'hub-backups', 'Videos', 'Music', 'Pictures', 'node', 'python', 'php', 'mysql', 'Fonts', 'Assets', 'Checksums']));
    $roots = [];
    if (PHP_OS_FAMILY === 'Windows') {
        foreach (range('C', 'Z') as $letter) if (is_dir($letter . ':/')) $roots[] = $letter . ':';
    } else {
        foreach (['/opt', '/srv', '/home', '/root', '/var/www', '/usr/local'] as $root) if (is_dir($root)) $roots[] = $root;
    }
    $deadline = microtime(true) + $budget;
    $servers = []; $clients = []; $seen = [];
    // ForeverTracker's own neighbourhood first and completely (servers and clients often sit next to it), then the drives
    foreach ([[[dirname(FT_ROOT)], min($deadline, microtime(true) + $budget / 2)], [$roots, $deadline]] as [$starts, $until]) {
    $queue = array_map(fn($r) => [$r, 0], $starts);
    while ($queue && microtime(true) < $until) {
        [$dir, $depth] = array_shift($queue);
        $key = strtolower(str_replace('\\', '/', $dir));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $entries = @scandir($dir . '/');
        if ($entries === false) continue;
        $names = array_flip($entries);
        if (isset($names['worldserver.conf'])) $servers[] = str_replace('\\', '/', $dir);
        if (isset($names['.build.info'])) $clients[] = str_replace('\\', '/', $dir);
        if ($depth >= 5) continue;
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || isset($skip[strtolower($name)]) || $name[0] === '.') continue;
            $path = $dir . '/' . $name;
            if (@is_dir($path) && !@is_link($path)) $queue[] = [$path, $depth + 1];
        }
    }
    }
    return [array_values(array_unique($servers)), array_values(array_unique($clients)), microtime(true) >= $deadline];
}

/** Settings read from a worldserver.conf (databases, data folder, remote console). */
function setup_server(string $folder): ?array
{
    $folder = rtrim(str_replace('\\', '/', $folder), '/');
    $conf = $folder . '/worldserver.conf';
    if (!is_file($conf)) return null;
    $values = [];
    foreach (file($conf, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*([A-Za-z][\w.]*)\s*=\s*"?([^"]*)"?\s*$/', $line, $m)) $values[$m[1]] = trim($m[2]);
    }
    $absolute = fn(string $path) => preg_match('#^([A-Za-z]:)?[/\\\\]#', $path) ? str_replace('\\', '/', $path) : $folder . '/' . preg_replace('#^\./#', '', str_replace('\\', '/', $path));
    $data = setup_real($absolute($values['DataDir'] ?? '.'));
    $db = function (string $key) use ($values) {
        $parts = explode(';', (string)($values[$key] ?? ''));
        return count($parts) >= 5 ? ['host' => $parts[0], 'port' => (int)$parts[1], 'user' => $parts[2], 'password' => $parts[3], 'database' => $parts[4]] : null;
    };
    $locales = [];
    foreach (['enUS', 'frFR', 'deDE', 'esES', 'esMX', 'ruRU', 'ptBR', 'itIT', 'koKR', 'zhCN', 'zhTW'] as $locale) {
        if (is_file("$data/dbc/$locale/SpellName.db2")) $locales[] = $locale;
    }
    $mysql = '';
    foreach ([$values['MySQLExecutable'] ?? '', dirname($folder) . '/mysql/bin/mysql.exe', $folder . '/mysql/bin/mysql.exe', dirname($folder) . '/mysql/bin/mysql'] as $candidate) {
        if ($candidate !== '' && is_file($absolute($candidate))) { $mysql = setup_real($absolute($candidate)); break; }
    }
    $extractor = '';
    foreach (['mapextractor.exe', 'mapextractor', 'tools/mapextractor.exe', 'tools/mapextractor'] as $name) if (is_file("$folder/$name")) { $extractor = "$folder/$name"; break; }
    return [
        'folder' => $folder, 'data' => $data, 'locales' => $locales,
        'maps' => is_dir("$data/maps"), 'vmaps' => is_dir("$data/vmaps"), 'mmaps' => is_dir("$data/mmaps"),
        'auth' => $db('LoginDatabaseInfo'), 'world' => $db('WorldDatabaseInfo'), 'hotfixes' => $db('HotfixDatabaseInfo'),
        'ra' => ['enabled' => ($values['Ra.Enable'] ?? '0') === '1', 'host' => in_array($values['Ra.IP'] ?? '', ['', '0.0.0.0'], true) ? '127.0.0.1' : $values['Ra.IP'], 'port' => (int)($values['Ra.Port'] ?? 3443)],
        'mysql' => $mysql, 'extractor' => $extractor, 'worldserver' => is_file("$folder/worldserver.exe") || is_file("$folder/worldserver"),
    ];
}

function setup_real(string $path): string
{
    $real = realpath($path);
    return str_replace('\\', '/', $real === false ? $path : $real);
}

/** The game client of a folder with .build.info: version, build, product and installed locales. */
function setup_client(string $folder): ?array
{
    $folder = rtrim(str_replace('\\', '/', $folder), '/');
    $lines = @file($folder . '/.build.info', FILE_IGNORE_NEW_LINES);
    if (!$lines || count($lines) < 2) return null;
    $head = array_map(fn($h) => explode('!', $h)[0], explode('|', $lines[0]));
    $best = null;
    foreach (array_slice($lines, 1) as $line) {
        if (trim($line) === '') continue;
        $row = array_combine($head, array_pad(explode('|', $line), count($head), ''));
        if ($best === null || ($row['Active'] ?? '') === '1') $best = $row;
    }
    if (!$best) return null;
    $version = (string)($best['Version'] ?? '');
    preg_match_all('/\b([a-z]{2}[A-Z]{2}) text\?/', (string)($best['Tags'] ?? ''), $m);
    $flavors = [];
    foreach (@scandir($folder) ?: [] as $name) {
        if ($name !== '' && $name[0] === '_' && is_dir("$folder/$name")) {
            foreach (['Wow.exe', 'WowB.exe', 'WowClassic.exe', 'WowClassicB.exe', 'WowT.exe'] as $exe) if (is_file("$folder/$name/$exe")) { $flavors[] = $name; break; }
        }
    }
    return ['folder' => $folder, 'version' => $version, 'build' => (int)substr(strrchr($version, '.') ?: '.0', 1), 'product' => (string)($best['Product'] ?? ''),
            'locales' => array_values(array_unique($m[1])), 'flavors' => $flavors];
}

function setup_command(array $candidates): string
{
    foreach ($candidates as $command) {
        $out = @shell_exec($command . ' --version 2>&1');
        if (is_string($out) && preg_match('/Python 3\.(\d+)/', $out, $m) && (int)$m[1] >= 9) return trim(explode(' ', $command)[0], '"');
    }
    return '';
}

function setup_checks(): array
{
    $python = setup_command(PHP_OS_FAMILY === 'Windows' ? ['python', 'py -3', 'python3'] : ['python3', 'python']);
    return [
        ['php', version_compare(PHP_VERSION, '8.0', '>='), 'PHP ' . PHP_VERSION, t('PHP 8.0 or newer runs the website.', 'PHP 8.0 ou plus récent fait tourner le site.')],
        ['mysqli', extension_loaded('mysqli'), 'mysqli', t('Required: the website reads the databases with it.', 'Requis : le site lit les bases avec.')],
        ['gmp', extension_loaded('gmp'), 'gmp', t('Optional: lets game masters log in with their game account.', 'Optionnel : permet aux MJ de se connecter avec leur compte de jeu.')],
        ['python', $python !== '', $python !== '' ? 'Python 3 (' . $python . ')' : 'Python 3', t('Required for the analysis and the locator (python.org, "Add to PATH").', 'Requis pour l’analyse et le localisateur (python.org, « Add to PATH »).'), $python],
        ['writable', is_writable(FT_ROOT) && is_writable(FT_ROOT . '/data'), t('Folder writable', 'Dossier modifiable'), t('config.ini and data/ are written here.', 'config.ini et data/ sont écrits ici.')],
    ];
}

// ------------------------------------------------------------------ configuration file
/** Writes config.ini from config.sample.ini (or the current config.ini), changing only the given keys. */
function setup_write_config(array $values): void
{
    $path = config_path();
    $text = (string)file_get_contents(is_file($path) ? $path : FT_ROOT . '/config.sample.ini');
    foreach ($values as $section => $keys) {
        foreach ($keys as $key => $value) {
            if (is_string($value) && (str_contains($value, '"') || preg_match('/[\r\n]/', $value))) {
                throw new RuntimeException(sprintf(t('%s.%s cannot contain a double quote or a line break.', '%s.%s ne peut pas contenir de guillemet double ni de saut de ligne.'), $section, $key));
            }
            $line = $key . ' = ' . (is_int($value) ? (string)$value : '"' . $value . '"');
            if (!preg_match('/^\[' . preg_quote($section, '/') . '\]\s*$/m', $text)) $text = rtrim($text) . "\n\n[$section]\n";
            // the key inside its section: from the section header to the next one
            $text = preg_replace_callback('/(^\[' . preg_quote($section, '/') . '\][^\n]*\n)(.*?)(?=^\[|\z)/ms', function ($m) use ($key, $line) {
                $body = $m[2];
                $pattern = '/^' . preg_quote($key, '/') . '\s*=.*$/m';
                $body = preg_match($pattern, $body) ? preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $body, 1) : rtrim($body, "\n") . "\n" . $line . "\n\n";
                return $m[1] . $body;
            }, $text, 1);
        }
    }
    if (file_put_contents($path, $text) === false) throw new RuntimeException(sprintf(t('Cannot write %s.', 'Impossible d’écrire %s.'), $path));
}

// ------------------------------------------------------------------ background setup run
function setup_paths(): array { return [FT_ROOT . '/data/setup.log', FT_ROOT . '/data/setup.state']; }

function setup_status(): array
{
    [$log, $state] = setup_paths();
    $text = is_file($log) ? (string)file_get_contents($log) : '';
    $done = str_contains($text, 'FT-SETUP-DONE');
    $failed = str_contains($text, 'FT-SETUP-FAILED');
    $started = is_file($state) ? (int)file_get_contents($state) : 0;
    $steps = [];
    foreach (['extract' => 'FT-STEP extract', 'snapshot' => 'FT-STEP snapshot', 'analyse' => 'FT-STEP analyse', 'locate' => 'FT-STEP locate'] as $step => $marker) $steps[$step] = str_contains($text, $marker);
    $clean = trim(preg_replace('/^FT-(STEP|SETUP)[^\n]*\n?/m', '', $text));
    return ['running' => $started && !$done && !$failed && $started > time() - 7200, 'done' => $done, 'failed' => $failed, 'steps' => $steps,
            'log' => implode("\n", array_slice(preg_split('/\R/', $clean), -18))];
}

/** Runs the first-time pipeline in the background: [extract the client DB2,] snapshot, analyse, locate. */
function setup_start(array $plan): array
{
    if (setup_status()['running']) return ['ok' => false, 'message' => t('The setup is already running.', 'L’installation est déjà en cours.')];
    [$log, $state] = setup_paths();
    $python = (string)config('tools', 'python', PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    $tracker = escapeshellarg($python) . ' -X utf8 -u ' . escapeshellarg(FT_ROOT . '/tools/tracker.py');
    $windows = PHP_OS_FAMILY === 'Windows';
    $echo = fn(string $text) => 'echo ' . $text;
    $steps = [];
    $source = (string)$plan['source'];
    if (!empty($plan['extract'])) {
        $out = FT_ROOT . '/data/extract';
        $steps[] = $echo('FT-STEP extract');
        foreach ($plan['locales'] as $locale) {
            $cd = ($windows ? 'cd /d ' : 'cd ') . escapeshellarg(dirname($plan['extractor']));
            $steps[] = $cd . ' && ' . escapeshellarg($plan['extractor']) . ' -i ' . escapeshellarg($plan['client']) . ' -o ' . escapeshellarg($out) . ' -e 2 -l ' . $locale
                . ($plan['product'] !== '' ? ' -p ' . escapeshellarg($plan['product']) : '');
        }
        $source = $out . '/dbc';
    }
    $steps[] = $echo('FT-STEP snapshot');
    $steps[] = $tracker . ' snapshot --dbc ' . escapeshellarg($source) . ' --build ' . (int)$plan['build'] . ' --locale ' . escapeshellarg($plan['locales'][0] ?? 'enUS');
    $steps[] = $echo('FT-STEP analyse');
    $steps[] = $tracker . ' analyse';
    if (!empty($plan['locate'])) { $steps[] = $echo('FT-STEP locate'); $steps[] = $tracker . ' locate'; }
    $chain = '(' . implode(' && ', $steps) . ' && ' . $echo('FT-SETUP-DONE') . ') || ' . $echo('FT-SETUP-FAILED');
    file_put_contents($state, (string)time());
    file_put_contents($log, '');
    if ($windows) {
        file_put_contents(FT_ROOT . '/data/setup-run.cmd', "@echo off\r\ncd /d " . escapeshellarg(FT_ROOT) . "\r\n(" . $chain . ') >> ' . escapeshellarg($log) . " 2>&1\r\n");
        pclose(popen('start "" /B cmd /C ' . escapeshellarg(FT_ROOT . '/data/setup-run.cmd'), 'r'));
    } else {
        exec('cd ' . escapeshellarg(FT_ROOT) . ' && (' . $chain . ') >> ' . escapeshellarg($log) . ' 2>&1 &');
    }
    return ['ok' => true];
}

// ------------------------------------------------------------------ API
function setup_api(): void
{
    $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) json_out(['error' => t('Session expired, reload the page.', 'Session expirée, rechargez la page.')], 400);
    @set_time_limit(120);
    switch ((string)($input['action'] ?? '')) {
        case 'detect':
            [$servers, $clients, $partial] = setup_scan();
            json_out(['ok' => true, 'checks' => setup_checks(), 'servers' => array_values(array_filter(array_map('setup_server', $servers))),
                      'clients' => array_values(array_filter(array_map('setup_client', $clients))), 'partial' => $partial]);
        case 'server':
            $server = setup_server((string)($input['folder'] ?? ''));
            json_out($server ? ['ok' => true, 'server' => $server] : ['ok' => false, 'message' => t('No worldserver.conf in this folder.', 'Aucun worldserver.conf dans ce dossier.')]);
        case 'client':
            $folder = (string)($input['folder'] ?? '');
            $client = setup_client($folder) ?? setup_client(dirname($folder));
            json_out($client ? ['ok' => true, 'client' => $client] : ['ok' => false, 'message' => t('No .build.info here: choose the game\'s main folder.', 'Aucun .build.info ici : choisissez le dossier principal du jeu.')]);
        case 'database':
            $d = $input['db'] ?? [];
            mysqli_report(MYSQLI_REPORT_OFF);
            $link = @new mysqli((string)($d['host'] ?? '127.0.0.1'), (string)($d['user'] ?? ''), (string)($d['password'] ?? ''), '', (int)($d['port'] ?? 3306));
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            if ($link->connect_errno) json_out(['ok' => false, 'message' => sprintf(t('Connection refused: %s', 'Connexion refusée : %s'), $link->connect_error)]);
            $databases = array_column($link->query('SHOW DATABASES')->fetch_all(MYSQLI_ASSOC), 'Database');
            $has = function (string $db, string $table) use ($link, $databases): bool {
                if (!in_array($db, $databases, true)) return false;
                $r = $link->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema = '" . $link->real_escape_string($db) . "' AND table_name = '" . $link->real_escape_string($table) . "'");
                return (int)$r->fetch_assoc()['n'] > 0;
            };
            $names = $d['names'] ?? [];
            $checks = ['world' => $has((string)($names['world'] ?? ''), 'creature_template'), 'hotfixes' => $has((string)($names['hotfixes'] ?? ''), 'spell_name'),
                       'auth' => $has((string)($names['auth'] ?? ''), 'realmlist')];
            $build = 0;
            if ($checks['auth']) $build = (int)($link->query('SELECT MAX(gamebuild) b FROM `' . str_replace('`', '', (string)$names['auth']) . '`.realmlist')->fetch_assoc()['b'] ?? 0);
            $tracker = (string)($names['tracker'] ?? 'forever_tracker');
            // what this user may do: create databases (global CREATE), and which existing ones it can write to
            $global = false; $writable = [];
            foreach (array_map(fn($r) => (string)reset($r), $link->query('SHOW GRANTS')->fetch_all(MYSQLI_ASSOC)) as $grant) {
                if (preg_match('/^GRANT (.+) ON \*\.\* TO/', $grant, $m) && (str_contains($m[1], 'ALL PRIVILEGES') || preg_match('/\bCREATE\b/', $m[1]))) $global = true;
                if (preg_match('/^GRANT (ALL PRIVILEGES|.*\bCREATE\b.*) ON `([^`]+)`\.\*/', $grant, $m)) $writable[] = str_replace('\\_', '_', $m[2]);
            }
            $game = array_map('strval', array_filter([$names['auth'] ?? '', $names['world'] ?? '', $names['hotfixes'] ?? '']));
            $suggest = array_values(array_filter($writable, fn($db) => in_array($db, $databases, true) && !in_array($db, $game, true) && !preg_match('/^(characters|playerbot)/i', $db)));
            $exists = in_array($tracker, $databases, true);
            json_out(['ok' => true, 'databases' => $databases, 'checks' => $checks, 'build' => $build, 'tracker_exists' => $exists,
                      'can_create' => $global || in_array($tracker, $writable, true), 'suggest' => $suggest, 'user' => (string)($d['user'] ?? ''), 'version' => $link->server_info]);
        case 'save':
            $c = $input['config'] ?? [];
            $admin = trim((string)($c['admin_name'] ?? ''));
            $password = (string)($c['admin_password'] ?? '');
            if (!is_file(config_path()) && ($admin === '' || strlen($password) < 10)) json_out(['ok' => false, 'message' => t('Choose an administrator name and a password of at least 10 characters.', 'Choisissez un nom d’administrateur et un mot de passe d’au moins 10 caractères.')]);
            if ($admin !== '' && !preg_match('/^[A-Za-z0-9_.-]{2,32}$/', $admin)) json_out(['ok' => false, 'message' => t('Administrator name: 2 to 32 letters, digits, dots, dashes or underscores.', 'Nom d’administrateur : 2 à 32 lettres, chiffres, points, tirets ou soulignés.')]);
            $db = $c['db'] ?? [];
            // the tracker's own database: created when missing
            mysqli_report(MYSQLI_REPORT_OFF);
            $link = @new mysqli((string)$db['host'], (string)$db['user'], (string)$db['password'], '', (int)$db['port']);
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            if ($link->connect_errno) json_out(['ok' => false, 'message' => $link->connect_error]);
            $tracker = preg_replace('/[^A-Za-z0-9_]/', '', (string)($db['tracker'] ?? 'forever_tracker')) ?: 'forever_tracker';
            try { $link->query('CREATE DATABASE IF NOT EXISTS `' . $tracker . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); }
            catch (mysqli_sql_exception $e) { json_out(['ok' => false, 'message' => sprintf(t('Cannot create the database %s: %s. Create it yourself or use an existing one.', 'Impossible de créer la base %s : %s. Créez-la vous-même ou utilisez une base existante.'), $tracker, $e->getMessage())]); }
            $values = [
                'site' => ['default_language' => in_array($c['language'] ?? '', ['en', 'fr'], true) ? $c['language'] : 'en'],
                'tools' => ['python' => (string)($c['python'] ?? 'python')],
                'database' => ['host' => (string)$db['host'], 'port' => (int)$db['port'], 'user' => (string)$db['user'], 'password' => (string)$db['password'], 'tracker' => $tracker,
                               'world' => (string)$db['world'], 'hotfixes' => (string)$db['hotfixes'], 'auth' => (string)$db['auth']],
                'server' => ['root' => (string)($c['server_folder'] ?? ''), 'dbc' => (string)($c['dbc'] ?? ''), 'maps' => (string)($c['maps'] ?? ''), 'vmaps' => (string)($c['vmaps'] ?? ''),
                             'mmaps' => (string)($c['mmaps'] ?? ''), 'build' => (int)($c['build'] ?? 0), 'build_file' => '', 'mysql' => (string)($c['mysql'] ?? '')],
                'console' => ['host' => (string)($c['ra_host'] ?? '127.0.0.1'), 'port' => (int)($c['ra_port'] ?? 3443), 'user' => (string)($c['ra_user'] ?? ''), 'password' => (string)($c['ra_password'] ?? '')],
                'client' => ['path' => (string)($c['client'] ?? '')],
            ];
            if ($admin !== '' && $password !== '') $values['admins'] = [$admin => password_hash($password, PASSWORD_DEFAULT)];
            try { setup_write_config($values); } catch (RuntimeException $e) { json_out(['ok' => false, 'message' => $e->getMessage()]); }
            if ($admin !== '' && $password !== '') $_SESSION['ft_admin'] = ['name' => $admin, 'via' => 'local'];
            json_out(['ok' => true, 'csrf' => csrf_token()]);
        case 'run':
            if (!is_file(config_path())) json_out(['ok' => false, 'message' => t('Save the configuration first.', 'Enregistrez d’abord la configuration.')]);
            $p = $input['plan'] ?? [];
            $plan = ['extract' => !empty($p['extract']), 'extractor' => (string)($p['extractor'] ?? ''), 'client' => (string)($p['client'] ?? ''), 'product' => (string)($p['product'] ?? ''),
                     'locales' => array_values(array_filter((array)($p['locales'] ?? ['enUS']), fn($l) => preg_match('/^[a-z]{2}[A-Z]{2}$/', (string)$l))) ?: ['enUS'],
                     'source' => (string)($p['source'] ?? ''), 'build' => (int)($p['build'] ?? 0), 'locate' => !empty($p['locate'])];
            if ($plan['extract'] && (!is_file($plan['extractor']) || !is_dir($plan['client']))) json_out(['ok' => false, 'message' => t('mapextractor or the game client was not found.', 'mapextractor ou le client du jeu est introuvable.')]);
            if (!$plan['extract'] && !is_dir($plan['source'])) json_out(['ok' => false, 'message' => t('The DB2 folder to store was not found.', 'Le dossier DB2 à enregistrer est introuvable.')]);
            if ($plan['build'] < 1) json_out(['ok' => false, 'message' => t('Unknown client build.', 'Build du client inconnu.')]);
            json_out(setup_start($plan));
        case 'status':
            json_out(['ok' => true] + setup_status());
    }
    json_out(['error' => 'unknown action'], 400);
}
