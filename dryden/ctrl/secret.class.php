<?php

/**
 * Passwords the panel shows to their owners again (MySQL users, FTP accounts) are
 * stored encrypted, never as plain text: libsodium's authenticated encryption
 * (XSalsa20-Poly1305) with a key derived from this server's secret in
 * cnf/security.php, which only root and the panel can read. A copy of the database
 * on its own (a backup, a dump) holds only ciphertext.
 *
 * Stored form: "enc1:" + base64(nonce + ciphertext).
 *
 * Written by RusTus, 2026-11-06
 */
class ctrl_secret
{

    const PREFIX = 'enc1:';
    const LOG = '/var/lib/sentora/password-reveal.log';

    /**
     * Return the encryption key, or false when this server has no secret set up.
     *
     * Loads cnf/security.php when $security is not set yet; the result is cached.
     * @global array<string, mixed> $security Server secrets from cnf/security.php.
     * @return string|false The raw 32-byte key, or false.
     */
    private static function Key()
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }
        global $security;
        $file = ctrl_options::GetSystemOption('sentora_root') . 'cnf/security.php';
        if (!isset($security['server_crypto_key']) && is_file($file)) {
            require_once $file;
        }
        $secret = isset($security['server_crypto_key']) ? (string) $security['server_crypto_key'] : '';
        if (strlen($secret) < 16 || $secret === 'GENERATED_SALT_HERE' || !function_exists('sodium_crypto_secretbox')) {
            return $key = false;
        }
        // a key for this purpose only (the same secret also salts other things)
        return $key = sodium_crypto_generichash('sentora stored passwords|' . $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * Return the stored form of a password, or '' when it can not be encrypted here.
     * @param string $plain The plain-text password.
     * @return string "enc1:" + base64(nonce + ciphertext), or '' (no key, or empty password).
     */
    static function Encrypt($plain)
    {
        $key = self::Key();
        if ($key === false || (string) $plain === '') {
            return '';
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox((string) $plain, $nonce, $key));
    }

    /**
     * Return the password, or false (not stored, not encrypted here, or tampered with).
     * @param mixed $stored The stored value (a string from the database, or null).
     * @return string|false The plain-text password, or false.
     */
    static function Decrypt($stored)
    {
        $key = self::Key();
        if ($key === false || !self::IsEncrypted($stored)) {
            return false;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        return ($plain === false) ? false : $plain;
    }

    /**
     * Is $stored a value made by Encrypt() (starts with "enc1:")?
     * @param mixed $stored The stored value.
     * @return bool
     */
    static function IsEncrypted($stored)
    {
        return is_string($stored) && strncmp($stored, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /**
     * Show password may ask for the panel password first (Admin > Sentora Config,
     * "Confirm panel password to show passwords"; off by default).
     * Returns true when the password may be shown, false when it was wrong, or null
     * when it still has to be asked for.
     *
     * A wrong password is written to the reveal log.
     * @global db_driver $zdbh The panel's database connection.
     * @return bool|null
     */
    static function ConfirmGate()
    {
        if (strtolower((string) ctrl_options::GetSystemOption('show_password_confirm')) !== 'true') {
            return true;
        }
        if (!isset($_POST['inConfirmPw']) || !is_string($_POST['inConfirmPw']) || $_POST['inConfirmPw'] === '') {
            return null;
        }
        global $zdbh;
        $u = ctrl_users::GetUserDetail();
        $q = $zdbh->prepare("SELECT ac_pass_vc, ac_passsalt_vc FROM x_accounts WHERE ac_id_pk = :id AND ac_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $u['userid']));
        $row = $q->fetch();
        if (!$row) {
            return false;
        }
        // the same check the login form makes
        $crypto = new runtime_hash;
        $crypto->SetPassword($_POST['inConfirmPw']);
        $crypto->SetSalt($row['ac_passsalt_vc']);
        $ok = hash_equals((string) $row['ac_pass_vc'], (string) $crypto->CryptParts($crypto->Crypt())->Hash);
        if (!$ok) {
            self::LogReveal('', 'entered a wrong panel password for Show password');
        }
        return $ok;
    }

    /**
     * Return the small form that asks for the panel password before showing one.
     * @param string $action The URL the form posts to.
     * @param string $field Name of the hidden field (set to 1) that repeats the "show" request.
     * @param string $what What is being shown (e.g. the FTP account name), shown to the user.
     * @return string The HTML form, with the CSFR token.
     */
    static function ConfirmForm($action, $field, $what)
    {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        return '<form method="post" action="' . $h($action) . '" style="display:inline">'
            . ui_language::translate('To show the password of') . ' <strong>' . $h($what) . '</strong>, '
            . ui_language::translate('enter your panel password:') . ' '
            . '<input type="password" name="inConfirmPw" autocomplete="current-password" required autofocus> '
            . '<input type="hidden" name="' . $h($field) . '" value="1">'
            . runtime_csfr::Token()
            . ' <button class="btn btn-primary btn-sm" type="submit">' . ui_language::translate('Show password') . '</button></form>';
    }

    /**
     * Write a line in the reveal log: who looked at which password, from where.
     * @param string $what What was looked at (e.g. "FTP account bob").
     * @param string $did Optional. What the user did. Default 'viewed the password of'.
     * @return void
     */
    static function LogReveal($what, $did = 'viewed the password of')
    {
        $u = ctrl_users::GetUserDetail();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-';
        @file_put_contents(self::LOG, date('Y-m-d H:i:s') . ' ' . (isset($u['username']) ? $u['username'] : '?')
            . ' from ' . $ip . ' ' . trim($did . ' ' . $what) . "\n", FILE_APPEND | LOCK_EX);
        @chmod(self::LOG, 0640);
    }

}

?>
