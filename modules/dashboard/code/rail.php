<?php

/**
 * The widget rail (the bar on the right of every panel page, assets/rail.js): the logged-in
 * account's dashboard widgets, in their dashboard order, drawn small and one above the other.
 * Answers an HTML fragment; the page asks for it after it has loaded, and again every two minutes
 * while the rail is open, so a closed rail costs nothing.
 *
 * The same widgets, and the same rules, as the dashboard: only the account's own, only ones it may
 * have (dashboard_widgets::Available), and nothing for an account that may not open the dashboard.
 *
 * Written by RusTus, 2026-11-12
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once dirname(__FILE__) . '/controller.ext.php';

session_start();
ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
// (the widgets read the logged-in account the way a panel page does, through the request controller:
// one that knows only this session, with nothing posted or asked for, so no module or action runs)
$controller = new runtime_controller();
Closure::bind(function () {
    $this->vars_get = array(array());
    $this->vars_post = array(array());
    $this->vars_session = array($_SESSION);
    $this->vars_cookie = array(array());
}, $controller, 'runtime_controller')();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$me = $uid > 0 ? ctrl_users::GetUserDetail($uid) : false;
// (only a page of the panel asks: a request made by another site has no X-Requested-With)
if (!$me || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'sentora-rail' || !dashboard_widgets::CanOpen($me, 'dashboard')) {
    http_response_code(403);
    exit;
}

$all = dashboard_widgets::All();
$layout = module_controller::Layout();
$out = '';
foreach ($layout as $id) {
    $look = module_controller::Look($id);
    $out .= '<section class="dw-card dw-mini" data-id="' . dashboard_widgets::h($id) . '" style="--c:' . $look[1] . '">'
        . '<header><h4>' . module_controller::Icon($look[0]) . dashboard_widgets::h(ui_language::translate($all[$id][0])) . '</h4></header>'
        . '<div class="dw-body">' . dashboard_widgets::Render($id, $me) . '</div></section>';
}
session_write_close();
if ($out === '') {
    $out = '<p class="dw-rail-empty">' . dashboard_widgets::h(ui_language::translate('No widgets yet: add some on your dashboard.')) . '</p>';
}
echo dashboard_widgets::Styles($layout) . $out
    . '<a class="dw-rail-arrange" href="./?module=dashboard">' . dashboard_widgets::h(ui_language::translate('Arrange them on your dashboard')) . ' &rarr;</a>';
