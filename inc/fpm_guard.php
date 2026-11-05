<?php

/**
 * Runs before every script in the panel's PHP-FPM pool (php_admin_value
 * [auto_prepend_file] in pool.d/sentora.conf). Only the panel's own files may run
 * there: a hosted site can otherwise point its .htaccess at the panel's socket
 * (SetHandler "proxy:unix:/run/php/sentora-panel.sock|..." or RewriteRule [H=...])
 * and have its own PHP file run as the panel's user.
 */
$sentoraPanelRoot = dirname(__DIR__) . '/';
$sentoraScript = isset($_SERVER['SCRIPT_FILENAME']) ? realpath((string) $_SERVER['SCRIPT_FILENAME']) : false;
if ($sentoraScript === false || strpos($sentoraScript, $sentoraPanelRoot) !== 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 Forbidden\n");
}
unset($sentoraPanelRoot, $sentoraScript);
