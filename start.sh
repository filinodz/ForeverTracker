#!/bin/sh
# Forever Tracker: starts the website with PHP's built-in server (FT_PHP, FT_PORT may be set).
cd "$(dirname "$0")" || exit 1
PHP="${FT_PHP:-php}"
PORT="${FT_PORT:-8090}"
echo "Forever Tracker: http://127.0.0.1:$PORT/"
exec "$PHP" -S "127.0.0.1:$PORT" -t public public/router.php
