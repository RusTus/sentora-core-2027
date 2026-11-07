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



// Deleting hMail Forwarder
/**
 * The mailbox's delivery list in Postfix, rebuilt from all its forwarders in the panel, so that
 * forwarders of one mailbox no longer overwrite each other: each used to replace the whole list,
 * and deleting one set it back to the mailbox alone, while the panel still listed the others.
 * The mailbox keeps its own copy when it has no forwarder left, or when any forwarder keeps one.
 *
 * @param db_driver $mail_db The mail server's database.
 * @param db_driver $zdbh The panel's database.
 * @param string $mailbox The forwarded mailbox address.
 * @param array<int, array{0: string, 1: int}> $extra A forwarder about to be added: [destination, keep a copy].
 * @param int $skip The ID of a forwarder about to be deleted (0: none).
 * @return void
 */
if (!function_exists('SentoraForwardersGoto')) {
    function SentoraForwardersGoto($mail_db, $zdbh, $mailbox, $extra, $skip)
    {
        $q = $zdbh->prepare("SELECT fw_id_pk, fw_destination_vc, fw_keepmessage_in FROM x_forwarders WHERE fw_address_vc = :a AND fw_deleted_ts IS NULL");
        $q->execute(array(':a' => $mailbox));
        $list = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int) $r['fw_id_pk'] !== (int) $skip) {
                $list[] = array((string) $r['fw_destination_vc'], (int) $r['fw_keepmessage_in']);
            }
        }
        $list = array_merge($list, $extra);
        $keep = !$list;
        $goto = array();
        foreach ($list as $f) {
            if ($f[1] == 1) {
                $keep = true;
            }
            if (!in_array(strtolower($f[0]), $goto, true)) {
                $goto[] = strtolower($f[0]);
            }
        }
        if ($keep) {
            array_unshift($goto, strtolower($mailbox));
        }
        $sql = $mail_db->prepare("UPDATE alias SET goto = :g, modified = NOW() WHERE address = :a");
        $sql->execute(array(':g' => implode(',', $goto), ':a' => $mailbox));
    }
}

if (!fs_director::CheckForEmptyValue(self::$delete)) {
    $numrows = $mail_db->prepare("SELECT address FROM alias WHERE address=:fw_address_vc");
    $numrows->bindParam(':fw_address_vc', $rowforwarder['fw_address_vc']);
    $numrows->execute();
    $result = $numrows->fetch();
    if ($result) {
        SentoraForwardersGoto($mail_db, $zdbh, $rowforwarder['fw_address_vc'], array(), (int) $rowforwarder['fw_id_pk']);
    }
	

   $domaincheck = explode("@", $rowforwarder['fw_address_vc']);
   $sql = $mail_db->prepare("SELECT * FROM mailbox WHERE domain=:domain");
   $sql->bindParam(':domain', $domaincheck[1]);
   $sql->execute();
   $mailboxresult = $sql->fetch();
   $sql = $mail_db->prepare("SELECT * FROM alias WHERE domain=:domain");
   $sql->bindParam(':domain', $domaincheck[1]);
   $sql->execute();
   $aliasresult = $sql->fetch();

   if (!$mailboxresult && !$aliasresult) {
       $sql = $mail_db->prepare("DELETE FROM domain WHERE domain=:domain");
       $sql->bindParam(':domain', $domaincheck[1]);
       $sql->execute();
   }
}



// Adding hMail Forwarder
if (!fs_director::CheckForEmptyValue(self::$create)) {
    $numrows = $mail_db->prepare("SELECT address FROM alias WHERE address=:address");
    $numrows->bindParam(':address', $address);
    $numrows->execute();
    $result = $numrows->fetch();
    if ($result) {
        SentoraForwardersGoto($mail_db, $zdbh, $address, array(array($destination, (int) $keepmessage)), 0);
    }
}
?>