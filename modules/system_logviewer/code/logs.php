<?php

/**
 * System Log Viewer: which log files exist, and reading them.
 *
 * The list is built from fixed folders on every request; nothing is linked or
 * copied into the web root, and a file can only be opened by the id of an
 * entry in this list (never by a path from the browser).
 */
class system_logviewer_logs
{

    /** label => glob patterns */
    static $sources = array(
        // A file is listed under the first group that matches it
        'PHP-FPM (extra PHP versions)' => array('/var/sentora/logs/php*-fpm.log'),
        'Sentora panel' => array('/var/sentora/logs/*.log'),
        'Hosted domains' => array('/var/sentora/logs/domains/*/*.log'),
        'FTP (ProFTPD)' => array('/var/sentora/logs/proftpd/*.log'),
        'DNS (BIND)' => array('/var/sentora/logs/bind/*.log'),
        'Apache' => array('/var/log/apache2/*.log'),
        'Mail' => array('/var/log/dovecot*.log', '/var/log/mail.log', '/var/log/mail.err'),
        'MySQL' => array('/var/log/mysql/*.log'),
        'System' => array('/var/log/syslog', '/var/log/auth.log', '/var/log/kern.log'),
    );

    /** every log file found, keyed by id */
    static function All()
    {
        $list = array();
        foreach (self::$sources as $group => $patterns) {
            foreach ($patterns as $pattern) {
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

    static function Get($id)
    {
        $all = self::All();
        return (is_string($id) && isset($all[$id])) ? $all[$id] : false;
    }

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

    /** the logged-in panel user, if an administrator (for read.php / view.php) */
    static function RequireAdmin()
    {
        session_start();
        $uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
        $user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
        session_write_close();
        if (!$user || (int) $user['usergroupid'] !== 1) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('The System Log Viewer is only available to panel administrators.');
        }
        return $user;
    }

}

?>
