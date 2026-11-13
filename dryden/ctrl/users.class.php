<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * General user infoamtion class.
 * @package zpanelx
 * @subpackage dryden -> controller
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-06
 */
class ctrl_users {

    /**
     * Return an existing theme folder name, or '' (the caller then uses the reseller's or the default).
     *
     * Maps renamed themes to their new names; an unknown or unsafe name gives 'Modern'.
     * @param mixed $theme The stored theme name (string or null from the database).
     * @return string
     */
    static function SafeTheme($theme)
    {
        $theme = (string) $theme;
        // Renamed and retired themes, until sentora-update has moved the accounts
        $renamed = array('Kerf' => 'Modern', 'Legacy' => 'Modern', 'Sentora_Default' => 'Modern');
        if (isset($renamed[$theme])) {
            $theme = $renamed[$theme];
        }
        // A theme is a folder with a master.ztml; "_" folders hold shared files, not themes
        if ($theme === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $theme) || !is_file(dirname(dirname(__DIR__)) . '/etc/styles/' . $theme . '/master.ztml')) {
            return ($theme === '') ? '' : 'Modern';
        }
        return $theme;
    }


    /**
     * Returns an array of infomation for the account details, package, groups and quota limits for a given UID.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param int|string $uid Optional. The Sentora user account ID. Default '' (the logged-in user).
     * @return array<string, string> Keys: 'username', 'userid', 'password', 'email', 'resellerid', 'packageid', 'enabled', 'usertheme', 'usercss', 'lastlogon', 'fullname', 'packagename', 'usergroup', 'usergroupid', 'address', 'postcode', 'phone', 'language', the '*quota' limits and 'catorder' (all values as strings; '' when the account does not exist).
     */
    static function GetUserDetail($uid = "") {
        global $zdbh;
        $userdetail = new runtime_dataobject();
        if ($uid == "") {
            $uid = ctrl_auth::CurrentUserID();
        }
        // (asked for by many parts of every page: read once per page, again after anything changed)
        $read = function () use ($zdbh, $uid) {
            $rows = $zdbh->prepare("
                SELECT * FROM x_accounts
                LEFT JOIN x_profiles ON (x_accounts.ac_id_pk=x_profiles.ud_user_fk)
                LEFT JOIN x_groups   ON (x_accounts.ac_group_fk=x_groups.ug_id_pk)
                LEFT JOIN x_packages ON (x_accounts.ac_package_fk=x_packages.pk_id_pk)
                LEFT JOIN x_quotas   ON (x_accounts.ac_package_fk=x_quotas.qt_package_fk)
                WHERE x_accounts.ac_id_pk= :uid
              ");
            $rows->bindValue(':uid', $uid);
            $rows->execute();
            return $rows->fetch();
        };
        $dbvals = ($zdbh instanceof db_driver) ? $zdbh->Remember('user:' . $uid, $read) : $read();
        $userdetail->addItemValue('username', $dbvals['ac_user_vc']);
        $userdetail->addItemValue('userid', $dbvals['ac_id_pk']);
        $userdetail->addItemValue('password', $dbvals['ac_pass_vc']);
        $userdetail->addItemValue('email', $dbvals['ac_email_vc']);
        $userdetail->addItemValue('resellerid', $dbvals['ac_reseller_fk']);
        $userdetail->addItemValue('packageid', $dbvals['ac_package_fk']);
        $userdetail->addItemValue('enabled', $dbvals['ac_enabled_in']);
        // Theme and CSS names become file paths (the theme's templates are compiled
        // to PHP): only an existing theme folder and a plain CSS name are used
        $userdetail->addItemValue('usertheme', self::SafeTheme($dbvals['ac_usertheme_vc']));
        $userdetail->addItemValue('usercss', preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $dbvals['ac_usercss_vc']) ? $dbvals['ac_usercss_vc'] : '');
        $userdetail->addItemValue('lastlogon', $dbvals['ac_lastlogon_ts']);
        $userdetail->addItemValue('fullname', $dbvals['ud_fullname_vc']);
        $userdetail->addItemValue('packagename', $dbvals['pk_name_vc']);
        $userdetail->addItemValue('usergroup', $dbvals['ug_name_vc']);
        $userdetail->addItemValue('usergroupid', $dbvals['ac_group_fk']);
        $userdetail->addItemValue('address', $dbvals['ud_address_tx']);
        $userdetail->addItemValue('postcode', $dbvals['ud_postcode_vc']);
        $userdetail->addItemValue('phone', $dbvals['ud_phone_vc']);
        $userdetail->addItemValue('language', $dbvals['ud_language_vc']);
        $userdetail->addItemValue('diskquota', $dbvals['qt_diskspace_bi']);
        $userdetail->addItemValue('bandwidthquota', $dbvals['qt_bandwidth_bi']);
        $userdetail->addItemValue('domainquota', $dbvals['qt_domains_in']);
        $userdetail->addItemValue('subdomainquota', $dbvals['qt_subdomains_in']);
        $userdetail->addItemValue('parkeddomainquota', $dbvals['qt_parkeddomains_in']);
        $userdetail->addItemValue('ftpaccountsquota', $dbvals['qt_ftpaccounts_in']);
        $userdetail->addItemValue('mysqlquota', $dbvals['qt_mysql_in']);
        $userdetail->addItemValue('mailboxquota', $dbvals['qt_mailboxes_in']);
        $userdetail->addItemValue('forwardersquota', $dbvals['qt_fowarders_in']);
        $userdetail->addItemValue('distlistsquota', $dbvals['qt_distlists_in']);
        $userdetail->addItemValue('catorder', $dbvals['ac_catorder_vc']);
        return $userdetail->getDataObject();
    }

    /**
     * Returns the current usage of a particular resource.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param string $resource What time of quota should we be checking? (domains, subdomains, parkeddomains, mailboxes, forwarders, distlists, ftpaccounts, mysql, diskspace, bandwidth)
     * @param int $acc_key The user ID of which to check the quota status for.
     * @return int|string|null The count (or bytes for diskspace/bandwidth) as returned by PDO; null when there is no x_bandwidth row this month. An unknown $resource leaves the result undefined (null).
     */
    static function GetQuotaUsages($resource, $acc_key = 0) {
        global $zdbh;
        if ($resource == 'domains') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_vhosts WHERE vh_acc_fk= :acc_key AND vh_type_in=1 AND vh_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'subdomains') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_vhosts WHERE vh_acc_fk= :acc_key AND vh_type_in=2 AND vh_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'parkeddomains') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_vhosts WHERE vh_acc_fk= :acc_key AND vh_type_in=3 AND vh_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'mailboxes') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_mailboxes WHERE mb_acc_fk= :acc_key AND mb_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'forwarders') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_forwarders WHERE fw_acc_fk= :acc_key AND fw_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'distlists') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_distlists WHERE dl_acc_fk= :acc_key AND dl_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'ftpaccounts') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_ftpaccounts WHERE ft_acc_fk= :acc_key AND ft_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'mysql') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_mysql_databases WHERE my_acc_fk= :acc_key AND my_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'diskspace') {
            $sql = $zdbh->prepare("SELECT bd_diskamount_bi FROM x_bandwidth WHERE bd_acc_fk= :acc_key AND bd_month_in=" . date("Ym", time()) . "");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval ? $retval['bd_diskamount_bi'] : 0; // no row yet this month (the daemon adds it)
        }
        if ($resource == 'bandwidth') {
            $sql = $zdbh->prepare("SELECT bd_transamount_bi FROM x_bandwidth WHERE bd_acc_fk= :acc_key AND bd_month_in=" . date("Ym", time()) . "");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval ? $retval['bd_transamount_bi'] : 0; // no row yet this month (the daemon adds it)
        }
        return $retval;
    }

    /**
     * Count the account's domains of one type (1 domain, 2 subdomain, 3 parked).
     *
     * Broken: it calls execute() on an undefined $sql, so it fails before counting.
     * @global db_driver $zdbh The ZPX database handle.
     * @param int $userid The account ID.
     * @param int|string $type Optional. The vh_type_in value. Default "1".
     * @return int
     * @todo Does this still need to be here as this is now managed under a module and not seen as 'core' but template still relies on this at present! - Bobby Allen
     */
    static function GetUserDomains($userid, $type = "1") {
        global $zdbh;
        $domains = 0;
        $numrows = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_acc_fk= :userid AND vh_deleted_ts IS NULL AND vh_type_in= :type");
        $numrows->bindParam(':userid', $userid);
        $numrows->bindParam(':type', $type);
        $status = $sql->execute();
        if ($status) {
            if ($numrows->fetchColumn() <> 0) {
                $domains = count($numrows->fetchColumn());
                return $domains;
            }
        }
        return $domains;
    }

    /**
     * Checks that the specified user is active and therefore allowed to login to the panel.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param int $uid The Sentora user account ID.
     * @return bool
     */
    static function CheckUserEnabled($uid) {
        global $zdbh;
        $domains = 0;
        $sql = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_id_pk= :uid AND ac_enabled_in=1 AND ac_deleted_ts IS NULL");
        $sql->bindParam(':uid', $uid);
        $status = $sql->execute();

        if ($status) {
            if ($sql->fetchColumn() <> 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks that a specified email address is unique in the user accounts table.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param string $email The email address to check.
     * @return bool True when no (not deleted) account uses the address.
     */
    static function CheckUserEmailIsUnique($email) {
        global $zdbh;
            $sql = "SELECT COUNT(*) FROM x_accounts WHERE LOWER(ac_email_vc)=:email AND ac_deleted_ts IS NULL";
            $uniqueuser = $zdbh->prepare($sql);
            // Compared in lower case on both sides (a capital letter used to let a duplicate through)
            $email = strtolower(trim((string) $email));
            $uniqueuser->bindParam(':email', $email);
            if ($uniqueuser->execute()) {
                if ($uniqueuser->fetchColumn() > 0) {
                    return false;
                } else {
                    return true;
                }
            } else {
                return false;
            }

        }
    }
?>
