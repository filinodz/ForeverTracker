<?php
// Imports: world data in WowPacketParser's SQL format (or any SQL of INSERT statements),
// merged into the server with the Forge's engine. Nothing is ever deleted or overwritten: only the rows the server
// lacks are added, spawns get new guids, spawns that already stand at the same place are left out, and every
// operation keeps an exact rollback.
//
//   data/imports/*.sql            files to import (drop them there, or upload them from the Forge)
//   data/imports/parsed/<id>.json what an import kept: {tables: {name: {db, columns, rows}}}
//   ft_imports, ft_import_items     the imports, and which items each one makes obtainable (loot, vendor, quest reward)
require_once FT_APP . '/forge.php';

const IMPORT_SPAWN_TABLES = ['creature' => 'npc', 'gameobject' => 'go'];
// tables keyed by a spawn guid: [table => [guid columns]]
const IMPORT_GUID_TABLES = ['creature_addon' => ['guid'], 'gameobject_addon' => ['guid'], 'creature_formations' => ['leaderGUID', 'memberGUID'],
    'game_event_creature' => ['guid'], 'game_event_gameobject' => ['guid'], 'linked_respawn' => ['guid', 'linkedGuid'], 'spawn_group' => ['spawnId'],
    'pool_members' => ['spawnId'], 'creature_movement_override' => ['SpawnId']];
// the spawn kind behind the guid columns of those tables
const IMPORT_GUID_KIND = ['creature_addon' => 'npc', 'gameobject_addon' => 'go', 'creature_formations' => 'npc', 'game_event_creature' => 'npc',
    'game_event_gameobject' => 'go', 'creature_movement_override' => 'npc'];

function import_dir(): string { return FT_ROOT . '/data/imports'; }

function import_schema(): void
{
    db()->query("CREATE TABLE IF NOT EXISTS ft_imports (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, file VARCHAR(255) NOT NULL,
        imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, admin VARCHAR(190) NOT NULL DEFAULT '', counts JSON, ignored JSON) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->query("CREATE TABLE IF NOT EXISTS ft_import_items (item INT NOT NULL, import_id INT NOT NULL, kind VARCHAR(8) NOT NULL, source INT NOT NULL,
        PRIMARY KEY (item, import_id, kind, source), KEY ix_import (import_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ------------------------------------------------------------------ parsing
/** Statements of an SQL file, one at a time (quotes, backticks and comments respected). */
function import_statements(string $path): Generator
{
    $handle = fopen($path, 'rb');
    if (!$handle) return;
    $statement = ''; $quote = ''; $comment = ''; $star = false;
    while (!feof($handle)) {
        $chunk = (string)fread($handle, 1 << 20);
        $length = strlen($chunk);
        for ($i = 0; $i < $length; $i++) {
            $c = $chunk[$i];
            if ($comment === '-') { if ($c === "\n") { $comment = ''; $statement .= "\n"; } continue; }
            if ($comment === '*') { if ($c === '/' && $star) { $comment = ''; $statement .= ' '; } $star = $c === '*'; continue; }
            if ($quote !== '') {
                // copy everything up to the next quote or backslash at once
                $span = strcspn($chunk, $quote . '\\', $i);
                if ($span > 0) { $statement .= substr($chunk, $i, $span); $i += $span - 1; continue; }
                $statement .= $c;
                if ($c === '\\') { if ($i + 1 < $length) { $statement .= $chunk[++$i]; } else { $statement .= (string)fread($handle, 1); } }
                elseif ($c === $quote) $quote = '';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $statement .= $c; continue; }
            if ($c === '-' && ($chunk[$i + 1] ?? '') === '-') { $comment = '-'; $i++; continue; }
            if ($c === '#') { $comment = '-'; continue; }
            if ($c === '/' && ($chunk[$i + 1] ?? '') === '*') { $comment = '*'; $star = false; $i++; continue; }
            if ($c === ';') { if (trim($statement) !== '') yield trim($statement); $statement = ''; continue; }
            $statement .= $c;
        }
    }
    fclose($handle);
    if (trim($statement) !== '') yield trim($statement);
}

/** [table, columns|null, rows] of an INSERT / REPLACE statement, or null. Values: int, float, string, null, or ['@', NAME, offset]. */
function import_insert(string $sql): ?array
{
    if (!preg_match('/^(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+`?(\w+)`?(?:\.`?(\w+)`?)?\s*(?:\(([^)]*)\))?\s*VALUES\s*/is', $sql, $m)) return null;
    $table = ($m[2] ?? '') !== '' ? $m[2] : $m[1];
    $columns = ($m[3] ?? '') !== '' ? array_map(fn($c) => trim($c, " `\t\r\n"), explode(',', $m[3])) : null;
    $rows = []; $row = []; $i = strlen($m[0]); $n = strlen($sql); $depth = 0;
    while ($i < $n) {
        $c = $sql[$i];
        if ($c === '(') { $depth = 1; $row = []; $i++; continue; }
        if ($c === ')') { if ($depth) { $rows[] = $row; $depth = 0; } $i++; continue; }
        if ($c === ',' || ctype_space($c)) { $i++; continue; }
        if (!$depth) { $i++; continue; }
        if ($c === "'" || $c === '"') {
            $value = ''; $i++;
            while ($i < $n) {
                $ch = $sql[$i];
                if ($ch === '\\' && $i + 1 < $n) {
                    $next = $sql[$i + 1];
                    $value .= ['n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0", 'Z' => "\x1a"][$next] ?? $next;
                    $i += 2; continue;
                }
                if ($ch === $c) { if (($sql[$i + 1] ?? '') === $c) { $value .= $c; $i += 2; continue; } $i++; break; }
                $value .= $ch; $i++;
            }
            $row[] = $value;
            continue;
        }
        // a bare token: number, NULL, @VAR, @VAR+N
        $span = strcspn($sql, ",)", $i);
        $token = trim(substr($sql, $i, $span));
        $i += $span;
        if (strcasecmp($token, 'NULL') === 0) $row[] = null;
        elseif (preg_match('/^@(\w+)\s*([+-]\s*\d+)?$/', $token, $v)) $row[] = ['@', strtoupper($v[1]), (int)str_replace(' ', '', $v[2] ?? '0')];
        elseif (preg_match('/^-?\d+$/', $token)) $row[] = (int)$token;
        elseif (is_numeric($token)) $row[] = (float)$token;
        elseif (preg_match('/^0x[0-9a-f]+$/i', $token)) $row[] = hexdec($token);
        else $row[] = $token;  // an expression the importer does not understand: the row is dropped later
    }
    return [$table, $columns, $rows];
}

/** Columns of a table of a database, in order (cached). */
function import_columns(string $database, string $table): array
{
    static $cache = [];
    return $cache[$database . '.' . $table] ??= array_column(rows('SELECT COLUMN_NAME c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ORDINAL_POSITION', [$table], $database), 'c');
}

/** Imports a file: keeps the INSERT rows of the server's world and hotfixes tables; returns the new import id. */
function import_file(string $path, string $name): array
{
    require_once FT_APP . '/collector.php';
    if (collector_is($path)) return collector_import($path, $name);
    import_schema();
    @set_time_limit(0);
    @ini_set('memory_limit', '1024M');
    $tables = []; $ignored = []; $statements = 0; $bad = 0;
    foreach (import_statements($path) as $sql) {
        $statements++;
        $insert = import_insert($sql);
        if (!$insert) continue;
        [$table, $columns, $rows] = $insert;
        $database = table_exists($table, 'world') ? 'world' : (table_exists($table, 'hotfixes') ? 'hotfixes' : '');
        if ($database === '' || !$rows) { if ($rows) $ignored[$table] = ($ignored[$table] ?? 0) + count($rows); continue; }
        $known = import_columns($database, $table);
        $columns ??= array_slice($known, 0, count($rows[0]));
        // columns this core does not have are dropped (older or newer WowPacketParser)
        $keep = []; foreach ($columns as $i => $column) { $at = array_search(strtolower($column), array_map('strtolower', $known), true); if ($at !== false) $keep[$i] = $known[$at]; }
        $entry = &$tables[$table];
        $entry ??= ['db' => $database, 'columns' => [], 'rows' => []];
        foreach ($rows as $row) {
            if (count($row) !== count($columns)) { $bad++; continue; }
            $record = [];
            foreach ($keep as $i => $column) {
                if (is_string($row[$i]) && $row[$i] !== '' && !preg_match('/^[\s\S]*$/u', $row[$i])) $row[$i] = mb_convert_encoding($row[$i], 'UTF-8', 'Windows-1252');
                $record[$column] = $row[$i];
            }
            $entry['rows'][] = $record;
            foreach (array_keys($record) as $column) $entry['columns'][$column] = true;
        }
        unset($entry);
    }
    foreach ($tables as $table => &$entry) {
        $entry['columns'] = array_keys($entry['columns']);
        // every row on the same columns (a missing one takes the column's default: left out of the INSERT)
        $groups = [];
        foreach ($entry['rows'] as $record) $groups[implode(',', array_keys($record))][] = array_values($record);
        $entry['groups'] = array_map(fn($k, $rows) => ['columns' => explode(',', $k), 'rows' => $rows], array_keys($groups), $groups);
        unset($entry['rows']);
    }
    unset($entry);
    if (!$tables) return ['ok' => false, 'message' => sprintf(t('No INSERT of a world or hotfixes table was found in %s (%d statements read).', 'Aucun INSERT d’une table world ou hotfixes trouvé dans %s (%d requêtes lues).'), basename($path), $statements)];
    $counts = []; foreach ($tables as $table => $entry) $counts[$table] = array_sum(array_map(fn($g) => count($g['rows']), $entry['groups']));
    rows('INSERT INTO ft_imports (name, file, admin, counts, ignored) VALUES (?, ?, ?, ?, ?)', [$name, basename($path), (string)(admin()['name'] ?? 'cli'), json_encode($counts), json_encode($ignored)]);
    $id = (int)db()->insert_id;
    @mkdir(import_dir() . '/parsed', 0775, true);
    file_put_contents(import_dir() . "/parsed/$id.json", json_encode(['tables' => $tables], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    import_index($id, $tables);
    return ['ok' => true, 'id' => $id, 'counts' => $counts, 'ignored' => $ignored, 'bad' => $bad, 'statements' => $statements];
}

/** Which items each import makes obtainable: loot (creature / gameobject), vendor, quest reward. */
function import_index(int $id, array $tables): void
{
    $found = [];
    $each = function (string $table, callable $fn) use ($tables) {
        foreach ($tables[$table]['groups'] ?? [] as $group) foreach ($group['rows'] as $row) $fn(array_combine($group['columns'], $row));
    };
    foreach (['creature_loot_template' => 'loot', 'gameobject_loot_template' => 'object'] as $table => $kind) {
        $each($table, function ($r) use (&$found, $kind) { if ((int)($r['ItemType'] ?? 0) === 0 && is_int($r['Item'] ?? null) && is_int($r['Entry'] ?? null)) $found[] = [$r['Item'], $kind, $r['Entry']]; });
    }
    $each('npc_vendor', function ($r) use (&$found) { if (is_int($r['item'] ?? null) && is_int($r['entry'] ?? null) && (int)($r['type'] ?? 1) === 1) $found[] = [$r['item'], 'vendor', $r['entry']]; });
    $each('quest_template', function ($r) use (&$found) {
        foreach (['RewardItem1', 'RewardItem2', 'RewardItem3', 'RewardItem4', 'RewardChoiceItemID1', 'RewardChoiceItemID2', 'RewardChoiceItemID3', 'RewardChoiceItemID4', 'RewardChoiceItemID5', 'RewardChoiceItemID6'] as $c)
            if (is_int($r[$c] ?? null) && $r[$c] > 0 && is_int($r['ID'] ?? null)) $found[] = [$r[$c], 'quest', $r['ID']];
    });
    foreach (array_chunk($found, 500) as $chunk) {
        db()->query('INSERT IGNORE INTO ft_import_items (item, import_id, kind, source) VALUES ' . implode(',', array_map(fn($f) => '(' . (int)$f[0] . ',' . $id . ",'" . $f[1] . "'," . (int)$f[2] . ')', $chunk)));
    }
}

function import_load(int $id): ?array
{
    $path = import_dir() . "/parsed/$id.json";
    return is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
}

function import_delete(int $id): void
{
    import_schema();
    rows('DELETE FROM ft_import_items WHERE import_id = ?', [$id]);
    rows('DELETE FROM ft_imports WHERE id = ?', [$id]);
    @unlink(import_dir() . "/parsed/$id.json");
}

// ------------------------------------------------------------------ planning
/**
 * The Forge plan of an import: tables to apply (all when empty), optionally only the rows that make the given items
 * obtainable. Spawns get new guids from the current maximum; a spawn whose creature / object already stands within
 * 3 yards on the same map is left out, with the rows that depend on its guid.
 */
function import_build(int $id, array $only = [], array $items = []): array
{
    $data = import_load($id);
    if (!$data) return ['plans' => [], 'rows' => 0, 'skipped' => 0, 'duplicates' => 0, 'tables' => [], 'warnings' => [t('This import has no data any more: import the file again.', 'Cet import n’a plus de données : importez le fichier à nouveau.')], 'items' => []];
    $tables = $data['tables'];
    if ($only) $tables = array_intersect_key($tables, array_flip($only));
    $warnings = []; $ops = []; $nearby = 0; $unknown = 0; $lootIds = []; $vendors = [];
    $itemSet = $items ? array_flip(array_map('intval', $items)) : null;
    if ($itemSet !== null) {
        // only what makes these items obtainable: their loot rows, vendor rows and the quests that reward them
        $tables = array_intersect_key($tables, array_flip(['creature_loot_template', 'gameobject_loot_template', 'npc_vendor', 'quest_template']));
    }

    // new guids for spawns, and the spawns that already exist close by
    $guidMap = ['npc' => [], 'go' => []]; $next = [];
    foreach (IMPORT_SPAWN_TABLES as $table => $kind) {
        if (!isset($tables[$table])) continue;
        $next[$kind] = (int)value("SELECT COALESCE(MAX(guid), 0) FROM `$table`", [], 'world');
        $entries = [];
        foreach ($tables[$table]['groups'] as $group) foreach ($group['rows'] as $row) { $r = array_combine($group['columns'], $row); if (is_int($r['id'] ?? null)) $entries[$r['id']] = true; }
        $existing = [];
        foreach (array_chunk(array_keys($entries), 1000) as $chunk) {
            foreach (rows("SELECT id, map, position_x x, position_y y FROM `$table` WHERE id IN (" . implode(',', $chunk) . ')', [], 'world') as $e) $existing[$e['id'] . ':' . $e['map']][] = [(float)$e['x'], (float)$e['y']];
        }
        $templates = $kind === 'npc' ? 'creature_template' : 'gameobject_template';
        $known = [];
        foreach (array_chunk(array_keys($entries), 1000) as $chunk) foreach (rows("SELECT entry FROM `$templates` WHERE entry IN (" . implode(',', $chunk) . ')', [], 'world') as $t) $known[(int)$t['entry']] = true;
        foreach ($tables[$templates]['groups'] ?? [] as $group) foreach ($group['rows'] as $row) { $r = array_combine($group['columns'], $row); if (is_int($r['entry'] ?? null)) $known[$r['entry']] = true; }
        foreach ($tables[$table]['groups'] as &$group) {
            $keepRows = [];
            foreach ($group['rows'] as $row) {
                $r = array_combine($group['columns'], $row);
                $original = is_array($r['guid'] ?? null) ? implode('', $r['guid']) : (string)($r['guid'] ?? '');
                if (!isset($known[(int)($r['id'] ?? 0)])) { $unknown++; continue; }
                $close = false;
                foreach ($existing[($r['id'] ?? 0) . ':' . ($r['map'] ?? 0)] ?? [] as [$x, $y]) {
                    if (hypot($x - (float)$r['position_x'], $y - (float)$r['position_y']) < 3.0) { $close = true; break; }
                }
                if ($close) { $nearby++; continue; }
                $guidMap[$kind][$original] ??= ++$next[$kind];
                $r['guid'] = $guidMap[$kind][$original];
                $existing[$r['id'] . ':' . $r['map']][] = [(float)$r['position_x'], (float)$r['position_y']];
                $keepRows[] = array_values($r);
            }
            $group['rows'] = $keepRows;
        }
        unset($group);
    }

    foreach ($tables as $table => $entry) {
        $database = $entry['db'];
        // the primary key, or the table's first index when it has none (loot tables: Entry, ItemType, Item)
        $primary = forge_primary($database, $table) ?: array_column(rows("SELECT COLUMN_NAME c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ?
            AND INDEX_NAME = (SELECT MIN(INDEX_NAME) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ?) ORDER BY SEQ_IN_INDEX", [$table, $table], $database), 'c');
        foreach ($entry['groups'] as $group) {
            $columns = $group['columns'];
            $rows = [];
            foreach ($group['rows'] as $row) {
                $r = array_combine($columns, $row);
                // guid references of dependent tables follow their spawn's new guid
                if (isset(IMPORT_GUID_TABLES[$table])) {
                    $kind = IMPORT_GUID_KIND[$table] ?? (str_contains($table, 'gameobject') ? 'go' : 'npc');
                    $lost = false;
                    foreach (IMPORT_GUID_TABLES[$table] as $c) {
                        if (!array_key_exists($c, $r)) continue;
                        $original = is_array($r[$c]) ? implode('', $r[$c]) : (string)$r[$c];
                        if (isset($guidMap[$kind][$original])) $r[$c] = $guidMap[$kind][$original];
                        elseif (is_array($r[$c]) || !in_array($table, ['spawn_group', 'pool_members', 'linked_respawn'], true)) { $lost = true; break; }
                    }
                    if ($lost) continue;
                }
                if ($itemSet !== null) {
                    if (in_array($table, ['creature_loot_template', 'gameobject_loot_template'], true) && !isset($itemSet[(int)($r['Item'] ?? 0)])) continue;
                    if ($table === 'npc_vendor' && !isset($itemSet[(int)($r['item'] ?? 0)])) continue;
                    if ($table === 'quest_template') {
                        $rewards = array_intersect_key($r, array_flip(['RewardItem1', 'RewardItem2', 'RewardItem3', 'RewardItem4', 'RewardChoiceItemID1', 'RewardChoiceItemID2', 'RewardChoiceItemID3', 'RewardChoiceItemID4', 'RewardChoiceItemID5', 'RewardChoiceItemID6']));
                        if (!array_intersect_key(array_flip(array_map('intval', $rewards)), $itemSet)) continue;
                    }
                }
                // a value the importer could not read (an unknown @variable or expression): the row is left out
                if (array_filter($r, 'is_array')) { $unknown++; continue; }
                if ($table === 'creature_loot_template' && is_int($r['Entry'] ?? null)) $lootIds[$r['Entry']] = true;
                if ($table === 'npc_vendor' && is_int($r['entry'] ?? null)) $vendors[$r['entry']] = true;
                $rows[] = array_values($r);
            }
            if (!$rows) continue;
            $match = $primary && !array_diff($primary, $columns) ? $primary : $columns;
            forge_merge($ops, ['db' => $database, 'table' => $table, 'columns' => $columns, 'match' => $match, 'rows' => $rows]);
        }
    }
    // a creature drops a loot table only when its template points to it: fill LootID where it is still 0
    // a creature sells only with the vendor flag (npcflag 128): added where it lacks it
    if ($vendors) forge_merge($ops, ['db' => 'world', 'table' => 'creature_template', 'columns' => ['entry', 'npcflag'], 'match' => ['entry'], 'bits' => 'npcflag',
        'rows' => array_map(fn($entry) => [$entry, 128], array_keys($vendors))]);
    // (a loot table some creature already points to needs nothing: the rows were added to that creature's own table)
    foreach (array_chunk(array_keys($lootIds), 1000) as $chunk) {
        foreach (rows('SELECT DISTINCT LootID FROM creature_template_difficulty WHERE LootID IN (' . implode(',', $chunk) . ')', [], 'world') as $r) unset($lootIds[(int)$r['LootID']]);
    }
    if ($lootIds) {
        $fill = [];
        foreach (array_keys($lootIds) as $entry) $fill[] = [$entry, 0, $entry];
        forge_merge($ops, ['db' => 'world', 'table' => 'creature_template_difficulty', 'columns' => ['Entry', 'DifficultyID', 'LootID'], 'match' => ['Entry', 'DifficultyID'], 'fill' => 'LootID', 'rows' => $fill]);
    }
    if ($nearby) $warnings[] = sprintf(t('%d spawn(s) already stand at the same place on the server and were left out.', '%d placement(s) existent déjà au même endroit sur le serveur et ont été écartés.'), $nearby);
    if ($unknown) $warnings[] = sprintf(t('%d row(s) were left out: unknown template or a value the importer could not read.', '%d ligne(s) écartées : modèle inconnu ou valeur illisible.'), $unknown);
    $built = forge_plan($ops, [], $warnings);
    $built['items'] = [['type' => 'import', 'id' => $id, 'kind' => 'import']];
    $built['spawns'] = (bool)array_intersect(array_keys($tables), array_keys(IMPORT_SPAWN_TABLES));
    return $built;
}

function import_apply(int $id, array $only = [], array $items = []): array
{
    forge_schema();
    require_once FT_APP . '/spawns.php';
    $built = import_build($id, $only, $items);
    if ($built['spawns'] && $built['rows'] && spawn_world_running()) {
        return ['ok' => false, 'message' => t('This import adds spawns and the worldserver is running: it hands out spawn ids itself. Stop it first, or leave the creature and gameobject tables out.', 'Cet import ajoute des placements et le worldserver tourne : il attribue lui-même les identifiants. Arrêtez-le d’abord, ou écartez les tables creature et gameobject.')];
    }
    return forge_commit($built, 'import');
}
