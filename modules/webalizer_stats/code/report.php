<?php

/**
 * Webalizer Stats: serves a domain's report pages and graphs to that domain's
 * owner only. Reports are in /var/sentora/stats/webalizer/<user>/<domain>/,
 * outside the web root. URL: report.php/<domain>/<file>, so the report's own
 * relative links and images keep working.
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

session_start();
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
session_write_close();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function webalizer_report_deny($code, $text)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($text);
}

if (!$user || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $user['username'])) {
    webalizer_report_deny(403, 'Please log in to the control panel first.');
}
$parts = explode('/', trim(isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '', '/'));
if (count($parts) == 1 && $parts[0] !== '') {
    $parts[] = 'index.html';
}
if (count($parts) != 2) {
    webalizer_report_deny(404, 'Not found.');
}
list($domain, $file) = $parts;
if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $domain) || strpos($domain, '..') !== false
    || !preg_match('/^[A-Za-z0-9_-]+\.(html|png)$/', $file)) {
    webalizer_report_deny(404, 'Not found.');
}

// The domain must be one of this user's own domains or subdomains
global $zdbh;
$sql = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_acc_fk = :uid AND vh_name_vc = :domain AND vh_deleted_ts IS NULL");
$sql->bindParam(':uid', $user['userid']);
$sql->bindParam(':domain', $domain);
$sql->execute();
if ((int) $sql->fetchColumn() === 0 && !ctrl_statsdomains::IsPanelDomainOf($user['userid'], $domain)) {
    webalizer_report_deny(404, 'Unknown domain.');
}

$path = '/var/sentora/stats/webalizer/' . $user['username'] . '/' . $domain . '/' . $file;
if (!is_file($path)) {
    webalizer_report_deny(404, 'No statistics yet for this domain.');
}
if (substr($file, -4) === '.png') {
    header('Content-Type: image/png');
} else {
    // Report text comes from the log (referrers, search terms, user agents):
    // no scripts may run, it may only load its own graphs
    header('Content-Type: text/html; charset=iso-8859-1');
    header("Content-Security-Policy: sandbox allow-same-origin allow-popups; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
}
header('Cache-Control: private, max-age=300');
header('Content-Length: ' . filesize($path));
readfile($path);
