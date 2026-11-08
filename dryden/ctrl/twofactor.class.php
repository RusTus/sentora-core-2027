<?php

/**
 * Optional two-factor login (TOTP, RFC 6238: 6-digit codes from any
 * authenticator app). Switched on for the whole panel with the Sentora Config
 * setting "twofactor_enabled" (disabled, enabled: each account turns it on in My Account,
 * or enabled-forced: every account must use it).
 *
 * To switch it off again if an administrator loses their phone, run on the
 * server:  setso --set twofactor_enabled disabled
 *
 * Written by RusTus, 2026-11-07
 */
class ctrl_twofactor
{

    const DIGITS = 6;
    const PERIOD = 30;
    const PENDING_SECONDS = 300;

    /**
     * Return the two-factor mode set in Sentora Config.
     *
     * Sentora Config > Two-factor login: disabled, enabled (each account decides in My
     * Account) or enabled-forced (every account must use it: one that has not set it up
     * does so right after its password, before it can use the panel). Older values:
     * true = enabled, false = disabled.
     * @return string 'disabled', 'enabled' or 'enabled-forced'.
     */
    static function Mode()
    {
        $v = strtolower(trim((string) ctrl_options::GetSystemOption('twofactor_enabled')));
        if ($v === 'true' || $v === 'enabled') {
            return 'enabled';
        }
        return ($v === 'enabled-forced') ? 'enabled-forced' : 'disabled';
    }

    /**
     * Is two-factor login switched on for the panel (enabled or enabled-forced)?
     * @return bool
     */
    static function Enabled()
    {
        return self::Mode() !== 'disabled';
    }

    /**
     * Must every account use two-factor login (enabled-forced)?
     * @return bool
     */
    static function Forced()
    {
        return self::Mode() === 'enabled-forced';
    }

    /**
     * Give the setting its three choices (servers that still have true|false get them here).
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function EnsureSetting()
    {
        global $zdbh;
        $zdbh->exec("UPDATE x_settings SET so_defvalues_tx = 'disabled|enabled|enabled-forced',
            so_value_tx = CASE LOWER(so_value_tx) WHEN 'true' THEN 'enabled' WHEN 'false' THEN 'disabled' ELSE so_value_tx END,
            so_desc_tx = 'disabled: off. enabled: each account can turn on a 6-digit code from an authenticator app in My Account. enabled-forced: every account must use it (one that has not set it up does so at its next login).'
            WHERE so_name_vc = 'twofactor_enabled' AND so_defvalues_tx <> 'disabled|enabled|enabled-forced'");
    }

    /**
     * Create the x_twofactor and x_twofactor_recovery tables if missing (once per request).
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if (!$done) {
            self::EnsureSetting();
            $zdbh->exec("CREATE TABLE IF NOT EXISTS x_twofactor (
                tf_acc_fk INT NOT NULL PRIMARY KEY, tf_secret_vc VARCHAR(255) NOT NULL,
                tf_enabled_in TINYINT NOT NULL DEFAULT 0, tf_lastcounter_in BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
            // one-time recovery codes (stored hashed, like passwords)
            $zdbh->exec("CREATE TABLE IF NOT EXISTS x_twofactor_recovery (
                rc_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, rc_acc_fk INT NOT NULL, rc_hash_vc VARCHAR(255) NOT NULL,
                rc_used_ts INT NULL, rc_created_ts INT NOT NULL, KEY rc_acc (rc_acc_fk)) ENGINE=InnoDB");
            $done = true;
        }
    }

    /**
     * Return the account's x_twofactor row.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return array<int|string, mixed>|false The row (PDO default fetch: by name and by number), or false when there is none.
     */
    static function Row($uid)
    {
        global $zdbh;
        self::Ensure();
        $sql = $zdbh->prepare("SELECT * FROM x_twofactor WHERE tf_acc_fk = :a");
        $sql->execute(array(':a' => (int) $uid));
        $row = $sql->fetch();
        if ($row) {
            // the secret is stored encrypted (ctrl_secret); one from before that is used as it is
            // until the daemon encrypts it (EncryptStored)
            $plain = self::SecretFrom((string) $row['tf_secret_vc']);
            $row['tf_secret_vc'] = $plain;
            $row[1] = $plain;
        }
        return $row;
    }

    /**
     * The secret as stored: encrypted (ctrl_secret, "enc1:...") or, from before that, plain base32.
     *
     * @param string $stored The tf_secret_vc value.
     * @return string The base32 secret ('' when it can not be decrypted).
     */
    static function SecretFrom($stored)
    {
        if (ctrl_secret::IsEncrypted($stored)) {
            $plain = ctrl_secret::Decrypt($stored);
            return $plain === false ? '' : (string) $plain;
        }
        return $stored;
    }

    /**
     * The secret as it is stored: encrypted when the column has room (servers updated before the
     * daemon widened it keep the plain secret until EncryptStored runs).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $secret The base32 secret.
     * @return string
     */
    static function SecretTo($secret)
    {
        global $zdbh;
        static $wide = null;
        if ($wide === null) {
            $len = $zdbh->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_twofactor' AND COLUMN_NAME = 'tf_secret_vc'")->fetchColumn();
            $wide = (int) $len >= 255;
        }
        $enc = $wide ? ctrl_secret::Encrypt($secret) : '';
        return $enc !== '' ? $enc : $secret;
    }

    /**
     * Daemon: make room for encrypted secrets and encrypt the ones still stored plain (they were
     * readable to anyone with a copy of the panel's database).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return int How many were encrypted.
     */
    static function EncryptStored()
    {
        global $zdbh;
        self::Ensure();
        $len = (int) $zdbh->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_twofactor' AND COLUMN_NAME = 'tf_secret_vc'")->fetchColumn();
        if ($len > 0 && $len < 255) {
            $zdbh->exec("ALTER TABLE x_twofactor MODIFY tf_secret_vc VARCHAR(255) NOT NULL");
        }
        $n = 0;
        $set = $zdbh->prepare("UPDATE x_twofactor SET tf_secret_vc = :s WHERE tf_acc_fk = :a AND tf_secret_vc = :old");
        foreach ($zdbh->query("SELECT tf_acc_fk, tf_secret_vc FROM x_twofactor WHERE tf_secret_vc NOT LIKE 'enc1:%'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $enc = ctrl_secret::Encrypt((string) $r['tf_secret_vc']);
            if ($enc !== '') {
                $set->execute(array(':s' => $enc, ':a' => (int) $r['tf_acc_fk'], ':old' => $r['tf_secret_vc']));
                $n += $set->rowCount();
            }
        }
        return $n;
    }

    /**
     * Must this account enter a code at login?
     * @param int|string $uid The account ID.
     * @return bool
     */
    static function Required($uid)
    {
        if (!self::Enabled()) {
            return false;
        }
        $row = self::Row($uid);
        return $row && (int) $row['tf_enabled_in'] === 1;
    }

    /**
     * Encode binary data as base32 (RFC 4648 alphabet, no padding).
     * @param string $bin The binary data.
     * @return string
     */
    static function Base32Encode($bin)
    {
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    /**
     * Decode a base32 string (characters outside the alphabet are ignored).
     * @param string $b32 The base32 text.
     * @return string The binary data.
     */
    static function Base32Decode($b32)
    {
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string) $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    /**
     * Return the TOTP/HOTP code for a secret and time step.
     * @param string $secret The base32 secret.
     * @param int $counter The time step (unix time / PERIOD).
     * @return string The 6-digit code, zero-padded.
     */
    static function Code($secret, $counter)
    {
        $hash = hash_hmac('sha1', pack('J', $counter), self::Base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % 1000000), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Check a code for an account (one step of clock drift either way). A code
     * that was already used can not be used again.
     *
     * A good code is stored as the last used time step.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @param string $code The code the user typed (spaces are ignored).
     * @param bool $requireEnabled Optional. Only accept when two-factor is already active for the account (false while setting it up). Default true.
     * @return bool
     */
    static function Verify($uid, $code, $requireEnabled = true)
    {
        global $zdbh;
        $row = self::Row($uid);
        $code = preg_replace('/\s+/', '', (string) $code);
        if (!$row || ($requireEnabled && (int) $row['tf_enabled_in'] !== 1) || !preg_match('/^[0-9]{6}$/', $code)) {
            return false;
        }
        $now = (int) floor(time() / self::PERIOD);
        for ($c = $now - 1; $c <= $now + 1; $c++) {
            if ($c > (int) $row['tf_lastcounter_in'] && hash_equals(self::Code($row['tf_secret_vc'], $c), $code)) {
                $zdbh->prepare("UPDATE x_twofactor SET tf_lastcounter_in = :c WHERE tf_acc_fk = :a")->execute(array(':c' => $c, ':a' => (int) $uid));
                return true;
            }
        }
        return false;
    }

    /**
     * Start setting up: a new secret, not active until a code from it is entered.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return string The new base32 secret.
     */
    static function Begin($uid)
    {
        global $zdbh;
        self::Ensure();
        $secret = self::Base32Encode(random_bytes(20));
        $zdbh->prepare("REPLACE INTO x_twofactor (tf_acc_fk, tf_secret_vc, tf_enabled_in, tf_lastcounter_in) VALUES (:a, :s, 0, 0)")
            ->execute(array(':a' => (int) $uid, ':s' => self::SecretTo($secret)));
        return $secret;
    }

    /**
     * Turn two-factor login on for the account and remove its "Remember me" tokens.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return void
     */
    static function Activate($uid)
    {
        global $zdbh;
        $zdbh->prepare("UPDATE x_twofactor SET tf_enabled_in = 1 WHERE tf_acc_fk = :a")->execute(array(':a' => (int) $uid));
        // "Remember me" logins made before the second step existed stop working
        ctrl_loginguard::Ensure();
        $zdbh->prepare("DELETE FROM x_remember_tokens WHERE rt_acc_fk = :a")->execute(array(':a' => (int) $uid));
    }

    /**
     * Turn two-factor login off for the account (deletes its secret and recovery codes).
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return void
     */
    static function Disable($uid)
    {
        global $zdbh;
        self::Ensure();
        $zdbh->prepare("DELETE FROM x_twofactor WHERE tf_acc_fk = :a")->execute(array(':a' => (int) $uid));
        $zdbh->prepare("DELETE FROM x_twofactor_recovery WHERE rc_acc_fk = :a")->execute(array(':a' => (int) $uid));
    }

    // --- recovery codes: for a lost phone ------------------------------------------------------

    const RECOVERY_COUNT = 10;
    const RECOVERY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I/L

    /**
     * Create ten new one-time codes (the old ones stop working); shown once, stored hashed.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return string[] The codes as XXXXX-XXXXX.
     */
    static function NewRecoveryCodes($uid)
    {
        global $zdbh;
        self::Ensure();
        $zdbh->prepare("DELETE FROM x_twofactor_recovery WHERE rc_acc_fk = :a")->execute(array(':a' => (int) $uid));
        $codes = array();
        $ins = $zdbh->prepare("INSERT INTO x_twofactor_recovery (rc_acc_fk, rc_hash_vc, rc_created_ts) VALUES (:a, :h, :t)");
        $n = strlen(self::RECOVERY_ALPHABET);
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $c = '';
            for ($j = 0; $j < 10; $j++) {
                $c .= self::RECOVERY_ALPHABET[random_int(0, $n - 1)];
            }
            $ins->execute(array(':a' => (int) $uid, ':h' => password_hash($c, PASSWORD_BCRYPT), ':t' => time()));
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        return $codes;
    }

    /**
     * Count the account's unused recovery codes.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return int
     */
    static function RecoveryLeft($uid)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_twofactor_recovery WHERE rc_acc_fk = :a AND rc_used_ts IS NULL");
        $q->execute(array(':a' => (int) $uid));
        return (int) $q->fetchColumn();
    }

    /**
     * Accept a recovery code instead of the app's code: true once, then that code is used up.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @param string $code The code the user typed (dashes and spaces ignored).
     * @return bool
     */
    static function UseRecoveryCode($uid, $code)
    {
        global $zdbh;
        self::Ensure();
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
        if (strlen($c) !== 10 || strspn($c, self::RECOVERY_ALPHABET) !== 10) {
            return false;
        }
        $q = $zdbh->prepare("SELECT rc_id_pk, rc_hash_vc FROM x_twofactor_recovery WHERE rc_acc_fk = :a AND rc_used_ts IS NULL");
        $q->execute(array(':a' => (int) $uid));
        foreach ($q->fetchAll() as $r) {
            if (password_verify($c, $r['rc_hash_vc'])) {
                $u = $zdbh->prepare("UPDATE x_twofactor_recovery SET rc_used_ts = :t WHERE rc_id_pk = :id AND rc_used_ts IS NULL");
                $u->execute(array(':t' => time(), ':id' => (int) $r['rc_id_pk']));
                if ($u->rowCount() === 1) {
                    self::Log('account ' . (int) $uid . ' logged in with a recovery code (' . self::RecoveryLeft($uid) . ' left)');
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * An administrator or reseller resets a client's two-factor login (lost phone and
     * codes): it is removed with its recovery codes and "Remember me" logins. With
     * enabled-forced the client sets it up again at the next login.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The client's account ID.
     * @param string $byUsername User name of the administrator or reseller (for the log).
     * @return void
     */
    static function ResetBy($uid, $byUsername)
    {
        global $zdbh;
        self::Disable($uid);
        ctrl_loginguard::Ensure();
        $zdbh->prepare("DELETE FROM x_remember_tokens WHERE rt_acc_fk = :a")->execute(array(':a' => (int) $uid));
        $u = ctrl_users::GetUserDetail((int) $uid);
        self::Log((string) $byUsername . ' reset the two-factor login of ' . ($u ? $u['username'] : 'account ' . (int) $uid));
    }

    /**
     * Append a line to /var/lib/sentora/twofactor.log (recovery codes used, resets).
     * @param string $text The message.
     * @return void
     */
    static function Log($text)
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-';
        @file_put_contents('/var/lib/sentora/twofactor.log', date('Y-m-d H:i:s') . ' ' . $text . ' (from ' . $ip . ")\n", FILE_APPEND | LOCK_EX);
        @chmod('/var/lib/sentora/twofactor.log', 0640);
    }

    /**
     * Return the otpauth:// link an authenticator app adds the account from.
     * @param string $secret The base32 secret.
     * @param string $username The account's user name.
     * @return string
     */
    static function OtpauthUri($secret, $username)
    {
        $issuer = 'Sentora ' . ctrl_options::GetSystemOption('sentora_domain');
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $username) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
    }

    /**
     * The QR code of a text (the otpauth:// link) as an inline SVG image, drawn on the
     * server with the bundled qrcode-generator (etc/lib/qrcode): an authenticator app
     * scans it to add the account. Medium error correction and a 4-module quiet zone,
     * as phone cameras expect; dark modules are drawn as horizontal runs.
     * @param string $text The text to encode.
     * @param int $pixels Optional. Width and height of the image. Default 220.
     * @return string The <svg> element.
     */
    static function QrSvg($text, $pixels = 220)
    {
        require_once ctrl_options::GetSystemOption('sentora_root') . 'etc/lib/qrcode/qrcode.php';
        $qr = QRCode::getMinimumQRCode((string) $text, QR_ERROR_CORRECT_LEVEL_M);
        $n = $qr->getModuleCount();
        $q = 4;
        $size = $n + 2 * $q;
        $path = '';
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if (!$qr->isDark($r, $c)) {
                    continue;
                }
                $run = 1;
                while ($c + $run < $n && $qr->isDark($r, $c + $run)) {
                    $run++;
                }
                $path .= 'M' . ($c + $q) . ' ' . ($r + $q) . 'h' . $run . 'v1h-' . $run . 'z';
                $c += $run - 1;
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $pixels . '" height="' . (int) $pixels . '" viewBox="0 0 ' . $size . ' ' . $size
            . '" shape-rendering="crispEdges" role="img" aria-label="QR code for your authenticator app">'
            . '<rect width="' . $size . '" height="' . $size . '" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
    }

}

?>
