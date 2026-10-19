<?php
page_start(t('Overview', 'Tableau de bord'), '', t('Everything WoW Forever contains, and what your server already provides.', 'Tout ce que contient WoW Forever, et ce que votre serveur propose déjà.'));

$weights = ['dungeon' => 3, 'boss' => 2, 'zone' => 2, 'quest' => 3, 'item' => 2, 'spell' => 2, 'talent' => 2, 'recipe' => 1, 'mount' => 1, 'pet' => .5, 'toy' => .5, 'achievement' => 1, 'taxi' => 1];
$coverage = coverage();
$global = 0; $weightSum = 0; $totals = ['total' => 0, 'ok' => 0, 'partial' => 0, 'missing' => 0, 'new' => 0];
foreach ($coverage as $type => $c) {
    $global += $c['score'] * ($weights[$type] ?? 1); $weightSum += $weights[$type] ?? 1;
    foreach ($totals as $k => $v) $totals[$k] += $c[$k];
}
$global = $weightSum ? (int)round($global / $weightSum) : 0;
$builds = builds();
$meta = meta();
$client = $builds[0] ?? null;
$serverBuild = (int)($meta['server_build'] ?? 0);
$unknownToCore = $client && $serverBuild < (int)$client['build'] ? (int)value('SELECT COUNT(*) FROM ft_entities WHERE internal = 0 AND first_build > ?', [$serverBuild]) : 0;
$changeCounts = [];
foreach (rows('SELECT build, kind, COUNT(*) n FROM ft_changes GROUP BY build, kind') as $r) $changeCounts[$r['build']][$r['kind']] = (int)$r['n'];
$priorities = rows("SELECT type, id, name, sub, icon, quality, status, score, is_new, checks FROM ft_entities
    WHERE internal = 0 AND status <> 'ok' AND type IN ('dungeon','boss','zone') ORDER BY FIELD(type,'dungeon','zone','boss'), is_new DESC, score ASC LIMIT 8");
$latestBuild = (int)value('SELECT MAX(build) FROM ft_changes');
$latestVersion = '';
foreach ($builds as $b) if ((int)$b['build'] === $latestBuild) $latestVersion = $b['version'];
$latest = rows("SELECT c.type, c.id, c.kind, c.name, e.name_en, e.icon, e.quality, e.status, e.score FROM ft_changes c LEFT JOIN ft_entities e ON e.type = c.type AND e.id = c.id
    WHERE c.build = ? AND (e.internal = 0 OR e.internal IS NULL) ORDER BY FIELD(c.kind,'added','modified','removed'), FIELD(c.type,'dungeon','zone','quest','mount','pet','toy','item','spell','talent','achievement') LIMIT 8", [$latestBuild]);
$fixCount = table_exists('ft_fixes') ? (int)value('SELECT COUNT(*) FROM ft_fixes f JOIN ft_entities e USING (type, id) WHERE e.internal = 0') : 0;
?>
<section class="hero">
  <div class="hero-copy">
    <span class="eyebrow"><?= e(sprintf(t('Official client %s · server data %s', 'Client officiel %s · données serveur %s'), $client['version'] ?? '—', $serverBuild ?: '—')) ?></span>
    <h1><?= e(t('Server coverage', 'Couverture du serveur')) ?></h1>
    <p><?= e(t('Every resource of the official client, checked against your server: core data, spawns, loot, vendors, trainers, quest givers and scripts.', 'Chaque ressource du client officiel, vérifiée sur votre serveur : données du core, PNJ placés, butin, marchands, entraîneurs, donneurs de quêtes et scripts.')) ?></p>
    <div class="hero-actions">
      <a class="btn is-primary" href="<?= e(url('todo')) ?>"><i class="fas fa-list-check"></i> <?= e(t('What to add', 'Ce qu’il faut ajouter')) ?></a>
      <a class="btn" href="<?= e(url('changes')) ?>"><i class="fas fa-code-compare"></i> <?= e(t('Latest changes', 'Derniers changements')) ?></a>
      <?php if (is_admin() && $fixCount): ?><a class="btn" href="<?= e(url('admin')) ?>"><i class="fas fa-hammer"></i> <?= e(sprintf(t('%s ready fixes', '%s corrections prêtes'), number($fixCount))) ?></a><?php endif; ?>
    </div>
  </div>
  <div class="hero-ring">
    <div class="ring-wrap is-xl"><?= ring($global, 168) ?><div class="ring-label"><strong><?= $global ?></strong><small>%</small><span><?= e(t('server coverage', 'couverture serveur')) ?></span></div></div>
    <ul class="ring-legend">
      <li class="is-ok"><i></i><?= e(status_label('ok')) ?><b><?= number($totals['ok']) ?></b></li>
      <li class="is-partial"><i></i><?= e(status_label('partial')) ?><b><?= number($totals['partial']) ?></b></li>
      <li class="is-missing"><i></i><?= e(status_label('missing')) ?><b><?= number($totals['missing']) ?></b></li>
    </ul>
  </div>
</section>

<section class="kpis">
  <article class="kpi"><span class="kpi-icon"><i class="fas fa-layer-group"></i></span><div><strong><?= number($totals['total']) ?></strong><span><?= e(t('official resources', 'ressources officielles')) ?></span></div></article>
  <article class="kpi is-ok"><span class="kpi-icon"><i class="fas fa-circle-check"></i></span><div><strong><?= number($totals['ok']) ?></strong><span><?= e(t('available in game', 'disponibles en jeu')) ?></span></div><em><?= $totals['total'] ? round(100 * $totals['ok'] / $totals['total']) : 0 ?>%</em></article>
  <article class="kpi is-missing"><span class="kpi-icon"><i class="fas fa-screwdriver-wrench"></i></span><div><strong><?= number($totals['partial'] + $totals['missing']) ?></strong><span><?= e(t('to finish or add', 'à terminer ou ajouter')) ?></span></div></article>
  <article class="kpi is-gold"><span class="kpi-icon"><i class="fas fa-star"></i></span><div><strong><?= number($totals['new']) ?></strong><span><?= e(t('new in Forever', 'nouveautés Forever')) ?></span></div></article>
</section>

<?php if ($unknownToCore): ?>
<div class="alert is-warn"><i class="fas fa-triangle-exclamation"></i><div><strong><?= e(t('Your server data is one build behind.', 'Les données de votre serveur ont un build de retard.')) ?></strong>
  <?= e(sprintf(t('The server loads the DB2 of build %d while the official client is %s: %s resources added since are unknown to the core.', 'Le serveur charge les DB2 du build %d alors que le client officiel est en %s : %s ressources ajoutées depuis sont inconnues du core.'), $serverBuild, $client['version'], number($unknownToCore))) ?>
  <a href="<?= e(url('changes')) ?>"><?= e(t('See what changed', 'Voir ce qui a changé')) ?> →</a></div></div>
<?php endif; ?>

<section class="block">
  <header class="block-head"><div><span class="eyebrow"><?= e(t('Resources', 'Ressources')) ?></span><h2><?= e(t('Coverage by category', 'Couverture par catégorie')) ?></h2></div>
    <div class="legend"><span class="is-ok"><?= e(status_label('ok')) ?></span><span class="is-partial"><?= e(status_label('partial')) ?></span><span class="is-missing"><?= e(status_label('missing')) ?></span></div></header>
  <div class="cats">
  <?php foreach (TYPES as $type => $info): $c = $coverage[$type] ?? null; if (!$c) continue; $score = (int)round($c['score']); ?>
    <a class="cat" href="<?= e(type_url($type)) ?>">
      <div class="cat-top">
        <span class="cat-icon"><i class="fas <?= $info[1] ?>"></i></span>
        <span class="cat-score"><?= $score ?>%</span>
      </div>
      <strong><?= e(type_label($type)) ?></strong>
      <small><?= number($c['total']) ?> <?= e(t('official', 'officiels')) ?><?= $c['new'] ? ' · <b class="gold">' . number($c['new']) . ' ' . e(t('new', 'nouv.')) . '</b>' : '' ?></small>
      <span class="stack" aria-hidden="true"><i class="is-ok" style="width:<?= round(100 * $c['ok'] / $c['total'], 2) ?>%"></i><i class="is-partial" style="width:<?= round(100 * $c['partial'] / $c['total'], 2) ?>%"></i><i class="is-missing" style="width:<?= round(100 * $c['missing'] / $c['total'], 2) ?>%"></i></span>
      <span class="cat-counts"><b class="is-ok"><?= number($c['ok']) ?></b><b class="is-partial"><?= number($c['partial']) ?></b><b class="is-missing"><?= number($c['missing']) ?></b></span>
    </a>
  <?php endforeach; ?>
  </div>
</section>

<div class="split">
  <section class="block">
    <header class="block-head"><div><span class="eyebrow"><?= e(t('Priorities', 'Priorités')) ?></span><h2><?= e(t('World content to finish', 'Contenu du monde à terminer')) ?></h2></div><a class="link" href="<?= e(url('todo')) ?>"><?= e(t('Full list', 'Liste complète')) ?> →</a></header>
    <ul class="list">
    <?php foreach ($priorities as $r): $fail = failing_checks(json_decode($r['checks'], true) ?: []); ?>
      <li><?= icon_html($r, $r['type']) ?><div class="list-main"><?= name_link($r, $r['type']) ?><?= $r['is_new'] ? ' <em class="tag is-new">' . e(t('New', 'Nouveau')) . '</em>' : '' ?>
        <small><?= e(type_label($r['type'])) ?><?= $fail ? ' · ' . e(check_label($fail[0])) : '' ?></small></div><?= status_badge($r['status'], (int)$r['score']) ?></li>
    <?php endforeach; ?>
    </ul>
  </section>
  <section class="block">
    <header class="block-head"><div><span class="eyebrow"><?= e(t('Build', 'Build')) ?> <?= e($latestVersion) ?></span><h2><?= e(t('Latest changes', 'Derniers changements')) ?></h2></div><a class="link" href="<?= e(url('changes', ['build' => $latestBuild])) ?>"><?= e(t('Changelog', 'Changelog')) ?> →</a></header>
    <?php if (!$latest): ?><p class="muted"><?= e(t('No change between the two latest builds.', 'Aucun changement entre les deux derniers builds.')) ?></p><?php endif; ?>
    <ul class="list">
    <?php foreach ($latest as $r): ?>
      <li><?= icon_html($r, $r['type']) ?><div class="list-main"><?= name_link($r, $r['type']) ?><small><?= e(type_label($r['type'])) ?></small></div><span class="kind is-<?= e($r['kind']) ?>"><?= e(kind_label($r['kind'])) ?></span></li>
    <?php endforeach; ?>
    </ul>
  </section>
</div>

<section class="block">
  <header class="block-head"><div><span class="eyebrow"><?= e(t('Timeline', 'Chronologie')) ?></span><h2><?= e(t('Tracked builds', 'Builds suivis')) ?></h2></div></header>
  <ol class="timeline">
  <?php foreach ($builds as $index => $b): $c = $changeCounts[$b['build']] ?? []; ?>
    <li class="<?= (int)$b['is_server'] ? 'is-server' : '' ?> <?= $index === 0 ? 'is-latest' : '' ?>">
      <a href="<?= e(url('changes', ['build' => (int)$b['build']])) ?>">
        <span class="tl-dot"></span>
        <strong><?= e($b['version']) ?></strong>
        <small><?= $b['released'] ? e(date(t('M j, Y', 'd/m/Y'), strtotime($b['released']))) : e(t('date unknown', 'date inconnue')) ?></small>
        <span class="tl-tags"><?php if ($index === 0): ?><em class="tag is-gold"><?= e(t('Latest', 'Dernier')) ?></em><?php endif; ?><?php if ((int)$b['is_server']): ?><em class="tag"><?= e(t('Your server', 'Votre serveur')) ?></em><?php endif; ?></span>
        <?php if ($c): ?><span class="tl-counts"><b class="is-added">+<?= number($c['added'] ?? 0) ?></b><b class="is-modified">~<?= number($c['modified'] ?? 0) ?></b><b class="is-removed">−<?= number($c['removed'] ?? 0) ?></b></span>
        <?php else: ?><span class="tl-counts"><em><?= e($index === count($builds) - 1 ? t('Reference build', 'Build de référence') : t('No data change', 'Aucune donnée modifiée')) ?></em></span><?php endif; ?>
      </a>
    </li>
  <?php endforeach; ?>
  </ol>
</section>
<?php page_end();
