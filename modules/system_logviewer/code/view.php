<?php

/**
 * System Log Viewer: follows one log live (the page script asks read.php for
 * new lines every two seconds and adds them as plain text).
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once 'modules/system_logviewer/code/logs.php';
system_logviewer_logs::RequireAdmin();

$log = system_logviewer_logs::Get(isset($_GET['id']) ? (string) $_GET['id'] : '');
$dark = !(isset($_GET['theme']) && $_GET['theme'] === 'light');

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
if (!$log) {
    http_response_code(404);
    exit('Unknown log.');
}
$h = function ($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
};
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo $h(basename($log['path'])); ?> - System Log Viewer</title>
<style>
html, body { height: 100%; margin: 0; }
body { display: flex; flex-direction: column; font: 13px/1.4 system-ui, sans-serif;
       background: <?php echo $dark ? '#111' : '#f4f4f4'; ?>; color: <?php echo $dark ? '#ccc' : '#111'; ?>; }
header { padding: 6px 10px; display: flex; gap: 14px; align-items: center; flex-wrap: wrap;
         border-bottom: 1px solid <?php echo $dark ? '#333' : '#ccc'; ?>; }
header b { font-family: ui-monospace, monospace; }
#log { flex: 1; margin: 0; padding: 8px 10px; overflow: auto; white-space: pre; tab-size: 4;
       font: 12px/1.45 ui-monospace, "DejaVu Sans Mono", monospace; }
.muted { opacity: .7; }
</style>
</head>
<body data-id="<?php echo $h($log['id']); ?>" data-readable="<?php echo $log['readable'] ? '1' : '0'; ?>">
<header>
    <b><?php echo $h($log['path']); ?></b>
    <span class="muted"><?php echo $h($log['group']); ?></span>
    <span id="size" class="muted"></span>
    <label><input type="checkbox" id="follow" checked> Follow</label>
    <label><input type="checkbox" id="pause"> Pause</label>
</header>
<pre id="log"><?php echo $log['readable'] ? '' : $h('The web server can not read this log. It belongs to root or the adm group; the panel runs as www-data, the same user as every hosted site, so giving the panel access would give it to every site too. Read it on the server: sudo tail -f ' . $log['path']); ?></pre>
<script src="../assets/logviewer.js"></script>
</body>
</html>
