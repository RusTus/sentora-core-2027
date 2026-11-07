<?php

/**
 * Apache Status data (administrators only), as JSON.
 *
 * Reads Apache's mod_status pages from this server (http://127.0.0.1/server-status, which
 * the panel's vhost allows from the server itself only, with ExtendedStatus On) and
 * returns them parsed (code/statusparse.php). The page turns the counters into rates
 * between two reads, so nothing is kept on the server.
 *
 * The request lists hold client addresses and URLs (query strings included), so this is
 * for administrators only, like the module.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once 'modules/apache_status/code/statusparse.php';

session_start();
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
session_write_close();
// messages in the account's language (no request controller here)
if ($user) {
    ui_language::ForAccount($user['userid']);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!$user || (int) $user['usergroupid'] !== 1) {
    http_response_code(403);
    exit(json_encode(array('error' => ui_language::translate('Administrators only.'))));
}

/**
 * Fetch one of Apache's status pages from this server.
 *
 * @param string $query '' for the HTML page, '?auto' for the summary.
 * @return array{0: int, 1: string} The HTTP status code (0 when Apache did not answer) and the body.
 */
function apst_fetch($query)
{
    $ch = curl_init('http://127.0.0.1/server-status' . $query);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 8388608));
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($code, $body);
}

list($codeAuto, $auto) = apst_fetch('?auto');
if ($codeAuto !== 200 || strpos($auto, 'Total Accesses') === false) {
    http_response_code(502);
    exit(json_encode(array('error' => str_replace('%s', (string) $codeAuto, ui_language::translate("Apache's status page did not answer (HTTP %s). It needs mod_status with ExtendedStatus On, reachable at http://127.0.0.1/server-status.")))));
}
list($codeHtml, $html) = apst_fetch('');
$out = apst_status($auto, $codeHtml === 200 ? $html : '');
$out['t'] = microtime(true);
// what this read itself added to Apache's counters (two requests, these bytes), so the
// page can leave the panel's own reads out of the rates
$out['self'] = array('requests' => 2, 'bytes' => strlen($auto) + strlen($html));
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
