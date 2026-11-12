<?php

/**
 * Server Monitor module: controller.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-11
 */

/**
 * Server Monitor (Server Admin): a live dashboard of the server itself. The page
 * (assets/monitor.js) polls code/stats.php, which reads the kernel's figures; the Hardening card
 * (assets/hardening.js) reads code/hardening.php, the last report of sentora-post-install-check.
 */
class module_controller extends ctrl_module
{

    /**
     * Template value <@ Assets @>: the module's assets folder.
     *
     * @return string The relative path 'modules/server_monitor/assets/'.
     */
    static function getAssets()
    {
        return 'modules/server_monitor/assets/';
    }

    /**
     * Whether the signed-in account is an administrator.
     *
     * @return bool
     */
    static function Admin()
    {
        $u = ctrl_users::GetUserDetail();
        return is_array($u) && (int) $u['usergroupid'] === 1;
    }

    /**
     * Template value <@ HardeningForm @>: the "Run the check now" button (a form with the panel's
     * protection against forged requests).
     *
     * @return string The HTML.
     */
    static function getHardeningForm()
    {
        if (!self::Admin()) {
            return '';
        }
        return '<form method="post" action="./?module=server_monitor&amp;action=HardeningCheck" class="smh-run">'
            . '<button type="submit">' . htmlspecialchars(ui_language::translate('Run the check now'), ENT_QUOTES, 'UTF-8') . '</button>'
            . runtime_csfr::Token() . '</form>';
    }

    /**
     * Action: ask root to run the check now (zsudo hardening-check, through its queue); the card shows
     * "checking" until the new report is there.
     *
     * @return void
     */
    static function doHardeningCheck()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $zsudo = (string) ctrl_options::GetSystemOption('zsudo');
        if ($zsudo !== '' && is_executable($zsudo) && function_exists('exec')) {
            $out = array();
            exec(escapeshellcmd($zsudo) . ' hardening-check 2>&1', $out, $rc);
            if ((int) $rc === 0) {
                $_SESSION['sm_hard_asked'] = time();
            }
        }
        header('Location: ./?module=server_monitor#sm-hard');
        exit;
    }

}

?>
