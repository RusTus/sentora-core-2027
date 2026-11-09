<?php

/**
 * One-time login links (the API's CreateLoginLink): a program (a reseller's own site, a billing
 * system) lets a client into the panel without typing the password.
 *
 * - A link works once, for TTL seconds, for one live client account; never for an administrator.
 * - Only its SHA-256 is kept; a link is 64 random hex characters.
 * - It stands for the password only: an account with two-factor login is still asked for the code,
 *   and a server that requires two-factor login still makes the account set it up
 *   (inc/init.inc.php, ?loginlink=).
 * - A wrong or used link counts like a wrong password for the address (ctrl_loginguard).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class ws_loginlink
{
    /** How long a link works (seconds). */
    const TTL = 120;

    /**
     * Create the x_login_links table if missing (once per request).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if (!$done) {
            $zdbh->exec("CREATE TABLE IF NOT EXISTS x_login_links (
                ll_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, ll_hash_vc CHAR(64) NOT NULL, ll_acc_fk INT NOT NULL,
                ll_key_fk INT NOT NULL DEFAULT 0, ll_expires_in INT NOT NULL, ll_used_in TINYINT NOT NULL DEFAULT 0,
                UNIQUE KEY ll_hash (ll_hash_vc)) ENGINE=InnoDB");
            $done = true;
        }
    }

    /**
     * The panel's address for an account: its reseller's own panel address when it is live
     * (Branding), else the main one.
     *
     * @param int $uid The account.
     * @return string Such as https://panel.example.com (no slash at the end).
     */
    static function PanelUrl($uid)
    {
        $branded = ctrl_branding::PanelUrlFor((int) $uid);
        if ($branded !== '') {
            return $branded;
        }
        $domain = (string) ctrl_options::GetSystemOption('sentora_domain');
        $port = (string) ctrl_options::GetSystemOption('sentora_port');
        $https = ctrl_options::GetSystemOption('panel_ssl_tx') != null || $port === '443';
        if ($port !== '' && $port !== '80' && $port !== '443') {
            $domain .= ':' . (int) $port;
        }
        return ($https ? 'https://' : 'http://') . $domain;
    }

    /**
     * Make a link.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid A live client account (checked by the caller: ws_xmws::Target()).
     * @param int $keyId The API key that asked (0: the server's).
     * @return string The full address.
     */
    static function Create($uid, $keyId)
    {
        global $zdbh;
        self::Ensure();
        $token = bin2hex(random_bytes(32));
        $zdbh->prepare("INSERT INTO x_login_links (ll_hash_vc, ll_acc_fk, ll_key_fk, ll_expires_in) VALUES (:h, :u, :k, :e)")
            ->execute(array(':h' => hash('sha256', $token), ':u' => (int) $uid, ':k' => (int) $keyId, ':e' => time() + self::TTL));
        $zdbh->prepare("DELETE FROM x_login_links WHERE ll_expires_in < :old")->execute(array(':old' => time() - 86400));
        return self::PanelUrl($uid) . '/?loginlink=' . $token;
    }

    /**
     * Use a link: it works once, in time, for a live client account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $token As in the address.
     * @return int The account, or 0.
     */
    static function Redeem($token)
    {
        global $zdbh;
        if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return 0;
        }
        self::Ensure();
        $q = $zdbh->prepare("SELECT ll_id_pk, ll_acc_fk FROM x_login_links WHERE ll_hash_vc = :h AND ll_used_in = 0 AND ll_expires_in >= :t");
        $q->execute(array(':h' => hash('sha256', $token), ':t' => time()));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return 0;
        }
        // used up first, in one step: two clicks at once can not both log in
        $u = $zdbh->prepare("UPDATE x_login_links SET ll_used_in = 1 WHERE ll_id_pk = :id AND ll_used_in = 0");
        $u->execute(array(':id' => (int) $r['ll_id_pk']));
        if ($u->rowCount() !== 1) {
            return 0;
        }
        $a = $zdbh->prepare("SELECT ac_id_pk FROM x_accounts WHERE ac_id_pk = :id AND ac_enabled_in = 1 AND ac_deleted_ts IS NULL AND ac_group_fk <> 1");
        $a->execute(array(':id' => (int) $r['ll_acc_fk']));
        return (int) $a->fetchColumn();
    }
}
