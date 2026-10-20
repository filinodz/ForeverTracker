<?php
// Forever Tracker front controller: every page and API call goes through here.
require dirname(__DIR__) . '/app/bootstrap.php';
require FT_APP . '/layout.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = base_path();
if ($path === rtrim($base, '/')) $path = '';  // mounted under a folder: /tracker as well as /tracker/
elseif (strncmp($path, $base, strlen($base)) === 0) $path = substr($path, strlen($base));
$path = trim(rawurldecode($path), '/');
if ($path === 'index.php') $path = '';
$parts = $path === '' ? [] : explode('/', $path);

$byPath = [];
foreach (TYPES as $type => $info) $byPath[$info[0]] = $type;

// Setup wizard: the first run on this computer, or an administrator changing the settings.
if (($parts[0] ?? '') === 'setup' || $parts === ['api', 'setup']) {
    if (!is_file(config_path())) define('FT_SETUP', true);
    require FT_APP . '/setup.php';
    setup_route($parts);
    exit;
}

// Private trackers only show the login page to visitors.
$open = in_array($parts[0] ?? '', ['login', 'logout', 'api'], true);
if (!config('site', 'public', 1) && !is_admin() && !$open) redirect(url('login', ['next' => $_SERVER['REQUEST_URI'] ?? '']));

$page = static function (string $file, array $vars = []) {
    extract($vars);
    require FT_APP . '/pages/' . $file . '.php';
    exit;
};

switch (true) {
    case $parts === []:
        $page('home');
    case $parts === ['changes']:
        $page('changes');
    case $parts === ['todo']:
        $page('todo');
    case $parts === ['search']:
        $page('search');
    case $parts === ['login']:
        $page('login');
    case $parts === ['logout']:
        if (csrf_ok($_GET['t'] ?? null)) unset($_SESSION['ft_admin']);
        redirect(url());
    case count($parts) === 1 && isset($byPath[$parts[0]]):
        $page('catalog', ['type' => $byPath[$parts[0]]]);
    case count($parts) === 2 && isset(TYPES[$parts[0]]) && ctype_digit($parts[1]):
        $page('entry', ['type' => $parts[0], 'id' => (int)$parts[1]]);
    case ($parts[0] ?? '') === 'admin':
        require_admin();
        $section = $parts[1] ?? 'forge';
        if (in_array($section, ['forge', 'notes', 'history', 'spawns', 'imports'], true) && count($parts) <= 2) $page('admin/' . $section);
        break;
    case ($parts[0] ?? '') === 'api' && count($parts) === 4 && $parts[1] === 'tooltip':
        require FT_APP . '/api/tooltip.php';
        exit;
    case $parts === ['api', 'search']:
        require FT_APP . '/api/search.php';
        exit;
    case $parts === ['api', 'forge']:
        require FT_APP . '/api/forge.php';
        exit;
    case $parts === ['api', 'imports']:
        require FT_APP . '/api/imports.php';
        exit;
    case $parts === ['notes']:
        redirect(url('admin/notes', $_GET));
    // Old CMS-style links: /entry?type=item&id=1
    case $parts === ['entry'] && isset(TYPES[$_GET['type'] ?? '']):
        redirect(entry_url($_GET['type'], (int)($_GET['id'] ?? 0)));
}
http_response_code(404);
$page('404');
