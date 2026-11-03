<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 * 
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
set_time_limit(0);
ini_set('memory_limit', '256M');
require($_SERVER["DOCUMENT_ROOT"] . 'cnf/db.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/db/driver.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/debug/logger.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/runtime/dataobject.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/runtime/hook.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/sys/versions.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/ctrl/options.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/fs/director.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/fs/filehandler.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'inc/dbc.inc.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/sys/backup.class.php');
try {
    $zdbh = new db_driver("mysql:host=" . $host . ";dbname=" . $dbname . "", $user, $pass);
} catch (PDOException $e) {
    exit();
}
if (isset($_POST['inDownLoad'])) {
    $download = $_POST['inDownLoad'];
} else {
    $download = 0;
}
if (isset($_GET['id']) && $_GET['id'] != "") {
    session_start();
    // Strict: a missing session id must never match (loose == let null equal null)
    if (!empty($_SESSION['zpuid']) && ctype_digit((string) $_GET['id']) && (int) $_SESSION['zpuid'] === (int) $_GET['id']) {
        $userid = $_GET['id'];
        $rows = $zdbh->prepare("
	    	SELECT * FROM x_accounts 
	        LEFT JOIN x_profiles ON (x_accounts.ac_id_pk=x_profiles.ud_user_fk) 
	        LEFT JOIN x_groups   ON (x_accounts.ac_group_fk=x_groups.ug_id_pk) 
	        LEFT JOIN x_packages ON (x_accounts.ac_package_fk=x_packages.pk_id_pk) 
	        LEFT JOIN x_quotas   ON (x_accounts.ac_package_fk=x_quotas.qt_package_fk) 
	        WHERE x_accounts.ac_id_pk= :userid
	        ");
        $rows->bindParam(':userid', $userid);
        $rows->execute();
        $dbvals = $rows->fetch();

        if ($backup = ExecuteBackup($userid, $dbvals['ac_user_vc'], $download)) {
            echo "<p>Ready to download file: <b>" . htmlspecialchars(basename($backup), ENT_QUOTES) . "<b></p>";
			//echo "<button class=\"fg-button ui-state-default ui-corner-all\" type=\"button\" onclick=\"window.location.href='../../../etc/tmp/" . basename($backup) . "';return false;\">Download Now</button>";
            echo "<button class=\"fg-button ui-state-default ui-corner-all\" type=\"button\" onclick=\"window.location.href='downloadbackup.php?id=" . (int) $userid . "&file=" . rawurlencode(basename($backup)) . "';return false;\">Download Now</button>";
            echo "<button class=\"fg-button ui-state-default ui-corner-all\" type=\"button\" value=\"Close Window\" onClick=\"return window.close()\">Close Window</button>";
        } else {
            echo "Could not find user!";
        }
    } else {
        echo "<h2>Unauthorized Access!</h2>";
        echo "You have no permission to view this module.";
    }
}

function ExecuteBackup($userid, $username, $download = 0) {
    // One routine for this page and the daily job (dryden/sys/backup.class.php):
    // built outside the web root, kept in the account's backups folder
    return sys_backup::Create($userid, $username);
}

function readfile_chunked($filename) {
    $chunksize = 1 * (1024 * 1024);
    $buffer = '';
    $handle = fopen($filename, 'rb');
    if ($handle === false) {
        return false;
    }
    while (!feof($handle)) {
        $buffer = fread($handle, $chunksize);
        print $buffer;
    }
    return fclose($handle);
}

?>