<?php
/**
 * The progress of the logged-in account's backup, as JSON, for the Backup page
 * (assets/progress.js asks every few seconds while one is running). Read-only.
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
session_start();
ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($uid < 1) {
    http_response_code(403);
    exit('{"state":"none"}');
}
echo json_encode(sys_backup::Progress($uid));
