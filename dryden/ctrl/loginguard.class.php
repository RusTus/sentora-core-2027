<?php

/**
 * Login protection: lockout after repeated wrong passwords, and the
 * "Remember me" token.
 *
 * There was no limit at all on wrong passwords, and "Remember me" stored the
 * username and the bcrypt password hash in two cookies that logged in forever
 * (anyone who copied the cookies had the account until the password changed).
 *
 * Written by RusTus, 2026-11-07
 */
class ctrl_loginguard
{

    const ACCOUNT_LIMIT = 5;      // wrong passwords per account ...
    const ADDRESS_LIMIT = 20;     // ... or per address ...
    const WINDOW = 900;           // ... within 15 minutes locks for 15 minutes
    const REMEMBER_DAYS = 30;
    const RESET_EMAIL_LIMIT = 3;    // password reset emails per address ...
    const RESET_ADDRESS_LIMIT = 10; // ... or requests per IP, within WINDOW
    const RESET_PREFIX = 'reset:';  // reset requests share x_login_failures under this prefix
    const AUTH_LOG = '/var/lib/sentora/sentora-auth.log';

    /**
     * Create the x_login_failures and x_remember_tokens tables if they are missing.
     *
     * Runs the CREATE TABLE statements at most once per request.
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
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

    /**
     * Return the client's IP address (REMOTE_ADDR), cut to 45 characters.
     * @return string The address, or '' when there is none (CLI).
     */
    static function Address()
    {
        return substr((string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 0, 45);
    }

    /**
     * Too many recent failures for this account name or this address?
     * @global db_driver $zdbh The panel's database connection.
     * @param string $username The user name typed on the login form.
     * @return bool True when the login must be refused for now.
     */
    static function IsLocked($username)
    {
        global $zdbh;
        self::Ensure();
        $since = time() - self::WINDOW;
        $sql = $zdbh->prepare("SELECT
            (SELECT COUNT(*) FROM x_login_failures WHERE lf_user_vc = :u AND lf_time_in > :t1) AS byuser,
            (SELECT COUNT(*) FROM x_login_failures WHERE lf_ip_vc = :ip AND lf_time_in > :t2 AND lf_user_vc NOT LIKE 'reset:%') AS byip");
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

    /**
     * Record one wrong password for this user name and address.
     *
     * Also writes a line to the fail2ban log (AUTH_LOG) and deletes failures older
     * than a day.
     * @global db_driver $zdbh The panel's database connection.
     * @param string $username The user name typed on the login form.
     * @return void
     */
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

    /**
     * Clear the recorded failures for a user name after a good login.
     * @global db_driver $zdbh The panel's database connection.
     * @param string $username The user name that logged in.
     * @return void
     */
    static function Succeeded($username)
    {
        global $zdbh;
        self::Ensure();
        $username = strtolower((string) $username);
        $zdbh->prepare("DELETE FROM x_login_failures WHERE lf_user_vc = :u")->execute(array(':u' => $username));
    }

    /**
     * Forgot password: may another reset email be sent for this address, from this IP?
     * Counts every request (known address or not), so the form can neither flood an
     * inbox nor be used to find out which addresses have accounts.
     * @global db_driver $zdbh The panel's database connection.
     * @param string $email The email address typed on the form.
     * @return bool True when another reset email may be sent.
     */
    static function ResetAllowed($email)
    {
        global $zdbh;
        self::Ensure();
        $since = time() - self::WINDOW;
        $key = substr(self::RESET_PREFIX . strtolower(trim((string) $email)), 0, 100);
        $ip = self::Address();
        $sql = $zdbh->prepare("SELECT
            (SELECT COUNT(*) FROM x_login_failures WHERE lf_user_vc = :k AND lf_time_in > :t1) AS byemail,
            (SELECT COUNT(*) FROM x_login_failures WHERE lf_ip_vc = :ip AND lf_time_in > :t2 AND lf_user_vc LIKE 'reset:%') AS byip");
        $sql->bindParam(':k', $key);
        $sql->bindParam(':ip', $ip);
        $sql->bindParam(':t1', $since);
        $sql->bindParam(':t2', $since);
        $sql->execute();
        $row = $sql->fetch();
        return ((int) $row['byemail'] < self::RESET_EMAIL_LIMIT) && ((int) $row['byip'] < self::RESET_ADDRESS_LIMIT);
    }

    /**
     * Record one forgot-password request (no fail2ban line: it is not a wrong password).
     * @global db_driver $zdbh The panel's database connection.
     * @param string $email The email address typed on the form.
     * @return void
     */
    static function ResetRequested($email)
    {
        global $zdbh;
        self::Ensure();
        $key = substr(self::RESET_PREFIX . strtolower(trim((string) $email)), 0, 100);
        $ip = self::Address();
        $now = time();
        $sql = $zdbh->prepare("INSERT INTO x_login_failures (lf_user_vc, lf_ip_vc, lf_time_in) VALUES (:u, :ip, :t)");
        $sql->bindParam(':u', $key);
        $sql->bindParam(':ip', $ip);
        $sql->bindParam(':t', $now);
        $sql->execute();
        $old = $now - 86400;
        $zdbh->prepare("DELETE FROM x_login_failures WHERE lf_time_in < :old")->execute(array(':old' => $old));
    }

    /**
     * Was this request made over HTTPS?
     * @return bool
     */
    static function IsHttps()
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    }

    /**
     * Set an HttpOnly, SameSite=Lax cookie on path /, secure when the request is HTTPS.
     * @param string $name The cookie name.
     * @param string $value The cookie value ('' to delete it).
     * @param int $expires Unix time the cookie expires.
     * @return void
     */
    private static function SetCookie($name, $value, $expires)
    {
        setcookie($name, $value, array(
            'expires' => $expires, 'path' => '/', 'secure' => self::IsHttps(), 'httponly' => true, 'samesite' => 'Lax'));
    }

    /**
     * "Remember me": store a new random token (only its hash) and set the zRemember cookie.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID (cast to int).
     * @return void
     */
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

    /**
     * Log in from a valid "Remember me" cookie (the token is replaced each time).
     *
     * Also deletes the old zUser/zPass cookies, and the zRemember cookie when it is not valid.
     * @global db_driver $zdbh The panel's database connection.
     * @return bool True when the session was started from the cookie.
     */
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
        // Two-factor required for everyone, and this account has not set it up (the token is from
        // before that was switched on): log in with the password, which then asks to set it up. The
        // cookie login came after that check in init.inc.php, so its first page went without it.
        if (ctrl_twofactor::Forced() && !ctrl_twofactor::Required((int) $row['rt_acc_fk'])) {
            self::Forget();
            return false;
        }
        ctrl_auth::SetUserSession((int) $row['rt_acc_fk'], true);
        self::Remember((int) $row['rt_acc_fk']);
        return true;
    }

    /**
     * Log out: delete the stored token (it stops working everywhere it was copied to) and the cookie.
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
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
