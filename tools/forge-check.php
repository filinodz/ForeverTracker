<?php
// Dry run of the Forge with the website's own engine: plans the fixes against the databases as they are now,
// applies them, runs the rollback, checks that every inserted row is gone, then rolls everything back.
// Nothing is kept.
//
//   php tools/forge-check.php [hotfix|trainer|instance] [--each]
//
// Without --each the whole list is checked as one selection (what "select all" does on the Forge page);
// with --each every fix is also checked alone, so a failure names its fix.
if (PHP_SAPI !== 'cli') exit;
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/app/bootstrap.php';
require FT_APP . '/forge.php';

$kind = in_array($argv[1] ?? '', FORGE_KINDS, true) ? $argv[1] : null;
$each = in_array('--each', $argv, true);
$list = rows('SELECT type, id, kind FROM ft_fixes' . ($kind ? ' WHERE kind = ?' : '') . ' ORDER BY kind, type, id', $kind ? [$kind] : []);
$list = array_map(fn($f) => ['type' => $f['type'], 'id' => (int)$f['id'], 'kind' => $f['kind']], $list);

/** Applies a plan, then its rollback, inside transactions that are always rolled back. Returns an error or ''. */
function dry_run(array $built): string
{
    $links = [];
    try {
        foreach ($built['plans'] as $database => $plan) {
            $link = db($database);
            $link->begin_transaction();
            $links[] = $link;
            foreach ($plan['apply'] as $statement) $link->query($statement);
        }
        foreach ($built['tables'] as $name => [$insert]) {
            if (!$insert) continue;
            [$database, $table] = explode('.', $name, 2);
            $before[$name] = (int)value('SELECT COUNT(*) FROM ' . forge_ident($table), [], $database);
        }
        foreach ($built['plans'] as $database => $plan) foreach ($plan['rollback'] as $statement) db($database)->query($statement);
        foreach ($built['tables'] as $name => [$insert]) {
            if (!$insert) continue;
            [$database, $table] = explode('.', $name, 2);
            $removed = $before[$name] - (int)value('SELECT COUNT(*) FROM ' . forge_ident($table), [], $database);
            if ($removed !== $insert) return "$name: inserted $insert row(s), the rollback removed $removed";
        }
        return '';
    } catch (mysqli_sql_exception $e) {
        return $e->getMessage();
    } finally {
        foreach ($links as $link) { try { $link->rollback(); } catch (Throwable $ignored) {} }
    }
}

$failed = 0;
$started = microtime(true);
foreach (array_chunk($list, 2000) as $chunk) {
    $built = forge_build($chunk, ['push' => true]);
    $error = dry_run($built);
    printf("%d fix(es): %d row(s) to insert, %d already present, %d shared%s\n", count($chunk), $built['rows'], $built['skipped'], $built['duplicates'], $error ? "\nFAIL $error" : ' · OK');
    foreach ($built['warnings'] as $warning) echo "  - $warning\n";
    if ($error) $failed++;
}
if ($each) {
    foreach ($list as $fix) {
        $error = dry_run(forge_build([$fix], ['push' => true]));
        if ($error) { $failed++; echo "FAIL {$fix['kind']} {$fix['type']} {$fix['id']}: $error\n"; }
    }
}
echo json_encode(['checked' => count($list), 'failed' => $failed, 'seconds' => round(microtime(true) - $started, 1)]), "\n";
exit($failed ? 1 : 0);
