<?php
// Work list: everything the server still lacks, most structural content first.
$onlyNew = !empty($_GET['new']);
$level = in_array($_GET['level'] ?? '', ['missing', 'all'], true) ? $_GET['level'] : 'all';
$focus = isset(TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$statusSql = $level === 'missing' ? "status = 'missing'" : "status <> 'ok'";
$newSql = $onlyNew ? ' AND is_new = 1' : '';
$counts = [];
foreach (rows("SELECT type, status, COUNT(*) n FROM ft_entities WHERE internal = 0 AND $statusSql$newSql GROUP BY type, status") as $x) $counts[$x['type']][$x['status']] = (int)$x['n'];

if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="forever-tracker-todo.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['type', 'id', 'name', 'name_en', 'status', 'score', 'new', 'missing'], ';');
    foreach ($focus !== '' ? [$focus] : PRIORITY as $ty) {
        foreach (rows("SELECT * FROM ft_entities WHERE internal = 0 AND type = ? AND $statusSql$newSql ORDER BY score, name", [$ty]) as $x) {
            $fails = failing_checks(json_decode($x['checks'], true) ?: []);
            fputcsv($out, [$ty, $x['id'], $x['name'], $x['name_en'], $x['status'], $x['score'], $x['is_new'], implode(' | ', array_map(fn($c) => check_label($c) . ': ' . tr($c['detail']), $fails))], ';');
        }
    }
    exit;
}
$query = fn(array $change) => '?' . http_build_query(array_filter(array_merge(['new' => $onlyNew ? 1 : 0, 'level' => $level, 'type' => $focus], $change), fn($v) => $v !== '' && $v !== null && $v !== 0));
$total = array_sum(array_map('array_sum', $counts));
page_start(t('To add', 'À ajouter'), 'todo', t('Everything the server still lacks, by priority.', 'Tout ce qui manque encore au serveur, par priorité.'));
?>
<section class="page-head">
  <div class="page-title">
    <span class="page-icon"><i class="fas fa-list-check"></i></span>
    <div><span class="eyebrow"><?= e(t('Work list', 'Liste de travail')) ?></span><h1><?= e(t('What your server still lacks', 'Ce qui manque encore à votre serveur')) ?></h1>
    <p><?= e(t('Ordered by impact: instances and their bosses first, then zones, quests, flight paths, class content, collections and items. Each line says what blocks it.', 'Classé par impact : les instances et leurs boss d’abord, puis les zones, les quêtes, les trajets aériens, le contenu de classe, les collections et les objets. Chaque ligne indique ce qui bloque.')) ?></p></div>
  </div>
  <div class="stat-tiles is-compact">
    <span class="stat-tile is-missing"><strong><?= number($total) ?></strong><span><?= e(t('to finish', 'à terminer')) ?></span></span>
    <a class="btn" href="<?= e($query(['format' => 'csv'])) ?>"><i class="fas fa-file-csv"></i> <?= e(t('Export CSV', 'Exporter en CSV')) ?></a>
    <?php if (is_admin()): ?><a class="btn is-gold" href="<?= e(url('admin')) ?>"><i class="fas fa-hammer"></i> <?= e(t('The Forge', 'La Forge')) ?></a><?php endif; ?>
  </div>
</section>

<div class="chips is-small">
  <a class="<?= !$onlyNew ? 'is-active' : '' ?>" href="<?= e($query(['new' => 0])) ?>"><?= e(t('All official content', 'Tout le contenu officiel')) ?></a>
  <a class="<?= $onlyNew ? 'is-active' : '' ?>" href="<?= e($query(['new' => 1])) ?>"><i class="fas fa-star"></i> <?= e(t('Only new in Forever', 'Seulement les nouveautés Forever')) ?></a>
  <span class="chips-sep"></span>
  <a class="<?= $level === 'all' ? 'is-active' : '' ?>" href="<?= e($query(['level' => 'all'])) ?>"><?= e(t('Missing + incomplete', 'Manquant + incomplet')) ?></a>
  <a class="<?= $level === 'missing' ? 'is-active' : '' ?>" href="<?= e($query(['level' => 'missing'])) ?>"><?= e(t('Missing only', 'Manquant seulement')) ?></a>
  <?php if ($focus): ?><span class="chips-sep"></span><a class="is-active" href="<?= e($query(['type' => ''])) ?>"><i class="fas fa-xmark"></i> <?= e(type_label($focus)) ?></a><?php endif; ?>
</div>

<div class="todo-grid <?= $focus ? 'is-focus' : '' ?>">
<?php foreach (PRIORITY as $index => $ty): if (empty($counts[$ty]) || ($focus !== '' && $focus !== $ty)) continue;
    $limit = $focus === $ty ? 300 : 6;
    $list = rows("SELECT * FROM ft_entities WHERE internal = 0 AND type = ? AND $statusSql$newSql ORDER BY status = 'missing' DESC, is_new DESC, score ASC, level DESC, name LIMIT $limit", [$ty]);
    $n = array_sum($counts[$ty]); ?>
  <section class="block todo">
    <header class="block-head"><div><span class="eyebrow"><?= e(t('Priority', 'Priorité')) ?> <?= $index + 1 ?></span><h2><i class="fas <?= TYPES[$ty][1] ?> muted"></i> <?= e(type_label($ty)) ?> <small class="count"><?= number($n) ?></small></h2>
      <span class="split-counts"><?php foreach (['missing', 'partial'] as $s) if (!empty($counts[$ty][$s])) echo '<b class="is-' . $s . '">' . number($counts[$ty][$s]) . ' ' . e(mb_strtolower(status_label($s))) . '</b>'; ?></span></div>
      <?php if ($focus !== $ty): ?><a class="link" href="<?= e($query(['type' => $ty])) ?>"><?= e(t('See all', 'Tout voir')) ?> →</a><?php else: ?><a class="link" href="<?= e(type_url($ty, ['status' => 'todo', 'new' => $onlyNew ? 1 : ''])) ?>"><?= e(t('Open in the catalogue', 'Ouvrir dans le catalogue')) ?> →</a><?php endif; ?></header>
    <ul class="list">
    <?php foreach ($list as $x): $fails = failing_checks(json_decode($x['checks'], true) ?: []); ?>
      <li><?= icon_html($x, $ty) ?><div class="list-main"><?= name_link($x, $ty) ?><?= $x['is_new'] ? ' <em class="tag is-new">' . e(t('New', 'Nouveau')) . '</em>' : '' ?>
        <small><?= e(implode(' · ', array_map('check_label', $fails))) ?></small></div><?= status_badge($x['status'], (int)$x['score']) ?></li>
    <?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>
</div>
<?php if (!$total): ?><div class="empty-state is-inline"><div class="empty-icon"><i class="fas fa-champagne-glasses"></i></div><p><?= e(t('Nothing left with these filters. Well done!', 'Plus rien avec ces filtres. Bravo !')) ?></p></div><?php endif; ?>
<?php page_end();
