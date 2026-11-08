<?php
/**
 * Repo Browser, every hour: has a newer version of an installed module been published?
 *
 * For each installed add-on module that one of the panel's repositories lists (etc/zppy-cache/
 * package.list), reads <repository>/<module>.xml and compares its <latestversion> with the
 * installed version. Each module's newer version is kept in x_modules.mo_updatever_vc, and the
 * administrators get one notice (key module-updates) naming them, with Repo Browser's Update
 * button to install them. Once every module is up to date the notice is taken away. Only the
 * repositories added in Repo Browser are asked; nothing is downloaded or installed here.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-08
 */
require_once dirname(__FILE__) . '/../code/repohelper.php';

echo fs_filehandler::NewLine() . "START Module update check" . fs_filehandler::NewLine();
echo repo_browser_helper::UpdateCheck() . fs_filehandler::NewLine();
echo "END Module update check" . fs_filehandler::NewLine();
