<?php

/**
 * Sentora File Manager (built on Tiny File Manager, see tinyfilemanager.inc.php).
 *
 * Written by RusTus, 2026-11-05
 */
define('SENTORA_FILE_MANAGER', true);
define('APP_TITLE', 'File Manager');
require __DIR__ . '/bootstrap.inc.php';

// Uploading from a web address would let the panel server fetch any URL,
// including addresses on your internal network: switched off.
// Tiny File Manager also accepts a JSON body in place of $_POST (editor saves),
// so look at both before deciding what is allowed.
$sentora_fm_json = json_decode((string) file_get_contents('php://input'), true);
$sentora_fm_req = array_merge($_REQUEST, is_array($sentora_fm_json) ? $sentora_fm_json : array());

if (!empty($sentora_fm_req['uploadurl'])) {
    header('Content-Type: application/json');
    echo json_encode(array('fail' => array('message' => 'Uploading from a web address is disabled on this server.')));
    exit;
}

// Tiny File Manager saves its Settings by rewriting a PHP file shared by every
// user, and stores the theme name unchecked: refused. Settings are fixed in config.php.
if (isset($sentora_fm_req['type']) && $sentora_fm_req['type'] === 'settings') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Settings are fixed on this server.';
    exit;
}

// The page may only load its own scripts and styles and talk to this server
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; "
    . "worker-src 'self' blob:; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'");

$GLOBALS['sentora_fm_root'] = $sentora_fm_root;
require __DIR__ . '/tinyfilemanager.inc.php';
