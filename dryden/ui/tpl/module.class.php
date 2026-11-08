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
 * Template tag <# ui_tpl_module #>: the current module's page.
 */
class ui_tpl_module {

    /**
     * Returns the template code (module.zpm) of the module named in the URL.
     *
     * @global runtime_controller $controller The request controller (reads the module URL parameter).
     * @return string|null The module template, an error text when it cannot be loaded, or null when no module is requested (dashboard).
     */
    public static function Template() {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'module')) {
            return ui_module::getModule($controller->getCurrentModule());
        }
    }

}

?>
