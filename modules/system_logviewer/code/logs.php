<?php

/**
 * System Log Viewer: which log files exist, and reading them.
 *
 * The list is built from fixed folders on every request; nothing is linked or
 * copied into the web root, and a file can only be opened by the id of an
 * entry in this list (never by a path from the browser).
 *
 * Written by RusTus, 2026-11-12
 */
class system_logviewer_logs
{

    /** @var array<string, string[]> Group label => glob patterns (a file is listed under the first group that matches it). */
    static $sources = array(
        // A file is listed under the first group that matches it
        // errors, warnings and bans from the logs the panel can not read itself (the daemon gathers
        // them: code/problems.php), and what the panel asked root to do (zsudo's queue)
        'Problems (mail, scanners, bans)' => array('{log_dir}/problems.log', '{log_dir}/problems.log.1'),
        'PHP-FPM (extra PHP versions)' => array('{log_dir}/php*-fpm.log'),
        'Sentora panel' => array('{log_dir}/*.log'),
        // the panel's own records: who logged in as whom (Shadowing), two-factor resets and recovery
        // codes used, and passwords shown (MySQL Users, Mailboxes ...)
        'Panel security' => array('/var/lib/sentora/shadowing.log', '/var/lib/sentora/twofactor.log', '/var/lib/sentora/password-reveal.log', '/var/lib/sentora/panel-update.log'),
        'Hosted domains' => array('{log_dir}/domains/*/*.log'),
        'FTP (ProFTPD)' => array('{log_dir}/proftpd/*.log'),
        'DNS (BIND)' => array('{log_dir}/bind/*.log'),
        // ({apache_log_dir}: Apache's own log folder on this system, sys_platform)
        'Apache' => array('{apache_log_dir}/*log'),
        'Mail' => array('/var/log/dovecot*.log', '/var/log/mail.log', '/var/log/mail.err', '/var/log/maillog'),
        'MySQL' => array('/var/log/mysql/*.log', '/var/log/mariadb/*.log'),
        'System' => array('/var/log/syslog', '/var/log/auth.log', '/var/log/kern.log', '/var/log/messages', '/var/log/secure'),
    );

    /** @var array<string, string[]> The same on Windows (the Windows installer's folders under log_dir). */
    static $windows = array(
        'Sentora panel' => array('{log_dir}/panel/*.log'),
        'Panel security' => array('/var/lib/sentora/shadowing.log', '/var/lib/sentora/twofactor.log', '/var/lib/sentora/password-reveal.log', '/var/lib/sentora/panel-update.log'),
        'Hosted domains' => array('{log_dir}/domains/*/*.log'),
        'Apache' => array('{log_dir}/apache/*.log'),
        'PHP' => array('{log_dir}/php/*.log'),
        'FTP (SFTPGo)' => array('{log_dir}/sftpgo/*.log'),
        'Mail (Stalwart, ClamAV, webmail)' => array('{log_dir}/stalwart/*', '{log_dir}/clamav/*.log', '{log_dir}/roundcube/*', '{log_dir}/z-push/*.log'),
        'DNS (CoreDNS)' => array('{log_dir}/coredns/*.log'),
        'MySQL' => array('{log_dir}/mysql/*.log'),
    );

    /**
     * The files a pattern matches, folder by folder with scandir and fnmatch (Windows: glob() there finds nothing with
     * these absolute paths). Links are never followed into.
     *
     * @param string $pattern An absolute pattern with / between its parts; * and ? only.
     * @return string[]
     */
    static function Expand($pattern)
    {
        $parts = explode('/', $pattern);
        $paths = array(array_shift($parts));   // ('C:' or '' for a path from the drive's root)
        foreach ($parts as $n => $part) {
            $next = array();
            foreach ($paths as $base) {
                if (strpbrk($part, '*?') === false) {
                    $next[] = $base . '/' . $part;
                    continue;
                }
                $dir = $base === '' ? '/' : $base . '/';
                if (!is_dir($dir) || is_link(rtrim($dir, '/')) || !is_readable($dir)) {
                    continue;
                }
                foreach ((array) @scandir($dir) as $name) {
                    if ($name !== '.' && $name !== '..' && fnmatch($part, $name)) {
                        $next[] = $base . '/' . $name;
                    }
                }
            }
            $paths = $next;
        }
        return $paths;
    }

    /**
     * Return every log file found, keyed by id.
     *
     * Only real files (no symlinks); the id is the first 16 hex characters of sha1(path).
     * @return array<string, array{id: string, group: string, path: string, readable: bool, size: int, mtime: int}>
     */
    static function All()
    {
        $list = array();
        // (the panel's log folder from the settings: /var/sentora/logs on Linux)
        $logDir = rtrim(str_replace('\\', '/', (string) ctrl_options::GetSystemOption('log_dir')), '/');
        $logDir = $logDir !== '' ? $logDir : '/var/sentora/logs';
        foreach (sys_versions::IsWindows() ? self::$windows : self::$sources as $group => $patterns) {
            foreach ($patterns as $pattern) {
                $pattern = str_replace(array('{log_dir}', '{apache_log_dir}'), array($logDir, rtrim(sys_platform::Get('apache_log_dir'), '/')), $pattern);
                // A folder the panel may not list (SELinux keeps BIND's and the FTP server's logs from
                // the web server) is not tried: access() is asked first, which SELinux does not log
                $dir = dirname($pattern);
                if (strpbrk(basename($pattern), '*?[') !== false && strpbrk($dir, '*?[') === false && !is_readable($dir)) {
                    continue;
                }
                foreach (sys_versions::IsWindows() ? self::Expand($pattern) : (array) glob($pattern) as $path) {
                    // Only real files; a symlink can not point the viewer somewhere else
                    if (!is_file($path) || is_link($path)) {
                        continue;
                    }
                    $id = substr(sha1($path), 0, 16);
                    if (isset($list[$id])) {
                        continue;
                    }
                    $list[$id] = array(
                        'id' => $id,
                        'group' => $group,
                        'path' => $path,
                        'readable' => is_readable($path),
                        'size' => (int) @filesize($path),
                        'mtime' => (int) @filemtime($path),
                    );
                }
            }
        }
        return $list;
    }

    /**
     * Return one log by its id.
     *
     * @param mixed $id The id from the request (only a string can match).
     * @return array{id: string, group: string, path: string, readable: bool, size: int, mtime: int}|false The log, or false when there is no such id.
     */
    static function Get($id)
    {
        $all = self::All();
        return (is_string($id) && isset($all[$id])) ? $all[$id] : false;
    }

    /**
     * Format a size in bytes as bytes, KB, MB or GB.
     *
     * @param int|float $bytes The size.
     * @return string For example "12.3 MB".
     */
    static function HumanSize($bytes)
    {
        $units = array('bytes', 'KB', 'MB', 'GB');
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < 3) {
            $n /= 1024;
            $i++;
        }
        return ($i ? number_format($n, 1) : (int) $n) . ' ' . $units[$i];
    }

    /**
     * Return the logged-in panel user, if an administrator (for read.php / view.php).
     *
     * Starts (and closes) the session; anyone else gets a 403 and the request ends (exit).
     * @return array<string, string> The user (ctrl_users::GetUserDetail()).
     */
    static function RequireAdmin()
    {
        session_start();
        ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
        $uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
        $user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
        session_write_close();
        if ($user) {
            self::UseLanguageOf($user['userid']);
        }
        if (!$user || (int) $user['usergroupid'] !== 1) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit(ui_language::translate('The System Log Viewer is only available to panel administrators.'));
        }
        return $user;
    }

    /**
     * Make ui_language::translate() use an account's language in read.php and view.php.
     *
     * Those run without the panel's request controller, where translate() would always
     * give English; the language is checked as translate() checks it (English otherwise).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return void
     */
    static function UseLanguageOf($uid)
    {
        ui_language::ForAccount($uid);
    }

}

?>
