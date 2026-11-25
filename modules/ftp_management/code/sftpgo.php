<?php

/**
 * FTP server driver for SFTPGo (Windows; ftp_php = sftpgo.php): included after an FTP account was made, changed, had
 * its password reset or was deleted. The account is already saved in x_ftpaccounts; the daemon (SYSTEM) brings SFTPGo's
 * users in line with it (sys_winftp::Sync), so this page only asks for a daemon run.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-25
 */
sys_winftp::Soon();
