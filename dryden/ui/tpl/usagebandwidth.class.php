<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Generic template place holder class.
 * @package zpanelx
 * @subpackage dryden -> ui -> tpl
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-06
 */
/**
 * Template tag <# ui_tpl_usagebandwidth #>: the bandwidth used this month.
 */
class ui_tpl_usagebandwidth {

    /**
     * Returns this month's bandwidth use of the current user in human-readable form.
     *
     * @return string E.g. '1.2 GB'.
     */
    public static function Template() {
        $currentuser = ctrl_users::GetUserDetail();
        return fs_director::ShowHumanFileSize(ctrl_users::GetQuotaUsages('bandwidth', $currentuser['userid']));
    }

}

?>
