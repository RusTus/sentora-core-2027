<?php

/**
 * GoAccess report viewer: the reports of the account's own domains (administrators:
 * all of them). The report is GoAccess's own page (charts and tables drawn by its
 * scripts); it is shown sandboxed: its scripts run, but it can not reach the panel,
 * the network or any other site. ?theme=dark or light starts it in the colours that
 * match the panel.
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once 'modules/goaccess_stats/code/goaccess.php';

session_start();
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
session_write_close();
// messages in the account's language (no request controller here)
if ($user) {
    ui_language::ForAccount($user['userid']);
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Type: text/html; charset=utf-8');
// The report's scripts build their tables from templates with eval; in this sandbox
// (no origin of its own, no network: connect-src falls under default-src 'none')
// they can still only draw the report
// No pop-ups, no forms, only the panel may frame it
header("Content-Security-Policy: sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval'; style-src 'unsafe-inline'; img-src data:; font-src data:; form-action 'none'; base-uri 'none'; frame-ancestors 'self'");
header('X-Frame-Options: SAMEORIGIN');

if (!$user) {
    http_response_code(403);
    exit(htmlspecialchars(ui_language::translate('Please log in to the control panel first.'), ENT_QUOTES, 'UTF-8'));
}
// Only reports of the account's own domains (administrators: all of them)
$key = isset($_GET['r']) ? (string) $_GET['r'] : '';
$file = goaccess_helper::ReportFile($key);
if ($file === false || !array_key_exists($key, goaccess_helper::ReportsFor($user))) {
    http_response_code(404);
    exit(htmlspecialchars(ui_language::translate('Unknown report.'), ENT_QUOTES, 'UTF-8'));
}
if (!is_file($file)) {
    echo '<p style="font-family: sans-serif">' . htmlspecialchars(ui_language::translate('No report yet. Reports are made every hour from the web server logs, once there have been visitors.'), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}
// The report keeps its display settings in browser storage, which a sandboxed page
// (no origin of its own, so it can not reach the panel) is not given: it gets an
// in-memory stand-in instead, defined before the report's own scripts run. When the
// panel asks for dark or light, the stand-in starts with GoAccess's theme preference
// set to match (GoAccess reads 'AppPrefs' from it; its own theme menu still works)
$theme = isset($_GET['theme']) ? (string) $_GET['theme'] : '';
$seed = $theme === 'dark' ? '{"theme":"darkBlue"}' : ($theme === 'light' ? '{"theme":"bright"}' : '');
$shim = '<script>(function(){function M(s){var d=s?{AppPrefs:s}:{};return{getItem:function(k){return Object.prototype.hasOwnProperty.call(d,k)?d[k]:null;},'
    . 'setItem:function(k,v){d[k]=String(v);},removeItem:function(k){delete d[k];},clear:function(){d={};},key:function(i){return Object.keys(d)[i]||null;},'
    . 'get length(){return Object.keys(d).length;}};}["localStorage","sessionStorage"].forEach(function(n){var ok=false;try{ok=!!window[n];}catch(e){}'
    . 'if(!ok){try{Object.defineProperty(window,n,{value:M(n==="localStorage"?' . json_encode($seed) . ':""),configurable:true});}catch(e){}}});})();</script>'
    // its height, for the panel to size the frame (etc/lib/sentora/frame-height.js); only a number is sent
    . '<script>(function(){var last=0;function send(){var b=document.body,e=document.documentElement;if(!b||!e||window.parent===window)return;'
    . 'var h=Math.max(b.scrollHeight,e.scrollHeight);if(Math.abs(h-last)<3)return;last=h;window.parent.postMessage({snFrameHeight:h},"*");}'
    . 'window.addEventListener("load",send);setInterval(send,1000);document.addEventListener("click",function(){setTimeout(send,300);});})();</script>';
// Streamed (a report for many domains can be large): the stand-in goes right after <head>
$fh = fopen($file, 'rb');
$start = (string) fread($fh, 8192);
$pos = stripos($start, '<head>');
echo $pos === false ? $shim . $start : substr($start, 0, $pos + 6) . $shim . substr($start, $pos + 6);
fpassthru($fh);
fclose($fh);
