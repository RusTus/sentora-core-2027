<?php
/**
 * PHP Versions: switch MySQL's older login method (mysql_native_password) off when an administrator
 * asked for it in the Older logins card and no MySQL user uses it any more (sys_oldlogins).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-12
 */
sys_oldlogins::DaemonSwitchOff();

// The extra PHP versions' updates: installs one asked for in PHP Versions, and once a day checks the
// release's builds and php.net (a notice for the administrators when one is ready or not built yet)
$pvOut = sys_phpbundles::DaemonRun();
if ($pvOut !== '') {
    echo fs_filehandler::NewLine() . "START PHP updates" . fs_filehandler::NewLine() . trim($pvOut) . fs_filehandler::NewLine() . "END PHP updates" . fs_filehandler::NewLine();
}

// The extensions of each PHP version (PHP Versions > Extensions): what each has, for the page, and
// the administrator's switches and settings, tried with PHP first (sys_phpext)
$pvExt = sys_phpext::DaemonRun();
if ($pvExt) {
    echo fs_filehandler::NewLine() . "START PHP extensions" . fs_filehandler::NewLine() . implode(fs_filehandler::NewLine(), $pvExt) . fs_filehandler::NewLine() . "END PHP extensions" . fs_filehandler::NewLine();
}
