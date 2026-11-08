<?php

/**
 * Forwarders module: OnAfterDeleteClient hook.
 *
 * Removes the forwarders of deleted clients from the mail server and marks them
 * deleted in the panel.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Updated by RusTus, 2026-11-06
 */

DeleteForwardersForDeletedClient();

/**
 * Mark the forwarders of every deleted client as deleted.
 *
 * Includes the mail server's hook file (e.g. postfix.php), which stops the forwarding
 * in the mail database (the mailbox's alias delivers to itself again), then sets
 * fw_deleted_ts on x_forwarders; not when the mail database could not be reached
 * ($mailCleanupFailed), so that is tried again next run.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function DeleteForwardersForDeletedClient() {
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
    if (file_exists("modules/forwarders/hooks/" . ctrl_options::GetSystemOption('mailserver_php') . "")) {
        include("modules/forwarders/hooks/" . ctrl_options::GetSystemOption('mailserver_php') . "");
    }

    // Marked deleted only once the mail server was updated (the mail server file sets
    // $mailCleanupFailed when it could not reach it), so a failure is retried next time
    foreach ((empty($mailCleanupFailed) ? $deletedclients : array()) as $deletedclient) {
        //$result = $zdbh->query("SELECT * FROM x_forwarders WHERE fw_acc_fk=:deletedclient AND fw_deleted_ts IS NULL")->Fetch();       
        $numrows = $zdbh->prepare("SELECT * FROM x_forwarders WHERE fw_acc_fk=:deletedclient AND fw_deleted_ts IS NULL");
        $numrows->bindParam(':deletedclient', $deletedclient);
        $numrows->execute();
        $result = $numrows->fetch();

        if ($result) {
            $sql = $zdbh->prepare("UPDATE x_forwarders SET fw_deleted_ts=:time WHERE fw_acc_fk=:deletedclient");
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->bindParam(':deletedclient', $deletedclient);
            $sql->execute();
        }
    }
}

?>