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
 * Template tag <# ui_tpl_clientipaddress #>: the visitor's IP address.
 */
class ui_tpl_clientipaddress {

    /**
     * Returns the IP address of the browser making the request.
     *
     * @return string The client IP ($_SERVER['REMOTE_ADDR']).
     */
    public static function Template() {
        return sys_monitoring::ClientIPAddress();
    }

}

?>
