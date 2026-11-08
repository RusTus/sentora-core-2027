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
 * Template tag <# ui_tpl_domainname #>: the panel's host name.
 */
class ui_tpl_domainname {

    /**
     * Returns the host name the panel was opened on, without "www.".
     *
     * @return string The HTTP Host header with any "www." removed.
     */
    public static function Template() {
        $domain = str_replace('www.', '', $_SERVER['HTTP_HOST']);
        return $domain;
    }

}

?>
