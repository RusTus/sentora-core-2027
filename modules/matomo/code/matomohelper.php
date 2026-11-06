<?php

/**
 * Matomo for Sentora: shared code for the panel page and the daemon jobs.
 *
 * Matomo (etc/apps/matomo, Matomo 5) runs in the panel's own PHP pool. Every
 * domain and subdomain gets a Matomo site, every account a view-only Matomo user
 * for exactly its own domains (the panel opens Matomo with that user's token).
 * Visits come from the web server logs (imported every hour) or, per domain,
 * from the tracking code the site owner adds to their pages.
 */
class matomo_helper
{

    /** Where Matomo is installed. */
    const APP_DIR = '/etc/sentora/panel/etc/apps/matomo/';
    /** Folder for the files the daemon hands to Matomo (sync spec, log chunks, token). */
    const SPEC_DIR = '/var/lib/sentora/matomo/';

    /**
     * Create the module's tables if they do not exist (once per request).
     *
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
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_matomo_sites (
            ms_domain_vc VARCHAR(255) NOT NULL PRIMARY KEY, ms_acc_fk INT NOT NULL, ms_idsite_in INT NOT NULL DEFAULT 0,
            ms_source_vc VARCHAR(8) NOT NULL DEFAULT 'log', ms_offset_bi BIGINT NOT NULL DEFAULT 0, ms_inode_bi BIGINT NOT NULL DEFAULT 0,
            ms_deleted_in TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_matomo_users (
            mu_acc_fk INT NOT NULL PRIMARY KEY, mu_login_vc VARCHAR(100) NOT NULL, mu_token_vc VARCHAR(64) NULL) ENGINE=InnoDB");
        $done = true;
    }

    /**
     * Check that Matomo and its SentoraSync plugin are installed.
     *
     * @return bool
     */
    static function Installed()
    {
        return is_file(self::APP_DIR . 'config/config.ini.php') && is_dir(self::APP_DIR . 'plugins/SentoraSync');
    }

    /**
     * Get the Matomo address on the panel's domain.
     *
     * @return string The URL, ending with a slash.
     */
    static function Url()
    {
        return 'https://' . ctrl_options::GetSystemOption('sentora_domain') . '/etc/apps/matomo/';
    }

    /**
     * Get the Matomo login name of a panel account.
     *
     * @param string $username The panel username.
     * @return string
     */
    static function Login($username)
    {
        return 'sentora_' . $username;
    }

    /**
     * List an account's domains with their Matomo site and source.
     *
     * Includes the panel's own domain for the panel owner.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return array<int, array<string, mixed>> One row per domain: 'domain' (string), 'idsite' (int, 0 while not set up), 'source' ('log' or 'js').
     */
    static function Domains($uid)
    {
        global $zdbh;
        self::Ensure();
        $list = array();
        $panel = ctrl_statsdomains::PanelRowFor($uid);
        if ($panel) {
            $list[] = $panel['vh_name_vc'];
        }
        $sql = $zdbh->prepare("SELECT vh_name_vc FROM x_vhosts WHERE vh_acc_fk = :uid AND vh_deleted_ts IS NULL AND vh_type_in IN (1, 2) ORDER BY vh_name_vc");
        $sql->execute(array(':uid' => (int) $uid));
        while ($row = $sql->fetch()) {
            $list[] = $row['vh_name_vc'];
        }
        $out = array();
        $q = $zdbh->prepare("SELECT ms_idsite_in, ms_source_vc FROM x_matomo_sites WHERE ms_domain_vc = :d AND ms_acc_fk = :uid AND ms_deleted_in = 0");
        foreach ($list as $domain) {
            $q->execute(array(':d' => $domain, ':uid' => (int) $uid));
            $r = $q->fetch();
            $out[] = array('domain' => $domain, 'idsite' => $r ? (int) $r['ms_idsite_in'] : 0, 'source' => $r ? $r['ms_source_vc'] : 'log');
        }
        return $out;
    }

    /**
     * Get the Matomo token of an account's view-only user.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return string|false The token, or false when there is none (or it looks wrong).
     */
    static function Token($uid)
    {
        global $zdbh;
        self::Ensure();
        $sql = $zdbh->prepare("SELECT mu_token_vc FROM x_matomo_users WHERE mu_acc_fk = :uid");
        $sql->execute(array(':uid' => (int) $uid));
        $t = $sql->fetchColumn();
        return ($t && preg_match('/^[0-9a-f]{32,64}$/', $t)) ? $t : false;
    }

    /**
     * Build Matomo's JavaScript tracking snippet for a site.
     *
     * @param int $idsite The Matomo site ID.
     * @return string The HTML snippet.
     */
    static function TrackingCode($idsite)
    {
        $u = self::Url();
        return "<!-- Matomo -->\n<script>\n  var _paq = window._paq = window._paq || [];\n  _paq.push(['trackPageView']);\n  _paq.push(['enableLinkTracking']);\n"
            . "  (function() {\n    var u=\"" . $u . "\";\n    _paq.push(['setTrackerUrl', u+'matomo.php']);\n    _paq.push(['setSiteId', '" . (int) $idsite . "']);\n"
            . "    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];\n    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);\n  })();\n</script>\n<!-- End Matomo Code -->";
    }

    /**
     * Run a Matomo console command as the panel user.
     *
     * @param string[] $args The command and its arguments (each is shell-escaped).
     * @return array{0: int, 1: string} The exit code and the output.
     */
    static function Console($args)
    {
        $cmd = 'cd ' . escapeshellarg(self::APP_DIR) . ' && setpriv --reuid=sentora --regid=sentora --init-groups --reset-env /usr/bin/php' . sys_phpversions::DefaultVersion() . ' console';
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        exec($cmd . ' 2>&1', $out, $rc);
        return array($rc, implode("\n", $out));
    }

}

?>
