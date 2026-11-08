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
 * Template tag <# ui_tpl_quotadiskspace #>: the disk space quota.
 */
class ui_tpl_quotadiskspace {

    /**
     * Returns the user's disk space quota in human-readable form.
     *
     * @return string E.g. '500 MB', or '<: Unlimited :>' when the quota is 0.
     */
    public static function Template() {
        $currentuser = ctrl_users::GetUserDetail();
        if ($currentuser['diskquota'] == 0)
            $diskspacequota = '<: Unlimited :>';
        else
            $diskspacequota = fs_director::ShowHumanFileSize($currentuser['diskquota']);
        return $diskspacequota;
    }

}

?>
