<?php

/**
 * Cron Manager file browser: the subfolders and .php files of one folder in the
 * logged-in account's public_html, as JSON, loaded when a folder is opened. Read-only.
 * Paths are relative to public_html (the server's own paths are never sent). Hidden
 * entries and symbolic links are left out; the folder is checked by
 * pd_safe::ResolveDir (inside the account's public_html, no links).
 *
 * Written by RusTus, 2026-11-07
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once dirname(__FILE__) . '/cronsafe.php';

session_start();
ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
if (!$user) {
    http_response_code(403);
    exit('{"error":"Please log in."}');
}
// no panel controller here: the account's own language for ui_language::translate()
ui_language::ForAccount($uid);
$rel = trim((string) (isset($_GET['p']) ? $_GET['p'] : ''), '/');
$dir = pd_safe::ResolveDir($user['username'], $rel);
if ($dir === false) {
    http_response_code(404);
    exit(json_encode(array('error' => ui_language::translate('That folder can not be opened.'))));
}
$names = @scandir($dir);
if ($names === false) {
    http_response_code(403);
    exit(json_encode(array('error' => ui_language::translate('That folder can not be read.'))));
}
natcasesort($names);
$plain = function ($name) {
    return $name !== '' && $name[0] !== '.' && !preg_match('/[\x00-\x1f\x7f]/', $name);
};
// does a folder hold anything to show (a subfolder or a .php file)? stop at the first
$hasContent = function ($path) use ($plain) {
    if (!($h = @opendir($path))) {
        return false;
    }
    while (($n = readdir($h)) !== false) {
        if ($plain($n) && !is_link($path . '/' . $n) && (is_dir($path . '/' . $n) || preg_match('/\.php$/i', $n))) {
            closedir($h);
            return true;
        }
    }
    closedir($h);
    return false;
};
$folders = array();
$files = array();
foreach ($names as $name) {
    $path = $dir . '/' . $name;
    if (!$plain($name) || is_link($path)) {
        continue;
    }
    $childRel = ltrim(($rel === '' ? '' : $rel . '/') . $name, '/');
    if (is_dir($path)) {
        $folders[] = array('name' => $name, 'path' => $childRel, 'children' => $hasContent($path));
    } elseif (is_file($path) && preg_match('/\.php$/i', $name)) {
        // files whose path a cron line can not carry (spaces and the like) are shown but not offered
        $files[] = array('name' => $name, 'path' => $childRel, 'ok' => cron_safe::ValidRel($childRel));
    }
    if (count($folders) + count($files) >= 2000) {
        break;
    }
}
echo json_encode(array('path' => $rel, 'folders' => $folders, 'files' => $files), JSON_UNESCAPED_SLASHES);
