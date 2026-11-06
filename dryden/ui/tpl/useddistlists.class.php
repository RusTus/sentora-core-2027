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
 */
/**
 * Template tag <# ui_tpl_useddistlists #>: the number of distribution lists in use.
 */
class ui_tpl_useddistlists {

    /**
     * Returns how many distribution lists the current user has, counted by ctrl_users::GetQuotaUsages().
     *
     * @return int|string The count (a numeric string or int, as the database driver returns it).
     */
    public static function Template() {
        $currentuser = ctrl_users::GetUserDetail();
        return ctrl_users::GetQuotaUsages('distlists', $currentuser['userid']);
    }
    
}

?>
