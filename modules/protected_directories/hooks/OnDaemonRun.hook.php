<?php

/**
 * Protected Directories: usernames and passwords of protected folders used to be
 * unique across the whole server. That stopped two accounts from both having an
 * "admin", and with the old hashing (the same password gave the same hash) adding a
 * user failed exactly when someone else already used that password. Both unique
 * indexes are dropped here; the module keeps names unique per folder itself.
 */
global $zdbh; // hooks are included inside a function
foreach (array('x_htpasswd_user_username', 'x_htpasswd_user_password') as $index) {
    $q = $zdbh->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_htpasswd_user' AND INDEX_NAME = :i");
    $q->execute(array(':i' => $index));
    if ((int) $q->fetchColumn() > 0) {
        $zdbh->exec("ALTER TABLE x_htpasswd_user DROP INDEX `" . $index . "`");
        echo fs_filehandler::NewLine() . "Protected Directories: dropped the server-wide unique index " . $index . fs_filehandler::NewLine();
    }
}
