<?php
// Spawns: the creatures and world objects found by the locator (ft_spawns), placed on the server in one of three ways:
//   server    the worldserver's remote console (RA) runs ".forge spawn" (ForeverTracker core module): exact height, live, undoable
//   database  rows written in the world database with the height of the nearest existing spawn (worldserver stopped)
//   addon     the ForeverForge in-game addon walks a GM through the list with standard GM commands
require_once FT_APP . '/forge.php';

const SPAWN_ROLES = ['starter', 'ender', 'objective', 'vendor', 'trainer', 'loot', 'boss'];

function spawn_role_label(string $role): string
{
    return [
        'starter' => t('Quest giver', 'Donneur de quête'), 'ender' => t('Quest ender', 'Valideur de quête'), 'objective' => t('Quest objective', 'Objectif de quête'),
        'vendor' => t('Vendor', 'Marchand'), 'trainer' => t('Trainer', 'Entraîneur'), 'loot' => t('Drops a missing item', 'Butin manquant'), 'boss' => t('Boss', 'Boss'),
    ][$role] ?? $role;
}

function spawn_state_label(string $state): string
{
    return ['ready' => t('Ready', 'Prêt'), 'manual' => t('Place in game', 'À placer en jeu'), 'unknown' => t('No position', 'Sans position'),
            'pending' => t('Not looked up', 'Non recherché'), 'spawned' => t('Spawned', 'Placé')][$state] ?? $state;
}

// ------------------------------------------------------------------ remote console (RA)
final class ForgeConsole
{
    /** @var resource|null */
    private $socket = null;
    public string $error = '';

    public static function configured(): bool
    {
        return (string)config('console', 'user', '') !== '' && (string)config('console', 'password', '') !== '';
    }

    public function open(): bool
    {
        $host = (string)config('console', 'host', '127.0.0.1');
        $port = (int)config('console', 'port', 3443);
        $socket = @stream_socket_client("tcp://$host:$port", $code, $message, 5);
        if (!$socket) { $this->error = sprintf(t('Cannot reach the remote console at %s:%d (%s). Is the worldserver running with Ra.Enable = 1?', 'Console distante injoignable sur %s:%d (%s). Le worldserver tourne-t-il avec Ra.Enable = 1 ?'), $host, $port, $message); return false; }
        stream_set_timeout($socket, 15);
        $this->socket = $socket;
        if (!$this->until('Username:')) return $this->fail(t('The remote console did not ask for a user name.', 'La console distante n’a pas demandé d’utilisateur.'));
        fwrite($socket, (string)config('console', 'user', '') . "\r\n");
        if (!$this->until('Password:')) return $this->fail(t('The remote console did not ask for a password.', 'La console distante n’a pas demandé de mot de passe.'));
        fwrite($socket, (string)config('console', 'password', '') . "\r\n");
        $answer = $this->until('TC>');
        if ($answer === null) return $this->fail(t('Remote console login refused: check [console] user and password (a game account with GM level 3 on all realms, RealmID -1).', 'Connexion à la console refusée : vérifiez [console] user et password (un compte de jeu de niveau MJ 3 sur tous les royaumes, RealmID -1).'));
        return true;
    }

    private function fail(string $message): bool
    {
        $this->error = $message;
        $this->close();
        return false;
    }

    /** Reads until a marker; returns what was read, or null on timeout / closed connection / failed login. */
    private function until(string $marker): ?string
    {
        $buffer = '';
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $chunk = fread($this->socket, 8192);
            if ($chunk === false || ($chunk === '' && feof($this->socket))) return null;
            $buffer .= $chunk;
            if (str_contains($buffer, 'Authentication failed')) return null;
            if (str_contains($buffer, $marker)) return $buffer;
            if ($chunk === '') usleep(20000);
        }
        return null;
    }

    /** Runs one command and returns its output (without the prompt). */
    public function run(string $command): ?string
    {
        if (!$this->socket) return null;
        fwrite($this->socket, ltrim($command, '.') . "\r\n");
        $out = $this->until('TC>');
        return $out === null ? null : trim(str_replace('TC>', '', $out));
    }

    public function close(): void
    {
        if ($this->socket) { @fwrite($this->socket, "quit\r\n"); @fclose($this->socket); }
        $this->socket = null;
    }
}

/** [ok, message, version]: whether the console answers and has the ForeverTracker core module. */
function spawn_console_status(): array
{
    if (!ForgeConsole::configured()) return [false, t('The remote console is not configured ([console] in config.ini).', 'La console distante n’est pas configurée ([console] dans config.ini).'), 0];
    $console = new ForgeConsole();
    if (!$console->open()) return [false, $console->error, 0];
    $answer = (string)$console->run('forge version');
    $console->close();
    if (preg_match('/FORGE OK version (\d+)/', $answer, $m)) return [true, sprintf(t('Connected · ForeverTracker core module v%d', 'Connecté · module ForeverTracker du core v%d'), (int)$m[1]), (int)$m[1]];
    return [false, t('Connected, but the core has no .forge command: build the core with the ForeverTracker module (see docs/SPAWNS.md).', 'Connecté, mais le core n’a pas la commande .forge : compilez le core avec le module ForeverTracker (voir docs/SPAWNS.md).'), 0];
}

// ------------------------------------------------------------------ plans
/**
 * The spawn points of a selection [{kind, entry}, …] as they stand now: entries spawned since the locator ran are left out.
 * Returns ['points' => [[kind, entry, name, map, x, y, zHint|null, hintDistance|null, orientation, wander]], 'manual' => [...], 'skipped' => n, 'warnings' => [...]].
 */
function spawn_plan(array $selection): array
{
    $wanted = [];
    foreach (array_slice($selection, 0, 3000) as $pick) {
        $kind = ($pick['kind'] ?? '') === 'go' ? 'go' : 'npc';
        $entry = (int)($pick['entry'] ?? 0);
        if ($entry > 0) $wanted[$kind][$entry] = true;
    }
    $points = []; $manual = []; $skipped = 0; $warnings = [];
    foreach ($wanted as $kind => $entries) {
        $ids = implode(',', array_keys($entries));
        $spawned = array_flip(array_map('intval', array_column(rows('SELECT DISTINCT id FROM ' . ($kind === 'npc' ? 'creature' : 'gameobject') . " WHERE id IN ($ids)", [], 'world'), 'id')));
        foreach (rows("SELECT kind, entry, name, state, map, points, wander FROM ft_spawns WHERE kind = ? AND entry IN ($ids)", [$kind]) as $s) {
            $entry = (int)$s['entry'];
            if (isset($spawned[$entry])) { $skipped++; continue; }
            if ($s['state'] === 'manual') { $manual[] = ['kind' => $kind, 'entry' => $entry, 'name' => $s['name'], 'map' => (int)$s['map']]; continue; }
            if ($s['state'] !== 'ready') continue;
            foreach (json_decode((string)$s['points'], true) ?: [] as [$x, $y, $z, $dist, $o]) {
                $points[] = [$kind, $entry, $s['name'], (int)$s['map'], (float)$x, (float)$y, $z === null ? null : (float)$z, $dist === null ? null : (float)$dist, (float)$o, (float)$s['wander']];
            }
        }
    }
    if ($skipped) $warnings[] = sprintf(t('%d selected creature(s) or object(s) are spawned already and were left out.', '%d PNJ ou objet(s) choisis sont déjà placés et ont été écartés.'), $skipped);
    return ['points' => $points, 'manual' => $manual, 'skipped' => $skipped, 'warnings' => $warnings];
}

function spawn_number(float $value): string { return rtrim(rtrim(sprintf('%.2f', $value), '0'), '.') ?: '0'; }

/** The .forge command of a point (core module). */
function spawn_forge_command(array $p): string
{
    [$kind, $entry, , $map, $x, $y, $z, , $o, $wander] = $p;
    $command = 'forge spawn ' . $kind . ' ' . $entry . ' ' . $map . ' ' . spawn_number($x) . ' ' . spawn_number($y);
    $command .= ' ' . ($z === null ? '' : spawn_number($z));
    if ($z === null) $command = rtrim($command);
    return $z === null ? $command : $command . ' ' . spawn_number($o) . ($kind === 'npc' && $wander > 0 ? ' ' . spawn_number($wander) : '');
}

// ------------------------------------------------------------------ mode: server (remote console + core module)
function spawn_apply_server(array $selection): array
{
    forge_schema();
    $plan = spawn_plan($selection);
    if (!$plan['points']) return ['ok' => false, 'message' => $plan['warnings'] ? implode(' ', $plan['warnings']) : t('Nothing to spawn: the selection has no known position.', 'Rien à placer : la sélection n’a aucune position connue.')];
    $console = new ForgeConsole();
    if (!$console->open()) return ['ok' => false, 'message' => $console->error];
    $done = []; $undo = []; $errors = [];
    @set_time_limit(0);
    foreach ($plan['points'] as $p) {
        $answer = (string)$console->run(spawn_forge_command($p));
        if (preg_match('/FORGE OK (npc|go) (\d+)/', $answer, $m)) {
            $done[] = '.' . spawn_forge_command($p) . '   -> guid ' . $m[2];
            $undo[] = 'forge delete ' . $m[1] . ' ' . $m[2];
        } else {
            $errors[] = $p[2] . ' (' . $p[1] . '): ' . (preg_match('/FORGE ERR (.*)/', $answer, $e) ? trim($e[1]) : ($answer === '' ? t('no answer', 'pas de réponse') : mb_strimwidth($answer, 0, 120, '…')));
            if (count($errors) >= 25 && !$done) break;  // the module is missing or the console misbehaves: stop early
        }
    }
    $console->close();
    $items = array_values(array_unique(array_map(fn($p) => $p[0] . ':' . $p[1], $plan['points'])));
    if ($done) {
        rows('INSERT INTO ft_forge_log (admin, kind, items, rows_count, sql_apply, sql_rollback, state, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            (string)admin()['name'], 'spawn', json_encode(array_map(fn($k) => ['type' => 'spawn', 'id' => (int)explode(':', $k)[1], 'kind' => explode(':', $k)[0]], $items)), count($done),
            json_encode(['console' => implode("\n", $done)], JSON_UNESCAPED_UNICODE), json_encode(['console' => implode("\n", array_reverse($undo))], JSON_UNESCAPED_UNICODE),
            'applied', $errors ? implode(' · ', array_slice($errors, 0, 10)) : implode(' ', $plan['warnings']),
        ]);
    }
    return ['ok' => (bool)$done, 'log' => $done ? (int)db()->insert_id : 0, 'spawned' => count($done), 'failed' => count($errors), 'errors' => array_slice($errors, 0, 10),
            'manual' => count($plan['manual']), 'warnings' => $plan['warnings'], 'message' => $done ? '' : implode(' · ', array_slice($errors, 0, 3))];
}

/** Rolls back console spawns: ".forge delete" for each spawn created. */
function spawn_rollback_console(string $script): array
{
    $console = new ForgeConsole();
    if (!$console->open()) return [false, $console->error];
    $failed = [];
    foreach (preg_split('/\R/', trim($script)) as $command) {
        if (trim($command) === '') continue;
        $answer = (string)$console->run(trim($command));
        // a spawn deleted by hand since is fine: it is gone either way
        if (!str_contains($answer, 'FORGE OK') && !str_contains($answer, 'no creature spawn') && !str_contains($answer, 'no gameobject spawn')) $failed[] = $command . ': ' . mb_strimwidth($answer, 0, 80, '…');
    }
    $console->close();
    return [!$failed, implode(' · ', array_slice($failed, 0, 5))];
}

// ------------------------------------------------------------------ mode: database
/** Forge operations for the points that have a height hint (the worldserver must be stopped: it hands out spawn ids itself). */
function spawn_db_ops(array $plan, int $maxHintDistance = 15): array
{
    $ops = []; $left = 0;
    $guid = ['npc' => (int)value('SELECT COALESCE(MAX(guid), 0) FROM creature', [], 'world'), 'go' => (int)value('SELECT COALESCE(MAX(guid), 0) FROM gameobject', [], 'world')];
    $difficulties = [];
    $difficulty = function (int $map) use (&$difficulties): string {
        return $difficulties[$map] ??= (string)(value('SELECT spawnDifficulties FROM creature WHERE map = ? GROUP BY spawnDifficulties ORDER BY COUNT(*) DESC LIMIT 1', [$map], 'world') ?? '0');
    };
    $npc = []; $go = [];
    foreach ($plan['points'] as [$kind, $entry, , $map, $x, $y, $z, $dist, $o, $wander]) {
        if ($z === null || $dist === null || $dist > $maxHintDistance) { $left++; continue; }
        if ($kind === 'npc') {
            $npc[] = [++$guid['npc'], $entry, $map, $difficulty($map), round($x, 2), round($y, 2), round($z + 0.1, 2), round($o, 3), 120, $wander, $wander > 0 ? 1 : 0, 0];
        } else {
            $half = $o / 2;
            $go[] = [++$guid['go'], $entry, $map, $difficulty($map), round($x, 2), round($y, 2), round($z, 2), round($o, 3), 0, 0, round(sin($half), 6), round(cos($half), 6), 300, 255, 1, 0];
        }
    }
    if ($npc) $ops[] = ['db' => 'world', 'table' => 'creature', 'match' => ['guid'], 'rows' => $npc,
        'columns' => ['guid', 'id', 'map', 'spawnDifficulties', 'position_x', 'position_y', 'position_z', 'orientation', 'spawntimesecs', 'wander_distance', 'MovementType', 'VerifiedBuild']];
    if ($go) $ops[] = ['db' => 'world', 'table' => 'gameobject', 'match' => ['guid'], 'rows' => $go,
        'columns' => ['guid', 'id', 'map', 'spawnDifficulties', 'position_x', 'position_y', 'position_z', 'orientation', 'rotation0', 'rotation1', 'rotation2', 'rotation3', 'spawntimesecs', 'animprogress', 'state', 'VerifiedBuild']];
    return [$ops, $left];
}

/** True when the worldserver answers on its remote console port (it then owns the spawn ids). */
function spawn_world_running(): bool
{
    $socket = @fsockopen((string)config('console', 'host', '127.0.0.1'), (int)config('console', 'port', 3443), $code, $message, 1.5);
    if (!$socket) return false;
    fclose($socket);
    return true;
}

/** The database mode as a Forge plan: [built, left] where left counts the points without a close height hint. */
function spawn_build_database(array $selection): array
{
    $plan = spawn_plan($selection);
    [$ops, $left] = spawn_db_ops($plan);
    $merged = [];
    foreach ($ops as $op) forge_merge($merged, $op);
    $warnings = $plan['warnings'];
    if ($left) $warnings[] = sprintf(t('%d point(s) have no existing spawn close enough to borrow a height from: use the server or addon mode for them.', '%d point(s) n’ont aucun placement assez proche pour en reprendre la hauteur : utilisez le mode serveur ou l’addon pour eux.'), $left);
    if ($plan['manual']) $warnings[] = sprintf(t('%d boss(es) or dungeon creature(s) need the addon (Spawn here).', '%d boss ou PNJ de donjon nécessitent l’addon (Placer ici).'), count($plan['manual']));
    $built = forge_plan($merged, [], $warnings);
    $built['items'] = array_values(array_map(fn($k) => ['type' => 'spawn', 'id' => (int)explode(':', $k)[1], 'kind' => explode(':', $k)[0]],
        array_unique(array_map(fn($p) => $p[0] . ':' . $p[1], $plan['points']))));
    return [$built, $left];
}

function spawn_apply_database(array $selection): array
{
    forge_schema();
    if (spawn_world_running()) return ['ok' => false, 'message' => t('The worldserver is running: it hands out spawn ids itself, so writing spawns directly could collide with its own. Stop it first, or use the server mode.', 'Le worldserver tourne : il attribue lui-même les identifiants de placement, une écriture directe pourrait entrer en conflit. Arrêtez-le d’abord, ou utilisez le mode serveur.')];
    [$built] = spawn_build_database($selection);
    return forge_commit($built, 'spawn');
}

// ------------------------------------------------------------------ mode: addon / GM commands
/** Standard GM commands (any TrinityCore): go there, then spawn where the GM stands. */
function spawn_gm_commands(array $plan): string
{
    $out = ['-- ForeverTracker · spawns · ' . date('Y-m-d H:i') . ' · ' . count($plan['points']) . ' point(s)'];
    foreach ($plan['points'] as [$kind, $entry, $name, $map, $x, $y, $z, , $o]) {
        $out[] = '-- ' . $name . ' (' . $entry . ')';
        // ".go xyz x y z map": without a z the map would be read as the height, so a high z is given and the server drops the GM on the ground
        $out[] = '.go xyz ' . spawn_number($x) . ' ' . spawn_number($y) . ' ' . ($z === null ? '500' : spawn_number($z + 1.5)) . ' ' . $map . ' ' . spawn_number($o);
        if ($z === null) $out[] = '.go xyz ' . spawn_number($x) . ' ' . spawn_number($y);
        $out[] = $kind === 'npc' ? '.npc add ' . $entry : '.gobject add ' . $entry;
    }
    return implode("\n", $out) . "\n";
}

function spawn_lua_string(string $value): string { return '"' . addcslashes($value, "\\\"\n\r") . '"'; }

/** The queue file of the ForeverForge addon (Queue.lua). */
function spawn_addon_queue(array $plan): string
{
    $lines = ['-- ForeverTracker - spawn queue - ' . date('Y-m-d H:i') . ' - generated by the website, replaced at each export', 'ForeverForgeQueue = {', '  generated = ' . spawn_lua_string(date('c')) . ',', '  points = {'];
    foreach ($plan['points'] as [$kind, $entry, $name, $map, $x, $y, $z, , $o, $wander]) {
        $lines[] = sprintf('    { k = "%s", e = %d, n = %s, m = %d, x = %s, y = %s, z = %s, o = %s, w = %s },', $kind, $entry, spawn_lua_string($name), $map,
            spawn_number($x), spawn_number($y), $z === null ? 'nil' : spawn_number($z), spawn_number($o), spawn_number($wander));
    }
    $lines[] = '  },';
    $lines[] = '  manual = {';
    foreach ($plan['manual'] as $m) $lines[] = sprintf('    { k = "%s", e = %d, n = %s, m = %d },', $m['kind'], $m['entry'], spawn_lua_string($m['name']), $m['map']);
    $lines[] = '  },';
    $lines[] = '}';
    return implode("\n", $lines) . "\n";
}

/** The addon folder: the files shipped in addon/ForeverForge plus the queue. */
function spawn_addon_files(array $plan): array
{
    $files = [];
    $dir = FT_ROOT . '/addon/ForeverForge';
    foreach (glob($dir . '/*.{toc,lua}', GLOB_BRACE) ?: [] as $path) {
        if (basename($path) !== 'Queue.lua') $files['ForeverForge/' . basename($path)] = (string)file_get_contents($path);
    }
    $files['ForeverForge/Queue.lua'] = spawn_addon_queue($plan);
    return $files;
}

/** A .zip archive of text files (stored, not compressed: PHP's zip extension is often missing). */
function spawn_zip(array $files): string
{
    $data = ''; $directory = ''; $count = 0;
    [$time, $date] = (function () {
        $t = getdate();
        return [($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2), (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday']];
    })();
    foreach ($files as $name => $content) {
        $crc = crc32($content); $size = strlen($content); $offset = strlen($data);
        $data .= pack('VvvvvvVVVvv', 0x04034b50, 10, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0) . $name . $content;
        $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 10, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $count++;
    }
    return $data . $directory . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($directory), strlen($data), 0);
}

/** The game client's AddOns folder from [client] path, or ''. */
function spawn_client_addons(): string
{
    $root = rtrim(str_replace('\\', '/', (string)config('client', 'path', '')), '/');
    if ($root === '' || !is_dir($root)) return '';
    foreach (['', '/_classic_beta_', '/_classic_era_', '/_classic_', '/_retail_'] as $flavor) {
        if (is_file($root . $flavor . '/Wow.exe') || is_file($root . $flavor . '/WowClassic.exe') || is_file($root . $flavor . '/WowB.exe') || is_file($root . $flavor . '/WowClassicB.exe')) {
            return $root . $flavor . '/Interface/AddOns';
        }
    }
    return '';
}
