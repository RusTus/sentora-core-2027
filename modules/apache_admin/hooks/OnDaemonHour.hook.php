<?php

/**
 * Every hour, for each hosting account: everything inside its folder belongs to
 * the account's own Linux user (sys_accountphp::FixOwnership), and its folder
 * stays closed to other accounts whatever a site changed (sys_accountphp::Safeguard).
 *
 * Written by RusTus, 2026-11-05
 */
echo fs_filehandler::NewLine() . "START Account file ownership." . fs_filehandler::NewLine();
$checked = 0;
$fixedTotal = 0;
foreach (sys_accountphp::Wanted() as $id => $acc) {
    $home = rtrim(sys_accountphp::HomeDir($acc['username']), '/');
    if (is_dir($home) && posix_getpwnam(sys_accountphp::UserName($id)) !== false) {
        $fixed = sys_accountphp::FixOwnership(sys_accountphp::UserName($id), $home);
        sys_accountphp::Safeguard(sys_accountphp::UserName($id), $home);
        $checked++;
        $fixedTotal += $fixed;
        if ($fixed > 0) {
            echo $acc['username'] . ": " . $fixed . " file(s) given back to " . sys_accountphp::UserName($id) . fs_filehandler::NewLine();
        }
    }
}
echo "Checked " . $checked . " account folder(s): " . ($fixedTotal > 0 ? $fixedTotal . " file(s) had another owner and were fixed" : "all files already owned by their account") . "; folders closed to other accounts." . fs_filehandler::NewLine();
echo "END Account file ownership." . fs_filehandler::NewLine();

?>
