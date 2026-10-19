<?php
page_start(t('Page not found', 'Page introuvable'));
?>
<section class="empty-state"><div class="empty-icon"><i class="fas fa-compass"></i></div><h1><?= e(t('Lost in the Twisting Nether', 'Perdu dans le Néant distordu')) ?></h1>
  <p><?= e(t('This page does not exist. Try the search, or go back to the overview.', 'Cette page n’existe pas. Essayez la recherche, ou revenez au tableau de bord.')) ?></p>
  <div class="hero-actions is-center"><a class="btn is-primary" href="<?= e(url()) ?>"><i class="fas fa-house"></i> <?= e(t('Overview', 'Tableau de bord')) ?></a><button class="btn" type="button" data-palette-open><i class="fas fa-magnifying-glass"></i> <?= e(t('Search', 'Rechercher')) ?></button></div></section>
<?php page_end();
