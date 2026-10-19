<?php
// The Forge: turns selected fixes into SQL, applies them in transactions and keeps a history with rollbacks.

const FORGE_KINDS = ['hotfix', 'trainer', 'instance', 'vendor'];

function forge_schema(): void
{
    db()->query("CREATE TABLE IF NOT EXISTS ft_forge_log (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        admin VARCHAR(190) NOT NULL, kind VARCHAR(64) NOT NULL, items JSON NOT NULL, rows_count INT NOT NULL DEFAULT 0, sql_apply JSON NOT NULL, sql_rollback JSON NOT NULL,
        state VARCHAR(16) NOT NULL, message TEXT, rolled_back_at DATETIME NULL, KEY ix_state (state)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function forge_kind_label(string $kind): string
{
    return [
        'hotfix' => t('Core data (hotfixes)', 'Données du core (hotfixes)'), 'trainer' => t('Trainers', 'Entraîneurs'),
        'vendor' => t('Vendors', 'Marchands'), 'instance' => t('Instances', 'Instances'),
    ][$kind] ?? $kind;
}

/** Splits generated SQL into statements (generated values never contain a raw line break). */
function forge_statements(string $sql): array
{
    return array_values(array_filter(array_map('trim', preg_split('/;\s*\R/', rtrim($sql) . "\n")), fn($s) => $s !== ''));
}

/**
 * Builds the SQL of a selection: [{type, id, kind}, …].
 * Returns ['plans' => [db => ['apply' => sql, 'rollback' => sql]], 'items' => [...], 'rows' => n, 'warnings' => [...]].
 */
function forge_build(array $selection, array $options = []): array
{
    $plans = []; $items = []; $rows = 0; $warnings = []; $pushes = []; $vendorItems = [];
    $add = function (string $database, string $apply, string $rollback) use (&$plans) {
        $plans[$database]['apply'][] = $apply;
        array_unshift($plans[$database]['rollback'], $rollback);
    };
    foreach (array_slice($selection, 0, 500) as $pick) {
        $type = (string)($pick['type'] ?? ''); $id = (int)($pick['id'] ?? 0); $kind = (string)($pick['kind'] ?? '');
        if (!isset(TYPES[$type]) || !$id || !in_array($kind, FORGE_KINDS, true)) continue;
        if ($kind === 'vendor') { $vendorItems[$id] = true; $items[] = ['type' => 'item', 'id' => $id, 'kind' => 'vendor']; continue; }
        $fix = row('SELECT * FROM ft_fixes WHERE type = ? AND id = ? AND kind = ?', [$type, $id, $kind]);
        if (!$fix) { $warnings[] = sprintf(t('No %s fix for %s %d (run the analysis again?).', 'Pas de correction %s pour %s %d (relancer l’analyse ?).'), $kind, $type, $id); continue; }
        $plans[$fix['db']] ??= ['apply' => [], 'rollback' => []];
        $add($fix['db'], $fix['sql_apply'], $fix['sql_rollback']);
        $rows += (int)$fix['rows_count'];
        $items[] = ['type' => $type, 'id' => $id, 'kind' => $kind];
        if ($kind === 'hotfix') foreach ((json_decode((string)$fix['detail'], true)['push'] ?? []) as $push) $pushes[] = $push;
    }
    if ($vendorItems) {
        $vendor = (int)($options['vendor'] ?? 0);
        $npc = $vendor ? row('SELECT entry, name, npcflag FROM creature_template WHERE entry = ?', [$vendor], 'world') : null;
        if (!$npc) {
            $warnings[] = t('Choose an existing vendor (creature_template entry).', 'Choisissez un marchand existant (entry de creature_template).');
        } else {
            if (!((int)$npc['npcflag'] & 128)) $warnings[] = sprintf(t('%s has no vendor flag (npcflag 128): the items will not show.', '%s n’a pas le drapeau marchand (npcflag 128) : les objets ne s’afficheront pas.'), $npc['name']);
            $ids = array_keys($vendorItems);
            $sold = array_map('intval', array_column(rows('SELECT item FROM npc_vendor WHERE entry = ? AND type = 1 AND item IN (' . implode(',', $ids) . ')', [$vendor], 'world'), 'item'));
            $ids = array_values(array_diff($ids, $sold));
            if ($sold) $warnings[] = sprintf(t('%d item(s) already sold by this vendor were skipped.', '%d objet(s) déjà vendus par ce marchand ont été ignorés.'), count($sold));
            if ($ids) {
                $slot = (int)value('SELECT COALESCE(MAX(slot), -1) FROM npc_vendor WHERE entry = ?', [$vendor], 'world');
                $values = [];
                foreach ($ids as $item) $values[] = '(' . $vendor . ',' . (++$slot) . ',' . $item . ',0,0,0,1,NULL,0,0,0)';
                $plans['world'] ??= ['apply' => [], 'rollback' => []];
                $add('world', "INSERT INTO `npc_vendor` (`entry`,`slot`,`item`,`maxcount`,`incrtime`,`ExtendedCost`,`type`,`BonusListIDs`,`PlayerConditionID`,`IgnoreFiltering`,`VerifiedBuild`) VALUES\n" . implode(",\n", $values) . ';',
                    'DELETE FROM `npc_vendor` WHERE entry = ' . $vendor . ' AND type = 1 AND ExtendedCost = 0 AND item IN (' . implode(',', $ids) . ');');
                $rows += count($ids);
            }
        }
    }
    if ($pushes && !empty($options['push'])) {
        $hotfixId = (int)value('SELECT COALESCE(MAX(Id), 0) + 1 FROM hotfix_data', [], 'hotfixes');
        $build = (int)(meta()['client_build'] ?? 0);
        $values = [];
        foreach ($pushes as [$hash, $record]) $values[] = '(' . $hotfixId . ',' . random_int(1, 2147483647) . ',' . (int)$hash . ',' . (int)$record . ',1,' . $build . ')';
        $plans['hotfixes'] ??= ['apply' => [], 'rollback' => []];
        $add('hotfixes', "INSERT INTO `hotfix_data` (`Id`,`UniqueId`,`TableHash`,`RecordId`,`Status`,`VerifiedBuild`) VALUES\n" . implode(",\n", $values) . ';',
            'DELETE FROM `hotfix_data` WHERE Id = ' . $hotfixId . ';');
        $rows += count($values);
    }
    foreach ($plans as $database => $plan) $plans[$database] = ['apply' => implode("\n", $plan['apply']), 'rollback' => implode("\n", $plan['rollback'])];
    return ['plans' => $plans, 'items' => $items, 'rows' => $rows, 'warnings' => $warnings];
}

/** The whole selection as one .sql script (with USE statements). */
function forge_script(array $plans, string $part = 'apply'): string
{
    $out = '-- Forever Tracker · Forge · ' . date('Y-m-d H:i') . ' · ' . ($part === 'apply' ? 'apply' : 'rollback') . "\n";
    foreach ($plans as $database => $plan) {
        $out .= "\nUSE `" . config('database', $database, $database) . "`;\nSTART TRANSACTION;\n" . $plan[$part] . "\nCOMMIT;\n";
    }
    return $out;
}

/** Runs every statement of every database in transactions; all or nothing. Returns [ok, message]. */
function forge_run(array $plans, string $part): array
{
    $started = [];
    try {
        foreach ($plans as $database => $plan) {
            $link = db($database);
            $link->begin_transaction();
            $started[] = $link;
            foreach (forge_statements($plan[$part]) as $statement) $link->query($statement);
        }
        foreach ($started as $link) $link->commit();
        return [true, ''];
    } catch (mysqli_sql_exception $e) {
        foreach ($started as $link) { try { $link->rollback(); } catch (Throwable $ignored) {} }
        return [false, $e->getMessage()];
    }
}

function forge_apply(array $selection, array $options): array
{
    forge_schema();
    $built = forge_build($selection, $options);
    if (!$built['plans']) return ['ok' => false, 'message' => $built['warnings'] ? implode(' ', $built['warnings']) : t('Nothing to apply.', 'Rien à appliquer.')];
    [$ok, $message] = forge_run($built['plans'], 'apply');
    $kinds = implode(',', array_unique(array_column($built['items'], 'kind')));
    $apply = array_map(fn($p) => $p['apply'], $built['plans']);
    $rollback = array_map(fn($p) => $p['rollback'], $built['plans']);
    rows('INSERT INTO ft_forge_log (admin, kind, items, rows_count, sql_apply, sql_rollback, state, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        (string)admin()['name'], $kinds, json_encode($built['items']), $built['rows'], json_encode($apply, JSON_UNESCAPED_UNICODE), json_encode($rollback, JSON_UNESCAPED_UNICODE),
        $ok ? 'applied' : 'failed', $ok ? implode(' ', $built['warnings']) : $message,
    ]);
    return ['ok' => $ok, 'log' => (int)db()->insert_id, 'rows' => $built['rows'], 'warnings' => $built['warnings'], 'message' => $message, 'items' => $built['items']];
}

function forge_rollback(int $logId): array
{
    forge_schema();
    $log = row('SELECT * FROM ft_forge_log WHERE id = ?', [$logId]);
    if (!$log || $log['state'] !== 'applied') return ['ok' => false, 'message' => t('This operation cannot be rolled back.', 'Cette opération ne peut pas être annulée.')];
    $plans = [];
    foreach (json_decode($log['sql_rollback'], true) ?: [] as $database => $sql) $plans[$database] = ['rollback' => $sql];
    [$ok, $message] = forge_run($plans, 'rollback');
    if ($ok) rows("UPDATE ft_forge_log SET state = 'rolled_back', rolled_back_at = NOW() WHERE id = ?", [$logId]);
    return ['ok' => $ok, 'message' => $message];
}

// ------------------------------------------------------------------ background analysis
function analysis_paths(): array { return [FT_ROOT . '/data/analysis.log', FT_ROOT . '/data/analysis.state']; }
function analysis_status(): array
{
    [$log, $state] = analysis_paths();
    $text = is_file($log) ? (string)file_get_contents($log) : '';
    $started = is_file($state) ? (int)file_get_contents($state) : 0;
    $finished = str_contains($text, 'done in') || str_contains($text, 'Traceback') || str_contains($text, 'Error');
    return ['running' => $started && !$finished && $started > time() - 1800, 'started' => $started, 'failed' => str_contains($text, 'Traceback') || str_contains($text, 'Error'),
            'log' => implode("\n", array_slice(preg_split('/\R/', trim($text)), -14))];
}
function analysis_start(): array
{
    if (analysis_status()['running']) return ['ok' => false, 'message' => t('The analysis is already running.', 'L’analyse est déjà en cours.')];
    [$log, $state] = analysis_paths();
    $python = (string)config('tools', 'python', PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    file_put_contents($state, (string)time());
    file_put_contents($log, '');
    $command = escapeshellarg($python) . ' -u ' . escapeshellarg(FT_ROOT . '/tools/tracker.py') . ' analyse > ' . escapeshellarg($log) . ' 2>&1';
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start "" /B cmd /C "' . $command . '"', 'r'));
    } else {
        exec($command . ' &');
    }
    return ['ok' => true];
}
