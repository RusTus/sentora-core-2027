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
class ui_tpl_csspath {

    public static function Template() {
        $user = ctrl_users::GetUserDetail();
        $template = ui_template::GetUserTemplate();
        $css = (string) $user['usercss'];
        // No variation chosen, or one this template does not have (it was chosen for
        // another template): the template's own first variation
        if ($css === '' || !is_file("etc/styles/" . $template . "/css/" . $css . ".css")) {
            $css = ui_template::DefaultCSS($template);
        }
        return "etc/styles/" . $template . "/css/" . $css . ".css";
    }

}

?>
