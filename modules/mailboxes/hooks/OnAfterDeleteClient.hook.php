<?php

/**
 * Mailboxes module hook (OnAfterDeleteClient): delete the mailboxes of deleted accounts.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
DeleteMailboxesForDeletedClient();

/**
 * Mark the mailboxes of all deleted accounts deleted, after running the mail server's hook include file.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function DeleteMailboxesForDeletedClient() {
    global $zdbh;
    $deletedclients = array();
    $sql = "SELECT COUNT(*) FROM x_accounts WHERE ac_deleted_ts IS NOT NULL";
    if ($numrows = $zdbh->query($sql)) {
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare("SELECT * FROM x_accounts WHERE ac_deleted_ts IS NOT NULL");
            $sql->execute();
            while ($rowclient = $sql->fetch()) {
                $deletedclients[] = $rowclient['ac_id_pk'];
            }
        }
    }

    // Include mail server specific file here.
    if (file_exists("modules/mailboxes/hooks/" . ctrl_options::GetSystemOption('mailserver_php') . "")) {
        include("modules/mailboxes/hooks/" . ctrl_options::GetSystemOption('mailserver_php') . "");
    }

    foreach ($deletedclients as $deletedclient) {
//      $result = $zdbh->query("SELECT * FROM x_mailboxes WHERE mb_acc_fk=" . $deletedclient . " AND mb_deleted_ts IS NULL")->Fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_mailboxes WHERE mb_acc_fk=:deletedclient AND mb_deleted_ts IS NULL");
        $numrows->bindParam(':deletedclient', $deletedclient);
        $numrows->execute();
        $result = $numrows->fetch();
        if ($result) {
            $time = time();
            $sql = $zdbh->prepare("UPDATE x_mailboxes SET mb_deleted_ts=:time WHERE mb_acc_fk=:deletedclient");
            $sql->bindParam(':time', $time);
            $sql->bindParam(':deletedclient', $deletedclient);
            $sql->execute();
        }
    }
}

?>