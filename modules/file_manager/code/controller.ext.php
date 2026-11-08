<?php

/**
 * File Manager module: controller.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-07
 */

/**
 * File Manager module: the account's files in the browser (Tiny File Manager, bundled in app/,
 * confined to the account's hosting folder). This page shows the folder, the disk space used
 * and the largest upload, and opens the file manager in the panel's own Light or Dark look.
 */
class module_controller extends ctrl_module
{

    /**
     * Escape a value for HTML.
     *
     * @param mixed $s The value (cast to string).
     * @return string The escaped text.
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translate and escape a text.
     *
     * @param string $s The English text.
     * @return string The escaped translation.
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * The account's hosting folder (<@ HostingFolder @>), escaped.
     *
     * @return string
     */
    static function getHostingFolder()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return self::h(ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . '/');
    }

    /**
     * The disk space used against the package's limit (<@ DiskUse @>), with a bar.
     *
     * @return string The HTML.
     */
    static function getDiskUse()
    {
        $u = ctrl_users::GetUserDetail();
        $used = (float) ctrl_users::GetQuotaUsages('diskspace', $u['userid']);
        $max = (float) $u['diskquota'];
        $pct = $max > 0 ? min(100, round($used / $max * 100)) : 0;
        return '<span class="fm-v">' . self::h(fs_director::ShowHumanFileSize($used)) . ' <small>' . ($max > 0 ? self::t('of') . ' ' . self::h(fs_director::ShowHumanFileSize($max)) : self::t('no limit')) . '</small></span>'
            . ($max > 0 ? '<span class="fm-bar' . ($pct >= 90 ? ' full' : ($pct >= 75 ? ' high' : '')) . '"><i style="width:' . $pct . '%"></i></span>' : '');
    }

    /**
     * The largest file that can be uploaded here (<@ UploadLimit @>): the smaller of PHP's upload and post limits.
     *
     * @return string For example '64 MB'.
     */
    static function getUploadLimit()
    {
        $bytes = function ($v) {
            $v = trim((string) $v);
            $n = (float) $v;
            switch (strtolower(substr($v, -1))) {
                case 'g':
                    $n *= 1024;
                    // no break
                case 'm':
                    $n *= 1024;
                    // no break
                case 'k':
                    $n *= 1024;
            }
            return $n;
        };
        $limits = array_filter(array($bytes(ini_get('upload_max_filesize')), $bytes(ini_get('post_max_size'))), function ($n) { return $n > 0; });
        return $limits ? self::h(fs_director::ShowHumanFileSize((int) min($limits))) : self::t('no limit');
    }

    /**
     * Template flag <% if FtpOn %>: FTP Accounts is switched on, for its link.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return bool
     */
    static function getFtpOn()
    {
        global $zdbh;
        return (int) $zdbh->query("SELECT COUNT(*) FROM x_modules WHERE mo_folder_vc = 'ftp_management' AND mo_enabled_en = 'true'")->fetchColumn() > 0;
    }

}
