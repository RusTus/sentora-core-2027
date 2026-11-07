<?php
/**
 * FTP Accounts module hook (OnAfterEnableClient): a client switched on again: their FTP accounts can log in again.
 *
 * Syncs every client's state to ProFTPD's table right away (see code/clientstate.php);
 * the hook gets no account ID, and the sync only touches what changed.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
require_once dirname(__FILE__) . '/../code/clientstate.php';
FtpSyncClientState();
