<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 *
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
/**
 * MySQL Databases module: lists, creates and deletes the account's MySQL databases.
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True when the database name is already taken (shown by getResult()). */
    static $alreadyexists;
    /** @var bool|null True when no database name was given (shown by getResult()). */
    static $blank;
    /** @var bool|null True when the database name is not valid (shown by getResult()). */
    static $badname;
    /** @var bool|null True after a database was created or deleted (shown by getResult()). */
    static $ok;

    /**
     * The 'worker' methods.
     */
	// tg - Set user name input max characters minus prefix
    /**
     * Returns how many characters the database name may have (<@ MaxCharAllowed @>).
     *
     * MySQL names are at most 32 characters, including the "<username>_" prefix.
     *
     * @global db_driver $zdbh The panel's database connection (declared, not used).
     * @global runtime_controller $controller The request controller (declared, not used).
     * @return int|false The number of characters left, or false when the prefix alone is longer than 32.
     */
    static function getMaxCharAllowed()
    {
        global $zdbh;
        global $controller;
		
        // $uid and $username were never set here (PHP warnings); use the logged-in user
        $currentuser = ctrl_users::GetUserDetail();
        $username = $currentuser['username'] . "_";

        if (strlen($username) < 33)
		{
			$prefixsize = strlen($username);
			$maxChar = 32-$prefixsize;

            return $maxChar;
        } else {
            return false;
        }
    }
	
    /**
     * Lists an account's databases.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return array<int, array<string, mixed>>|false One row per database: 'mysqlid', 'totaldb' (number of
     *         users mapped to it), 'mysqlname', 'mysqlsize' (bytes) and 'mysqlfriendlysize'; false when there are none.
     */
    static function ListDatabases($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_mysql_databases WHERE my_acc_fk=:uid AND my_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            while ($rowmysql = $sql->fetch()) {
                $numrowdb = $zdbh->query("SELECT COUNT(*) FROM x_mysql_dbmap WHERE mm_acc_fk=" . $rowmysql['my_acc_fk'] . " AND mm_database_fk=" . $rowmysql['my_id_pk'] . "")->fetch();
                $res[] = array('mysqlid' => $rowmysql['my_id_pk'],
                    'totaldb' => $numrowdb[0],
                    'mysqlname' => $rowmysql['my_name_vc'],
                    'mysqlsize' => $rowmysql['my_usedspace_bi'],
                    'mysqlfriendlysize' => fs_director::ShowHumanFileSize($rowmysql['my_usedspace_bi']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Returns one database by its ID (any account's: the caller checks ownership).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $mysqlid The database ID (x_mysql_databases.my_id_pk).
     * @return array<int, array<string, mixed>>|false A list with the one row: 'mysqlid', 'mysqlname',
     *         'mysqlsize' and 'mysqlfriendlysize'; false when it does not exist or was deleted.
     */
    static function ListCurrentDatabases($mysqlid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_mysql_databases WHERE my_id_pk=:mysqlid AND my_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':mysqlid', $mysqlid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':mysqlid', $mysqlid);
            $res = array();
            $sql->execute();
            while ($rowmysql = $sql->fetch()) {
                $res[] = array('mysqlid' => $rowmysql['my_id_pk'],
                    'mysqlname' => $rowmysql['my_name_vc'],
                    'mysqlsize' => $rowmysql['my_usedspace_bi'],
                    'mysqlfriendlysize' => fs_director::ShowHumanFileSize($rowmysql['my_usedspace_bi']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Creates a database "<username>_<name>" for an account and records it.
     *
     * Runs the OnBeforeCreateDatabase / OnAfterCreateDatabase hooks and sets $ok; the
     * error flags are set by CheckCreateForErrors().
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (declared, not used).
     * @param int|string $uid The account ID.
     * @param string $databasename The name without the prefix (spaces are removed, lower-cased).
     * @return bool True when created, false on a validation or database error.
     */
    static function ExecuteCreateDatabase($uid, $databasename)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $databasename = strtolower(str_replace(' ', '', $databasename));
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrors($currentuser['username'], $databasename))) {
            return false;
        }
        runtime_hook::Execute('OnBeforeCreateDatabase');
        try {
            $db = $zdbh->mysqlRealEscapeString($currentuser['username'] . "_" . $databasename);
            $sql = $zdbh->prepare("CREATE DATABASE `$db` DEFAULT CHARACTER SET 'utf8' COLLATE 'utf8_general_ci';");
            $sql->execute();
            $sql = $zdbh->prepare("FLUSH PRIVILEGES");
            $sql->execute();
            $sql = $zdbh->prepare("INSERT INTO x_mysql_databases (
									my_acc_fk,
									my_name_vc,
									my_created_ts) VALUES (
									:userid,
									:name,
									:time)");
            $time = time();
            $name = $currentuser['username'] . "_" . $databasename;

            $sql->bindParam(':userid', $currentuser['userid']);
            $sql->bindParam(':time', $time);
            $sql->bindParam(':name', $name);
            $sql->execute();
        } catch (PDOException $e) {
            return false;
        }
        runtime_hook::Execute('OnAfterCreateDatabase');
        self::$ok = true;
        return true;
    }

    /**
     * Checks a new database name, setting $blank, $badname or $alreadyexists on a problem.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $username The account's user name (the prefix).
     * @param string $databasename The name without the prefix.
     * @return bool True when the name can be used.
     */
    static function CheckCreateForErrors($username, $databasename)
    {
        global $zdbh;
        // Check to make sure the database name is not blank before we go any further...
        if ($databasename == '') {
            self::$blank = true;
            return false;
        }
        // Check for invalid username
        if (!self::IsValidUserName($databasename)) {
            self::$badname = true;
            return false;
        }
        // Check to make sure the database is not a duplicate...
        $sql = "SELECT COUNT(*) FROM x_mysql_databases WHERE my_name_vc=:dbName AND my_deleted_ts IS NULL";
        $dbName = $username . "_" . $databasename;
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':dbName', $dbName);

        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                self::$alreadyexists = true;
                return false;
            }
        }

        return true;
    }

    /**
     * Drops a database and marks its record deleted (removes its user mappings too).
     *
     * Runs the OnBeforeDeleteDatabase / OnAfterDeleteDatabase hooks and sets $ok. Does
     * not check ownership: doConfirmDeleteDatabase() does.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $my_id_pk The database ID.
     * @return bool True when deleted, false on a database error.
     */
    static function ExecuteDeleteDatabase($my_id_pk)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeDeleteDatabase');
        $numrows = $zdbh->prepare("SELECT my_name_vc FROM x_mysql_databases WHERE my_id_pk=:my_id_pk");
        $numrows->bindParam(':my_id_pk', $my_id_pk);
        $numrows->execute();
        $rowmysql = $numrows->fetch();
        try {
            $my_name_vc = $zdbh->mysqlRealEscapeString($rowmysql['my_name_vc']);
            $sql = $zdbh->prepare("DROP DATABASE IF EXISTS `$my_name_vc`;");
            //$sql->bindParam(':my_name_vc', $rowmysql['my_name_vc'], PDO::PARAM_STR);
            $sql->execute();

            $sql = $zdbh->prepare("FLUSH PRIVILEGES");
            $sql->execute();

            $sql = $zdbh->prepare("UPDATE x_mysql_databases SET my_deleted_ts = :time WHERE my_id_pk = :my_id_pk");
            $sql->bindParam(':time', time());
            $sql->bindParam(':my_id_pk', $my_id_pk);
            $sql->execute();

            $sql = $zdbh->prepare("DELETE FROM x_mysql_dbmap WHERE mm_database_fk=:my_id_pk");
            $sql->bindParam(':my_id_pk', $my_id_pk);
            $sql->execute();
        } catch (PDOException $e) {
            return false;
        }
        runtime_hook::Execute('OnAfterDeleteDatabase');
        self::$ok = true;
        return true;
    }

    /**
     * Checks a database name (without the prefix).
     *
     * @param string $username The name: letters, digits and dashes, up to 63, not starting or ending with a dash.
     * @return bool True when valid.
     */
    static function IsValidUserName($username)
    {
        if (!preg_match('/^[a-z\d][a-z\d-]{0,62}$/i', $username) || preg_match('/-$/', $username)) {
            return false;
        }
        return true;
    }

    /**
     * End 'worker' methods.
     */

    /**
     * Webinterface sudo methods.
     */

    /**
     * Creates a database from the form (POST action=CreateDatabase, field inDatabase).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool What ExecuteCreateDatabase() returns.
     */
    static function doCreateDatabase()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        return self::ExecuteCreateDatabase($currentuser['userid'], $formvars['inDatabase']);
    }

    /**
     * Handles a Delete button in the database list (POST action=DeleteDatabase).
     *
     * Redirects to the confirmation page (&show=Delete&other=<id>) and exits when one
     * of the account's databases was chosen.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when no Delete button was pressed (otherwise it does not return).
     */
    static function doDeleteDatabase()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListDatabases($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['mysqlid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['mysqlid'] . "");
                exit;
            }
        }
        return true;
    }

    /**
     * Deletes the database confirmed on the delete page (POST action=ConfirmDeleteDatabase, field inDelete).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool False when the database is not the account's own, else what ExecuteDeleteDatabase() returns.
     */
    static function doConfirmDeleteDatabase()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inDelete']) || !ctrl_ownership::Owns('x_mysql_databases', 'my_id_pk', 'my_acc_fk', $formvars['inDelete'], ' AND my_deleted_ts IS NULL')) {
            return false;
        }
        return self::ExecuteDeleteDatabase($formvars['inDelete']);
    }

    /**
     * Returns the logged-in account's databases (<% loop DatabaseList %>, <% if DatabaseList %>).
     *
     * @return array<int, array<string, mixed>>|false See ListDatabases().
     */
    static function getDatabaseList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListDatabases($currentuser['userid']);
    }

    /**
     * Tells whether to show the delete confirmation (<% if isDeleteDatabase %>).
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|null $uid Optional. The account ID; default null = the logged-in user.
     * @return bool|null True when ?show=Delete and the database in ?other= is the account's; null when it is not theirs.
     */
    static function getisDeleteDatabase($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete Database Account.
        // This shall avoid exposing database based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

    	$sql = "SELECT * FROM x_mysql_databases WHERE my_acc_fk=:userid AND my_id_pk=:editedUsrID AND my_deleted_ts IS NULL";
    	$numrows = $zdbh->prepare($sql);
    	$numrows->bindParam(':userid', $currentuser['userid']);
		$numrows->bindParam(':editedUsrID', $urlvars['other']);
    	$numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }

        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Delete");
    }

    /**
     * Tells whether to show the create form (<% if isCreateDatabase %>): when no ?show= is given.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisCreateDatabase()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        return !isset($urlvars['show']);
    }

    /**
     * Returns the logged-in user's name, the database name prefix (<@ CurrentUserName @>).
     *
     * @return string The user name.
     */
    static function getCurrentUserName()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return $currentuser['username'];
    }

    /**
     * Returns the name of the database in ?other= (<@ EditCurrentDatabaseName @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string|null The name, '' when no ?other= is given, null when the database does not exist.
     */
    static function getEditCurrentDatabaseName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentDatabases($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['mysqlname'];
        } else {
            return '';
        }
    }

    /**
     * Returns the ID of the database in ?other= (<@ EditCurrentDatabaseID @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string|null The ID, '' when no ?other= is given, null when the database does not exist.
     */
    static function getEditCurrentDatabaseID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentDatabases($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['mysqlid'];
        } else {
            return '';
        }
    }

    /**
     * Tells whether the account may create another database (<% if QuotaLimit %>).
     *
     * @return bool True when the quota is unlimited (negative) or not used up.
     */
    static function getQuotaLimit()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['mysqlquota'] < 0 ) or //-1 = unlimited
                ($currentuser['mysqlquota'] > ctrl_users::GetQuotaUsages('mysql', $currentuser['userid']));
    }

    /**
     * Returns the database usage pie chart image (<@ MysqlUsagepChart @>).
     *
     * @global runtime_controller $controller The request controller (declared, not used).
     * @return string An <img> tag: the pChart pie of free/used, or the "unlimited" picture.
     */
    static function getMysqlUsagepChart()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $maximum = $currentuser['mysqlquota'];
        if ($maximum < 0) { //-1 = unlimited
            return '<img src="' . ui_template::AssetPath('img/misc/unlimited.png') . '" alt="' . ui_language::translate('Unlimited') . '"/>';
        } else {
            $used = ctrl_users::GetQuotaUsages('mysql', $currentuser['userid']);
            $free = max($maximum - $used, 0);
            return '<img src="etc/lib/pChart2/sentora/z3DPie.php?score=' . $free . '::' . $used
                    . '&labels=Free: ' . $free . '::Used: ' . $used
                    . '&legendfont=verdana&legendfontsize=8&imagesize=240::190&chartsize=120::90&radius=100&legendsize=150::160"'
                    . ' alt="' . ui_language::translate('Pie chart') . '"/>';
        }
    }

    /**
     * Returns the message for the last action (<@ Result @>): the first error flag set, else success.
     *
     * @return string|null The alert HTML, or null when there is nothing to show.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return ui_sysmessage::shout(ui_language::translate("You need to specify a database name to create your database."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return ui_sysmessage::shout(ui_language::translate("Your MySQL database name is not valid. Please enter a valid MySQL database name."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("A database with that name already appears to exsist."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your databases have been saved successfully!"), "zannounceok");
        }
        return;
    }

    /**
     * Webinterface sudo methods.
     */
}
