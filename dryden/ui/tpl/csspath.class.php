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
 * Template tag <# ui_tpl_csspath #>: the stylesheet of the chosen theme variation.
 */
class ui_tpl_csspath {

    /**
     * Returns the path of the CSS file to load for the current user.
     *
     * Uses the user's own choice first, then the reseller's (Theme Manager), then the
     * template's first variation when the chosen file does not exist.
     *
     * @return string Relative path such as 'etc/styles/<template>/css/<variation>.css'.
     */
    public static function Template() {
        $user = ctrl_users::GetUserDetail();
        $template = ui_template::GetUserTemplate();
        // The person's own choice (My Account) first, then their reseller's (Theme Manager)
        $css = ui_template::GetStylePref($user['userid']);
        if ($css === '' || !is_file("etc/styles/" . $template . "/css/" . $css . ".css")) {
            $css = (string) $user['usercss'];
        }
        // No variation chosen, or one this template does not have (it was chosen for
        // another template): the template's own first variation
        if ($css === '' || !is_file("etc/styles/" . $template . "/css/" . $css . ".css")) {
            $css = ui_template::DefaultCSS($template);
        }
        return "etc/styles/" . $template . "/css/" . $css . ".css";
    }

}

?>
