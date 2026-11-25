<?php

/**
 * FTP server driver for SFTPGo (Windows; ftp_php = sftpgo.php), the hooks' side: the FTP accounts of deleted clients are
 * marked deleted; the daemon (sys_winftp::Sync) then removes their SFTPGo users.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-25
 */
foreach ($deletedclients as $deletedclient) {
    $sql = $zdbh->prepare("UPDATE x_ftpaccounts SET ft_deleted_ts=:time WHERE ft_acc_fk=:deletedclient AND ft_deleted_ts IS NULL");
    $sql->bindParam(':deletedclient', $deletedclient);
    $sql->bindValue(':time', time());
    $sql->execute();
}
sys_winftp::Soon();
