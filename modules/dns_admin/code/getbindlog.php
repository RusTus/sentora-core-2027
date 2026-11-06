<?php
/**
 * DNS Admin: BIND's log (errors, warnings or all of it) in its own window.
 * Panel administrators only (it covers every customer's domains: this checked only that
 * somebody was logged in), every line escaped (a DNS name in the log could otherwise put
 * a script into the administrator's page), the last 2000 lines, never through a link.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

session_start();
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
session_write_close();
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
header('Cache-Control: no-store');
if (!$user || (int) $user['usergroupid'] !== 1) {
    http_response_code(403);
    exit('<p>The BIND log is only available to panel administrators.</p>');
}

$bindlog = '/var/sentora/logs/bind/bind.log';
$lines = array();
if (is_file($bindlog) && !is_link($bindlog) && ($fh = @fopen($bindlog, 'rb'))) {
    $size = (int) fstat($fh)['size'];
    if ($size > 2 * 1024 * 1024) {
        fseek($fh, -2 * 1024 * 1024, SEEK_END);
        fgets($fh); // a partial first line
    }
    while (($l = fgets($fh, 8192)) !== false) {
        $lines[] = rtrim($l, "\r\n");
        if (count($lines) > 2000) {
            array_shift($lines);
        }
    }
    fclose($fh);
}
$view = isset($_POST['inViewErrors']) ? 'errors' : (isset($_POST['inViewWarnings']) ? 'warnings' : 'all');
$titles = array('errors' => 'BIND errors', 'warnings' => 'BIND warnings', 'all' => 'BIND log (the last 2000 lines)');
$h = function ($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title><?php echo $h($titles[$view]); ?></title>
<style>body{background:#000;color:#090;font:13px/1.45 monospace;margin:12px}h2{color:#fff;font:bold 16px sans-serif}.e{color:#c00}.w{color:#ff9}.ok{color:#0f0}.f{color:#aaa}</style>
</head><body>
<h2><?php echo $h($titles[$view]); ?></h2>
<?php
$shown = 0;
foreach ($lines as $l) {
    $isErr = (stripos($l, 'error:') !== false || stripos($l, 'error ') !== false);
    $isWarn = (stripos($l, 'warning:') !== false || stripos($l, 'warning ') !== false);
    if (($view === 'errors' && !$isErr) || ($view === 'warnings' && !$isWarn)) {
        continue;
    }
    $class = $isErr ? 'e' : ($isWarn ? 'w' : ((strpos($l, 'succeeded') !== false || strpos($l, 'SIGHUP') !== false) ? 'ok' : ((strpos($l, 'Failed') !== false) ? 'f' : '')));
    echo '<div' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . $h($l) . "</div>\n";
    $shown++;
}
if ($shown === 0) {
    echo '<p>' . ($lines ? 'Nothing to show.' : 'The log is empty or can not be read.') . '</p>';
}
?>
</body></html>
