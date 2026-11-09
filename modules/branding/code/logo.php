<?php

/**
 * Branding: a brand's logo, for the login page and the panel's top bar. Public (the login page
 * shows it before anyone is logged in), like any logo on a website. Only the PNG the panel drew
 * itself from the upload is ever sent (ctrl_branding::SetLogo()), as an image that can not run
 * anything. ?b=<account ID>, &d=1 for the logo for dark backgrounds, &v= changes with each new logo
 * (so browsers may keep it for a day). ?t=<template ID> (&d=1): the logo of one of the logged-in
 * account's own saved templates, for its preview (never anyone else's).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cross-Origin-Resource-Policy: same-origin');
$uid = isset($_GET['b']) && ctype_digit((string) $_GET['b']) ? (int) $_GET['b'] : 0;
$tpl = isset($_GET['t']) && ctype_digit((string) $_GET['t']) ? (int) $_GET['t'] : 0;
$png = '';
$private = false;
if ($tpl > 0 && ctrl_branding::Enabled()) {
    // a saved template's logo (its preview on Branding): only for the account that saved it
    require_once 'inc/session.inc.php';
    session_start();
    ctrl_auth::CheckSessionAccounts();
    $me = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
    session_write_close();
    $private = true;
    if ($me > 0) {
        try {
            $png = ctrl_brandtemplates::LogoData($me, $tpl, !empty($_GET['d']));
        } catch (Exception $e) {
            $png = '';
        }
    }
} elseif ($uid > 0 && ctrl_branding::Enabled()) {
    try {
        $png = ctrl_branding::LogoData($uid, !empty($_GET['d']));
    } catch (Exception $e) {
        $png = '';
    }
}
if ($png === '' || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}
header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header($private ? 'Cache-Control: private, no-store' : 'Cache-Control: public, max-age=86400');
echo $png;
