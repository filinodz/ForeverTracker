<?php
// POST api/imports (administrators): upload or import a WowPacketParser SQL file or a ForeverScribe file, preview, apply, delete;
// apply the imported sources of items; import the game client's ForeverScribe files that changed (sync).
require_once FT_APP . '/imports.php';
if (!is_admin()) json_out(['error' => t('Administrators only.', 'Réservé aux administrateurs.')], 403);
$upload = !empty($_FILES['file']);
$input = $upload ? $_POST : (json_decode((string)file_get_contents('php://input'), true) ?: []);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf'] ?? null))) json_out(['error' => t('Session expired, reload the page.', 'Session expirée, rechargez la page.')], 400);
import_schema();
forge_schema();
@set_time_limit(0);
$dir = import_dir();
@mkdir($dir, 0775, true);

if ($upload) {
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'message' => in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? sprintf(t('The file is larger than PHP accepts (%s): copy it into %s instead and import it from the list.', 'Le fichier dépasse ce que PHP accepte (%s) : copiez-le plutôt dans %s et importez-le depuis la liste.'), ini_get('upload_max_filesize'), $dir)
            : t('Upload failed.', 'Échec de l’envoi.')]);
    }
    $name = preg_replace('/[^\w.\- ]+/u', '_', basename((string)$file['name'])) ?: 'import.sql';
    if (!preg_match('/\.(sql|lua)$/i', $name)) json_out(['ok' => false, 'message' => t('Send a .sql file in WowPacketParser\'s format, or ForeverScribe.lua.', 'Envoyez un fichier .sql au format de WowPacketParser, ou ForeverScribe.lua.')]);
    $target = $dir . '/' . date('Ymd-His') . '-' . $name;
    move_uploaded_file($file['tmp_name'], $target);
    json_out(import_file($target, pathinfo($name, PATHINFO_FILENAME)));
}

$id = (int)($input['id'] ?? 0);
$tables = array_values(array_filter((array)($input['tables'] ?? []), fn($t) => is_string($t) && preg_match('/^\w+$/', $t)));
switch ((string)($input['action'] ?? '')) {
    case 'import':
        $file = basename((string)($input['file'] ?? ''));
        if ($file === '' || !is_file("$dir/$file")) json_out(['ok' => false, 'message' => t('File not found.', 'Fichier introuvable.')]);
        json_out(import_file("$dir/$file", pathinfo($file, PATHINFO_FILENAME)));
    case 'scribe_install':
    case 'scribe_zip':
        // the ForeverScribe addon, into the game client of [client] path or as a zip
        require_once FT_APP . '/spawns.php';
        $files = [];
        foreach (glob(FT_ROOT . '/addon/ForeverScribe/*') ?: [] as $path) $files['ForeverScribe/' . basename($path)] = (string)file_get_contents($path);
        if ($input['action'] === 'scribe_zip') json_out(['ok' => true, 'zip' => base64_encode(spawn_zip($files)), 'name' => 'ForeverScribe.zip']);
        $target = spawn_client_addons();
        if ($target === '') json_out(['ok' => false, 'message' => t('Game client not found: set [client] path in config.ini (the folder with _classic_beta_ or Wow.exe), or download the addon.', 'Client du jeu introuvable : renseignez [client] path dans config.ini (le dossier contenant _classic_beta_ ou Wow.exe), ou téléchargez l’addon.')]);
        foreach ($files as $name => $content) {
            @mkdir(dirname($target . '/' . $name), 0775, true);
            if (file_put_contents($target . '/' . $name, $content) === false) json_out(['ok' => false, 'message' => sprintf(t('Cannot write %s.', 'Impossible d’écrire %s.'), $target . '/' . $name)]);
        }
        json_out(['ok' => true, 'message' => sprintf(t('ForeverScribe installed in %s. Restart the game or /reload.', 'ForeverScribe installé dans %s. Relancez le jeu ou /reload.'), $target)]);
    case 'sync':
        require_once FT_APP . '/collector.php';
        $report = collector_sync(!empty($input['apply']) && !empty($input['confirm']));
        json_out(['ok' => true, 'report' => $report, 'message' => $report ? implode(' · ', $report) : t('No new ForeverScribe notes in the game folder.', 'Aucune nouvelle note ForeverScribe dans le dossier du jeu.')]);
    case 'preview':
        $built = import_build($id, $tables);
        json_out(['ok' => true, 'rows' => $built['rows'], 'skipped' => $built['skipped'], 'tables' => $built['tables'], 'warnings' => $built['warnings'], 'count' => 1,
                  'apply' => forge_script($built['plans'], 'apply'), 'rollback' => forge_script($built['plans'], 'rollback'), 'databases' => array_keys($built['plans'])]);
    case 'apply':
        if (empty($input['confirm'])) json_out(['error' => t('Confirmation missing.', 'Confirmation manquante.')], 400);
        json_out(import_apply($id, $tables));
    case 'delete':
        import_delete($id);
        json_out(['ok' => true]);
    case 'items':
        // the imported sources of the chosen items, from every import that has them
        if (empty($input['confirm'])) json_out(['error' => t('Confirmation missing.', 'Confirmation manquante.')], 400);
        $items = array_values(array_filter(array_map('intval', (array)($input['items'] ?? []))));
        if (!$items) json_out(['ok' => false, 'message' => t('Nothing selected.', 'Rien de sélectionné.')]);
        $byImport = [];
        foreach (rows('SELECT DISTINCT import_id, item FROM ft_import_items WHERE item IN (' . implode(',', $items) . ')') as $r) $byImport[(int)$r['import_id']][] = (int)$r['item'];
        if (!$byImport) json_out(['ok' => false, 'message' => t('No imported file has these items: import WowPacketParser files first (The Forge > Imports).', 'Aucun fichier importé ne contient ces objets : importez d’abord des fichiers WowPacketParser (La Forge > Imports).')]);
        $written = 0; $logs = []; $messages = [];
        foreach ($byImport as $importId => $list) {
            $result = import_apply($importId, [], $list);
            if (!$result['ok']) { $messages[] = '#' . $importId . ': ' . ($result['message'] ?? ''); continue; }
            $written += (int)$result['rows'];
            if (!empty($result['log'])) $logs[] = $result['log'];
            if (!empty($result['message'])) $messages[] = $result['message'];
        }
        json_out(['ok' => $written > 0 || !$messages, 'rows' => $written, 'logs' => $logs, 'message' => implode(' ', $messages),
                  'items' => array_map(fn($i) => ['type' => 'item', 'id' => $i, 'kind' => 'vendor'], array_merge(...array_values($byImport)))]);
}
json_out(['error' => 'unknown action'], 400);
