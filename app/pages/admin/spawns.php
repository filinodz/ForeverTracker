<?php
// The Forge · Spawns: creatures and world objects the content needs but nobody spawned, with their official positions (locator).
require_once FT_APP . '/spawns.php';
forge_schema();
$hasTable = table_exists('ft_spawns');
$states = ['ready' => 'fa-location-dot', 'manual' => 'fa-hand-pointer', 'unknown' => 'fa-circle-question', 'pending' => 'fa-hourglass-half'];
$state = isset($states[$_GET['state'] ?? '']) ? $_GET['state'] : 'ready';
$role = in_array($_GET['role'] ?? '', SPAWN_ROLES, true) ? $_GET['role'] : '';
$kindFilter = in_array($_GET['kind'] ?? '', ['npc', 'go'], true) ? $_GET['kind'] : '';
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 60));
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 100;

$counts = []; $roleCounts = []; $list = []; $total = 0; $updated = null;
if ($hasTable) {
    foreach (rows('SELECT state, COUNT(*) n FROM ft_spawns GROUP BY state') as $x) $counts[$x['state']] = (int)$x['n'];
    $where = ['state = ?']; $params = [$state];
    if ($role !== '') { $where[] = 'JSON_SEARCH(reasons, \'one\', ?) IS NOT NULL'; $params[] = $role; }
    if ($kindFilter !== '') { $where[] = 'kind = ?'; $params[] = $kindFilter; }
    if ($q !== '') {
        if (ctype_digit($q)) { $where[] = 'entry = ?'; $params[] = (int)$q; }
        else { $where[] = 'name LIKE ?'; $params[] = '%' . addcslashes($q, '%_\\') . '%'; }
    }
    $whereSql = implode(' AND ', $where);
    foreach (rows("SELECT reasons FROM ft_spawns WHERE state = ?", [$state]) as $x)
        foreach (array_unique(array_column(json_decode($x['reasons'], true) ?: [], 0)) as $r) $roleCounts[$r] = ($roleCounts[$r] ?? 0) + 1;
    $total = (int)value("SELECT COUNT(*) FROM ft_spawns WHERE $whereSql", $params);
    $list = rows("SELECT * FROM ft_spawns WHERE $whereSql ORDER BY priority, map, name LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
    $updated = value('SELECT MAX(updated_at) FROM ft_spawns');
}
// names of the places and of the quests / items / spells the spawns are for
$zones = []; $refs = [];
if ($list) {
    $zoneIds = array_filter(array_unique(array_map(fn($x) => (int)$x['zone'], $list)));
    $mapIds = array_filter(array_unique(array_map(fn($x) => (int)$x['map'], $list)), fn($m) => $m !== null);
    if ($zoneIds) foreach (rows("SELECT id, name, name_en FROM ft_entities WHERE type = 'zone' AND id IN (" . implode(',', $zoneIds) . ')') as $z) $zones['z' . $z['id']] = display_name($z);
    if ($mapIds) foreach (rows("SELECT id, name, name_en FROM ft_entities WHERE type = 'dungeon' AND id IN (" . implode(',', $mapIds) . ')') as $m) $zones['m' . $m['id']] = display_name($m);
    $byType = [];
    foreach ($list as $x) foreach (json_decode($x['reasons'], true) ?: [] as [, $type, $id]) $byType[$type][(int)$id] = true;
    foreach ($byType as $type => $ids) {
        if (!isset(TYPES[$type])) continue;
        foreach (rows('SELECT type, id, name, name_en FROM ft_entities WHERE type = ? AND id IN (' . implode(',', array_keys($ids)) . ')', [$type]) as $r) $refs[$type . ':' . $r['id']] = display_name($r);
    }
}
$continents = [0 => t('Eastern Kingdoms', 'Royaumes de l’Est'), 1 => t('Kalimdor', 'Kalimdor')];
$place = function (array $x) use ($zones, $continents): string {
    $zone = $zones['z' . (int)$x['zone']] ?? null;
    $map = $x['map'] === null ? null : ($zones['m' . (int)$x['map']] ?? $continents[(int)$x['map']] ?? t('Map', 'Carte') . ' ' . (int)$x['map']);
    return implode(' · ', array_filter([$zone, $zone !== $map ? $map : null]));
};
$query = fn(array $change) => '?' . http_build_query(array_filter(array_merge(['state' => $state, 'role' => $role, 'kind' => $kindFilter, 'q' => $q], $change), fn($v) => $v !== '' && $v !== null));
$status = analysis_status();
page_start(t('Spawns', 'Placements'), 'admin');
?>
<section class="page-head is-forge">
  <div class="page-title">
    <span class="page-icon is-gold"><i class="fas fa-location-crosshairs"></i></span>
    <div><span class="eyebrow"><a href="<?= e(url('admin')) ?>"><?= e(t('The Forge', 'La Forge')) ?></a></span><h1><?= e(t('Spawns', 'Placements')) ?></h1>
    <p><?= e(t('Quest givers, objectives, vendors, trainers, rare drops and bosses your server knows but never spawned, with their positions in the official game. Spawn them from here, from the database or in game with the ForeverForge addon.', 'Donneurs de quête, objectifs, marchands, entraîneurs, butins rares et boss que votre serveur connaît mais n’a jamais placés, avec leur position dans le jeu officiel. Placez-les d’ici, par la base de données ou en jeu avec l’addon ForeverForge.')) ?></p></div>
  </div>
  <div class="analysis-card" data-analysis data-command="locate" data-running="<?= $status['running'] ? 1 : 0 ?>">
    <div><strong><i class="fas fa-map-location-dot"></i> <?= e(t('Locator', 'Localisateur')) ?></strong>
      <small data-analysis-text><?= $updated ? e(sprintf(t('Last run: %s', 'Dernier : %s'), date(t('M j, H:i', 'd/m H:i'), strtotime((string)$updated)))) : e(t('Never run', 'Jamais lancé')) ?></small></div>
    <button class="btn is-small" type="button" data-analysis-start <?= $status['running'] ? 'disabled' : '' ?>><i class="fas fa-play"></i> <?= e($updated ? t('Run again', 'Relancer') : t('Run', 'Lancer')) ?></button>
    <pre class="analysis-log" data-analysis-log <?= $status['log'] === '' ? 'hidden' : '' ?>><?= e($status['log']) ?></pre>
  </div>
</section>

<nav class="tabs" aria-label="Forge">
  <a href="<?= e(url('admin')) ?>"><i class="fas fa-hammer"></i><?= e(t('Fixes', 'Corrections')) ?></a>
  <?php foreach ($states as $key => $icon): ?>
    <a class="<?= $state === $key ? 'is-active' : '' ?>" href="<?= e(url('admin/spawns', ['state' => $key])) ?>"><i class="fas <?= $icon ?>"></i><?= e(spawn_state_label($key)) ?><em><?= number($counts[$key] ?? 0) ?></em></a>
  <?php endforeach; ?>
  <a href="<?= e(url('admin/imports')) ?>"><i class="fas fa-file-import"></i><?= e(t('Imports', 'Imports')) ?></a>
  <a href="<?= e(url('admin/history')) ?>"><i class="fas fa-clock-rotate-left"></i><?= e(t('History', 'Historique')) ?></a>
</nav>
<p class="tab-desc"><i class="fas fa-circle-info"></i> <?= e([
    'ready' => t('Positions converted from Wowhead\'s official map coordinates. The height comes from the server when it spawns them (server mode, addon), or from the nearest existing spawn (database mode).', 'Positions converties depuis les coordonnées officielles de Wowhead. La hauteur vient du serveur quand il les place (mode serveur, addon), ou du placement existant le plus proche (mode base de données).'),
    'manual' => t('Inside dungeons and raids Wowhead gives no position: export them to the ForeverForge addon, enter the instance, stand where each one belongs and press "Spawn here".', 'Dans les donjons et raids, Wowhead ne donne pas de position : exportez-les vers l’addon ForeverForge, entrez dans l’instance, placez-vous à l’endroit voulu et appuyez sur « Placer ici ».'),
    'unknown' => t('Wowhead\'s Forever branch has no position for these: often content Blizzard does not use in Forever, or a creature that only appears through a script or an event.', 'La branche Forever de Wowhead n’a aucune position pour eux : souvent du contenu que Blizzard n’utilise pas dans Forever, ou un PNJ qui n’apparaît que par script ou événement.'),
    'pending' => t('Not looked up yet: run the locator again (it only asks Wowhead for what it does not know).', 'Pas encore recherchés : relancez le localisateur (il ne demande à Wowhead que ce qu’il ne connaît pas).'),
][$state]) ?></p>

<?php if (!$hasTable): ?>
  <div class="empty-state is-inline"><div class="empty-icon"><i class="fas fa-map-location-dot"></i></div><p><?= e(t('Run the locator once: it lists what is missing and asks Wowhead where it stands (a few minutes the first time).', 'Lancez le localisateur une fois : il liste ce qui manque et demande à Wowhead où cela se trouve (quelques minutes la première fois).')) ?></p></div>
<?php else: ?>
<form class="toolbar" method="get" data-autosubmit-form>
  <input type="hidden" name="state" value="<?= e($state) ?>">
  <label class="field is-search"><i class="fas fa-magnifying-glass"></i><input name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Filter by name or entry…', 'Filtrer par nom ou entry…')) ?>"></label>
  <label class="field is-select"><span><?= e(t('Needed as', 'Rôle')) ?></span><select name="role" data-autosubmit><option value=""><?= e(t('All', 'Tous')) ?></option>
    <?php foreach (SPAWN_ROLES as $r): if (empty($roleCounts[$r])) continue; ?><option value="<?= e($r) ?>" <?= $role === $r ? 'selected' : '' ?>><?= e(spawn_role_label($r)) ?> (<?= $roleCounts[$r] ?>)</option><?php endforeach; ?></select></label>
  <label class="field is-select"><span><?= e(t('Kind', 'Genre')) ?></span><select name="kind" data-autosubmit><option value=""><?= e(t('All', 'Tous')) ?></option>
    <option value="npc" <?= $kindFilter === 'npc' ? 'selected' : '' ?>><?= e(t('Creatures', 'PNJ')) ?></option><option value="go" <?= $kindFilter === 'go' ? 'selected' : '' ?>><?= e(t('World objects', 'Objets du monde')) ?></option></select></label>
</form>

<p class="result-count"><b><?= number($total) ?></b> <?= e(t('creatures and objects', 'PNJ et objets')) ?></p>
<?php if (!$list): ?>
  <div class="empty-state is-inline"><div class="empty-icon"><i class="fas fa-champagne-glasses"></i></div><p><?= e(t('Nothing here.', 'Rien ici.')) ?></p></div>
<?php else: ?>
<div class="table-card" data-spawn-table data-state="<?= e($state) ?>" data-filter='<?= e(json_encode(['state' => $state, 'role' => $role, 'kind' => $kindFilter, 'q' => $q])) ?>' data-total="<?= $total ?>">
<table class="table is-forge">
  <thead><tr><th class="check-col"><label class="checkbox"><input type="checkbox" data-select-all><span></span></label></th><th><?= e(t('Creature / object', 'PNJ / objet')) ?></th><th><?= e(t('Needed for', 'Nécessaire pour')) ?></th><th><?= e(t('Where', 'Où')) ?></th><th class="num"><?= e(t('Spawns', 'Placements')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($list as $x): $points = json_decode((string)$x['points'], true) ?: []; $reasons = json_decode((string)$x['reasons'], true) ?: [];
        $hinted = count(array_filter($points, fn($p) => $p[2] !== null && $p[3] !== null && $p[3] <= 15)); ?>
    <tr>
      <td class="check-col"><?php if (in_array($x['state'], ['ready', 'manual'], true)): ?><label class="checkbox"><input type="checkbox" data-spawn-pick='<?= e(json_encode(['kind' => $x['kind'], 'entry' => (int)$x['entry']])) ?>'><span></span></label><?php endif; ?></td>
      <td class="res"><span class="ic is-plain"><i class="fas <?= $x['kind'] === 'npc' ? 'fa-user' : 'fa-cube' ?>"></i></span><div><strong><?= e($x['name'] !== '' ? $x['name'] : '#' . $x['entry']) ?></strong>
        <small><?= e($x['kind'] === 'npc' ? t('Creature', 'PNJ') : t('World object', 'Objet du monde')) ?> · entry <?= (int)$x['entry'] ?> · <a href="https://www.wowhead.com/<?= e((string)config('wowhead', 'branch', 'forever')) ?>/<?= $x['kind'] === 'npc' ? 'npc' : 'object' ?>=<?= (int)$x['entry'] ?>" target="_blank" rel="noopener">Wowhead <i class="fas fa-arrow-up-right-from-square"></i></a></small></div></td>
      <td class="fix-cell"><?php foreach (array_slice($reasons, 0, 3) as [$r, $type, $id]): $label = $refs[$type . ':' . $id] ?? (type_label($type) . ' ' . $id); ?>
        <span class="reason"><em class="tag is-plain"><?= e(spawn_role_label($r)) ?></em> <?php if (isset(TYPES[$type])): ?><a href="<?= e(entry_url($type, (int)$id)) ?>" data-tt="<?= e($type . ':' . (int)$id) ?>"><?= e($label) ?></a><?php else: ?><?= e($label) ?><?php endif; ?></span>
      <?php endforeach; ?><?= count($reasons) > 3 ? '<small class="muted">+' . (count($reasons) - 3) . '</small>' : '' ?></td>
      <td><?= e($place($x)) ?: '<span class="muted">—</span>' ?><?php if ($x['note'] !== ''): ?><small class="muted"><?= e($x['note']) ?></small><?php endif; ?></td>
      <td class="num"><?= count($points) ?: ($x['state'] === 'manual' ? '1' : '—') ?><?php if ($points): ?><small class="<?= $hinted === count($points) ? 'ok-text' : 'muted' ?>" title="<?= e(t('Points with an existing spawn closer than 15 yards (height for the database mode)', 'Points avec un placement existant à moins de 15 mètres (hauteur pour le mode base de données)')) ?>"><?= $hinted ?>/<?= count($points) ?> <i class="fas fa-ruler-vertical"></i></small><?php endif; ?></td>
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
<?php endif; endif; endif; ?>

<div class="action-bar" data-spawn-bar hidden>
  <div class="action-count"><strong data-selected-count>0</strong> <span><?= e(t('selected', 'sélectionné(s)')) ?></span></div>
  <?php if ($total > $perPage || $total > 0): ?><button class="btn is-small" type="button" data-spawn-all><i class="fas fa-check-double"></i> <?= e(sprintf(t('All %s', 'Les %s'), number($total))) ?></button><?php endif; ?>
  <button class="btn is-small is-gold" type="button" data-spawn-open><i class="fas fa-location-crosshairs"></i> <?= e(t('Spawn…', 'Placer…')) ?></button>
</div>

<div class="modal" id="spawn-dialog" hidden>
  <div class="modal-box is-wide" role="dialog" aria-modal="true" aria-labelledby="spawn-dialog-title">
    <header><h2 id="spawn-dialog-title"><i class="fas fa-location-crosshairs"></i> <?= e(t('Spawn the selection', 'Placer la sélection')) ?></h2><button class="icon-btn" type="button" data-modal-close aria-label="<?= e(t('Close', 'Fermer')) ?>"><i class="fas fa-xmark"></i></button></header>
    <div class="modal-body">
      <p class="muted" data-spawn-summary></p>
      <div class="forge-warnings" data-spawn-warnings></div>
      <div class="mode-grid">
        <section class="mode-card" data-mode="server">
          <header><i class="fas fa-server"></i><div><strong><?= e(t('On the running server', 'Sur le serveur en marche')) ?></strong><small><?= e(t('Recommended · exact height · live · undoable', 'Conseillé · hauteur exacte · immédiat · annulable')) ?></small></div></header>
          <p><?= e(t('The website sends ".forge spawn" through the worldserver\'s remote console. The server computes the ground height (buildings included), saves each spawn and shows it at once.', 'Le site envoie « .forge spawn » par la console distante du worldserver. Le serveur calcule la hauteur du sol (bâtiments compris), enregistre chaque placement et l’affiche aussitôt.')) ?></p>
          <p class="mode-status" data-console-status><i class="fas fa-spinner fa-spin"></i> <?= e(t('Checking the remote console…', 'Vérification de la console distante…')) ?></p>
          <button class="btn is-gold" type="button" data-spawn-go="server" disabled><i class="fas fa-bolt"></i> <?= e(t('Spawn now', 'Placer maintenant')) ?></button>
        </section>
        <section class="mode-card" data-mode="addon">
          <header><i class="fas fa-gamepad"></i><div><strong><?= e(t('In game, with the ForeverForge addon', 'En jeu, avec l’addon ForeverForge')) ?></strong><small><?= e(t('Any TrinityCore server · bosses and dungeons', 'Tout serveur TrinityCore · boss et donjons')) ?></small></div></header>
          <p><?= e(t('A GM types /forge and presses Start: the addon goes to each point and spawns it with the standard commands (or in place with the core module). Bosses are placed by hand with "Spawn here".', 'Un MJ tape /forge et appuie sur Démarrer : l’addon va à chaque point et le place avec les commandes standard (ou sur place avec le module du core). Les boss se placent à la main avec « Placer ici ».')) ?></p>
          <p class="mode-status" data-client-status></p>
          <div class="btn-row"><button class="btn is-gold" type="button" data-spawn-go="install" hidden><i class="fas fa-download"></i> <?= e(t('Install into the game', 'Installer dans le jeu')) ?></button><button class="btn" type="button" data-spawn-go="zip"><i class="fas fa-file-zipper"></i> <?= e(t('Download the addon', 'Télécharger l’addon')) ?></button></div>
        </section>
        <section class="mode-card" data-mode="database">
          <header><i class="fas fa-database"></i><div><strong><?= e(t('In the database', 'Dans la base de données')) ?></strong><small><?= e(t('Worldserver stopped · height from the nearest spawn', 'Worldserver arrêté · hauteur du placement voisin')) ?></small></div></header>
          <p><?= e(t('Writes creature / gameobject rows in one transaction, with an exact rollback. Only the points that have an existing spawn within 15 yards are written: their height is borrowed from it.', 'Écrit les lignes creature / gameobject en une transaction, avec une annulation exacte. Seuls les points ayant un placement existant à moins de 15 mètres sont écrits : leur hauteur lui est empruntée.')) ?></p>
          <p class="mode-status" data-db-status></p>
          <div class="btn-row"><button class="btn" type="button" data-spawn-go="sql"><i class="fas fa-code"></i> <?= e(t('Preview SQL', 'Voir le SQL')) ?></button><button class="btn is-gold" type="button" data-spawn-go="database"><i class="fas fa-bolt"></i> <?= e(t('Write', 'Écrire')) ?></button></div>
        </section>
        <section class="mode-card" data-mode="commands">
          <header><i class="fas fa-terminal"></i><div><strong><?= e(t('GM commands', 'Commandes MJ')) ?></strong><small><?= e(t('By hand, anywhere', 'À la main, partout')) ?></small></div></header>
          <p><?= e(t('The list of ".go xyz" and ".npc add" / ".gobject add" commands, to paste in game one by one or in a macro.', 'La liste des commandes « .go xyz » et « .npc add » / « .gobject add », à coller en jeu une à une ou dans une macro.')) ?></p>
          <div class="btn-row"><button class="btn" type="button" data-spawn-go="commands"><i class="fas fa-copy"></i> <?= e(t('Copy', 'Copier')) ?></button><button class="btn" type="button" data-spawn-go="commands-file"><i class="fas fa-download"></i> .txt</button></div>
        </section>
      </div>
    </div>
  </div>
</div>
<?php require FT_APP . '/pages/admin/_modals.php'; ?>
<?php page_end();
