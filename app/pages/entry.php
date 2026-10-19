<?php
// One resource: in-game tooltip, every server check with its fix, Forge fixes, history across builds.
$r = row('SELECT * FROM ft_entities WHERE type = ? AND id = ?', [$type, $id]);
$history = rows('SELECT c.*, b.version FROM ft_changes c LEFT JOIN ft_builds b ON b.build = c.build WHERE c.type = ? AND c.id = ? ORDER BY c.build DESC', [$type, $id]);
if (!$r) {
    http_response_code(404);
    page_start(t('Not found', 'Introuvable'), $type);
    ?>
    <section class="empty-state"><div class="empty-icon"><i class="fas fa-circle-question"></i></div><h1><?= e(t('This resource is not in the latest official build.', 'Cette ressource n’est pas dans le dernier build officiel.')) ?></h1>
    <?php if ($history): ?><p><?= e(t('It was removed in', 'Elle a été supprimée en')) ?> <?= e($history[0]['version']) ?>.</p><?php endif; ?>
    <a class="btn is-primary" href="<?= e(url()) ?>"><?= e(t('Back to the overview', 'Retour au tableau de bord')) ?></a></section>
    <?php page_end(); return;
}
$checks = json_decode($r['checks'], true) ?: [];
$data = json_decode($r['data'], true) ?: [];
$tip = table_exists('ft_tooltips') ? value('SELECT tip FROM ft_tooltips WHERE type = ? AND id = ?', [$type, $id]) : null;
$wh = wowhead_url($type, $r);
$related = [];
if ($type === 'dungeon') $related = rows("SELECT * FROM ft_entities WHERE type = 'boss' AND JSON_EXTRACT(data, '$.map') = ? ORDER BY level", [$id]);
if ($type === 'boss') $related = rows("SELECT * FROM ft_entities WHERE type = 'dungeon' AND id = ?", [(int)($data['map'] ?? 0)]);
$teach = [];
foreach (['teach', 'creates'] as $key) {
    $ids = array_map('intval', (array)($data[$key] ?? []));
    if ($ids) $teach[$key] = rows("SELECT * FROM ft_entities WHERE type = 'item' AND id IN (" . implode(',', $ids) . ')');
}
if (!empty($data['item'])) $teach['item'] = rows("SELECT * FROM ft_entities WHERE type = 'item' AND id = ?", [(int)$data['item']]);
if (!empty($data['spell'])) $teach['spell'] = rows("SELECT * FROM ft_entities WHERE type = 'spell' AND id = ?", [(int)$data['spell']]);
if (!empty($data['ranks'])) $teach['ranks'] = rows("SELECT * FROM ft_entities WHERE type = 'spell' AND id IN (" . implode(',', array_map('intval', $data['ranks'])) . ') ORDER BY FIELD(id,' . implode(',', array_map('intval', $data['ranks'])) . ')');
$zoneQuests = $type === 'zone' ? rows("SELECT status, COUNT(*) n FROM ft_entities WHERE type = 'quest' AND sub = ? GROUP BY status", [(string)$id]) : [];
$fixes = is_admin() && table_exists('ft_fixes') ? rows('SELECT kind, db, rows_count, needs, summary FROM ft_fixes WHERE type = ? AND id = ?', [$type, $id]) : [];
$applied = is_admin() && table_exists('ft_forge_log') ? rows("SELECT id, kind, created_at, state FROM ft_forge_log WHERE JSON_CONTAINS(items, JSON_OBJECT('type', ?, 'id', ?)) ORDER BY id DESC LIMIT 5", [$type, $id]) : [];
$fieldLabels = ['name' => t('Name', 'Nom'), 'quality' => t('Quality', 'Qualité'), 'ilvl' => t('Item level', 'Niveau d’objet'), 'req' => t('Required level', 'Niveau requis'),
    'slot' => t('Slot', 'Emplacement'), 'stats' => t('Stats', 'Caractéristiques'), 'sell' => t('Sell price', 'Prix de vente'), 'buy' => t('Buy price', 'Prix d’achat'),
    'desc' => 'Description', 'aura' => t('Aura text', 'Texte de l’aura'), 'effects' => t('Effects', 'Effets'), 'level' => t('Level', 'Niveau'), 'rank' => t('Rank', 'Rang'),
    'ranks' => t('Rank spells', 'Sorts de rang'), 'tier' => t('Row', 'Rangée'), 'column' => t('Column', 'Colonne'), 'points' => 'Points', 'reward' => t('Reward', 'Récompense'),
    'players' => t('Players', 'Joueurs'), 'bonding' => t('Binding', 'Lien'), 'set' => t('Item set', 'Ensemble'), 'source' => 'Source', 'title' => t('Title', 'Titre')];
$fmt = fn($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (($v === null || $v === '') ? '∅' : (string)$v);
$otherName = other_name($r);
$name = display_name($r);
$isItemLike = in_array($type, ['item', 'toy'], true);
page_start($name, $type);
?>
<nav class="crumbs"><a href="<?= e(url()) ?>"><i class="fas fa-house"></i></a><i class="fas fa-chevron-right"></i><a href="<?= e(type_url($type)) ?>"><?= e(type_label($type)) ?></a><i class="fas fa-chevron-right"></i><span><?= e($name) ?></span></nav>

<section class="entry-hero is-<?= e($r['status']) ?>">
  <div class="entry-icon"><?= icon_html($r, $type, 'large', false) ?></div>
  <div class="entry-title">
    <span class="eyebrow"><?= e(type_label($type)) ?> · ID <?= (int)$r['id'] ?></span>
    <h1 class="<?= $isItemLike ? 'q' . (int)$r['quality'] : '' ?>"><?= e($name) ?></h1>
    <div class="tags">
      <?php if ($otherName): ?><span class="tag is-plain" lang="<?= fr() ? 'en' : 'fr' ?>"><i class="fas fa-language"></i> <?= e($otherName) ?></span><?php endif; ?>
      <?php if ($r['is_new']): ?><em class="tag is-new"><i class="fas fa-star"></i> <?= e(t('New in Forever', 'Nouveau dans Forever')) ?></em><?php endif; ?>
      <?php if ((int)$r['internal']): ?><em class="tag"><?= e(t('Internal / test content', 'Contenu interne / de test')) ?></em><?php endif; ?>
      <span class="tag is-plain"><i class="fas fa-code-branch"></i> <?= e(t('since build', 'depuis le build')) ?> <?= (int)$r['first_build'] ?></span>
    </div>
    <div class="entry-links">
      <?php if ($wh): ?><a class="btn is-small" href="<?= e($wh) ?>" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Wowhead Forever</a><?php endif; ?>
      <?php if ($type === 'zone'): ?><a class="btn is-small" href="<?= e(type_url('quest', ['sub' => $id, 'status' => 'todo'])) ?>"><i class="fas fa-scroll"></i> <?= e(t('Quests to fix', 'Quêtes à corriger')) ?></a><?php endif; ?>
      <?php if ($fixes): ?><a class="btn is-small is-gold" href="#forge"><i class="fas fa-hammer"></i> <?= e(t('Fix with the Forge', 'Corriger avec la Forge')) ?></a><?php endif; ?>
    </div>
  </div>
  <div class="entry-score">
    <div class="ring-wrap is-md"><?= ring((int)$r['score'], 104, 'is-' . $r['status']) ?><div class="ring-label"><strong><?= (int)$r['score'] ?></strong><small>%</small></div></div>
    <?= status_badge($r['status']) ?>
  </div>
</section>

<div class="entry-grid">
  <div class="entry-side">
    <?php if ($tip): ?>
    <section class="block is-tooltip">
      <header class="block-head"><div><span class="eyebrow"><?= e(t('In game', 'En jeu')) ?></span><h2><?= e(t('Tooltip', 'Infobulle')) ?></h2></div></header>
      <div class="tt-static" data-tt-static="<?= e($type . ':' . $id) ?>" data-icon="<?= e($r['icon'] ? icon_url($r['icon'], 'large') : '') ?>"><script type="application/json"><?= str_replace('</', '<\/', (string)$tip) ?></script></div>
    </section>
    <?php endif; ?>
    <section class="block">
      <header class="block-head"><div><span class="eyebrow"><?= e(t('Official data', 'Données officielles')) ?></span><h2><?= e(t('From the client', 'Depuis le client')) ?></h2></div></header>
      <dl class="facts">
        <?php if ((int)$r['level']): ?><div><dt><?= e(['item' => t('Item level', 'Niveau d’objet'), 'dungeon' => t('Players', 'Joueurs'), 'achievement' => 'Points', 'recipe' => t('Skill required', 'Compétence requise'), 'boss' => t('Order', 'Ordre')][$type] ?? t('Level', 'Niveau')) ?></dt><dd><?= (int)$r['level'] ?></dd></div><?php endif; ?>
        <?php if (!empty($data['min'])): ?><div><dt><?= e(t('Minimum level', 'Niveau minimum')) ?></dt><dd><?= (int)$data['min'] ?></dd></div><?php endif; ?>
        <?php if (isset($data['pos'])): ?><div><dt><?= e(t('Position', 'Position')) ?></dt><dd><?= e(implode(' / ', $data['pos'])) ?> · <?= e(t('map', 'carte')) ?> <?= (int)($data['map'] ?? 0) ?></dd></div><?php endif; ?>
        <?php if ($r['sub'] !== '' && !in_array($type, ['quest', 'item'], true)): ?><div><dt><?= e(t('Category', 'Catégorie')) ?></dt><dd><?= e(sub_label($r['sub'])) ?></dd></div><?php endif; ?>
        <div><dt><?= e(t('First seen', 'Vu depuis')) ?></dt><dd><?= e(t('build', 'build')) ?> <?= (int)$r['first_build'] ?></dd></div>
        <?php if ($zoneQuests): ?><div><dt><?= e(t('Quests of the zone', 'Quêtes de la zone')) ?></dt><dd class="badges"><?php foreach ($zoneQuests as $zq): ?><?= status_badge($zq['status']) ?> <b><?= (int)$zq['n'] ?></b> <?php endforeach; ?></dd></div><?php endif; ?>
      </dl>
      <?php if (!empty($data['text'])): ?><blockquote class="quote"><?= e($data['text']) ?><cite>Wowhead Forever</cite></blockquote><?php endif; ?>
      <?php if (!empty($data['unnamed'])): ?><p class="muted small"><?= e(t('The client only lists this quest ID. Run the analysis with --wowhead to name it.', 'Le client ne donne que l’ID de cette quête. Lancez l’analyse avec --wowhead pour la nommer.')) ?></p><?php endif; ?>
      <?php foreach (['spell' => t('Spell', 'Sort'), 'item' => t('Item', 'Objet'), 'teach' => t('Taught by', 'Appris par'), 'creates' => t('Creates', 'Crée'), 'ranks' => t('Ranks', 'Rangs')] as $key => $label): if (empty($teach[$key])) continue; ?>
        <p class="facts-title"><?= e($label) ?></p>
        <ul class="list is-compact"><?php foreach ($teach[$key] as $t): ?><li><?= icon_html($t, $t['type']) ?><div class="list-main"><?= name_link($t, $t['type']) ?></div><?= status_badge($t['status'], (int)$t['score']) ?></li><?php endforeach; ?></ul>
      <?php endforeach; ?>
    </section>
  </div>

  <div class="entry-main">
    <section class="block">
      <header class="block-head"><div><span class="eyebrow"><?= e(t('Server check', 'Vérification du serveur')) ?></span><h2><?= e(t('What your server provides', 'Ce que votre serveur propose')) ?></h2></div></header>
      <ol class="checks">
      <?php foreach ($checks as $c): $advice = (float)$c['w'] === 0.0; [$label, $hint] = isset($c['code']) ? check_info($c['code']) : [(string)($c['label'] ?? ''), '']; $detail = tr($c['detail'] ?? ''); ?>
        <li class="<?= $c['ok'] ? 'is-ok' : ($advice ? 'is-advice' : 'is-fail') ?>">
          <span class="check-dot"><i class="fas <?= $c['ok'] ? 'fa-check' : ($advice ? 'fa-info' : 'fa-xmark') ?>"></i></span>
          <div>
            <strong><?= e($label) ?><?php if ($advice && !$c['ok']): ?> <em class="tag"><?= e(t('advice', 'conseil')) ?></em><?php endif; ?></strong>
            <?php if ($detail !== ''): ?><p><?= e($detail) ?></p><?php endif; ?>
            <?php if (!$c['ok'] && $hint !== ''): ?><p class="hint"><i class="fas fa-wrench"></i> <?= e($hint) ?></p><?php endif; ?>
          </div>
          <span class="weight" title="<?= e(t('Weight in the score', 'Poids dans le score')) ?>"><?= $advice ? '—' : '×' . (float)$c['w'] ?></span>
        </li>
      <?php endforeach; ?>
      </ol>
    </section>

    <?php if (is_admin() && ($fixes || $applied)): ?>
    <section class="block is-forge" id="forge">
      <header class="block-head"><div><span class="eyebrow"><i class="fas fa-hammer"></i> <?= e(t('The Forge', 'La Forge')) ?></span><h2><?= e(t('Automatic fixes', 'Corrections automatiques')) ?></h2></div><a class="link" href="<?= e(url('admin')) ?>"><?= e(t('Open the Forge', 'Ouvrir la Forge')) ?> →</a></header>
      <?php foreach ($applied as $a): ?>
        <div class="alert <?= $a['state'] === 'applied' ? 'is-ok' : 'is-info' ?>"><i class="fas <?= $a['state'] === 'applied' ? 'fa-circle-check' : 'fa-rotate-left' ?>"></i><div>
          <?= e($a['state'] === 'applied' ? sprintf(t('Fix “%s” applied on %s. Run the analysis again to refresh the status.', 'Correction « %s » appliquée le %s. Relancez l’analyse pour actualiser le statut.'), $a['kind'], date(t('M j, H:i', 'd/m H:i'), strtotime($a['created_at']))) : sprintf(t('Fix “%s” was rolled back.', 'La correction « %s » a été annulée.'), $a['kind'])) ?>
          <a href="<?= e(url('admin/history')) ?>"><?= e(t('History', 'Historique')) ?> →</a></div></div>
      <?php endforeach; ?>
      <div class="fix-cards">
      <?php foreach ($fixes as $f): $sum = json_decode($f['summary'], true); ?>
        <article class="fix-card" data-fix="<?= e(json_encode(['type' => $type, 'id' => $id, 'kind' => $f['kind']])) ?>">
          <span class="fix-kind is-<?= e($f['kind']) ?>"><i class="fas <?= ['hotfix' => 'fa-database', 'trainer' => 'fa-chalkboard-user', 'instance' => 'fa-dungeon'][$f['kind']] ?? 'fa-wrench' ?>"></i></span>
          <div><strong><?= e(['hotfix' => t('Add to the core (hotfixes)', 'Ajouter au core (hotfixes)'), 'trainer' => t('Teach at trainers', 'Enseigner chez les entraîneurs'), 'instance' => t('Create the instance', 'Créer l’instance')][$f['kind']] ?? $f['kind']) ?></strong>
            <p><?= e(tr($sum)) ?></p>
            <?php if ($f['needs']): ?><p class="warn-text"><i class="fas fa-link"></i> <?= e(t('Apply the hotfix fix first: the core must know this spell.', 'Appliquez d’abord la correction hotfix : le core doit connaître ce sort.')) ?></p><?php endif; ?>
            <small><?= e(sprintf(t('%d row(s) in %s · rollback included', '%d ligne(s) dans %s · annulation incluse'), (int)$f['rows_count'], $f['db'])) ?></small></div>
          <div class="fix-actions"><button class="btn is-small" type="button" data-forge-preview><i class="fas fa-eye"></i> SQL</button><button class="btn is-small is-gold" type="button" data-forge-apply><i class="fas fa-bolt"></i> <?= e(t('Apply', 'Appliquer')) ?></button></div>
        </article>
      <?php endforeach; ?>
      <?php if (in_array($type, ['item', 'toy'], true) && array_filter($checks, fn($c) => !$c['ok'] && in_array($c['code'] ?? '', ['source', 'obtainable'], true))): ?>
        <article class="fix-card" data-fix="<?= e(json_encode(['type' => 'item', 'id' => $type === 'toy' ? (int)($data['item'] ?? 0) : $id, 'kind' => 'vendor'])) ?>">
          <span class="fix-kind is-vendor"><i class="fas fa-store"></i></span>
          <div><strong><?= e(t('Sell it at a vendor', 'Le vendre chez un marchand')) ?></strong><p><?= e(t('Adds the item to the vendor you choose in the Forge (npc_vendor).', 'Ajoute l’objet au marchand choisi dans la Forge (npc_vendor).')) ?></p></div>
          <div class="fix-actions"><a class="btn is-small is-gold" href="<?= e(url('admin', ['tab' => 'vendor', 'q' => (string)($type === 'toy' ? (int)($data['item'] ?? 0) : $id)])) ?>"><i class="fas fa-store"></i> <?= e(t('Choose a vendor', 'Choisir un marchand')) ?></a></div>
        </article>
      <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($related): ?>
    <section class="block">
      <header class="block-head"><div><span class="eyebrow"><?= e($type === 'dungeon' ? t('Encounters', 'Rencontres') : t('Instance', 'Instance')) ?></span><h2><?= e($type === 'dungeon' ? t('Bosses of this instance', 'Boss de cette instance') : t('Where this boss is fought', 'Où se combat ce boss')) ?></h2></div></header>
      <ul class="list is-grid"><?php foreach ($related as $x): ?><li><?= icon_html($x, $x['type']) ?><div class="list-main"><?= name_link($x, $x['type']) ?><small><?= e(other_name($x)) ?></small></div><?= status_badge($x['status'], (int)$x['score']) ?></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>

    <section class="block">
      <header class="block-head"><div><span class="eyebrow"><?= e(t('History', 'Historique')) ?></span><h2><?= e(t('Changes across official builds', 'Changements entre les builds officiels')) ?></h2></div></header>
      <?php if (!$history): ?><p class="muted"><?= e(t('Unchanged in every tracked build.', 'Inchangé dans tous les builds suivis.')) ?></p><?php endif; ?>
      <?php foreach ($history as $h): $fields = json_decode($h['fields'], true) ?: []; ?>
        <article class="history">
          <header><span class="kind is-<?= e($h['kind']) ?>"><?= e(kind_label($h['kind'])) ?></span> <strong><?= e($h['version'] ?: $h['build']) ?></strong> <small><?= e(t('compared with', 'comparé à')) ?> <?= (int)$h['prev_build'] ?></small></header>
          <?php if ($fields): ?><div class="diff"><?php foreach ($fields as $field => [$before, $after]): ?><div class="diff-row"><span><?= e($fieldLabels[$field] ?? $field) ?></span><del><?= e($fmt($before)) ?></del><ins><?= e($fmt($after)) ?></ins></div><?php endforeach; ?></div><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>
  </div>
</div>
<?php if (is_admin()) require FT_APP . '/pages/admin/_modals.php'; ?>
<?php page_end();
