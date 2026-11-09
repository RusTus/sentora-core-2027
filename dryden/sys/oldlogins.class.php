<?php

/**
 * Older, weaker logins kept only for websites moved from an old Sentora server, so they can be
 * found, and retired once nothing needs them (PHP Versions > Older logins).
 *
 * - MySQL users with mysql_native_password (SHA-1 based): PHP 7.1 can not log in with MySQL 8.4's
 *   default caching_sha2_password. MySQL 8.4 keeps the method off until mysql_native_password = ON
 *   in its settings; sentora-migrate-import switches it on for moved sites on PHP 7.1.
 * - Mailboxes whose password is still a {PLAIN-MD5} hash (moved from an old server): accepted until
 *   the password is next changed, then stored as SHA-512 crypt.
 *
 * MariaDB is left out of the MySQL part: there mysql_native_password is the normal method.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class sys_oldlogins
{
    /** MySQL's settings file the older method is switched on in. */

    /** The setting the panel asks the daemon with to switch the older method off ('requested'). */
    const REQUEST = 'mysql_native_off';

    /**
     * Is this server MySQL (not MariaDB)?
     *
     * @return bool
     */
    static function IsMysql()
    {
        global $zdbh;
        static $is = null;
        if ($is === null) {
            $is = stripos((string) $zdbh->query("SELECT VERSION()")->fetchColumn(), 'mariadb') === false;
        }
        return $is;
    }

    /**
     * Is MySQL's older login method (mysql_native_password) switched on?
     *
     * @return bool False on MariaDB (not a separate method there) and when it is off.
     */
    static function NativeActive()
    {
        global $zdbh;
        if (!self::IsMysql()) {
            return false;
        }
        try {
            return $zdbh->query("SELECT PLUGIN_STATUS FROM information_schema.PLUGINS WHERE PLUGIN_NAME = 'mysql_native_password'")->fetchColumn() === 'ACTIVE';
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * The MySQL users that log in with the older method, with their panel record when they have one.
     *
     * @return array<int, array<string, mixed>> user, host, mu_id_pk, mu_acc_fk, owner (null for users the panel does not manage).
     */
    static function NativeUsers()
    {
        global $zdbh;
        if (!self::IsMysql()) {
            return array();
        }
        try {
            return $zdbh->query("SELECT u.User AS user, u.Host AS host, m.mu_id_pk, m.mu_acc_fk, a.ac_user_vc AS owner
                FROM mysql.user u
                LEFT JOIN x_mysql_users m ON m.mu_name_vc = u.User AND m.mu_access_vc = u.Host AND m.mu_deleted_ts IS NULL
                LEFT JOIN x_accounts a ON a.ac_id_pk = m.mu_acc_fk
                WHERE u.plugin = 'mysql_native_password' AND u.User NOT LIKE 'mysql.%'
                ORDER BY a.ac_user_vc, u.User")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * Does a MySQL user log in with the older method?
     *
     * @param string $user The MySQL user name.
     * @param string $host Its host ('localhost', '%' or an address).
     * @return bool
     */
    static function UsesNative($user, $host)
    {
        global $zdbh;
        if (!self::IsMysql()) {
            return false;
        }
        try {
            $q = $zdbh->prepare("SELECT plugin FROM mysql.user WHERE User = :u AND Host = :h");
            $q->execute(array(':u' => $user, ':h' => $host));
            return $q->fetchColumn() === 'mysql_native_password';
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * The account's domains that run on a PHP older than 7.4 (which can not use MySQL 8.4's
     * default login method).
     *
     * @param int $accountId The account (x_accounts) ID.
     * @return string[] The domain names.
     */
    static function OldPhpDomains($accountId)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT vh_name_vc, vh_php_version_vc FROM x_vhosts WHERE vh_acc_fk = :a AND vh_deleted_ts IS NULL AND vh_php_version_vc IS NOT NULL AND vh_php_version_vc <> ''");
        $q->execute(array(':a' => (int) $accountId));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $v = sys_phpversions::Effective($r['vh_php_version_vc']);
            if (version_compare($v, '7.4', '<')) {
                $out[] = $r['vh_name_vc'];
            }
        }
        return $out;
    }

    /**
     * Should a new MySQL user of this account log in with the older method? Only when MySQL has
     * it switched on and the account has domains on a PHP older than 7.4.
     *
     * @param int $accountId The account (x_accounts) ID.
     * @return bool
     */
    static function NewUsersNeedNative($accountId)
    {
        return self::NativeActive() && count(self::OldPhpDomains($accountId)) > 0;
    }

    /**
     * Move a panel MySQL user to MySQL's default login method, with the same password (the
     * panel's encrypted copy of it), so the sites' settings keep working.
     *
     * @param int $muId The x_mysql_users ID.
     * @return string '' when done, otherwise the (English) reason.
     */
    static function Modernize($muId)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT mu_name_vc, mu_access_vc, mu_pass_vc FROM x_mysql_users WHERE mu_id_pk = :i AND mu_deleted_ts IS NULL");
        $q->execute(array(':i' => (int) $muId));
        $u = $q->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            return 'That MySQL user is not in the panel.';
        }
        $pass = ctrl_secret::Decrypt($u['mu_pass_vc']);
        if ($pass === false || $pass === '') {
            return 'The panel has no copy of its password: set a new one in MySQL Users (and in the site\'s settings).';
        }
        try {
            $zdbh->prepare("ALTER USER :u@:h IDENTIFIED WITH caching_sha2_password BY :p")
                ->execute(array(':u' => $u['mu_name_vc'], ':h' => $u['mu_access_vc'] ?: 'localhost', ':p' => $pass));
        } catch (Exception $e) {
            return 'MySQL refused: ' . $e->getMessage();
        }
        return '';
    }

    /**
     * The mailboxes whose password is still an old {PLAIN-MD5} hash.
     *
     * @return string[] The addresses.
     */
    static function Md5Mailboxes()
    {
        global $zdbh;
        try {
            return $zdbh->query("SELECT username FROM sentora_postfix.mailbox WHERE password LIKE '{PLAIN-MD5}%' ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * The request to switch the older method off, and its outcome.
     *
     * @return string '' (none), 'requested', or what the daemon did ('done ...', 'failed: ...').
     */
    static function OffState()
    {
        return (string) ctrl_options::GetSystemOption(self::REQUEST);
    }

    /**
     * Ask the daemon (root) to switch MySQL's older login method off.
     *
     * @return void
     */
    static function RequestOff()
    {
        global $zdbh;
        if ((int) $zdbh->query("SELECT COUNT(*) FROM x_settings WHERE so_name_vc = '" . self::REQUEST . "'")->fetchColumn() === 0) {
            $zdbh->exec("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_desc_tx, so_module_vc, so_usereditable_en)
                VALUES ('" . self::REQUEST . "', 'Switch off MySQL older logins', '', 'Set by PHP Versions, done by the daemon.', 'PHP Versions', 'false')");
        }
        $zdbh->prepare("UPDATE x_settings SET so_value_tx = 'requested' WHERE so_name_vc = :n")->execute(array(':n' => self::REQUEST));
    }

    /**
     * Daemon (root): switch MySQL's older login method off when asked and nothing uses it any more.
     * The line in MySQL's settings is turned into a comment and MySQL restarts.
     *
     * @return void
     */
    static function DaemonSwitchOff()
    {
        global $zdbh;
        if (self::OffState() !== 'requested') {
            return;
        }
        $set = function ($v) use ($zdbh) {
            $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = :n")->execute(array(':v' => $v, ':n' => self::REQUEST));
        };
        $users = self::NativeUsers();
        if ($users) {
            $set('failed: ' . count($users) . ' MySQL user(s) still use it');
            return;
        }
        $c = (string) @file_get_contents(sys_platform::Get('db_conf_file'));
        if ($c === '' || !preg_match('/^\s*mysql[_-]native[_-]password\s*=\s*(ON|1|TRUE)\s*$/mi', $c)) {
            $set('failed: ' . sys_platform::Get('db_conf_file') . ' does not switch it on (it may be set elsewhere)');
            return;
        }
        @copy(sys_platform::Get('db_conf_file'), sys_platform::Get('db_conf_file') . '.before-off');
        $c = preg_replace('/^(\s*)(mysql[_-]native[_-]password\s*=\s*(ON|1|TRUE)\s*)$/mi', '$1# $2  (switched off by Sentora, PHP Versions)', $c);
        file_put_contents(sys_platform::Get('db_conf_file'), $c);
        exec('systemctl restart ' . escapeshellarg(sys_platform::Get('db_service')) . ' 2>&1', $o, $rc);
        echo "MySQL: the older login method (mysql_native_password) switched off, MySQL restarted" . ($rc === 0 ? '' : ' (with an error: ' . implode(' ', $o) . ')') . fs_filehandler::NewLine();
        // the daemon's own connection went with the restart
        include ctrl_options::GetSystemOption('sentora_root') . 'cnf/db.php';
        $zdbh = new db_driver("mysql:host=$host;dbname=$dbname", $user, $pass);
        $GLOBALS['zdbh'] = $zdbh;
        $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = :n")->execute(array(':v' => ($rc === 0 ? 'done ' : 'failed: MySQL restarted with an error, ') . date('Y-m-d H:i'), ':n' => self::REQUEST));
    }
}
