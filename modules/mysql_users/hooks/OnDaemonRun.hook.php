<?php

/**
 * MySQL Users: the panel keeps only an encrypted copy of each MySQL user's password
 * (ctrl_secret), which the owner can see again with Show password. Copies stored
 * as plain text by older versions are encrypted here.
 */
global $zdbh; // hooks are included inside a function
echo fs_filehandler::NewLine() . "START MySQL user passwords" . fs_filehandler::NewLine();
// Admin > Sentora Config: ask for the panel password before Show password (off by default)
if ((int) $zdbh->query("SELECT COUNT(*) FROM x_settings WHERE so_name_vc = 'show_password_confirm'")->fetchColumn() === 0) {
    $zdbh->exec("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
        VALUES ('show_password_confirm', 'Confirm panel password to show passwords', 'false', 'true|false',
        'Show password (MySQL Users, FTP) asks for the panel password first.', 'Sentora Config', 'true')");
}
// Room for the encrypted form (about 80 characters for a 16-character password)
$w = $zdbh->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_mysql_users' AND COLUMN_NAME = 'mu_pass_vc'")->fetchColumn();
if ((int) $w < 255) {
    $zdbh->exec("ALTER TABLE x_mysql_users MODIFY mu_pass_vc VARCHAR(255) DEFAULT NULL");
    echo "Widened x_mysql_users.mu_pass_vc for encrypted passwords" . fs_filehandler::NewLine();
}
$rows = $zdbh->query("SELECT mu_id_pk, mu_pass_vc FROM x_mysql_users WHERE mu_pass_vc IS NOT NULL AND mu_pass_vc <> '' AND mu_pass_vc NOT LIKE 'enc1:%'")->fetchAll();
$set = $zdbh->prepare("UPDATE x_mysql_users SET mu_pass_vc = :e WHERE mu_id_pk = :id");
$n = 0;
foreach ($rows as $r) {
    $enc = ctrl_secret::Encrypt($r['mu_pass_vc']);
    if ($enc === '') {
        echo "Could not encrypt stored passwords: this server has no secret in cnf/security.php." . fs_filehandler::NewLine();
        break;
    }
    $set->execute(array(':e' => $enc, ':id' => $r['mu_id_pk']));
    $n++;
}
if ($n > 0) {
    echo "Encrypted " . $n . " MySQL user password(s) that were stored as plain text." . fs_filehandler::NewLine();
}
$total = (int) $zdbh->query("SELECT COUNT(*) FROM x_mysql_users WHERE mu_deleted_ts IS NULL")->fetchColumn();
$kept = (int) $zdbh->query("SELECT COUNT(*) FROM x_mysql_users WHERE mu_deleted_ts IS NULL AND mu_pass_vc LIKE 'enc1:%'")->fetchColumn();
echo $total . " MySQL user(s): " . $kept . " with an encrypted password the owner can see, none as plain text." . fs_filehandler::NewLine();
echo "END MySQL user passwords" . fs_filehandler::NewLine();
