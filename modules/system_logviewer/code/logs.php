<?php

/**
 * System Log Viewer: which log files exist, and reading them.
 *
 * The list is built from fixed folders on every request; nothing is linked or
 * copied into the web root, and a file can only be opened by the id of an
 * entry in this list (never by a path from the browser).
 *
 * Written by RusTus, 2026-11-09
 */
class system_logviewer_logs
{

    /** @var array<string, string[]> Group label => glob patterns (a file is listed under the first group that matches it). */
    static $sources = array(
        // A file is listed under the first group that matches it
        // errors, warnings and bans from the logs the panel can not read itself (the daemon gathers
        // them: code/problems.php), and what the panel asked root to do (zsudo's queue)
        'Problems (mail, scanners, bans)' => array('/var/sentora/logs/problems.log', '/var/sentora/logs/problems.log.1'),
        'PHP-FPM (extra PHP versions)' => array('/var/sentora/logs/php*-fpm.log'),
        'Sentora panel' => array('/var/sentora/logs/*.log'),
        // the panel's own records: who logged in as whom (Shadowing), two-factor resets and recovery
        // codes used, and passwords shown (MySQL Users, Mailboxes ...)
        'Panel security' => array('/var/lib/sentora/shadowing.log', '/var/lib/sentora/twofactor.log', '/var/lib/sentora/password-reveal.log', '/var/lib/sentora/panel-update.log'),
        'Hosted domains' => array('/var/sentora/logs/domains/*/*.log'),
        'FTP (ProFTPD)' => array('/var/sentora/logs/proftpd/*.log'),
        'DNS (BIND)' => array('/var/sentora/logs/bind/*.log'),
        // ({apache_log_dir}: Apache's own log folder on this system, sys_platform)
        'Apache' => array('{apache_log_dir}/*log'),
        'Mail' => array('/var/log/dovecot*.log', '/var/log/mail.log', '/var/log/mail.err', '/var/log/maillog'),
        'MySQL' => array('/var/log/mysql/*.log', '/var/log/mariadb/*.log'),
        'System' => array('/var/log/syslog', '/var/log/auth.log', '/var/log/kern.log', '/var/log/messages', '/var/log/secure'),
    );

    /**
     * Return every log file found, keyed by id.
     *
     * Only real files (no symlinks); the id is the first 16 hex characters of sha1(path).
     * @return array<string, array{id: string, group: string, path: string, readable: bool, size: int, mtime: int}>
     */
    static function All()
    {
        $list = array();
        foreach (self::$sources as $group => $patterns) {
            foreach ($patterns as $pattern) {
                $pattern = str_replace('{apache_log_dir}', rtrim(sys_platform::Get('apache_log_dir'), '/'), $pattern);
                foreach ((array) glob($pattern) as $path) {
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
