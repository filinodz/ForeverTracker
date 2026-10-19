<?php
// Changelog of an official build: every resource added, changed or removed, with Blizzard's notes.
$builds = builds();
$withChanges = array_map('intval', array_column(rows('SELECT DISTINCT build FROM ft_changes ORDER BY build DESC'), 'build'));
$build = (int)($_GET['build'] ?? ($withChanges[0] ?? 0));
$filterType = isset(TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$kind = in_array($_GET['kind'] ?? '', ['added', 'modified', 'removed'], true) ? $_GET['kind'] : '';
$showInternal = !empty($_GET['internal']);
$current = null;
foreach ($builds as $b) if ((int)$b['build'] === $build) $current = $b;

$visible = $showInternal ? '' : " AND (e.internal = 0 OR (e.internal IS NULL AND c.kind = 'removed'))";
$where = 'c.build = ?' . $visible; $params = [$build];
if ($filterType !== '') { $where .= ' AND c.type = ?'; $params[] = $filterType; }
if ($kind !== '') { $where .= ' AND c.kind = ?'; $params[] = $kind; }
$summary = [];
foreach (rows("SELECT c.type, c.kind, COUNT(*) n FROM ft_changes c LEFT JOIN ft_entities e ON e.type = c.type AND e.id = c.id WHERE c.build = ?$visible GROUP BY c.type, c.kind", [$build]) as $x)
    $summary[$x['type']][$x['kind']] = (int)$x['n'];
$limit = $filterType !== '' ? 500 : 40;
$list = rows("SELECT c.*, e.name_en, e.icon, e.quality, e.status, e.score, e.is_new, e.data FROM ft_changes c LEFT JOIN ft_entities e ON e.type = c.type AND e.id = c.id
    WHERE $where ORDER BY FIELD(c.type,'dungeon','boss','zone','quest','mount','pet','toy','talent','spell','recipe','item','achievement','taxi'), FIELD(c.kind,'added','modified','removed'), c.name LIMIT 5000", $params);
$grouped = [];
foreach ($list as $x) $grouped[$x['type']][] = $x;
$note = table_exists('ft_notes') ? row('SELECT * FROM ft_notes WHERE build = ?', [$build]) : null;
$fieldLabels = ['name' => t('name', 'nom'), 'quality' => t('quality', 'qualité'), 'ilvl' => t('item level', 'niveau d’objet'), 'req' => t('required level', 'niveau requis'),
    'stats' => t('stats', 'caractéristiques'), 'sell' => t('sell price', 'prix de vente'), 'buy' => t('buy price', 'prix d’achat'), 'desc' => 'description',
    'effects' => t('effects', 'effets'), 'aura' => 'aura', 'level' => t('level', 'niveau'), 'ranks' => t('ranks', 'rangs'), 'points' => 'points', 'reward' => t('reward', 'récompense'), 'source' => 'source'];

/** Small Markdown subset for the official notes: headings, lists, bold, links, paragraphs. */
function markdown(string $text): string
{
    $html = ''; $list = false;
    foreach (preg_split('/\R/', $text) as $line) {
        $line = rtrim($line);
        $inline = preg_replace(['/\*\*(.+?)\*\*/', '/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/'], ['<strong>$1</strong>', '<a href="$2" target="_blank" rel="noopener">$1</a>'], e($line));
        if (preg_match('/^\s*[-*]\s+(.*)$/', $line)) { if (!$list) { $html .= '<ul>'; $list = true; } $html .= '<li>' . preg_replace('/^\s*[-*]\s+/', '', $inline) . '</li>'; continue; }
        if ($list) { $html .= '</ul>'; $list = false; }
        if ($line === '') continue;
        if (preg_match('/^(#{2,3})\s+/', $line, $m)) { $html .= '<h' . strlen($m[1]) . '>' . preg_replace('/^#{2,3}\s+/', '', $inline) . '</h' . strlen($m[1]) . '>'; continue; }
        $html .= '<p>' . $inline . '</p>';
    }
    return $html . ($list ? '</ul>' : '');
}
$query = fn(array $change) => '?' . http_build_query(array_filter(array_merge(['build' => $build, 'type' => $filterType, 'kind' => $kind, 'internal' => $showInternal ? 1 : ''], $change), fn($v) => $v !== '' && $v !== null));
$totals = ['added' => 0, 'modified' => 0, 'removed' => 0];
foreach ($summary as $counts) foreach ($counts as $k => $n) $totals[$k] += $n;

page_start(t('Changelog', 'Changelog') . ($current ? ' ' . $current['version'] : ''), 'changes', t('What changed in each official WoW Forever build.', 'Ce qui a changé dans chaque build officiel de WoW Forever.'));
?>
<section class="page-head">
  <div class="page-title">
    <span class="page-icon"><i class="fas fa-code-compare"></i></span>
    <div><span class="eyebrow"><?= e(t('Official changelog', 'Changelog officiel')) ?></span><h1><?= e(t('Build', 'Build')) ?> <?= e($current['version'] ?? '—') ?></h1>
    <p><?= e(t('Every resource added, changed or removed in the client files of this build, compared with the previous tracked build. Hover a name for its tooltip.', 'Chaque ressource ajoutée, modifiée ou supprimée dans les fichiers du client de ce build, par rapport au build suivi précédent. Survolez un nom pour son infobulle.')) ?></p></div>
  </div>
  <div class="stat-tiles is-compact">
    <span class="stat-tile is-ok"><strong>+<?= number($totals['added']) ?></strong><span><?= e(kind_label('added')) ?></span></span>
    <span class="stat-tile is-partial"><strong>~<?= number($totals['modified']) ?></strong><span><?= e(kind_label('modified')) ?></span></span>
    <span class="stat-tile is-missing"><strong>−<?= number($totals['removed']) ?></strong><span><?= e(kind_label('removed')) ?></span></span>
  </div>
</section>

<div class="build-switch">
  <?php foreach ($builds as $b): $has = in_array((int)$b['build'], $withChanges, true); ?>
    <a class="<?= (int)$b['build'] === $build ? 'is-active' : '' ?> <?= $has ? '' : 'is-quiet' ?>" href="?build=<?= (int)$b['build'] ?>"><strong><?= e($b['version']) ?></strong><small><?= $b['released'] ? e(date(t('M j', 'd/m'), strtotime($b['released']))) : '' ?><?= (int)$b['is_server'] ? ' · ' . e(t('server', 'serveur')) : '' ?></small></a>
  <?php endforeach; ?>
</div>

<?php if ($current && $current['note']): ?><div class="alert is-info"><i class="fas fa-circle-info"></i><div><?= e($current['note']) ?></div></div><?php endif; ?>

<?php if ($note): ?>
<section class="block notes">
  <header class="block-head"><div><span class="eyebrow">Blizzard</span><h2><?= e($note['title'] ?: t('Official patch notes', 'Notes de mise à jour officielles')) ?></h2></div><?php if ($note['url']): ?><a class="link" href="<?= e($note['url']) ?>" target="_blank" rel="noopener"><?= e(t('Source', 'Source')) ?> ↗</a><?php endif; ?></header>
  <div class="prose"><?= markdown((string)$note['body']) ?></div>
</section>
<?php elseif (is_admin() && $build): ?>
<p class="muted"><a href="<?= e(url('admin/notes', ['build' => $build])) ?>"><i class="fas fa-pen-to-square"></i> <?= e(t('Add Blizzard’s patch notes for this build', 'Ajouter les notes de mise à jour de Blizzard pour ce build')) ?></a></p>
<?php endif; ?>

<?php if (!in_array($build, $withChanges, true)): ?>
  <div class="empty-state is-inline"><div class="empty-icon"><i class="fas fa-equals"></i></div><p><?= e(t('No data change in this build compared with the previous one.', 'Aucune donnée modifiée dans ce build par rapport au précédent.')) ?></p></div>
<?php else: ?>
<div class="chips">
  <a class="<?= $filterType === '' ? 'is-active' : '' ?>" href="<?= e($query(['type' => ''])) ?>"><?= e(t('All', 'Tout')) ?></a>
  <?php foreach ($summary as $ty => $counts): ?>
    <a class="<?= $filterType === $ty ? 'is-active' : '' ?>" href="<?= e($query(['type' => $ty])) ?>"><i class="fas <?= TYPES[$ty][1] ?>"></i> <?= e(type_label($ty)) ?>
      <span><?php foreach (['added' => '+', 'modified' => '~', 'removed' => '−'] as $k => $sign) if (!empty($counts[$k])) echo '<b class="is-' . $k . '">' . $sign . number($counts[$k]) . '</b>'; ?></span></a>
  <?php endforeach; ?>
</div>
<div class="chips is-small">
  <?php foreach (['' => t('Every change', 'Tous les changements'), 'added' => kind_label('added'), 'modified' => kind_label('modified'), 'removed' => kind_label('removed')] as $k => $label): ?><a class="<?= $kind === $k ? 'is-active' : '' ?>" href="<?= e($query(['kind' => $k])) ?>"><?= e($label) ?></a><?php endforeach; ?>
  <a class="<?= $showInternal ? 'is-active' : '' ?>" href="<?= e($query(['internal' => $showInternal ? '' : 1])) ?>"><i class="fas fa-flask"></i> <?= e(t('Internal / test', 'Interne / test')) ?></a>
</div>

<?php foreach ($grouped as $ty => $items): ?>
<section class="block">
  <header class="block-head"><div><h2><i class="fas <?= TYPES[$ty][1] ?> muted"></i> <?= e(type_label($ty)) ?> <small class="count"><?= count($items) ?></small></h2></div>
  <?php if ($filterType === '' && count($items) > $limit): ?><a class="link" href="<?= e($query(['type' => $ty])) ?>"><?= e(t('Show all', 'Tout afficher')) ?> →</a><?php endif; ?></header>
  <ul class="changes">
  <?php foreach (array_slice($items, 0, $limit) as $x): $fields = json_decode($x['fields'], true) ?: []; $wh = wowhead_url($ty, $x); ?>
    <li>
      <span class="kind is-<?= e($x['kind']) ?>"><?= e(kind_label($x['kind'])) ?></span>
      <div class="res"><?= icon_html($x, $ty) ?><div><?= name_link($x, $ty) ?><?= $x['is_new'] ? ' <em class="tag is-new">' . e(t('New', 'Nouveau')) . '</em>' : '' ?><small>ID <?= (int)$x['id'] ?></small></div></div>
      <?php if ($fields): ?><span class="change-fields"><?php foreach (array_slice($fields, 0, 3, true) as $field => [$before, $after]): ?>
        <span><em><?= e($fieldLabels[$field] ?? $field) ?></em> <?php if (!is_array($before) && !is_array($after) && mb_strlen((string)$before) < 40 && mb_strlen((string)$after) < 40): ?><del><?= e($before === '' || $before === null ? '∅' : $before) ?></del> → <ins><?= e($after === '' || $after === null ? '∅' : $after) ?></ins><?php else: ?><ins><?= e(t('changed', 'modifié')) ?></ins><?php endif; ?></span>
      <?php endforeach; ?><?= count($fields) > 3 ? '<span class="more">+' . (count($fields) - 3) . '</span>' : '' ?></span><?php else: ?><span></span><?php endif; ?>
      <span class="change-status"><?= $x['status'] ? status_badge($x['status'], (int)$x['score']) : '' ?><?php if ($wh): ?><a class="icon-btn is-small" href="<?= e($wh) ?>" target="_blank" rel="noopener" aria-label="Wowhead"><i class="fas fa-arrow-up-right-from-square"></i></a><?php endif; ?></span>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; endif; ?>
<?php page_end();
