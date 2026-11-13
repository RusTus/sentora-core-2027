<?php

/**
 * Backup Config: how far a restore or a reading of the backups is (Restore tab), or how the off-server
 * backup stands (?what=offsite: adding the node, a connection test, a copy and its progress), as JSON,
 * for the tabs to follow in the background (every 2 seconds while something is queued or runs).
 * Panel administrators only; only the progress is answered (the step, how much was copied), nothing
 * of the backups or the settings.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-13
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

session_start();
ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
session_write_close();
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if (!$user || (int) $user['usergroupid'] !== 1) {
    http_response_code(403);
    exit('{}');
}
if (isset($_GET['what']) && $_GET['what'] === 'offsite') {
    require_once 'modules/backup_admin/code/controller.ext.php';
    exit(json_encode(module_controller::OffsiteProgress()));
}
$st = json_decode((string) @file_get_contents('/var/lib/sentora/panel-restore.json'), true);
$st = is_array($st) ? $st : array();
$queued = is_file('/var/lib/sentora/tmp/panel-restore-request.json');
echo json_encode(array(
    'active' => $queued || !empty($st['running']) || !empty($st['listing']),
    'queued' => $queued,
    'listing' => !empty($st['listing']),
    'step' => (string) ($st['step'] ?? ''),
    'pct' => max(0, min(100, (int) ($st['progress_pct'] ?? 0))),
    'bytes' => (int) ($st['progress_bytes'] ?? 0),
    'rate' => preg_match('/^[0-9.]+[kMGT]?B\/s$/', (string) ($st['progress_rate'] ?? '')) ? (string) $st['progress_rate'] : '',
));
