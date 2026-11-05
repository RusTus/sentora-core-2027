<?php

/**
 * Mailboxes: mailbox passwords belong to whoever uses the mailbox. The account owner
 * can set a new one (Edit) but never read it, so the panel keeps no copy: copies an
 * earlier version kept for "Show password" are removed here.
 */
global $zdbh; // hooks are included inside a function
$has = (int) $zdbh->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_mailboxes' AND COLUMN_NAME = 'mb_passenc_tx'")->fetchColumn();
if ($has > 0) {
    $n = $zdbh->exec("UPDATE x_mailboxes SET mb_passenc_tx = NULL WHERE mb_passenc_tx IS NOT NULL");
    if ($n > 0) {
        echo fs_filehandler::NewLine() . "Mailboxes: removed " . $n . " stored mailbox password copies (mailbox passwords are reset, never shown)." . fs_filehandler::NewLine();
    }
}
