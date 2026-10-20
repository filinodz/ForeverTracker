<?php
// The Forge · Imports: world data in WowPacketParser's SQL format, merged safely into the server.
require_once FT_APP . '/imports.php';
import_schema();
forge_schema();
$dir = import_dir();
@mkdir($dir, 0775, true);
$imports = rows('SELECT s.*, (SELECT COUNT(DISTINCT item) FROM ft_import_items i WHERE i.import_id = s.id) items FROM ft_imports s ORDER BY s.id DESC');
$importedFiles = array_flip(array_column($imports, 'file'));
$waiting = [];
foreach (array_merge(glob($dir . '/*.sql') ?: [], glob($dir . '/*.lua') ?: []) as $path) if (!isset($importedFiles[basename($path)])) $waiting[] = ['file' => basename($path), 'size' => filesize($path), 'time' => filemtime($path)];
require_once FT_APP . '/collector.php';
collector_schema();
$scribeFiles = collector_files();
$scribeState = is_file(FT_ROOT . '/data/collector-state.json') ? (json_decode((string)file_get_contents(FT_ROOT . '/data/collector-state.json'), true) ?: []) : [];
$located = (int)value('SELECT COUNT(*) FROM ft_collected');
$clientAddons = (function () { require_once FT_APP . '/spawns.php'; return spawn_client_addons(); })();
$scribeInstalled = $clientAddons !== '' && is_file($clientAddons . '/ForeverScribe/ForeverScribe.toc');
$tableLabels = [
    'creature' => t('Creature spawns', 'Placements de PNJ'), 'gameobject' => t('Object spawns', 'Placements d’objets'), 'creature_template' => t('New creatures', 'Nouveaux PNJ'),
    'gameobject_template' => t('New objects', 'Nouveaux objets du monde'), 'creature_loot_template' => t('Creature loot', 'Butin des PNJ'), 'gameobject_loot_template' => t('Chest loot', 'Butin des coffres'),
    'npc_vendor' => t('Vendors', 'Marchands'), 'quest_template' => t('Quests', 'Quêtes'), 'creature_queststarter' => t('Quest givers', 'Donneurs de quête'),
    'creature_questender' => t('Quest enders', 'Valideurs de quête'), 'trainer_spell' => t('Trainers', 'Entraîneurs'), 'gossip_menu_option' => t('Gossip options', 'Options de dialogue'),
    'gameobject_queststarter' => t('Quest givers (objects)', 'Donneurs de quête (objets)'), 'gameobject_questender' => t('Quest enders (objects)', 'Valideurs de quête (objets)'),
];
$leftLabels = [
    'vendor_unknown' => t('vendors unknown to this server', 'marchands inconnus de ce serveur'), 'cost_unknown' => t('items paid with items or currencies (no known ExtendedCost)', 'objets payés en objets ou monnaies (ExtendedCost inconnu)'),
    'loot_unknown' => t('creatures unknown to this server', 'PNJ inconnus de ce serveur'), 'object_no_loot' => t('objects without a loot table', 'objets sans table de butin'),
    'quest_unknown' => t('quests unknown to this server', 'quêtes inconnues de ce serveur'),
];
$megabytes = fn(int $bytes) => number($bytes / 1048576, 1) . ' MB';
page_start(t('Imports', 'Imports'), 'admin');
?>
<section class="page-head is-forge">
  <div class="page-title">
    <span class="page-icon is-gold"><i class="fas fa-file-import"></i></span>
    <div><span class="eyebrow"><a href="<?= e(url('admin')) ?>"><?= e(t('The Forge', 'La Forge')) ?></a></span><h1><?= e(t('Imports', 'Imports')) ?></h1>
    <p><?= e(t('What the ForeverScribe addon noted while you played, and world data files in WowPacketParser\'s SQL format: vendors, loot, quest givers, spawns. ForeverTracker adds only what your server lacks — nothing is deleted or overwritten — and every operation can be rolled back.', 'Ce que l’addon ForeverScribe a noté pendant que vous jouiez, et les fichiers de données du monde au format SQL de WowPacketParser : marchands, butin, donneurs de quête, placements. ForeverTracker n’ajoute que ce qui manque à votre serveur (rien n’est supprimé ni écrasé) et chaque opération peut être annulée.')) ?></p></div>
  </div>
</section>

<nav class="tabs" aria-label="Forge">
  <a href="<?= e(url('admin')) ?>"><i class="fas fa-hammer"></i><?= e(t('Fixes', 'Corrections')) ?></a>
  <a href="<?= e(url('admin', ['tab' => 'vendor'])) ?>"><i class="fas fa-gem"></i><?= e(t('Items', 'Objets')) ?></a>
  <a href="<?= e(url('admin/spawns')) ?>"><i class="fas fa-location-crosshairs"></i><?= e(t('Spawns', 'Placements')) ?></a>
  <a class="is-active" href="<?= e(url('admin/imports')) ?>"><i class="fas fa-file-import"></i><?= e(t('Imports', 'Imports')) ?><em><?= count($imports) ?></em></a>
  <a href="<?= e(url('admin/history')) ?>"><i class="fas fa-clock-rotate-left"></i><?= e(t('History', 'Historique')) ?></a>
</nav>

<section class="block import-add">
  <header class="block-head"><div><span class="eyebrow"><?= e(t('Collect while you play', 'Collecter en jouant')) ?></span><h2><?= e(t('The ForeverScribe addon', 'L’addon ForeverScribe')) ?></h2></div></header>
  <ol class="steps-list">
    <li><?= e(t('Install ForeverScribe in your game. It only uses the normal addon API: it notes what the game shows you — what vendors sell, what creatures and chests drop, who gives and takes quests, where creatures stand. No character, realm or account name is kept.', 'Installez ForeverScribe dans votre jeu. Il n’utilise que l’API normale des addons : il note ce que le jeu vous montre — ce que vendent les marchands, ce que lâchent les PNJ et les coffres, qui donne et valide les quêtes, où se tiennent les PNJ. Aucun nom de personnage, de royaume ou de compte n’est gardé.')) ?></li>
    <li><?= e(t('Play: open vendors, loot, talk to quest givers. /scribe shows what was noted. The notes are saved when you log out or /reload.', 'Jouez : ouvrez les marchands, ramassez le butin, parlez aux donneurs de quête. /scribe montre ce qui a été noté. Les notes sont enregistrées quand vous vous déconnectez ou faites /reload.')) ?></li>
    <li><?= e(t('ForeverTracker imports the new notes by itself while the launcher runs, or press Import new notes. Vendors, loot and quest givers become an import below; where creatures stand feeds the locator (Spawns).', 'ForeverTracker importe les nouvelles notes tout seul tant que le lanceur tourne, ou appuyez sur Importer les nouvelles notes. Marchands, butin et donneurs de quête deviennent un import ci-dessous ; les positions des PNJ alimentent le localisateur (Placements).')) ?></li>
  </ol>
  <div class="btn-row">
    <button class="btn is-small" type="button" data-scribe="scribe_install" <?= $clientAddons === '' ? 'disabled' : '' ?>><i class="fas fa-download"></i> <?= e($scribeInstalled ? t('Update the addon in the game', 'Mettre à jour l’addon dans le jeu') : t('Install into the game', 'Installer dans le jeu')) ?></button>
    <button class="btn is-small" type="button" data-scribe="scribe_zip"><i class="fas fa-file-zipper"></i> <?= e(t('Download the addon', 'Télécharger l’addon')) ?></button>
    <button class="btn is-small is-gold" type="button" data-scribe="sync"><i class="fas fa-rotate"></i> <?= e(t('Import new notes', 'Importer les nouvelles notes')) ?></button>
  </div>
  <ul class="list is-compact import-waiting">
    <?php foreach ($scribeFiles as $f): $new = ($scribeState[$f['path']] ?? '') !== sha1_file($f['path']); ?><li><span class="fix-kind is-small"><i class="fas fa-feather"></i></span><div class="list-main"><strong>ForeverScribe · <?= e($f['flavor']) ?></strong><small><?= e(date(t('M j, H:i', 'd/m H:i'), $f['modified'])) ?> · <?= e($new ? t('new notes to import', 'nouvelles notes à importer') : t('imported', 'importé')) ?></small></div></li><?php endforeach; ?>
    <?php if (!$scribeFiles): ?><li class="muted small"><?= e($clientAddons === '' ? t('Set [client] path in config.ini to find the notes in your game folder, or send ForeverScribe.lua below.', 'Renseignez [client] path dans config.ini pour trouver les notes dans le dossier du jeu, ou envoyez ForeverScribe.lua ci-dessous.') : t('No notes yet: play with the addon, then log out or /reload.', 'Pas encore de notes : jouez avec l’addon, puis déconnectez-vous ou faites /reload.')) ?></li><?php endif; ?>
    <?php if ($located): ?><li class="muted small"><?= e(sprintf(t('%d creature(s) or object(s) located by ForeverScribe: run the locator again (Spawns) to use them.', '%d PNJ ou objet(s) localisés par ForeverScribe : relancez le localisateur (Placements) pour les utiliser.'), $located)) ?></li><?php endif; ?>
  </ul>
</section>

<section class="block import-add">
  <header class="block-head"><div><span class="eyebrow"><?= e(t('Add a file', 'Ajouter un fichier')) ?></span><h2><?= e(t('SQL in WowPacketParser\'s format', 'SQL au format de WowPacketParser')) ?></h2></div></header>
  <ol class="steps-list">
    <li><?= e(t('Use a SQL file you already have in WowPacketParser\'s world format (usually ending in _world.sql), or any file of INSERT statements for your world tables.', 'Utilisez un fichier SQL que vous avez déjà, au format monde de WowPacketParser (il finit souvent par _world.sql), ou tout fichier d’INSERT pour vos tables du monde.')) ?></li>
    <li><?= e(sprintf(t('Send it here, or copy big files into %s and import them from the list below.', 'Envoyez-le ici, ou copiez les gros fichiers dans %s et importez-les depuis la liste ci-dessous.'), str_replace('\\', '/', $dir))) ?></li>
    <li><?= e(t('Preview what your server lacks, then apply it.', 'Prévisualisez ce qui manque à votre serveur, puis appliquez-le.')) ?></li>
  </ol>
  <form class="import-upload" data-import-upload>
    <label class="btn"><i class="fas fa-file-arrow-up"></i> <?= e(t('Choose a .sql or ForeverScribe.lua file', 'Choisir un fichier .sql ou ForeverScribe.lua')) ?><input type="file" accept=".sql,.lua" hidden></label>
    <small class="muted"><?= e(sprintf(t('Up to %s through the browser.', 'Jusqu’à %s par le navigateur.'), (string)ini_get('upload_max_filesize'))) ?></small>
  </form>
  <?php if ($waiting): ?>
  <ul class="list is-compact import-waiting">
    <?php foreach ($waiting as $w): ?><li><span class="fix-kind is-small"><i class="fas fa-file-code"></i></span><div class="list-main"><strong><?= e($w['file']) ?></strong><small><?= e($megabytes($w['size'])) ?> · <?= e(date(t('M j, H:i', 'd/m H:i'), $w['time'])) ?></small></div><button class="btn is-small is-gold" type="button" data-import-file="<?= e($w['file']) ?>"><i class="fas fa-file-import"></i> <?= e(t('Import', 'Importer')) ?></button></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<?php if (!$imports): ?>
  <div class="empty-state is-inline"><div class="empty-icon"><i class="fas fa-file-import"></i></div><p><?= e(t('No file imported yet.', 'Aucun fichier importé pour le moment.')) ?></p></div>
<?php endif; ?>
<?php foreach ($imports as $s): $counts = json_decode((string)$s['counts'], true) ?: []; $ignored = json_decode((string)$s['ignored'], true) ?: []; ?>
<article class="block import-card" data-import="<?= (int)$s['id'] ?>">
  <header class="op-head">
    <span class="fix-kind is-import"><i class="fas fa-file-import"></i></span>
    <div><strong>#<?= (int)$s['id'] ?> · <?= e($s['name']) ?></strong>
      <small><?= e($s['file']) ?> · <?= e(date(t('M j, Y H:i', 'd/m/Y H:i'), strtotime($s['imported_at']))) ?> · <?= number(array_sum($counts)) ?> <?= e(t('rows', 'lignes')) ?> · <?= number((int)$s['items']) ?> <?= e(t('item(s) made obtainable', 'objet(s) rendus obtenables')) ?></small></div>
    <button class="icon-btn is-small" type="button" data-import-delete title="<?= e(t('Forget this import (the server is not changed)', 'Oublier cet import (le serveur n’est pas modifié)')) ?>"><i class="fas fa-trash"></i></button>
  </header>
  <div class="import-tables">
    <?php foreach ($counts as $table => $n): ?>
      <label class="checkbox-line"><input type="checkbox" value="<?= e($table) ?>" checked> <span><?= e($tableLabels[$table] ?? $table) ?></span> <small class="muted"><?= e($table) ?> · <?= number($n) ?></small></label>
    <?php endforeach; ?>
  </div>
  <?php if ($ignored): ?><p class="muted small"><?= e(t('Not on this server (left out):', 'Absent de ce serveur (écarté) :')) ?> <?= e(implode(', ', array_map(fn($t, $n) => ($leftLabels[$t] ?? $t) . " ($n)", array_keys($ignored), $ignored))) ?></p><?php endif; ?>
  <div class="btn-row">
    <button class="btn is-small" type="button" data-import-preview><i class="fas fa-eye"></i> <?= e(t('Preview', 'Aperçu')) ?></button>
    <button class="btn is-small is-gold" type="button" data-import-apply><i class="fas fa-bolt"></i> <?= e(t('Apply what is missing', 'Appliquer ce qui manque')) ?></button>
  </div>
</article>
<?php endforeach; ?>

<section class="block">
  <header class="block-head"><div><span class="eyebrow"><?= e(t('How it merges', 'Comment la fusion se fait')) ?></span><h2><?= e(t('Safe by design', 'Sûr par conception')) ?></h2></div></header>
  <ul class="confirm-points">
    <li><i class="fas fa-shield-halved"></i><?= e(t('Only INSERT rows are read; DELETE and UPDATE statements of the file are never run.', 'Seules les lignes INSERT sont lues ; les DELETE et UPDATE du fichier ne sont jamais exécutés.')) ?></li>
    <li><i class="fas fa-code-merge"></i><?= e(t('Rows your server already has are left out; spawns get new guids, and a spawn that already stands within 3 yards is left out with its addon.', 'Les lignes déjà présentes sont écartées ; les placements reçoivent de nouveaux guid, et un placement qui existe déjà à moins de 3 mètres est écarté avec son addon.')) ?></li>
    <li><i class="fas fa-sack-dollar"></i><?= e(t('Loot works at once: a creature whose LootID is 0 gets its own loot table.', 'Le butin fonctionne aussitôt : un PNJ dont le LootID vaut 0 reçoit sa propre table de butin.')) ?></li>
    <li><i class="fas fa-rotate-left"></i><?= e(t('Columns your core does not have are dropped. The history keeps the exact SQL and a rollback.', 'Les colonnes que votre core n’a pas sont ignorées. L’historique garde le SQL exact et l’annulation.')) ?></li>
  </ul>
</section>
<?php require FT_APP . '/pages/admin/_modals.php'; ?>
<?php page_end();
