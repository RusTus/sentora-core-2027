<?php

/**
 * Sub-domains module hook (after a client is deleted): removes the client's subdomains.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

DeleteSubDomainsForDeletedClient();

/**
 * Mark the subdomains of every deleted client as deleted and remove their hosting folders.
 *
 * Runs over all deleted accounts each time; the account's whole hosting folder is
 * removed when it still had live subdomains.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function DeleteSubDomainsForDeletedClient() {
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
    foreach ($deletedclients as $deletedclient) {
        $deletedir = false;
        //$result = $zdbh->query("SELECT * FROM x_vhosts WHERE vh_acc_fk=:deletedclient AND vh_type_in=2 AND vh_deleted_ts IS NULL")->Fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_acc_fk=:deletedclient AND vh_type_in=2 AND vh_deleted_ts IS NULL");
        $numrows->bindParam(':deletedclient', $deletedclient);
        $numrows->execute();
        $result = $numrows->fetch();
        if ($result) {
            $sql = $zdbh->prepare("UPDATE x_vhosts SET vh_deleted_ts=:time WHERE vh_acc_fk=:deletedclient AND vh_type_in=2");
            $sql->bindParam(':deletedclient', $deletedclient);
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->execute();
            $deletedir = true;
        }
        if ($deletedir == true) {
            $currentuser = ctrl_users::GetUserDetail($deletedclient);
            // Never with an empty or odd user name: the path would be the folder of every
            // account (hosted_dir itself) or somewhere else entirely
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', (string) $currentuser['username']) && strpos((string) $currentuser['username'], '..') === false
                && is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'])) {
                fs_filehandler::RemoveDirectory(ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username']);
            }
        }
    }
}

?>