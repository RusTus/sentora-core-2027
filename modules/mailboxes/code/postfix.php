<?php

/**
 * Mailboxes module: Postfix/Dovecot mail server include file.
 *
 * Included by module_controller::ExecuteAddMailbox(), ExecuteDeleteMailbox() and
 * ExecuteUpdateMailbox() (the mailserver_php option); writes the mail server's
 * database according to the controller's $create, $delete and $update flags.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
/*
 * Mailbox passwords are stored as {SHA512-CRYPT} hashes (salted SHA-512 crypt,
 * 50,000 rounds), the scheme named in front as Dovecot expects. Dovecot, SOGo and
 * Roundcube's password change all read and write that format; mailboxes still on the
 * old {PLAIN-MD5} keep working until their password is next changed.
 */
if (!function_exists('sentora_mailbox_hash')) {
    /**
     * Hash a mailbox password for Dovecot as {SHA512-CRYPT} with a random salt.
     *
     * @param string $password The plain password.
     * @return string The hash, prefixed with "{SHA512-CRYPT}".
     */
    function sentora_mailbox_hash($password)
    {
        $salt = substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16);
        return '{SHA512-CRYPT}' . crypt((string) $password, '$6$rounds=50000$' . $salt . '$');
    }
}


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
    // to the PHP log only: printed into the page, the exception's trace showed the database login
    error_log('Sentora mailboxes: the mail server database could not be reached: ' . $e->getMessage());
}

// Adding PostFix Mailboxes
if (!fs_director::CheckForEmptyValue(self::$create)) {
    //$result = $mail_db->query("SELECT domain FROM domain WHERE domain='" . $domain . "'")->Fetch();
    $numrows = $mail_db->prepare("SELECT domain FROM domain WHERE domain=:domain");
    $numrows->bindParam(':domain', $domain);
    $numrows->execute();
    $result = $numrows->fetch();
    if (!$result) {
        $sql = $mail_db->prepare("INSERT INTO domain (  domain,
                                                        description,
                                                        aliases,
                                                        mailboxes,
                                                        maxquota,
                                                        quota,
                                                        transport,
                                                        backupmx,
                                                        created,
                                                        modified,
                                                        active) VALUES (
                                                        :domain,
                                                        '',
                                                        0,
                                                        0,
                                                        0,
                                                        0,
                                                        '',
                                                        0,
                                                        NOW(),
                                                        NOW(),
                                                        '1')");
        $sql->bindParam(':domain', $domain);
        $sql->execute();
    }
    //$result = $mail_db->query("SELECT username FROM mailbox WHERE username='" . $fulladdress . "'")->Fetch();
    $numrows = $mail_db->prepare("SELECT username FROM mailbox WHERE username=:fulladdress");
    $numrows->bindParam(':fulladdress', $fulladdress);
    $numrows->execute();
    $result = $numrows->fetch();
    if (!$result) {
        $sql = $mail_db->prepare("INSERT INTO mailbox (username,
								 							password,
														 	name,
															maildir,
														 	local_part,
														 	quota,
														 	domain,
														 	created,
														 	modified,
														 	active) VALUES (
														 	:fulladdress,
														 	:password,
														 	:address,
														 	:location,
														 	:address2,
														 	:maxMail,
														 	:domain,
														 	NOW(),
														 	NOW(),
														 	'1')");
        $password = sentora_mailbox_hash($password);
        $location = $domain . "/" . $address . "/";
        $maxMail = ctrl_options::GetSystemOption('max_mail_size');

        $sql->bindParam(':password', $password);
        $sql->bindParam(':address', $address);
        $sql->bindParam(':fulladdress', $fulladdress);
        $sql->bindParam(':location', $location);
        $sql->bindParam(':address2', $address);
        $sql->bindParam(':maxMail', $maxMail);
        $sql->bindParam(':domain', $domain);
        $sql->execute();
        $sql = $mail_db->prepare("INSERT INTO alias  (address,
														 	goto,
														 	domain,
															created,
														 	modified,
														 	active) VALUES (
														 	:fulladdress,
														 	:fulladdress2,
														 	:domain,
														 	NOW(),
														 	NOW(),
														 	'1')");
        $sql->bindParam(':domain', $domain);
        $sql->bindParam(':fulladdress', $fulladdress);
        $sql->bindParam(':fulladdress2', $fulladdress);
        $sql->execute();
    }
}

// Deleting PostFix Mailboxes
if (!fs_director::CheckForEmptyValue(self::$delete)) {
    $sql = $mail_db->prepare("DELETE FROM mailbox WHERE username=:mb_address_vc");
    $sql->bindParam(':mb_address_vc', $rowmailbox['mb_address_vc']);
    $sql->execute();
    $sql = $mail_db->prepare("DELETE FROM alias WHERE address=:mb_address_vc");
    $sql->bindParam(':mb_address_vc', $rowmailbox['mb_address_vc']);
    $sql->execute();
    // the aliases that delivered to it, when asked to delete them too
    $sql = $mail_db->prepare("DELETE FROM alias WHERE address=:address");
    foreach (isset($dropAliasRows) ? $dropAliasRows : array() as $r) {
        $sql->execute(array(':address' => $r['al_address_vc']));
    }
    // the distribution lists it was on, when asked to take it off them (this exact address only)
    $get = $mail_db->prepare("SELECT goto FROM alias WHERE address=:address");
    $set = $mail_db->prepare("UPDATE alias SET goto=:goto, modified=NOW() WHERE address=:address");
    foreach (isset($leaveListRows) ? $leaveListRows : array() as $r) {
        $get->execute(array(':address' => $r['dl_address_vc']));
        $goto = $get->fetchColumn();
        if ($goto === false) {
            continue;
        }
        $keep = array();
        foreach (explode(',', (string) $goto) as $a) {
            $a = trim($a);
            if ($a !== '' && strcasecmp($a, (string) $rowmailbox['mb_address_vc']) !== 0) {
                $keep[] = $a;
            }
        }
        $set->execute(array(':goto' => implode(',', $keep), ':address' => $r['dl_address_vc']));
    }
}

//Saving PostFix Mailboxes
if (!fs_director::CheckForEmptyValue(self::$update)) {
    if (!fs_director::CheckForEmptyValue($password)) {
        $sql = $mail_db->prepare("UPDATE mailbox SET password=:password, modified=NOW() WHERE username=:mb_address_vc");
        $password = sentora_mailbox_hash($password);
        $sql->bindParam(':password', $password);
        $sql->bindParam(':mb_address_vc', $rowmailbox['mb_address_vc']);
        $sql->execute();
    }
    $sql = $mail_db->prepare("UPDATE mailbox SET active=:enabled, modified=NOW() WHERE username=:mb_address_vc");
    $sql->bindParam(':enabled', $enabled);
    $sql->bindParam(':mb_address_vc', $rowmailbox['mb_address_vc']);
    $sql->execute();
}
?>