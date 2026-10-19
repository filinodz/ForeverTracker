<?php
// Catalogue of one resource type ($type), with filters, sorting and pagination.
$perPage = 50;
$page = max(1, min(2000, (int)($_GET['p'] ?? 1)));
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 60));
$status = in_array($_GET['status'] ?? '', ['ok', 'partial', 'missing', 'todo'], true) ? $_GET['status'] : '';
$onlyNew = !empty($_GET['new']);
$showInternal = !empty($_GET['internal']);
$sub = mb_substr((string)($_GET['sub'] ?? ''), 0, 60);
$sorts = ['name' => 'e.name ASC', 'level' => 'e.level DESC, e.name ASC', 'score' => 'e.score ASC, e.name ASC', 'id' => 'e.id DESC', 'recent' => 'e.changed_build DESC, e.id DESC'];
$sort = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : ($type === 'item' ? 'level' : 'name');

$where = ['e.type = ?']; $params = [$type];
if (!$showInternal) $where[] = 'e.internal = 0';
if ($status === 'todo') $where[] = "e.status <> 'ok'";
elseif ($status !== '') { $where[] = 'e.status = ?'; $params[] = $status; }
if ($onlyNew) $where[] = 'e.is_new = 1';
if ($q !== '') {
    if (ctype_digit($q)) { $where[] = 'e.id = ?'; $params[] = (int)$q; }
    else { $where[] = '(e.name LIKE ? OR e.name_en LIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
}
if ($sub !== '') {
    if ($type === 'item') { $where[] = 'e.quality = ?'; $params[] = (int)$sub; }
    else { $where[] = 'e.sub = ?'; $params[] = $sub; }
}
$whereSql = implode(' AND ', $where);
$total = (int)value("SELECT COUNT(*) FROM ft_entities e WHERE $whereSql", $params);
$counts = ['ok' => 0, 'partial' => 0, 'missing' => 0];
foreach (rows('SELECT status, COUNT(*) n FROM ft_entities e WHERE e.type = ? AND e.internal = 0 GROUP BY status', [$type]) as $r) $counts[$r['status']] = (int)$r['n'];
$list = rows("SELECT e.* FROM ft_entities e WHERE $whereSql ORDER BY {$sorts[$sort]} LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
$fixes = [];
if (is_admin() && $list && table_exists('ft_fixes')) {
    foreach (rows('SELECT id, kind FROM ft_fixes WHERE type = ? AND id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $list)) . ')', [$type]) as $f) $fixes[(int)$f['id']][] = $f['kind'];
}

$qualities = [0 => t('Poor', 'Médiocre'), 1 => t('Common', 'Commun'), 2 => t('Uncommon', 'Inhabituel'), 3 => t('Rare', 'Rare'), 4 => t('Epic', 'Épique'), 5 => t('Legendary', 'Légendaire'), 6 => t('Artifact', 'Artefact'), 7 => t('Heirloom', 'Héritage')];
$subOptions = [];
if ($type === 'item') $subOptions = $qualities;
elseif (!in_array($type, ['quest', 'boss', 'taxi', 'mount', 'pet', 'toy'], true)) {
    foreach (rows("SELECT sub, COUNT(*) n FROM ft_entities WHERE type = ? AND internal = 0 AND sub <> '' GROUP BY sub ORDER BY n DESC LIMIT 40", [$type]) as $r)
        $subOptions[$r['sub']] = sub_label($r['sub']) . ' (' . $r['n'] . ')';
}
$itemClasses = [0 => t('Consumable', 'Consommable'), 1 => t('Container', 'Conteneur'), 2 => t('Weapon', 'Arme'), 3 => t('Gem', 'Gemme'), 4 => t('Armor', 'Armure'),
    5 => t('Reagent', 'Composant'), 6 => t('Projectile', 'Projectile'), 7 => t('Trade goods', 'Artisanat'), 9 => t('Recipe', 'Recette'), 11 => t('Quiver', 'Carquois'),
    12 => t('Quest', 'Quête'), 13 => t('Key', 'Clé'), 15 => t('Miscellaneous', 'Divers'), 16 => t('Glyph', 'Glyphe'), 17 => t('Battle pet', 'Mascotte')];
$zoneNames = [];
if ($type === 'quest') foreach (rows("SELECT id, name, name_en FROM ft_entities WHERE type = 'zone'") as $z) $zoneNames[(string)$z['id']] = fr() ? $z['name'] : ($z['name_en'] ?: $z['name']);
$levelHeader = ['item' => t('iLvl', 'Niv.'), 'quest' => t('Level', 'Niveau'), 'dungeon' => t('Players', 'Joueurs'), 'achievement' => 'Points',
                'spell' => t('Level', 'Niveau'), 'talent' => t('Level', 'Niveau'), 'recipe' => t('Skill', 'Compét.'), 'zone' => t('Level', 'Niveau')][$type] ?? null;
$pageUrl = function (array $change) {
    $query = array_merge($_GET, $change);
    foreach ($query as $key => $v) if ($v === '' || $v === null || $v === 0 || $v === '0') unset($query[$key]);
    return '?' . http_build_query($query);
};
$all = array_sum($counts);
page_start(type_label($type), $type, type_desc($type));
?>
<section class="page-head">
  <div class="page-title">
    <span class="page-icon"><i class="fas <?= TYPES[$type][1] ?>"></i></span>
    <div><span class="eyebrow"><?= e(t('Resources', 'Ressources')) ?></span><h1><?= e(type_label($type)) ?></h1><p><?= e(type_desc($type)) ?></p></div>
  </div>
  <div class="stat-tiles">
    <?php foreach (['ok', 'partial', 'missing'] as $s): ?>
      <a class="stat-tile is-<?= $s ?> <?= $status === $s ? 'is-active' : '' ?>" href="<?= e($pageUrl(['status' => $status === $s ? '' : $s, 'p' => ''])) ?>"><strong><?= number($counts[$s]) ?></strong><span><?= e(status_label($s)) ?></span><em><?= $all ? round(100 * $counts[$s] / $all) : 0 ?>%</em></a>
    <?php endforeach; ?>
    <span class="stack is-wide" aria-hidden="true"><?php foreach (['ok', 'partial', 'missing'] as $s): ?><i class="is-<?= $s ?>" style="width:<?= $all ? round(100 * $counts[$s] / $all, 2) : 0 ?>%"></i><?php endforeach; ?></span>
  </div>
</section>

<form class="toolbar" method="get" data-autosubmit-form>
  <label class="field is-search"><i class="fas fa-magnifying-glass"></i><input name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Filter by name or ID…', 'Filtrer par nom ou ID…')) ?>" aria-label="<?= e(t('Name or ID', 'Nom ou ID')) ?>"></label>
  <div class="segmented" role="group" aria-label="<?= e(t('Status', 'Statut')) ?>">
    <?php foreach (['' => t('All', 'Tous'), 'todo' => t('To do', 'À faire'), 'ok' => status_label('ok'), 'partial' => status_label('partial'), 'missing' => status_label('missing')] as $value => $label): ?>
      <label class="<?= $value !== '' && $value !== 'todo' ? 'is-' . $value : '' ?>"><input type="radio" name="status" value="<?= $value ?>" <?= $status === $value ? 'checked' : '' ?> data-autosubmit><span><?= e($label) ?></span></label>
    <?php endforeach; ?>
  </div>
  <?php if ($subOptions): ?>
  <label class="field is-select"><span><?= e($type === 'item' ? t('Quality', 'Qualité') : t('Category', 'Catégorie')) ?></span><select name="sub" data-autosubmit>
    <option value=""><?= e(t('All', 'Toutes')) ?></option>
    <?php foreach ($subOptions as $v => $label): ?><option value="<?= e($v) ?>" <?= (string)$sub === (string)$v ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select></label>
  <?php endif; ?>
  <label class="field is-select"><span><?= e(t('Sort', 'Tri')) ?></span><select name="sort" data-autosubmit>
    <?php foreach (['name' => t('Name', 'Nom'), 'level' => $levelHeader ?? t('Level', 'Niveau'), 'score' => t('Least complete', 'Moins complet'), 'recent' => t('Recently changed', 'Modifié récemment'), 'id' => 'ID'] as $v => $label): ?>
      <option value="<?= $v ?>" <?= $sort === $v ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select></label>
  <label class="toggle"><input type="checkbox" name="new" value="1" <?= $onlyNew ? 'checked' : '' ?> data-autosubmit><span></span><?= e(t('New in Forever', 'Nouveau dans Forever')) ?></label>
  <label class="toggle"><input type="checkbox" name="internal" value="1" <?= $showInternal ? 'checked' : '' ?> data-autosubmit><span></span><?= e(t('Internal / test', 'Interne / test')) ?></label>
  <noscript><button class="btn is-small">OK</button></noscript>
</form>

<p class="result-count"><b><?= number($total) ?></b> <?= e(t('results', 'résultats')) ?><?php if ($q !== '' || $status !== '' || $sub !== '' || $onlyNew || $showInternal): ?> · <a href="<?= e(type_url($type)) ?>"><?= e(t('Reset filters', 'Réinitialiser')) ?></a><?php endif; ?></p>
<?php if (!$list): ?>
  <div class="empty-state is-inline"><div class="empty-icon"><i class="fas fa-wind"></i></div><p><?= e(t('Nothing matches these filters.', 'Rien ne correspond à ces filtres.')) ?></p></div>
<?php else: ?>
<div class="table-card">
<table class="table">
  <thead><tr><th><?= e(t('Resource', 'Ressource')) ?></th><?php if ($levelHeader): ?><th class="num"><?= e($levelHeader) ?></th><?php endif; ?><th><?= e(t('What is missing', 'Ce qui manque')) ?></th><th><?= e(t('Status', 'Statut')) ?></th><th class="num">ID</th></tr></thead>
  <tbody>
  <?php foreach ($list as $r): $checks = json_decode($r['checks'], true) ?: []; $fails = failing_checks($checks);
        $subText = $type === 'quest' ? ($zoneNames[$r['sub']] ?? '') : ($type === 'item' ? ($itemClasses[(int)strtok($r['sub'], ':')] ?? '') : sub_label($r['sub']));
        $other = other_name($r);
        $wh = wowhead_url($type, $r); ?>
    <tr>
      <td class="res"><?= icon_html($r, $type) ?><div><?= name_link($r, $type) ?><?php if ($r['is_new']): ?> <em class="tag is-new"><?= e(t('New', 'Nouveau')) ?></em><?php endif; ?><?php if ((int)$r['internal']): ?> <em class="tag"><?= e(t('Internal', 'Interne')) ?></em><?php endif; ?><?php if (!empty($fixes[(int)$r['id']])): ?> <em class="tag is-gold" title="<?= e(t('The Forge can fix it', 'La Forge peut le corriger')) ?>"><i class="fas fa-hammer"></i></em><?php endif; ?>
        <small><?= e(implode(' · ', array_filter([$subText, $other]))) ?></small></div></td>
      <?php if ($levelHeader): ?><td class="num" data-label="<?= e($levelHeader) ?>"><?= (int)$r['level'] ?: '—' ?></td><?php endif; ?>
      <td class="missing-cell"><?php if ($fails): ?><span><?= e(check_label($fails[0])) ?></span><small><?= e(mb_strimwidth(tr($fails[0]['detail']), 0, 96, '…')) ?></small><?php if (count($fails) > 1): ?><small class="more">+<?= count($fails) - 1 ?> <?= e(t('more', 'autre(s)')) ?></small><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td><?= status_badge($r['status'], (int)$r['score']) ?></td>
      <td class="num"><?php if ($wh): ?><a class="id-link" href="<?= e($wh) ?>" target="_blank" rel="noopener" title="Wowhead"><?= (int)$r['id'] ?></a><?php else: ?><span class="id-link"><?= (int)$r['id'] ?></span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php $pages = (int)ceil($total / $perPage); if ($pages > 1): ?>
<nav class="pager" aria-label="<?= e(t('Pages', 'Pages')) ?>">
  <?php if ($page > 1): ?><a class="btn is-small" href="<?= e($pageUrl(['p' => $page - 1])) ?>"><i class="fas fa-arrow-left"></i> <?= e(t('Previous', 'Précédent')) ?></a><?php else: ?><span></span><?php endif; ?>
  <span class="pager-info"><?= e(sprintf(t('Page %d of %s', 'Page %d sur %s'), $page, number($pages))) ?></span>
  <?php if ($page < $pages): ?><a class="btn is-small" href="<?= e($pageUrl(['p' => $page + 1])) ?>"><?= e(t('Next', 'Suivant')) ?> <i class="fas fa-arrow-right"></i></a><?php else: ?><span></span><?php endif; ?>
</nav>
<?php endif; endif; ?>
<?php page_end();
