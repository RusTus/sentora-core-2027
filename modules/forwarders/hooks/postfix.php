<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 * 
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
$mailserver_db = ctrl_options::GetSystemOption('mailserver_db');
include('cnf/db.php');
$z_db_user = $user;
$z_db_pass = $pass;
try {
    $mail_db = new db_driver("mysql:host=" . $host . ";dbname=" . $mailserver_db . "", $z_db_user, $z_db_pass);
} catch (PDOException $e) {
    
}

// The deleted clients' forwarders stop forwarding: like deleting a forwarder in the panel
// (code/postfix.php), the mailbox's alias goes back to delivering to itself. The
// mailboxes hook removes the mailbox and its alias row. This used to look the alias up on
// the panel's database instead of the mail database ($mail_db), run "DELETE * FROM" and
// overwrite its own statement in the loop, so it failed and the forwarders stayed live.
if (!isset($mail_db)) {
    $mailCleanupFailed = true; // read by OnAfterDeleteClient.hook.php: nothing is marked deleted
    echo "Forwarders of deleted clients: the mail server database could not be reached; tried again next time" . fs_filehandler::NewLine();
}
foreach ((isset($mail_db) ? $deletedclients : array()) as $deletedclient) {
    $numrows = $zdbh->prepare("SELECT fw_address_vc FROM x_forwarders WHERE fw_acc_fk=:deletedclient AND fw_deleted_ts IS NULL");
    $numrows->bindParam(':deletedclient', $deletedclient);
    $numrows->execute();
    foreach ($numrows->fetchAll() as $rowforwarder) {
        $msql = $mail_db->prepare("UPDATE alias SET goto=:address, modified=NOW() WHERE address=:address2");
        $msql->bindParam(':address', $rowforwarder['fw_address_vc']);
        $msql->bindParam(':address2', $rowforwarder['fw_address_vc']);
        $msql->execute();
    }
}
?>