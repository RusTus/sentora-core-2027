<?php

/**
 * PHP Versions, once a day: the domains still using a PHP version that gets no
 * security fixes any more, or stops getting them within 90 days, so the
 * administrator sees them in the daemon log.
 */
global $zdbh; // hooks are included inside a function
echo fs_filehandler::NewLine() . "START PHP version support" . fs_filehandler::NewLine();
$rows = $zdbh->query("SELECT v.vh_name_vc, v.vh_php_version_vc, a.ac_user_vc FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk
    WHERE v.vh_deleted_ts IS NULL AND a.ac_deleted_ts IS NULL ORDER BY a.ac_user_vc, v.vh_name_vc")->fetchAll();
$ended = 0;
$soon = 0;
foreach ($rows as $row) {
    $run = sys_phpversions::Effective((string) $row['vh_php_version_vc']);
    $days = sys_phpversions::DaysLeft($run);
    if ($days === null || $days > 90) {
        continue;
    }
    if ($days < 0) {
        $ended++;
        echo "UNSUPPORTED: " . $row['vh_name_vc'] . " (" . $row['ac_user_vc'] . ") runs PHP " . $run . ", no security fixes since " . sys_phpversions::$supportEnds[$run] . fs_filehandler::NewLine();
    } else {
        $soon++;
        echo "Ending soon: " . $row['vh_name_vc'] . " (" . $row['ac_user_vc'] . ") runs PHP " . $run . ", security fixes until " . sys_phpversions::$supportEnds[$run] . " (" . $days . " days)" . fs_filehandler::NewLine();
    }
}
echo count($rows) . " domain(s): " . ($ended + $soon === 0 ? "all on PHP versions with security fixes for more than 90 days"
    : $ended . " on an unsupported PHP version, " . $soon . " ending within 90 days (choose a newer version in Domains)") . "." . fs_filehandler::NewLine();
echo "END PHP version support" . fs_filehandler::NewLine();
