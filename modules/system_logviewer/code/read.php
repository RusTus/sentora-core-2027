<?php

/**
 * System Log Viewer: returns new lines of one log as JSON.
 * GET id=<log id>&offset=<bytes already shown, or -1 for the last 64 KB>
 *
 * Written by RusTus, 2026-11-07
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once 'modules/system_logviewer/code/logs.php';
system_logviewer_logs::RequireAdmin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$log = system_logviewer_logs::Get(isset($_GET['id']) ? (string) $_GET['id'] : '');
if (!$log) {
    http_response_code(404);
    exit(json_encode(array('error' => ui_language::translate('Unknown log.'))));
}
if (!$log['readable']) {
    http_response_code(403);
    exit(json_encode(array('error' => ui_language::translate('The web server can not read this log.'))));
}

$chunk = 65536;
clearstatcache(true, $log['path']);
$size = (int) filesize($log['path']);
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : -1;
$reset = false;
if ($offset < 0) {
    $offset = max(0, $size - $chunk);
} elseif ($offset > $size) {
    // The log was rotated or truncated: start again
    $offset = max(0, $size - $chunk);
    $reset = true;
}
$data = '';
if ($size > $offset) {
    $fh = fopen($log['path'], 'rb');
    if ($fh) {
        fseek($fh, $offset);
        $data = (string) fread($fh, min($chunk, $size - $offset));
        fclose($fh);
    }
}
echo json_encode(array(
    'size' => $size,
    'next' => $offset + strlen($data),
    'reset' => $reset,
    'more' => ($offset + strlen($data)) < $size,
    'data' => $data,
), JSON_INVALID_UTF8_SUBSTITUTE);
