<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Class to display controller debugging in the template layer.
 * @package zpanelx
 * @subpackage dryden -> ui
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
/**
 * Template tag <# ui_controllerdebug #>: the controller debug panel (shown when 'debug' is in the URL).
 */
class ui_controllerdebug extends runtime_controller {

    /**
     * Template placeholder to display controller debug infomation.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global runtime_controller $controller The request controller.
     * @return string|null HTML output to display the controller debug infomation in a pretty way, or null when debugging is off.
     */
    static function Template() {
        global $controller;
        if ($controller->OutputControllerDebug()) {
            $controllerdebug = $controller->OutputControllerDebug();
            $retval = "<!-- BEGIN DEBUG -->
	<div class=\"zdebug\" id=\"zdebug\">" . $controllerdebug . "</div>
	<!-- END DEBUG -->";
            return $retval;
        }
    }

}

?>
