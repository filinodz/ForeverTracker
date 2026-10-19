<?php
require_once FT_APP . '/auth.php';
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
if (!str_starts_with($next, base_path()) || str_contains($next, '//')) $next = url('admin');
if (is_admin()) redirect($next);
$error = '';
$_SESSION['ft_attempts'] = array_values(array_filter($_SESSION['ft_attempts'] ?? [], fn($time) => $time > time() - 600));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $error = t('Session expired, please try again.', 'Session expirée, réessayez.');
    } elseif (count($_SESSION['ft_attempts']) >= 8) {
        $error = t('Too many attempts. Wait a few minutes.', 'Trop de tentatives. Patientez quelques minutes.');
    } else {
        $account = admin_login((string)($_POST['login'] ?? ''), (string)($_POST['password'] ?? ''), $error);
        if ($account) {
            session_regenerate_id(true);
            $_SESSION['ft_admin'] = $account;
            $_SESSION['ft_attempts'] = [];
            redirect($next);
        }
        $_SESSION['ft_attempts'][] = time();
    }
}
page_start(t('Administrator login', 'Connexion administrateur'), 'login');
?>
<section class="login">
  <div class="login-card">
    <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="64" height="64">
    <h1><?= e(t('Administrator login', 'Connexion administrateur')) ?></h1>
    <p class="muted"><?= e(t('Use a game master account (Battle.net e-mail) or a local administrator from config.ini. The Forge, notes and history are reserved to administrators.', 'Utilisez un compte maître du jeu (e-mail Battle.net) ou un administrateur local de config.ini. La Forge, les notes et l’historique sont réservés aux administrateurs.')) ?></p>
    <?php if ($error): ?><div class="alert is-warn"><i class="fas fa-triangle-exclamation"></i><div><?= e($error) ?></div></div><?php endif; ?>
    <form method="post" class="form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="next" value="<?= e($next) ?>">
      <label class="field is-stacked"><span><?= e(t('E-mail or administrator name', 'E-mail ou nom d’administrateur')) ?></span><input name="login" autocomplete="username" required maxlength="190" value="<?= e($_POST['login'] ?? '') ?>"></label>
      <label class="field is-stacked"><span><?= e(t('Password', 'Mot de passe')) ?></span><input name="password" type="password" autocomplete="current-password" required maxlength="128"></label>
      <button class="btn is-primary is-block"><i class="fas fa-right-to-bracket"></i> <?= e(t('Log in', 'Se connecter')) ?></button>
    </form>
  </div>
</section>
<?php page_end();
