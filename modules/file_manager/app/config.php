<?php

/**
 * Tiny File Manager settings for Sentora. Loaded by tinyfilemanager.inc.php;
 * the root folder comes from bootstrap.inc.php (the logged-in user's folder).
 */
if (!defined('SENTORA_FILE_MANAGER')) {
    http_response_code(403);
    exit;
}

// Sentora's login is the login; there is no second password
$use_auth = false;
$auth_users = array();
$readonly_users = array();
$directories_users = array();
$global_readonly = false;

$root_path = $GLOBALS['sentora_fm_root'];
$root_url = '';
// Previews and "direct link" go through raw.php (sandboxed, same login check)
define('FM_ROOT_URL', 'raw.php?f=');

// Fixed settings (the Settings page can not save on this server)
// Light or dark as the panel shows it: the File Manager page sets this cookie from the panel's own
// colours (so Auto follows the computer too); only these two values are used
$sentora_fm_theme = (isset($_COOKIE['sn_fm_theme']) && $_COOKIE['sn_fm_theme'] === 'dark') ? 'dark' : 'light';
$CONFIG = '{"lang":"en","error_reporting":false,"show_hidden":true,"hide_Cols":false,"theme":"' . $sentora_fm_theme . '"}';

$edit_files = true;
$use_highlightjs = true;
$highlightjs_style = $sentora_fm_theme === 'dark' ? 'vs2015' : 'vs';
// the code editor's colours, to match
$ace_theme = $sentora_fm_theme === 'dark' ? 'one_dark' : 'textmate';
$default_timezone = date_default_timezone_get();
$datetime_format = 'Y-m-d H:i';
$path_display_mode = 'relative';
// Never send files to an outside document viewer
$online_viewer = false;
$sticky_navbar = true;
$ip_ruleset = 'OFF';

// Upload size follows PHP's own limits
$sentora_fm_ini_bytes = function ($v) {
    $v = trim((string) $v);
    $n = (int) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
};
$max_upload_size_bytes = min($sentora_fm_ini_bytes(ini_get('upload_max_filesize')), $sentora_fm_ini_bytes(ini_get('post_max_size')));
$upload_chunk_size_bytes = min(2000000, $max_upload_size_bytes);

// Local copies of the page's libraries (no CDN requests)
$sentora_fm_v = 'vendor/';
$external_local = array(
    'css-bootstrap' => '<link href="' . $sentora_fm_v . 'bootstrap/bootstrap.min.css" rel="stylesheet">',
    'css-dropzone' => '<link href="' . $sentora_fm_v . 'dropzone/dropzone.min.css" rel="stylesheet">',
    'css-font-awesome' => '<link rel="stylesheet" href="' . $sentora_fm_v . 'font-awesome/css/font-awesome.min.css">',
    'css-highlightjs' => '<link rel="stylesheet" href="' . $sentora_fm_v . 'highlightjs/styles/' . $highlightjs_style . '.min.css">',
    'js-ace' => '<script src="' . $sentora_fm_v . 'ace/ace.js"></script>',
    // the panel's look (sentora.css), loaded with the last scripts so it comes after Tiny File Manager's own styles
    'js-bootstrap' => '<script src="' . $sentora_fm_v . 'bootstrap/bootstrap.bundle.min.js"></script><link rel="stylesheet" href="sentora.css?v=3">',
    'js-dropzone' => '<script src="' . $sentora_fm_v . 'dropzone/dropzone.min.js"></script>',
    'js-jquery' => '<script src="' . $sentora_fm_v . 'jquery/jquery.min.js"></script>',
    'js-jquery-datatables' => '<script src="' . $sentora_fm_v . 'datatables/jquery.dataTables.min.js" defer></script>',
    'js-highlightjs' => '<script src="' . $sentora_fm_v . 'highlightjs/highlight.min.js"></script>',
    'pre-jsdelivr' => '',
    'pre-cloudflare' => '',
);
