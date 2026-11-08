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
 * Template tag <# ui_tpl_lastlogon #>: the user's previous log-on time.
 */
class ui_tpl_lastlogon {

    /**
     * Returns the date and time the current user last logged on.
     *
     * @return string The date in the 'sentora_df' format, or '<: Never :>' when the user has never logged on.
     */
    public static function Template() {
        $currentuser = ctrl_users::GetUserDetail(ctrl_auth::CurrentUserID());
        if ($currentuser['lastlogon']) {
            return date(ctrl_options::GetSystemOption('sentora_df'), $currentuser['lastlogon']);
        } else {
            return "<: Never :>";
        }
    }

}

?>