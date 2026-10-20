<?php
// The setup wizard page (see app/setup.php). Standalone: it works before there is a configuration or a database.
$configured = is_file(config_path());
$other = fr() ? 'en' : 'fr';
$steps = [
    ['fa-list-check', t('Welcome', 'Bienvenue')],
    ['fa-server', t('Server', 'Serveur')],
    ['fa-database', t('Databases', 'Bases de données')],
    ['fa-gamepad', t('Game client', 'Client du jeu')],
    ['fa-user-shield', t('Access', 'Accès')],
    ['fa-rocket', t('Install', 'Installation')],
];
?>
<!DOCTYPE html>
<html lang="<?= e(lang()) ?>" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(t('Setup', 'Installation')) ?> · ForeverTracker</title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>" type="image/svg+xml">
<script>try{var th=localStorage.getItem('ft-theme');if(th)document.documentElement.dataset.theme=th;}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="setup-page">
<div class="setup">
  <header class="setup-top">
    <div class="setup-brand"><img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="34" height="34"><div><strong>ForeverTracker</strong><small><?= e(t('Setup wizard', 'Assistant d’installation')) ?> · <?= e(FT_VERSION) ?></small></div></div>
    <div class="setup-actions">
      <a class="icon-btn" href="?lang=<?= $other ?>" title="<?= e(t('Français', 'English')) ?>"><b><?= strtoupper($other) ?></b></a>
      <button class="icon-btn" type="button" data-theme-toggle title="<?= e(t('Light / dark theme', 'Thème clair / sombre')) ?>"><i class="fas fa-moon"></i><i class="fas fa-sun"></i></button>
    </div>
  </header>

  <ol class="setup-steps" data-steps>
    <?php foreach ($steps as $i => [$icon, $label]): ?><li data-step-dot="<?= $i ?>"><span><i class="fas <?= $icon ?>"></i></span><em><?= e($label) ?></em></li><?php endforeach; ?>
  </ol>

  <main class="setup-card">
    <!-- 0 welcome -->
    <section data-step="0">
      <h1><?= e($configured ? t('Change the settings', 'Modifier les réglages') : t('Welcome to ForeverTracker', 'Bienvenue dans ForeverTracker')) ?></h1>
      <p class="lead"><?= e(t('This guide connects ForeverTracker to your server and your game client, then runs the first analysis. It looks for them on this computer by itself: you only confirm.', 'Ce guide relie ForeverTracker à votre serveur et à votre client de jeu, puis lance la première analyse. Il les cherche lui-même sur cet ordinateur : vous n’avez qu’à confirmer.')) ?></p>
      <ul class="setup-checks" data-checks><li class="is-wait"><i class="fas fa-spinner fa-spin"></i><div><strong><?= e(t('Looking at this computer…', 'Examen de cet ordinateur…')) ?></strong><small><?= e(t('Servers, game clients, PHP and Python (a few seconds).', 'Serveurs, clients de jeu, PHP et Python (quelques secondes).')) ?></small></div></li></ul>
      <div class="setup-nav"><span></span><button class="btn is-gold" type="button" data-next disabled><?= e(t('Continue', 'Continuer')) ?> <i class="fas fa-arrow-right"></i></button></div>
    </section>

    <!-- 1 server -->
    <section data-step="1" hidden>
      <h1><?= e(t('Your server', 'Votre serveur')) ?></h1>
      <p class="lead"><?= e(t('The folder of your worldserver (with worldserver.conf). Its settings give the databases, the data folder and the remote console.', 'Le dossier de votre worldserver (avec worldserver.conf). Ses réglages donnent les bases de données, le dossier de données et la console distante.')) ?></p>
      <div class="choice-list" data-servers></div>
      <div class="setup-inline"><label class="field is-grow"><i class="fas fa-folder-open"></i><input data-server-path placeholder="<?= e(t('Another folder, e.g. C:/WoW/Server', 'Un autre dossier, ex. C:/WoW/Server')) ?>"></label><button class="btn" type="button" data-server-inspect><?= e(t('Use this folder', 'Utiliser ce dossier')) ?></button></div>
      <div class="setup-detail" data-server-detail hidden></div>
      <div class="setup-nav"><button class="btn" type="button" data-back><i class="fas fa-arrow-left"></i> <?= e(t('Back', 'Retour')) ?></button><button class="btn is-gold" type="button" data-next disabled><?= e(t('Continue', 'Continuer')) ?> <i class="fas fa-arrow-right"></i></button></div>
    </section>

    <!-- 2 databases -->
    <section data-step="2" hidden>
      <h1><?= e(t('Databases', 'Bases de données')) ?></h1>
      <p class="lead"><?= e(t('Filled in from worldserver.conf. ForeverTracker only reads the game databases; it writes its own tables in a separate database, created if needed.', 'Rempli depuis worldserver.conf. ForeverTracker ne fait que lire les bases du jeu ; il écrit ses propres tables dans une base à part, créée si besoin.')) ?></p>
      <div class="form-grid">
        <label class="form-field"><span><?= e(t('Host', 'Hôte')) ?></span><input data-db="host" value="127.0.0.1"></label>
        <label class="form-field"><span><?= e(t('Port', 'Port')) ?></span><input data-db="port" type="number" value="3306"></label>
        <label class="form-field"><span><?= e(t('User', 'Utilisateur')) ?></span><input data-db="user" autocomplete="off"></label>
        <label class="form-field"><span><?= e(t('Password', 'Mot de passe')) ?></span><input data-db="password" type="password" autocomplete="new-password"></label>
        <label class="form-field"><span>auth</span><input data-db="auth" value="auth"></label>
        <label class="form-field"><span>world</span><input data-db="world" value="world"></label>
        <label class="form-field"><span>hotfixes</span><input data-db="hotfixes" value="hotfixes"></label>
        <label class="form-field"><span><?= e(t('ForeverTracker database', 'Base de ForeverTracker')) ?></span><input data-db="tracker" value="forever_tracker"></label>
      </div>
      <div class="setup-detail" data-db-result hidden></div>
      <div class="setup-nav"><button class="btn" type="button" data-back><i class="fas fa-arrow-left"></i> <?= e(t('Back', 'Retour')) ?></button>
        <div class="btn-row"><button class="btn" type="button" data-db-test><i class="fas fa-plug"></i> <?= e(t('Test the connection', 'Tester la connexion')) ?></button><button class="btn is-gold" type="button" data-next disabled><?= e(t('Continue', 'Continuer')) ?> <i class="fas fa-arrow-right"></i></button></div></div>
    </section>

    <!-- 3 client -->
    <section data-step="3" hidden>
      <h1><?= e(t('Game client', 'Client du jeu')) ?></h1>
      <p class="lead"><?= e(t('The official client is the reference: ForeverTracker stores its data (DB2 files) for each build and compares your server with it.', 'Le client officiel est la référence : ForeverTracker enregistre ses données (fichiers DB2) pour chaque build et compare votre serveur avec.')) ?></p>
      <div class="choice-list" data-clients></div>
      <div class="setup-inline"><label class="field is-grow"><i class="fas fa-folder-open"></i><input data-client-path placeholder="<?= e(t('Another folder (the one with .build.info)', 'Un autre dossier (celui avec .build.info)')) ?>"></label><button class="btn" type="button" data-client-inspect><?= e(t('Use this folder', 'Utiliser ce dossier')) ?></button></div>
      <h2><?= e(t('Where to read the official data', 'Où lire les données officielles')) ?></h2>
      <div class="choice-list" data-sources></div>
      <div class="setup-options">
        <span><?= e(t('Languages', 'Langues')) ?></span>
        <label class="checkbox-line"><input type="checkbox" data-locale="enUS" checked> English (enUS)</label>
        <label class="checkbox-line"><input type="checkbox" data-locale="frFR"> Français (frFR)</label>
      </div>
      <div class="setup-nav"><button class="btn" type="button" data-back><i class="fas fa-arrow-left"></i> <?= e(t('Back', 'Retour')) ?></button><button class="btn is-gold" type="button" data-next disabled><?= e(t('Continue', 'Continuer')) ?> <i class="fas fa-arrow-right"></i></button></div>
    </section>

    <!-- 4 access -->
    <section data-step="4" hidden>
      <h1><?= e(t('Access', 'Accès')) ?></h1>
      <p class="lead"><?= e(t('The administrator opens the Forge, the patch notes and the history. Game masters can also log in with their game account later.', 'L’administrateur ouvre la Forge, les notes de patch et l’historique. Les MJ pourront aussi se connecter avec leur compte de jeu.')) ?></p>
      <div class="form-grid">
        <label class="form-field"><span><?= e(t('Administrator name', 'Nom de l’administrateur')) ?></span><input data-admin="name" value="admin" autocomplete="off"></label>
        <label class="form-field"><span><?= e(t('Password (10 characters or more)', 'Mot de passe (10 caractères ou plus)')) ?></span><input data-admin="password" type="password" autocomplete="new-password"></label>
        <label class="form-field"><span><?= e(t('Password again', 'Mot de passe, encore')) ?></span><input data-admin="confirm" type="password" autocomplete="new-password"></label>
        <label class="form-field"><span><?= e(t('Default language of the website', 'Langue par défaut du site')) ?></span><select data-language><option value="en">English</option><option value="fr" <?= fr() ? 'selected' : '' ?>>Français</option></select></label>
      </div>
      <?php if ($configured): ?><p class="muted small"><?= e(t('Leave the password empty to keep the current administrators.', 'Laissez le mot de passe vide pour garder les administrateurs actuels.')) ?></p><?php endif; ?>
      <h2><?= e(t('Remote console (optional)', 'Console distante (optionnel)')) ?></h2>
      <p class="muted small"><?= e(t('Lets the Forge spawn creatures on the running server. A game account (like 1#1) with GM level 3 on all realms; the worldserver needs Ra.Enable = 1 and the ForeverTracker core module. You can set it later in config.ini.', 'Permet à la Forge de placer des PNJ sur le serveur en marche. Un compte de jeu (comme 1#1) de niveau MJ 3 sur tous les royaumes ; le worldserver a besoin de Ra.Enable = 1 et du module ForeverTracker du core. Réglable plus tard dans config.ini.')) ?></p>
      <div class="form-grid">
        <label class="form-field"><span><?= e(t('Console host', 'Hôte de la console')) ?></span><input data-ra="host" value="127.0.0.1"></label>
        <label class="form-field"><span><?= e(t('Console port', 'Port de la console')) ?></span><input data-ra="port" type="number" value="3443"></label>
        <label class="form-field"><span><?= e(t('Game account', 'Compte de jeu')) ?></span><input data-ra="user" autocomplete="off" placeholder="1#1"></label>
        <label class="form-field"><span><?= e(t('Its password', 'Son mot de passe')) ?></span><input data-ra="password" type="password" autocomplete="new-password"></label>
      </div>
      <div class="setup-nav"><button class="btn" type="button" data-back><i class="fas fa-arrow-left"></i> <?= e(t('Back', 'Retour')) ?></button><button class="btn is-gold" type="button" data-next><?= e(t('Continue', 'Continuer')) ?> <i class="fas fa-arrow-right"></i></button></div>
    </section>

    <!-- 5 install -->
    <section data-step="5" hidden>
      <h1><?= e(t('Ready to install', 'Prêt à installer')) ?></h1>
      <dl class="setup-summary" data-summary></dl>
      <label class="checkbox-line"><input type="checkbox" data-locate checked> <?= e(t('Also run the locator: finds the creatures your quests and vendors need and where they stand, through Wowhead (a few minutes the first time).', 'Lancer aussi le localisateur : trouve les PNJ dont vos quêtes et marchands ont besoin et où ils se trouvent, via Wowhead (quelques minutes la première fois).')) ?></label>
      <ol class="setup-progress" data-progress hidden>
        <li data-progress-step="config"><i class="fas fa-circle"></i><?= e(t('Configuration written', 'Configuration écrite')) ?></li>
        <li data-progress-step="extract"><i class="fas fa-circle"></i><?= e(t('Client data extracted (mapextractor)', 'Données du client extraites (mapextractor)')) ?></li>
        <li data-progress-step="snapshot"><i class="fas fa-circle"></i><?= e(t('Client build stored', 'Build du client enregistré')) ?></li>
        <li data-progress-step="analyse"><i class="fas fa-circle"></i><?= e(t('Server analysed (about 3 minutes)', 'Serveur analysé (environ 3 minutes)')) ?></li>
        <li data-progress-step="locate"><i class="fas fa-circle"></i><?= e(t('Missing creatures located', 'PNJ manquants localisés')) ?></li>
      </ol>
      <pre class="analysis-log setup-log" data-log hidden></pre>
      <div class="setup-nav"><button class="btn" type="button" data-back><i class="fas fa-arrow-left"></i> <?= e(t('Back', 'Retour')) ?></button>
        <div class="btn-row"><button class="btn is-gold" type="button" data-install><i class="fas fa-rocket"></i> <?= e(t('Save and install', 'Enregistrer et installer')) ?></button><a class="btn is-gold" href="<?= e(url()) ?>" data-open hidden><i class="fas fa-arrow-right"></i> <?= e(t('Open ForeverTracker', 'Ouvrir ForeverTracker')) ?></a></div></div>
    </section>
  </main>
  <p class="setup-foot"><?= e(t('Everything stays on this computer. Nothing is sent anywhere, except the locator\'s questions to Wowhead\'s public tooltips.', 'Tout reste sur cet ordinateur. Rien n’est envoyé, sauf les questions du localisateur aux infobulles publiques de Wowhead.')) ?> · <a href="https://github.com/filinodz/ForeverTracker/blob/main/docs/INSTALL.md" target="_blank" rel="noopener"><?= e(t('Installation guide', 'Guide d’installation')) ?></a></p>
</div>
<div class="toasts" id="toasts" aria-live="polite"></div>
<script>window.FT = {base: <?= json_encode(base_path()) ?>, lang: <?= json_encode(lang()) ?>, csrf: <?= json_encode(csrf_token()) ?>, configured: <?= $configured ? 'true' : 'false' ?>};</script>
<script src="<?= e(asset('js/setup.js')) ?>" defer></script>
</body>
</html>
