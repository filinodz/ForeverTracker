<?php
// Imports the ForeverScribe notes of the game client ([client] path) that changed since their last import, the same
// way as The Forge > Imports > Import new notes. The launcher runs it every minute while the website runs.
//
//   php tools/collector-sync.php [--apply] [--quiet]
//
// --apply (or [collector] auto_apply = 1 in config.ini) also applies what the server lacks: vendors, loot, quest givers
// and enders, each import recorded in the history with its rollback. Where creatures stand is used by the locator.
if (PHP_SAPI !== 'cli') exit;
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/app/bootstrap.php';
require FT_APP . '/collector.php';

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true) || (int)config('collector', 'auto_apply', 0) === 1;
$quiet = in_array('--quiet', $args, true);
forge_schema();
$report = collector_sync($apply);
foreach ($report as $line) echo $line, "\n";
if (!$report && !$quiet) echo "No new ForeverScribe notes.\n";
exit(0);
