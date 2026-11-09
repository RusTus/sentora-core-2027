<?php
/**
 * Site Health, every hour: check every live website from this server and read the new lines of
 * its logs; each account's summary is what the Site Health page and dashboard widget show.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
echo fs_filehandler::NewLine() . "START Site Health" . fs_filehandler::NewLine();
if (ui_module::CheckModuleEnabled('Site Health')) {
    foreach (sys_sitehealth::Collect() as $siteHealthLine) {
        echo $siteHealthLine . fs_filehandler::NewLine();
    }
} else {
    echo "Site Health module DISABLED." . fs_filehandler::NewLine();
}
echo "END Site Health" . fs_filehandler::NewLine();
