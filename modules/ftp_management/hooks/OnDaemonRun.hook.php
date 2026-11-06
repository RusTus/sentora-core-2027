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
    // ProFTPD's table on its own (any user not covered above)
    $left = $zdbh->query("SELECT userid, passwd FROM `" . $ftpDb . "`.ftpuser WHERE passwd <> '' AND passwd NOT LIKE '$6$%'")->fetchAll();
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
