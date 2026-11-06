<?php

/**
 * DNS Manager module: OnAfterDeleteClient hook.
 *
 * Marks the DNS records of deleted accounts as deleted and queues a zone update.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
DeleteDNSRecordsForDeletedClient();

/**
 * Mark the DNS records of every deleted account as deleted.
 *
 * Queues a zone update for the domain of the first record found per account.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function DeleteDNSRecordsForDeletedClient() {
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
        //$result = $zdbh->query("SELECT * FROM x_dns WHERE dn_acc_fk=" . $deletedclient . " AND dn_deleted_ts IS NULL")->Fetch();        
        $numrows = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_acc_fk=:deletedclient AND dn_deleted_ts IS NULL");
        $numrows->bindParam(':deletedclient', $deletedclient);
        $numrows->execute();
        $result = $numrows->fetch();
        if ($result) {
            $sql = $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=:time WHERE dn_acc_fk=:deletedclient");
            $sql->bindParam(':deletedclient', $deletedclient);
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->execute();
            TriggerDNSUpdate($result['dn_vhost_fk']);
        }
    }
}

/**
 * Add a domain ID to the dns_hasupdates list so the daemon rewrites its zone.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @param int|string $id The domain (x_vhosts) ID.
 * @return true|null True when the ID was added, null when it was already in the list.
 */
function TriggerDNSUpdate($id) {
    global $zdbh;
    $GetRecords = ctrl_options::GetSystemOption('dns_hasupdates');
    $records = explode(",", $GetRecords);
    foreach ($records as $record) {
        $RecordArray[] = $record;
    }
    if (!in_array($id, $RecordArray)) {
        $newlist = $GetRecords . "," . $id;
        $newlist = str_replace(",,", ",", $newlist);
        $sql = "UPDATE x_settings SET so_value_tx=:newlist WHERE so_name_vc='dns_hasupdates'";
        $sql = $zdbh->prepare($sql);
        $sql->bindParam(':newlist', $newlist);
        $sql->execute();
        return true;
    }
}

?>