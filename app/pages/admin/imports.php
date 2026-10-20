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
foreach (glob($dir . '/*.sql') ?: [] as $path) if (!isset($importedFiles[basename($path)])) $waiting[] = ['file' => basename($path), 'size' => filesize($path), 'time' => filemtime($path)];
$tableLabels = [
    'creature' => t('Creature spawns', 'Placements de PNJ'), 'gameobject' => t('Object spawns', 'Placements d’objets'), 'creature_template' => t('New creatures', 'Nouveaux PNJ'),
    'gameobject_template' => t('New objects', 'Nouveaux objets du monde'), 'creature_loot_template' => t('Creature loot', 'Butin des PNJ'), 'gameobject_loot_template' => t('Chest loot', 'Butin des coffres'),
    'npc_vendor' => t('Vendors', 'Marchands'), 'quest_template' => t('Quests', 'Quêtes'), 'creature_queststarter' => t('Quest givers', 'Donneurs de quête'),
    'creature_questender' => t('Quest enders', 'Valideurs de quête'), 'trainer_spell' => t('Trainers', 'Entraîneurs'), 'gossip_menu_option' => t('Gossip options', 'Options de dialogue'),
];
$megabytes = fn(int $bytes) => number($bytes / 1048576, 1) . ' MB';
page_start(t('Imports', 'Imports'), 'admin');
?>
<section class="page-head is-forge">
  <div class="page-title">
    <span class="page-icon is-gold"><i class="fas fa-file-import"></i></span>
    <div><span class="eyebrow"><a href="<?= e(url('admin')) ?>"><?= e(t('The Forge', 'La Forge')) ?></a></span><h1><?= e(t('Imports', 'Imports')) ?></h1>
    <p><?= e(t('World data files in WowPacketParser\'s SQL format: spawns with their positions, loot, vendors, quests, gossip. ForeverTracker adds only what your server lacks — nothing is deleted or overwritten — and every operation can be rolled back.', 'Fichiers de données du monde au format SQL de WowPacketParser : placements et leur position, butin, marchands, quêtes, dialogues. ForeverTracker n’ajoute que ce qui manque à votre serveur (rien n’est supprimé ni écrasé) et chaque opération peut être annulée.')) ?></p></div>
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
  <header class="block-head"><div><span class="eyebrow"><?= e(t('Add a file', 'Ajouter un fichier')) ?></span><h2><?= e(t('SQL in WowPacketParser\'s format', 'SQL au format de WowPacketParser')) ?></h2></div></header>
  <ol class="steps-list">
    <li><?= e(t('Use a SQL file you already have in WowPacketParser\'s world format (usually ending in _world.sql), or any file of INSERT statements for your world tables.', 'Utilisez un fichier SQL que vous avez déjà, au format monde de WowPacketParser (il finit souvent par _world.sql), ou tout fichier d’INSERT pour vos tables du monde.')) ?></li>
    <li><?= e(sprintf(t('Send it here, or copy big files into %s and import them from the list below.', 'Envoyez-le ici, ou copiez les gros fichiers dans %s et importez-les depuis la liste ci-dessous.'), str_replace('\\', '/', $dir))) ?></li>
  </ol>
  <form class="import-upload" data-import-upload>
    <label class="btn"><i class="fas fa-file-arrow-up"></i> <?= e(t('Choose a .sql file', 'Choisir un fichier .sql')) ?><input type="file" accept=".sql" hidden></label>
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
  <?php if ($ignored): ?><p class="muted small"><?= e(t('Not on this server (left out):', 'Absent de ce serveur (écarté) :')) ?> <?= e(implode(', ', array_map(fn($t, $n) => "$t ($n)", array_keys($ignored), $ignored))) ?></p><?php endif; ?>
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
