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
<html lang="en" class="lv-window<?php echo $dark ? ' lv-dark' : ''; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $h(basename($log['path'])); ?> - System Log Viewer</title>
<link rel="stylesheet" href="../assets/logviewer.css">
</head>
<body>
<div class="lv" id="lv" data-read="read.php" data-id="<?php echo $h($log['id']); ?>" data-path="<?php echo $h($log['path']); ?>" data-group="<?php echo $h($log['group']); ?>"
     data-readable="<?php echo $log['readable'] ? '1' : '0'; ?>"
     data-locked="The panel can not read this log: it belongs to root or the adm group, and the panel runs as its own user, with no more rights than it needs. Read it on the server as root, for example:"
     data-rotated="The log was rotated or truncated; showing its end." data-ago="ago" data-lines="lines" data-shown="shown">
    <section class="lv-main">
        <div class="lv-head">
            <div class="lv-title">
                <svg class="lv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/></svg>
                <div><b class="lv-path"></b><span class="lv-meta"></span></div>
            </div>
            <div class="lv-tools">
                <span class="lv-live"><i></i><span class="lv-live-text">Live</span></span>
                <label class="lv-filter"><svg class="lv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 5h18l-7 8v6l-4 2v-8z"/></svg>
                    <input type="search" class="lv-filter-input" placeholder="Show lines with..." aria-label="Show only lines with this text"></label>
                <label class="lv-check"><input type="checkbox" class="lv-follow" checked> Follow</label>
                <label class="lv-check"><input type="checkbox" class="lv-wrap"> Wrap</label>
                <button type="button" class="lv-btn lv-pause" data-resume="Resume">Pause</button>
                <button type="button" class="lv-btn lv-clear" title="Clear the screen (the log itself is not changed)">Clear</button>
            </div>
        </div>
        <div class="lv-out" tabindex="0"></div>
        <div class="lv-foot"><span class="lv-count"></span><span class="lv-legend"><i class="bad"></i>errors <i class="warn"></i>warnings</span></div>
    </section>
</div>
<script src="../assets/logviewer.js"></script>
</body>
</html>
