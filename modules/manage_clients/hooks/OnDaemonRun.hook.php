<?php
/**
 * Manage Clients hook (OnDaemonRun), Windows only: every live account's PHP as its own Windows account
 * (sys_winphp: the list of live accounts, then SentoraZsudo's phpaccount-sync when it changed or once a day).
 * Nothing on Linux, where Apache Config makes each account's PHP pool.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-24
 */
if (sys_versions::IsWindows()) {
    $winphpSaid = sys_winphp::Sync();
    if ($winphpSaid !== '') {
        echo $winphpSaid . fs_filehandler::NewLine();
    }
}
