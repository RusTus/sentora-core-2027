<?php

/**
 * Optional two-factor login (TOTP, RFC 6238: 6-digit codes from any
 * authenticator app). Switched on for the whole panel with the Sentora Config
 * setting "twofactor_enabled" (disabled, enabled: each account turns it on in My Account,
 * or enabled-forced: every account must use it).
 *
 * To switch it off again if an administrator loses their phone, run on the
 * server:  setso --set twofactor_enabled disabled
 */
class ctrl_twofactor
{

    const DIGITS = 6;
    const PERIOD = 30;
    const PENDING_SECONDS = 300;

    /**
     * Sentora Config > Two-factor login: disabled, enabled (each account decides in My
     * Account) or enabled-forced (every account must use it: one that has not set it up
     * does so right after its password, before it can use the panel). Older values:
     * true = enabled, false = disabled.
     */
    static function Mode()
    {
        $v = strtolower(trim((string) ctrl_options::GetSystemOption('twofactor_enabled')));
        if ($v === 'true' || $v === 'enabled') {
            return 'enabled';
        }
        return ($v === 'enabled-forced') ? 'enabled-forced' : 'disabled';
    }

    static function Enabled()
    {
        return self::Mode() !== 'disabled';
    }

    static function Forced()
    {
        return self::Mode() === 'enabled-forced';
    }

    /** the setting's three choices (servers that still have true|false get them here) */
    static function EnsureSetting()
    {
        global $zdbh;
        $zdbh->exec("UPDATE x_settings SET so_defvalues_tx = 'disabled|enabled|enabled-forced',
            so_value_tx = CASE LOWER(so_value_tx) WHEN 'true' THEN 'enabled' WHEN 'false' THEN 'disabled' ELSE so_value_tx END,
            so_desc_tx = 'disabled: off. enabled: each account can turn on a 6-digit code from an authenticator app in My Account. enabled-forced: every account must use it (one that has not set it up does so at its next login).'
            WHERE so_name_vc = 'twofactor_enabled' AND so_defvalues_tx <> 'disabled|enabled|enabled-forced'");
    }

    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if (!$done) {
            self::EnsureSetting();
            $zdbh->exec("CREATE TABLE IF NOT EXISTS x_twofactor (
                tf_acc_fk INT NOT NULL PRIMARY KEY, tf_secret_vc VARCHAR(64) NOT NULL,
                tf_enabled_in TINYINT NOT NULL DEFAULT 0, tf_lastcounter_in BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
            // one-time recovery codes (stored hashed, like passwords)
            $zdbh->exec("CREATE TABLE IF NOT EXISTS x_twofactor_recovery (
                rc_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, rc_acc_fk INT NOT NULL, rc_hash_vc VARCHAR(255) NOT NULL,
                rc_used_ts INT NULL, rc_created_ts INT NOT NULL, KEY rc_acc (rc_acc_fk)) ENGINE=InnoDB");
            $done = true;
        }
    }

    static function Row($uid)
    {
        global $zdbh;
        self::Ensure();
        $sql = $zdbh->prepare("SELECT * FROM x_twofactor WHERE tf_acc_fk = :a");
        $sql->execute(array(':a' => (int) $uid));
        return $sql->fetch();
    }

    /** must this account enter a code at login? */
    static function Required($uid)
    {
        if (!self::Enabled()) {
            return false;
        }
        $row = self::Row($uid);
        return $row && (int) $row['tf_enabled_in'] === 1;
    }

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

    /** start setting up: a new secret, not active until a code from it is entered */
    static function Begin($uid)
    {
        global $zdbh;
        self::Ensure();
        $secret = self::Base32Encode(random_bytes(20));
        $zdbh->prepare("REPLACE INTO x_twofactor (tf_acc_fk, tf_secret_vc, tf_enabled_in, tf_lastcounter_in) VALUES (:a, :s, 0, 0)")
            ->execute(array(':a' => (int) $uid, ':s' => $secret));
        return $secret;
    }

    static function Activate($uid)
    {
        global $zdbh;
        $zdbh->prepare("UPDATE x_twofactor SET tf_enabled_in = 1 WHERE tf_acc_fk = :a")->execute(array(':a' => (int) $uid));
        // "Remember me" logins made before the second step existed stop working
        ctrl_loginguard::Ensure();
        $zdbh->prepare("DELETE FROM x_remember_tokens WHERE rt_acc_fk = :a")->execute(array(':a' => (int) $uid));
    }

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

    /** ten new one-time codes (the old ones stop working); shown once, stored hashed */
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

    static function RecoveryLeft($uid)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_twofactor_recovery WHERE rc_acc_fk = :a AND rc_used_ts IS NULL");
        $q->execute(array(':a' => (int) $uid));
        return (int) $q->fetchColumn();
    }

    /** a recovery code instead of the app's code: true once, then that code is used up */
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

    /** /var/lib/sentora/twofactor.log: recovery codes used, resets */
    static function Log($text)
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-';
        @file_put_contents('/var/lib/sentora/twofactor.log', date('Y-m-d H:i:s') . ' ' . $text . ' (from ' . $ip . ")\n", FILE_APPEND | LOCK_EX);
        @chmod('/var/lib/sentora/twofactor.log', 0640);
    }

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
