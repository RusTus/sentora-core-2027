<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Main module interface class.
 * @package zpanelx
 * @subpackage dryden -> ui
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-06
 */
/**
 * Loads modules and reads their details (module.zpm, module.xml, x_modules table).
 */
class ui_module {

    /**
     * Creates the object (does nothing; the class is used statically).
     */
    function __construct() {

    }

    /**
     * Checks that the module exists.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $name Name of the module to check that exists.
     * @return bool True when modules/<name>/module.zpm exists and the user's group may use the current module.
     */
    static function CheckModuleExists($name) {
        $user = ctrl_users::GetUserDetail();
        if (file_exists("modules/" . $name . "/module.zpm"))
            if (ctrl_groups::CheckGroupModulePermissions($user['usergroupid'], self::GetModuleID()))
                return true;
        return false;
    }

    /**
     * Returns the module template code.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $name Name of the module.
     * @return string|false|null The module.zpm contents (false when unreadable), or null when the module does not exist or is not allowed.
     */
    static function GetModuleContent($name) {
        if (self::CheckModuleExists($name)) {
            return fs_filehandler::ReadFileContents("modules/" . $name . "/module.zpm");
        }
    }

    /**
     * Handles the GetModule control, if unable to load the module will handle the error too!
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $module The name of the module to load.
     * @return string|false|null The module template, the text "Unable to find requested module!", or false/null as from GetModuleContent().
     */
    static function GetModule($module) {
        if (self::CheckModuleExists($module)) {
            $retval = self::GetModuleContent($module);
        } else {
            runtime_hook::Execute('OnFailedModuleLoad');
            $retval = "Unable to find requested module!";
        }
        return $retval;
    }

    /**
     * Gathers module infomation from the modules XML file and adds the details to the DB.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The panel's database connection.
     * @global debug_logger $zlo The logger (declared, not used).
     * @param string $module The name of the module (folder) of which to import the module infomation in for.
     * @return bool True when the row was inserted, false when module.xml could not be parsed or the insert threw.
     */
    static function ModuleInfoToDB($module) {
        global $zdbh;
        global $zlo;
        runtime_hook::Execute('OnBeforeModuleInfoToDB');
        $mod_xml = "modules/$module/module.xml";
        try {
            $mod_config = new xml_reader(fs_filehandler::ReadFileContents($mod_xml));
            $mod_config->Parse();
            $module_name = $mod_config->document->name[0]->tagData;
            $module_version = $mod_config->document->version[0]->tagData;
            $module_description = $mod_config->document->desc[0]->tagData;
            $module_defaultcat = $mod_config->document->defaultcat[0]->tagData;
            // The type tag is optional (most module.xml files have none): one of the three
            // known types, otherwise "user" (the old check compared with true, so let anything through)
            $module_type = isset($mod_config->document->type[0]) ? trim((string) $mod_config->document->type[0]->tagData) : '';
            if (!in_array($module_type, array('user', 'system', 'modadmin'), true)) {
                $module_type = "user";
            }
            $sql = $zdbh->prepare("SELECT mc_id_pk FROM x_modcats WHERE mc_name_vc = :module_defaultcat");
            $sql->bindParam(':module_defaultcat', $module_defaultcat);
            $status = $sql->execute();
            $result = $sql->fetch();

            if ($result) {
                $cat_fk = $result['mc_id_pk'];
            } else {
                $cat_fk = 2;
            }
            $sql = $zdbh->prepare("INSERT INTO x_modules (mo_name_vc, mo_category_fk, mo_version_in, mo_folder_vc, mo_installed_ts, mo_type_en, mo_desc_tx) VALUES (:module_name, :cat_fk, :module_version, :module, " . time() . ", :module_type,  :module_description)");
            $sql->bindParam(':module_name', $module_name);
            $sql->bindParam(':cat_fk', $cat_fk);
            $sql->bindParam(':module_version', $module_version);
            $sql->bindParam(':module', $module);
            $sql->bindParam(':module_type', $module_type);
            $sql->bindParam(':module_description', $module_description);

            $sql->execute();
            return true;
        } catch (Exception $e) {
            return false;
        }
        runtime_hook::Execute('OnAfterModuleInfoToDB');
    }

    /**
     * This class scans the module directory and will return an array of new modules that are not yet in the database.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param bool $init Upon finding modules that don't exist in the database, add them!
     * @return array<int, string> List of all new modules (folder names).
     */
    static function ScanForNewModules($init = false) {
        global $zdbh;
        $new_module_list = array();
        $rootdir = ctrl_options::GetSystemOption('sentora_root') . 'modules';
        $handle = @opendir($rootdir);
        $chkdir = $rootdir . '/';
        if ($handle) {
            while ($file = readdir($handle)) {
                if ($file != "." && $file != "..") {
                    if (is_dir($chkdir . $file)) {

                        $sql = $zdbh->prepare("SELECT mo_id_pk FROM x_modules WHERE mo_folder_vc = :file");
                        $sql->bindParam(':file', $file);
                        $status = $sql->execute();
                        $match_module = $sql->fetch();

                        if (!$match_module) {
                            array_push($new_module_list, $file);
                        }
                    }
                }
            }
            closedir($handle);
        }
        if ($init == true) {
            foreach ($new_module_list as $modules_to_import) {
                ui_module::ModuleInfoToDB($modules_to_import);
            }
        }
        return $new_module_list;
    }

    /**
     * Checks to see if the specified module is enabled.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param string $modulename The name of the module of which to check.
     * @return bool
     */
    static function CheckModuleEnabled($modulename) {
        global $zdbh;

        $numrows = $zdbh->prepare("SELECT mo_name_vc, mo_enabled_en FROM x_modules WHERE mo_name_vc = :module");
        $numrows->bindParam(':module', $modulename);
        $numrows->execute();
        $retval = $numrows->fetch();

        if ($retval['mo_enabled_en'] == "true") {
            $retvalEnd = true;
        } else {
            $retvalEnd = false;
        }
        return $retvalEnd;
    }

    /**
     * Returns the name of the current module.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The ZPX database handle.
     * @return string|null The name of the currently loaded (active) module, or null when it is not in x_modules.
     */
    public static function GetModuleName() {
        global $controller;
        global $zdbh;
        $bindArray = array(
            ':module' => $controller->GetControllerRequest('URL', 'module'),
        );
        $retval = $zdbh->bindQuery("SELECT mo_name_vc FROM x_modules WHERE mo_folder_vc = :module", $bindArray);
        $moduleInfo = $zdbh->returnRow();
        return $moduleInfo['mo_name_vc'];
    }

    /**
     * This class returns the database ID of the currently loaded (active) module.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The ZPX database handle.
     * @return int|string|null The module ID of the currently loaded (active) module (as the database driver returns it), or null when not found.
     */
    static function GetModuleID() {
        global $controller;
        global $zdbh;
        $bindArray = array(
            ':module' => $controller->GetControllerRequest('URL', 'module'),
        );
        $retval = $zdbh->bindQuery("SELECT mo_id_pk FROM x_modules WHERE mo_folder_vc = :module", $bindArray);
        $moduleInfo = $zdbh->returnRow();
        return $moduleInfo['mo_id_pk'];
    }

    /**
     * This class returns the folder name of the current module.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The ZPX database handle.
     * @return string|null The modules folder name as it appears in panel/modules/, or null when not found.
     */
    static function GetModuleFolderName() {
        global $controller;
        global $zdbh;
        $bindArray = array(
            ':module' => $controller->GetControllerRequest('URL', 'module'),
        );
        $retval = $zdbh->bindQuery("SELECT mo_folder_vc FROM x_modules WHERE mo_folder_vc = :module", $bindArray);
        $moduleInfo = $zdbh->returnRow();
        return $moduleInfo['mo_folder_vc'];
    }

    /**
     * This class returns the description of the current module.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The ZPX database handle.
     * @return string|null The module description from the database (originally improted from the module.xml file), or null when not found.
     */
    static function GetModuleDescription() {
        global $controller;
        global $zdbh;
        $bindArray = array(
            ':module' => $controller->GetControllerRequest('URL', 'module'),
        );
        $retval = $zdbh->bindQuery("SELECT mo_desc_tx FROM x_modules WHERE mo_folder_vc = :module", $bindArray);
        $moduleInfo = $zdbh->returnRow();
        return $moduleInfo['mo_desc_tx'];
    }

    /**
     * Returns the current module's Name and Category Name.
     * @since 10.1.0
     * @author Jason Davis (jason.davis.fl@gmail.com)
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The ZPX database handle.
     * @return array<string, mixed>|false Row with 'mo_category_fk', 'mo_name_vc' and 'mc_name_vc', or false when the module is not found.
     */
    public static function GetModuleCategoryName() {
        global $controller;
        global $zdbh;
        $bindArray = array(
            ':module' => $controller->GetControllerRequest('URL', 'module'),
        );
        $sql = 'SELECT  x_modules.mo_category_fk ,  x_modules.mo_name_vc ,  x_modcats.mc_name_vc
                    FROM  x_modcats
                    LEFT JOIN  x_modules
                    ON ( x_modcats.mc_id_pk = x_modules.mo_category_fk )
                    WHERE x_modules.mo_folder_vc = :module';

        $retval = $zdbh->bindQuery($sql , $bindArray);
        $moduleRow = $zdbh->returnRow();

        return $moduleRow;
    }


    /**
     * Checks to see if the specified module has updates.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param string $modulefolder The module folder of which to check updates for.
     * @return array<int, string>|false If updates are avaliable will return an array with the new version and download URL otherwise will return 'false'.
     */
    static function GetModuleHasUpdates($modulefolder) {
        global $zdbh;

        $bindArray = array(
            ':module' => $modulefolder,
        );
        $retvalQuery = $zdbh->bindQuery("SELECT mo_updateurl_tx, mo_updatever_vc FROM x_modules WHERE mo_folder_vc = :module", $bindArray);
        $retval = $zdbh->returnRow();

        if ($retval['mo_updatever_vc'] <> "") {
            $retval = array($retval['mo_updatever_vc'], $retval['mo_updateurl_tx']);
        } else {
            $retval = false;
        }
        return $retval;
    }

    /**
     * Returns an array of the XML tags from the module.xml file.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global debug_logger $zlo The Generic ZPX logging object (declared, not used).
     * @param string $modulefolder The module folder name of which to import the XML data from.
     * @return array<string, string>|false Will an array of the module XML data if the parsing of the document is successful otherwise will return 'false'.
     */
    static function GetModuleXMLTags($modulefolder) {
        global $zlo;
        $mod_xml = "modules/$modulefolder/module.xml";
        $info = array();
        try {
            $mod_config = new xml_reader(fs_filehandler::ReadFileContents($mod_xml));
            $mod_config->Parse();
            $info['name'] = $mod_config->document->name[0]->tagData;
            $info['version'] = $mod_config->document->version[0]->tagData;
            $info['desc'] = $mod_config->document->desc[0]->tagData;
            $info['authorname'] = $mod_config->document->authorname[0]->tagData;
            $info['authoremail'] = $mod_config->document->authoremail[0]->tagData;
            $info['authorurl'] = $mod_config->document->authorurl[0]->tagData;
            $info['updateurl'] = $mod_config->document->updateurl[0]->tagData;
            return $info;
        } catch (Exception $e) {
            return false;
        }
    }

}

?>
