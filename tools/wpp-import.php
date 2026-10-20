<?php
// Imports a file in WowPacketParser's SQL format (or any file of INSERT statements) into ForeverTracker, the same way as
// The Forge > Imports, for big files or scripts.
//
//   php tools/wpp-import.php <file.sql> [--name "Westfall, 2026-10-20"] [--check]
//   php tools/wpp-import.php --list
//
// --check plans the whole import against the databases as they are now, applies it, runs the rollback and rolls
// everything back: nothing is kept. Applying is done from the website (history, rollback).
if (PHP_SAPI !== 'cli') exit;
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/app/bootstrap.php';
require FT_APP . '/imports.php';

$args = array_slice($argv, 1);
$opt = function (string $name) use (&$args) { $i = array_search($name, $args, true); if ($i === false) return null; $v = $args[$i + 1] ?? ''; array_splice($args, $i, 2); return $v; };
$name = $opt('--name');
$check = in_array('--check', $args, true); $args = array_values(array_diff($args, ['--check']));
import_schema();

if (in_array('--list', $args, true)) {
    foreach (rows('SELECT id, name, file, imported_at, counts FROM ft_imports ORDER BY id') as $s) {
        $counts = json_decode((string)$s['counts'], true) ?: [];
        printf("#%d %s (%s, %s): %s\n", $s['id'], $s['name'], $s['file'], $s['imported_at'], implode(', ', array_map(fn($t, $n) => "$t $n", array_keys($counts), $counts)));
    }
    exit(0);
}
$file = $args[0] ?? '';
if ($file === '' || !is_file($file)) { fwrite(STDERR, "Usage: php tools/wpp-import.php <file.sql> [--name NAME] [--check]\n"); exit(2); }

$started = microtime(true);
$result = import_file($file, $name ?: pathinfo($file, PATHINFO_FILENAME));
if (!$result['ok']) { fwrite(STDERR, $result['message'] . "\n"); exit(1); }
printf("Imported #%d: %d statements read in %.1f s\n", $result['id'], $result['statements'], microtime(true) - $started);
foreach ($result['counts'] as $table => $n) printf("  %-34s %d row(s)\n", $table, $n);
if ($result['ignored']) echo '  not on this server: ', implode(', ', array_map(fn($t, $n) => "$t ($n)", array_keys($result['ignored']), $result['ignored'])), "\n";
if ($result['bad']) echo "  {$result['bad']} malformed row(s) skipped\n";
$items = (int)value('SELECT COUNT(DISTINCT item) FROM ft_import_items WHERE import_id = ?', [$result['id']]);
echo "  makes $items item(s) obtainable (loot, vendor, quest reward)\n";

if ($check) {
    $built = import_build($result['id']);
    printf("Plan: %d row(s) to write, %d already on the server, %d shared\n", $built['rows'], $built['skipped'], $built['duplicates']);
    foreach ($built['tables'] as $table => [$new, $skip]) printf("  %-44s new %d, present %d\n", $table, $new, $skip);
    foreach ($built['warnings'] as $warning) echo "  - $warning\n";
    $links = [];
    try {
        foreach ($built['plans'] as $database => $plan) { $link = db($database); $link->begin_transaction(); $links[] = $link; foreach ($plan['apply'] as $s) $link->query($s); }
        foreach ($built['plans'] as $database => $plan) foreach ($plan['rollback'] as $s) db($database)->query($s);
        echo "Dry run: applied and rolled back without error.\n";
    } catch (mysqli_sql_exception $e) {
        echo 'Dry run FAILED: ', $e->getMessage(), "\n";
        exit(1);
    } finally {
        foreach ($links as $link) { try { $link->rollback(); } catch (Throwable $ignored) {} }
    }
}
exit(0);
