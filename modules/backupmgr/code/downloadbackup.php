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
 */
include($_SERVER["DOCUMENT_ROOT"] . 'cnf/db.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/db/driver.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/debug/logger.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/runtime/dataobject.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/sys/versions.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/ctrl/options.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/ctrl/auth.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/ctrl/users.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/fs/director.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'inc/dbc.inc.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/sys/backup.class.php');

try {
    $zdbh = new db_driver("mysql:host=" . $host . ";dbname=" . $dbname . "", $user, $pass);
} catch (PDOException $e) {
    exit();
}

if (isset($_GET['id'])) {
    $userid = $_GET['id'];
} else {
    $userid = NULL;
}

session_start();
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
	echo '<h2>ERROR:</h2>That backup was not found. It may have been removed; make a new one.';
} else {
	http_response_code(403);
	echo '<h2>Unauthorized Access!</h2>You have no permission to view this module.';
}
