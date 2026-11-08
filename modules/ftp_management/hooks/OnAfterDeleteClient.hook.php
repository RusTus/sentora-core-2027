<?php

/**
 * FTP Accounts module: OnAfterDeleteClient hook.
 *
 * Lists the deleted accounts and includes the FTP server specific hook file
 * (ftp_php setting), which removes their FTP users.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Updated by RusTus, 2026-11-06
 */
DeleteFTPForDeletedClient();

/**
 * Remove the FTP users of deleted accounts through the FTP server specific hook file.
 *
 * The included file uses $deletedclients (the IDs of the deleted accounts).
 *
 * @global db_driver $zdbh The panel's database connection.
 * @global runtime_controller $controller The request controller (unused).
 * @return void
 */
function DeleteFTPForDeletedClient() {
    global $zdbh;
    global $controller;
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

    // Include FTP server specific file here.
    if (file_exists("modules/ftp_management/hooks/" . ctrl_options::GetSystemOption('ftp_php') . "")) {
        include("modules/ftp_management/hooks/" . ctrl_options::GetSystemOption('ftp_php') . "");
    }
}

?>