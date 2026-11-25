<?php

/**
 * Protected Directories: usernames and passwords of protected folders used to be
 * unique across the whole server. That stopped two accounts from both having an
 * "admin", and with the old hashing (the same password gave the same hash) adding a
 * user failed exactly when someone else already used that password. Both unique
 * indexes are dropped here; the module keeps names unique per folder itself.
 *
 * Written by RusTus, 2026-11-25
 */
global $zdbh; // hooks are included inside a function
if (sys_versions::IsWindows()) {
    // (Windows: the pages can not write in the accounts' folders; the daemon writes every protected folder's .htaccess
    // block and password file from the database, and takes the block out of folders no longer protected)
    require_once dirname(__FILE__) . '/../code/pdsafe.php';
    $pdBegin = '# BEGIN Sentora protected directory (managed by the panel: do not edit this block)';
    $pdEnd = '# END Sentora protected directory';
    $pdChanged = 0;
    $pdRows = $zdbh->query("SELECT f.*, a.ac_user_vc FROM x_htpasswd_file f JOIN x_accounts a ON a.ac_id_pk = f.x_htpasswd_sentora_user_id WHERE a.ac_deleted_ts IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pdRows as $pdRow) {
        $user = (string) $pdRow['ac_user_vc'];
        $rel = pd_safe::RelPath($user, (string) $pdRow['x_htpasswd_file_target']);
        $dir = ($rel === false) ? false : pd_safe::ResolveDir($user, $rel);
        if ($dir === false) {
            continue;
        }
        $passwd = pd_safe::PasswdFile($user, $dir);
        $current = pd_safe::Read($dir . '/.htaccess');
        if ($current === false) {
            continue;
        }
        // (the block taken out, then put back while the folder is protected)
        $rest = preg_replace('/' . preg_quote($pdBegin, '/') . '.*?' . preg_quote($pdEnd, '/') . '(\R\R?)?/s', '', $current);
        $rest = trim($rest) === '' ? '' : ltrim(rtrim($rest), "\r\n") . "\n";
        $on = $pdRow['x_htpasswd_file_deleted'] === null;
        $msg = preg_replace('/["\x00-\x1F\x7F\\\\]/', '', (string) $pdRow['x_htpasswd_file_message']);
        $new = $on ? $pdBegin . "\nAuthType Basic\nAuthName \"" . $msg . "\"\nAuthUserFile \"" . $passwd . "\"\nRequire valid-user\n" . $pdEnd . "\n" . ($rest === '' ? '' : "\n" . $rest) : $rest;
        if ($new !== $current) {
            if ($new === '') {
                pd_safe::Remove($dir . '/.htaccess');
            } else {
                pd_safe::Write($dir, '.htaccess', $new, 0644);
            }
            $pdChanged++;
        }
        if ($on) {
            $q = $zdbh->prepare("SELECT u.x_htpasswd_user_username, u.x_htpasswd_user_password FROM x_htpasswd_user u JOIN x_htpasswd_mapper m ON m.x_htpasswd_user_id = u.x_htpasswd_user_id
                WHERE m.x_htpasswd_file_id = :f AND u.x_htpasswd_user_deleted IS NULL AND u.x_htpasswd_sentora_user_id = :u ORDER BY u.x_htpasswd_user_username");
            $q->execute(array(':f' => (int) $pdRow['x_htpasswd_file_id'], ':u' => (int) $pdRow['x_htpasswd_sentora_user_id']));
            $lines = '';
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) {
                if (preg_match('/^[A-Za-z0-9._-]{1,32}$/', (string) $u['x_htpasswd_user_username']) && strpbrk((string) $u['x_htpasswd_user_password'], ":\r\n") === false) {
                    $lines .= $u['x_htpasswd_user_username'] . ':' . $u['x_htpasswd_user_password'] . "\n";
                }
            }
            $pdir = pd_safe::PasswdDir($user);
            if ($pdir !== false && pd_safe::Read($passwd) !== $lines) {
                pd_safe::Write($pdir, basename($passwd), $lines, 0640);
                $pdChanged++;
            }
        } elseif (is_file($passwd)) {
            pd_safe::Remove($passwd);
        }
    }
    if ($pdChanged) {
        echo fs_filehandler::NewLine() . "Protected Directories: " . $pdChanged . " file(s) written" . fs_filehandler::NewLine();
    }
}
foreach (array('x_htpasswd_user_username', 'x_htpasswd_user_password') as $index) {
    $q = $zdbh->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_htpasswd_user' AND INDEX_NAME = :i");
    $q->execute(array(':i' => $index));
    if ((int) $q->fetchColumn() > 0) {
        $zdbh->exec("ALTER TABLE x_htpasswd_user DROP INDEX `" . $index . "`");
        echo fs_filehandler::NewLine() . "Protected Directories: dropped the server-wide unique index " . $index . fs_filehandler::NewLine();
    }
}
