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

/*
 * Mailboxes: the mail of deleted mailboxes. Deleting a mailbox removed only its Postfix
 * records, so its mail stayed on disk, and a new mailbox with the same address later read
 * it. The mail folders belong to vmail, so root removes them here: the maildir
 * (<vmail>/<domain>/<name>/), its home (<vmail>/<domain>/.home/<name>, filters and
 * settings) and the mailbox's SpamAV settings. Only mailboxes deleted from this version
 * on (those deleted before are marked done the first time, so an update removes nothing
 * old), never while the same address is a mailbox again, and only exact folders inside
 * the mail folder, never through a link.
 */
MailboxesPurgeDeleted();

/**
 * Remove the stored mail of mailboxes deleted since the last run (see the note above).
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function MailboxesPurgeDeleted()
{
    global $zdbh;
    $has = (int) $zdbh->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_mailboxes' AND COLUMN_NAME = 'mb_purged_in'")->fetchColumn();
    if ($has === 0) {
        $zdbh->exec("ALTER TABLE x_mailboxes ADD mb_purged_in TINYINT(1) NOT NULL DEFAULT 0");
        // mailboxes deleted before this version: left as they are
        $zdbh->exec("UPDATE x_mailboxes SET mb_purged_in = 1 WHERE mb_deleted_ts IS NOT NULL");
        return;
    }
    $base = dirname(rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/')) . '/vmail';
    $real = realpath($base);
    if ($real === false || is_link($base)) {
        return; // no mail folder here (or a link): nothing to do safely
    }
    $q = $zdbh->query("SELECT mb_id_pk, mb_address_vc FROM x_mailboxes WHERE mb_deleted_ts IS NOT NULL AND mb_purged_in = 0");
    $done = $zdbh->prepare("UPDATE x_mailboxes SET mb_purged_in = 1 WHERE mb_id_pk = :id");
    $live = $zdbh->prepare("SELECT COUNT(*) FROM x_mailboxes WHERE mb_address_vc = :a AND mb_deleted_ts IS NULL");
    $removed = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $addr = strtolower((string) $row['mb_address_vc']);
        $live->execute(array(':a' => $addr));
        $inUse = (int) $live->fetchColumn() > 0;
        if (!$inUse && preg_match('/^([a-z0-9][a-z0-9._+-]{0,63})@([a-z0-9][a-z0-9.-]{0,252})$/', $addr, $m)
            && strpos($m[1], '..') === false && strpos($m[2], '..') === false) {
            foreach (array($real . '/' . $m[2] . '/' . $m[1], $real . '/' . $m[2] . '/.home/' . $m[1]) as $dir) {
                if (MailboxesRemoveTree($dir, $real)) {
                    $removed++;
                }
            }
            if (class_exists('sys_spamav')) {
                $zdbh->prepare("DELETE FROM x_spamav_prefs WHERE sp_mailbox_vc = :m")->execute(array(':m' => $addr));
            }
        }
        $done->execute(array(':id' => (int) $row['mb_id_pk']));
    }
    if ($removed > 0) {
        echo fs_filehandler::NewLine() . "Mailboxes: removed " . $removed . " mail folder(s) of deleted mailboxes." . fs_filehandler::NewLine();
    }
}

/**
 * Remove a folder and everything in it, only when it is a real folder directly where it is
 * expected inside the mail folder: links are removed as links, never followed.
 *
 * @param string $dir The folder.
 * @param string $root The mail folder it must be inside (its real path).
 * @return bool True when it was removed.
 */
function MailboxesRemoveTree($dir, $root)
{
    clearstatcache();
    if (!is_dir($dir) || is_link($dir) || realpath($dir) !== $dir || strpos($dir, $root . '/') !== 0) {
        return false;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (is_link($p) || !$f->isDir()) {
            @unlink($p);
        } else {
            @rmdir($p);
        }
    }
    return @rmdir($dir);
}
