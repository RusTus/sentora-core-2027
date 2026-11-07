<?php

/**
 * View Site Logs: returns new lines of one of the account's site logs as JSON.
 * GET id=<vhost id>-<error|access>&offset=<bytes already shown, or -1 for the last 64 KB>
 *
 * Only for a logged-in account whose group may use the module, and only for the
 * account's own domains; the file is found from the domain, never from a path in
 * the request.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

session_start();
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
session_write_close();
if ($user) {
    // the account's language for the messages below (no request controller here)
    ui_language::ForAccount($user['userid']);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/**
 * End the request with an error.
 *
 * @param int $code The HTTP status code.
 * @param string $message The message (fixed English; translated here).
 * @return never
 */
function ulv_fail($code, $message)
{
    http_response_code($code);
    exit(json_encode(array('error' => ui_language::translate($message))));
}

if (!$user) {
    ulv_fail(403, 'Log in to the panel first.');
}
// the account's group must be allowed the module, as for the page itself
$mod = $zdbh->prepare("SELECT mo_id_pk FROM x_modules WHERE mo_folder_vc = 'user_logviewer'");
$mod->execute();
$moduleId = $mod->fetchColumn();
if (!$moduleId || !ctrl_groups::CheckGroupModulePermissions($user['usergroupid'], $moduleId)) {
    ulv_fail(403, 'This module is not available to your account.');
}
if (!isset($_GET['id']) || !is_string($_GET['id']) || !preg_match('/^([0-9]{1,10})-(error|access)$/', $_GET['id'], $m)) {
    ulv_fail(404, 'Unknown log.');
}
$q = $zdbh->prepare("SELECT vh_name_vc FROM x_vhosts WHERE vh_id_pk = :id AND vh_acc_fk = :uid AND vh_deleted_ts IS NULL");
$q->bindValue(':id', (int) $m[1]);
$q->bindValue(':uid', (int) $user['userid']);
$q->execute();
$domain = $q->fetchColumn();
if (!$domain) {
    ulv_fail(404, 'Unknown log.');
}
$path = '/var/sentora/logs/domains/' . $user['username'] . '/' . $domain . '-' . $m[2] . '.log';
if (!is_file($path) || is_link($path)) {
    ulv_fail(404, 'This log does not exist yet.');
}
if (!is_readable($path)) {
    ulv_fail(403, 'The panel can not read this log.');
}

$chunk = 65536;
clearstatcache(true, $path);
$size = (int) filesize($path);
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : -1;
$reset = false;
if ($offset < 0) {
    $offset = max(0, $size - $chunk);
} elseif ($offset > $size) {
    // The log was rotated or truncated: start again
    $offset = max(0, $size - $chunk);
    $reset = true;
}
$data = '';
if ($size > $offset) {
    $fh = fopen($path, 'rb');
    if ($fh) {
        fseek($fh, $offset);
        $data = (string) fread($fh, min($chunk, $size - $offset));
        fclose($fh);
    }
}
echo json_encode(array(
    'size' => $size,
    'next' => $offset + strlen($data),
    'reset' => $reset,
    'more' => ($offset + strlen($data)) < $size,
    'data' => $data,
), JSON_INVALID_UTF8_SUBSTITUTE);
