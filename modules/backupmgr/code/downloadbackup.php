<?php

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

	# Get username from Logged in userID
	$currentuser = ctrl_users::GetUserDetail($userid);
    $username = $currentuser['username'];
	
	# Get username from file name.  
	$input = isset($_GET["file"]) ? (string) $_GET["file"] : '';
	# Only one of this account's own backup files, by name (no paths)
	if (sys_backup::ValidFile($username, $input)) {

		# Check file name is set
		if (isset($_GET["file"])){
				
			$filepath = sys_backup::BackupDir($username) . $input;
			
			# Check file name exists then download
			if(file_exists($filepath)) {
							
				header('Content-Description: File Transfer');
				header('Content-Type: application/octet-stream');
				header('Content-Disposition: attachment; filename="'.basename($filepath).'"');
				header('Expires: 0');
				header('Cache-Control: must-revalidate');
				header('Pragma: public');
				header('Content-Length: ' . filesize($filepath));
				flush(); // Flush system output buffer
				readfile($filepath);
				//header('Location: ' . $_SERVER['HTTP_REFERER']); # DONT NEED THIS
				
				# Exit code
				exit;
				die();
			} else {
				echo '<h2>ERROR: </h2>' . '<br>';
				echo 'Something went wrong. File does not exist. Check with your administrator for help.';
			}
			
		} else {
			echo '<h2>ERROR: </h2>' . '<br>';
			echo 'Something went wrong. Missing file info. Please try again or contact you administrator for help.';	
		}

	} else {
		echo '<h2>Unauthorized Access!</h2>' . '<br>';
		echo 'You have no permission to download this file.';	
	}
	
} else {
	
	echo '<h2>Unauthorized Access!</h2>';
	echo 'You have no permission to view this module.';
	
}

?>