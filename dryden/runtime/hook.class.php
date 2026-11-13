<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Integration hooks class.
 * @package zpanelx
 * @subpackage dryden -> runtime
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-06
 */
/**
 * Runs module hook files (modules/NAME/hooks/HOOK.hook.php).
 */
class runtime_hook {

    /**
     * Executes a hook file at the called position.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * Includes the HOOK.hook.php file of every module's hooks folder and logs each one.
     *
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $name The name of the hook of which to execute.
     * @return void
     */
    static function Execute($name) {
        // A page runs some hooks dozens of times (the template engine twice for each of its tags):
        // which modules have a file for a hook is looked up once per page. (The daemon and the
        // command line tools look every time: a module can be installed while they run.)
        static $found = array();
        $mod_folder = "modules/*/hooks/{" . $name . ".hook.php}";
        if (PHP_SAPI === 'cli' || !isset($found[$name])) {
            $found[$name] = glob($mod_folder, GLOB_BRACE) ?: array();
        }
        if (!$found[$name]) {
            return;
        }
        $hook_log = new debug_logger();
        $hook_log->method = ctrl_options::GetSystemOption('logmode');
        $hook_log->logcode = "861";
        foreach ($found[$name] as $hook_file) {
            if (file_exists($hook_file)) {
                $hook_log->detail = "Execute hook file (" . $hook_file . ")";
                try {
                  include $hook_file;
                } catch (Exception $e) {
                  $hook_log->detail .= ' -> Exception(' . $e->getMessage() . ') :(';
                }
                $hook_log->writeLog();
            }
        }
    }

}

?>
