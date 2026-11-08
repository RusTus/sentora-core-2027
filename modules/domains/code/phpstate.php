<?php
/**
 * Which PHP each of the logged-in account's domains really runs on, as JSON, for the badge next to
 * its PHP choice (Domains, Sub Domains: loaded after the page). Read-only; only the account's own
 * domains are answered. See sys_phpversions::LiveState().
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-08
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
    exit('{}');
}
$ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', isset($_GET['ids']) ? (string) $_GET['ids'] : ''))))), 0, 500);
$out = array();
if ($ids) {
    ui_language::ForAccount($uid);
    $q = $zdbh->prepare('SELECT vh_id_pk, vh_name_vc, vh_php_version_vc FROM x_vhosts WHERE vh_acc_fk = ? AND vh_deleted_ts IS NULL AND vh_active_in = 1 AND vh_id_pk IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $q->execute(array_merge(array($uid), $ids));
    while ($r = $q->fetch()) {
        $out[(int) $r['vh_id_pk']] = sys_phpversions::LiveState((string) $r['vh_name_vc'], (string) $r['vh_php_version_vc']);
    }
}
echo json_encode((object) $out);
