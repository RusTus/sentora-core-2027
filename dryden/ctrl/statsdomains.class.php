<?php

/**
 * The panel's own domain in the statistics modules (Webalizer, Visitor Stats,
 * Matomo): the panel is not a hosted domain, so it has no row in x_vhosts, but
 * its traffic (/var/sentora/logs/sentora-access.log) is reported under the main
 * administrator account created at install (account 1, zadmin).
 */
class ctrl_statsdomains
{

    const OWNER_ID = 1;

    static function PanelDomain()
    {
        $domain = strtolower(trim((string) ctrl_options::GetSystemOption('sentora_domain')));
        return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain) && strpos($domain, '..') === false ? $domain : false;
    }

    static function PanelLog()
    {
        return rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/') . '/sentora-access.log';
    }

    static function OwnerName()
    {
        global $zdbh;
        $sql = $zdbh->prepare("SELECT ac_user_vc FROM x_accounts WHERE ac_id_pk = :id AND ac_deleted_ts IS NULL");
        $sql->execute(array(':id' => self::OWNER_ID));
        $name = $sql->fetchColumn();
        return ($name !== false && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) ? $name : false;
    }

    /** is $domain the panel's own domain, and is $uid the account that owns its stats? */
    static function IsPanelDomainOf($uid, $domain)
    {
        $panel = self::PanelDomain();
        return $panel !== false && (int) $uid === self::OWNER_ID && strcasecmp((string) $domain, $panel) === 0;
    }

    /** the panel domain as an extra row for the owner's domain lists (no x_vhosts id) */
    static function PanelRowFor($uid)
    {
        $panel = self::PanelDomain();
        if ($panel === false || (int) $uid !== self::OWNER_ID) {
            return false;
        }
        return array('vh_id_pk' => 0, 'vh_name_vc' => $panel, 'vh_directory_vc' => '', 'vh_active_in' => 1);
    }


    /**
     * The log analysers (Webalizer, Visitors, GoAccess) parse text that website
     * visitors control, so they run as this user, which can read nothing else: the
     * root jobs open the logs and give them the text on standard input.
     */
    const STATS_USER = 'sentora-stats';

    static function EnsureStatsUser()
    {
        if (posix_getpwnam(self::STATS_USER) === false) {
            exec('useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin ' . escapeshellarg(self::STATS_USER) . ' 2>&1');
        }
        return posix_getpwnam(self::STATS_USER) !== false;
    }

    /** a command line run as the stats user, at low priority and for 15 minutes at most */
    static function AsStatsUser($command)
    {
        $u = escapeshellarg(self::STATS_USER);
        return 'nice -n 10 timeout 900 setpriv --reuid=' . $u . ' --regid=' . $u . ' --clear-groups --reset-env ' . $command;
    }

}

?>
