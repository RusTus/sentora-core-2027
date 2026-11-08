<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * The controller class!
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
 * Request controller: holds the request data and dispatches module actions.
 *
 * Init() snapshots GET, POST, SESSION and COOKIE, loads the requested module and
 * calls the module_controller::do<action>() method for a valid POST action.
 */
class runtime_controller
{

    /**
     * @var array<int, array<string, mixed>> All current request 'get' variables (index 0 holds $_GET).
     */
    private $vars_get;

    /**
     * @var array<int, array<string, mixed>> All current request 'post' variables (index 0 holds $_POST).
     */
    private $vars_post;

    /**
     * @var array<int, array<string, mixed>> All current request 'session' variables (index 0 holds $_SESSION).
     */
    private $vars_session;

    /**
     * @var array<int, array<string, mixed>> All current request 'cookie' variables (index 0 holds $_COOKIE).
     */
    private $vars_cookie;

    /**
     * Get the latest requests and updates the values avaliable to the model/view.
     *
     * Shows the login template when nobody is logged in, loads the requested module
     * and, for a CSRF-valid POST with an 'action', calls module_controller::do<action>().
     *
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return void
     */
    public function Init()
    {

        //Set class varables
        $this->vars_get = array($_GET);
        $this->vars_post = array($_POST);
        $this->vars_session = array($_SESSION);
        $this->vars_cookie = array($_COOKIE);

        //Here we get the users information
        $user = ctrl_users::GetUserDetail();

        if (!isset($this->vars_session[0]['zpuid'])) {
            ui_module::GetLoginTemplate();
        }

        if (isset($this->vars_get[0]['module'])) {
            ui_module::getModule($this->GetCurrentModule());
        }
        if (isset($this->vars_get[0]['action'])) {
            if (ctrl_groups::CheckGroupModulePermissions($user['usergroupid'], ui_module::GetModuleID())) {
                // Sentora: every action changes something, so it must be a form post with
                // this session's token: a link or a form on another site can not trigger one
                // (several modules never checked the token themselves)
                if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !runtime_csfr::Valid()) {
                    runtime_csfr::Protect();
                }
                if ((class_exists('module_controller', FALSE)) && (method_exists('module_controller', 'do' . $this->vars_get[0]['action']))) {
                    call_user_func(array('module_controller', 'do' . $this->vars_get[0]['action']));
                } else {
                    echo ui_sysmessage::shout("No 'do" . runtime_xss::xssClean($this->vars_get[0]['action']) . "' class exists - Please create it to enable controller actions and runtime placeholders within your module.");
                }
            }
        }
        return;
    }

    /**
     * Returns a vlaue from one of the requested type.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $type The type of request data to return.
     * @param string $name The named key of the array.
     * @return mixed The request value if it is set (string, array or any session value), otherwise false.
     */
    public function GetControllerRequest($type, $name)
    {
        if ($type == 'FORM') {
            if (isset($this->vars_post[0][$name])) {
                return $this->vars_post[0][$name];
            } else {
                return false;
            }
        } elseif ($type == 'URL') {
            if (isset($this->vars_get[0][$name])) {
                return $this->vars_get[0][$name];
            } else {
                return false;
            }
        } elseif ($type == 'USER') {
            if (isset($this->vars_session[0][$name])) {
                return $this->vars_session[0][$name];
            } else {
                return false;
            }
        } else {
            if (isset($this->vars_cookie[0][$name])) {
                return $this->vars_cookie[0][$name];
            } else {
                return false;
            }
        }
        return false;
    }

    /**
     * Grabs the list of all controller requests for a given type.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $type What type of requests would you like to see? (URL, USER, FORM or COOKIE)
     * @return array<string, mixed> List of all set variables for the requested type.
     */
    public function GetAllControllerRequests($type = "URL")
    {
        if ($type == 'FORM') {
            return $this->vars_post[0];
        } elseif ($type == 'URL') {
            return $this->vars_get[0];
        } elseif ($type == 'USER') {
            return $this->vars_session[0];
        } else {
            return $this->vars_cookie[0];
        }
        return false;
    }

    /**
     * Gets the current framework requested action.
     * @return string|false The 'action' URL value, or false when none is set.
     */
    public function GetAction()
    {
        if (isset($this->vars_get[0]['action']))
            return $this->vars_get[0]['action'];
        return false;
    }

    /**
     * Gets the current framework requested module 'options'.
     * @return string|false The 'options' URL value, or false when none is set.
     */
    public function GetOptions()
    {
        if (isset($this->vars_get[0]['options']))
            return $this->vars_get[0]['options'];
        return false;
    }

    /**
     * Gets and returns the name of the current module.
     * @return string|false The 'module' URL value, or false when none is set.
     */
    public function GetCurrentModule()
    {
        if (isset($this->vars_get[0]['module']))
            return $this->vars_get[0]['module'];
        return false;
    }

    /**
     * Displays Controller debug infomation (mainly for module development and debugging)
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global mixed $script_memory Never assigned anywhere in the panel (null); passed to debug_execution::ScriptMemoryUsage(), which ignores it.
     * @global float $starttime The microtime of when the script started executing (set in dryden/loader.inc.php).
     * @return string|false HTML output of the debug infomation, or false when 'debug' is not in the URL.
     */
    public function OutputControllerDebug()
    {
        global $script_memory;
        global $starttime;
        if (isset($this->vars_get[0]['debug'])) {
            ob_start();
            var_dump($this->GetAllControllerRequests('URL'));
            $set_urls = ob_get_contents();
            ob_end_clean();
            ob_start();
            var_dump($this->GetAllControllerRequests('FORM'));
            $set_forms = ob_get_contents();
            ob_end_clean();
            ob_start();
            var_dump($this->GetAllControllerRequests('USER'));
            $set_sessions = ob_get_contents();
            ob_end_clean();
            ob_start();
            var_dump($this->GetAllControllerRequests('COOKIE'));
            $set_cookies = ob_get_contents();
            ob_end_clean();
            $classes_loaded = debug_execution::GetLoadedClasses();
            ob_start();
            print_r($classes_loaded);
            $classes_array = ob_get_contents();
            ob_end_clean();
            $sql_queries = debug_execution::GetSQLQueriesExecuted();
            ob_start();
            print_r($sql_queries);
            $sql_array = ob_get_contents();
            ob_end_clean();
            $mtime = microtime();
            $mtime = explode(" ", $mtime);
            $mtime = $mtime[1] + $mtime[0];
            $endtime = $mtime;
            $totaltime = ($endtime - $starttime);
            runtime_hook::Execute('OnDisplayRuntimeDebug');
            return "<h1>Controller Debug Mode</h1><strong>PHP Script Memory Usage:</strong> " . debug_execution::ScriptMemoryUsage($script_memory) . "<br><strong>Script Execution Time: </strong> " . $totaltime . "<br><br><strong>URL Variables set:</strong><br><pre>" . $set_urls . "</pre><strong>POST Variables set:</strong><br><pre>" . $set_forms . "</pre><strong>SESSION Variables set:</strong><br><pre>" . $set_sessions . "</pre><strong>COOKIE Variables set:</strong><br><pre>" . $set_cookies . "</pre><br><br><strong>Loaded classes (Total: " . count($classes_loaded) . "):</strong><pre>" . $classes_array . "</pre><br><br><strong>SQL queries executed (Total: " . count($sql_queries) . "):</strong><pre>" . $sql_array . "</pre>";
        } else {
            return false;
        }
    }

    /**
     * Checks if the current script is running in CLI mode (eg. as a cron job)
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return bool True when there is no HTTP User-Agent header.
     */
    static function IsCLI()
    {
        if (isset($_SERVER['HTTP_USER_AGENT']))
            return false;
        return true;
    }

    /**
     * Used in hooks to communicate with the modules controller.ext.php
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $module_path The full path to the module's hooks directory (the hook file's dirname).
     * @return void
     */
    static function ModuleControllerCode($module_path)
    {
        $raw_path = str_replace("\\", "/", $module_path);
        $module_path = str_replace("/hooks", "/code/", $raw_path);
        $rawroot_path = str_replace("\\", "/", dirname(__FILE__));
        $root_path = str_replace("/dryden/runtime", "/", $rawroot_path);
        require_once $root_path . 'dryden/loader.inc.php';
        require_once $root_path . 'cnf/db.php';
        require_once $root_path . 'inc/dbc.inc.php';
        if (file_exists($module_path . 'controller.ext.php')) {
            require_once $module_path . 'controller.ext.php';
        } else {
            $hook_log = new debug_logger();
            $hook_log->method = ctrl_options::GetSystemOption('logmode');
            $hook_log->logcode = "611";
            $hook_log->detail = "No hook controller.ext.php avaliable to import in (" . $root_path . 'controller.ext.php' . ")";
            $hook_log->writeLog();
        }
    }

}

?>
