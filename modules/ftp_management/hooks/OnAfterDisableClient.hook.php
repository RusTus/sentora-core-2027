<?php
/**
 * FTP Accounts module hook (OnAfterDisableClient): a client switched off: its FTP accounts can not log in.
 *
 * Syncs every client's state to ProFTPD's table right away (see code/clientstate.php);
 * the hook gets no account ID, and the sync only touches what changed. Then ends the
 * FTP sessions of switched-off logins that are still open.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-07
 */
require_once dirname(__FILE__) . '/../code/clientstate.php';
FtpSyncClientState();
// and end its FTP sessions that are still open
FtpEndSessionsNow();
