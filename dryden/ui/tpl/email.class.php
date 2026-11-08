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
 * Template tag <# ui_tpl_email #>: the current user's email address.
 */
class ui_tpl_email {

    /**
     * Returns the email address of the logged-in user.
     *
     * @return string The email address (not HTML-escaped).
     */
    public static function Template() {
        $user_array = ctrl_users::GetUserDetail();
        return $user_array['email'];
    }

}

?>
