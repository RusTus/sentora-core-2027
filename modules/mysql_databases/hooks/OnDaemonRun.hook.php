<?php

/**
 * MySQL Databases module hook (OnDaemonRun): records the size of every database.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Updated by RusTus, 2026-11-06
 */

echo fs_filehandler::NewLine() . "START MySQL Databases hook" . fs_filehandler::NewLine();
echo "Calculating the total size of all MySQL databases...." . fs_filehandler::NewLine();
CalculateAllDatabaseSize();
echo "END MySQL Databases hook" . fs_filehandler::NewLine();

/*
 * Calculate the total size of all MySQL database.
 */

/**
 * Calculates the size of each database and stores it in x_mysql_databases.my_usedspace_bi.
 *
 * Connects to each database with the panel's credentials (cnf/db.php) and adds up
 * Data_length + Index_length of its tables.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function CalculateAllDatabaseSize() {
    global $zdbh;
    include('cnf/db.php');
    $z_db_user = $user;
    $z_db_pass = $pass;
    $mysqlsql = $zdbh->query("SELECT my_id_pk, my_name_vc FROM x_mysql_databases WHERE my_deleted_ts IS NULL");
    while ($database = $mysqlsql->fetch()) {
        $currentdb = new db_driver("mysql:host=$host;dbname=" . $database['my_name_vc'] . "", $z_db_user, $z_db_pass);
        $currentdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $dbsize = $currentdb->query("SHOW TABLE STATUS");
        $dbgetsize = 0;
        while ($row = $dbsize->fetch()) {
            $dbgetsize = $dbgetsize + ($row['Data_length'] + $row['Index_length']);
        }
        //$zdbh->query("UPDATE x_mysql_databases SET my_usedspace_bi = '" . $dbgetsize . "' WHERE my_id_pk =" . $database['my_id_pk'] . "");
        $numrows = $zdbh->prepare("UPDATE x_mysql_databases SET my_usedspace_bi = :dbgetsize WHERE my_id_pk =:my_id_pk");
        $numrows->bindParam(':dbgetsize', $dbgetsize);
        $numrows->bindParam(':my_id_pk', $database['my_id_pk']);
        $numrows->execute();
        //echo "Database found: " . $database['my_name_vc'] . " - " . $dbgetsize . " \n";
    }
}

?>