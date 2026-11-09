<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * The the main ZPanel(X) (M)odular (W)eb (S)ervice controller.
 * @package zpanelx
 * @subpackage core -> api
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Sentora: https://<panel>/api/<module>, XML or JSON sent with POST (ws_xmws). In order: the module
 * must be on and have an API; the address must not be locked out for wrong keys; the key must be
 * right (the server's own, or one from the API Access page, from an address it allows); a
 * reseller's key may use only the modules its group has; the request must be one the module's own
 * API class defines. The request then acts as the key's owner, in memory only.
 *
 * Updated by RusTus, 2026-11-09
 */
$rawPath = str_replace("\\", "/", dirname(__FILE__));
$rootPath = str_replace("/bin", "/", $rawPath);
chdir($rootPath);

require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

debug_phperrors::SetMode('prod');
// Anything printed along the way (blank lines after a module file's closing tag, a warning, a
// database error from a hook) is held back: the answer is always the XML alone, and never shows
// the server's internals (ws_xmws::SendResponse() drops it)
ob_start();
// and a request that stopped before its answer (a database error ends the script with the query and
// a stack trace on screen) gets a plain error instead of that text
register_shutdown_function(function () {
    if (!empty($GLOBALS['xmwsAnswered'])) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ctrl_apikeys::Log(ws_xmws::$keyId, ws_xmws::$actor, ws_xmws::$module, ws_xmws::$request, '1101', 'stopped with an error');
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (ws_xmws::$json) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array('version' => ws_xmws::VERSION, 'response' => '1101', 'content' => 'Internal error: the request was not completed (the server\'s logs say why)'));
        return;
    }
    if (!headers_sent()) {
        header("Content-Type: text/xml; charset=utf-8");
    }
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<xmws>\n\t<version>" . ws_xmws::VERSION . "</version>\n\t<response>1101</response>\n\t<content>Internal error: the request was not completed (the server's logs say why)</content>\n</xmws>";
});
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$apiModule = fs_protector::SanitiseFolderName(isset($_GET['m']) && is_string($_GET['m']) ? $_GET['m'] : '');
// Requests are XML or JSON sent with POST; a page opened in a browser gets nothing
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    $GLOBALS['xmwsAnswered'] = true;
    exit;
}
// A module switched off in Module Admin answers no API requests either
$apiOn = $apiModule !== '' && (int) $zdbh->query("SELECT COUNT(*) FROM x_modules WHERE mo_folder_vc = " . $zdbh->quote($apiModule) . " AND mo_enabled_en = 'true'")->fetchColumn() > 0;

if ($apiOn && file_exists('modules/' . $apiModule . '/code/webservice.ext.php')) {
    include 'modules/' . $apiModule . '/code/controller.ext.php';
    include 'modules/' . $apiModule . '/code/webservice.ext.php';
    $api = new webservice();
    ws_xmws::$module = $apiModule;
    ws_xmws::$request = substr(preg_replace('/[^A-Za-z0-9_]/', '', (string) $api->wsdataarray['request']), 0, 60);
    if ($api->wsdataarray['request'] == '') {
        $api->SendResponse(ws_xmws::Error('1106', 'No \'request\' method was recieved'));
        die();
    }
    if (ws_xmws::KeyLocked()) {
        // too many wrong keys from this address lately: even the right one waits (15 minutes)
        http_response_code(429);
        $api->SendResponse(ws_xmws::Error('1104', 'Too many failed API key attempts from this address: try again later'));
    } elseif (!$api->CheckServerAPIKey()) {
        $why = ws_xmws::$note;
        $answer = ws_xmws::Error('1103', 'Server API key authentication failed');
        if ($why !== '') {
            // (the log keeps why a right key was refused: the address)
            ws_xmws::$note = $why;
        }
        $api->SendResponse($answer);
    } else {
        // A reseller's key: only the modules the reseller's own group has
        $mayUse = ws_xmws::$admin;
        if (!$mayUse) {
            $q = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts a JOIN x_permissions p ON p.pe_group_fk = a.ac_group_fk
                JOIN x_modules m ON m.mo_id_pk = p.pe_module_fk WHERE a.ac_id_pk = :u AND m.mo_folder_vc = :m");
            $q->execute(array(':u' => ws_xmws::$actor, ':m' => $apiModule));
            $mayUse = (int) $q->fetchColumn() > 0;
        }
        // Only the requests the module's own API class defines (never the base class's own
        // methods, such as RequireUserAuth or SendResponse, which a request could name)
        $name = (string) $api->wsdataarray['request'];
        $isRequest = false;
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,59}$/', $name) && method_exists($api, $name)) {
            $m = new ReflectionMethod($api, $name);
            $isRequest = $m->isPublic() && !$m->isStatic() && strcasecmp($m->getDeclaringClass()->getName(), 'webservice') === 0;
        }
        if (!$mayUse) {
            $api->SendResponse(ws_xmws::Error('1108', 'This key may not use the ' . $apiModule . ' module'));
        } elseif (!$isRequest) {
            $api->SendResponse(ws_xmws::Error('1102', 'Request not found'));
        } else {
            // The panel's code asks who is logged in (package lists, the administrator to give a
            // deleted client's accounts to...): the request acts as the key's owner (the server's key:
            // the administrator), in memory only (no session is started, no cookie is sent)
            $_SESSION = array('zpuid' => ws_xmws::$actor);
            $controller = new runtime_controller();
            $controller->Init();
            $api->SendResponse(call_user_func(array($api, $name)));
        }
    }
} else {
    http_response_code(404);
    $GLOBALS['xmwsAnswered'] = true;
    echo "No modular web service found using this request URL (" . htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8') . ")";
}
