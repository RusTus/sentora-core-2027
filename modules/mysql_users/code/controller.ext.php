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
 * Updated by RusTus, 2026-11-09
 */
/**
 * MySQL Users module: controller.
 *
 * Creates and deletes MySQL users, grants and revokes their access to the account's
 * databases, resets and (after the panel password) shows their passwords.
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True when the MySQL user name is already taken. */
    static $alreadyexists;
    /** @var bool|null True when the database is already mapped to the user. */
    static $dbalreadyadded;
    /** @var bool|null True when the user name or database was left blank. */
    static $blank;
    /** @var bool|null True when the new password was left blank. */
	static $blankpassword;
    /** @var bool|null True when the user name is not valid. */
    static $badname;
    /** @var bool|null True when the password does not meet the character rules. */
    static $badpass;
    /** @var bool|null True when the password is shorter than password_minlength. */
	static $badpasswordlength;
    /** @var bool|null True when someone tried to create a user named 'root'. */
    static $rootabuse;
    /** @var bool|null True when the access host is not a valid IP address. */
    static $badIP;
    /** @var bool|null True when the last change was saved (shown by getResult()). */
    static $ok;
    /** @var string|null Name of the user just created (shown once with its password). */
    static $newuser = null; // shown once: the panel keeps no copy of the password
    /** @var string|null Generated password of the user just created. */
    static $newpass = null;
    /** @var string|null Show password: the user name whose password is shown. */
    static $shownuser = null; // Show password
    /** @var string|null Show password: the decrypted password ('' when none is stored). */
    static $shownpass = null;
    /** @var array{0: string, 1: string, 2: string}|null Show password: form action, button name and user name for the panel password prompt. */
    static $askpw = null;   // Show password: ask for the panel password first
    /** @var bool|null True when the panel password given for Show password was wrong. */
    static $wrongpw = null;

    // The 'worker' methods.
	// tg - Set user name input max characters minus prefix
    /**
     * Template value <@ MaxCharAllowed @>: how many characters the user name may have.
     *
     * MySQL user names are at most 32 characters and get the prefix "<panel user>_".
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (not used).
     * @return int|false 32 minus the prefix length, or false when the prefix is too long.
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
     * Look for database mappings of a MySQL user whose database was deleted.
     *
     * Does nothing with them yet (the clean-up step is empty).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID (bound to mm_user_fk).
     * @return bool True when the user has mappings, otherwise false.
     */
    static function CleanOrphanDatabases($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_mysql_dbmap WHERE mm_user_fk=:userid";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $uid);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $uid);
            $sql->execute();
            while ($rowmysql = $sql->fetch()) {
                $rowdbSql = "SELECT * FROM x_mysql_databases WHERE my_id_pk=:id AND my_deleted_ts IS NULL";
                $find = $zdbh->prepare($rowdbSql);
                $find->bindParam(':id', $rowmysql['mm_database_fk']);
                $find->execute();
                $rowdb = $find->fetch();

                if (!$rowdb) {

                }
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * List the MySQL users of an account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return array<int, array<string, mixed>>|false One row per user: 'userid', 'username', 'dbpassword' (always ''), 'totaldb', 'accesshtml', 'access'; false when there are none.
     */
    static function ListUsers($uid)
    {
        global $zdbh;
        // Remove deleted databases from MySQL userlist...
        self::CleanOrphanDatabases($uid);
        $sql = "SELECT * FROM x_mysql_users WHERE mu_acc_fk=:userid AND mu_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $uid);
            $res = array();
            $sql->execute();
            while ($rowmysql = $sql->fetch()) {
                //$numrowdb = $zdbh->query("SELECT COUNT(*) FROM x_mysql_dbmap WHERE mm_user_fk=" . $rowmysql['mu_id_pk'] . "")->fetch();
                $numrows = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_dbmap WHERE mm_user_fk=:mysql");
                $numrows->bindParam(':mysql', $rowmysql['mu_id_pk']);
                $numrows->execute();
                $numrowdb = $numrows->fetch();

                if ($rowmysql['mu_access_vc'] == "%") {
                    $access = "ANY";
                } else {
                    $access = $rowmysql['mu_access_vc'];
                }
                array_push($res, array('userid' => $rowmysql['mu_id_pk'],
                    'username' => $rowmysql['mu_name_vc'],
                    'dbpassword' => '', // MySQL keeps its own hash; the panel keeps no copy
                    'totaldb' => $numrowdb[0],
                    'accesshtml' => $access,
                    'access' => $rowmysql['mu_access_vc']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the MySQL databases of an account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return array<int, array<string, mixed>>|false One row per database: 'mysqlid', 'mysqlname'; false when there are none.
     */
    static function ListDatabases($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_mysql_databases WHERE my_acc_fk=:userid AND my_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $uid);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->bindParam(':userid', $uid);
            $sql->execute();
            while ($rowmysql = $sql->fetch()) {
                array_push($res, array('mysqlid' => $rowmysql['my_id_pk'],
                    'mysqlname' => $rowmysql['my_name_vc']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the databases a MySQL user has access to.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|false $uid The MySQL user ID (mu_id_pk).
     * @return array<int, array<string, mixed>>|false One row per mapping: 'mmid', 'mmaccount', 'mmuserid', 'mmdbid', 'mmdbname'; false when there are none.
     */
    static function ListUserDatabases($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_mysql_dbmap WHERE mm_user_fk=:userid";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $uid);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->bindParam(':userid', $uid);
            $sql->execute();
            while ($rowmysql = $sql->fetch()) {
                $numrows = $zdbh->prepare("SELECT * FROM x_mysql_databases WHERE my_id_pk=:database AND my_deleted_ts IS NULL");
                $numrows->bindParam(':database', $rowmysql['mm_database_fk']);
                $numrows->execute();
                $rowdb = $numrows->fetch();
                if ($rowdb) {
                    array_push($res, array('mmid' => $rowmysql['mm_id_pk'],
                        'mmaccount' => $rowmysql['mm_acc_fk'],
                        'mmuserid' => $rowmysql['mm_user_fk'],
                        'mmdbid' => $rowmysql['mm_database_fk'],
                        'mmdbname' => $rowdb['my_name_vc']));
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Get one MySQL user of the logged-in account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $mid The MySQL user ID (mu_id_pk), from the URL or form.
     * @return array<int, array<string, mixed>>|false A single row: 'userid', 'username'; false when not found or not owned.
     */
    static function ListCurrentUser($mid)
    {
        // Only this account's own records (the id comes from ?other= or the form)
        if (!ctrl_ownership::Owns('x_mysql_users', 'mu_id_pk', 'mu_acc_fk', $mid)) {
            return false;
        }
        global $zdbh;
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_id_pk=:mid AND mu_deleted_ts IS NULL");
        $numrows->bindParam(':mid', $mid);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_id_pk=:mid AND mu_deleted_ts IS NULL");
            $res = array();
            $sql->bindParam(':mid', $mid);
            $sql->execute();
            while ($rowmysql = $sql->fetch()) {
                array_push($res, array('userid' => $rowmysql['mu_id_pk'],
                    'username' => $rowmysql['mu_name_vc']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * is the database (by id) one of the account's own, not deleted
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @param int|string $database The database ID (my_id_pk).
     * @return bool True when the account owns the database.
     */
    static function AccountOwnsDatabase($uid, $database)
    {
        global $zdbh;
        if (!preg_match('/^[0-9]{1,10}$/', (string) $database) || !preg_match('/^[0-9]{1,10}$/', (string) $uid)) {
            return false;
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_databases WHERE my_id_pk = :id AND my_acc_fk = :uid AND my_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $database, ':uid' => (int) $uid));
        return (int) $q->fetchColumn() > 0;
    }

    /**
     * A database name for GRANT and REVOKE. MySQL reads _ and % in a database name there as
     * wildcards: GRANT ... ON `my_ql`.* (account "my", database "ql") also covered `mysql`, MariaDB's
     * own database, and with it every MySQL login. Escaped, the name means only itself.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name The database name.
     * @return string For use between backticks.
     */
    static function GrantDb($name)
    {
        global $zdbh;
        return str_replace(array('_', '%'), array('\\_', '\\%'), $zdbh->mysqlRealEscapeString((string) $name));
    }

    /**
     * Create a MySQL user with access to one of the account's databases.
     *
     * Prefixes the name with "<panel user>_", generates a random password, creates the
     * user in MySQL, grants it the database and stores it (password encrypted). Sets
     * self::$newuser, self::$newpass and self::$ok.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (not used).
     * @param int|string $uid The account ID.
     * @param string $username The user name without prefix.
     * @param int|string $database The database ID.
     * @param string $access The host the user may connect from ('%', 'localhost' or an IP).
     * @return bool True when created, false when validation failed or the database is not the account's.
     */
    static function ExecuteCreateUser($uid, $username, $database, $access)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        // Check for spaces and remove if found...
        $username = strtolower(str_replace(' ', '', $username));
        // the part typed must start with a letter or digit, not end with a dash (the whole
        // name is checked again with its prefix below)
        if ($username !== '' && !preg_match('/^[a-z\d]([a-z\d_-]*[a-z\d_])?$/', $username)) {
            self::$badname = true;
            return false;
        }
		// tg - Add prefix to DB user name
		$username = $currentuser['username'] . "_" . $username;
        // If errors are found, then exit before creating user...
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrors($username, $database, $access))) {
            return false;
        }
        // The database must be one of this account's own (the id comes from the form or
        // the API: another account's id gave the new user full rights on its database)
        if (!self::AccountOwnsDatabase($uid, $database)) {
            return false;
        }
        runtime_hook::Execute('OnBeforeCreateDatabaseUser');
        $password = fs_director::GenerateRandomPassword(16, 4);
        // Create user in MySQL
        $sql = $zdbh->prepare("CREATE USER :username@:access;");
        $sql->bindParam(':username', $username);
        $sql->bindParam(':access', $access);
        $sql->execute();
        // Set MySQL password for new user...
		if (version_compare(sys_versions::ShowMySQLVersion(), '5.7.6', '<')) {
			// MySQL 5.7 or OLDER
			$sql = $zdbh->prepare("SET PASSWORD FOR :username@:access=PASSWORD(:password)");
        } else {
			// MySQL 5.7 + ; an account with sites on PHP 7.1 (which can not use MySQL 8.4's default
			// method) gets the older one while MySQL still allows it (PHP Versions > Older logins)
			$sql = $zdbh->prepare("ALTER USER :username@:access IDENTIFIED " . (sys_oldlogins::NewUsersNeedNative($uid) ? 'WITH mysql_native_password ' : '') . "BY :password");
		}
        $sql->bindParam(':username', $username);
        $sql->bindParam(':access', $access);
        $sql->bindParam(':password', $password);
        $sql->execute();
        // Get the database name from the ID...
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_databases WHERE my_id_pk=:database AND my_deleted_ts IS NULL");
        $numrows->bindParam(':database', $database);
        $numrows->execute();
        $rowdb = $numrows->fetch();
        // Remove all priveledges to all databases
        $sql = $zdbh->prepare("GRANT USAGE ON *.* TO :username@:access");
        $sql->bindParam(':username', $username);
        $sql->bindParam(':access', $access);
        $sql->execute();
        // Grant privileges for new user to the assigned database...
        $usernameClean = $zdbh->mysqlRealEscapeString($username);
        $accessClean = $zdbh->mysqlRealEscapeString($access);
        $my_name_vc = self::GrantDb($rowdb['my_name_vc']);
        $sql = $zdbh->prepare("GRANT ALL PRIVILEGES ON `$my_name_vc`.* TO `$usernameClean`@`$accessClean`");
        //$sql->bindParam(':username', $username, PDO::PARAM_STR);
        //$sql->bindParam(':access', $access, PDO::PARAM_STR);
        //$sql->bindParam(':name', $rowdb['my_name_vc'], PDO::PARAM_STR);
        $sql->execute();
        $sql = $zdbh->prepare("FLUSH PRIVILEGES");
        $sql->execute();
        // Add user to Sentora database...
        $sql = $zdbh->prepare("INSERT INTO x_mysql_users (
								mu_acc_fk,
								mu_name_vc,
								mu_database_fk,
								mu_pass_vc,
								mu_access_vc,
								mu_created_ts) VALUES (
								:userid,
								:username,
								:database,
								:password,
								:access,
								:time)");
        $sql->bindParam(':userid', $uid);
        $sql->bindParam(':username', $username);
        $sql->bindParam(':database', $database);
        // The panel keeps only an encrypted copy (ctrl_secret), shown to the owner on
        // request; MySQL stores its own hash
        $enc = ctrl_secret::Encrypt($password);
        $sql->bindParam(':password', $enc);
        self::EnsurePassColumn();
        self::$newuser = $username;
        self::$newpass = $password;
        $sql->bindParam(':access', $access);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        // Get the new users id...
        //$rowuser = $zdbh->query("SELECT * FROM x_mysql_users WHERE mu_name_vc='" . $username . "' AND mu_acc_fk=" . $uid . " AND mu_deleted_ts IS NULL")->fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_name_vc=:username AND mu_acc_fk=:userid AND mu_deleted_ts IS NULL");
        $numrows->bindParam(':username', $username);
        $numrows->bindParam(':userid', $uid);
        $numrows->execute();
        $rowuser = $numrows->fetch();
        // Add database to Sentora user account...
        self::ExecuteAddDB($uid, $rowuser['mu_id_pk'], $database);
        runtime_hook::Execute('OnAfterCreateDatabaseUser');
        self::$ok = true;
        return true;
    }

    /**
     * Validate a new MySQL user.
     *
     * Sets self::$blank, self::$rootabuse, self::$alreadyexists, self::$badname or self::$badIP on failure.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $username The full (prefixed) user name.
     * @param int|string $database The database ID.
     * @param string $access The access host.
     * @return bool True when the user can be created.
     */
    static function CheckCreateForErrors($username, $database, $access)
    {
        global $zdbh;
        // Check to make sure the user name is not blank before we go any further...
        if ($username == '') {
            self::$blank = true;
            return false;
        }
        // Check to make sure the user name is not blank before we go any further...
        if ($username == 'root') {
            self::$rootabuse = true;
            return false;
        }
        // Check to make sure the user name is not blank before we go any further...
        if ($database == '') {
            self::$blank = true;
            return false;
        }
        // Check to make sure the user name is not a duplicate...
        $sql = "SELECT COUNT(*) FROM x_mysql_users WHERE mu_name_vc=:username AND mu_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':username', $username);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                self::$alreadyexists = true;
                return false;
            }
        }
        // Check to make sure the user name is not a duplicate (checks actual mysql table)...
        $sql = "SELECT EXISTS(SELECT 1 FROM mysql.user WHERE user = :username)";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':username', $username);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                self::$alreadyexists = true;
                return false;
            }
        }
        // Check for invalid username
        if (!self::IsValidUserName($username)) {
            self::$badname = true;
            return false;
        }
        // Check for invalid IP address
        if ($access != "%" && strtolower($access) != "localhost") {
            if (!sys_monitoring::IsAnyValidIP($access)) {
                self::$badIP = true;
                return false;
            }
        }
        return true;
    }

    /**
     * Check that a database is not already mapped to a MySQL user.
     *
     * Sets self::$dbalreadyadded on failure.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $userid The MySQL user ID.
     * @param int|string $database The database ID.
     * @return bool True when it can be added.
     */
    static function CheckAddForErrors($userid, $database)
    {
        global $zdbh;
        // Check to make sure the database isnt already added...
        //$result = $zdbh->query("SELECT * FROM x_mysql_dbmap WHERE mm_database_fk=" . $database . " AND mm_user_fk=" . $userid . "")->fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_dbmap WHERE mm_database_fk=:database AND mm_user_fk=:userid");
        $numrows->bindParam(':database', $database);
        $numrows->bindParam(':userid', $userid);
        $numrows->execute();
        $result = $numrows->fetch();
        if ($result) {
            self::$dbalreadyadded = true;
            return false;
        }
        return true;
    }

    /**
     * Delete a MySQL user and its database mappings.
     *
     * Drops the user from MySQL when it exists. Sets self::$ok.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $mu_id_pk The MySQL user ID.
     * @return true Always true.
     */
    static function ExecuteDeleteUser($mu_id_pk)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeDeleteDatabaseUser');
        //$rowuser = $zdbh->query("SELECT * FROM x_mysql_users WHERE mu_id_pk=" . $mu_id_pk . " AND mu_deleted_ts IS NULL")->fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_id_pk=:mu_id_pk AND mu_deleted_ts IS NULL");
        $numrows->bindParam(':mu_id_pk', $mu_id_pk);
        $numrows->execute();
        $rowuser = $numrows->fetch();

        $sql = "SELECT EXISTS(SELECT 1 FROM mysql.user WHERE user = :name)";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':name', $rowuser['mu_name_vc']);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                //drop user
                $sql = $zdbh->prepare("DROP USER :name@:access;");
                $sql->bindParam(':name', $rowuser['mu_name_vc']);
                $sql->bindParam(':access', $rowuser['mu_access_vc']);
                $sql->execute();
                //flush privileges
                $sql = $zdbh->prepare("FLUSH PRIVILEGES");
                $sql->execute();
            }
        }
        $sql = $zdbh->prepare("
			UPDATE x_mysql_users
			SET mu_deleted_ts = :time
			WHERE mu_id_pk = :mu_id_pk");
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':mu_id_pk', $mu_id_pk);
        $sql->execute();
        $sql = $zdbh->prepare("
			DELETE FROM x_mysql_dbmap
			WHERE mm_user_fk = :mu_id_pk");
        $sql->bindParam(':mu_id_pk', $mu_id_pk);
        $sql->execute();
        runtime_hook::Execute('OnAfterDeleteDatabaseUser');
        self::$ok = true;
        return true;
    }

    /**
     * Give a MySQL user access to a database.
     *
     * Both must belong to the account. Grants all privileges and adds the mapping.
     * Sets self::$ok.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|null $uid The account ID ('' or null: the logged-in user).
     * @param int|string $myuserid The MySQL user ID.
     * @param int|string $dbid The database ID.
     * @return bool True when added; false when already mapped or not the account's.
     */
    static function ExecuteAddDB($uid, $myuserid, $dbid)
    {
        global $zdbh;
        if (fs_director::CheckForEmptyValue(self::CheckAddForErrors($myuserid, $dbid))) {
            return false;
        }
        if (!isset($uid) || $uid == NULL || $uid == '') {
            $currentuser = ctrl_users::GetUserDetail();
            $uid = $currentuser['userid'];
        }
        // Both the user and the database must belong to the account
        if (!self::AccountOwnsDatabase($uid, $dbid)) {
            return false;
        }
        $own = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_users WHERE mu_id_pk = :id AND mu_acc_fk = :uid AND mu_deleted_ts IS NULL");
        $own->execute(array(':id' => (int) $myuserid, ':uid' => (int) $uid));
        if ((int) $own->fetchColumn() === 0) {
            return false;
        }
        runtime_hook::Execute('OnBeforeAddDatabaseAccess');
        //$rowdb = $zdbh->query("SELECT * FROM x_mysql_databases WHERE my_id_pk=" . $dbid . " AND my_deleted_ts IS NULL")->fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_databases WHERE my_id_pk=:dbid AND my_deleted_ts IS NULL");
        $numrows->bindParam(':dbid', $dbid);
        $numrows->execute();
        $rowdb = $numrows->fetch();

        //$rowuser = $zdbh->query("SELECT * FROM x_mysql_users WHERE mu_id_pk=" . $myuserid . " AND mu_deleted_ts IS NULL")->fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_id_pk=:myuserid AND mu_deleted_ts IS NULL");
        $numrows->bindParam(':myuserid', $myuserid);
        $numrows->execute();
        $rowuser = $numrows->fetch();

        $my_name_vc = self::GrantDb($rowdb['my_name_vc']);
        $mu_name_vc = $zdbh->mysqlRealEscapeString($rowuser['mu_name_vc']);
        $mu_access_vc = $zdbh->mysqlRealEscapeString($rowuser['mu_access_vc']);
        $sql = $zdbh->prepare("GRANT ALL PRIVILEGES ON `$my_name_vc`.* TO `$mu_name_vc`@`$mu_access_vc`");
        // The names are escaped into the statement above, binding unused parameters throws an error
        $sql->execute();
        $sql = $zdbh->prepare("FLUSH PRIVILEGES");
        $sql->execute();
        $sql2 = $zdbh->prepare("
			INSERT INTO x_mysql_dbmap (
							mm_acc_fk,
							mm_user_fk,
							mm_database_fk) VALUES (
							:uid,
							:myuserid,
							:dbid
                                                        )");
        $sql2->bindParam(':uid', $uid);
        $sql2->bindParam(':myuserid', $myuserid);
        $sql2->bindParam(':dbid', $dbid);
        $sql2->execute();
        runtime_hook::Execute('OnAfterAddDatabaseAccess');
        self::$ok = true;
        return true;
    }

    /**
     * Remove a MySQL user's access to a database.
     *
     * Revokes the privileges and deletes the mapping. Sets self::$ok.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $myuserid The MySQL user ID.
     * @param int|string $mapid The mapping ID (mm_id_pk).
     * @return true Always true.
     */
    static function ExecuteRemoveDB($myuserid, $mapid)
    { // <-- mmid = dbmaps
        global $zdbh;
        runtime_hook::Execute('OnBeforeRemoveDatabaseAccess');

        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_dbmap WHERE mm_id_pk=:mapid");
        $numrows->bindParam(':mapid', $mapid);
        $numrows->execute();
        $rowdbmap = $numrows->fetch();

        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_databases WHERE my_id_pk=:mm_database_fk AND my_deleted_ts IS NULL");
        $numrows->bindParam(':mm_database_fk', $rowdbmap['mm_database_fk']);
        $numrows->execute();
        $rowdb = $numrows->fetch();

        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_id_pk=:myuserid AND mu_deleted_ts IS NULL");
        $numrows->bindParam(':myuserid', $myuserid);
        $numrows->execute();
        $rowuser = $numrows->fetch();

        // The grant as made now (GrantDb: _ escaped), and one made before that (unescaped); each
        // may not exist, which MySQL reports as an error
        $who = "`" . $zdbh->mysqlRealEscapeString((string) $rowuser['mu_name_vc']) . "`@`" . $zdbh->mysqlRealEscapeString((string) $rowuser['mu_access_vc']) . "`";
        foreach (array_unique(array(self::GrantDb((string) $rowdb['my_name_vc']), $zdbh->mysqlRealEscapeString((string) $rowdb['my_name_vc']))) as $grantDb) {
            try {
                $zdbh->prepare("REVOKE ALL PRIVILEGES ON `" . $grantDb . "`.* FROM " . $who)->execute();
            } catch (PDOException $e) {
            }
        }

        $sql = $zdbh->prepare("FLUSH PRIVILEGES");
        $sql->execute();

        $sql = $zdbh->prepare("DELETE FROM x_mysql_dbmap WHERE mm_id_pk=:mapid AND mm_user_fk=:myuserid");
        $sql->bindParam(':mapid', $mapid);
        $sql->bindParam(':myuserid', $myuserid);
        $sql->execute();

        runtime_hook::Execute('OnAfterRemoveDatabaseAccess');
        self::$ok = true;
        return true;
    }

    /**
     * Set a new password for a MySQL user.
     *
     * Changes it in MySQL and stores the encrypted copy. Sets self::$ok.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $myuserid The MySQL user ID.
     * @param string $password The new password.
     * @return bool True when done, false when the password is not valid.
     */
    static function ExecuteResetPassword($myuserid, $password)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeResetDatabasePassword');
        //$rowuser = $zdbh->query("SELECT * FROM x_mysql_users WHERE mu_id_pk=" . $myuserid . " AND mu_deleted_ts IS NULL")->fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_id_pk=:myuserid AND mu_deleted_ts IS NULL");
        $numrows->bindParam(':myuserid', $myuserid);
        $numrows->execute();
        $rowuser = $numrows->fetch();

        // If errors are found, then exit before resetting password...
        if (fs_director::CheckForEmptyValue(self::CheckPasswordForErrors($password))) {
            return false;
        }
        $sql = "SELECT EXISTS(SELECT 1 FROM mysql.user WHERE user = :mu_name_vc)";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':mu_name_vc', $rowuser['mu_name_vc']);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                // Set MySQL password for new user...
				if (version_compare(sys_versions::ShowMySQLVersion(), '5.7.6', '<')) {
					// MySQL 5.7 or OLDER
					$sql = $zdbh->prepare("SET PASSWORD FOR :mu_name_vc@:mu_access_vc=PASSWORD(:password)");
				} else {
					// MySQL 5.7 + ; the user keeps its login method (a site on PHP 7.1 uses the older one)
					$sql = $zdbh->prepare("ALTER USER :mu_name_vc@:mu_access_vc IDENTIFIED "
						. (sys_oldlogins::UsesNative((string) $rowuser['mu_name_vc'], (string) $rowuser['mu_access_vc']) ? 'WITH mysql_native_password ' : '') . "BY :password");
				}
                $sql->bindParam(':mu_name_vc', $rowuser['mu_name_vc']);
                $sql->bindParam(':mu_access_vc', $rowuser['mu_access_vc']);
                $sql->bindParam(':password', $password);
                $sql->execute();
                $sql = $zdbh->prepare("FLUSH PRIVILEGES");
                $sql->execute();
                // Only an encrypted copy is kept, for the owner to see again
                $enc = ctrl_secret::Encrypt($password);
                self::EnsurePassColumn();
                $sql = $zdbh->prepare("UPDATE x_mysql_users SET mu_pass_vc=:enc WHERE mu_id_pk=:myuserid");
                $sql->bindParam(':enc', $enc);
                $sql->bindParam(':myuserid', $myuserid);
                $sql->execute();
            }
        }
        runtime_hook::Execute('OnAfterResetDatabasePassword');
        self::$ok = true;
        return true;
    }

    /**
     * room for the encrypted password (older databases have 40 characters)
     *
     * Widens x_mysql_users.mu_pass_vc to VARCHAR(255) if needed, once per request.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function EnsurePassColumn()
    {
        global $zdbh;
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $w = $zdbh->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_mysql_users' AND COLUMN_NAME = 'mu_pass_vc'")->fetchColumn();
        if ((int) $w < 255) {
            $zdbh->exec("ALTER TABLE x_mysql_users MODIFY mu_pass_vc VARCHAR(255) DEFAULT NULL");
        }
    }

    /**
     * Validate a new password.
     *
     * Sets self::$blankpassword, self::$badpasswordlength or self::$badpass on failure.
     *
     * @param string $password The password.
     * @return bool True when the password can be used.
     */
    static function CheckPasswordForErrors($password)
    {
		// Check to make sure the password is not blank before we go any further...
        if ($password == '') {
            self::$blankpassword = TRUE;
            return false;
        }
		// Check for password length...
		if (strlen($password) < (int) self::getMinPassLength()) { // the same minimum the form asks for
			self::$badpasswordlength = true;
			return false;
		}
        if (!self::IsValidPassword($password)) {
            self::$badpass = true;
            return false;
        }
        return true;
    }

    /**
     * Check a MySQL user name: letters, digits, '-' and '_', at most 32 characters, not ending in '-'.
     *
     * @param string $username The full (prefixed) user name.
     * @return bool True when valid.
     */
    static function IsValidUserName($username)
    {
		// tg - Added regx [a-z\d_] to allow underscore
        // letters, digits, '_' and '-' after the first character (this only let a dash be the second one)
        if (!preg_match('/^[a-z\d][a-z\d_-]{0,62}$/i', $username) || preg_match('/-$/', $username)) {
            return false;
        } else {
			// tg - Updated user name max size check
            if (strlen($username) < 33) {
                // Enforce the MySQL username limit! (http://dev.mysql.com/doc/refman/4.1/en/user-names.html)
                return true;
            }
            return false;
        }
    }

	/**
	 * Check the password rules: 8+ characters with a digit, a lower and an upper case letter (or ends in '-').
	 *
	 * @param string $password The password.
	 * @return bool True when acceptable.
	 */
	static function IsValidPassword($password)
    {
        return preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}/', $password) || preg_match('/-$/', $password) == 1;
		//return preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z])/', $password) || preg_match('/-$/', $password) == 1;
    }
	

    // End 'worker' methods.

    /**
     * Handle the create user form (start of the web interface methods).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when the user was created.
     */
    static function doCreateUser()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        $choice = isset($formvars['inAccess']) ? (string) $formvars['inAccess'] : '1';
        if ($choice === '3') {
            $access = 'localhost'; // this server only
        } elseif ($choice === '2') {
            $access = isset($formvars['inAccessIP']) ? trim((string) $formvars['inAccessIP']) : '';
        } else {
            $access = '%';
        }
        if (self::ExecuteCreateUser($currentuser['userid'], isset($formvars['inUserName']) ? $formvars['inUserName'] : '', isset($formvars['inDatabase']) ? $formvars['inDatabase'] : '', $access))
            return true;
        return false;
    }

    /**
     * Handle the user list buttons: Edit or Delete (redirect and exit), or Show password.
     *
     * Show password asks for the panel password first (self::$askpw / self::$wrongpw) and
     * then sets self::$shownuser and self::$shownpass.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doEditUser()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListUsers($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['userid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['userid'] . "");
                exit;
            }
            if (isset($formvars['inEdit_' . $row['userid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $row['userid'] . "");
                exit;
            }
            // Show password: one of this account's own users (the loop only has those)
            if (isset($formvars['inShow_' . $row['userid'] . ''])) {
                global $zdbh;
                $q = $zdbh->prepare("SELECT mu_name_vc, mu_pass_vc FROM x_mysql_users WHERE mu_id_pk = :id AND mu_acc_fk = :uid AND mu_deleted_ts IS NULL");
                $q->execute(array(':id' => (int) $row['userid'], ':uid' => (int) $currentuser['userid']));
                $u = $q->fetch();
                if ($u) {
                    $gate = ctrl_secret::ConfirmGate();
                    if ($gate !== true) {
                        if ($gate === null) {
                            self::$askpw = array('./?module=mysql_users&action=EditUser', 'inShow_' . (int) $row['userid'], $u['mu_name_vc']);
                        } else {
                            self::$wrongpw = true;
                        }
                        return;
                    }
                    $plain = ctrl_secret::Decrypt($u['mu_pass_vc']);
                    self::$shownuser = $u['mu_name_vc'];
                    self::$shownpass = ($plain === false) ? '' : $plain;
                    if ($plain !== false) {
                        ctrl_secret::LogReveal('MySQL user ' . $u['mu_name_vc']);
                    }
                }
                return;
            }
        }
        return;
    }

    /**
     * Handle the add database form.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when the access was added.
     */
    static function doAddDB()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inUser'], $formvars['inDatabase'])
            || !ctrl_ownership::Owns('x_mysql_users', 'mu_id_pk', 'mu_acc_fk', $formvars['inUser'], ' AND mu_deleted_ts IS NULL')
            || !ctrl_ownership::Owns('x_mysql_databases', 'my_id_pk', 'my_acc_fk', $formvars['inDatabase'], ' AND my_deleted_ts IS NULL')) {
            return false;
        }
        if (self::ExecuteAddDB($currentuser['userid'], $formvars['inUser'], $formvars['inDatabase']))
            return true;
        return false;
    }

    /**
     * Handle a Remove button in the user's database list.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when the access was removed.
     */
    static function doRemoveDB()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inUser']) || !ctrl_ownership::Owns('x_mysql_users', 'mu_id_pk', 'mu_acc_fk', $formvars['inUser'], ' AND mu_deleted_ts IS NULL')) {
            return false;
        }
        foreach (self::ListUserDatabases($formvars['inUser']) as $row) {
            if (isset($formvars['inRemove_' . $row['mmid'] . ''])) {
                if (self::ExecuteRemoveDB($formvars['inUser'], $formvars['inRemove_' . $row['mmid'] . ''])) {
                    return true;
                } else {
                    return false;
                }
            }
        }
        return false;
    }

    /**
     * Handle the delete confirmation form.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when the user was deleted.
     */
    static function doConfirmDeleteUser()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inDelete']) || !ctrl_ownership::Owns('x_mysql_users', 'mu_id_pk', 'mu_acc_fk', $formvars['inDelete'], ' AND mu_deleted_ts IS NULL')) {
            return false;
        }
        if (self::ExecuteDeleteUser($formvars['inDelete']))
            return true;
        return false;
    }

    /**
     * Handle the reset password form.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when the password was changed.
     */
    static function doResetPW()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inUser']) || !ctrl_ownership::Owns('x_mysql_users', 'mu_id_pk', 'mu_acc_fk', $formvars['inUser'], ' AND mu_deleted_ts IS NULL')) {
            return false;
        }
        if (self::ExecuteResetPassword($formvars['inUser'], $formvars['inResetPW']))
            return true;
        return false;
    }

    /**
     * Template loop <% loop UserList %>: the logged-in user's MySQL users.
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return array<int, array<string, mixed>>|false See ListUsers().
     */
    static function getUserList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListUsers($currentuser['userid']);
    }

    /**
     * Template loop <% loop DatabaseList %>: the logged-in user's databases.
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return array<int, array<string, mixed>>|false See ListDatabases().
     */
    static function getDatabaseList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListDatabases($currentuser['userid']);
    }

    /**
     * Template loop <% loop UserDatabaseList %>: databases of the MySQL user in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return array<int, array<string, mixed>>|false See ListUserDatabases().
     */
    static function getUserDatabaseList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListUserDatabases($controller->GetControllerRequest('URL', 'other'));
    }

    /**
     * Template flag <% if isDeleteUser %>: show the delete view?
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|null $uid Optional. The account ID. Default null (the logged-in user).
     * @return bool|null True when ?show=Delete and the user in ?other= is the account's; null when it is not.
     */
    static function getisDeleteUser($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete MySQL Account.
        // This shall avoid exposing mysql username based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

    	$sql = "SELECT * FROM x_mysql_users WHERE mu_acc_fk=:userid AND mu_id_pk=:editedUsrID AND mu_deleted_ts IS NULL";
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
     * Template flag <% if isEditUser %>: show the edit view?
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|null $uid Optional. The account ID. Default null (the logged-in user).
     * @return bool|null True when ?show=Edit and the user in ?other= is the account's; null when it is not.
     */
    static function getisEditUser($uid = null)
    {
		
        global $controller;
        global $zdbh;

        $urlvars     = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Edit MySQL Account.
        // This shall avoid exposing mysql username based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

    	$sql = "SELECT * FROM x_mysql_users WHERE mu_acc_fk=:userid AND mu_id_pk=:editedUsrID AND mu_deleted_ts IS NULL";
    	$numrows = $zdbh->prepare($sql);
    	$numrows->bindParam(':userid', $currentuser['userid']);
		$numrows->bindParam(':editedUsrID', $urlvars['other']);
    	$numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }
		
        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Edit");
    }

    /**
     * Template flag <% if isCreateUser %>: show the list and create form?
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when there is no ?show=.
     */
    static function getisCreateUser()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (!isset($urlvars['show']))
            return true;
        return false;
    }

    /**
     * Template value <@ CurrentUserName @>: the panel user name (the MySQL user name prefix).
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return string
     */
    static function getCurrentUserName()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return $currentuser['username'];
    }

    /**
     * Template value <@ EditCurrentUserName @>: name of the MySQL user in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The name ('' when there is no ?other=; null when it is not owned).
     */
    static function getEditCurrentUserName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentUser($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['username'];
        } else {
            return "";
        }
    }

    /**
     * Template value <@ EditCurrentUserID @>: ID of the MySQL user in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The ID ('' when there is no ?other=; null when it is not owned).
     */
    static function getEditCurrentUserID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentUser($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['userid'];
        } else {
            return "";
        }
    }

    /**
     * Escape a value for HTML.
     *
     * @param mixed $s The value (cast to string).
     * @return string The escaped text.
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translate and escape a text.
     *
     * @param string $s The English text.
     * @return string The escaped translation.
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * A MySQL user or database name with the account's prefix muted.
     *
     * @param string $name The full name.
     * @return string The HTML.
     */
    static function NameHtml($name)
    {
        $prefix = ctrl_users::GetUserDetail()['username'] . '_';
        return strpos($name, $prefix) === 0 ? '<span class="mu-prefix">' . self::h($prefix) . '</span><b>' . self::h(substr($name, strlen($prefix))) . '</b>' : '<b>' . self::h($name) . '</b>';
    }

    /**
     * Where a user may connect from, in words.
     *
     * @param string $access The host: '%', 'localhost' or an IP address.
     * @return array{0: string, 1: string} The words and a class for the pill.
     */
    static function AccessLabel($access)
    {
        if ($access === '%') {
            return array(ui_language::translate('Any address'), 'any');
        }
        if (strtolower($access) === 'localhost') {
            return array(ui_language::translate('This server only'), 'local');
        }
        return array(str_replace('%s', $access, ui_language::translate('Only from %s')), 'ip');
    }

    /**
     * The figures at the top (<@ Summary @>): users, databases, and users with no database yet.
     *
     * @return string The HTML.
     */
    static function getSummary()
    {
        $me = ctrl_users::GetUserDetail();
        $users = self::ListUsers($me['userid']);
        $users = $users ? $users : array();
        $dbs = self::ListDatabases($me['userid']);
        $none = count(array_filter($users, function ($u) { return (int) $u['totaldb'] === 0; }));
        $tiles = array(
            array('MySQL users', count($users), '', '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6"/>'),
            array('Databases', $dbs ? count($dbs) : 0, '', '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>'),
            array('Users with no database', $none, $none ? 'warn' : '', '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>'),
        );
        $out = '';
        foreach ($tiles as $x) {
            $out .= '<div class="mu-tile ' . $x[2] . '"><svg class="mu-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $x[3] . '</svg>'
                . '<div><b>' . (int) $x[1] . '</b><span>' . self::t($x[0]) . '</span></div></div>';
        }
        return $out;
    }

    /**
     * One card per MySQL user (<@ UserCards @>): name, where it may connect from, its databases, and Show password,
     * Databases and password, and Delete (buttons of the EditUser form).
     *
     * @return string The HTML ('' when there are none).
     */
    static function getUserCards()
    {
        $me = ctrl_users::GetUserDetail();
        $users = self::ListUsers($me['userid']);
        if (!$users) {
            return '';
        }
        $out = '';
        foreach ($users as $u) {
            $id = (int) $u['userid'];
            $acc = self::AccessLabel((string) $u['access']);
            $dbs = self::ListUserDatabases($id);
            $pills = '';
            foreach ($dbs ? $dbs : array() as $d) {
                $pills .= '<span class="mu-db">' . self::h($d['mmdbname']) . '</span>';
            }
            $out .= '<div class="mu-user" data-s="' . self::h(strtolower($u['username'] . ' ' . implode(' ', array_column($dbs ? $dbs : array(), 'mmdbname')))) . '">'
                . '<span class="mu-avatar" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span>'
                . '<div class="mu-main"><div class="mu-name">' . self::NameHtml($u['username']) . '<span class="mu-acc ' . $acc[1] . '">' . self::h($acc[0]) . '</span>'
                . (sys_oldlogins::UsesNative((string) $u['username'], (string) $u['access']) ? '<span class="mu-acc mu-old" title="' . self::t('It logs in with MySQL\'s older, weaker method, kept for sites on PHP 7.1. Once its sites run on PHP 8, the administrator moves it to the modern one (PHP Versions > Older logins).') . '">' . self::t('Older login (PHP 7.1)') . '</span>' : '') . '</div>'
                . '<div class="mu-dbs">' . ($pills !== '' ? $pills : '<span class="mu-nodb">' . self::t('No database yet: open Databases and password to add one') . '</span>') . '</div></div>'
                . '<div class="mu-act">'
                . '<button class="btn btn-default btn-sm" type="submit" name="inShow_' . $id . '" value="' . $id . '"><svg class="mu-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>' . self::t('Show password') . '</button>'
                . '<button class="btn btn-primary btn-sm" type="submit" name="inEdit_' . $id . '" value="' . $id . '"><svg class="mu-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>' . self::t('Databases and password') . '</button>'
                . '<button class="btn btn-default btn-sm mu-del" type="submit" name="inDelete_' . $id . '" value="' . $id . '" title="' . self::t('Delete') . '" aria-label="' . str_replace('%s', self::h($u['username']), self::t('Delete %s')) . '"><svg class="mu-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></button>'
                . '</div></div>';
        }
        return $out;
    }

    /**
     * The card for a password: the user just made (shown once here) or the one asked for with Show password,
     * or the panel password prompt before showing one (<@ SecretCard @>).
     *
     * @return string The HTML ('' when there is nothing to show).
     */
    static function getSecretCard()
    {
        $copy = '<button type="button" class="btn btn-default btn-sm mu-copy" data-done="' . self::t('Copied') . '">' . self::t('Copy') . '</button>';
        if (self::$askpw !== null) {
            return '<form class="mu-card mu-secret ask" method="post" action="' . self::h(self::$askpw[0]) . '">'
                . '<h4><svg class="mu-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>'
                . str_replace('%s', '<code>' . self::h(self::$askpw[2]) . '</code>', self::t('Show the password of %s')) . '</h4>'
                . '<p class="mu-sub">' . self::t('Enter your own panel password first. Each password shown is written to the panel\'s log.') . '</p>'
                . '<div class="mu-inline"><input class="form-control" type="password" name="inConfirmPw" autocomplete="current-password" required autofocus aria-label="' . self::t('Your panel password') . '" placeholder="' . self::t('Your panel password') . '">'
                . '<input type="hidden" name="' . self::h(self::$askpw[1]) . '" value="1">' . runtime_csfr::Token()
                . '<button class="btn btn-primary" type="submit">' . self::t('Show password') . '</button><a class="btn btn-default" href="./?module=mysql_users">' . self::t('Cancel') . '</a></div></form>';
        }
        if (self::$shownuser !== null && self::$shownpass !== '') {
            return '<div class="mu-card mu-secret"><h4>' . str_replace('%s', '<code>' . self::h(self::$shownuser) . '</code>', self::t('Password of %s')) . '</h4>'
                . '<div class="mu-kv"><code class="mu-pw">' . self::h(self::$shownpass) . '</code>' . $copy . '</div></div>';
        }
        if (!fs_director::CheckForEmptyValue(self::$newpass)) {
            return '<div class="mu-card mu-secret new"><h4><svg class="mu-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>' . self::t('MySQL user created') . '</h4>'
                . '<div class="mu-kv"><span>' . self::t('User name') . '</span><code>' . self::h(self::$newuser) . '</code>' . $copy . '</div>'
                . '<div class="mu-kv"><span>' . self::t('Password') . '</span><code class="mu-pw">' . self::h(self::$newpass) . '</code>' . $copy . '</div>'
                . '<div class="mu-kv"><span>' . self::t('Server') . '</span><code>localhost</code>' . $copy . '</div>'
                . '<p class="mu-sub">' . self::t('Put these in your application\'s settings (for example wp-config.php). You can see the password again later with Show password.') . '</p></div>';
        }
        return '';
    }

    /**
     * The databases the edited user has, each with Remove (<@ UserDatabaseRows @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getUserDatabaseRows()
    {
        global $controller;
        $list = self::ListUserDatabases($controller->GetControllerRequest('URL', 'other'));
        if (!$list) {
            return '<p class="mu-empty">' . self::t('This user has no database yet: add one below.') . '</p>';
        }
        $out = '';
        foreach ($list as $d) {
            $out .= '<div class="mu-dbrow"><svg class="mu-ico mu-dbico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>'
                . '<span class="mu-dbname">' . self::NameHtml($d['mmdbname']) . '</span><span class="mu-all">' . self::t('all privileges') . '</span>'
                . '<button class="btn btn-default btn-sm mu-del" type="submit" name="inRemove_' . (int) $d['mmid'] . '" value="' . (int) $d['mmid'] . '">' . self::t('Remove access') . '</button></div>';
        }
        return $out;
    }

    /**
     * The options of the databases the edited user does not have yet (<@ AddDatabaseOptions @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The <option> HTML ('' when it has them all).
     */
    static function getAddDatabaseOptions()
    {
        global $controller;
        $me = ctrl_users::GetUserDetail();
        $has = array();
        foreach (self::ListUserDatabases($controller->GetControllerRequest('URL', 'other')) ?: array() as $d) {
            $has[(int) $d['mmdbid']] = true;
        }
        $out = '';
        foreach (self::ListDatabases($me['userid']) ?: array() as $d) {
            if (!isset($has[(int) $d['mysqlid']])) {
                $out .= '<option value="' . (int) $d['mysqlid'] . '">' . self::h($d['mysqlname']) . '</option>';
            }
        }
        return $out;
    }

    /**
     * Template flag <% if CanAddDatabase %>: the edited user does not have every database yet.
     *
     * @return bool
     */
    static function getCanAddDatabase()
    {
        return self::getAddDatabaseOptions() !== '';
    }

    /**
     * Where the edited user may connect from, as a pill (<@ EditAccess @>).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getEditAccess()
    {
        global $zdbh, $controller;
        $q = $zdbh->prepare("SELECT mu_access_vc FROM x_mysql_users WHERE mu_id_pk = :id AND mu_acc_fk = :a AND mu_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $controller->GetControllerRequest('URL', 'other'), ':a' => (int) ctrl_users::GetUserDetail()['userid']));
        $a = $q->fetchColumn();
        if ($a === false) {
            return '';
        }
        $l = self::AccessLabel((string) $a);
        return '<span class="mu-acc ' . $l[1] . '">' . self::h($l[0]) . '</span>';
    }

    /**
     * The edited user's name, prefix muted (<@ EditNameHtml @>).
     *
     * @return string The HTML.
     */
    static function getEditNameHtml()
    {
        $n = self::getEditCurrentUserName();
        return $n ? self::NameHtml($n) : '';
    }

    /**
     * What the user to delete loses (<@ DeleteDetails @>): the databases it can use.
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getDeleteDetails()
    {
        global $controller;
        $list = self::ListUserDatabases($controller->GetControllerRequest('URL', 'other'));
        $names = array_column($list ? $list : array(), 'mmdbname');
        return '<ul class="mu-lose"><li>' . self::t('The user is removed from MySQL: applications that log in with it stop working.') . '</li>'
            . ($names ? '<li>' . str_replace('%s', '<b>' . self::h(implode(', ', $names)) . '</b>', self::t('It loses access to: %s (the databases and their data stay).')) . '</li>' : '') . '</ul>';
    }

    /**
     * The user name typed when creating failed, so it is not lost (<@ PostedUserName @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The escaped name.
     */
    static function getPostedUserName()
    {
        global $controller;
        return (self::$blank || self::$badname || self::$alreadyexists || self::$badIP || self::$rootabuse) ? self::h((string) $controller->GetControllerRequest('FORM', 'inUserName')) : '';
    }

    /**
     * Template value <@ MinPassLength @>: the minimum password length shown in the forms.
     *
     * @return int|string The 'password_minlength' option (a string) when above 9, otherwise 9.
     */
    static function getMinPassLength()
    {
        $minpasswordlength = ctrl_options::GetSystemOption('password_minlength');
        $trylength = 9;
        if ($trylength < $minpasswordlength) {
            $uselength = $minpasswordlength;
        } else {
            $uselength = $trylength;
        }
        return $uselength;
    }

    /**
     * Template value <@ Result @>: the outcome of the last action.
     *
     * @return string|null The message HTML (error, password prompt, shown or new password, success), or null.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return ui_sysmessage::shout(ui_language::translate("You need to specify a user name and select a database to create your MySQL user."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$rootabuse)) {
            return ui_sysmessage::shout(ui_language::translate("You cannot create a user named 'root'! This attempt has been logged and the system administrator notified!."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("A MySQL user with that name already exists."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return ui_sysmessage::shout(ui_language::translate("That user name can not be used: letters, digits, dashes and underscores, not starting with a dash or underscore or ending with a dash."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badpass)) {
            return ui_sysmessage::shout(ui_language::translate("The password needs a lower case letter, a capital and a digit."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$badpasswordlength)) {
            return ui_sysmessage::shout(sprintf(ui_language::translate("The password is too short. Characters needed: %d"), (int) self::getMinPassLength()), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$blankpassword)) {
            return ui_sysmessage::shout(ui_language::translate("You entered blank a password. Please retry and enter a valid password."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badIP)) {
            return ui_sysmessage::shout(ui_language::translate("The IP address is not valid. Please enter a valid IP address."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$dbalreadyadded)) {
            return ui_sysmessage::shout(ui_language::translate("That database has already been added to this user."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$wrongpw)) {
            return ui_sysmessage::shout(ui_language::translate("That is not your panel password: the password was not shown."), "zannounceerror");
        }
        if (self::$shownuser !== null && self::$shownpass === '') {
            return ui_sysmessage::shout(str_replace('%s', '<strong>' . self::h(self::$shownuser) . '</strong>', self::t("The password of %s is not stored (it was set before passwords were kept). Set a new one in Databases and password; it can be shown after that.")), "zannounceerror");
        }
        if (self::$shownuser !== null || self::$askpw !== null || !fs_director::CheckForEmptyValue(self::$newpass)) {
            return; // shown by getSecretCard()
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your MySQL users have been saved successfully!"), "zannounceok");
        }
        return;
    }

    // Webinterface sudo methods.
}
