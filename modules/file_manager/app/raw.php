<?php

/**
 * Sentora File Manager: serves a file from the user's folder for previews and
 * "direct link". Images are shown inline; everything else is a download. A
 * sandbox policy stops any HTML, SVG or script in a file from running with
 * the panel's privileges.
 */
define('SENTORA_FILE_MANAGER', true);
require __DIR__ . '/bootstrap.inc.php';

$rel = isset($_GET['f']) ? (string) $_GET['f'] : '';
if ($rel === '' || strpos($rel, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
    sentora_fm_deny(400, 'Invalid file.');
}
$file = realpath($sentora_fm_root . '/' . ltrim($rel, '/'));
if ($file === false || !is_file($file) || strpos($file, $sentora_fm_root . '/') !== 0) {
    sentora_fm_deny(404, 'File not found.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file) ?: 'application/octet-stream';
$inline = in_array($mime, array('image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon'), true);

header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode(basename($file)) . '"');
header('Cache-Control: private, no-store');
readfile($file);
