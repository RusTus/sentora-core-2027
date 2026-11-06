<?php

/**
 * Core class: account backups (web files and MySQL databases).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * Account backups (web files + MySQL databases) for the Backup module and the
 * daily backup job.
 *
 * The old code built each backup in the panel's web folder (etc/tmp/), with a
 * guessable name (user_Mon-dd-YYYY_hhmmss.zip) and chmod 777, and left it there
 * whenever the user chose "download" - so anyone could fetch a full copy of a
 * site and its databases. It also put the MySQL root password on the mysqldump
 * command line, where every process on the server can read it.
 *
 * Now: a private 0700 work folder outside the web root, a random part in every
 * file name, every argument shell-escaped, and the MySQL credentials passed in
 * a 0600 option file.
 */
class sys_backup
{

    /**
     * Return the folder where finished backups for an account are kept.
     *
     * Not checked for links; use SafeBackupDir() before reading or writing.
     *
     * @param string $username The account's panel user name.
     * @return string Folder path with a trailing slash.
     */
    static function BackupDir($username)
    {
        return ctrl_options::GetSystemOption('hosted_dir') . $username . '/backups/';
    }

    /**
     * Check that a user name is safe to use in paths and file names.
     *
     * @param mixed $username The user name to check (any type; only strings pass).
     * @return bool True for 1-64 letters, digits, '_' or '-'.
     */
    static function ValidUser($username)
    {
        return is_string($username) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $username) === 1;
    }

    /**
     * Return the account's backups folder, only when it is a real folder inside the account.
     *
     * Never a symbolic link a site put in its place: the panel and the daily job
     * would otherwise read, write and delete wherever it points. Made if missing
     * (and, when run as root, given to the account's own user).
     *
     * @global db_driver $zdbh The panel's database connection (read through $GLOBALS).
     * @param string $username The account's panel user name.
     * @param bool $create Optional. Create the folder when missing. Default true.
     * @return string|false Folder path with a trailing slash, or false.
     */
    static function SafeBackupDir($username, $create = true)
    {
        if (!self::ValidUser($username)) {
            return false;
        }
        $home = rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . '/' . $username;
        $dir = $home . '/backups';
        clearstatcache(true, $dir);
        if (is_link($home) || !is_dir($home) || is_link($dir)) {
            return false;
        }
        if (!is_dir($dir)) {
            if (!$create || !@mkdir($dir, 0750)) {
                return false;
            }
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                $id = (int) $GLOBALS['zdbh']->query("SELECT ac_id_pk FROM x_accounts WHERE ac_user_vc = " . $GLOBALS['zdbh']->quote($username) . " AND ac_deleted_ts IS NULL")->fetchColumn();
                if ($id > 0 && posix_getpwnam(sys_accountphp::UserName($id)) !== false) {
                    @chown($dir, sys_accountphp::UserName($id));
                }
            }
        }
        clearstatcache(true, $dir);
        return (!is_link($dir) && is_dir($dir) && realpath($dir) === $dir) ? $dir . '/' : false;
    }

    /**
     * List the account's backup files, newest first.
     *
     * Real files with a backup name only (links and anything else in the folder are
     * left out).
     *
     * @param string $username The account's panel user name.
     * @return array<string, array{size: int, mtime: int}> File name => size in bytes and modification time.
     */
    static function ListFiles($username)
    {
        $dir = self::SafeBackupDir($username, false);
        $out = array();
        if ($dir === false || !($h = @opendir($dir))) {
            return $out;
        }
        while (($f = readdir($h)) !== false) {
            if (!self::ValidFile($username, $f)) {
                continue;
            }
            $st = @lstat($dir . $f);
            if ($st !== false && ($st['mode'] & 0170000) === 0100000) {
                $out[$f] = array('size' => $st['size'], 'mtime' => $st['mtime']);
            }
        }
        closedir($h);
        uasort($out, function ($a, $b) { return $b['mtime'] - $a['mtime']; });
        return $out;
    }

    /**
     * Open one of the account's backups for reading.
     *
     * A real file (not a link) with a backup name, and the file opened is the one
     * checked (same device and inode), so swapping in a link between the check and
     * the open fails.
     *
     * @param string $username The account's panel user name.
     * @param string $file The backup's file name (no path).
     * @return array{0: resource, 1: int}|false array(handle, size), or false.
     */
    static function OpenFile($username, $file)
    {
        $dir = self::SafeBackupDir($username, false);
        if ($dir === false || !self::ValidFile($username, $file)) {
            return false;
        }
        $path = $dir . $file;
        $st = @lstat($path);
        if ($st === false || ($st['mode'] & 0170000) !== 0100000) {
            return false;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $fs = fstat($fh);
        if ($fs['dev'] !== $st['dev'] || $fs['ino'] !== $st['ino']) {
            fclose($fh);
            return false;
        }
        return array($fh, $fs['size']);
    }

    /**
     * Remove one of the account's backups (a link of that name is removed itself).
     *
     * @param string $username The account's panel user name.
     * @param string $file The backup's file name (no path).
     * @return bool True when removed.
     */
    static function DeleteFile($username, $file)
    {
        $dir = self::SafeBackupDir($username, false);
        if ($dir === false || !self::ValidFile($username, $file)) {
            return false;
        }
        clearstatcache(true, $dir . $file);
        return (is_link($dir . $file) || is_file($dir . $file)) ? @unlink($dir . $file) : false;
    }

    /**
     * Delete backups older than $days, in the account's real backups folder only (the daily purge).
     *
     * @param string $username The account's panel user name.
     * @param int|string $days Age in days (cast to int); nothing is removed below 1.
     * @return int Number of files removed.
     */
    static function Purge($username, $days)
    {
        $dir = self::SafeBackupDir($username, false);
        $n = 0;
        if ($dir === false || (int) $days < 1 || !($h = @opendir($dir))) {
            return $n;
        }
        while (($f = readdir($h)) !== false) {
            if (!self::ValidFile($username, $f)) {
                continue;
            }
            $st = @lstat($dir . $f);
            if ($st !== false && (time() - $st['mtime']) > (int) $days * 86400 && @unlink($dir . $f)) {
                $n++;
            }
        }
        closedir($h);
        return $n;
    }

    // --- a backup in the background (Advanced > Backup > Back up now) --------------------------

    /**
     * Return the progress file of an account's background backup.
     *
     * @param int|string $accountId The account ID (cast to int).
     * @return string Full path of the JSON file.
     */
    static function ProgressFile($accountId)
    {
        return '/var/lib/sentora/tmp/backup-' . (int) $accountId . '.json';
    }

    /**
     * Check whether a backup of this account is being made now (its worker holds the lock).
     *
     * @param int|string $accountId The account ID (cast to int).
     * @return bool True while the worker holds the lock.
     */
    static function Running($accountId)
    {
        $h = @fopen('/var/lib/sentora/tmp/backup-' . (int) $accountId . '.lock', 'c');
        if ($h === false) {
            return false;
        }
        $free = flock($h, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($h, LOCK_UN);
        }
        fclose($h);
        return !$free;
    }

    /**
     * Return the progress of this account's backup, for the page.
     *
     * 'state' is none, running, done or failed; a worker that stopped without
     * finishing reads as failed.
     *
     * @param int|string $accountId The account ID.
     * @return array<string, mixed> 'state' always; 'started', 'elapsed' and, when known, 'file', 'size', 'message', 'finished'.
     */
    static function Progress($accountId)
    {
        $s = json_decode((string) @file_get_contents(self::ProgressFile($accountId)), true);
        if (!is_array($s) || !isset($s['state'])) {
            return array('state' => 'none');
        }
        $out = array('state' => (string) $s['state'], 'started' => (int) ($s['started'] ?? 0));
        if ($out['state'] === 'running' || $out['state'] === 'starting') {
            if (self::Running($accountId)) {
                $out['state'] = 'running';
            } elseif ($out['state'] === 'starting' && time() - $out['started'] < 30) {
                $out['state'] = 'running'; // the worker is just starting
            } else {
                $out['state'] = 'failed';
                $out['message'] = 'The backup stopped before it was finished. Try again.';
            }
        }
        $out['elapsed'] = max(0, (isset($s['finished']) ? (int) $s['finished'] : time()) - $out['started']);
        foreach (array('file', 'size', 'message', 'finished') as $k) {
            if (isset($s[$k]) && !isset($out[$k])) {
                $out[$k] = $s[$k];
            }
        }
        return $out;
    }

    /**
     * Start a backup in the background.
     *
     * The worker (bin/sentora-account-backup) runs as the panel's user, like the page
     * did. Writes the progress file with state 'starting'.
     *
     * @param int|string $accountId The account ID (cast to int).
     * @return string 'started', 'running', 'recent' (one finished less than 2 minutes ago) or 'failed'.
     */
    static function StartBackground($accountId)
    {
        $accountId = (int) $accountId;
        if (self::Running($accountId)) {
            return 'running';
        }
        $p = self::Progress($accountId);
        if ($p['state'] === 'running') {
            return 'running';
        }
        if ($p['state'] === 'done' && isset($p['finished']) && time() - (int) $p['finished'] < 120) {
            return 'recent';
        }
        $worker = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/bin/sentora-account-backup';
        if (!is_file($worker)) {
            return 'failed';
        }
        $file = self::ProgressFile($accountId);
        @file_put_contents($file . '.new', json_encode(array('state' => 'starting', 'started' => time())));
        @chmod($file . '.new', 0660);
        @rename($file . '.new', $file);
        exec('setsid nohup php ' . escapeshellarg($worker) . ' ' . $accountId . ' > /dev/null 2>&1 < /dev/null &');
        return 'started';
    }

    /**
     * Check that a name is a valid name of one of this account's backup files.
     *
     * @param string $username The account's panel user name.
     * @param mixed $file The file name to check (any type; only strings pass).
     * @return bool True for '<user>_<date>_<time>[_<random>].zip'.
     */
    static function ValidFile($username, $file)
    {
        return self::ValidUser($username) && is_string($file)
            && preg_match('/^' . preg_quote($username, '/') . '_[A-Za-z0-9-]+_[0-9]{6}(_[0-9a-f]{8})?\.zip$/', $file) === 1;
    }

    /**
     * Make a backup of an account's public_html and MySQL databases.
     *
     * Builds the zip in a private work folder, then moves it into the account's
     * backups folder.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $userid The account ID.
     * @param string $username The account's panel user name.
     * @return string|false Full path of the zip in the account's backups folder, or false.
     */
    static function Create($userid, $username)
    {
        global $zdbh;
        if (!self::ValidUser($username)) {
            return false;
        }
        $home = ctrl_options::GetSystemOption('hosted_dir') . $username;
        $tempRoot = rtrim(ctrl_options::GetSystemOption('temp_dir'), '/');
        if (!is_dir($tempRoot)) {
            @mkdir($tempRoot, 0755, true);
        }
        $work = $tempRoot . '/backup-' . bin2hex(random_bytes(12));
        if (!@mkdir($work, 0700)) {
            return false;
        }
        $name = $username . '_' . date('M-d-Y_His') . '_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = ctrl_options::GetSystemOption('zip_exe');
        $zip = ($zip ? $zip : 'zip');

        // Web files. -y stores symbolic links as links: zip follows them otherwise, so a
        // site could link to any file the panel (or root, for the daily job) can read
        // and have it copied into its own backup
        exec('cd ' . escapeshellarg($home) . ' && ' . escapeshellarg($zip) . ' -qr9y ' . escapeshellarg($work . '/' . $name) . ' public_html 2>&1', $out, $rc);

        // Databases: credentials in an option file, never on the command line
        include ctrl_options::GetSystemOption('sentora_root') . 'cnf/db.php';
        $cnf = $work . '/my.cnf';
        file_put_contents($cnf, "[client]\nuser=\"" . addcslashes($user, "\"\\") . "\"\npassword=\"" . addcslashes($pass, "\"\\") . "\"\nhost=\"" . addcslashes($host, "\"\\") . "\"\n");
        chmod($cnf, 0600);
        $dump = ctrl_options::GetSystemOption('mysqldump_exe');
        $dump = ($dump ? $dump : 'mysqldump');
        $sql = $zdbh->prepare("SELECT my_name_vc FROM x_mysql_databases WHERE my_acc_fk = :userid AND my_deleted_ts IS NULL");
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        while ($row = $sql->fetch()) {
            $db = (string) $row['my_name_vc'];
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)) {
                continue;
            }
            $file = $db . '_' . date('dmy_Gi') . '.sql';
            exec(escapeshellarg($dump) . ' --defaults-extra-file=' . escapeshellarg($cnf) . ' --no-create-db ' . escapeshellarg($db)
                . ' > ' . escapeshellarg($work . '/' . $file) . ' 2> /dev/null');
            exec('cd ' . escapeshellarg($work) . ' && ' . escapeshellarg($zip) . ' -q ' . escapeshellarg($name) . ' ' . escapeshellarg($file));
            @unlink($work . '/' . $file);
        }
        @unlink($cnf);

        $result = false;
        if (is_file($work . '/' . $name)) {
            // the account's real backups folder (never through a link), checked right
            // before; rename() replaces a link of that name instead of writing through it
            $dir = self::SafeBackupDir($username);
            $moved = ($dir !== false) && @rename($work . '/' . $name, $dir . $name);
            if (!$moved && $dir !== false) {
                // another disk: a copy under a new random name in that folder, renamed into place
                $tmp = $dir . '.sentora-tmp-' . bin2hex(random_bytes(8));
                $moved = @copy($work . '/' . $name, $tmp) && @rename($tmp, $dir . $name);
                @unlink($tmp);
                @unlink($work . '/' . $name);
            }
            if ($moved) {
                @chmod($dir . $name, 0640);
                if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                    // Made by the daemon (root): it belongs to the account's own user, which
                    // its FTP logins run as (the panel reads it through the folder's access list)
                    $owner = sys_accountphp::UserName((int) $userid);
                    @chown($dir . $name, posix_getpwnam($owner) !== false ? $owner : 'www-data');
                    @chgrp($dir . $name, 'www-data');
                }
                $result = $dir . $name;
            }
        }
        foreach ((array) glob($work . '/*') as $left) {
            @unlink($left);
        }
        @rmdir($work);
        return $result;
    }

}

?>
