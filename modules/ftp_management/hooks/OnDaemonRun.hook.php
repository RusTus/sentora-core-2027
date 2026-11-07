<?php

/**
 * FTP passwords are stored as one-way hashes only (SHA-512 crypt, "$6$"), which
 * ProFTPD checks logins against (SQLAuthTypes Crypt). Every run: widen the two
 * password columns if needed, and hash any password still stored as plain text
 * (accounts made before this change), in the panel's table and ProFTPD's.
 */
echo fs_filehandler::NewLine() . "START FTP password hashes" . fs_filehandler::NewLine();
FtpHashStoredPasswords();
echo "END FTP password hashes" . fs_filehandler::NewLine();

echo fs_filehandler::NewLine() . "START FTP access types" . fs_filehandler::NewLine();
FtpWriteAccessRules();
echo "END FTP access types" . fs_filehandler::NewLine();

// a switched-off client's FTP accounts are switched off with it (also done right away by the
// OnAfterEnable/DisableClient hooks; this covers clients switched off before, or by hand)
echo fs_filehandler::NewLine() . "START FTP accounts of switched-off clients" . fs_filehandler::NewLine();
require_once dirname(__FILE__) . '/../code/clientstate.php';
$ftpState = FtpSyncClientState();
echo "Switched off " . $ftpState['off'] . " and back on " . $ftpState['on'] . " FTP login(s)" . fs_filehandler::NewLine();
$ftpEnded = FtpEndSwitchedOffSessions();
if ($ftpEnded > 0) {
    echo "Ended " . $ftpEnded . " open FTP session(s) of switched-off logins" . fs_filehandler::NewLine();
}
echo "END FTP accounts of switched-off clients" . fs_filehandler::NewLine();

/**
 * The same hash the FTP module makes (module_controller::HashPassword); a copy, since
 * every module's controller is called module_controller and only one can be loaded.
 *
 * @param string $password The plain password.
 * @return string The SHA-512 crypt hash ("$6$...").
 */
function FtpHashPassword($password)
{
    $salt = substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16);
    return crypt((string) $password, '$6$rounds=50000$' . $salt . '$');
}

/**
 * Widen the password columns, add the encrypted-copy column and hash every FTP password still stored as plain text.
 *
 * Updates both x_ftpaccounts and ProFTPD's ftpuser table (database from the ftp_db
 * setting) and prints what it did.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function FtpHashStoredPasswords()
{
    global $zdbh;
    $ftpDb = (string) ctrl_options::GetSystemOption('ftp_db');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $ftpDb)) {
        $ftpDb = 'sentora_proftpd';
    }
    $hashed = function ($p) {
        return is_string($p) && strncmp($p, '$6$', 3) === 0;
    };

    // Room for a hash (about 106 characters) in both columns
    $width = $zdbh->prepare("SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
        WHERE (TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_ftpaccounts' AND COLUMN_NAME = 'ft_password_vc')
           OR (TABLE_SCHEMA = :ftpdb AND TABLE_NAME = 'ftpuser' AND COLUMN_NAME = 'passwd')");
    $width->execute(array(':ftpdb' => $ftpDb));
    foreach ($width->fetchAll() as $col) {
        if ((int) $col['CHARACTER_MAXIMUM_LENGTH'] < 255) {
            $null = ($col['TABLE_NAME'] === 'ftpuser') ? "NOT NULL DEFAULT ''" : 'DEFAULT NULL';
            $zdbh->exec("ALTER TABLE `" . $col['TABLE_SCHEMA'] . "`.`" . $col['TABLE_NAME'] . "` MODIFY `" . $col['COLUMN_NAME'] . "` VARCHAR(255) " . $null);
            echo "Widened " . $col['TABLE_SCHEMA'] . "." . $col['TABLE_NAME'] . "." . $col['COLUMN_NAME'] . fs_filehandler::NewLine();
        }
    }

    // The encrypted copy the owner can see (Show password)
    $has = $zdbh->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_ftpaccounts' AND COLUMN_NAME = 'ft_passenc_tx'")->fetchColumn();
    if ((int) $has === 0) {
        $zdbh->exec("ALTER TABLE x_ftpaccounts ADD COLUMN ft_passenc_tx TEXT NULL AFTER ft_password_vc");
        echo "Added the column for encrypted FTP passwords" . fs_filehandler::NewLine();
    }

    // The panel's table: hash, and give ProFTPD the same hash; keep an encrypted
    // copy of the old plain-text password so the owner can still see it
    $n = 0;
    $rows = $zdbh->query("SELECT ft_id_pk, ft_user_vc, ft_password_vc FROM x_ftpaccounts WHERE ft_password_vc IS NOT NULL AND ft_password_vc <> '' AND ft_password_vc NOT LIKE '$6$%'")->fetchAll();
    $setPanel = $zdbh->prepare("UPDATE x_ftpaccounts SET ft_password_vc = :h, ft_passenc_tx = IF(IFNULL(ft_passenc_tx, '') = '', :e, ft_passenc_tx) WHERE ft_id_pk = :id");
    $setFtp = $zdbh->prepare("UPDATE `" . $ftpDb . "`.ftpuser SET passwd = :h WHERE userid = :u");
    foreach ($rows as $row) {
        $hash = FtpHashPassword($row['ft_password_vc']);
        if (!$hashed($hash)) {
            continue; // crypt() failed: leave it, try again next run
        }
        $setPanel->execute(array(':h' => $hash, ':e' => ctrl_secret::Encrypt($row['ft_password_vc']), ':id' => $row['ft_id_pk']));
        $setFtp->execute(array(':h' => $hash, ':u' => $row['ft_user_vc']));
        $n++;
    }
    // ProFTPD's table on its own (any user not covered above; "!" + hash is a switched-off client's)
    $left = $zdbh->query("SELECT userid, passwd FROM `" . $ftpDb . "`.ftpuser WHERE passwd <> '' AND passwd NOT LIKE '$6$%' AND passwd NOT LIKE '!%'")->fetchAll();
    $setOnly = $zdbh->prepare("UPDATE `" . $ftpDb . "`.ftpuser SET passwd = :h WHERE userid = :u");
    foreach ($left as $row) {
        $hash = FtpHashPassword($row['passwd']);
        if ($hashed($hash)) {
            $setOnly->execute(array(':h' => $hash, ':u' => $row['userid']));
            $n++;
        }
    }
    $total = (int) $zdbh->query("SELECT COUNT(*) FROM x_ftpaccounts WHERE ft_deleted_ts IS NULL")->fetchColumn();
    if ($n > 0) {
        echo "Hashed " . $n . " FTP password(s) that were stored as plain text" . fs_filehandler::NewLine();
    }
    $kept = (int) $zdbh->query("SELECT COUNT(*) FROM x_ftpaccounts WHERE ft_deleted_ts IS NULL AND ft_passenc_tx LIKE 'enc1:%'")->fetchColumn();
    echo $total . " FTP account(s): logins checked against hashes only, " . $kept . " with an encrypted password the owner can see." . fs_filehandler::NewLine();
}


/**
 * The access types of the FTP accounts (FTP Accounts: Read-only, Write-only, Full access) as
 * ProFTPD rules, and AuthOrder mod_sql.c when ProFTPD's configuration has no AuthOrder (so only the
 * panel's FTP accounts log in, not the server's own Linux users). They used to be stored only: every FTP account could read and write.
 *
 * Writes ftp-access.conf next to ProFTPD's configuration (one DenyUser line per account: no WRITE
 * for read-only ones, so no upload, delete, rename or new folder; no READ for write-only ones, so
 * no download), adds an Include for it to that configuration once, checks the configuration with
 * "proftpd -t" and reloads ProFTPD. When the check fails, the previous rules are put back.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @param string|null $conf Optional. ProFTPD's configuration file (null: the ftp_config_file setting, else /etc/proftpd/proftpd.conf). Default null.
 * @param bool $reload Optional. Reload ProFTPD after a change. Default true.
 * @return bool|null True when changed and in use, false when the check failed, null when nothing changed (or no ProFTPD).
 */
function FtpWriteAccessRules($conf = null, $reload = true)
{
    global $zdbh;
    if ($conf === null) {
        $conf = trim((string) ctrl_options::GetSystemOption('ftp_config_file'));
        if ($conf === '') {
            $conf = '/etc/proftpd/proftpd.conf';
        }
    }
    $real = @realpath($conf);
    if ($real === false || !is_file($real) || !is_writable($real)) {
        echo "No ProFTPD configuration to add the rules to (" . $conf . ")" . fs_filehandler::NewLine();
        return null;
    }
    $rules = dirname($real) . '/ftp-access.conf';
    $deny = array('WRITE' => array(), 'READ' => array());
    foreach ($zdbh->query("SELECT ft_user_vc, ft_access_vc FROM x_ftpaccounts WHERE ft_deleted_ts IS NULL AND ft_access_vc IN ('RO', 'WO') ORDER BY ft_user_vc")->fetchAll() as $r) {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $r['ft_user_vc'])) {
            $deny[$r['ft_access_vc'] === 'RO' ? 'WRITE' : 'READ'][] = $r['ft_user_vc'];
        }
    }
    $text = "# Made by the Sentora daemon from the access type of each FTP account (FTP Accounts).\n# Do not edit: it is written again when an account changes.\n";
    $main = (string) file_get_contents($real);
    // only the panel's FTP accounts may log in (ProFTPD also took the server's own Linux users
    // with their system passwords); new installs have it in the configuration itself
    if (!preg_match('/^\s*AuthOrder\b/mi', $main)) {
        $text .= "AuthOrder mod_sql.c\n";
    }
    if ($deny['WRITE'] || $deny['READ']) {
        $text .= "<Directory />\n";
        foreach ($deny as $cmd => $users) {
            if ($users) {
                $text .= "  <Limit " . $cmd . ">\n" . implode('', array_map(function ($u) { return "    DenyUser " . $u . "\n"; }, $users)) . "  </Limit>\n";
            }
        }
        $text .= "</Directory>\n";
    }
    $old = is_file($rules) ? (string) file_get_contents($rules) : null;
    $include = "Include " . $rules;
    $hasInclude = preg_match('/^\s*Include\s+' . preg_quote($rules, '/') . '\s*$/m', $main) === 1;
    if ($old === $text && $hasInclude) {
        return null;
    }
    file_put_contents($rules, $text);
    @chmod($rules, 0600);
    if (!$hasInclude) {
        file_put_contents($real, rtrim($main, "\n") . "\n\n# FTP account access types (Read-only, Write-only), written by the Sentora daemon\n" . $include . "\n");
    }
    $out = array();
    $rc = 1;
    exec('proftpd -t -c ' . escapeshellarg($real) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        // keep ProFTPD working: the old rules, or none
        if ($old !== null) {
            file_put_contents($rules, $old);
        } else {
            file_put_contents($real, $main);
            @unlink($rules);
        }
        echo "ProFTPD did not accept the FTP access rules, left as they were: " . implode(' ', array_slice($out, -3)) . fs_filehandler::NewLine();
        return false;
    }
    if ($reload) {
        exec('systemctl reload proftpd 2>&1 || service proftpd reload 2>&1', $out, $rc);
    }
    echo "FTP access rules written: " . count($deny['WRITE']) . " read-only, " . count($deny['READ']) . " write-only account(s)" . fs_filehandler::NewLine();
    return true;
}
