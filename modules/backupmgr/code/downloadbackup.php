<?php

/**
 * Backup manager: download one of the logged-in account's backup archives.
 *
 * Called as downloadbackup.php?id=<account id>&file=<name>.zip. Sends the zip when
 * the id is the session's own account and the file is one of its backups, else
 * answers 404 or 403.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Updated by RusTus, 2026-11-07
 */
// The panel's own class loader, from the panel's folder (this file's own place: Windows' DocumentRoot has no slash
// at the end, and a request can not change it); the list of files this once included by hand missed classes the
// session check uses
chdir(dirname(__DIR__, 3));
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';


if (isset($_GET['id'])) {
    $userid = $_GET['id'];
} else {
    $userid = NULL;
}

session_start();
ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
// no panel controller here: the logged-in account's own language for ui_language::translate()
ui_language::ForAccount(isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0);
// Strict: a missing session id must never match (loose == let null equal null)
if (!empty($_SESSION['zpuid']) && ctype_digit((string) $userid) && (int) $_SESSION['zpuid'] === (int) $userid) {
	$currentuser = ctrl_users::GetUserDetail($userid);
	$username = $currentuser['username'];
	// One of this account's own backups: a real file with a backup name, never a link
	// (sys_backup::OpenFile checks the file it opens is the one it checked)
	$input = isset($_GET["file"]) ? (string) $_GET["file"] : '';
	$opened = sys_backup::OpenFile($username, $input);
	if ($opened !== false) {
		list($fh, $size) = $opened;
		header('Content-Description: File Transfer');
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . $input . '"');
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: private, no-store');
		header('Content-Length: ' . $size);
		while (ob_get_level() > 0) { ob_end_clean(); }
		fpassthru($fh);
		fclose($fh);
		exit;
	}
	http_response_code(404);
	echo '<h2>' . htmlspecialchars(ui_language::translate('ERROR:'), ENT_QUOTES, 'UTF-8') . '</h2>' . htmlspecialchars(ui_language::translate('That backup was not found. It may have been removed; make a new one.'), ENT_QUOTES, 'UTF-8');
} else {
	http_response_code(403);
	echo '<h2>' . htmlspecialchars(ui_language::translate('Unauthorized Access!'), ENT_QUOTES, 'UTF-8') . '</h2>' . htmlspecialchars(ui_language::translate('You have no permission to view this module.'), ENT_QUOTES, 'UTF-8');
}
