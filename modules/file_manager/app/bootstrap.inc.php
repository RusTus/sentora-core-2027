<?php

/**
 * Sentora File Manager: shared start-up for index.php and raw.php.
 *
 * 1. Requires a logged-in Sentora panel user (no separate login or password).
 * 2. Confines PHP to that user's own folder for the rest of the request with
 *    open_basedir, so even a bug in the file manager can not reach the panel's
 *    files, its database passwords or other accounts.
 * 3. Drops the panel's database connection and credentials before any file
 *    manager code runs.
 */
if (!defined('SENTORA_FILE_MANAGER')) {
    http_response_code(403);
    exit;
}

function sentora_fm_deny($status, $message)
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$sentora_fm_app = __DIR__;
chdir(dirname(dirname(dirname(__DIR__)))); // the panel root, /etc/sentora/panel
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

// --- 1. Sentora login
session_start();
$sentora_fm_uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$sentora_fm_user = ($sentora_fm_uid > 0) ? ctrl_users::GetUserDetail($sentora_fm_uid) : false;
session_write_close();
if (!$sentora_fm_user || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $sentora_fm_user['username'])) {
    sentora_fm_deny(403, 'Please log in to the control panel first.');
}

// --- The user's own folder: /var/sentora/hostdata/<username>/
$sentora_fm_hosted = rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . '/';
$sentora_fm_root = realpath($sentora_fm_hosted . $sentora_fm_user['username']);
if ($sentora_fm_root === false || !is_dir($sentora_fm_root) || strpos($sentora_fm_root . '/', realpath($sentora_fm_hosted) . '/') !== 0) {
    sentora_fm_deny(404, 'Your hosting folder does not exist yet.');
}
$sentora_fm_temp = rtrim((string) ini_get('upload_tmp_dir'), '/');
if ($sentora_fm_temp === '') {
    $sentora_fm_temp = rtrim(sys_get_temp_dir(), '/');
}
$sentora_fm_sessions = rtrim((string) session_save_path(), '/');

// --- 2. Confine PHP: only the user's folder, this app, the upload temp folder and sessions
$sentora_fm_allowed = array($sentora_fm_root . '/', $sentora_fm_app . '/', $sentora_fm_temp . '/');
if ($sentora_fm_sessions !== '') {
    $sentora_fm_allowed[] = $sentora_fm_sessions . '/';
}
$sentora_fm_basedir = implode(PATH_SEPARATOR, $sentora_fm_allowed);
ini_set('open_basedir', $sentora_fm_basedir);
if (ini_get('open_basedir') !== $sentora_fm_basedir) {
    // Fail closed: never run the file manager unconfined
    sentora_fm_deny(500, 'The file manager could not restrict itself to your folder, so it will not start.');
}
ini_set('upload_tmp_dir', $sentora_fm_temp);

// --- 3. Nothing below needs the panel's database or its passwords
unset($host, $user, $pass, $dbname, $sql_charset);
$zdbh = null;
$GLOBALS['zdbh'] = null;

// Defence in depth for everything the file manager sends
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
