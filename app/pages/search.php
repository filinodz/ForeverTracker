<?php
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 60));
$results = $q !== '' ? search_entities($q, 120) : [];
page_start(t('Search', 'Recherche') . ($q !== '' ? ' · ' . $q : ''), 'search');
?>
<section class="page-head">
  <div class="page-title">
    <span class="page-icon"><i class="fas fa-magnifying-glass"></i></span>
    <div><span class="eyebrow"><?= e(t('Search', 'Recherche')) ?></span><h1><?= $q !== '' ? '« ' . e($q) . ' »' : e(t('Search every resource', 'Chercher dans toutes les ressources')) ?></h1>
    <p><?= e(t('Names in English and French, or an exact ID.', 'Noms en français et en anglais, ou un ID exact.')) ?></p></div>
  </div>
</section>
<form class="toolbar" method="get"><label class="field is-search is-grow"><i class="fas fa-magnifying-glass"></i><input name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Thunderfury, Ragefire Chasm, 19019…', 'Lame-tonnerre, Gouffre de Ragefeu, 19019…')) ?>" autofocus></label><button class="btn is-primary"><?= e(t('Search', 'Rechercher')) ?></button></form>
<?php if ($q !== '' && !$results): ?><div class="empty-state is-inline"><div class="empty-icon"><i class="fas fa-wind"></i></div><p><?= e(t('No resource found.', 'Aucune ressource trouvée.')) ?></p></div><?php endif; ?>
<?php if ($results): ?>
<p class="result-count"><b><?= count($results) ?></b> <?= e(t('results', 'résultats')) ?></p>
<ul class="list is-grid">
<?php foreach ($results as $x): ?>
  <li><?= icon_html($x, $x['type']) ?><div class="list-main"><?= name_link($x, $x['type']) ?><small><?= e(type_label($x['type'])) ?> · ID <?= (int)$x['id'] ?><?= (int)$x['internal'] ? ' · ' . e(t('internal', 'interne')) : '' ?></small></div><?= status_badge($x['status'], (int)$x['score']) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php page_end();
