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
 * Template tag <# ui_tpl_date #>: today's date.
 */
class ui_tpl_date {

    /**
     * Returns today's date written out in full.
     *
     * @return string The date, e.g. 'Friday November 6th, 2026'.
     */
    public static function Template() {
        return ui_language::Date('l j F Y'); // the account's language (and day-first order: right for every language)
    }

}

?>
