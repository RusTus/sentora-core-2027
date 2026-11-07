<?php

/**
 * MySQL Databases module hook (OnAfterDeleteClient): drops the databases of deleted clients.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

DeleteClientDatabases();

/**
 * Drops the MySQL databases of every deleted client and marks them deleted.
 *
 * For each account with ac_deleted_ts set, drops its first remaining database, marks all
 * of the account's x_mysql_databases rows deleted and removes that database's user
 * mappings (x_mysql_dbmap). Database errors are ignored.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function DeleteClientDatabases() {
    global $zdbh;
    $sql = "SELECT * FROM x_accounts WHERE ac_deleted_ts IS NOT NULL";
    $numrows = $zdbh->query($sql);
    if ($numrows->fetchColumn() <> 0) {
        $sql = $zdbh->prepare($sql);
        $sql->execute();
        while ($rowclient = $sql->fetch()) {
            //$rowdatabase = $zdbh->query("SELECT * FROM x_mysql_databases WHERE my_acc_fk=" . $rowclient['ac_id_pk'] . " AND my_deleted_ts IS NULL")->fetch();
            
            // Every database of the deleted client (this took only the first, dropped it and
            // then marked all of them deleted, so the others stayed on the server with the
            // client's data and nothing ever came back for them). Each one is marked deleted
            // only once it is really gone, so a failed drop is tried again next time.
            $numrows = $zdbh->prepare("SELECT * FROM x_mysql_databases WHERE my_acc_fk=:ac_id_pk AND my_deleted_ts IS NULL");
            $numrows->bindParam(':ac_id_pk', $rowclient['ac_id_pk']);
            $numrows->execute();
            $databases = $numrows->fetchAll();
            foreach ($databases as $rowdatabase) {
                try {
                    $my_name_vc = $zdbh->mysqlRealEscapeString($rowdatabase['my_name_vc']);
                    $delete = $zdbh->prepare("DROP DATABASE IF EXISTS `$my_name_vc`;");
                    $delete->execute();
                    $delete = $zdbh->prepare("UPDATE x_mysql_databases
						SET my_deleted_ts = :time
						WHERE my_id_pk = :my_id_pk");
                    $time = time();
                    $delete->bindParam(':time', $time);
                    $delete->bindParam(':my_id_pk', $rowdatabase['my_id_pk']);
                    $delete->execute();
                    $delete = $zdbh->prepare("DELETE FROM x_mysql_dbmap
						WHERE mm_database_fk=:my_id_pk");
                    $delete->bindParam(':my_id_pk', $rowdatabase['my_id_pk']);
                    $delete->execute();
                    if (PHP_SAPI === 'cli') { echo "Dropped database " . $rowdatabase['my_name_vc'] . " of deleted client " . $rowclient['ac_user_vc'] . "\n"; } // the daemon's log; never in a web page (it runs before a redirect)
                } catch (PDOException $e) {
                    if (PHP_SAPI === 'cli') { echo "Could not drop database " . $rowdatabase['my_name_vc'] . " of deleted client " . $rowclient['ac_user_vc'] . ": " . $e->getMessage() . "\n"; }
                }
            }
            if ($databases) {
                try {
                    $zdbh->prepare("FLUSH PRIVILEGES")->execute();
                } catch (PDOException $e) {
                }
            }
        }
    }
}

?>