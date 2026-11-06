<?php

/**
 * Distribution Lists module: OnAfterDeleteClient hook.
 *
 * Removes the distribution lists of deleted clients from the mail server and marks
 * them deleted in the panel.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

DeleteDistListForDeletedClient();

/**
 * Mark the distribution lists of every deleted client as deleted.
 *
 * Includes the mail server's hook file (e.g. postfix.php), which removes the lists'
 * aliases from the mail database, then sets dl_deleted_ts on x_distlists.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function DeleteDistListForDeletedClient() {
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
    if (file_exists("modules/distlists/hooks/" . ctrl_options::GetSystemOption('mailserver_php') . "")) {
        include("modules/distlists/hooks/" . ctrl_options::GetSystemOption('mailserver_php') . "");
    }

    foreach ($deletedclients as $deletedclient) {
        //$result = $zdbh->query("SELECT * FROM x_distlists WHERE dl_acc_fk=" . $deletedclient . " AND dl_deleted_ts IS NULL")->Fetch();      
        $numrows = $zdbh->prepare("SELECT * FROM x_distlists WHERE dl_acc_fk=:deletedclient AND dl_deleted_ts IS NULL");
        $numrows->bindParam(':deletedclient', $deletedclient);
        $numrows->execute();
        $result = $numrows->fetch();
        if ($result) {
            $sql = $zdbh->prepare("UPDATE x_distlists SET dl_deleted_ts=:time WHERE dl_acc_fk=:deletedclient");
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->bindParam(':deletedclient', $deletedclient);
            $sql->execute();
        }
    }
}

?>