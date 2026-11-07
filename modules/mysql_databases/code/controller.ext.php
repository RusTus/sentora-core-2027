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

    // The 'worker' methods.
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
                $cq = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_dbmap WHERE mm_acc_fk = :a AND mm_database_fk = :d");
                $cq->execute(array(':a' => (int) $rowmysql['my_acc_fk'], ':d' => (int) $rowmysql['my_id_pk']));
                $numrowdb = array((int) $cq->fetchColumn());
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
        // Check for invalid username, and the length the form promises (the whole name, prefix
        // included, is kept to 32 characters, so it also fits as a MySQL user name)
        $max = self::getMaxCharAllowed();
        if (!self::IsValidUserName($databasename) || $max === false || strlen($databasename) > $max) {
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

            // bindValue: bindParam takes a variable, and PHP 8 refuses time() there (the database
            // was already dropped by then, so its record stayed and it kept showing in the list)
            $sql = $zdbh->prepare("UPDATE x_mysql_databases SET my_deleted_ts = :time WHERE my_id_pk = :my_id_pk");
            $sql->bindValue(':time', time(), PDO::PARAM_INT);
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

    // End 'worker' methods.

    // Webinterface sudo methods.

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
        // The package's limit on MySQL databases, checked here as well: the page only hides the
        // form at the limit, so a posted form could create more than the package allows
        if (!self::getQuotaLimit()) {
            return false;
        }
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
     * The users mapped to each of an account's databases.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return array<int, array<int, string>> Database ID => user names.
     */
    static function UsersByDatabase($uid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT m.mm_database_fk, u.mu_name_vc FROM x_mysql_dbmap m JOIN x_mysql_users u ON u.mu_id_pk = m.mm_user_fk
            WHERE m.mm_acc_fk = :uid AND u.mu_deleted_ts IS NULL ORDER BY u.mu_name_vc");
        $q->execute(array(':uid' => (int) $uid));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
            $out[(int) $r[0]][] = (string) $r[1];
        }
        return $out;
    }

    /**
     * Whether a module is installed and switched on, for the links to it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $folder The module's folder name.
     * @return bool
     */
    static function ModuleOn($folder)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_modules WHERE mo_folder_vc = :f AND mo_enabled_en = 'true'");
        $q->execute(array(':f' => $folder));
        return (int) $q->fetchColumn() > 0;
    }

    /**
     * The figures at the top (<@ Summary @>): databases against the package's limit, their size, and the users with access.
     *
     * @return string The HTML.
     */
    static function getSummary()
    {
        $me = ctrl_users::GetUserDetail();
        $list = self::ListDatabases($me['userid']);
        $list = $list ? $list : array();
        $size = 0;
        foreach ($list as $d) {
            $size += (int) $d['mysqlsize'];
        }
        $users = array();
        foreach (self::UsersByDatabase($me['userid']) as $names) {
            $users = array_merge($users, $names);
        }
        $max = (int) $me['mysqlquota'];
        $used = count($list);
        $pct = $max > 0 ? min(100, round($used / $max * 100)) : 0;
        $t = function ($s) { return htmlspecialchars(ui_language::translate($s), ENT_QUOTES, 'UTF-8'); };
        $quota = '<div class="my-tile my-quota' . ($max >= 0 && $used >= $max ? ' full' : '') . '">'
            . '<svg class="my-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>'
            . '<div><b>' . $used . ($max < 0 ? '' : ' <small>/ ' . $max . '</small>') . '</b><span>' . $t('Databases') . ($max < 0 ? ' &middot; ' . $t('no limit') : '') . '</span>'
            . ($max > 0 ? '<div class="my-bar" role="img" aria-label="' . $pct . '%"><i style="width:' . $pct . '%"></i></div>' : '') . '</div></div>';
        $tiles = array(
            array('Total size', fs_director::ShowHumanFileSize($size), '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>'),
            array('Users with access', (string) count(array_unique($users)), '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6"/>'),
        );
        foreach ($tiles as $x) {
            $quota .= '<div class="my-tile"><svg class="my-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $x[2] . '</svg>'
                . '<div><b>' . htmlspecialchars($x[1], ENT_QUOTES, 'UTF-8') . '</b><span>' . $t($x[0]) . '</span></div></div>';
        }
        return $quota;
    }

    /**
     * One card per database (<@ DatabaseCards @>): its name, size, the users who can use it, phpMyAdmin and Delete.
     *
     * @return string The HTML ('' when there are none).
     */
    static function getDatabaseCards()
    {
        $me = ctrl_users::GetUserDetail();
        $list = self::ListDatabases($me['userid']);
        if (!$list) {
            return '';
        }
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $t = function ($s) use ($h) { return $h(ui_language::translate($s)); };
        $users = self::UsersByDatabase($me['userid']);
        $pma = self::ModuleOn('phpmyadmin');
        $musers = self::ModuleOn('mysql_users');
        $prefix = $me['username'] . '_';
        $out = '';
        foreach ($list as $d) {
            $name = (string) $d['mysqlname'];
            $id = (int) $d['mysqlid'];
            $own = isset($users[$id]) ? $users[$id] : array();
            $nameHtml = strpos($name, $prefix) === 0 ? '<span class="my-prefix">' . $h($prefix) . '</span><b>' . $h(substr($name, strlen($prefix))) . '</b>' : '<b>' . $h($name) . '</b>';
            $pills = '';
            foreach ($own as $u) {
                $pills .= '<span class="my-user">' . $h($u) . '</span>';
            }
            $out .= '<div class="my-db" data-s="' . $h(strtolower($name . ' ' . implode(' ', $own))) . '">'
                . '<svg class="my-ico-db" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>'
                . '<div class="my-db-main"><div class="my-name">' . $nameHtml . '</div>'
                . '<div class="my-meta"><span class="my-size">' . $h($d['mysqlfriendlysize']) . '</span>'
                . ($own ? '<span class="my-users">' . $pills . '</span>'
                    : '<span class="my-nouser">' . $t('No user can use it yet') . ($musers ? ': <a href="./?module=mysql_users">' . $t('add one in MySQL Users') . '</a>' : '') . '</span>')
                . '</div></div>'
                . '<div class="my-act">'
                . ($pma ? '<a class="btn btn-default btn-sm" href="./?module=phpmyadmin"><svg class="my-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg>phpMyAdmin</a>' : '')
                . '<button class="btn btn-default btn-sm my-del" type="submit" name="inDelete_' . $id . '" value="' . $id . '" title="' . $t('Delete') . '" aria-label="' . str_replace('%s', $h($name), $t('Delete %s')) . '">'
                . '<svg class="my-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></button>'
                . '</div></div>';
        }
        return $out;
    }

    /**
     * The details of the database to delete (<@ DeleteDetails @>): its size and the users who lose access to it.
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML ('' when it is not one of the account's).
     */
    static function getDeleteDetails()
    {
        global $controller;
        $me = ctrl_users::GetUserDetail();
        $id = (int) $controller->GetControllerRequest('URL', 'other');
        if (!ctrl_ownership::Owns('x_mysql_databases', 'my_id_pk', 'my_acc_fk', $id, ' AND my_deleted_ts IS NULL')) {
            return '';
        }
        $cur = self::ListCurrentDatabases($id);
        $users = self::UsersByDatabase($me['userid']);
        $own = isset($users[$id]) ? $users[$id] : array();
        $t = function ($s) { return htmlspecialchars(ui_language::translate($s), ENT_QUOTES, 'UTF-8'); };
        return '<ul class="my-lose"><li>' . str_replace('%s', htmlspecialchars($cur[0]['mysqlfriendlysize'], ENT_QUOTES, 'UTF-8'), $t('All its tables and data are deleted (%s).')) . ' ' . $t('This can not be undone: make a backup first if you may need it.') . '</li>'
            . ($own ? '<li>' . $t('These users lose access to it (the users themselves stay):') . ' <b>' . htmlspecialchars(implode(', ', $own), ENT_QUOTES, 'UTF-8') . '</b></li>' : '') . '</ul>';
    }

    /**
     * The database name typed when creating failed, so it is not lost (<@ PostedName @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The escaped name ('' after a success).
     */
    static function getPostedName()
    {
        global $controller;
        return (self::$blank || self::$badname || self::$alreadyexists) ? htmlspecialchars((string) $controller->GetControllerRequest('FORM', 'inDatabase'), ENT_QUOTES, 'UTF-8') : '';
    }

    /**
     * The question before deleting a database (<@ ConfirmText @>), translated and escaped for an attribute.
     *
     * @return string
     */
    static function getConfirmText()
    {
        return htmlspecialchars(ui_language::translate('Delete this database and everything in it?'), ENT_QUOTES, 'UTF-8');
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
            return ui_sysmessage::shout(sprintf(ui_language::translate("That database name can not be used: letters, digits and dashes only (not at the start or end), and at most %d characters."), (int) self::getMaxCharAllowed()), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("A database with that name already exists."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your databases have been saved successfully!"), "zannounceok");
        }
        return;
    }

    // Webinterface sudo methods.
}
