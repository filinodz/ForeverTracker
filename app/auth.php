<?php
// Administrator login: game accounts (Battle.net e-mail + password, checked the way the login server does)
// with a GM level, or local administrators declared in config.ini ([admins] name = "password hash").

/** Battle.net account SRP6 (auth.battlenet_accounts salt/verifier), versions 1 and 2. */
final class FtBnetSrp6
{
    private const N1 = '86A7F6DEEB306CE519770FE37D556F29944132554DED0BD68205E27F3231FEF5A10108238A3150C59CAF7B0B6478691C13A6ACF5E1B5ADAFD4A943D4A21A142B800E8A55F8BFBAC700EB77A7235EE5A609E350EA9FC19F10D921C2FA832E4461B7125D38D254A0BE873DFC27858ACB3F8B9F258461E4373BC3A6C2A9634324AB';
    private const N2 = 'AC6BDB41324A9A9BF166DE5E1389582FAF72B6651987EE07FC3192943DB56050A37329CBB4A099ED8193E0757767A13DD52312AB4B03310DCD7F48A9DA04FD50E8083969EDB767B0CF6095179A163AB3661A05FBD5FAAAE82918A9962F0B93B855F97993EC975EEAA80D740ADBF4FF747359D041D5C33EA71D281E446B14773BCA97B43A23FB801676BD207A436C6481F1D2B9078717461A5B9D32E688F87748544523B524B0D57D5EA77A2775D2ECFA032CFBDBF52FB3786160279004E57AE6AF874E7303CE53299CCC041C7BC308D82A5698F3A8D0C38271AE35F8E9DBFBB694B5C803D89F7AE435DE236D525F54759B65E372FCD68EF20FA7111F9E4AFF73';

    public static function verifier(string $email, string $password, string $salt, int $version): string
    {
        $name = strtoupper(hash('sha256', strtoupper(trim($email))));
        if ($version === 1) {
            $password = strtoupper(substr($password, 0, 16));
            $x = gmp_import(hash('sha256', $salt . hash('sha256', $name . ':' . $password, true), true), 1, GMP_LSW_FIRST);
            $n = gmp_init(self::N1, 16);
            $length = 128;
        } else {
            $bytes = hash_pbkdf2('sha512', $name . ':' . $password, $salt, 15000, 64, true);
            $x = gmp_import($bytes, 1, GMP_MSW_FIRST);
            if (ord($bytes[0]) & 0x80) $x = gmp_sub($x, gmp_pow(2, 512));  // the server reads the bytes as a signed number
            $n = gmp_init(self::N2, 16);
            $x = gmp_mod($x, gmp_sub($n, 1));
            $length = 256;
        }
        return str_pad(gmp_export(gmp_powm(gmp_init(2), $x, $n), 1, GMP_LSW_FIRST), $length, "\0", STR_PAD_RIGHT);
    }
}

/** Returns ['name' => …, 'via' => …] or null, with $error set. */
function admin_login(string $login, string $password, ?string &$error = null): ?array
{
    $login = trim($login);
    $admins = config_all()['admins'] ?? [];
    if (isset($admins[$login]) && password_verify($password, (string)$admins[$login])) {
        return ['name' => $login, 'via' => 'local'];
    }
    if (!str_contains($login, '@')) {
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');  // same cost as a real check
        $error = t('Unknown administrator or wrong password.', 'Administrateur inconnu ou mot de passe incorrect.');
        return null;
    }
    if (!function_exists('gmp_init')) {
        $error = t('Game account login needs the PHP gmp extension.', 'La connexion par compte de jeu nécessite l’extension PHP gmp.');
        return null;
    }
    $bnet = row('SELECT id, email, srp_version, salt, verifier, locked FROM battlenet_accounts WHERE email = ? LIMIT 1', [strtoupper($login)], 'auth');
    $computed = FtBnetSrp6::verifier($login, $password, $bnet['salt'] ?? str_repeat("\0", 32), (int)($bnet['srp_version'] ?? 2));
    if (!$bnet || !hash_equals(rtrim((string)$bnet['verifier'], "\0"), rtrim($computed, "\0"))) {
        $error = t('Wrong e-mail or password.', 'E-mail ou mot de passe incorrect.');
        return null;
    }
    $level = (int)value('SELECT MAX(aa.SecurityLevel) FROM account a JOIN account_access aa ON aa.AccountID = a.id WHERE a.battlenet_account = ?', [(int)$bnet['id']], 'auth');
    if ($level < (int)config('admin', 'gm_level', 3)) {
        $error = t('This account is not a game master of the required level.', 'Ce compte n’est pas maître du jeu au niveau requis.');
        return null;
    }
    return ['name' => strtolower($login), 'via' => 'gm', 'level' => $level];
}
