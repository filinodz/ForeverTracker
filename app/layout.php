<?php
// Page shell: sidebar, top bar, command palette and footer.

/** Coverage per type for the sidebar and the dashboard: [type => [total, ok, partial, missing, score, new]]. */
function coverage(): array
{
    static $coverage = null;
    if ($coverage !== null) return $coverage;
    $coverage = [];
    if (!ready()) return $coverage;
    foreach (rows('SELECT type, status, COUNT(*) n, AVG(score) s, SUM(is_new) new_n FROM ft_entities WHERE internal = 0 GROUP BY type, status') as $r) {
        $c = $coverage[$r['type']] ?? ['total' => 0, 'ok' => 0, 'partial' => 0, 'missing' => 0, 'score_sum' => 0, 'new' => 0];
        $c['total'] += (int)$r['n'];
        $c[$r['status']] = (int)$r['n'];
        $c['score_sum'] += (float)$r['s'] * (int)$r['n'];
        $c['new'] += (int)$r['new_n'];
        $coverage[$r['type']] = $c;
    }
    foreach ($coverage as $type => $c) $coverage[$type]['score'] = $c['total'] ? $c['score_sum'] / $c['total'] : 0;
    return $coverage;
}

function page_start(string $title, string $active = '', string $description = ''): void
{
    $builds = builds();
    $client = $builds[0] ?? null;
    $meta = meta();
    $serverBuild = (int)($meta['server_build'] ?? 0);
    $behind = $client && $serverBuild && $serverBuild < (int)$client['build'];
    $coverage = coverage();
    $site = (string)config('site', 'name', 'Forever Tracker');
    $otherLang = fr() ? 'en' : 'fr';
    $query = $_GET; $query['lang'] = $otherLang;
    $nav = [
        '' => [t('Overview', 'Tableau de bord'), 'fa-chart-pie'],
        'changes' => [t('Changelog', 'Changelog'), 'fa-code-compare'],
        'todo' => [t('To add', 'À ajouter'), 'fa-list-check'],
    ];
    $todo = array_sum(array_map(fn($c) => $c['partial'] + $c['missing'], $coverage));
    ?>
<!DOCTYPE html>
<html lang="<?= e(lang()) ?>" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · <?= e($site) ?></title>
<?php if ($description !== ''): ?><meta name="description" content="<?= e($description) ?>"><?php endif; ?>
<meta name="theme-color" content="#0e0f13">
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>" type="image/svg+xml">
<script>try{var th=localStorage.getItem('ft-theme');if(th)document.documentElement.dataset.theme=th;}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body data-base="<?= e(base_path()) ?>" data-lang="<?= e(lang()) ?>">
<div class="app">
  <aside class="side" id="side" aria-label="<?= e(t('Navigation', 'Navigation')) ?>">
    <a class="brand" href="<?= e(url()) ?>">
      <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="30" height="30">
      <span><strong><?= e($site) ?></strong><small><?= e(t('Official content vs. your server', 'Contenu officiel vs votre serveur')) ?></small></span>
    </a>
    <button class="side-search" type="button" data-palette-open>
      <i class="fas fa-magnifying-glass"></i><span><?= e(t('Search…', 'Rechercher…')) ?></span><kbd>Ctrl K</kbd>
    </button>
    <nav class="side-nav">
      <?php foreach ($nav as $path => [$label, $icon]): ?>
        <a href="<?= e(url($path)) ?>" class="<?= $active === $path ? 'is-active' : '' ?>"><i class="fas <?= $icon ?>"></i><span><?= e($label) ?></span>
          <?php if ($path === 'todo' && $todo): ?><em class="pill-count"><?= number($todo) ?></em><?php endif; ?></a>
      <?php endforeach; ?>
      <?php if (is_admin()): ?>
        <a href="<?= e(url('admin')) ?>" class="<?= str_starts_with($active, 'admin') ? 'is-active' : '' ?> is-forge"><i class="fas fa-hammer"></i><span><?= e(t('The Forge', 'La Forge')) ?></span><em class="pill-count is-gold">admin</em></a>
      <?php endif; ?>
    </nav>
    <p class="side-title"><?= e(t('Resources', 'Ressources')) ?></p>
    <nav class="side-nav is-resources">
      <?php foreach (TYPES as $type => $info): $c = $coverage[$type] ?? null; $score = $c ? (int)round($c['score']) : 0; ?>
        <a href="<?= e(type_url($type)) ?>" class="<?= $active === $type ? 'is-active' : '' ?>">
          <i class="fas <?= $info[1] ?>"></i><span><?= e(type_label($type)) ?></span>
          <?php if ($c): ?><span class="mini-bar" title="<?= $score ?>%"><i style="width:<?= $score ?>%"></i></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <?php if ($client): ?>
      <div class="build-chips">
        <span class="chip" title="<?= e(t('Latest official client build', 'Dernier build officiel du client')) ?>"><i class="fas fa-cloud-arrow-down"></i><?= e(t('Client', 'Client')) ?> <b><?= e(substr((string)$client['build'], 0)) ?></b></span>
        <span class="chip <?= $behind ? 'is-warn' : 'is-ok' ?>" title="<?= e(t('DB2 build loaded by your server', 'Build des DB2 chargées par votre serveur')) ?>"><i class="fas fa-server"></i><?= e(t('Server', 'Serveur')) ?> <b><?= e($serverBuild ?: '?') ?></b></span>
      </div>
      <?php endif; ?>
      <div class="side-actions">
        <a class="icon-btn" href="?<?= e(http_build_query($query)) ?>" title="<?= e(t('Français', 'English')) ?>"><b><?= strtoupper($otherLang) ?></b></a>
        <button class="icon-btn" type="button" data-theme-toggle title="<?= e(t('Light / dark theme', 'Thème clair / sombre')) ?>"><i class="fas fa-moon"></i><i class="fas fa-sun"></i></button>
        <?php if (is_admin()): ?>
          <a class="icon-btn" href="<?= e(url('logout', ['t' => csrf_token()])) ?>" title="<?= e(t('Log out', 'Se déconnecter')) . ' (' . e(admin()['name']) . ')' ?>"><i class="fas fa-right-from-bracket"></i></a>
        <?php else: ?>
          <a class="icon-btn" href="<?= e(url('login')) ?>" title="<?= e(t('Administrator login', 'Connexion administrateur')) ?>"><i class="fas fa-user-shield"></i></a>
        <?php endif; ?>
        <?php if (config('site', 'home_url')): ?>
          <a class="icon-btn" href="<?= e(config('site', 'home_url')) ?>" title="<?= e(config('site', 'home_label') ?: t('Website', 'Site')) ?>"><i class="fas fa-house"></i></a>
        <?php endif; ?>
      </div>
    </div>
  </aside>
  <div class="scrim" data-side-close></div>
  <div class="main">
    <header class="top">
      <button class="icon-btn top-menu" type="button" data-side-open aria-label="Menu"><i class="fas fa-bars"></i></button>
      <a class="top-brand" href="<?= e(url()) ?>"><img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="28" height="28"><strong><?= e($site) ?></strong></a>
      <button class="top-search" type="button" data-palette-open><i class="fas fa-magnifying-glass"></i><span><?= e(t('Search an item, quest, spell, dungeon…', 'Chercher un objet, une quête, un sort, un donjon…')) ?></span><kbd>Ctrl K</kbd></button>
      <?php if ($client): ?>
        <span class="top-status <?= $behind ? 'is-warn' : 'is-ok' ?>"><i></i><?= $behind ? e(sprintf(t('Server %d · client %d', 'Serveur %d · client %d'), $serverBuild, (int)$client['build'])) : e(t('Server up to date', 'Serveur à jour')) ?></span>
      <?php endif; ?>
    </header>
    <main class="content" id="content">
<?php if (!ready() && !str_starts_with($active, 'login')): ?>
      <section class="empty-state"><div class="empty-icon"><i class="fas fa-database"></i></div><h1><?= e(t('No analysis yet', 'Aucune analyse pour le moment')) ?></h1>
        <p><?= e(t('Run the analysis once; the website fills itself.', 'Lancez l’analyse une fois ; le site se remplit tout seul.')) ?></p><pre class="code">python tools/tracker.py analyse</pre></section>
<?php page_end(); exit; endif;
}

function page_end(): void
{
    $meta = meta();
    $when = isset($meta['generated_at']) ? date(t('M j, Y · H:i', 'd/m/Y · H:i'), strtotime((string)$meta['generated_at'])) : '—';
    ?>
    </main>
    <footer class="foot">
      <p><strong><?= e(config('site', 'name', 'Forever Tracker')) ?></strong> · <?= e(t('Analysis of', 'Analyse du')) ?> <?= e($when) ?> · <?php if (config('site', 'repository_url')): ?><a href="<?= e(config('site', 'repository_url')) ?>" rel="noopener">ForeverTracker <?= e(FT_VERSION) ?></a><?php else: ?>ForeverTracker <?= e(FT_VERSION) ?><?php endif; ?></p>
      <p class="legal"><?= e(t('Unofficial tool, not affiliated with Blizzard Entertainment. Game content and icons © Blizzard Entertainment.', 'Outil non officiel, sans lien avec Blizzard Entertainment. Contenu du jeu et icônes © Blizzard Entertainment.')) ?></p>
    </footer>
  </div>
</div>

<div class="palette" id="palette" hidden>
  <div class="palette-box" role="dialog" aria-modal="true" aria-label="<?= e(t('Search', 'Recherche')) ?>">
    <label class="palette-input"><i class="fas fa-magnifying-glass"></i><input type="search" autocomplete="off" spellcheck="false" placeholder="<?= e(t('Name in English or French, or an ID…', 'Nom en français ou en anglais, ou un ID…')) ?>"><kbd>Esc</kbd></label>
    <div class="palette-results" role="listbox"></div>
    <div class="palette-foot"><span><kbd>↑</kbd><kbd>↓</kbd> <?= e(t('navigate', 'naviguer')) ?></span><span><kbd>↵</kbd> <?= e(t('open', 'ouvrir')) ?></span><a href="<?= e(url('search')) ?>" data-palette-all><?= e(t('All results', 'Tous les résultats')) ?> →</a></div>
  </div>
</div>
<div class="tt" id="tt" hidden></div>
<script>window.FT = {base: <?= json_encode(base_path()) ?>, lang: <?= json_encode(lang()) ?>, csrf: <?= json_encode(is_admin() ? csrf_token() : '') ?>};</script>
<script src="<?= e(asset('js/tooltip.js')) ?>" defer></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
<?php
}
