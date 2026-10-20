<?php
// ForeverScribe files: what the ForeverScribe addon noted while someone played (WTF\Account\<account>\SavedVariables\
// ForeverScribe.lua), turned into the same tables as an imported SQL file — vendors (npc_vendor), loot
// (creature_loot_template, gameobject_loot_template), quest givers and enders — so that the Imports page previews,
// applies and rolls them back like any other import. Where creatures stand goes to ft_collected, which the locator
// prefers over Wowhead.
require_once FT_APP . '/imports.php';

function collector_schema(): void
{
    db()->query("CREATE TABLE IF NOT EXISTS ft_collected (kind VARCHAR(4) NOT NULL, entry INT NOT NULL, name VARCHAR(190) NOT NULL DEFAULT '',
        points JSON, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (kind, entry)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** The value of a SavedVariables file (Lua table constructors, as the game writes them) as PHP arrays. */
function collector_lua(string $text): array
{
    $at = strpos($text, '{');
    if ($at === false) return [];
    $n = strlen($text); $i = $at;
    $skip = function () use ($text, $n, &$i) {
        while ($i < $n) {
            $c = $text[$i];
            if (ctype_space($c) || $c === ',' || $c === ';') { $i++; continue; }
            if ($c === '-' && ($text[$i + 1] ?? '') === '-') { $end = strpos($text, "\n", $i); $i = $end === false ? $n : $end + 1; continue; }
            break;
        }
    };
    $value = function () use ($text, $n, &$i, $skip, &$value) {
        $skip();
        $c = $text[$i] ?? '';
        if ($c === '{') {
            $i++; $out = []; $index = 1;
            while (true) {
                $skip();
                if (($text[$i] ?? '}') === '}') { $i++; return $out; }
                if ($text[$i] === '[') {
                    $i++; $key = $value(); $skip();
                    if (($text[$i] ?? '') === ']') $i++;
                    $skip();
                    if (($text[$i] ?? '') === '=') $i++;
                    $out[$key] = $value();
                } elseif (preg_match('/\G([A-Za-z_]\w*)\s*=/', $text, $m, 0, $i)) {
                    $i += strlen($m[0]);
                    $out[$m[1]] = $value();
                } else {
                    $out[$index++] = $value();
                }
            }
        }
        if ($c === '"' || $c === "'") {
            $i++; $s = '';
            while ($i < $n && $text[$i] !== $c) {
                if ($text[$i] === '\\') { $e = $text[$i + 1] ?? ''; $s .= ['n' => "\n", 't' => "\t", 'r' => "\r"][$e] ?? $e; $i += 2; continue; }
                $s .= $text[$i++];
            }
            $i++;
            return $s;
        }
        if (preg_match('/\G-?(?:\d+\.?\d*(?:[eE][-+]?\d+)?|0x[0-9a-fA-F]+)/', $text, $m, 0, $i)) {
            $i += strlen($m[0]);
            return str_contains($m[0], '.') || stripos($m[0], 'e') !== false && !str_starts_with(strtolower($m[0]), '0x') ? (float)$m[0] : (int)$m[0];
        }
        foreach (['true' => true, 'false' => false, 'nil' => null] as $word => $result) {
            if (substr($text, $i, strlen($word)) === $word) { $i += strlen($word); return $result; }
        }
        $i++;
        return null;
    };
    $result = $value();
    return is_array($result) ? $result : [];
}

/** True when a file is a ForeverScribe SavedVariables file. */
function collector_is(string $path): bool
{
    if (!preg_match('/\.lua$/i', $path)) return false;
    $head = (string)@file_get_contents($path, false, null, 0, 4096);
    return str_contains($head, 'ForeverScribeDB');
}

/**
 * Items a loot table already gives, directly or through reference loot (ItemType 1, followed to any depth):
 * [entry => [item => true]]. In the game a drop shows only as the item, so without this an item the
 * server already drops through a reference would be added a second time, straight into the creature's table.
 */
function collector_loot_items(string $table, array $entries): array
{
    $out = []; $refsOf = [];
    foreach (array_chunk(array_values(array_unique($entries)), 1000) as $chunk) {
        foreach (rows("SELECT Entry, ItemType, Item FROM `$table` WHERE Entry IN (" . implode(',', $chunk) . ')', [], 'world') as $r) {
            if ((int)$r['ItemType'] === 0) $out[(int)$r['Entry']][(int)$r['Item']] = true;
            elseif ((int)$r['ItemType'] === 1) $refsOf[(int)$r['Entry']][] = (int)$r['Item'];
        }
    }
    // the items of every reference, references inside references included
    $refItems = []; $children = []; $loaded = [];
    $todo = $refsOf ? array_values(array_unique(array_merge(...array_values($refsOf)))) : [];
    for ($depth = 0; $todo && $depth < 8; $depth++) {
        $next = [];
        foreach ($todo as $ref) $loaded[$ref] = true;
        foreach (array_chunk($todo, 1000) as $chunk) {
            foreach (rows('SELECT Entry, ItemType, Item FROM reference_loot_template WHERE Entry IN (' . implode(',', $chunk) . ')', [], 'world') as $r) {
                if ((int)$r['ItemType'] === 0) $refItems[(int)$r['Entry']][(int)$r['Item']] = true;
                elseif ((int)$r['ItemType'] === 1) { $children[(int)$r['Entry']][] = (int)$r['Item']; if (!isset($loaded[(int)$r['Item']])) $next[] = (int)$r['Item']; }
            }
        }
        $todo = array_values(array_unique($next));
    }
    // a reference gives its own items and those of the references inside it
    $expand = function (int $ref, array $seen) use (&$expand, $refItems, $children) {
        $items = $refItems[$ref] ?? [];
        foreach ($children[$ref] ?? [] as $child) if (!isset($seen[$child])) $items += $expand($child, $seen + [$child => true]);
        return $items;
    };
    foreach ($refsOf as $entry => $refs) foreach ($refs as $ref) $out[$entry] = ($out[$entry] ?? []) + $expand($ref, [$ref => true]);
    return $out;
}

/** [tables (as import_file keeps them), counts of what was left out, positions] of a ForeverScribe file. */
function collector_tables(array $data): array
{
    $build = 0;
    if (preg_match('/(\d+)$/', (string)($data['client'] ?? ''), $m)) $build = (int)$m[1];
    $left = ['vendor_unknown' => 0, 'cost_unknown' => 0, 'loot_unknown' => 0, 'loot_known' => 0, 'object_no_loot' => 0, 'quest_unknown' => 0];
    $rows = [];   // table => list of records
    $known = function (string $table, array $ids, string $column = 'entry') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $found = [];
        foreach (array_chunk($ids, 1000) as $chunk) foreach (rows("SELECT `$column` id FROM `$table` WHERE `$column` IN (" . implode(',', $chunk) . ')', [], 'world') as $r) $found[(int)$r['id']] = true;
        return $found;
    };

    // ---- vendors: the list as the vendor showed it (limited stock gets a restock time of one hour)
    $vendors = (array)($data['vendors'] ?? []);
    $creatures = $known('creature_template', array_keys($vendors));
    foreach ($vendors as $entry => $vendor) {
        if (!isset($creatures[(int)$entry])) { $left['vendor_unknown']++; continue; }
        foreach ((array)($vendor['items'] ?? []) as $item => $v) {
            $cost = 0;
            if (!empty($v['cost'])) {
                // an item paid with items or currencies: reuse the ExtendedCost of another vendor selling it, if any
                $cost = (int)value('SELECT ExtendedCost FROM npc_vendor WHERE item = ? AND ExtendedCost > 0 LIMIT 1', [(int)$item], 'world');
                if (!$cost) { $left['cost_unknown']++; continue; }
            }
            $limit = (int)($v['limit'] ?? -1);
            $rows['npc_vendor'][] = ['entry' => (int)$entry, 'slot' => (int)($v['slot'] ?? 0), 'item' => (int)$item, 'maxcount' => max(0, $limit),
                'incrtime' => $limit >= 0 ? 3600 : 0, 'ExtendedCost' => $cost, 'type' => 1, 'VerifiedBuild' => $build];
        }
    }

    // ---- loot: the chance is how often the item dropped among the corpses / objects looted
    $loot = (array)($data['loot'] ?? []);
    $npcEntries = []; $goEntries = [];
    foreach (array_keys($loot) as $key) { [$kind, $id] = explode(':', (string)$key) + [1 => 0]; if ($kind === 'npc') $npcEntries[] = (int)$id; if ($kind === 'go') $goEntries[] = (int)$id; }
    $lootIds = [];
    foreach (array_chunk(array_values(array_unique($npcEntries)), 1000) as $chunk) {
        foreach (rows('SELECT Entry, LootID FROM creature_template_difficulty WHERE DifficultyID = 0 AND Entry IN (' . implode(',', $chunk) . ')', [], 'world') as $r) $lootIds[(int)$r['Entry']] = (int)$r['LootID'];
    }
    $chests = [];
    foreach (array_chunk(array_values(array_unique($goEntries)), 1000) as $chunk) {
        foreach (rows('SELECT entry, type, Data1 FROM gameobject_template WHERE entry IN (' . implode(',', $chunk) . ')', [], 'world') as $r) $chests[(int)$r['entry']] = in_array((int)$r['type'], [3, 25], true) ? (int)$r['Data1'] : 0;
    }
    $questItems = [];
    foreach (rows('SELECT DISTINCT ObjectID FROM quest_objectives WHERE Type = 1', [], 'world') as $r) $questItems[(int)$r['ObjectID']] = true;
    // what each loot table already gives, references included
    $lootEntries = ['creature_loot_template' => [], 'gameobject_loot_template' => []];
    foreach ($lootIds as $entry => $lootId) $lootEntries['creature_loot_template'][] = $lootId ?: $entry;
    foreach ($chests as $lootId) if ($lootId) $lootEntries['gameobject_loot_template'][] = $lootId;
    $gives = ['creature_loot_template' => collector_loot_items('creature_loot_template', $lootEntries['creature_loot_template']),
              'gameobject_loot_template' => collector_loot_items('gameobject_loot_template', $lootEntries['gameobject_loot_template'])];
    foreach ($loot as $key => $source) {
        [$kind, $id] = explode(':', (string)$key) + [1 => 0];
        $id = (int)$id; $opened = max(1, (int)($source['opened'] ?? 1));
        if ($kind === 'npc') {
            if (!array_key_exists($id, $lootIds)) { $left['loot_unknown']++; continue; }
            [$table, $entry] = ['creature_loot_template', $lootIds[$id] ?: $id];
        } elseif ($kind === 'go') {
            if (empty($chests[$id])) { $left['object_no_loot']++; continue; }
            [$table, $entry] = ['gameobject_loot_template', $chests[$id]];
        } else continue;   // skinning and pick pocketing are kept for later
        foreach ((array)($source['items'] ?? []) as $item => $drop) {
            if (isset($gives[$table][$entry][(int)$item])) { $left['loot_known'] = ($left['loot_known'] ?? 0) + 1; continue; }
            $chance = round(min(100, max(0.01, 100 * (int)($drop['n'] ?? 1) / $opened)), 2);
            $rows[$table][] = ['Entry' => $entry, 'ItemType' => 0, 'Item' => (int)$item, 'Chance' => $chance, 'QuestRequired' => isset($questItems[(int)$item]) ? 1 : 0,
                'LootMode' => 1, 'GroupId' => 0, 'MinCount' => max(1, (int)($drop['min'] ?? 1)), 'MaxCount' => max(1, (int)($drop['max'] ?? 1)),
                'Comment' => sprintf('ForeverScribe: %d of %d', (int)($drop['n'] ?? 1), $opened)];
        }
    }

    // ---- quest givers and enders
    $quests = (array)($data['quests'] ?? []);
    $existingQuests = $known('quest_template', array_keys($quests), 'ID');
    $by = ['npc' => [], 'go' => []];
    foreach ($quests as $q) foreach (['s', 'e'] as $role) if (!empty($q[$role])) { [$kind, $id] = explode(':', (string)$q[$role]) + [1 => 0]; $by[$kind][] = (int)$id; }
    $templates = ['npc' => $known('creature_template', $by['npc']), 'go' => $known('gameobject_template', $by['go'])];
    foreach ($quests as $quest => $q) {
        if (!isset($existingQuests[(int)$quest])) { $left['quest_unknown']++; continue; }
        foreach (['s' => 'queststarter', 'e' => 'questender'] as $role => $suffix) {
            if (empty($q[$role])) continue;
            [$kind, $id] = explode(':', (string)$q[$role]) + [1 => 0];
            if (!isset($templates[$kind][(int)$id])) continue;
            $rows[($kind === 'npc' ? 'creature_' : 'gameobject_') . $suffix][] = ['id' => (int)$id, 'quest' => (int)$quest, 'VerifiedBuild' => $build];
        }
    }

    // keep the columns this core has, grouped as import_file keeps them
    $tables = [];
    foreach ($rows as $table => $records) {
        if (!table_exists($table, 'world')) continue;
        $columns = import_columns('world', $table);
        $use = array_values(array_intersect(array_keys($records[0]), $columns));
        $tables[$table] = ['db' => 'world', 'columns' => $use, 'groups' => [['columns' => $use, 'rows' => array_map(fn($r) => array_map(fn($c) => $r[$c], $use), $records)]]];
    }
    $positions = [];
    foreach (['npcs' => 'npc', 'objects' => 'go'] as $list => $kind) {
        foreach ((array)($data[$list] ?? []) as $entry => $e) if (!empty($e['p'])) $positions[] = [$kind, (int)$entry, (string)($e['n'] ?? ''), array_values((array)$e['p'])];
    }
    return [$tables, array_filter($left), $positions];
}

/** Where creatures and objects were seen: merged into ft_collected (points of earlier files are kept). */
function collector_positions(array $positions): int
{
    collector_schema();
    foreach ($positions as [$kind, $entry, $name, $points]) {
        $old = json_decode((string)value('SELECT points FROM ft_collected WHERE kind = ? AND entry = ?', [$kind, $entry]), true) ?: [];
        foreach ($points as $p) if (!in_array($p, $old, false) && count($old) < 60) $old[] = $p;
        rows('INSERT INTO ft_collected (kind, entry, name, points) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = IF(VALUES(name) = \'\', name, VALUES(name)), points = VALUES(points)',
            [$kind, $entry, $name, json_encode($old)]);
    }
    return count($positions);
}

/** Imports a ForeverScribe file as an import (see import_file); returns what import_file returns plus 'positions'. */
function collector_import(string $path, string $name): array
{
    import_schema();
    $data = collector_lua((string)file_get_contents($path));
    if (($data['version'] ?? 0) < 1) return ['ok' => false, 'message' => t('This is not a ForeverScribe file.', 'Ce n’est pas un fichier ForeverScribe.')];
    [$tables, $left, $positions] = collector_tables($data);
    $located = collector_positions($positions);
    // new places: the locator runs in the background so they show in The Forge › Spawns without a click
    if ($located) analysis_start('locate');
    if (!$tables) return ['ok' => (bool)$located, 'id' => 0, 'counts' => [], 'ignored' => $left, 'bad' => 0, 'statements' => 0, 'positions' => $located,
        'message' => $located ? '' : t('The file holds nothing this server can use yet.', 'Le fichier ne contient rien d’utilisable par ce serveur pour l’instant.')];
    $counts = []; foreach ($tables as $table => $entry) $counts[$table] = count($entry['groups'][0]['rows']);
    rows('INSERT INTO ft_imports (name, file, admin, counts, ignored) VALUES (?, ?, ?, ?, ?)', [$name, basename($path), (string)(admin()['name'] ?? 'cli'), json_encode($counts), json_encode($left)]);
    $id = (int)db()->insert_id;
    @mkdir(import_dir() . '/parsed', 0775, true);
    file_put_contents(import_dir() . "/parsed/$id.json", json_encode(['tables' => $tables], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    import_index($id, $tables);
    return ['ok' => true, 'id' => $id, 'counts' => $counts, 'ignored' => $left, 'bad' => 0, 'statements' => 0, 'positions' => $located];
}

/** ForeverScribe files of the game client set in [client] path: [path, account folder, modified]. */
function collector_files(): array
{
    $root = rtrim(str_replace('\\', '/', (string)config('client', 'path', '')), '/');
    if ($root === '' || !is_dir($root)) return [];
    $found = [];
    foreach (['', '/_classic_beta_', '/_classic_era_', '/_classic_', '/_retail_', '/_anniversary_'] as $flavor) {
        foreach (glob($root . $flavor . '/WTF/Account/*/SavedVariables/ForeverScribe.lua') ?: [] as $path) {
            $found[] = ['path' => $path, 'account' => basename(dirname(dirname($path))), 'flavor' => trim($flavor, '/_') ?: 'game', 'modified' => (int)filemtime($path)];
        }
    }
    return $found;
}

/**
 * Imports every ForeverScribe file of the game client that changed since its last import (data/collector-state.json);
 * with $apply, also applies what the server lacks (vendors, loot, quest givers). Returns one line per file.
 */
function collector_sync(bool $apply = false): array
{
    $statePath = FT_ROOT . '/data/collector-state.json';
    $state = is_file($statePath) ? (json_decode((string)file_get_contents($statePath), true) ?: []) : [];
    $report = [];
    foreach (collector_files() as $file) {
        $hash = sha1_file($file['path']);
        if (($state[$file['path']] ?? '') === $hash) continue;
        $result = collector_import($file['path'], 'ForeverScribe · ' . $file['flavor'] . ' · ' . date('Y-m-d H:i', $file['modified']));
        $line = $result['ok'] ? sprintf('%s: %s, %d creature(s) or object(s) located', $file['flavor'], $result['counts'] ? implode(', ', array_map(fn($t, $n) => "$t $n", array_keys($result['counts']), $result['counts'])) : 'no rows', $result['positions'] ?? 0)
            : $file['flavor'] . ': ' . ($result['message'] ?? 'failed');
        if ($apply && $result['ok'] && !empty($result['id'])) {
            $applied = import_apply((int)$result['id']);
            $line .= $applied['ok'] ? sprintf(' · applied: %d row(s) (history #%d)', $applied['rows'] ?? 0, $applied['log'] ?? 0) : ' · not applied: ' . ($applied['message'] ?? '');
        }
        $report[] = $line;
        $state[$file['path']] = $hash;
    }
    @file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $report;
}
