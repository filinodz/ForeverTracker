<?php
// Forever Tracker: configuration, database, language, session and shared helpers.
declare(strict_types=1);

const FT_VERSION = '1.2.2';
define('FT_ROOT', dirname(__DIR__));
define('FT_APP', __DIR__);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors', '0');

// ------------------------------------------------------------------ configuration
function config_all(): array
{
    static $config = null;
    if ($config === null) {
        $path = config_path();
        if (!is_file($path)) {
            if (defined('FT_SETUP')) return $config = [];  // the setup wizard runs before there is a configuration
            if (PHP_SAPI !== 'cli' && setup_allowed_here()) { header('Location: ' . base_path() . 'setup', true, 302); exit; }
            fail_page('Configuration missing', 'Open /setup from this computer, or copy config.sample.ini to config.ini and fill in your database settings.');
        }
        $config = parse_ini_file($path, true, INI_SCANNER_TYPED) ?: [];
    }
    return $config;
}
function config(string $section, string $key, $default = null) { return config_all()[$section][$key] ?? $default; }
function config_path(): string { return getenv('FOREVER_TRACKER_CONFIG') ?: FT_ROOT . '/config.ini'; }
/** The setup wizard answers requests from this computer only (it can read and write the server's settings). */
function setup_allowed_here(): bool
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($ip, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true) && empty($_SERVER['HTTP_X_FORWARDED_FOR']);
}

function fail_page(string $title, string $message, int $code = 500): void
{
    http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title><body style="font:16px system-ui;background:#09090f;color:#e9e7f2;display:grid;place-items:center;min-height:100vh;margin:0">'
        . '<div style="max-width:520px;padding:32px;border:1px solid #2a2838;border-radius:16px;background:#12121b"><h1 style="margin-top:0;font-size:22px">' . htmlspecialchars($title) . '</h1><p style="color:#a7a4b8">' . htmlspecialchars($message) . '</p></div>';
    exit;
}

// ------------------------------------------------------------------ session and language
session_name('ft_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();

function lang(): string
{
    static $lang = null;
    if ($lang !== null) return $lang;
    $wanted = $_GET['lang'] ?? $_COOKIE['ft_lang'] ?? null;
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'fr'], true)) {
        setcookie('ft_lang', $_GET['lang'], ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
    }
    if (!in_array($wanted, ['en', 'fr'], true)) {
        $wanted = (string)config('site', 'default_language', 'en');
        if (!in_array($wanted, ['en', 'fr'], true)) $wanted = 'en';
    }
    return $lang = $wanted;
}
function fr(): bool { return lang() === 'fr'; }
/** English and French text: the visitor's language wins. */
function t(string $en, string $fr): string { return fr() ? $fr : $en; }
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
/** A {"en": …, "fr": …} value from the analysis (or a plain string) in the visitor's language. */
function tr($value): string
{
    if (is_array($value)) return (string)($value[lang()] ?? $value['fr'] ?? $value['en'] ?? '');
    return (string)$value;
}
function number($value, int $decimals = 0): string { return number_format((float)$value, $decimals, fr() ? ',' : '.', fr() ? ' ' : ','); }

// ------------------------------------------------------------------ URLs
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/') . '/';  // dirname('/index.php') is '\' on Windows
    }
    return $base;
}
function url(string $path = '', array $query = []): string
{
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null && $v !== false);
    return base_path() . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
}
function asset(string $path): string
{
    $file = FT_ROOT . '/public/assets/' . $path;
    return base_path() . 'assets/' . $path . '?v=' . (is_file($file) ? filemtime($file) : FT_VERSION);
}
function entry_url(string $type, int $id): string { return url($type . '/' . $id); }
function redirect(string $to): void { header('Location: ' . $to, true, 303); exit; }

// ------------------------------------------------------------------ database
function db(string $name = 'tracker'): mysqli
{
    static $links = [];
    if (!isset($links[$name])) {
        $database = (string)config('database', $name, $name);
        try {
            $link = new mysqli((string)config('database', 'host', '127.0.0.1'), (string)config('database', 'user', ''), (string)config('database', 'password', ''),
                $database, (int)config('database', 'port', 3306));
        } catch (mysqli_sql_exception $e) {
            error_log('Forever Tracker: ' . $e->getMessage());
            fail_page('Database unavailable', 'The tracker cannot reach its database. Check the [database] section of config.ini.');
        }
        $link->set_charset('utf8mb4');
        $links[$name] = $link;
    }
    return $links[$name];
}
/** Prepared statement on a database; returns every row. */
function rows(string $sql, array $params = [], string $database = 'tracker'): array
{
    $statement = db($database)->prepare($sql);
    if ($params) {
        $types = '';
        foreach ($params as $param) $types .= is_int($param) ? 'i' : (is_float($param) ? 'd' : 's');
        $statement->bind_param($types, ...$params);
    }
    $statement->execute();
    $result = $statement->get_result();
    $out = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $statement->close();
    return $out;
}
function row(string $sql, array $params = [], string $database = 'tracker'): ?array { return rows($sql, $params, $database)[0] ?? null; }
function value(string $sql, array $params = [], string $database = 'tracker')
{
    $first = row($sql, $params, $database);
    return $first ? reset($first) : null;
}
function table_exists(string $table, string $database = 'tracker'): bool
{
    static $cache = [];
    $key = $database . '.' . $table;
    if (!isset($cache[$key])) {
        $cache[$key] = (bool)value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table], $database);
    }
    return $cache[$key];
}

function meta(): array
{
    static $meta = null;
    if ($meta !== null) return $meta;
    $meta = [];
    if (!table_exists('ft_meta')) return $meta;
    foreach (rows('SELECT k, v FROM ft_meta') as $r) $meta[$r['k']] = json_decode($r['v'], true);
    return $meta;
}
function ready(): bool { return meta() !== [] && table_exists('ft_entities'); }
function builds(): array
{
    static $builds = null;
    return $builds ??= ready() ? rows('SELECT build, version, released, note, source, is_server, counts FROM ft_builds ORDER BY build DESC') : [];
}

// ------------------------------------------------------------------ resource types
/** type => [path, icon, English, French, English description, French description, Wowhead kind] */
const TYPES = [
    'dungeon' => ['dungeons', 'fa-dungeon', 'Dungeons & raids', 'Donjons & raids', 'Instances, battlegrounds and arenas of the official client.', 'Instances, champs de bataille et arènes du client officiel.', null],
    'boss' => ['bosses', 'fa-skull', 'Bosses', 'Boss', 'Every boss encounter of every instance.', 'Chaque rencontre de boss de chaque instance.', null],
    'zone' => ['zones', 'fa-map', 'Zones', 'Zones', 'Open-world zones: creatures, quests, graveyards.', 'Zones du monde : PNJ, quêtes, cimetières.', 'zone'],
    'quest' => ['quests', 'fa-scroll', 'Quests', 'Quêtes', 'Official quests and whether they can be done.', 'Les quêtes officielles et leur faisabilité.', 'quest'],
    'item' => ['items', 'fa-gem', 'Items', 'Objets', 'Every item and how players obtain it.', 'Chaque objet et sa source d’obtention.', 'item'],
    'spell' => ['spells', 'fa-wand-sparkles', 'Spells', 'Sorts', 'Class, racial, profession and mount spells.', 'Sorts de classe, raciaux, de métier et de monture.', 'spell'],
    'talent' => ['talents', 'fa-sitemap', 'Talents', 'Talents', 'Talent trees and their ranks.', 'Arbres de talents et leurs rangs.', 'spell'],
    'recipe' => ['recipes', 'fa-hammer', 'Recipes', 'Recettes', 'Profession recipes and how they are learned.', 'Recettes de métier et leur apprentissage.', 'spell'],
    'mount' => ['mounts', 'fa-horse', 'Mounts', 'Montures', 'The mount collection.', 'La collection de montures.', 'spell'],
    'pet' => ['pets', 'fa-paw', 'Pets', 'Mascottes', 'Companion pets.', 'Mascottes de compagnie.', 'spell'],
    'toy' => ['toys', 'fa-puzzle-piece', 'Toys', 'Jouets', 'The toy box.', 'Le coffre à jouets.', 'item'],
    'achievement' => ['achievements', 'fa-trophy', 'Achievements', 'Hauts faits', 'Achievements and their rewards.', 'Hauts faits et leurs récompenses.', 'achievement'],
    'taxi' => ['flights', 'fa-feather', 'Flight points', 'Points de vol', 'Flight paths and their flight masters.', 'Trajets aériens et maîtres de vol.', null],
];
const PRIORITY = ['dungeon', 'boss', 'zone', 'quest', 'taxi', 'talent', 'spell', 'recipe', 'mount', 'pet', 'toy', 'achievement', 'item'];
function type_label(string $type): string { return isset(TYPES[$type]) ? t(TYPES[$type][2], TYPES[$type][3]) : $type; }
function type_desc(string $type): string { return isset(TYPES[$type]) ? t(TYPES[$type][4], TYPES[$type][5]) : ''; }
function type_url(string $type, array $query = []): string { return url(TYPES[$type][0] ?? '', $query); }
/** Category written by the analysis (class, profession, Donjon, Raid…) in the visitor's language. */
function sub_label(string $sub): string
{
    $labels = ['class' => t('Class', 'Classe'), 'profession' => t('Profession', 'Métier'), 'racial' => t('Racial', 'Racial'), 'talent' => 'Talent',
        'mount' => t('Mount', 'Monture'), 'pet' => t('Pet', 'Mascotte'), 'rune' => t('Rune engraving', 'Gravure de rune'), 'petability' => t('Pet ability', 'Technique de familier'),
        'item' => t('Item use', 'Utilisation d’objet'), 'other' => t('Other', 'Autre'), 'Monture' => t('Mount', 'Monture'), 'Mascotte' => t('Pet', 'Mascotte'), 'Jouet' => t('Toy', 'Jouet'),
        'Donjon' => t('Dungeon', 'Donjon'), 'Raid' => 'Raid', 'Champ de bataille' => t('Battleground', 'Champ de bataille'), 'Arène' => t('Arena', 'Arène')];
    return $labels[$sub] ?? $sub;
}
function status_label(string $status): string
{
    return ['ok' => t('Available', 'Disponible'), 'partial' => t('Incomplete', 'Incomplet'), 'missing' => t('Missing', 'Manquant')][$status] ?? $status;
}
function status_badge(string $status, ?int $score = null): string
{
    return '<span class="badge is-' . e($status) . '"><i></i>' . e(status_label($status)) . ($score !== null && $status === 'partial' ? ' <b>' . $score . '%</b>' : '') . '</span>';
}
function kind_label(string $kind): string
{
    return ['added' => t('Added', 'Ajouté'), 'modified' => t('Changed', 'Modifié'), 'removed' => t('Removed', 'Supprimé')][$kind] ?? $kind;
}

/** Check codes of the analysis: label and how to fix a failure. */
function check_info(string $code): array
{
    $info = [
        'core' => [t('Known to the core', 'Connu du core'), t('The server data lacks it: copy the official record with the Forge (hotfixes), or re-extract the current client DB2 into the server data.', 'Les données du serveur ne l’ont pas : copiez l’enregistrement officiel avec la Forge (hotfixes), ou ré-extrayez les DB2 du client actuel dans les données du serveur.')],
        'source' => [t('Obtainable in game', 'Obtenable en jeu'), t('Give it a source: creature or object loot, npc_vendor (the Forge can add it to a vendor), a quest reward or a learnable craft.', 'Donnez-lui une source : butin de PNJ ou d’objet, npc_vendor (la Forge peut l’ajouter à un marchand), une récompense de quête ou un artisanat apprenable.')],
        'learn' => [t('Learnable', 'Apprenable'), t('Teach it at a spawned trainer (the Forge does it), reward it from a quest (RewardSpell) or make its recipe item obtainable.', 'Enseignez-le chez un entraîneur placé (la Forge le fait), en récompense de quête (RewardSpell) ou rendez son objet-recette obtenable.')],
        'script' => [t('Spell script', 'Script du sort'), t('Test the spell in game; if its effect does nothing, write a SpellScript and register it in spell_script_names.', 'Testez le sort en jeu ; si son effet ne fait rien, écrivez un SpellScript et enregistrez-le dans spell_script_names.')],
        'ranks' => [t('Rank spells known', 'Rangs connus'), t('The server lacks some rank spells: copy them with the Forge or update the server DB2.', 'Le serveur n’a pas certains sorts de rang : copiez-les avec la Forge ou mettez à jour les DB2 du serveur.')],
        'talent_script' => [t('Script', 'Script'), t('Test the talent in game; dummy effects usually need a SpellScript.', 'Testez le talent en jeu ; les effets « dummy » nécessitent souvent un SpellScript.')],
        'quest_row' => [t('Quest data', 'Données de quête'), t('The core has no quest_template row: create the quest (title, objectives, rewards), quest_objectives and its giver and ender.', 'Le core n’a pas de ligne quest_template : créez la quête (titre, objectifs, récompenses), quest_objectives et ses donneur et valideur.')],
        'starter' => [t('Quest giver', 'Donneur de quête'), t('Link a spawned creature or object in creature_queststarter / gameobject_queststarter.', 'Liez un PNJ ou objet placé dans creature_queststarter / gameobject_queststarter.')],
        'ender' => [t('Quest ender', 'Valideur de quête'), t('Link a spawned creature or object in creature_questender / gameobject_questender.', 'Liez un PNJ ou objet placé dans creature_questender / gameobject_questender.')],
        'objectives' => [t('Objectives achievable', 'Objectifs réalisables'), t('Spawn the objective targets, or give the required items a source (creature_questitem, loot).', 'Placez les cibles des objectifs, ou donnez une source aux objets requis (creature_questitem, butin).')],
        'enabled' => [t('Enabled', 'Activé'), t('Remove the entry from the disables table once it is ready.', 'Retirez l’entrée de la table disables quand le contenu est prêt.')],
        'terrain' => [t('Terrain extracted', 'Terrain extrait'), t('Run mapextractor and the vmap tools on the current client, then copy maps and vmaps to the server data.', 'Lancez mapextractor et les outils vmap sur le client actuel, puis copiez maps et vmaps dans les données du serveur.')],
        'mmaps' => [t('Navigation (mmaps)', 'Navigation (mmaps)'), t('Generate the navigation mesh with mmaps_generator for this map.', 'Générez le maillage de navigation avec mmaps_generator pour cette carte.')],
        'instance_template' => ['instance_template', t('Add the instance_template row so the instance can be created (the Forge does it).', 'Ajoutez la ligne instance_template pour que l’instance puisse être créée (la Forge le fait).')],
        'entrance' => [t('Entrance', 'Entrée'), t('Add an areatrigger_teleport pointing to a world_safe_locs entry inside this map.', 'Ajoutez un areatrigger_teleport vers une entrée world_safe_locs dans cette carte.')],
        'battleground' => [t('Battleground declared', 'Champ de bataille déclaré'), t('Declare it in battleground_template.', 'Déclarez-le dans battleground_template.')],
        'spawns' => [t('Creatures spawned', 'PNJ placés'), t('Spawn the creatures (creature table): The Forge › Spawns finds where they stand.', 'Placez les créatures (table creature) : La Forge › Placements trouve où elles se trouvent.')],
        'bosses' => [t('Bosses present', 'Boss présents'), t('Spawn each boss of the instance; the Bosses page lists the missing ones.', 'Placez chaque boss de l’instance ; la page Boss liste ceux qui manquent.')],
        'boss_spawn' => [t('Boss spawned', 'Boss placé'), t('Create or reuse the boss creature_template and spawn it in the instance.', 'Créez ou réutilisez le creature_template du boss et placez-le dans l’instance.')],
        'boss_script' => [t('Scripted fight', 'Combat scripté'), t('Give the boss its mechanics: a C++ ScriptName or SmartAI.', 'Donnez ses mécaniques au boss : un ScriptName C++ ou SmartAI.')],
        'zone_quests' => [t('Zone quests', 'Quêtes de la zone'), t('Open the quests of this zone filtered “To do” to see what blocks them.', 'Ouvrez les quêtes de la zone filtrées « À faire » pour voir ce qui les bloque.')],
        'graveyard' => [t('Graveyard', 'Cimetière'), t('Add a graveyard_zone row linking a graveyard (world_safe_locs) to this zone.', 'Ajoutez une ligne graveyard_zone reliant un cimetière (world_safe_locs) à cette zone.')],
        'reward' => [t('Reward', 'Récompense'), t('Add the title or item in achievement_reward.', 'Ajoutez le titre ou l’objet dans achievement_reward.')],
        'flightmaster' => [t('Flight master spawned', 'Maître de vol placé'), t('Spawn a flight master (npcflag 8192) next to the node.', 'Placez un maître de vol (npcflag 8192) près du point.')],
        'obtainable' => [t('Obtainable', 'Obtenable'), t('Sell or drop the item that teaches it (the Forge can add it to a vendor), or teach it at a trainer.', 'Vendez ou faites tomber l’objet qui l’apprend (la Forge peut l’ajouter à un marchand), ou enseignez-le chez un entraîneur.')],
    ];
    return $info[$code] ?? [$code, ''];
}
function check_label(array $check): string { return isset($check['code']) ? check_info($check['code'])[0] : (string)($check['label'] ?? ''); }
function failing_checks(array $checks): array { return array_values(array_filter($checks, fn($c) => !$c['ok'] && (float)$c['w'] > 0)); }

/** Resources whose French or English name contains $q (or with that ID), best matches first. */
function search_entities(string $q, int $limit = 40): array
{
    if ($q === '' || !ready()) return [];
    if (ctype_digit($q)) {
        return rows("SELECT type, id, name, name_en, icon, quality, status, score, internal, is_new FROM ft_entities WHERE id = ? ORDER BY internal, FIELD(type,'dungeon','zone','quest','item','spell') LIMIT ?", [(int)$q, $limit]);
    }
    $like = '%' . addcslashes($q, '%_\\') . '%';
    return rows("SELECT type, id, name, name_en, icon, quality, status, score, internal, is_new, (name = ? OR name_en = ?) exact, (name LIKE ? OR name_en LIKE ?) prefix
        FROM ft_entities WHERE (name LIKE ? OR name_en LIKE ?) ORDER BY internal, exact DESC, prefix DESC,
        FIELD(type,'dungeon','boss','zone','quest','mount','pet','toy','talent','recipe','spell','item','achievement','taxi'), CHAR_LENGTH(name) LIMIT ?",
        [$q, $q, addcslashes($q, '%_\\') . '%', addcslashes($q, '%_\\') . '%', $like, $like, $limit]);
}

// ------------------------------------------------------------------ icons
function icon_url(string $icon, string $size = 'medium'): string
{
    return 'https://wow.zamimg.com/images/wow/icons/' . $size . '/' . rawurlencode(strtolower($icon)) . '.jpg';
}
/** The icon of a resource; hovering it shows the in-game tooltip. */
function icon_html(array $row, string $type, string $size = 'medium', bool $tooltip = true): string
{
    $quality = (int)($row['quality'] ?? -1);
    $class = 'ic' . ($size === 'large' ? ' is-large' : '') . ($quality >= 0 ? ' q' . $quality : '');
    $attr = $tooltip && isset($row['id']) ? ' data-tt="' . e($type . ':' . (int)$row['id']) . '"' : '';
    $glyph = '<i class="fas ' . e(TYPES[$type][1] ?? 'fa-circle') . '" aria-hidden="true"></i>';
    if (!empty($row['icon']) && !str_starts_with((string)$row['icon'], 'unknown_')) {
        return '<span class="' . $class . '"' . $attr . '><img src="' . e(icon_url((string)$row['icon'], $size)) . '" alt="" loading="lazy" onerror="this.remove()">' . $glyph . '</span>';
    }
    return '<span class="' . $class . ' is-glyph"' . $attr . '>' . $glyph . '</span>';
}
function wowhead_url(string $type, array $row): ?string
{
    $kind = TYPES[$type][6] ?? null;
    $data = is_array($row['data'] ?? null) ? $row['data'] : (json_decode((string)($row['data'] ?? '{}'), true) ?: []);
    $id = (int)$row['id'];
    if ($type === 'talent') $id = (int)($data['ranks'][0] ?? 0);
    if (in_array($type, ['mount', 'pet'], true)) $id = (int)($data['spell'] ?? 0);
    if ($type === 'toy') $id = (int)($data['item'] ?? 0);
    if (!$kind || !$id) return null;
    return 'https://www.wowhead.com/forever/' . (fr() ? 'fr/' : '') . $kind . '=' . $id;
}
/** A resource's name in the visitor's language (the other language when it has no translation). */
function display_name(array $row): string
{
    $fr = (string)($row['name'] ?? ''); $en = (string)($row['name_en'] ?? '');
    $name = fr() ? ($fr ?: $en) : ($en ?: $fr);
    return $name !== '' ? $name : '#' . (int)($row['id'] ?? 0);
}
/** The name in the other language, or '' when it is the same. */
function other_name(array $row): string
{
    $other = fr() ? (string)($row['name_en'] ?? '') : (string)($row['name'] ?? '');
    return $other !== '' && $other !== display_name($row) ? $other : '';
}
/** Name link with the resource's tooltip. */
function name_link(array $row, string $type): string
{
    $quality = $type === 'item' || $type === 'toy' ? ' q' . (int)($row['quality'] ?? 1) : '';
    return '<a class="name' . $quality . '" href="' . e(entry_url($type, (int)$row['id'])) . '" data-tt="' . e($type . ':' . (int)$row['id']) . '">' . e(display_name($row)) . '</a>';
}
function ring(int $percent, int $size = 120, string $class = ''): string
{
    $radius = 52; $circumference = 2 * M_PI * $radius;
    return '<svg class="ring ' . e($class) . '" viewBox="0 0 120 120" width="' . $size . '" height="' . $size . '" role="img" aria-label="' . $percent . '%">'
        . '<circle cx="60" cy="60" r="' . $radius . '" class="ring-track"/>'
        . '<circle cx="60" cy="60" r="' . $radius . '" class="ring-value" stroke-dasharray="' . round($circumference * $percent / 100, 1) . ' ' . round($circumference, 1) . '"/></svg>';
}

// ------------------------------------------------------------------ administrators
function admin(): ?array
{
    static $checked = false;
    if (($_SESSION['ft_admin']['via'] ?? '') === 'website' && !defined('FT_HOST_IDENTITY')) {
        unset($_SESSION['ft_admin']);  // logged out of the website
    }
    if (empty($_SESSION['ft_admin']) && !$checked && defined('FT_HOST_IDENTITY')) {
        // Mounted in a website (e.g. Forever CMS) whose logged-in staff are administrators here too.
        $checked = true;
        $host = FT_HOST_IDENTITY;
        $account = row('SELECT id FROM account WHERE id = ? AND username = ?', [(int)$host['account'], (string)$host['username']], 'auth');
        if ($account) {
            $level = (int)value('SELECT MAX(SecurityLevel) FROM account_access WHERE AccountID = ?', [(int)$account['id']], 'auth');
            if (!empty($host['site_admin']) || $level >= (int)config('admin', 'gm_level', 3)) {
                $_SESSION['ft_admin'] = ['name' => (string)$host['name'], 'via' => 'website', 'level' => $level];
            }
        }
    }
    return $_SESSION['ft_admin'] ?? null;
}
function is_admin(): bool { return admin() !== null; }
function require_admin(): void
{
    if (!is_admin()) redirect(url('login', ['next' => $_SERVER['REQUEST_URI'] ?? '']));
}
function csrf_token(): string
{
    if (empty($_SESSION['ft_csrf'])) $_SESSION['ft_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['ft_csrf'];
}
function csrf_ok(?string $token): bool { return is_string($token) && hash_equals(csrf_token(), $token); }
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    // logs of tools may hold bytes of the console code page: substitute them instead of failing silently
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
