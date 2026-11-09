<?php

/**
 * What the API tells about packages and clients, the same in every request: a package's limits, a
 * client's details (never its password hash) and its use against its limits.
 *
 * Sizes are in MB as the panel counts them (1 MB = 1,024,000 bytes, as the Packages page stores
 * them); a limit of -1 is "no limit".
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class ws_data
{
    /** The panel's MB (Packages stores MB times this). */
    const MB = 1024000;

    /** The limits and their x_quotas columns. */
    const LIMITS = array(
        'domains' => 'qt_domains_in', 'subdomains' => 'qt_subdomains_in', 'parkeddomains' => 'qt_parkeddomains_in',
        'mailboxes' => 'qt_mailboxes_in', 'forwarders' => 'qt_fowarders_in', 'distlists' => 'qt_distlists_in',
        'ftpaccounts' => 'qt_ftpaccounts_in', 'mysql' => 'qt_mysql_in',
    );

    /**
     * A package with its limits.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id The package.
     * @return array|null id, name, owner, php, clients, and each limit (diskspacemb, bandwidthmb; -1: no limit).
     */
    static function Package($id)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT p.*, q.* FROM x_packages p LEFT JOIN x_quotas q ON q.qt_package_fk = p.pk_id_pk WHERE p.pk_id_pk = :id AND p.pk_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $id));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $c = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_package_fk = :id AND ac_deleted_ts IS NULL");
        $c->execute(array(':id' => (int) $id));
        $out = array('id' => (int) $r['pk_id_pk'], 'name' => (string) $r['pk_name_vc'], 'owner' => (int) $r['pk_reseller_fk'],
            'php' => (int) $r['pk_enablephp_in'] === 1 ? 'true' : 'false', 'clients' => (int) $c->fetchColumn());
        foreach (self::LIMITS as $k => $col) {
            $out[$k] = isset($r[$col]) ? (int) $r[$col] : 0;
        }
        foreach (array('diskspacemb' => 'qt_diskspace_bi', 'bandwidthmb' => 'qt_bandwidth_bi') as $k => $col) {
            // (a size of 0 is "no limit" on the Packages page; the API says -1 for every "no limit")
            $v = isset($r[$col]) ? (float) $r[$col] : 0;
            $out[$k] = $v <= 0 ? -1 : round($v / self::MB, 2);
        }
        return $out;
    }

    /**
     * The packages an account owns.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $owner The account.
     * @return array<int, array> From Package().
     */
    static function Packages($owner)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT pk_id_pk FROM x_packages WHERE pk_reseller_fk = :u AND pk_deleted_ts IS NULL ORDER BY pk_name_vc");
        $q->execute(array(':u' => (int) $owner));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $p = self::Package((int) $id);
            if ($p) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * Does the package belong to the account (and is it live)?
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param mixed $id The package as sent.
     * @param int $owner The account.
     * @return int The package, or 0.
     */
    static function OwnPackage($id, $owner)
    {
        global $zdbh;
        if (!is_scalar($id) || !ctype_digit((string) $id)) {
            return 0;
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_packages WHERE pk_id_pk = :id AND pk_reseller_fk = :u AND pk_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $id, ':u' => (int) $owner));
        return (int) $q->fetchColumn() > 0 ? (int) $id : 0;
    }

    /**
     * A client's details, for the API: never its password hash, its reset key or its two-factor secret.
     *
     * @param int $uid The account.
     * @return array
     */
    static function Client($uid)
    {
        $u = ctrl_users::GetUserDetail((int) $uid);
        $keep = array('userid', 'username', 'fullname', 'email', 'phone', 'address', 'postcode', 'resellerid', 'packageid', 'packagename',
            'usergroupid', 'usergroup', 'enabled', 'language', 'lastlogon');
        $out = array();
        foreach ($keep as $k) {
            $out[$k] = isset($u[$k]) ? $u[$k] : '';
        }
        $out['suspended'] = '';
        if (class_exists('ws_suspend') && (int) $out['enabled'] !== 1) {
            $out['suspended'] = ws_suspend::Reason((int) $uid);
        }
        return $out;
    }

    /**
     * May the account add one more of something, within its package? (The pages check this before
     * they add; the API must too, or a client could go past its package.)
     *
     * @param int $uid The account.
     * @param string $what 'domains', 'subdomains', 'parkeddomains', 'mailboxes', 'forwarders',
     *                     'distlists', 'ftpaccounts' or 'mysql'.
     * @return bool
     */
    static function UnderLimit($uid, $what)
    {
        $key = array('domains' => 'domainquota', 'subdomains' => 'subdomainquota', 'parkeddomains' => 'parkeddomainquota',
            'mailboxes' => 'mailboxquota', 'forwarders' => 'forwardersquota', 'distlists' => 'distlistsquota',
            'ftpaccounts' => 'ftpaccountsquota', 'mysql' => 'mysqlquota');
        if (!isset($key[$what])) {
            return false;
        }
        $u = ctrl_users::GetUserDetail((int) $uid);
        $limit = (int) $u[$key[$what]];
        return $limit < 0 || $limit > (int) ctrl_users::GetQuotaUsages($what, (int) $uid);
    }

    /**
     * A client's use against its package's limits (this month's bandwidth).
     *
     * @param int $uid The account.
     * @return array For each limit: <name>used and <name>limit (-1: no limit); disk and bandwidth in MB.
     */
    static function Usage($uid)
    {
        $u = ctrl_users::GetUserDetail((int) $uid);
        $p = self::Package((int) $u['packageid']);
        $out = array();
        foreach (array_keys(self::LIMITS) as $k) {
            $out[$k . 'used'] = (int) ctrl_users::GetQuotaUsages($k === 'forwarders' ? 'forwarders' : $k, (int) $uid);
            $out[$k . 'limit'] = $p ? $p[$k] : 0;
        }
        $out['diskspacembused'] = round((float) ctrl_users::GetQuotaUsages('diskspace', (int) $uid) / self::MB, 2);
        $out['diskspacemblimit'] = $p ? $p['diskspacemb'] : 0;
        $out['bandwidthmbused'] = round((float) ctrl_users::GetQuotaUsages('bandwidth', (int) $uid) / self::MB, 2);
        $out['bandwidthmblimit'] = $p ? $p['bandwidthmb'] : 0;
        $out['month'] = date('Y-m');
        return $out;
    }
}
