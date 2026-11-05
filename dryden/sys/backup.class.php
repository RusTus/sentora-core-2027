<?php

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

    /** where finished backups for an account are kept */
    static function BackupDir($username)
    {
        return ctrl_options::GetSystemOption('hosted_dir') . $username . '/backups/';
    }

    static function ValidUser($username)
    {
        return is_string($username) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $username) === 1;
    }

    /**
     * The account's backups folder, only when it is a real folder inside the account
     * (never a symbolic link a site put in its place: the panel and the daily job
     * would otherwise read, write and delete wherever it points). Made if missing.
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
     * The account's backup files: real files with a backup name only (links and
     * anything else in the folder are left out), newest first
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
     * One of the account's backups, opened for reading: a real file (not a link) with
     * a backup name, and the file opened is the one checked (same device and inode),
     * so swapping in a link between the check and the open fails.
     * Returns array(handle, size) or false.
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

    /** remove one of the account's backups (a link of that name is removed itself) */
    static function DeleteFile($username, $file)
    {
        $dir = self::SafeBackupDir($username, false);
        if ($dir === false || !self::ValidFile($username, $file)) {
            return false;
        }
        clearstatcache(true, $dir . $file);
        return (is_link($dir . $file) || is_file($dir . $file)) ? @unlink($dir . $file) : false;
    }

    /** the daily purge: backups older than $days, in the account's real backups folder only */
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

    static function ProgressFile($accountId)
    {
        return '/var/lib/sentora/tmp/backup-' . (int) $accountId . '.json';
    }

    /** is a backup of this account being made now (its worker holds the lock)? */
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

    /** the progress of this account's backup, for the page: state none|running|done|failed */
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
     * Start a backup in the background; returns started|running|recent|failed.
     * The worker (bin/sentora-account-backup) runs as the panel's user, like the page did.
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

    /** valid name of one of this account's backup files */
    static function ValidFile($username, $file)
    {
        return self::ValidUser($username) && is_string($file)
            && preg_match('/^' . preg_quote($username, '/') . '_[A-Za-z0-9-]+_[0-9]{6}(_[0-9a-f]{8})?\.zip$/', $file) === 1;
    }

    /**
     * Make a backup; returns the full path of the zip in the account's backups
     * folder, or false.
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
