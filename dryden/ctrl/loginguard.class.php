<?php

/**
 * Login protection: lockout after repeated wrong passwords, and the
 * "Remember me" token.
 *
 * There was no limit at all on wrong passwords, and "Remember me" stored the
 * username and the bcrypt password hash in two cookies that logged in forever
 * (anyone who copied the cookies had the account until the password changed).
 */
class ctrl_loginguard
{

    const ACCOUNT_LIMIT = 5;      // wrong passwords per account ...
    const ADDRESS_LIMIT = 20;     // ... or per address ...
    const WINDOW = 900;           // ... within 15 minutes locks for 15 minutes
    const REMEMBER_DAYS = 30;
    const AUTH_LOG = '/var/lib/sentora/sentora-auth.log';

    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if ($done) {
            return;
        }
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_login_failures (
            lf_id_pk INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lf_user_vc VARCHAR(100) NOT NULL, lf_ip_vc VARCHAR(45) NOT NULL, lf_time_in INT NOT NULL,
            KEY lf_user (lf_user_vc, lf_time_in), KEY lf_ip (lf_ip_vc, lf_time_in)) ENGINE=InnoDB");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_remember_tokens (
            rt_id_pk INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            rt_acc_fk INT NOT NULL, rt_hash_vc CHAR(64) NOT NULL, rt_expires_in INT NOT NULL,
            KEY rt_hash (rt_hash_vc)) ENGINE=InnoDB");
        $done = true;
    }

    static function Address()
    {
        return substr((string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 0, 45);
    }

    /** too many recent failures for this account name or this address? */
    static function IsLocked($username)
    {
        global $zdbh;
        self::Ensure();
        $since = time() - self::WINDOW;
        $sql = $zdbh->prepare("SELECT
            (SELECT COUNT(*) FROM x_login_failures WHERE lf_user_vc = :u AND lf_time_in > :t1) AS byuser,
            (SELECT COUNT(*) FROM x_login_failures WHERE lf_ip_vc = :ip AND lf_time_in > :t2) AS byip");
        $username = strtolower((string) $username);
        $ip = self::Address();
        $sql->bindParam(':u', $username);
        $sql->bindParam(':ip', $ip);
        $sql->bindParam(':t1', $since);
        $sql->bindParam(':t2', $since);
        $sql->execute();
        $row = $sql->fetch();
        return ((int) $row['byuser'] >= self::ACCOUNT_LIMIT) || ((int) $row['byip'] >= self::ADDRESS_LIMIT);
    }

    static function Failed($username)
    {
        global $zdbh;
        self::Ensure();
        $sql = $zdbh->prepare("INSERT INTO x_login_failures (lf_user_vc, lf_ip_vc, lf_time_in) VALUES (:u, :ip, :t)");
        $username = substr(strtolower((string) $username), 0, 100);
        $ip = self::Address();
        $now = time();
        $sql->bindParam(':u', $username);
        $sql->bindParam(':ip', $ip);
        $sql->bindParam(':t', $now);
        $sql->execute();
        // One line per failure for fail2ban (jail sentora-panel), which blocks the address
        // at the firewall; the user name is cleaned so it can not forge other lines
        $clean = preg_replace('/[^A-Za-z0-9_.@:-]/', '?', substr((string) $username, 0, 64));
        @file_put_contents(self::AUTH_LOG, date('Y-m-d H:i:s') . " Sentora panel login failed for '" . $clean . "' from " . $ip . "\n", FILE_APPEND | LOCK_EX);
        $old = $now - 86400;
        $zdbh->prepare("DELETE FROM x_login_failures WHERE lf_time_in < :old")->execute(array(':old' => $old));
    }

    static function Succeeded($username)
    {
        global $zdbh;
        self::Ensure();
        $username = strtolower((string) $username);
        $zdbh->prepare("DELETE FROM x_login_failures WHERE lf_user_vc = :u")->execute(array(':u' => $username));
    }

    static function IsHttps()
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    }

    private static function SetCookie($name, $value, $expires)
    {
        setcookie($name, $value, array(
            'expires' => $expires, 'path' => '/', 'secure' => self::IsHttps(), 'httponly' => true, 'samesite' => 'Lax'));
    }

    /** "Remember me": a random token, only its hash is stored */
    static function Remember($uid)
    {
        global $zdbh;
        self::Ensure();
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = time() + 86400 * self::REMEMBER_DAYS;
        $uid = (int) $uid;
        $zdbh->prepare("INSERT INTO x_remember_tokens (rt_acc_fk, rt_hash_vc, rt_expires_in) VALUES (:a, :h, :e)")
            ->execute(array(':a' => $uid, ':h' => $hash, ':e' => $expires));
        self::SetCookie('zRemember', $uid . ':' . $token, $expires);
    }

    /** log in from a valid "Remember me" cookie (the token is replaced each time) */
    static function LoginFromCookie()
    {
        global $zdbh;
        // Cookies from before this change (username + password hash) are never used
        if (isset($_COOKIE['zUser']) || isset($_COOKIE['zPass'])) {
            self::SetCookie('zUser', '', time() - 3600);
            self::SetCookie('zPass', '', time() - 3600);
        }
        if (empty($_COOKIE['zRemember']) || !preg_match('/^([0-9]{1,10}):([0-9a-f]{64})$/', (string) $_COOKIE['zRemember'], $m)) {
            return false;
        }
        self::Ensure();
        $hash = hash('sha256', $m[2]);
        $now = time();
        $sql = $zdbh->prepare("SELECT rt_id_pk, rt_acc_fk FROM x_remember_tokens WHERE rt_hash_vc = :h AND rt_acc_fk = :a AND rt_expires_in > :t");
        $sql->execute(array(':h' => $hash, ':a' => (int) $m[1], ':t' => $now));
        $row = $sql->fetch();
        if (!$row) {
            self::Forget();
            return false;
        }
        $zdbh->prepare("DELETE FROM x_remember_tokens WHERE rt_id_pk = :id")->execute(array(':id' => $row['rt_id_pk']));
        $acc = $zdbh->prepare("SELECT ac_id_pk FROM x_accounts WHERE ac_id_pk = :a AND ac_enabled_in = 1 AND ac_deleted_ts IS NULL");
        $acc->execute(array(':a' => (int) $row['rt_acc_fk']));
        if (!$acc->fetch()) {
            self::Forget();
            return false;
        }
        ctrl_auth::SetUserSession((int) $row['rt_acc_fk'], true);
        self::Remember((int) $row['rt_acc_fk']);
        return true;
    }

    /** log out: the token stops working everywhere it was copied to */
    static function Forget()
    {
        global $zdbh;
        if (!empty($_COOKIE['zRemember']) && preg_match('/^[0-9]{1,10}:([0-9a-f]{64})$/', (string) $_COOKIE['zRemember'], $m)) {
            self::Ensure();
            $zdbh->prepare("DELETE FROM x_remember_tokens WHERE rt_hash_vc = :h")->execute(array(':h' => hash('sha256', $m[1])));
        }
        self::SetCookie('zRemember', '', time() - 3600);
        unset($_COOKIE['zRemember']);
    }

}

?>
