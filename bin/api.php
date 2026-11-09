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
 * Updated by RusTus, 2026-11-05
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
    if (!headers_sent()) {
        http_response_code(500);
        header("Content-Type: text/xml; charset=utf-8");
    }
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<xmws>\n\t<response>1101</response>\n\t<content>Internal error: the request was not completed (the server's logs say why)</content>\n</xmws>";
});
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$apiModule = fs_protector::SanitiseFolderName(isset($_GET['m']) && is_string($_GET['m']) ? $_GET['m'] : '');
// Requests are XML sent with POST; a page opened in a browser gets nothing
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

    if ($api->wsdataarray['request'] == '') {
        $response_nomethod = new runtime_dataobject;
        $response_nomethod->addItemValue('response', '1106');
        $response_nomethod->addItemValue('content', 'No \'request\' method was recieved');
        $api->SendResponse($response_nomethod->getDataObject());
        die();
    }

    if (ws_xmws::KeyLocked()) {
        // too many wrong keys from this address lately: even the right one waits (15 minutes)
        http_response_code(429);
        $api->SendResponse(ws_xmws::Error('1104', 'Too many failed API key attempts from this address: try again later'));
    } elseif ($api->CheckServerAPIKey()) {
        // The panel's code asks who is logged in (package lists, the administrator to give a deleted
        // client's accounts to...); with nobody, those requests failed with a PHP error. The server's
        // key acts as its administrator: so does the request, in memory only (no session is started,
        // no cookie is sent), and only once the key was right
        $apiAdmin = (int) $zdbh->query("SELECT ac_id_pk FROM x_accounts WHERE ac_group_fk = 1 AND ac_enabled_in = 1 AND ac_deleted_ts IS NULL ORDER BY ac_id_pk LIMIT 1")->fetchColumn();
        $_SESSION = array('zpuid' => $apiAdmin);
        $controller = new runtime_controller();
        $controller->Init();
        if (method_exists($api, $api->wsdataarray['request'])) {
            $api->SendResponse(call_user_func(array($api, '' . $api->wsdataarray['request'] . '')));
        } else {
            $response_nomethod = new runtime_dataobject;
            $response_nomethod->addItemValue('response', '1102');
            $response_nomethod->addItemValue('content', 'Request not found');
            $api->SendResponse($response_nomethod->getDataObject());
        }
    } else {
        $response_nokey = new runtime_dataobject;
        $response_nokey->addItemValue('response', '1103');
        $response_nokey->addItemValue('content', 'Server API key authentication failed');
        $api->SendResponse($response_nokey->getDataObject());
    }
} else {
    http_response_code(404);
    $GLOBALS['xmwsAnswered'] = true;
    echo "No modular web service found using this request URL (" . htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8') . ")";
}
