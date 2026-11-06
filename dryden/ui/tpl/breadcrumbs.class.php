<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Generic template place holder class.
 * @package zpanelx
 * @subpackage dryden -> ui -> tpl
 * @version 1.1.0
 * @author Jason Davis (jason.davis.fl@gmail.com)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
/**
 * Template tag <# ui_tpl_breadcrumbs #>: the breadcrumb trail.
 */
class ui_tpl_breadcrumbs {

    /**
     * Builds the Home > category > module breadcrumb for the current module page.
     *
     * @global db_driver $zdbh The panel's database connection (declared, not used).
     * @global runtime_controller $controller The request controller (reads the module URL parameter).
     * @return string A <ul class="breadcrumb"> list, or an empty string on the dashboard or when the module is unknown.
     */
    public static function Template() {
        global $zdbh, $controller;
        $line = '';

        if($controller->GetControllerRequest('URL', 'module')){

            $module = $controller->GetControllerRequest('URL', 'module');
            $moduleRow = ui_module::GetModuleCategoryName();

            if($moduleRow){
                $catUrl = strtolower(str_replace(' ', '-', $moduleRow['mc_name_vc']));
                $line .= '<ul class="breadcrumb">';
                $line .= '  <li><a href=".">Home</a></li>';
                $line .= '  <li><a href="./#' .$catUrl. '">' .$moduleRow['mc_name_vc']. '</a></li>';
                $line .= '  <li class="active">' .$moduleRow['mo_name_vc']. '</li>';
                $line .= '</ul>';
            }
        }

        return $line;
    }
}
?>