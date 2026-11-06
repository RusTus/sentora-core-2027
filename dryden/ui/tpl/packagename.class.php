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
 * Template tag <# ui_tpl_packagename #>: the current user's hosting package.
 */
class ui_tpl_packagename {

    /**
     * Returns the name of the hosting package of the logged-in user.
     *
     * @return string The package name, cleaned with runtime_xss::xssClean().
     */
    public static function Template() {
        $user_array = ctrl_users::GetUserDetail();
        return runtime_xss::xssClean($user_array['packagename']);
    }

}

?>
