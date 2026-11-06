<?php
/**
 * Aliases module hook (OnAfterDeleteClient): delete the aliases of deleted clients.
 *
 * Marks the aliases of deleted accounts deleted and, through the mail server
 * specific hook file (mailserver_php), removes them from the mail server.
 *
 * @package Sentora
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

DeleteAliasForDeletedClient();

/**
 * Mark the aliases of deleted accounts deleted and remove them from the mail server.
 *
 * Includes modules/aliases/hooks/<mailserver_php>, which uses $deletedclients.
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function DeleteAliasForDeletedClient() {
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
    if (file_exists("modules/aliases/hooks/" . ctrl_options::GetSystemOption('mailserver_php') . "")) {
        include("modules/aliases/hooks/" . ctrl_options::GetSystemOption('mailserver_php') . "");
    }

    foreach ($deletedclients as $deletedclient) {
        $bindArray = array(':deletedclient' => $deletedclient);
        $sqlStatment = $zdbh->bindQuery("SELECT * FROM x_aliases WHERE al_acc_fk=:deletedclient AND al_deleted_ts IS NULL", $bindArray);
        $result = $zdbh->returnRow();

        if ($result) {
            $sql = $zdbh->prepare("UPDATE x_aliases SET al_deleted_ts=:time WHERE al_acc_fk=:deletedclient");
            $sql->bindParam(':time', time());
            $sql->bindParam(':deletedclient', $deletedclient);
            $sql->execute();
        }
    }
}

?>