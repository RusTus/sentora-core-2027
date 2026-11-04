<?php

/**
 * One Linux user and one PHP-FPM pool per hosting account.
 *
 * Every account's PHP runs as its own user (web<account id>), so one hosted
 * site can not read or change another account's files, even with shell
 * commands. The account's folder belongs to that user; access lists (ACLs)
 * give the panel (File Manager, backups) read-write and Apache (static files)
 * read-only, and nobody else anything.
 *
 * Run by the daemon (root): Sync() creates users and pools for accounts and the
 * PHP versions their domains use, removes what is no longer needed and reloads
 * the PHP-FPM services whose pools changed.
 */
class sys_accountphp
{

    const PANEL_USER = 'sentora';
    const WEB_GROUP = 'www-data';
    const PANEL_SOCKET = '/run/php/sentora-panel.sock';
    const STATE_DIR = '/var/lib/sentora/accounts/';

    static function UserName($accountId)
    {
        return 'web' . (int) $accountId;
    }

    static function DefaultVersion()
    {
        return sys_phpversions::DefaultVersion();
    }

    /** where a version's pools live and which service runs them */
    static function PoolDir($version)
    {
        return ($version === self::DefaultVersion())
            ? '/etc/php/' . $version . '/fpm/pool.d/'
            : sys_phpversions::BASE_DIR . $version . '/etc/php-fpm.d/';
    }

    static function ServiceName($version)
    {
        return ($version === self::DefaultVersion()) ? 'php' . $version . '-fpm' : sys_phpversions::Service($version);
    }

    static function Socket($accountId, $version)
    {
        return ($version === self::DefaultVersion())
            ? '/run/php/' . self::UserName($accountId) . '.sock'
            : sys_phpversions::SOCKET_DIR . self::UserName($accountId) . '.sock';
    }

    static function HomeDir($username)
    {
        return ctrl_options::GetSystemOption('hosted_dir') . $username . '/';
    }

    /**
     * Apache lines that send a domain's PHP to its account's pool, with the
     * domain's open_basedir and the account's own temp folder.
     */
    static function ApacheHandler($accountId, $username, $version, $openBasedir)
    {
        $tmp = self::HomeDir($username) . 'tmp/';
        $admin = array();
        if ($openBasedir !== '') {
            $admin[] = 'open_basedir=' . $openBasedir;
        }
        $admin[] = 'upload_tmp_dir=' . $tmp;
        $admin[] = 'session.save_path=' . $tmp;
        $admin[] = 'sys_temp_dir=' . $tmp;
        $nl = fs_filehandler::NewLine();
        $line = '# PHP ' . $version . ' (PHP-FPM, runs as ' . self::UserName($accountId) . ')' . $nl;
        $line .= '<FilesMatch "\\.(php|phtml|php3)$">' . $nl;
        $line .= '    SetHandler "proxy:unix:' . self::Socket($accountId, $version) . '|fcgi://localhost/"' . $nl;
        $line .= '</FilesMatch>' . $nl;
        $line .= 'ProxyFCGISetEnvIf "true" PHP_ADMIN_VALUE "' . implode('\n', $admin) . '"' . $nl;
        return $line;
    }

    /** the panel's own static pages (suspended, over quota, parked domains) */
    static function PanelHandler()
    {
        $nl = fs_filehandler::NewLine();
        return '<FilesMatch "\\.php$">' . $nl . '    SetHandler "proxy:unix:' . self::PANEL_SOCKET . '|fcgi://localhost/"' . $nl . '</FilesMatch>' . $nl;
    }

    static function PoolConfig($accountId, $username, $version)
    {
        $user = self::UserName($accountId);
        $home = self::HomeDir($username);
        $children = (int) ctrl_options::GetSystemOption('php_pool_children');
        if ($children < 1 || $children > 200) {
            $children = 10;
        }
        return "; Sentora account " . $username . " (written by the Sentora daemon)\n"
            . "[" . $user . "]\n"
            . "user = " . $user . "\n"
            . "group = " . $user . "\n"
            . "listen = " . self::Socket($accountId, $version) . "\n"
            . "listen.owner = " . self::WEB_GROUP . "\n"
            . "listen.group = " . self::WEB_GROUP . "\n"
            . "listen.mode = 0660\n"
            . "pm = ondemand\n"
            . "pm.max_children = " . $children . "\n"
            . "pm.process_idle_timeout = 30s\n"
            . "pm.max_requests = 500\n"
            . "security.limit_extensions = .php .phtml .php3\n"
            . "; The account's own folder only, also if Apache passes nothing\n"
            . "php_admin_value[open_basedir] = " . $home . "\n"
            . "php_admin_value[upload_tmp_dir] = " . $home . "tmp/\n"
            . "php_admin_value[session.save_path] = " . $home . "tmp/\n"
            . "php_admin_value[sys_temp_dir] = " . $home . "tmp/\n";
    }

    private static function Run($cmd)
    {
        exec($cmd . ' 2>&1', $out, $rc);
        return $rc === 0;
    }

    /** create the account's Linux user and set up its folder (once) */
    static function EnsureUser($accountId, $username)
    {
        $user = self::UserName($accountId);
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $username)) {
            return false;
        }
        if (posix_getpwnam($user) === false) {
            if (posix_getgrnam($user) === false) {
                self::Run('groupadd ' . escapeshellarg($user));
            }
            // A normal (not system) uid, which ProFTPD accepts for FTP logins
            self::Run('useradd -M -g ' . escapeshellarg($user) . ' -d ' . escapeshellarg(rtrim(self::HomeDir($username), '/'))
                . ' -s /usr/sbin/nologin -c ' . escapeshellarg('Sentora account ' . $username) . ' ' . escapeshellarg($user));
            if (posix_getpwnam($user) === false) {
                echo "Could not create user " . $user . fs_filehandler::NewLine();
                return false;
            }
            echo "Created user " . $user . " for account " . $username . fs_filehandler::NewLine();
        }
        $home = rtrim(self::HomeDir($username), '/');
        if (!is_dir($home . '/tmp')) {
            @mkdir($home . '/tmp', 0770, true);
        }
        @mkdir(self::STATE_DIR, 0700, true);
        $marker = self::STATE_DIR . (int) $accountId . '.perms';
        if (!is_file($marker)) {
            self::SetPermissions($user, $home);
            file_put_contents($marker, date('c') . "\n");
            echo "Set up the folder of " . $username . " for " . $user . fs_filehandler::NewLine();
        }
        return true;
    }

    /**
     * The account's folder: its user owns it, the panel may read and write,
     * Apache may read, nobody else; new files and folders inherit the same.
     */
    static function SetPermissions($user, $home)
    {
        $h = escapeshellarg($home);
        // The owning group is www-data too (folders keep it for new files), so both
        // the owning-group entry and the named one are read-only
        $acl = 'u:' . $user . ':rwX,u:' . self::PANEL_USER . ':rwX,g::rX,g:' . self::WEB_GROUP . ':rX,m::rwX,o::---';
        $def = 'd:u::rwX,d:u:' . $user . ':rwX,d:u:' . self::PANEL_USER . ':rwX,d:g::rX,d:g:' . self::WEB_GROUP . ':rX,d:m::rwX,d:o::---';
        self::Run('chown -R ' . escapeshellarg($user . ':' . self::WEB_GROUP) . ' ' . $h);
        self::Run('chmod -R u+rwX,g+rX,o-rwx ' . $h);
        self::Run('find ' . $h . ' -type d -exec chmod g+s {} +');
        self::Run('setfacl -R -m ' . escapeshellarg($acl) . ' ' . $h);
        self::Run('find ' . $h . ' -type d -exec setfacl -m ' . escapeshellarg($def) . ' {} +');
    }

    /** accounts with their PHP versions: id => (username, versions) */
    static function Wanted()
    {
        global $zdbh;
        $default = self::DefaultVersion();
        $enabled = sys_phpversions::Enabled();
        $wanted = array();
        $sql = $zdbh->prepare("SELECT ac_id_pk, ac_user_vc FROM x_accounts WHERE ac_deleted_ts IS NULL");
        $sql->execute();
        while ($row = $sql->fetch()) {
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $row['ac_user_vc'])) {
                $wanted[(int) $row['ac_id_pk']] = array('username' => $row['ac_user_vc'], 'versions' => array($default => true));
            }
        }
        $sql = $zdbh->prepare("SELECT vh_acc_fk, vh_php_version_vc FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_php_version_vc IS NOT NULL AND vh_php_version_vc <> ''");
        $sql->execute();
        while ($row = $sql->fetch()) {
            $v = (string) $row['vh_php_version_vc'];
            if (isset($wanted[(int) $row['vh_acc_fk']]) && in_array($v, $enabled, true)) {
                $wanted[(int) $row['vh_acc_fk']]['versions'][$v] = true;
            }
        }
        return $wanted;
    }

    static function Sync()
    {
        $wanted = self::Wanted();
        $versions = array_unique(array_merge(array(self::DefaultVersion()), sys_phpversions::Enabled()));
        $reload = array();
        foreach ($wanted as $id => $acc) {
            self::EnsureUser($id, $acc['username']);
        }
        foreach ($versions as $version) {
            $dir = self::PoolDir($version);
            if (!is_dir($dir)) {
                continue;
            }
            $keep = array();
            foreach ($wanted as $id => $acc) {
                if (!isset($acc['versions'][$version]) || posix_getpwnam(self::UserName($id)) === false) {
                    continue;
                }
                $file = $dir . self::UserName($id) . '.conf';
                $keep[$file] = true;
                $conf = self::PoolConfig($id, $acc['username'], $version);
                if (!is_file($file) || file_get_contents($file) !== $conf) {
                    file_put_contents($file, $conf);
                    $reload[$version] = true;
                }
            }
            // Pools of deleted accounts, or versions an account no longer uses
            foreach ((array) glob($dir . 'web*.conf') as $file) {
                if (preg_match('#/web[0-9]+\.conf$#', $file) && !isset($keep[$file])) {
                    @unlink($file);
                    $reload[$version] = true;
                }
            }
        }
        foreach (array_keys($reload) as $version) {
            $service = escapeshellarg(self::ServiceName($version));
            // Reload keeps running requests; restart if the service was not running
            if (!self::Run('systemctl reload ' . $service)) {
                self::Run('systemctl restart ' . $service);
            }
            echo "PHP " . $version . ": account pools updated" . fs_filehandler::NewLine();
        }
        self::SyncFtpOwners($wanted);
        self::SyncCron($wanted);
    }

    /** FTP logins write files as the account's own user */
    static function SyncFtpOwners($wanted)
    {
        global $zdbh;
        $sql = $zdbh->prepare("SELECT ft_acc_fk, ft_user_vc FROM x_ftpaccounts WHERE ft_deleted_ts IS NULL");
        $sql->execute();
        $rows = $sql->fetchAll();
        if (count($rows) == 0) {
            return;
        }
        $db = $zdbh->prepare("UPDATE sentora_proftpd.ftpuser SET uid = :uid, gid = :gid WHERE userid = :name AND (uid <> :uid2 OR gid <> :gid2)");
        foreach ($rows as $row) {
            $pw = posix_getpwnam(self::UserName($row['ft_acc_fk']));
            if ($pw === false) {
                continue;
            }
            try {
                $db->execute(array(':uid' => $pw['uid'], ':gid' => $pw['gid'], ':name' => $row['ft_user_vc'], ':uid2' => $pw['uid'], ':gid2' => $pw['gid']));
            } catch (Exception $e) {
                return;
            }
        }
    }

    /** cron jobs run as the account's own user (/etc/cron.d/sentora-accounts) */
    static function SyncCron($wanted)
    {
        global $zdbh;
        $php = '/usr/bin/php' . self::DefaultVersion();
        $lines = "# Sentora cron jobs, one user per account (written by the Sentora daemon from Cron Manager)\n"
            . "SHELL=/bin/sh\nPATH=/usr/local/bin:/usr/bin:/bin\n";
        $sql = $zdbh->prepare("SELECT ct_id_pk, ct_acc_fk, ct_timing_vc, ct_fullpath_vc FROM x_cronjobs WHERE ct_deleted_ts IS NULL");
        $sql->execute();
        while ($row = $sql->fetch()) {
            $id = (int) $row['ct_acc_fk'];
            if (!isset($wanted[$id]) || posix_getpwnam(self::UserName($id)) === false) {
                continue;
            }
            $home = self::HomeDir($wanted[$id]['username']);
            $script = (string) $row['ct_fullpath_vc'];
            // Same checks as the Cron Manager: plain timing, a .php file in public_html
            if (!preg_match('#^[0-9*/,-]{1,40}( [0-9*/,-]{1,40}){4}$#', (string) $row['ct_timing_vc'])
                || strpos($script, $home . 'public_html/') !== 0 || strpos($script, '..') !== false
                || !preg_match('#^[A-Za-z0-9_./-]+\.php$#', $script)) {
                continue;
            }
            $lines .= "# CRON ID: " . (int) $row['ct_id_pk'] . "\n" . $row['ct_timing_vc'] . " " . self::UserName($id) . " " . $php
                . " -d open_basedir=" . $home . " -d sys_temp_dir=" . $home . "tmp/ " . $script . " > /dev/null 2>&1\n";
        }
        $file = '/etc/cron.d/sentora-accounts';
        if (!is_file($file) || file_get_contents($file) !== $lines) {
            file_put_contents($file . '.new', $lines);
            chmod($file . '.new', 0644);
            rename($file . '.new', $file);
            echo "Cron jobs updated." . fs_filehandler::NewLine();
        }
    }

}

?>
