<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Development class enables PHP error reporting for ease of development!
 * @package zpanelx
 * @subpackage dryden -> debug
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-06
 */
class debug_phperrors {

    /**
     * Sets PHP error reporting to ON and displays ALL errors if set to 'dev' otherwise will disable all errors.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $mode Either 'dev' or 'prod', is left blank 'prod' is used by default.
     * @return void
     */
    static function SetMode($mode = '') {
        if ($mode == 'dev') {
            error_reporting(E_ALL);
        } else {
            // Production: never show errors in the page, but still write fatal
            // errors to the Apache error log. Reporting nothing at all meant a
            // fatal error gave a blank page with no trace anywhere.
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');
            error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR);
        }
    }

}

?>
