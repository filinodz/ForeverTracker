<?php // Forge dialogs shared by the Forge and the resource pages. ?>
<div class="modal" id="forge-preview" hidden>
  <div class="modal-box is-wide" role="dialog" aria-modal="true" aria-labelledby="forge-preview-title">
    <header><h2 id="forge-preview-title"><i class="fas fa-code"></i> <?= e(t('SQL preview', 'Aperçu SQL')) ?></h2><button class="icon-btn" type="button" data-modal-close aria-label="<?= e(t('Close', 'Fermer')) ?>"><i class="fas fa-xmark"></i></button></header>
    <div class="modal-body">
      <div class="forge-warnings" data-forge-warnings></div>
      <div class="sql-tabs"><button type="button" class="is-active" data-sql-tab="apply"><?= e(t('Apply', 'Application')) ?></button><button type="button" data-sql-tab="rollback"><?= e(t('Rollback', 'Annulation')) ?></button><span data-sql-meta></span></div>
      <pre class="code is-sql" data-sql></pre>
    </div>
    <footer><button class="btn" type="button" data-sql-copy><i class="fas fa-copy"></i> <?= e(t('Copy', 'Copier')) ?></button><button class="btn" type="button" data-sql-download><i class="fas fa-download"></i> <?= e(t('Download', 'Télécharger')) ?></button><button class="btn is-gold" type="button" data-sql-apply><i class="fas fa-bolt"></i> <?= e(t('Apply…', 'Appliquer…')) ?></button></footer>
  </div>
</div>
<div class="modal" id="forge-confirm" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="forge-confirm-title">
    <header><h2 id="forge-confirm-title"><i class="fas fa-bolt"></i> <?= e(t('Apply to the server databases?', 'Appliquer aux bases du serveur ?')) ?></h2><button class="icon-btn" type="button" data-modal-close aria-label="<?= e(t('Close', 'Fermer')) ?>"><i class="fas fa-xmark"></i></button></header>
    <div class="modal-body">
      <p data-confirm-text></p>
      <ul class="confirm-points">
        <li><i class="fas fa-shield-halved"></i><?= e(t('Everything runs in one transaction: if a statement fails, nothing is kept.', 'Tout s’exécute dans une transaction : si une requête échoue, rien n’est conservé.')) ?></li>
        <li><i class="fas fa-rotate-left"></i><?= e(t('The rollback is saved in the history and can be run at any time.', 'L’annulation est enregistrée dans l’historique et peut être lancée à tout moment.')) ?></li>
        <li><i class="fas fa-server"></i><?= e(t('Restart the worldserver (hotfixes, trainers) or reload its tables for the change to show in game.', 'Redémarrez le worldserver (hotfixes, entraîneurs) ou rechargez ses tables pour voir le changement en jeu.')) ?></li>
      </ul>
      <label class="checkbox-line"><input type="checkbox" data-confirm-check> <?= e(t('I have a backup of my databases.', 'J’ai une sauvegarde de mes bases de données.')) ?></label>
    </div>
    <footer><button class="btn" type="button" data-modal-close><?= e(t('Cancel', 'Annuler')) ?></button><button class="btn is-gold" type="button" data-confirm-go disabled><i class="fas fa-bolt"></i> <?= e(t('Apply now', 'Appliquer maintenant')) ?></button></footer>
  </div>
</div>
<div class="toasts" id="toasts" aria-live="polite"></div>
