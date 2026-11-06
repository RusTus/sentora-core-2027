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

    /**
     * The 'worker' methods.
     */
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
			// MySQL 5.7 + 
			$sql = $zdbh->prepare("ALTER USER :username@:access IDENTIFIED BY :password");
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
        $my_name_vc = $zdbh->mysqlRealEscapeString($rowdb['my_name_vc']);
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

        $my_name_vc = $zdbh->mysqlRealEscapeString($rowdb['my_name_vc']);
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

        $sql = $zdbh->prepare("REVOKE ALL PRIVILEGES ON `" . $rowdb['my_name_vc'] . "`.* FROM '" . $rowuser['mu_name_vc'] . "'@'" . $rowuser['mu_access_vc'] . "'");
        $sql->execute();

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
					// MySQL 5.7 + 
					$sql = $zdbh->prepare("ALTER USER :mu_name_vc@:mu_access_vc IDENTIFIED BY :password");
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
		if (strlen($password) < ctrl_options::GetSystemOption('password_minlength')) {
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
        if (!preg_match('/^[a-z\d][a-z\d-][a-z\d_]{0,62}$/i', $username) || preg_match('/-$/', $username)) {
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
	

    /**
     * End 'worker' methods.
     */

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
        if ($formvars['inAccess'] == 1) {
            $access = "%";
        } else {
            $access = $formvars['inAccessIP'];
        }
        if (self::ExecuteCreateUser($currentuser['userid'], $formvars['inUserName'], $formvars['inDatabase'], $access))
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
     * Template value <@ MysqlUsagepChart @>: always the "unlimited" image.
     *
     * @return string An <img> tag.
     */
    static function getMysqlUsagepChart()
    {
        return '<img src="' . ui_template::AssetPath('img/misc/unlimited.png') . '" alt="' . ui_language::translate('Unlimited') . '"/>';
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
            return ui_sysmessage::shout(ui_language::translate("A MySQL username with that name already appears to exsist."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return ui_sysmessage::shout(ui_language::translate("Your MySQL user name is not valid. Please enter a valid MySQL user name."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badpass)) {
            return ui_sysmessage::shout(ui_language::translate("Your MySQL password is not valid. Valid characters are A-Z, a-z, 0-9."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$badpasswordlength)) {
            return ui_sysmessage::shout(ui_language::translate("Your password did not meet the minimun length requirements. Characters needed for password length") . ": " . ctrl_options::GetSystemOption('password_minlength'), "zannounceerror");
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
        if (self::$askpw !== null) {
            return ui_sysmessage::shout(ctrl_secret::ConfirmForm(self::$askpw[0], self::$askpw[1], self::$askpw[2]), "zannounceinfo");
        }
        if (!fs_director::CheckForEmptyValue(self::$wrongpw)) {
            return ui_sysmessage::shout(ui_language::translate("That is not your panel password: the password was not shown."), "zannounceerror");
        }
        if (self::$shownuser !== null) {
            $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
            if (self::$shownpass === '') {
                return ui_sysmessage::shout(ui_language::translate("The password of") . ' <strong>' . $h(self::$shownuser) . '</strong> '
                    . ui_language::translate("is not stored (it was set before passwords were kept). Use Reset Password to set a new one; it can be shown after that."), "zannounceerror");
            }
            return ui_sysmessage::shout(ui_language::translate("MySQL user") . ' <strong>' . $h(self::$shownuser) . '</strong>, '
                . ui_language::translate("password:") . ' <code style="user-select: all; font-size: 14px;">' . $h(self::$shownpass) . '</code>', "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$newpass)) {
            $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
            return ui_sysmessage::shout(ui_language::translate("MySQL user created:") . ' <strong>' . $h(self::$newuser) . '</strong><br>'
                . ui_language::translate("Password:") . ' <code style="user-select: all; font-size: 14px;">' . $h(self::$newpass) . '</code><br>'
                . ui_language::translate("You can see it again later with Show password."), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your MySQL users have been saved successfully!"), "zannounceok");
        }
        return;
    }

    /**
     * Webinterface sudo methods.
     */
}
