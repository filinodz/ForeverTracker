<?php
// POST api/forge (administrators): preview, apply, rollback, vendor lookup, background analysis.
require_once FT_APP . '/forge.php';
if (!is_admin()) json_out(['error' => t('Administrators only.', 'Réservé aux administrateurs.')], 403);
$input = json_decode((string)file_get_contents('php://input'), true) ?: [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf'] ?? null))) json_out(['error' => t('Session expired, reload the page.', 'Session expirée, rechargez la page.')], 400);

$action = (string)($input['action'] ?? '');
$selection = is_array($input['items'] ?? null) ? $input['items'] : [];
$options = ['vendor' => (int)($input['vendor'] ?? 0), 'push' => !empty($input['push'])];

switch ($action) {
    case 'preview':
        $built = forge_build($selection, $options);
        json_out(['ok' => true, 'rows' => $built['rows'], 'skipped' => $built['skipped'], 'tables' => $built['tables'], 'warnings' => $built['warnings'], 'count' => count($built['items']),
                  'apply' => forge_script($built['plans'], 'apply'), 'rollback' => forge_script($built['plans'], 'rollback'), 'databases' => array_keys($built['plans'])]);
    case 'apply':
        if (empty($input['confirm'])) json_out(['error' => t('Confirmation missing.', 'Confirmation manquante.')], 400);
        json_out(forge_apply($selection, $options));
    case 'rollback':
        json_out(forge_rollback((int)($input['log'] ?? 0)));
    case 'vendors':
        $q = trim(mb_substr((string)($input['q'] ?? ''), 0, 60));
        $params = ctype_digit($q) && $q !== '' ? [(int)$q] : ['%' . addcslashes($q, '%_\\') . '%'];
        $where = ctype_digit($q) && $q !== '' ? 'ct.entry = ?' : 'ct.npcflag & 128 AND ct.name LIKE ?';
        $list = rows("SELECT ct.entry, ct.name, ct.subname, ct.npcflag, (SELECT COUNT(*) FROM creature c WHERE c.id = ct.entry) spawns,
            (SELECT COUNT(*) FROM npc_vendor v WHERE v.entry = ct.entry) items FROM creature_template ct WHERE $where ORDER BY spawns > 0 DESC, items DESC LIMIT 15", $params, 'world');
        json_out(['ok' => true, 'vendors' => array_map(fn($v) => ['entry' => (int)$v['entry'], 'name' => $v['name'], 'subname' => (string)$v['subname'],
            'spawns' => (int)$v['spawns'], 'items' => (int)$v['items'], 'vendor' => ((int)$v['npcflag'] & 128) > 0], $list)]);
    case 'analyse':
        json_out(analysis_start());
    case 'locate':
        json_out(analysis_start('locate'));
    case 'analysis_status':
        json_out(['ok' => true] + analysis_status());
}

// ------------------------------------------------------------------ spawns (locator results)
if (str_starts_with($action, 'spawn_')) {
    require_once FT_APP . '/spawns.php';
    @set_time_limit(0);
    // the selection: picked rows, or every row of the page's filter ("All N")
    $picks = is_array($input['spawns'] ?? null) ? $input['spawns'] : [];
    if (is_array($input['filter'] ?? null)) {
        $f = $input['filter']; $where = ["state IN ('ready','manual')"]; $params = [];
        if (in_array($f['state'] ?? '', ['ready', 'manual'], true)) { $where[] = 'state = ?'; $params[] = $f['state']; }
        if (in_array($f['role'] ?? '', SPAWN_ROLES, true)) { $where[] = "JSON_SEARCH(reasons, 'one', ?) IS NOT NULL"; $params[] = $f['role']; }
        if (in_array($f['kind'] ?? '', ['npc', 'go'], true)) { $where[] = 'kind = ?'; $params[] = $f['kind']; }
        $fq = trim(mb_substr((string)($f['q'] ?? ''), 0, 60));
        if ($fq !== '') { if (ctype_digit($fq)) { $where[] = 'entry = ?'; $params[] = (int)$fq; } else { $where[] = 'name LIKE ?'; $params[] = '%' . addcslashes($fq, '%_\\') . '%'; } }
        $picks = rows('SELECT kind, entry FROM ft_spawns WHERE ' . implode(' AND ', $where) . ' LIMIT 3000', $params);
    }
    switch ($action) {
        case 'spawn_check':
            $plan = spawn_plan($picks);
            [$built, $left] = spawn_build_database($picks);
            [$consoleOk, $consoleMessage, $module] = spawn_console_status();
            $addons = spawn_client_addons();
            json_out(['ok' => true, 'entries' => count($picks), 'points' => count($plan['points']), 'manual' => count($plan['manual']), 'skipped' => $plan['skipped'],
                'database' => $built['rows'], 'database_left' => $left, 'world_running' => spawn_world_running(), 'warnings' => $plan['warnings'],
                'console' => ['ok' => $consoleOk, 'message' => $consoleMessage, 'module' => $module], 'client' => $addons]);
        case 'spawn_server':
            if (empty($input['confirm'])) json_out(['error' => t('Confirmation missing.', 'Confirmation manquante.')], 400);
            json_out(spawn_apply_server($picks));
        case 'spawn_database':
            if (empty($input['confirm'])) json_out(['error' => t('Confirmation missing.', 'Confirmation manquante.')], 400);
            json_out(spawn_apply_database($picks));
        case 'spawn_sql':
            [$built] = spawn_build_database($picks);
            json_out(['ok' => true, 'rows' => $built['rows'], 'skipped' => $built['skipped'], 'warnings' => $built['warnings'], 'count' => count($built['items']),
                'apply' => forge_script($built['plans'], 'apply'), 'rollback' => forge_script($built['plans'], 'rollback'), 'databases' => array_keys($built['plans'])]);
        case 'spawn_commands':
            json_out(['ok' => true, 'text' => spawn_gm_commands(spawn_plan($picks))]);
        case 'spawn_install':
        case 'spawn_zip':
            $files = spawn_addon_files(spawn_plan($picks));
            if ($action === 'spawn_install') {
                $target = spawn_client_addons();
                if ($target === '') json_out(['ok' => false, 'message' => t('Game client not found: set [client] path in config.ini (the folder with _classic_beta_ or Wow.exe).', 'Client du jeu introuvable : renseignez [client] path dans config.ini (le dossier contenant _classic_beta_ ou Wow.exe).')]);
                foreach ($files as $name => $content) {
                    @mkdir(dirname($target . '/' . $name), 0775, true);
                    if (file_put_contents($target . '/' . $name, $content) === false) json_out(['ok' => false, 'message' => sprintf(t('Cannot write %s.', 'Impossible d’écrire %s.'), $target . '/' . $name)]);
                }
                json_out(['ok' => true, 'message' => sprintf(t('ForeverForge installed in %s. In game: /reload, then /forge.', 'ForeverForge installé dans %s. En jeu : /reload, puis /forge.'), $target)]);
            }
            json_out(['ok' => true, 'zip' => base64_encode(spawn_zip($files)), 'name' => 'ForeverForge.zip']);
    }
}
json_out(['error' => 'unknown action'], 400);
