<?php
/**
 * Domains hook (OnDaemonRun), Windows only: the websites' Apache settings, folders and a gentle restart when the panel
 * asked (sys_winvhosts). Nothing on Linux, where Apache Config writes the vhosts.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
if (sys_versions::IsWindows()) {
    foreach (sys_winvhosts::Run() as $winvhostsLine) {
        echo $winvhostsLine . fs_filehandler::NewLine();
    }
}
