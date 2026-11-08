<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * The ZPanelX loader and default handler file.
 * @package zpanelx
 * @subpackage core
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-06
 */
// Session cookie settings (shared with the dryden/ajax handlers)
require_once 'inc/session.inc.php';
// Browser-side backstops for the panel's own pages: scripts, styles, fonts and
// images only from the panel itself, no framing by other sites, no plugins.
// (Inline scripts stay allowed because the panel's pages use them.)
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; "
    . "form-action 'self'; base-uri 'self'; object-src 'none'");
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
session_start();
// Every form that posts gets this session's CSRF token, added to the page as it is sent;
// the page head also carries it for the panel's own AJAX calls
ob_start(function ($html) {
    return class_exists('runtime_csfr') ? runtime_csfr::AddToHead(runtime_csfr::AddToForms($html)) : $html;
});
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
debug_phperrors::SetMode('dev');
require_once 'inc/dbc.inc.php';
debug_phperrors::SetMode(ctrl_options::GetSystemOption('debug_mode'));
require_once 'inc/init.inc.php';
//This is where we check the session for hi-jacking
if(!runtime_sessionsecurity::antiSessionHijacking()){
    exit(header("location: ./?sessionIssue"));
}
?>