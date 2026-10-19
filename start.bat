@echo off
rem Forever Tracker: starts the website with PHP's built-in server.
rem   FT_PHP       php.exe to use (default: php from PATH)
rem   FT_PHP_ARGS  extra arguments, e.g. -c C:\path\php.ini
rem   FT_PORT      port (default 8090)
setlocal
cd /d "%~dp0"
if "%FT_PHP%"=="" set "FT_PHP=php"
if "%FT_PORT%"=="" set "FT_PORT=8090"
echo Forever Tracker: http://127.0.0.1:%FT_PORT%/
"%FT_PHP%" %FT_PHP_ARGS% -S 127.0.0.1:%FT_PORT% -t public public\router.php
