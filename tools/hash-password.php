<?php
// Prints a password hash for the [admins] section of config.ini:
//   php tools/hash-password.php "my password"
if (PHP_SAPI !== 'cli') exit;
$password = $argv[1] ?? '';
if ($password === '') {
    fwrite(STDERR, "Usage: php tools/hash-password.php \"password\"\n");
    exit(1);
}
echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
