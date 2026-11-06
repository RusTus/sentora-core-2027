<?php

/**
 * Sentora guard for Pimped Apache Status (see VERSION).
 *
 * - Only a logged-in panel administrator may use it.
 * - Every request parameter is checked against what the app needs; anything
 *   else is dropped. That closes the original's holes: ?url= made the server
 *   fetch any address, ?view= and ?skin= went straight into include(), and
 *   ?filter=/?format= were echoed back unescaped.
 * - The server list is fixed to this server's own /server-status.
 */
if (basename($_SERVER['SCRIPT_FILENAME']) !== 'index.php') {
    http_response_code(403);
    exit;
}

$sentora_as_panel = dirname(dirname(dirname(__DIR__)));
$sentora_as_cwd = getcwd();
chdir($sentora_as_panel);
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
session_start();
$sentora_as_uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$sentora_as_user = ($sentora_as_uid > 0) ? ctrl_users::GetUserDetail($sentora_as_uid) : false;
session_write_close();
unset($host, $user, $pass, $dbname, $sql_charset);
$zdbh = null;
chdir($sentora_as_cwd);
if (!$sentora_as_user || (int) $sentora_as_user['usergroupid'] !== 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Apache Status is only available to panel administrators.');
}

$sentora_as_views = array('serverinfos.php', 'performance-check.php', 'allrequests.php', 'original.php', 'utilization.php', 'help.php');
$sentora_as_skins = array('skin-blue', 'skin-black', 'skin-red', 'skin-yellow', 'skin-purple', 'skin-green',
    'skin-blue-light', 'skin-black-light', 'skin-red-light', 'skin-yellow-light', 'skin-purple-light', 'skin-green-light');
$sentora_as_servers = json_decode((string) file_get_contents(__DIR__ . '/config/config_servers.json'), true);
$sentora_as_clean = array();
foreach ($_GET as $sentora_as_k => $sentora_as_v) {
    if (!is_string($sentora_as_v)) {
        continue;
    }
    switch ($sentora_as_k) {
        case 'view':
            $ok = in_array($sentora_as_v, $sentora_as_views, true);
            break;
        case 'skin':
            $ok = in_array($sentora_as_v, $sentora_as_skins, true);
            break;
        case 'lang':
            $ok = in_array($sentora_as_v, array('en', 'de'), true);
            break;
        case 'reload':
            $ok = ctype_digit($sentora_as_v) && (int) $sentora_as_v <= 3600;
            break;
        case 'group':
            $ok = is_array($sentora_as_servers) && array_key_exists($sentora_as_v, $sentora_as_servers);
            break;
        case 'servers':
            $ok = true;
            foreach (explode(',', $sentora_as_v) as $sentora_as_s) {
                $found = false;
                foreach ((array) $sentora_as_servers as $sentora_as_g) {
                    if (isset($sentora_as_g['servers'][$sentora_as_s])) {
                        $found = true;
                    }
                }
                $ok = $ok && $found;
            }
            break;
        case 'filter':
        case 'format':
            $ok = preg_match('/^[A-Za-z0-9_-]{1,40}$/', $sentora_as_v) === 1;
            break;
        default:
            $ok = false; // url, action and anything else
    }
    if ($ok) {
        $sentora_as_clean[$sentora_as_k] = $sentora_as_v;
    }
}
$_GET = $sentora_as_clean;
$_REQUEST = $sentora_as_clean;
$_POST = array();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'");
