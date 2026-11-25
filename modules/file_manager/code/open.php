<?php

/**
 * File Manager on Windows, the panel's part: for the logged-in account, a one-minute pass signed with the account's key
 * (from Apache's settings for its PHP, configs/apache/php-accounts/NAME.conf beside the panel's vhosts file), then on to
 * /sentora-fm/NAME/, where the account's own PHP runs the file manager (app/winaccount.inc.php). On Linux the file manager
 * is opened directly (app/index.php).
 *
 * Written by RusTus, 2026-11-25
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

session_start();
ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
session_write_close();
header('Cache-Control: no-store');
if (!$user) {
    http_response_code(403);
    exit('Please log in to the control panel first.');
}
if (!sys_versions::IsWindows()) {
    header('Location: ../app/index.php');
    exit;
}
$name = sys_winphp::Name($user['username']);
$conf = dirname((string) ctrl_options::GetSystemOption('apache_vhost')) . '/php-accounts/' . $name . '.conf';
$text = ($name !== '' && is_file($conf)) ? (string) @file_get_contents($conf) : '';
if (!preg_match('/SENTORA_KEY "([0-9a-f]{64})"/', $text, $m)) {
    http_response_code(503);
    exit('Your account\'s PHP is not set up yet (it is, within 5 minutes of a new account): try again shortly.');
}
$payload = rtrim(strtr(base64_encode(json_encode(array('u' => $name, 'exp' => time() + 60, 'n' => bin2hex(random_bytes(16))))), '+/', '-_'), '=');
header('Location: /sentora-fm/' . $name . '/index.php?t=' . $payload . '.' . hash_hmac('sha256', $payload, $m[1]));
