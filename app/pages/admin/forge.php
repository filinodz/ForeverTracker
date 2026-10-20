<?php
// The Forge (administrators): create what the server lacks, in bulk, with SQL preview and rollback.
require_once FT_APP . '/forge.php';
forge_schema();
$tabs = ['vendor' => ['fa-gem', t('Items', 'Objets'), t('Every item players cannot get on your server, with the reason. Fix them with the sources found in an imported WowPacketParser file (loot, vendor, quest reward), by spawning their source (Spawns), or by selling them at a vendor you choose.', 'Chaque objet que les joueurs ne peuvent pas obtenir sur votre serveur, avec la raison. Corrigez-les avec les sources trouvées dans un fichier WowPacketParser importé (butin, marchand, récompense de quête), en plaçant leur source (Placements), ou en les faisant vendre par le marchand de votre choix.')],
         'hotfix' => ['fa-database', t('Core data', 'Données du core'), t('Copies the official records the core does not know into the hotfixes database (items, spells, mounts, pets, toys, achievements…). Restart the worldserver afterwards.', 'Copie dans la base hotfixes les enregistrements officiels que le core ne connaît pas (objets, sorts, montures, mascottes, jouets, hauts faits…). Redémarrez le worldserver ensuite.')],
         'trainer' => ['fa-chalkboard-user', t('Trainers', 'Entraîneurs'), t('Teaches class and profession spells nobody teaches, at the spawned trainers of the same skill line, with a level and a price taken from their neighbours.', 'Enseigne les sorts de classe et de métier que personne n’enseigne, chez les entraîneurs placés de la même spécialité, avec un niveau et un prix pris sur leurs voisins.')],
         'instance' => ['fa-dungeon', t('Instances', 'Instances'), t('Creates the instance_template row of dungeons and raids, the first step before spawns and an entrance.', 'Crée la ligne instance_template des donjons et raids, première étape avant les PNJ et l’entrée.')]];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'vendor';
$importedOnly = !empty($_GET['imported']);
$hasImports = table_exists('ft_import_items');
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 60));
$filterType = isset(TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$onlyNew = !empty($_GET['new']);
$showInternal = !empty($_GET['internal']);
$quality = isset($_GET['quality']) && $_GET['quality'] !== '' ? (int)$_GET['quality'] : null;
$teachesOnly = !empty($_GET['teaches']);
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 100;

$internalSql = $showInternal ? '' : ' AND e.internal = 0';
$tabCounts = [];
foreach (rows("SELECT f.kind, COUNT(*) n FROM ft_fixes f JOIN ft_entities e USING (type, id) WHERE 1$internalSql GROUP BY f.kind") as $x) $tabCounts[$x['kind']] = (int)$x['n'];
$sourceFailing = "JSON_CONTAINS(e.checks, JSON_OBJECT('code', 'source', 'ok', false))";
$tabCounts['vendor'] = (int)value("SELECT COUNT(*) FROM ft_entities e WHERE e.type = 'item' AND $sourceFailing$internalSql");

$where = []; $params = [];
if ($q !== '') {
    if (ctype_digit($q)) { $where[] = 'e.id = ?'; $params[] = (int)$q; }
    else { $where[] = '(e.name LIKE ? OR e.name_en LIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
}
if ($onlyNew) $where[] = 'e.is_new = 1';
if (!$showInternal) $where[] = 'e.internal = 0';
$typeOptions = [];
if ($tab === 'vendor') {
    $where[] = "e.type = 'item'";
    $where[] = $sourceFailing;
    if ($quality !== null) { $where[] = 'e.quality = ?'; $params[] = $quality; }
    if ($teachesOnly) {
        $taught = [];
        foreach (rows("SELECT type, data FROM ft_entities WHERE type IN ('mount','pet','recipe','toy','spell') AND status <> 'ok'") as $x) {
            $d = json_decode($x['data'], true) ?: [];
            foreach ((array)($d['teach'] ?? []) as $item) $taught[(int)$item] = true;
            if ($x['type'] === 'toy' && !empty($d['item'])) $taught[(int)$d['item']] = true;
        }
        $where[] = $taught ? 'e.id IN (' . implode(',', array_keys($taught)) . ')' : '0';
    }
    if ($importedOnly) $where[] = $hasImports ? 'e.id IN (SELECT item FROM ft_import_items)' : '0';
    $whereSql = implode(' AND ', $where);
    $total = (int)value("SELECT COUNT(*) FROM ft_entities e WHERE $whereSql", $params);
    $list = rows("SELECT e.type, e.id, 'vendor' kind, e.name, e.name_en, e.icon, e.quality, e.status, e.score, e.is_new, e.internal, e.level, e.checks FROM ft_entities e
        WHERE $whereSql ORDER BY e.is_new DESC, e.quality DESC, e.level DESC, e.name LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
    // what the imported imports know about these items: [item => [[kind, source, name]]]
    $importSources = [];
    if ($hasImports && $list) {
        $found = rows('SELECT DISTINCT item, kind, source FROM ft_import_items WHERE item IN (' . implode(',', array_map(fn($x) => (int)$x['id'], $list)) . ') ORDER BY kind LIMIT 2000');
        $names = [];
        $npcs = array_unique(array_map(fn($f) => (int)$f['source'], array_filter($found, fn($f) => in_array($f['kind'], ['loot', 'vendor'], true))));
        if ($npcs) foreach (rows('SELECT entry, name FROM creature_template WHERE entry IN (' . implode(',', $npcs) . ')', [], 'world') as $n) $names['npc' . $n['entry']] = $n['name'];
        $objects = array_unique(array_map(fn($f) => (int)$f['source'], array_filter($found, fn($f) => $f['kind'] === 'object')));
        if ($objects) foreach (rows('SELECT entry, name FROM gameobject_template WHERE entry IN (' . implode(',', $objects) . ')', [], 'world') as $n) $names['object' . $n['entry']] = $n['name'];
        $quests = array_unique(array_map(fn($f) => (int)$f['source'], array_filter($found, fn($f) => $f['kind'] === 'quest')));
        if ($quests) foreach (rows("SELECT id, name, name_en FROM ft_entities WHERE type = 'quest' AND id IN (" . implode(',', $quests) . ')') as $n) $names['quest' . $n['id']] = display_name($n);
        foreach ($found as $f) {
            $key = ($f['kind'] === 'quest' ? 'quest' : ($f['kind'] === 'object' ? 'object' : 'npc')) . $f['source'];
            $importSources[(int)$f['item']][] = [$f['kind'], (int)$f['source'], $names[$key] ?? ('#' . $f['source'])];
        }
    }
    $importedCount = $hasImports ? (int)value("SELECT COUNT(DISTINCT s.item) FROM ft_import_items s JOIN ft_entities e ON e.type = 'item' AND e.id = s.item WHERE $sourceFailing" . ($showInternal ? '' : ' AND e.internal = 0')) : 0;
} else {
    $where[] = 'f.kind = ?'; $params[] = $tab;
    if ($filterType !== '') { $where[] = 'f.type = ?'; $params[] = $filterType; }
    $whereSql = implode(' AND ', $where);
    foreach (rows("SELECT f.type, COUNT(*) n FROM ft_fixes f JOIN ft_entities e USING (type, id) WHERE f.kind = ?$internalSql GROUP BY f.type", [$tab]) as $x) $typeOptions[$x['type']] = (int)$x['n'];
    $total = (int)value("SELECT COUNT(*) FROM ft_fixes f JOIN ft_entities e USING (type, id) WHERE $whereSql", $params);
    $list = rows("SELECT f.type, f.id, f.kind, f.db, f.rows_count, f.needs, f.summary, e.name, e.name_en, e.icon, e.quality, e.status, e.score, e.is_new, e.internal, e.level
        FROM ft_fixes f JOIN ft_entities e USING (type, id) WHERE $whereSql ORDER BY e.is_new DESC, FIELD(f.type,'dungeon','talent','spell','recipe','mount','pet','toy','achievement','item'), e.name
        LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
}
// fixes applied since the last analysis
$applied = [];
$since = (string)(meta()['generated_at'] ?? '1970-01-01');
foreach (rows("SELECT items FROM ft_forge_log WHERE state = 'applied' AND created_at >= ?", [str_replace('T', ' ', $since)]) as $x)
    foreach (json_decode($x['items'], true) ?: [] as $item) $applied[$item['type'] . ':' . $item['id'] . ':' . $item['kind']] = true;
$recent = rows('SELECT id, created_at, admin, kind, rows_count, state FROM ft_forge_log ORDER BY id DESC LIMIT 4');
$status = analysis_status();
$query = fn(array $change) => '?' . http_build_query(array_filter(array_merge(['tab' => $tab, 'q' => $q, 'type' => $filterType, 'new' => $onlyNew ? 1 : '', 'internal' => $showInternal ? 1 : '',
    'quality' => $quality, 'teaches' => $teachesOnly ? 1 : '', 'imported' => $importedOnly ? 1 : ''], $change), fn($v) => $v !== '' && $v !== null));
$qualities = [0 => t('Poor', 'Médiocre'), 1 => t('Common', 'Commun'), 2 => t('Uncommon', 'Inhabituel'), 3 => t('Rare', 'Rare'), 4 => t('Epic', 'Épique'), 5 => t('Legendary', 'Légendaire')];
page_start(t('The Forge', 'La Forge'), 'admin');
?>
<section class="page-head is-forge">
  <div class="page-title">
    <span class="page-icon is-gold"><i class="fas fa-hammer"></i></span>
    <div><span class="eyebrow"><?= e(t('Administration', 'Administration')) ?> · <?= e(admin()['name']) ?></span><h1><?= e(t('The Forge', 'La Forge')) ?></h1>
    <p><?= e(t('Creates what your server lacks from the official data, in one click. Every change is previewed as SQL, applied in a transaction and can be rolled back from the history.', 'Crée ce qui manque à votre serveur à partir des données officielles, en un clic. Chaque changement est prévisualisé en SQL, appliqué dans une transaction et peut être annulé depuis l’historique.')) ?></p></div>
  </div>
  <div class="analysis-card" data-analysis data-running="<?= $status['running'] ? 1 : 0 ?>">
    <div><strong><i class="fas fa-rotate"></i> <?= e(t('Analysis', 'Analyse')) ?></strong>
      <small data-analysis-text><?= $status['running'] ? e(t('Running…', 'En cours…')) : e(sprintf(t('Last run: %s', 'Dernière : %s'), isset(meta()['generated_at']) ? date(t('M j, H:i', 'd/m H:i'), strtotime((string)meta()['generated_at'])) : '—')) ?></small></div>
    <button class="btn is-small" type="button" data-analysis-start <?= $status['running'] ? 'disabled' : '' ?>><i class="fas fa-play"></i> <?= e(t('Run again', 'Relancer')) ?></button>
    <pre class="analysis-log" data-analysis-log <?= $status['log'] === '' ? 'hidden' : '' ?>><?= e($status['log']) ?></pre>
  </div>
</section>

<nav class="tabs" aria-label="Forge">
  <?php foreach ($tabs as $key => [$icon, $label]): ?>
    <a class="<?= $tab === $key ? 'is-active' : '' ?>" href="<?= e(url('admin', ['tab' => $key])) ?>"><i class="fas <?= $icon ?>"></i><?= e($label) ?><em><?= number($tabCounts[$key] ?? 0) ?></em></a>
  <?php endforeach; ?>
  <a href="<?= e(url('admin/spawns')) ?>"><i class="fas fa-location-crosshairs"></i><?= e(t('Spawns', 'Placements')) ?><?php if (table_exists('ft_spawns')): ?><em><?= number((int)value("SELECT COUNT(*) FROM ft_spawns WHERE state IN ('ready','manual')")) ?></em><?php endif; ?></a>
  <a href="<?= e(url('admin/imports')) ?>"><i class="fas fa-file-import"></i><?= e(t('Imports', 'Imports')) ?><?php if (table_exists('ft_imports')): ?><em><?= number((int)value('SELECT COUNT(*) FROM ft_imports')) ?></em><?php endif; ?></a>
  <a href="<?= e(url('admin/history')) ?>"><i class="fas fa-clock-rotate-left"></i><?= e(t('History', 'Historique')) ?></a>
  <a href="<?= e(url('admin/notes')) ?>"><i class="fas fa-pen-to-square"></i><?= e(t('Patch notes', 'Notes de patch')) ?></a>
</nav>
<p class="tab-desc"><i class="fas fa-circle-info"></i> <?= e($tabs[$tab][2]) ?></p>

<?php if ($tab === 'vendor'): ?>
<section class="vendor-pick" data-vendor-pick>
  <div class="vendor-pick-head"><span class="page-icon is-small"><i class="fas fa-store"></i></span><div><strong><?= e(t('Vendor', 'Marchand')) ?></strong><small><?= e(t('Search a vendor by name, or type its creature_template entry.', 'Cherchez un marchand par son nom, ou tapez son entry de creature_template.')) ?></small></div></div>
  <label class="field is-search is-grow"><i class="fas fa-magnifying-glass"></i><input type="search" data-vendor-q placeholder="<?= e(t('Name or entry…', 'Nom ou entry…')) ?>" value="<?= (int)config('admin', 'vendor_entry', 0) ?: '' ?>" autocomplete="off"></label>
  <div class="vendor-results" data-vendor-results></div>
  <div class="vendor-chosen" data-vendor-chosen hidden></div>
</section>
<?php endif; ?>

<form class="toolbar" method="get" data-autosubmit-form>
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <label class="field is-search"><i class="fas fa-magnifying-glass"></i><input name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Filter by name or ID…', 'Filtrer par nom ou ID…')) ?>"></label>
  <?php if ($typeOptions && count($typeOptions) > 1): ?>
  <label class="field is-select"><span><?= e(t('Type', 'Type')) ?></span><select name="type" data-autosubmit><option value=""><?= e(t('All', 'Tous')) ?></option>
    <?php foreach ($typeOptions as $ty => $n): ?><option value="<?= e($ty) ?>" <?= $filterType === $ty ? 'selected' : '' ?>><?= e(type_label($ty)) ?> (<?= $n ?>)</option><?php endforeach; ?></select></label>
  <?php endif; ?>
  <?php if ($tab === 'vendor'): ?>
  <label class="field is-select"><span><?= e(t('Quality', 'Qualité')) ?></span><select name="quality" data-autosubmit><option value=""><?= e(t('All', 'Toutes')) ?></option>
    <?php foreach ($qualities as $value => $label): ?><option value="<?= $value ?>" <?= $quality === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
  <label class="toggle"><input type="checkbox" name="teaches" value="1" <?= $teachesOnly ? 'checked' : '' ?> data-autosubmit><span></span><?= e(t('Teaches a recipe, mount, pet or toy', 'Apprend une recette, monture, mascotte ou jouet')) ?></label>
  <label class="toggle"><input type="checkbox" name="imported" value="1" <?= $importedOnly ? 'checked' : '' ?> data-autosubmit><span></span><?= e(t('Found in an import', 'Trouvé dans un import')) ?> (<?= number($importedCount) ?>)</label>
  <?php endif; ?>
  <label class="toggle"><input type="checkbox" name="new" value="1" <?= $onlyNew ? 'checked' : '' ?> data-autosubmit><span></span><?= e(t('New in Forever', 'Nouveau dans Forever')) ?></label>
  <label class="toggle"><input type="checkbox" name="internal" value="1" <?= $showInternal ? 'checked' : '' ?> data-autosubmit><span></span><?= e(t('Internal / test', 'Interne / test')) ?></label>
</form>

<p class="result-count"><b><?= number($total) ?></b> <?= e($tab === 'vendor' ? t('items nobody can get', 'objets que personne ne peut obtenir') : t('fixes', 'corrections')) ?><?php if ($tab === 'vendor' && !$importedCount): ?> · <a class="link" href="<?= e(url('admin/imports')) ?>"><?= e(t('Import WowPacketParser files to find their sources', 'Importez des fichiers WowPacketParser pour trouver leurs sources')) ?> →</a><?php endif; ?></p>
<?php if (!$list): ?>
  <div class="empty-state is-inline"><div class="empty-icon"><i class="fas fa-champagne-glasses"></i></div><p><?= e(t('Nothing to forge here.', 'Rien à forger ici.')) ?></p></div>
<?php else: ?>
<div class="table-card" data-forge-table data-tab="<?= e($tab) ?>">
<table class="table is-forge">
  <thead><tr><th class="check-col"><label class="checkbox"><input type="checkbox" data-select-all><span></span></label></th><th><?= e(t('Resource', 'Ressource')) ?></th><th><?= e($tab === 'vendor' ? t('Why, and the official sources', 'Pourquoi, et les sources officielles') : t('Fix', 'Correction')) ?></th><th><?= e(t('Status', 'Statut')) ?></th><th class="num"><?= e($tab === 'vendor' ? t('Sources', 'Sources') : t('Rows', 'Lignes')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($list as $x): $key = $x['type'] . ':' . $x['id'] . ':' . $x['kind']; $done = isset($applied[$key]);
        $summary = $x['kind'] === 'vendor' ? '' : tr(json_decode((string)$x['summary'], true)); ?>
    <tr class="<?= $done ? 'is-done' : '' ?>">
      <td class="check-col"><label class="checkbox"><input type="checkbox" data-pick='<?= e(json_encode(['type' => $x['type'], 'id' => (int)$x['id'], 'kind' => $x['kind']])) ?>' <?= $done ? 'disabled' : '' ?>><span></span></label></td>
      <td class="res"><?= icon_html($x, $x['type']) ?><div><?= name_link($x, $x['type']) ?><?php if ($x['is_new']): ?> <em class="tag is-new"><?= e(t('New', 'Nouveau')) ?></em><?php endif; ?><?php if ((int)$x['internal']): ?> <em class="tag"><?= e(t('Internal', 'Interne')) ?></em><?php endif; ?>
        <small><?= e(type_label($x['type'])) ?> · ID <?= (int)$x['id'] ?><?= (int)$x['level'] ? ' · ' . e(t('level', 'niveau')) . ' ' . (int)$x['level'] : '' ?></small></div></td>
      <td class="fix-cell"><?php if ($x['kind'] === 'vendor'): $why = ''; foreach (json_decode((string)$x['checks'], true) ?: [] as $check) if ($check['code'] === 'source' && !$check['ok']) $why = tr($check['detail']); ?>
        <span><?= e($why) ?></span>
        <?php foreach (array_slice($importSources[(int)$x['id']] ?? [], 0, 4) as [$sk, $sid, $sname]): ?><span class="reason"><em class="tag is-plain"><?= e(['loot' => t('Drop', 'Butin'), 'object' => t('Chest', 'Coffre'), 'vendor' => t('Sold by', 'Vendu par'), 'quest' => t('Quest reward', 'Récompense')][$sk] ?? $sk) ?></em> <?php if ($sk === 'quest'): ?><a href="<?= e(entry_url('quest', $sid)) ?>" data-tt="quest:<?= $sid ?>"><?= e($sname) ?></a><?php else: ?><?= e($sname) ?> <small class="muted">#<?= $sid ?></small><?php endif; ?></span><?php endforeach; ?>
        <?php else: ?><span><?= e($summary) ?></span><?php endif; ?>
        <?php if (!empty($x['needs'])): ?><small class="warn-text"><i class="fas fa-link"></i> <?= e(t('needs the hotfix fix first', 'nécessite d’abord la correction hotfix')) ?></small><?php endif; ?>
        <?php if ($done): ?><small class="ok-text"><i class="fas fa-circle-check"></i> <?= e(t('applied, waiting for the next analysis', 'appliquée, en attente de la prochaine analyse')) ?></small><?php endif; ?></td>
      <td><?= status_badge($x['status'], (int)$x['score']) ?></td>
      <td class="num"><?= $x['kind'] === 'vendor' ? count($importSources[(int)$x['id']] ?? []) : (int)$x['rows_count'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php $pages = (int)ceil($total / $perPage); if ($pages > 1): ?>
<nav class="pager">
  <?php if ($page > 1): ?><a class="btn is-small" href="<?= e($query(['p' => $page - 1])) ?>"><i class="fas fa-arrow-left"></i> <?= e(t('Previous', 'Précédent')) ?></a><?php else: ?><span></span><?php endif; ?>
  <span class="pager-info"><?= e(sprintf(t('Page %d of %s', 'Page %d sur %s'), $page, number($pages))) ?></span>
  <?php if ($page < $pages): ?><a class="btn is-small" href="<?= e($query(['p' => $page + 1])) ?>"><?= e(t('Next', 'Suivant')) ?> <i class="fas fa-arrow-right"></i></a><?php else: ?><span></span><?php endif; ?>
</nav>
<?php endif; endif; ?>

<?php if ($recent): ?>
<section class="block">
  <header class="block-head"><div><span class="eyebrow"><?= e(t('History', 'Historique')) ?></span><h2><?= e(t('Latest operations', 'Dernières opérations')) ?></h2></div><a class="link" href="<?= e(url('admin/history')) ?>"><?= e(t('Full history', 'Historique complet')) ?> →</a></header>
  <ul class="list is-compact"><?php foreach ($recent as $x): ?>
    <li><span class="fix-kind is-<?= e(explode(',', $x['kind'])[0]) ?> is-small"><i class="fas fa-bolt"></i></span><div class="list-main"><strong>#<?= (int)$x['id'] ?> · <?= e(implode(', ', array_map('forge_kind_label', explode(',', $x['kind'])))) ?></strong><small><?= e(date(t('M j, Y H:i', 'd/m/Y H:i'), strtotime($x['created_at']))) ?> · <?= e($x['admin']) ?> · <?= (int)$x['rows_count'] ?> <?= e(t('rows', 'lignes')) ?></small></div><span class="badge is-<?= $x['state'] === 'applied' ? 'ok' : ($x['state'] === 'failed' ? 'missing' : 'partial') ?>"><i></i><?= e(['applied' => t('Applied', 'Appliquée'), 'failed' => t('Failed', 'Échec'), 'rolled_back' => t('Rolled back', 'Annulée')][$x['state']] ?? $x['state']) ?></span></li>
  <?php endforeach; ?></ul>
</section>
<?php endif; ?>

<div class="action-bar" data-action-bar hidden>
  <div class="action-count"><strong data-selected-count>0</strong> <span><?= e(t('selected', 'sélectionnée(s)')) ?></span></div>
  <?php if ($tab === 'hotfix'): ?><label class="toggle is-small" title="<?= e(t('Also sends the records to connected game clients (hotfix_data). Not needed when players use the official client of the same build.', 'Envoie aussi les enregistrements aux clients connectés (hotfix_data). Inutile quand les joueurs ont le client officiel du même build.')) ?>"><input type="checkbox" data-push><span></span><?= e(t('Push to clients', 'Envoyer aux clients')) ?></label><?php endif; ?>
  <button class="btn is-small" type="button" data-forge-preview-selection><i class="fas fa-eye"></i> <?= e(t('Preview SQL', 'Voir le SQL')) ?></button>
  <button class="btn is-small" type="button" data-forge-download><i class="fas fa-download"></i> .sql</button>
  <?php if ($tab === 'vendor'): ?><button class="btn is-small is-gold" type="button" data-import-items><i class="fas fa-file-import"></i> <?= e(t('Apply imported sources', 'Appliquer les sources importées')) ?></button><?php endif; ?>
  <button class="btn is-small <?= $tab === 'vendor' ? '' : 'is-gold' ?>" type="button" data-forge-apply-selection><i class="fas fa-<?= $tab === 'vendor' ? 'store' : 'bolt' ?>"></i> <?= e($tab === 'vendor' ? t('Sell at the vendor', 'Vendre chez le marchand') : t('Apply', 'Appliquer')) ?></button>
</div>
<?php require FT_APP . '/pages/admin/_modals.php'; ?>
<?php page_end();
