<?php
// Router for PHP's built-in server:  php -S 127.0.0.1:8090 -t public public/router.php
// Existing files under public/ are served as they are; everything else goes to index.php.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/' && strpos($path, '..') === false && is_file(__DIR__ . $path) && basename($path) !== 'router.php') {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
