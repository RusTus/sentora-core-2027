<?php

/**
 * MySQL Users module: OnAfterDeleteClient hook.
 *
 * Drops the MySQL users of deleted clients and marks them deleted in the panel.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Updated by RusTus, 2026-11-06
 */

DeleteClientDatabaseUser();

/**
 * Drop the MySQL users of every deleted client.
 *
 * For each user: DROP USER in MySQL (when it exists), set mu_deleted_ts and delete
 * its x_mysql_dbmap rows.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return false|null False when a database error stopped it, otherwise null.
 */
function DeleteClientDatabaseUser() {
    global $zdbh;
    $sql = "SELECT * FROM x_accounts WHERE ac_deleted_ts IS NOT NULL";
    $numrows = $zdbh->query($sql);
    if ($numrows->fetchColumn() <> 0) {
        $sql = $zdbh->prepare($sql);
        $sql->execute();
        while ($rowclient = $sql->fetch()) {
            $rowusers = "SELECT * FROM x_mysql_users WHERE mu_acc_fk=:ac_id_pk AND mu_deleted_ts IS NULL";
            //$numuserrows = $zdbh->query($rowusers);      
            $numuserrows = $zdbh->prepare($rowusers);
            $numuserrows->bindParam(':ac_id_pk', $rowclient['ac_id_pk']);
            $numuserrows->execute();
            
            if ($numuserrows->fetchColumn() <> 0) {
                $rowusers = $zdbh->prepare($rowusers);
                $rowusers->bindParam(':ac_id_pk', $rowclient['ac_id_pk']);
                $rowusers->execute();
                while ($rowuser = $rowusers->fetch()) {


                    $delete = "SELECT EXISTS(SELECT 1 FROM mysql.user WHERE user = :mu_name_vc)";
                    $numrows = $zdbh->prepare($delete);
                    $numrows->bindParam(':mu_name_vc', $rowuser['mu_name_vc']);
                    
                    if ($numrows->execute()) {
                        if ($numrows->fetchColumn() <> 0) {
                            try {
                                $delete = $zdbh->prepare("DROP USER :mu_name_vc@:mu_access_vc;");
                                $delete->bindParam(':mu_name_vc', $rowuser['mu_name_vc']);
                                $delete->bindParam(':mu_access_vc', $rowuser['mu_access_vc']);
                                $delete->execute();
                                $delete = $zdbh->prepare("FLUSH PRIVILEGES");
                                $delete->execute();
                            } catch (PDOException $e) {
                                return false;
                            }
                        }
                    }
                    try {
                        $delete = $zdbh->prepare("
								UPDATE x_mysql_users
								SET mu_deleted_ts = :time 
								WHERE mu_id_pk = :mu_id_pk");
                        $delete->bindParam(':time', time());
                        $delete->bindParam(':mu_id_pk', $rowuser['mu_id_pk']);
                        $delete->execute();
                        $delete = $zdbh->prepare("
								DELETE FROM x_mysql_dbmap
								WHERE mm_user_fk = :mu_id_pk");
                        $delete->bindParam(':mu_id_pk', $rowuser['mu_id_pk']);
                        $delete->execute();
                    } catch (PDOException $e) {
                        return false;
                    }
                }
            }
        }
    }
}

?>