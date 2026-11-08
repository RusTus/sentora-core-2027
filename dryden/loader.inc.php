<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Module loader script for detecting and displaying the correct module using the Dryden framework, this handles the autolaoding of classes.
 * @package zpanelx
 * @subpackage dryden -> core
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-06
 */
global $starttime;
$mtime = explode(' ', microtime());
$mtime = $mtime[1] + $mtime[0];
$starttime = $mtime;
$class_name = null;

/**
 * Class autoloader: load the dryden class file of a class (ctrl_users -> dryden/ctrl/users.class.php).
 *
 * Underscores in the class name become directory separators. Does nothing when
 * the file does not exist. Registered with spl_autoload_register() below.
 *
 * @param string $class_name The class being loaded.
 * @return void
 */
function x__autoload($class_name)
{
    // Absolute path so classes still load if the working directory changes
    $path = __DIR__ . '/' . str_replace('_', '/', $class_name) . '.class.php';
    if (file_exists($path)) {
        require_once $path;
    }
}

spl_autoload_register('x__autoload');

if (isset($_GET['module'])) {
    $CleanModuleName = fs_protector::SanitiseFolderName($_GET['module']);

    $ControlerPath = 'modules/' . $CleanModuleName . '/code/controller.ext.php';
    if (file_exists($ControlerPath)) {
        require_once $ControlerPath;
    }

    $ModulePath = 'modules/' . $CleanModuleName . '/code/' . $class_name . '.class.php';
    if (file_exists($ModulePath)) {
        require_once $ModulePath;
    }
}
