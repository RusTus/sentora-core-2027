<?php

/**
 * Server Monitor's Hardening card (administrators only): the last report of sentora-post-install-check
 * (/var/sentora/logs/hardening.json, written by root through zsudo's queue: hourly from the daemon, or
 * when "Run the check now" is pressed) and its history (hardening-history.json), as JSON.
 *
 * Written by RusTus, 2026-11-11
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

session_start();
ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
$asked = isset($_SESSION['sm_hard_asked']) ? (int) $_SESSION['sm_hard_asked'] : 0;
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!$user || (int) $user['usergroupid'] !== 1) {
    http_response_code(403);
    exit('{"error":"Administrators only."}');
}

$read = function ($f) {
    if (!is_file($f) || is_link($f) || filesize($f) > 4194304) {
        return null;
    }
    $j = json_decode((string) @file_get_contents($f), true);
    return is_array($j) ? $j : null;
};
$report = $read('/var/sentora/logs/hardening.json');
$history = $read('/var/sentora/logs/hardening-history.json');
echo json_encode(array(
    'report' => $report,
    'history' => is_array($history) ? array_values($history) : array(),
    'asked' => $asked,
    'now' => time(),
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
