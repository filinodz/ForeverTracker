<?php
// Blizzard's patch notes for a build, shown above the datamined changelog.
db()->query('CREATE TABLE IF NOT EXISTS ft_notes (build INT PRIMARY KEY, title VARCHAR(255) NOT NULL DEFAULT \'\', url VARCHAR(500) NOT NULL DEFAULT \'\', body MEDIUMTEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$builds = builds();
$build = (int)($_GET['build'] ?? $_POST['build'] ?? ($builds[0]['build'] ?? 0));
$saved = false; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) $error = t('Session expired, please try again.', 'Session expirée, réessayez.');
    else {
        $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 250));
        $link = trim((string)($_POST['url'] ?? ''));
        $body = (string)($_POST['body'] ?? '');
        if ($link !== '' && (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $link))) $error = t('The source must be an http(s) address.', 'La source doit être une adresse http(s).');
        elseif ($title === '' && trim($body) === '') { rows('DELETE FROM ft_notes WHERE build = ?', [$build]); $saved = true; }
        else { rows('INSERT INTO ft_notes (build, title, url, body) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), url = VALUES(url), body = VALUES(body)', [$build, $title, $link, $body]); $saved = true; }
    }
}
$note = row('SELECT * FROM ft_notes WHERE build = ?', [$build]) ?? ['title' => '', 'url' => '', 'body' => ''];
page_start(t('Patch notes', 'Notes de patch'), 'admin');
?>
<section class="page-head"><div class="page-title"><span class="page-icon is-gold"><i class="fas fa-pen-to-square"></i></span>
  <div><span class="eyebrow"><a href="<?= e(url('admin')) ?>"><?= e(t('The Forge', 'La Forge')) ?></a></span><h1><?= e(t('Blizzard’s patch notes', 'Notes de mise à jour de Blizzard')) ?></h1>
  <p><?= e(t('Paste the official notes of a build and their source; they appear above the datamined changes. Supported: ## headings, - lists, **bold**, [links](https://…).', 'Collez les notes officielles d’un build et leur source ; elles s’affichent au-dessus des changements extraits. Pris en charge : ## titres, - listes, **gras**, [liens](https://…).')) ?></p></div></div></section>
<?php if ($saved): ?><div class="alert is-ok"><i class="fas fa-circle-check"></i><div><?= e(t('Saved.', 'Enregistré.')) ?> <a href="<?= e(url('changes', ['build' => $build])) ?>"><?= e(t('See the changelog', 'Voir le changelog')) ?> →</a></div></div><?php endif; ?>
<?php if ($error): ?><div class="alert is-warn"><i class="fas fa-triangle-exclamation"></i><div><?= e($error) ?></div></div><?php endif; ?>
<form method="post" class="block form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <div class="form-row">
    <label class="field is-stacked"><span>Build</span><select name="build" onchange="location.search='?build='+this.value">
      <?php foreach ($builds as $b): ?><option value="<?= (int)$b['build'] ?>" <?= (int)$b['build'] === $build ? 'selected' : '' ?>><?= e($b['version']) ?></option><?php endforeach; ?>
    </select></label>
    <label class="field is-stacked is-grow"><span><?= e(t('Title', 'Titre')) ?></span><input name="title" maxlength="250" value="<?= e($note['title']) ?>" placeholder="WoW Forever — Patch Notes"></label>
  </div>
  <label class="field is-stacked"><span><?= e(t('Source (Blizzard news or forum post)', 'Source (article ou message Blizzard)')) ?></span><input name="url" type="url" value="<?= e($note['url']) ?>" placeholder="https://"></label>
  <label class="field is-stacked"><span><?= e(t('Notes', 'Notes')) ?></span><textarea name="body" rows="18"><?= e($note['body']) ?></textarea></label>
  <button class="btn is-primary"><i class="fas fa-floppy-disk"></i> <?= e(t('Save', 'Enregistrer')) ?></button>
</form>
<?php page_end();
