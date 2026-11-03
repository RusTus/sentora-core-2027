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
 */
// Session cookie: never readable by scripts, not sent with cross-site posts,
// only over HTTPS when the panel is on HTTPS; ids the server did not make are refused
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)) {
    ini_set('session.cookie_secure', '1');
}
session_start();
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