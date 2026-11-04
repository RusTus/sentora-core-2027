<?php

/**
 * Optional two-factor login (TOTP, RFC 6238: 6-digit codes from any
 * authenticator app). Switched on for the whole panel with the Sentora Config
 * setting "twofactor_enabled"; each account then turns it on in My Account.
 *
 * To switch it off again if an administrator loses their phone, run on the
 * server:  setso --set twofactor_enabled false
 */
class ctrl_twofactor
{

    const DIGITS = 6;
    const PERIOD = 30;
    const PENDING_SECONDS = 300;

    static function Enabled()
    {
        return strtolower((string) ctrl_options::GetSystemOption('twofactor_enabled')) === 'true';
    }

    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if (!$done) {
            $zdbh->exec("CREATE TABLE IF NOT EXISTS x_twofactor (
                tf_acc_fk INT NOT NULL PRIMARY KEY, tf_secret_vc VARCHAR(64) NOT NULL,
                tf_enabled_in TINYINT NOT NULL DEFAULT 0, tf_lastcounter_in BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
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
    }

    static function OtpauthUri($secret, $username)
    {
        $issuer = 'Sentora ' . ctrl_options::GetSystemOption('sentora_domain');
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $username) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
    }

}

?>
