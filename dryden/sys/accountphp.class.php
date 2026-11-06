<?php

/**
 * Core class: per-account Linux users, PHP-FPM pools and cron jobs.
 *
 * Run by the daemon to keep hosting accounts isolated from each other.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

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

    /**
     * Return the Linux user name of an account (web<account id>).
     *
     * @param int|string $accountId The account ID (cast to int).
     * @return string The user name, e.g. 'web12'.
     */
    static function UserName($accountId)
    {
        return 'web' . (int) $accountId;
    }

    /**
     * Return the system PHP version (the version of the panel's own PHP).
     *
     * @return string Version as 'major.minor', e.g. '8.5'.
     */
    static function DefaultVersion()
    {
        return sys_phpversions::DefaultVersion();
    }

    /**
     * Return the folder that holds a PHP version's pool files.
     *
     * The system version uses /etc/php/<version>/fpm/pool.d/, extra versions their
     * own folder under sys_phpversions::BASE_DIR.
     *
     * @param string $version PHP version, e.g. '8.3'.
     * @return string Folder path with a trailing slash.
     */
    static function PoolDir($version)
    {
        return ($version === self::DefaultVersion())
            ? '/etc/php/' . $version . '/fpm/pool.d/'
            : sys_phpversions::BASE_DIR . $version . '/etc/php-fpm.d/';
    }

    /**
     * Return the systemd service that runs a PHP version's pools.
     *
     * @param string $version PHP version, e.g. '8.3'.
     * @return string Service name, e.g. 'php8.5-fpm' or 'sentora-php8.3-fpm'.
     */
    static function ServiceName($version)
    {
        return ($version === self::DefaultVersion()) ? 'php' . $version . '-fpm' : sys_phpversions::Service($version);
    }

    /**
     * Return the path of an account's PHP-FPM socket for a PHP version.
     *
     * @param int|string $accountId The account ID.
     * @param string $version PHP version, e.g. '8.3'.
     * @return string Full socket path.
     */
    static function Socket($accountId, $version)
    {
        return ($version === self::DefaultVersion())
            ? '/run/php/' . self::UserName($accountId) . '.sock'
            : sys_phpversions::SOCKET_DIR . self::UserName($accountId) . '.sock';
    }

    /**
     * Return an account's hosting folder.
     *
     * @param string $username The account's panel user name.
     * @return string Folder path (hosted_dir setting + user name) with a trailing slash.
     */
    static function HomeDir($username)
    {
        return ctrl_options::GetSystemOption('hosted_dir') . $username . '/';
    }

    /**
     * Build the Apache lines that send a domain's PHP to its account's pool.
     *
     * Includes the domain's open_basedir, the account's own temp folder and the
     * ApacheGuard() block.
     *
     * @param int|string $accountId The account ID.
     * @param string $username The account's panel user name.
     * @param string $version PHP version the domain runs, e.g. '8.3'.
     * @param string $openBasedir The open_basedir value, or '' for none.
     * @return string Apache configuration lines, each ending in a new line.
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
        $line .= self::ApacheGuard($accountId);
        return $line;
    }

    /**
     * Build the <Location> block that keeps a site's PHP in its own account's pool.
     *
     * A site's .htaccess can choose a request's handler late (RewriteRule [H=...]),
     * after every check Apache can make before the handler runs. These lines act when
     * the request is actually handed over: <Location> is merged after .htaccess, so a
     * site can not undo them.
     *  - PHP-FPM: a request for any socket but this account's own gets no script and
     *    none of the PHP settings (PHP-FPM keeps settings sent with a request in that
     *    worker, so they must never reach the panel's or another account's pool)
     *  - server-status / server-info: blanked by sentora-guard.lua's output filter
     *
     * @param int|string $accountId The account ID (cast to int).
     * @return string Apache configuration lines, each ending in a new line.
     */
    static function ApacheGuard($accountId)
    {
        $nl = fs_filehandler::NewLine();
        $notOwn = '"%{HANDLER} !~ m#^proxy:unix:/run/(php|sentora-php)/web' . (int) $accountId . '\\.sock\\|#"';
        return '<Location "/">' . $nl
            . '    # only this account\'s own PHP pools (see sys_accountphp::ApacheGuard)' . $nl
            . '    ProxyFCGISetEnvIf ' . $notOwn . ' !PHP_ADMIN_VALUE' . $nl
            . '    ProxyFCGISetEnvIf ' . $notOwn . ' !PHP_VALUE' . $nl
            . '    ProxyFCGISetEnvIf ' . $notOwn . ' SCRIPT_FILENAME "/sentora-refused-not-this-accounts-php"' . $nl
            . '    ProxyFCGISetEnvIf ' . $notOwn . ' PATH_TRANSLATED "/sentora-refused-not-this-accounts-php"' . $nl
            . '    <IfModule lua_module>' . $nl
            . '        SetOutputFilter SENTORA_STATUS_BLOCK' . $nl
            . '    </IfModule>' . $nl
            . '</Location>' . $nl;
    }

    /**
     * Build the Apache lines for the panel's own static pages (suspended, over quota, parked domains).
     *
     * @return string Apache <FilesMatch> block sending .php to the panel's own socket.
     */
    static function PanelHandler()
    {
        $nl = fs_filehandler::NewLine();
        return '<FilesMatch "\\.php$">' . $nl . '    SetHandler "proxy:unix:' . self::PANEL_SOCKET . '|fcgi://localhost/"' . $nl . '</FilesMatch>' . $nl;
    }

    /**
     * Build the PHP-FPM pool file for an account and a PHP version.
     *
     * @param int|string $accountId The account ID.
     * @param string $username The account's panel user name.
     * @param string $version PHP version, e.g. '8.3'.
     * @return string Contents of the pool .conf file.
     */
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

    /**
     * Run a shell command (stderr merged into the output, which is discarded).
     *
     * @param string $cmd The command line, already escaped.
     * @return bool True when the command exited with code 0.
     */
    private static function Run($cmd)
    {
        exec($cmd . ' 2>&1', $out, $rc);
        return $rc === 0;
    }

    /**
     * Create the account's Linux user and set up its folder (once).
     *
     * Makes the user and group when missing, creates the account folders and sets
     * owner and access lists the first time (a marker file in STATE_DIR records it).
     * Prints progress for the daemon log.
     *
     * @param int|string $accountId The account ID.
     * @param string $username The account's panel user name.
     * @return bool False when the user name is invalid or the user could not be created, else true.
     */
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
        // The account's folders: made here by root (the panel can not write to the
        // hosting folder's parent), new accounts get them within 5 minutes
        $home = rtrim(self::HomeDir($username), '/');
        $made = false;
        foreach (array('', '/public_html', '/backups', '/tmp') as $sub) {
            if (!is_dir($home . $sub)) {
                @mkdir($home . $sub, 0770, true);
                $made = true;
            }
        }
        if ($made) {
            // New folders get the same owner and access lists as the rest
            @unlink(self::STATE_DIR . (int) $accountId . '.perms');
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
     * Give the account's folder its owner and access lists.
     *
     * Its user owns it, the panel may read and write, Apache may read, nobody else;
     * new files and folders inherit the same.
     *
     * @param string $user The account's Linux user (web<id>).
     * @param string $home The account's folder, without a trailing slash.
     * @return void
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

    /**
     * Give everything in the account's folder to the account's own user.
     *
     * Covers what the panel (File Manager, new domain folders), root (daemon, backups)
     * or the old shared web server user made. The access lists keep the panel and
     * Apache working either way; owning its files lets the site's PHP chmod them, and
     * FTP shows the right owner.
     *
     * @param string $user The account's Linux user (web<id>).
     * @param string $home The account's folder, without a trailing slash.
     * @param bool $quick Optional. Only the folder and its first two levels. Default false.
     * @return int Number of files and folders whose owner was changed.
     */
    static function FixOwnership($user, $home, $quick = false)
    {
        $h = escapeshellarg($home);
        $who = '\\( -user ' . self::PANEL_USER . ' -o -user root -o -user ' . self::WEB_GROUP . ' \\)';
        // quick: the account folder and its first two levels (new domain folders)
        $depth = $quick ? ' -maxdepth 2' : '';
        // -print: one line per file that had another owner, so the change can be counted
        exec('find ' . $h . $depth . ' ' . $who . ' -print -exec chown -h ' . escapeshellarg($user) . ' {} + 2>/dev/null', $out);
        return count($out);
    }

    /**
     * Hourly safeguard for an account's folder.
     *
     * Whatever a site, an app or the File Manager changed, the account's own folder
     * and its public_html stay closed to everyone else (they are the doors between
     * accounts), with the access lists for the account, the panel and Apache in place;
     * and the access-list ceiling ("mask") a chmod lowers is put back, so the panel's
     * File Manager can edit files an app set to 644. The files' own permissions are
     * not changed.
     *
     * @param string $user The account's Linux user (web<id>).
     * @param string $home The account's folder, without a trailing slash.
     * @return void
     */
    static function Safeguard($user, $home)
    {
        $acl = 'u:' . $user . ':rwX,u:' . self::PANEL_USER . ':rwX,g::rX,g:' . self::WEB_GROUP . ':rX,m::rwX,o::---';
        $def = 'd:u::rwX,d:u:' . $user . ':rwX,d:u:' . self::PANEL_USER . ':rwX,d:g::rX,d:g:' . self::WEB_GROUP . ':rX,d:m::rwX,d:o::---';
        foreach (array($home, $home . '/public_html') as $dir) {
            if (is_dir($dir) && !is_link($dir)) {
                $d = escapeshellarg($dir);
                self::Run('chmod u+rwx,g+rx,g-w,g+s,o-rwx ' . $d);
                self::Run('setfacl -m ' . escapeshellarg($acl . ',' . $def) . ' ' . $d);
            }
        }
        self::Run('setfacl -R -m m::rwX ' . escapeshellarg($home));
    }

    /**
     * Lock the shared folders under /var/sentora against the hosted sites.
     *
     * The shared folders under /var/sentora belong to root and can not be changed by
     * the hosted sites (the installer once made the whole tree world-writable): a
     * site could otherwise swap its log file for a link to a root-owned file, which
     * Apache (opening logs as root) would then write to, or rename folders the root
     * jobs work in. Domain logs are readable by root and the panel only. Every run.
     * Also runs LockWorldWritable() and ReplacePublicApiKey().
     *
     * @return void
     */
    static function LockSharedFolders()
    {
        $base = '/var/sentora';
        if (!is_dir($base) || is_link($base)) {
            return;
        }
        $fix = function ($path, $mode, $group = 'root') {
            if (is_dir($path) && !is_link($path)) {
                @chown($path, 'root');
                @chgrp($path, $group);
                @chmod($path, $mode);
            }
        };
        $fix($base, 0755);
        $fix($base . '/logs', 0755);
        $fix($base . '/stats', 0755);
        $fix($base . '/backups', 0700);
        // Shared scratch space: anyone may add, nobody may remove or rename others' files
        $fix($base . '/temp', 01777);
        $fix($base . '/sessions', 01733);
        $fix($base . '/logs/domains', 0750, self::PANEL_USER);
        // The panel's working folders: the panel and Apache (group) only, not the sites
        // (the panel's PHP makes files world-readable; a closed folder hides them)
        $panel = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/');
        foreach (array('/etc/tmp', '/etc/apps/matomo/tmp', '/etc/apps/matomo/config') as $sub) {
            $dir = $panel . $sub;
            if ($panel !== '' && is_dir($dir) && !is_link($dir)) {
                $mode = fileperms($dir) & 07777;
                if ($mode & 0007) {
                    @chmod($dir, ($mode & 07770) | 02000);
                }
            }
        }
        foreach ((array) glob($base . '/logs/domains/*', GLOB_ONLYDIR) as $dir) {
            $fix($dir, 0750, self::PANEL_USER);
        }
        self::LockWorldWritable();
        self::ReplacePublicApiKey();
    }

    /**
     * Replace the public default (or a short) web service API key with a random one.
     *
     * Writes the new key to x_settings and prints a note for the daemon log.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function ReplacePublicApiKey()
    {
        global $zdbh;
        $key = (string) ctrl_options::GetSystemOption('apikey');
        if (strlen($key) >= 32 && !in_array(strtolower($key), ws_xmws::PublicKeys(), true)) {
            return;
        }
        $new = bin2hex(random_bytes(16));
        $zdbh->prepare("UPDATE x_settings SET so_value_tx = :k WHERE so_name_vc = 'apikey'")->execute(array(':k' => $new));
        echo "Security: the web service API key was the public default (or too short): replaced with a random one"
            . " (Admin > Sentora Config shows it, for programs that use the API)." . fs_filehandler::NewLine();
    }

    /**
     * Remove the world-writable bit from everything in /etc/sentora and /var/sentora.
     *
     * Nothing in /etc/sentora or /var/sentora may be writable by everyone: every hosted
     * site runs as its own Linux user, and Apache and BIND read the configuration as
     * root (a site that could change Apache's site settings or the folder holding them
     * could make Apache run its code as root). Older installers and daemons left
     * /etc/sentora, its configuration, the DNS zones and Apache's site settings that
     * way. Left out: the hosting folders and the mail store (their own rules), and the
     * two shared scratch folders, which are meant to be (sticky: nobody can remove or
     * rename another user's files there). Also fixes the owner and mode of the panel log.
     *
     * @return void
     */
    static function LockWorldWritable()
    {
        $roots = array_filter(array('/etc/sentora', '/var/sentora'), function ($d) {
            return is_dir($d) && !is_link($d);
        });
        if (!$roots) {
            return;
        }
        $prune = array('/var/sentora/hostdata', '/var/sentora/vmail', '/var/sentora/temp', '/var/sentora/sessions');
        $cmd = 'find ' . implode(' ', array_map('escapeshellarg', $roots)) . ' -xdev \\( '
            . implode(' -o ', array_map(function ($p) { return '-path ' . escapeshellarg($p); }, $prune))
            . ' \\) -prune -o -perm -o+w ! -type l -print0 2> /dev/null | xargs -0 -r chmod o-w 2> /dev/null; echo done';
        $before = (int) trim((string) shell_exec('find ' . implode(' ', array_map('escapeshellarg', $roots)) . ' -xdev \\( '
            . implode(' -o ', array_map(function ($p) { return '-path ' . escapeshellarg($p); }, $prune))
            . ' \\) -prune -o -perm -o+w ! -type l -print 2> /dev/null | wc -l'));
        if ($before > 0) {
            shell_exec($cmd);
            echo "Permissions: " . $before . " file(s) or folder(s) in /etc/sentora and /var/sentora were writable by everyone: closed." . fs_filehandler::NewLine();
        }
        // the panel's log: written by the daemon (root) and the panel (its own user)
        $log = (string) ctrl_options::GetSystemOption('logfile');
        if ($log !== '' && strpos($log, '/var/sentora/logs/') === 0 && is_file($log) && !is_link($log)) {
            @chown($log, 'root');
            @chgrp($log, self::PANEL_USER);
            @chmod($log, 0660);
        }
    }

    /**
     * List the live accounts with the PHP versions their domains use.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int,array{username:string,versions:array<string,bool>}> Account ID => user name and the set of versions (version => true), always including the default.
     */
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

    /**
     * Create and update account users, PHP-FPM pools, FTP owners and cron jobs.
     *
     * Makes missing domain folders, ensures every account's Linux user, writes the
     * pool files of every PHP version, removes pools no longer needed, protects the
     * panel's own pool and reloads the PHP-FPM services whose pools changed. Then runs
     * SyncFtpOwners(), SyncCron() and LockSharedFolders(). Run by the daemon (root).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Sync()
    {
        $wanted = self::Wanted();
        $versions = array_unique(array_merge(array(self::DefaultVersion()), sys_phpversions::Enabled()));
        $reload = array();
        // Domain folders the panel could not make yet (an account set up in the last
        // few minutes): made here, then given the account's owner and access lists
        global $zdbh;
        $dirs = $zdbh->prepare("SELECT vh_acc_fk, vh_directory_vc FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_type_in IN (1, 2)");
        $dirs->execute();
        while ($row = $dirs->fetch()) {
            $id = (int) $row['vh_acc_fk'];
            $sub = (string) $row['vh_directory_vc'];
            if (!isset($wanted[$id]) || $sub === '' || strpos($sub, '..') !== false || !preg_match('#^/?[A-Za-z0-9_./-]+$#', $sub)) {
                continue;
            }
            $path = rtrim(self::HomeDir($wanted[$id]['username']), '/') . '/public_html/' . ltrim($sub, '/');
            if (!is_dir($path)) {
                @mkdir($path, 0770, true);
                @unlink(self::STATE_DIR . $id . '.perms');
            }
        }
        foreach ($wanted as $id => $acc) {
            if (self::EnsureUser($id, $acc['username'])) {
                // New domain folders the panel made get the account's owner straight away
                $fixed = self::FixOwnership(self::UserName($id), rtrim(self::HomeDir($acc['username']), '/'), true);
                if ($fixed > 0) {
                    echo $acc['username'] . ": " . $fixed . " new folder(s) or file(s) given to " . self::UserName($id) . fs_filehandler::NewLine();
                }
            }
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
        // The panel's own pool runs only the panel's own scripts (inc/fpm_guard.php): a
        // hosted site's .htaccess can point at that pool's socket otherwise
        $guard = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/inc/fpm_guard.php';
        $panelPool = '/etc/php/' . self::DefaultVersion() . '/fpm/pool.d/sentora.conf';
        if (is_file($guard) && is_file($panelPool) && !is_link($panelPool)) {
            $conf = (string) file_get_contents($panelPool);
            if (strpos($conf, 'auto_prepend_file') === false) {
                file_put_contents($panelPool, rtrim($conf) . "\n; Only the panel's own scripts may run in this pool (a hosted site's .htaccess could point at it)\nphp_admin_value[auto_prepend_file] = " . $guard . "\n");
                $reload[self::DefaultVersion()] = true;
                echo "Panel PHP pool: only the panel's own scripts may run in it now" . fs_filehandler::NewLine();
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
        self::LockSharedFolders();
    }

    /**
     * Make FTP logins write files as the account's own user.
     *
     * Sets uid/gid of each FTP account in sentora_proftpd.ftpuser to the account's
     * Linux user. Stops quietly when that table can not be updated.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array<int,array{username:string,versions:array<string,bool>}> $wanted Accounts from Wanted() (not used).
     * @return void
     */
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

    /**
     * Return the Sentora Config settings for scheduled tasks.
     *
     * Creates the settings with their defaults when missing; a value that is not one
     * of the allowed choices reads as the default.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<string, int> 'cron_min_interval' and 'cron_max_runtime', both in minutes.
     */
    static function CronSettings()
    {
        global $zdbh;
        $defs = array(
            array('cron_min_interval', 'Shortest cron interval (minutes)', '1', '1|5|15', 'How often a scheduled task (Cron Manager) may run at most. Tasks set to run more often run at this interval.'),
            array('cron_max_runtime', 'Longest cron run (minutes)', '30', '10|30|60|120', 'A scheduled task still running after this is stopped.'),
        );
        $out = array();
        foreach ($defs as $d) {
            $v = ctrl_options::GetSystemOption($d[0]);
            if ($v === false || $v === null || $v === '') {
                $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
                    SELECT :n, :c, :v, :dv, :t, 'Sentora Config', 'true' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM x_settings WHERE so_name_vc = :n2)")
                    ->execute(array(':n' => $d[0], ':c' => $d[1], ':v' => $d[2], ':dv' => $d[3], ':t' => $d[4], ':n2' => $d[0]));
                $v = $d[2];
            }
            $allowed = explode('|', $d[3]);
            $out[$d[0]] = in_array((string) $v, $allowed, true) ? (int) $v : (int) $d[2];
        }
        return $out;
    }

    /**
     * Return how many minutes apart a cron timing runs (for the shortest interval).
     *
     * @param string $timing Five-field cron timing, e.g. '0 3 * * *'.
     * @return int Minutes between runs: 1440 for daily and longer.
     */
    static function CronEvery($timing)
    {
        $f = explode(' ', (string) $timing);
        if ($f[0] === '*') {
            return 1;
        }
        if (preg_match('#^\*/(\d+)$#', $f[0], $m)) {
            return max(1, (int) $m[1]);
        }
        if (strpos($f[0], ',') !== false) {
            $mins = array_map('intval', explode(',', $f[0]));
            sort($mins);
            $gap = 60;
            for ($i = 1; $i < count($mins); $i++) {
                $gap = min($gap, $mins[$i] - $mins[$i - 1]);
            }
            return max(1, $gap);
        }
        return isset($f[1]) && $f[1] === '*' ? 60 : 1440;
    }

    /**
     * Check that a cron script still is a real file in the account's public_html.
     *
     * No link anywhere in its path.
     *
     * @param string $home The account's folder, with a trailing slash.
     * @param string $script Full path of the script.
     * @return bool True when the script may run.
     */
    static function CronScriptOk($home, $script)
    {
        clearstatcache(true, $script);
        return strpos($script, $home . 'public_html/') === 0 && is_file($script) && !is_link($script) && realpath($script) === $script;
    }

    /**
     * Write the cron jobs of all accounts to /etc/cron.d/sentora-accounts.
     *
     * Cron jobs run as the account's own user, with the PHP version of the domain the
     * script is in, a time limit and low priority. Jobs of disabled accounts and jobs
     * whose script is missing, a link or outside public_html are left out. The file is
     * only rewritten when it changes.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array<int,array{username:string,versions:array<string,bool>}> $wanted Accounts from Wanted().
     * @return void
     */
    static function SyncCron($wanted)
    {
        global $zdbh;
        $php = '/usr/bin/php' . self::DefaultVersion();
        $limits = self::CronSettings();
        // a job runs with the PHP version of the domain its script is in (sites set to an
        // extra version in PHP Versions), else the system PHP
        $domains = array();
        foreach ($zdbh->query("SELECT vh_acc_fk, vh_directory_vc, vh_php_version_vc FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_type_in IN (1, 2)")->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $domains[(int) $d['vh_acc_fk']][] = $d;
        }
        $phpFor = function ($id, $home, $script) use ($domains, $php) {
            $script = preg_replace('#/+#', '/', $script);
            $best = '';
            $version = '';
            foreach (isset($domains[$id]) ? $domains[$id] : array() as $d) {
                $dir = $home . 'public_html/' . trim((string) $d['vh_directory_vc'], '/') . '/';
                if (strpos($script, $dir) === 0 && strlen($dir) > strlen($best)) {
                    $best = $dir;
                    $version = (string) $d['vh_php_version_vc'];
                }
            }
            if ($version !== '' && $version !== self::DefaultVersion() && preg_match('/^\d+\.\d+$/', $version)
                && sys_phpversions::IsEnabled($version) && is_executable(sys_phpversions::BASE_DIR . $version . '/bin/php')) {
                return sys_phpversions::BASE_DIR . $version . '/bin/php';
            }
            return $php;
        };
        $lines = "# Sentora cron jobs, one user per account (written by the Sentora daemon from Cron Manager)\n"
            . "# Each run: never two at once (flock), stopped after " . $limits['cron_max_runtime'] . " minutes, low priority, its output\n"
            . "# (at most 1 MB) kept in the account's tmp/cron-<id>.log for the Cron Manager.\n"
            . "SHELL=/bin/sh\nPATH=/usr/local/bin:/usr/bin:/bin\nMAILTO=\"\"\n";
        // jobs of enabled accounts only (a disabled client's tasks stop until it is enabled again)
        $sql = $zdbh->prepare("SELECT c.ct_id_pk, c.ct_acc_fk, c.ct_timing_vc, c.ct_fullpath_vc FROM x_cronjobs c
            JOIN x_accounts a ON a.ac_id_pk = c.ct_acc_fk WHERE c.ct_deleted_ts IS NULL AND a.ac_enabled_in = 1 AND a.ac_deleted_ts IS NULL");
        $sql->execute();
        $skipped = 0;
        while ($row = $sql->fetch()) {
            $id = (int) $row['ct_acc_fk'];
            $job = (int) $row['ct_id_pk'];
            if (!isset($wanted[$id]) || posix_getpwnam(self::UserName($id)) === false) {
                continue;
            }
            $home = self::HomeDir($wanted[$id]['username']);
            $script = (string) $row['ct_fullpath_vc'];
            $timing = (string) $row['ct_timing_vc'];
            // Same checks as the Cron Manager: plain timing, a .php file in public_html,
            // and still a real file now (it may have been replaced by a link since)
            if (!preg_match('#^[0-9*/,-]{1,40}( [0-9*/,-]{1,40}){4}$#', $timing)
                || strpos($script, $home . 'public_html/') !== 0 || strpos($script, '..') !== false
                || !preg_match('#^[A-Za-z0-9_./-]+\.php$#', $script) || !self::CronScriptOk($home, $script)) {
                $skipped++;
                continue;
            }
            // no more often than the server allows
            if (self::CronEvery($timing) < $limits['cron_min_interval']) {
                $timing = '*/' . $limits['cron_min_interval'] . ' * * * *';
            }
            $tmp = $home . 'tmp/';
            $run = 'timeout -k 30 ' . ($limits['cron_max_runtime'] * 60) . ' nice -n 10 ionice -c 3 ' . $phpFor($id, $home, $script)
                . ' -d open_basedir=' . $home . ' -d sys_temp_dir=' . $tmp . ' ' . $script;
            // no % on the line (cron makes it a line break): date -Iseconds
            $lines .= "# CRON ID: " . $job . "\n" . $timing . " " . self::UserName($id) . " flock -n " . $tmp . "cron-" . $job . ".lock sh -c '{ echo \"Started $(date -Iseconds)\"; "
                . $run . "; r=\$?; echo; echo \"Finished \$(date -Iseconds), exit code \$r\"; } 2>&1 | head -c 1048576 > " . $tmp . "cron-" . $job . ".log'\n";
        }
        if ($skipped) {
            echo $skipped . " cron job(s) left out: the script is missing, a link, or outside public_html." . fs_filehandler::NewLine();
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
