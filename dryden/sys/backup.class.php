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

        // Web files
        exec('cd ' . escapeshellarg($home) . ' && ' . escapeshellarg($zip) . ' -qr9 ' . escapeshellarg($work . '/' . $name) . ' public_html 2>&1', $out, $rc);

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
            $dir = self::BackupDir($username);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (@rename($work . '/' . $name, $dir . $name) || (@copy($work . '/' . $name, $dir . $name) && @unlink($work . '/' . $name))) {
                @chmod($dir . $name, 0640);
                if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                    // Made by the daemon (root): hand it to the web server user, which FTP and the panel use
                    @chown($dir . $name, 'www-data');
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
