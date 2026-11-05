<?php

include('cnf/db.php');
$z_db_user = $user;
$z_db_pass = $pass;
$z_db_host = $host;
$z_db_name = $dbname;
try {
    $zdbh = new db_driver("mysql:host=" . $z_db_host . ";dbname=" . $z_db_name . "", $z_db_user, $z_db_pass);
} catch (PDOException $e) {
    
}

echo fs_filehandler::NewLine() . "START Backup Config." . fs_filehandler::NewLine();
if (ui_module::CheckModuleEnabled('Backup Config')) {
    echo "Backup Config module ENABLED..." . fs_filehandler::NewLine();

// Schedule daily backups are enabled...
    if (strtolower(ctrl_options::GetSystemOption('schedule_bu')) == "true") {
        runtime_hook::Execute('OnBeforeScheduleBackup');
        echo "Backup Scheduling enabled - Backing up all enabled client files now..." . fs_filehandler::NewLine();
        // Get all accounts
        $bsql = "SELECT * FROM x_accounts WHERE ac_enabled_in=1 AND ac_deleted_ts IS NULL";
        $numrows = $zdbh->query($bsql);
        if ($numrows->fetchColumn() <> 0) {
            $bsql = $zdbh->prepare($bsql);
            $bsql->execute();
            while ($rowclients = $bsql->fetch()) {
                echo "Backing up client folder: " . $rowclients['ac_user_vc'] . "/public_html..." . fs_filehandler::NewLine();
                // Same secure routine as the Backup module (dryden/sys/backup.class.php):
                // built outside the web root, MySQL password never on a command line
                $made = sys_backup::Create($rowclients['ac_id_pk'], $rowclients['ac_user_vc']);
                echo ($made ? $made : "Backup FAILED for " . $rowclients['ac_user_vc']) . fs_filehandler::NewLine();
            }
        }
        runtime_hook::Execute('OnAfterScheduleBackup');
        echo "Backup Schedule COMPLETE..." . fs_filehandler::NewLine();
    }

// Purge backups are enabled....
    if (strtolower(ctrl_options::GetSystemOption('purge_bu')) == "true") {
        echo fs_filehandler::NewLine() . "Backup Purging enabled - Purging backups older than " . ctrl_options::GetSystemOption('purge_date') . " days..." . fs_filehandler::NewLine();
        runtime_hook::Execute('OnBeforePurgeBackup');
        clearstatcache();
        // Get all accounts
        $bsql = "SELECT * FROM x_accounts WHERE ac_enabled_in=1 AND ac_deleted_ts IS NULL";
        $numrows = $zdbh->query($bsql);
        if ($numrows->fetchColumn() <> 0) {
            $purge_date = ctrl_options::GetSystemOption('purge_date');
            $bsql = $zdbh->prepare($bsql);
            $bsql->execute();
            echo "[FILE][PURGE_DATE][FILE_DATE][ACTION]" . fs_filehandler::NewLine();
            while ($rowclients = $bsql->fetch()) {
                // backups older than the purge age, in the account's real backups folder
                // only (never through a link a site put in its place: this runs as root)
                $n = sys_backup::Purge($rowclients['ac_user_vc'], (int) $purge_date);
                echo $rowclients['ac_user_vc'] . ": " . $n . " old backup(s) removed" . fs_filehandler::NewLine();
            }
        }
        echo "Backup Purging COMPLETE..." . fs_filehandler::NewLine();
        runtime_hook::Execute('OnAfterPurgeBackup');
    }


    // Clean temp backups....
    echo fs_filehandler::NewLine() . "Purging backups from temp folder..." . fs_filehandler::NewLine();
    clearstatcache();
    echo "[FILE][PURGE_DATE][FILE_DATE][ACTION]" . fs_filehandler::NewLine();
    $temp_dir = ctrl_options::GetSystemOption('sentora_root') . "/modules/backupmgr/temp/";
    if ($handle = @opendir($temp_dir)) {
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                $filetime = @filemtime($temp_dir . $file);
                if ($filetime == NULL) {
                    $filetime = @filemtime(mb_convert_encoding($temp_dir . $file, 'ISO-8859-1', 'UTF-8'));
                }
                $filetime = floor((time() - $filetime) / 86400);
                echo "" . $file . " - " . $purge_date . " - " . $filetime . "";
                if (1 <= $filetime) {
                    //delete the file
                    echo " - Deleting file..." . fs_filehandler::NewLine();
                    unlink($temp_dir . $file);
                } else {
                    echo " - Skipping file..." . fs_filehandler::NewLine();
                }
            }
        }
    }
} else {
    echo "Backup Config module DISABLED...nothing to do." . fs_filehandler::NewLine();
}
echo "END Backup Config." . fs_filehandler::NewLine();
?>
