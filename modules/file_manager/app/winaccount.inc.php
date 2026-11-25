<?php

/**
 * Sentora File Manager on Windows: this request runs as the hosting account's own PHP (NT SERVICE\SentoraPHP-NAME, fenced
 * to the account's folder by open_basedir), reached at /sentora-fm/NAME/ (phpaccount.ps1 hands only that address to
 * NAME's PHP, with NAME's key). It can not read the panel's sessions or database, so the panel sends a pass: the account's
 * name, a minute of life and a one-time number, signed with the account's key (which only Apache and this PHP know; the
 * account's own scripts know it too, but it opens only the account's own files). The pass becomes this file manager's own
 * session (its cookie only for /sentora-fm/NAME/).
 *
 * Sets $sentora_fm_root (the account's folder) and $sentora_fm_app for bootstrap.inc.php.
 *
 * Written by RusTus, 2026-11-25
 */
if (!defined('SENTORA_FILE_MANAGER')) {
    http_response_code(403);
    exit;
}

$sentora_fm_key = (string) getenv('SENTORA_KEY');
$sentora_fm_base = explode(PATH_SEPARATOR, (string) ini_get('open_basedir'));
$sentora_fm_home = rtrim(str_replace('\\', '/', (string) $sentora_fm_base[0]), '/');
$sentora_fm_name = strtolower(basename($sentora_fm_home));
// the address names the account whose PHP this is (Apache sends nothing else here)
if (strlen($sentora_fm_key) < 32 || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $sentora_fm_name)
    || !preg_match('#^/sentora-fm/([a-z0-9-]{1,63})/[a-z]+\.php$#', (string) ($_SERVER['SCRIPT_NAME'] ?? ''), $sentora_fm_m)
    || $sentora_fm_m[1] !== $sentora_fm_name) {
    sentora_fm_deny(403, 'Open the File Manager from the control panel.');
}
$sentora_fm_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
session_name('SENTORAFM');
session_set_cookie_params(array('lifetime' => 0, 'path' => '/sentora-fm/' . $sentora_fm_name . '/', 'secure' => $sentora_fm_https, 'httponly' => true, 'samesite' => 'Strict'));
session_start();

if (isset($_GET['t'])) {
    // the panel's pass: "<payload>.<signature>", payload base64url JSON {u, exp, n}
    $sentora_fm_ok = false;
    $sentora_fm_t = explode('.', (string) $_GET['t']);
    if (count($sentora_fm_t) === 2 && hash_equals(hash_hmac('sha256', $sentora_fm_t[0], $sentora_fm_key), $sentora_fm_t[1])) {
        $sentora_fm_p = json_decode((string) base64_decode(strtr($sentora_fm_t[0], '-_', '+/')), true);
        $sentora_fm_n = is_array($sentora_fm_p) ? (string) ($sentora_fm_p['n'] ?? '') : '';
        if (is_array($sentora_fm_p) && ($sentora_fm_p['u'] ?? '') === $sentora_fm_name && (int) ($sentora_fm_p['exp'] ?? 0) >= time()
            && (int) $sentora_fm_p['exp'] <= time() + 120 && preg_match('/^[0-9a-f]{32}$/', $sentora_fm_n)) {
            // (once only: the numbers used in the last minutes, in the account's own tmp folder)
            $sentora_fm_used = $sentora_fm_home . '/tmp/.sentora-fm-passes';
            $sentora_fm_list = array();
            foreach (preg_split('/\n/', (string) @file_get_contents($sentora_fm_used), -1, PREG_SPLIT_NO_EMPTY) as $l) {
                $f = explode(' ', $l);
                if (count($f) === 2 && (int) $f[1] >= time()) {
                    $sentora_fm_list[$f[0]] = (int) $f[1];
                }
            }
            if (!isset($sentora_fm_list[$sentora_fm_n])) {
                $sentora_fm_list[$sentora_fm_n] = (int) $sentora_fm_p['exp'];
                $sentora_fm_out = '';
                foreach ($sentora_fm_list as $k => $v) {
                    $sentora_fm_out .= $k . ' ' . $v . "\n";
                }
                @file_put_contents($sentora_fm_used, $sentora_fm_out, LOCK_EX);
                $sentora_fm_ok = true;
            }
        }
    }
    if (!$sentora_fm_ok) {
        sentora_fm_deny(403, 'This File Manager link has expired: open the File Manager from the control panel again.');
    }
    session_regenerate_id(true);
    $_SESSION['sentora_fm'] = $sentora_fm_name;
    $_SESSION['sentora_fm_seen'] = time();
    session_write_close();
    header('Location: ' . basename((string) $_SERVER['SCRIPT_NAME']));
    exit;
}
if (($_SESSION['sentora_fm'] ?? '') !== $sentora_fm_name || time() - (int) ($_SESSION['sentora_fm_seen'] ?? 0) > 7200) {
    sentora_fm_deny(403, 'Open the File Manager from the control panel.');
}
$_SESSION['sentora_fm_seen'] = time();
session_write_close();

$sentora_fm_root = realpath($sentora_fm_home);
$sentora_fm_app = __DIR__;
if ($sentora_fm_root === false || !is_dir($sentora_fm_root)) {
    sentora_fm_deny(404, 'Your hosting folder does not exist yet.');
}
$sentora_fm_root = rtrim(str_replace('\\', '/', $sentora_fm_root), '/');
