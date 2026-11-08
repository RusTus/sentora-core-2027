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
 * Template tag <# ui_tpl_usergroup #>: the current user's group.
 */
class ui_tpl_usergroup {

    /**
     * Returns the name of the user group of the logged-in user.
     *
     * @return string The group name, e.g. 'Administrators'.
     */
    public static function Template() {
        $user_array = ctrl_users::GetUserDetail();
        return $user_array['usergroup'];
    }

}

?>
