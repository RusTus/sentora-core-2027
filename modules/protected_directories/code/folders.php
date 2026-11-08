<?php

/**
 * Protected Directories folder tree: the subfolders of one folder in the logged-in
 * account's public_html, as JSON, loaded when a folder is opened in the tree.
 * Read-only. Hidden folders (.name) and symbolic links are left out; the path is
 * checked by pd_safe::ResolveDir (inside the account's public_html, no links).
 *
 * Written by RusTus, 2026-11-07
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once dirname(__FILE__) . '/pdsafe.php';

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
$rel = isset($_GET['p']) ? (string) $_GET['p'] : '';
$dir = pd_safe::ResolveDir($user['username'], $rel);
if ($dir === false) {
    http_response_code(404);
    exit(json_encode(array('error' => ui_language::translate('That folder can not be opened.'))));
}

// folders the account already protects
$protected = array();
$q = $zdbh->prepare("SELECT x_htpasswd_file_target FROM x_htpasswd_file WHERE x_htpasswd_sentora_user_id = :u AND x_htpasswd_file_deleted IS NULL");
$q->execute(array(':u' => $uid));
foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $protected[(string) $t] = true;
}

$isFolder = function ($path, $name) {
    return $name !== '' && $name[0] !== '.' && !preg_match('/[\x00-\x1f\x7f]/', $name) && !is_link($path) && is_dir($path);
};
$out = array();
$names = @scandir($dir);
if ($names === false) {
    http_response_code(403);
    exit(json_encode(array('error' => ui_language::translate('That folder can not be read.'))));
}
natcasesort($names);
foreach ($names as $name) {
    $path = $dir . '/' . $name;
    if (!$isFolder($path, $name)) {
        continue;
    }
    // does it have subfolders (only to show the open arrow)? stop at the first one
    $children = false;
    if ($h = @opendir($path)) {
        while (($n = readdir($h)) !== false) {
            if ($isFolder($path . '/' . $n, $n)) {
                $children = true;
                break;
            }
        }
        closedir($h);
    }
    $out[] = array('name' => $name, 'path' => ltrim(($rel === '' ? '' : trim($rel, '/') . '/') . $name, '/'),
        'children' => $children, 'protected' => isset($protected[$path]));
    if (count($out) >= 1000) {
        break; // a folder with more subfolders than that is not browsed in full
    }
}
echo json_encode(array('path' => trim($rel, '/'), 'protected' => isset($protected[$dir]), 'folders' => $out), JSON_UNESCAPED_SLASHES);
