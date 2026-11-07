<?php

/**
 * FTP Accounts module: the FTP accounts of a switched-off client are switched off with it.
 *
 * ProFTPD checks a login against ftpuser.passwd (a "$6$" crypt hash). While the owning
 * client is switched off (x_accounts.ac_enabled_in = 0) that column holds the hash with a
 * "!" in front, which no password matches; when the client is switched on again the hash
 * is put back from the panel's own copy (x_ftpaccounts.ft_password_vc). Nothing else changes,
 * so the passwords, folders and access types are as they were.
 *
 * Used by the OnAfterEnableClient and OnAfterDisableClient hooks (right away), after an FTP
 * account is made or its password reset, and by the daemon (every run, as a backstop).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

if (!function_exists('FtpSyncClientState')) {

    /**
     * Block the FTP logins of switched-off clients and restore those of switched-on ones.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<string, int> 'off': logins blocked now, 'on': logins restored now.
     */
    function FtpSyncClientState()
    {
        global $zdbh;
        $ftpDb = (string) ctrl_options::GetSystemOption('ftp_db');
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $ftpDb)) {
            $ftpDb = 'sentora_proftpd';
        }
        $done = array('off' => 0, 'on' => 0);
        try {
            // switched off: "!" + the hash (only hashes: a plain-text password is hashed by the daemon first)
            $done['off'] = (int) $zdbh->exec("UPDATE `" . $ftpDb . "`.ftpuser u
                JOIN x_ftpaccounts f ON f.ft_user_vc = u.userid AND f.ft_deleted_ts IS NULL
                JOIN x_accounts a ON a.ac_id_pk = f.ft_acc_fk
                SET u.passwd = CONCAT('!', f.ft_password_vc)
                WHERE a.ac_enabled_in = 0 AND f.ft_password_vc LIKE '$6$%' AND u.passwd <> CONCAT('!', f.ft_password_vc)");
            // switched on again: the panel's hash back
            $done['on'] = (int) $zdbh->exec("UPDATE `" . $ftpDb . "`.ftpuser u
                JOIN x_ftpaccounts f ON f.ft_user_vc = u.userid AND f.ft_deleted_ts IS NULL
                JOIN x_accounts a ON a.ac_id_pk = f.ft_acc_fk
                SET u.passwd = f.ft_password_vc
                WHERE a.ac_enabled_in = 1 AND u.passwd LIKE '!%' AND f.ft_password_vc LIKE '$6$%'");
        } catch (Exception $e) {
            // no FTP database (or no rights to it): nothing to switch
        }
        return $done;
    }

    /**
     * Whether a client's FTP accounts are switched off with it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $userid The client's account ID.
     * @return bool True when the client is switched off.
     */
    function FtpClientSwitchedOff($userid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = :id");
        $q->execute(array(':id' => (int) $userid));
        $v = $q->fetchColumn();
        return $v !== false && (int) $v === 0;
    }
}
