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
        'vendor' => t('Vendors', 'Marchands'), 'instance' => t('Instances', 'Instances'), 'spawn' => t('Spawns', 'Placements'), 'import' => t('Import', 'Import'),
    ][$kind] ?? $kind;
}

/** Splits a saved script into statements (generated values never contain a raw line break). */
function forge_statements($sql): array
{
    if (is_array($sql)) return $sql;
    return array_values(array_filter(array_map('trim', preg_split('/;\s*\R/', rtrim((string)$sql) . "\n")), fn($s) => $s !== ''));
}

/** SQL literal of a JSON value. */
function forge_literal($value, string $database): string
{
    if ($value === null) return 'NULL';
    if (is_bool($value)) return $value ? '1' : '0';
    if (is_int($value)) return (string)$value;
    if (is_float($value)) return is_finite($value) ? sprintf('%.9g', $value) : '0';
    return "'" . db($database)->real_escape_string((string)$value) . "'";
}

function forge_ident(string $name): string { return '`' . str_replace('`', '', $name) . '`'; }

/** Primary key columns of a table (cached). */
function forge_primary(string $database, string $table): array
{
    static $cache = [];
    return $cache[$database . '.' . $table] ??= array_column(rows("SELECT COLUMN_NAME c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = 'PRIMARY' ORDER BY SEQ_IN_INDEX", [$table], $database), 'c');
}

/** "(a,b) IN ((1,2),(3,4))" or "a IN (1,3)" for rows. */
function forge_in(array $columns, array $tuples, string $database): string
{
    if (count($columns) === 1) return forge_ident($columns[0]) . ' IN (' . implode(',', array_map(fn($t) => forge_literal($t[0], $database), $tuples)) . ')';
    return '(' . implode(',', array_map('forge_ident', $columns)) . ') IN (' . implode(',', array_map(fn($t) => '(' . implode(',', array_map(fn($v) => forge_literal($v, $database), $t)) . ')', $tuples)) . ')';
}

function forge_key(array $values): string { return implode("\x1f", array_map(fn($v) => is_float($v) ? sprintf('%.9g', $v) : (string)$v, $values)); }

/** Adds an operation to the merged list: one entry per database/table/columns, rows unique on their match columns. */
function forge_merge(array &$ops, array $op): void
{
    $database = (string)($op['db'] ?? ''); $table = (string)($op['table'] ?? '');
    $columns = array_values($op['columns'] ?? []); $match = array_values($op['match'] ?? []);
    if (!in_array($database, ['world', 'hotfixes'], true) || $table === '' || !$columns || !$match || array_diff($match, $columns)) return;
    // "fill": the last column is set on existing rows only where it is still 0 / NULL (rows are never inserted)
    $fill = (string)($op['fill'] ?? '');
    if ($fill !== '' && end($columns) !== $fill) return;
    $id = $database . '.' . $table . '.' . implode(',', $columns) . ($fill !== '' ? '.fill' : '');
    $ops[$id] ??= ['db' => $database, 'table' => $table, 'columns' => $columns, 'match' => $match, 'hash' => (int)($op['hash'] ?? 0), 'rows' => [], 'duplicates' => 0, 'fill' => $fill];
    $where = array_map(fn($c) => array_search($c, $columns, true), $match);
    foreach ($op['rows'] ?? [] as $row) {
        if (!is_array($row) || count($row) !== count($columns)) continue;
        $key = forge_key(array_map(fn($i) => $row[$i], $where));
        if (isset($ops[$id]['rows'][$key])) { $ops[$id]['duplicates']++; continue; }
        $ops[$id]['rows'][$key] = $row;
    }
}

/**
 * Plans a selection [{type, id, kind}, …] against the databases as they are now.
 * Returns ['plans' => [db => ['apply' => [statements], 'rollback' => [statements]]], 'items', 'rows' (to insert),
 *          'skipped' (already on the server), 'duplicates' (shared by several fixes), 'tables' => [db.table => [insert, skip]], 'warnings'].
 */
function forge_build(array $selection, array $options = []): array
{
    $ops = []; $items = []; $warnings = []; $vendorItems = []; $stale = 0; $missing = 0;
    foreach (array_slice($selection, 0, 2000) as $pick) {
        $type = (string)($pick['type'] ?? ''); $id = (int)($pick['id'] ?? 0); $kind = (string)($pick['kind'] ?? '');
        if (!isset(TYPES[$type]) || !$id || !in_array($kind, FORGE_KINDS, true)) continue;
        if ($kind === 'vendor') { $vendorItems[$id] = true; $items[] = ['type' => 'item', 'id' => $id, 'kind' => 'vendor']; continue; }
        $fix = row('SELECT detail FROM ft_fixes WHERE type = ? AND id = ? AND kind = ?', [$type, $id, $kind]);
        if (!$fix) { $missing++; continue; }
        $detail = json_decode((string)$fix['detail'], true) ?: [];
        if (!isset($detail['ops'])) { $stale++; continue; }
        foreach ($detail['ops'] as $op) forge_merge($ops, $op);
        $items[] = ['type' => $type, 'id' => $id, 'kind' => $kind];
    }
    if ($missing) $warnings[] = sprintf(t('%d selected fix(es) no longer exist: run the analysis again.', '%d correction(s) choisie(s) n’existent plus : relancez l’analyse.'), $missing);
    if ($stale) $warnings[] = sprintf(t('%d fix(es) come from an older analysis and were left out: run the analysis again.', '%d correction(s) viennent d’une ancienne analyse et ont été écartées : relancez l’analyse.'), $stale);
    if ($vendorItems) {
        $vendor = (int)($options['vendor'] ?? 0);
        $npc = $vendor ? row('SELECT entry, name, npcflag FROM creature_template WHERE entry = ?', [$vendor], 'world') : null;
        if (!$npc) {
            $warnings[] = t('Choose an existing vendor (creature_template entry).', 'Choisissez un marchand existant (entry de creature_template).');
        } else {
            if (!((int)$npc['npcflag'] & 128)) $warnings[] = sprintf(t('%s has no vendor flag (npcflag 128): the items will not show.', '%s n’a pas le drapeau marchand (npcflag 128) : les objets ne s’afficheront pas.'), $npc['name']);
            $slot = (int)value('SELECT COALESCE(MAX(slot), -1) FROM npc_vendor WHERE entry = ?', [$vendor], 'world');
            $rows = [];
            foreach (array_keys($vendorItems) as $item) $rows[] = [$vendor, ++$slot, $item, 0, 0, 0, 1, null, 0, 0, 0];
            forge_merge($ops, ['db' => 'world', 'table' => 'npc_vendor', 'match' => ['entry', 'item', 'ExtendedCost', 'type'], 'rows' => $rows,
                'columns' => ['entry', 'slot', 'item', 'maxcount', 'incrtime', 'ExtendedCost', 'type', 'BonusListIDs', 'PlayerConditionID', 'IgnoreFiltering', 'VerifiedBuild']]);
        }
    }
    return forge_plan($ops, $options, $warnings) + ['items' => $items];
}

/** Plans merged operations against the databases as they are now (see forge_build for what it returns). */
function forge_plan(array $ops, array $options = [], array $warnings = []): array
{
    // keep only the rows the server does not have yet
    $plans = []; $tables = []; $inserted = 0; $skipped = 0; $duplicates = 0; $pushes = [];
    foreach ($ops as $op) {
        $database = $op['db']; $table = $op['table']; $columns = $op['columns']; $match = $op['match'];
        $duplicates += $op['duplicates'];
        if (!table_exists($table, $database)) { $warnings[] = sprintf(t('Table %s.%s does not exist on this server: its rows were left out.', 'La table %s.%s n’existe pas sur ce serveur : ses lignes ont été écartées.'), $database, $table); continue; }
        $where = array_map(fn($c) => array_search($c, $columns, true), $match);
        if (($op['fill'] ?? '') !== '') {
            // set the column of existing rows where it is still empty; the rollback empties it again
            $fill = $op['fill']; $at = count($columns) - 1; $todo = [];
            foreach (array_chunk(array_values($op['rows']), 500) as $chunk) {
                $wanted = [];
                foreach ($chunk as $row) $wanted[forge_key(array_map(fn($i) => $row[$i], $where))] = $row;
                $tuples = array_map(fn($row) => array_map(fn($i) => $row[$i], $where), $chunk);
                foreach (rows('SELECT ' . implode(',', array_map('forge_ident', $match)) . ', ' . forge_ident($fill) . ' v FROM ' . forge_ident($table) . ' WHERE ' . forge_in($match, $tuples, $database), [], $database) as $found) {
                    $current = $found['v']; unset($found['v']);
                    $key = forge_key(array_values($found));
                    if (isset($wanted[$key]) && ($current === null || (string)$current === '0' || (string)$current === '')) $todo[] = $wanted[$key];
                }
            }
            $tables[$database . '.' . $table . '.' . $fill] = [count($todo), count($op['rows']) - count($todo)];
            if (!$todo) continue;
            $inserted += count($todo);
            $plans[$database] ??= ['apply' => [], 'rollback' => []];
            foreach ($todo as $row) {
                $condition = implode(' AND ', array_map(fn($i) => forge_ident($columns[$i]) . ' = ' . forge_literal($row[$i], $database), $where));
                $plans[$database]['apply'][] = 'UPDATE ' . forge_ident($table) . ' SET ' . forge_ident($fill) . ' = ' . forge_literal($row[$at], $database) . ' WHERE ' . $condition . ';';
                array_unshift($plans[$database]['rollback'], 'UPDATE ' . forge_ident($table) . ' SET ' . forge_ident($fill) . ' = 0 WHERE ' . $condition . ' AND ' . forge_ident($fill) . ' = ' . forge_literal($row[$at], $database) . ';');
            }
            continue;
        }
        $existing = [];
        foreach (array_chunk(array_values($op['rows']), 500) as $chunk) {
            $tuples = array_map(fn($row) => array_map(fn($i) => $row[$i], $where), $chunk);
            foreach (rows('SELECT ' . implode(',', array_map('forge_ident', $match)) . ' FROM ' . forge_ident($table) . ' WHERE ' . forge_in($match, $tuples, $database), [], $database) as $found) {
                $existing[forge_key(array_values($found))] = true;
            }
        }
        $new = array_values(array_diff_key($op['rows'], $existing));
        $tables[$database . '.' . $table] = [count($new), count($op['rows']) - count($new)];
        $skipped += count($op['rows']) - count($new);
        if (!$new) continue;
        $inserted += count($new);
        $plans[$database] ??= ['apply' => [], 'rollback' => []];
        $head = 'INSERT INTO ' . forge_ident($table) . ' (' . implode(',', array_map('forge_ident', $columns)) . ") VALUES\n";
        foreach (array_chunk($new, 200) as $chunk) {
            $plans[$database]['apply'][] = $head . implode(",\n", array_map(fn($row) => '(' . implode(',', array_map(fn($v) => forge_literal($v, $database), $row)) . ')', $chunk)) . ';';
        }
        // the rollback deletes exactly these rows: by primary key when the operation sets all of it
        $primary = forge_primary($database, $table);
        $keys = $primary && !array_diff($primary, $columns) ? $primary : $match;
        $positions = array_map(fn($c) => array_search($c, $columns, true), $keys);
        foreach (array_chunk($new, 500) as $chunk) {
            array_unshift($plans[$database]['rollback'], 'DELETE FROM ' . forge_ident($table) . ' WHERE ' . forge_in($keys, array_map(fn($row) => array_map(fn($i) => $row[$i], $positions), $chunk), $database) . ';');
        }
        if ($op['hash'] && in_array('ID', $columns, true)) {
            $at = array_search('ID', $columns, true);
            foreach ($new as $row) $pushes[$op['hash'] . ':' . $row[$at]] = [$op['hash'], (int)$row[$at]];
        }
    }
    if ($pushes && !empty($options['push'])) {
        $hotfixId = (int)value('SELECT COALESCE(MAX(Id), 0) + 1 FROM hotfix_data', [], 'hotfixes');
        $build = (int)(meta()['client_build'] ?? 0);
        $values = [];
        foreach ($pushes as [$hash, $record]) $values[] = '(' . $hotfixId . ',' . random_int(1, 2147483647) . ',' . (int)$hash . ',' . (int)$record . ',1,' . $build . ')';
        $plans['hotfixes'] ??= ['apply' => [], 'rollback' => []];
        $plans['hotfixes']['apply'][] = "INSERT INTO `hotfix_data` (`Id`,`UniqueId`,`TableHash`,`RecordId`,`Status`,`VerifiedBuild`) VALUES\n" . implode(",\n", $values) . ';';
        array_unshift($plans['hotfixes']['rollback'], 'DELETE FROM `hotfix_data` WHERE Id = ' . $hotfixId . ';');
        $inserted += count($values);
    }
    if ($skipped) $warnings[] = sprintf(t('%d row(s) already on the server will be kept as they are.', '%d ligne(s) déjà présentes sur le serveur seront conservées telles quelles.'), $skipped);
    if ($duplicates) $warnings[] = sprintf(t('%d row(s) shared by several fixes will be inserted once.', '%d ligne(s) communes à plusieurs corrections seront insérées une seule fois.'), $duplicates);
    return ['plans' => $plans, 'rows' => $inserted, 'skipped' => $skipped, 'duplicates' => $duplicates, 'tables' => $tables, 'warnings' => $warnings];
}

/** The whole selection as one .sql script (with USE statements). */
function forge_script(array $plans, string $part = 'apply'): string
{
    $out = '-- Forever Tracker · Forge · ' . date('Y-m-d H:i') . ' · ' . ($part === 'apply' ? 'apply' : 'rollback') . "\n";
    $out .= "-- Rows already on the server were left out when this script was generated.\n";
    foreach ($plans as $database => $plan) {
        $out .= "\nUSE `" . config('database', $database, $database) . "`;\nSTART TRANSACTION;\n" . implode("\n", forge_statements($plan[$part])) . "\nCOMMIT;\n";
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
    return forge_commit($built, implode(',', array_unique(array_column($built['items'], 'kind'))));
}

/** Applies a built plan and records it in the history. */
function forge_commit(array $built, string $kinds): array
{
    $result = ['rows' => $built['rows'], 'skipped' => $built['skipped'], 'warnings' => $built['warnings'], 'items' => $built['items']];
    if (!$built['plans']) {
        if ($built['items'] && $built['skipped']) return ['ok' => true, 'log' => 0, 'message' => t('Everything selected is already on the server: nothing to do.', 'Tout ce qui est choisi est déjà sur le serveur : rien à faire.')] + $result;
        return ['ok' => false, 'message' => $built['warnings'] ? implode(' ', $built['warnings']) : t('Nothing to apply.', 'Rien à appliquer.')] + $result;
    }
    [$ok, $message] = forge_run($built['plans'], 'apply');
    $script = fn($part) => json_encode(array_map(fn($p) => implode("\n", $p[$part]), $built['plans']), JSON_UNESCAPED_UNICODE);
    rows('INSERT INTO ft_forge_log (admin, kind, items, rows_count, sql_apply, sql_rollback, state, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        (string)admin()['name'], $kinds, json_encode($built['items']), $built['rows'], $script('apply'), $script('rollback'),
        $ok ? 'applied' : 'failed', $ok ? implode(' ', $built['warnings']) : $message,
    ]);
    return ['ok' => $ok, 'log' => (int)db()->insert_id, 'message' => $message] + $result;
}

function forge_rollback(int $logId): array
{
    forge_schema();
    $log = row('SELECT * FROM ft_forge_log WHERE id = ?', [$logId]);
    if (!$log || $log['state'] !== 'applied') return ['ok' => false, 'message' => t('This operation cannot be rolled back.', 'Cette opération ne peut pas être annulée.')];
    $plans = []; $console = null;
    foreach (json_decode($log['sql_rollback'], true) ?: [] as $database => $sql) {
        if ($database === 'console') $console = (string)$sql; else $plans[$database] = ['rollback' => $sql];
    }
    if ($console !== null) {
        require_once FT_APP . '/spawns.php';
        [$ok, $message] = spawn_rollback_console($console);
    } else {
        [$ok, $message] = forge_run($plans, 'rollback');
    }
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
/** Starts tools/tracker.py in the background: "analyse", or "locate" (the locator, after an analysis). */
function analysis_start(string $task = 'analyse'): array
{
    if (analysis_status()['running']) return ['ok' => false, 'message' => t('The analysis or the locator is already running.', 'L’analyse ou le localisateur est déjà en cours.')];
    [$log, $state] = analysis_paths();
    $python = (string)config('tools', 'python', PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    file_put_contents($state, (string)time());
    file_put_contents($log, '');
    $task = $task === 'locate' ? 'locate' : 'analyse';
    $command = escapeshellarg($python) . ' -X utf8 -u ' . escapeshellarg(FT_ROOT . '/tools/tracker.py') . ' ' . $task . ' > ' . escapeshellarg($log) . ' 2>&1';
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start "" /B cmd /C "' . $command . '"', 'r'));
    } else {
        exec($command . ' &');
    }
    return ['ok' => true];
}
