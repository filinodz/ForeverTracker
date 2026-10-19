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
        json_out(['ok' => true, 'rows' => $built['rows'], 'warnings' => $built['warnings'], 'count' => count($built['items']),
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
    case 'analysis_status':
        json_out(['ok' => true] + analysis_status());
}
json_out(['error' => 'unknown action'], 400);
