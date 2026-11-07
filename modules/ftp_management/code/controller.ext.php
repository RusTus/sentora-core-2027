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
 * FTP Accounts module: create, delete, reset and show the FTP accounts of the logged-in account.
 *
 * Accounts are rows of x_ftpaccounts (a crypt hash for ProFTPD plus an encrypted
 * copy for "show password"); the FTP server specific file named by the ftp_php
 * setting is included after each change.
 */
class module_controller extends ctrl_module
{

    /** @var string|null Show password: the FTP user name whose password is shown. */
    static $shownuser = null; // Show password

    /** @var string|null Show password: the decrypted password ('' when it could not be decrypted). */
    static $shownpass = null;

    /** @var array{0: string, 1: string, 2: string}|null Show password: ask for the panel password first (form action, button name, FTP user name). */
    static $askpw = null;   // Show password: ask for the panel password first

    /** @var bool|null True when the panel password typed to show a password was wrong. */
    static $wrongpw = null;

    /** @var bool|null General error flag (shown by getResult()). */
    static $error;
    /** @var bool|null True when the FTP user name is already taken. */
    static $alreadyexists;
    /** @var bool|null True when the user name or password was blank. */
    static $blank;
	/** @var bool|null True when the new password was blank. */
	static $blankpassword;	
    /** @var bool|null True when the user name has invalid characters. */
    static $badname;
	/** @var bool|null True when the password does not have a digit, a lower-case and an upper-case letter. */
	static $badpass;
	/** @var bool|null True when the password is shorter than the password_minlength setting. */
	static $badpasswordlength;
    /** @var bool|null True when the chosen home folder is outside the account's folder. */
    static $invalidPath;
    /** @var bool|null True after a successful create, delete or password reset. */
    static $ok;
    /** @var bool|null True after an FTP account was deleted. */
    static $delete;
    /** @var bool|null True after an FTP password was reset. */
    static $reset;
    /** @var bool|null True after an FTP account was created. */
    static $create;
    /** @var bool|null True while an FTP account's access or home folder is changed (for the FTP server file). */
    static $update;
    /** @var string The view after a refused form ('new' or 'edit', '' for none), so it shows again with what was typed. */
    static $failed = '';

    // The 'worker' methods.

    /**
     * The logged-in account's user name (template <@ CurrentUserName @>, the FTP user name prefix).
     *
     * @global runtime_controller $controller The request controller (unused).
     * @return string The user name.
     */
    static function getCurrentUserName()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return $currentuser['username'];
    }
	
    /**
     * List an account's FTP accounts.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The panel account ID.
     * @return array<int, array<string, mixed>>|false One row per FTP account: 'id', 'directory', 'access', 'password' (always ''), 'username'; false when there are none.
     */
    static function ListClients($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_ftpaccounts WHERE ft_acc_fk=:userid AND ft_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $uid);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->bindParam(':userid', $uid);
            $sql->execute();
            while ($rowclients = $sql->fetch()) {
                $res[] = array('id' => $rowclients['ft_id_pk'],
                    'directory' => runtime_xss::xssClean($rowclients['ft_directory_vc']),
                    'access' => runtime_xss::xssClean($rowclients['ft_access_vc']),	
					'password' => '', // only a hash is stored: never shown			
                    'username' => runtime_xss::xssClean($rowclients['ft_user_vc']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Get one FTP account by its ID.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The FTP account ID (ft_id_pk), despite the name.
     * @return array<int, array<string, mixed>>|false A list with the one row ('id', 'directory', 'access', 'password', 'username'), or false when not found.
     */
    static function ListCurrentClient($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_ftpaccounts WHERE ft_id_pk=:userid AND ft_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $uid);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $uid);
            $res = array();
            $sql->execute();
            while ($rowclients = $sql->fetch()) {
                $res[] = array('id' => $rowclients['ft_id_pk'],
                    'directory' => runtime_xss::xssClean($rowclients['ft_directory_vc']),
                    'access' => runtime_xss::xssClean($rowclients['ft_access_vc']),
					'password' => '', // only a hash is stored: never shown
                    'username' => runtime_xss::xssClean($rowclients['ft_user_vc']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the folders in an account's home folder (except _errorpages).
     *
     * @param int|string $uid The panel account ID.
     * @return array<int, array<string, string>> One row per folder: 'domains' => folder name.
     */
    static function ListMasterDirs($uid)
    {
        $currentuser = ctrl_users::GetUserDetail($uid);
        $res = array();
        $handle = @opendir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "");
        $chkdir = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/";
        if (!$handle) {
            // Log an error as the folder cannot be opened...
        } else {
            while ($file = @readdir($handle)) {
                if ($file != '.' && $file != '..' && $file != '_errorpages') {
                    if (is_dir($chkdir . $file)) {
                        $res[] = array('domains' => runtime_xss::xssClean($file));
                    }
                }
            }
            closedir($handle);
        }
        return $res;
    }

    /**
     * List the folders in an account's public_html folder (except _errorpages).
     *
     * @param int|string $uid The panel account ID.
     * @return array<int, array<string, string>> One row per folder: 'domains' => folder name.
     */
    static function ListDomainDirs($uid)
    {
        $currentuser = ctrl_users::GetUserDetail($uid);
        $res = array();
        $handle = @opendir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/public_html");
        $chkdir = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/public_html/";
        if (!$handle) {
            // Log an error as the folder cannot be opened...
        } else {
            while ($file = @readdir($handle)) {
                if ($file != "." && $file != ".." && $file != "_errorpages") {
                    if (is_dir($chkdir . $file)) {
                        $res[] = array('domains' => runtime_xss::xssClean($file));
                    }
                }
            }
            closedir($handle);
        }
        return $res;
    }

    /**
     * Set a new password on one of the logged-in account's FTP accounts.
     *
     * Stores the crypt hash and an encrypted copy, sets self::$reset, includes the FTP
     * server specific file and runs the OnBefore/OnAfterResetFTPPassword hooks.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $ft_id_pk The FTP account ID.
     * @param string $password The new password.
     * @return bool|null True when changed; null when the account is not the logged-in account's; false when the password is not valid.
     */
    static function ExecuteResetPassword($ft_id_pk, $password)
    {
        global $zdbh;
        global $controller;

		if (fs_director::CheckForEmptyValue(self::CheckPasswordForErrors($password))) {

			// Verify if Current user can Edit FTP Account (the logged-in user)
			$currentuser = ctrl_users::GetUserDetail();
	
			$sql = "SELECT * FROM x_ftpaccounts WHERE ft_acc_fk=:userid AND ft_id_pk=:editedUsrID AND ft_deleted_ts IS NULL";
			$numrows = $zdbh->prepare($sql);
			$numrows->bindParam(':userid', $currentuser['userid']);
			$numrows->bindParam(':editedUsrID', $ft_id_pk);
			$numrows->execute();
	
			if( $numrows->rowCount() == 0 ) {
				return;
			}
	
			// Change User Password: ProFTPD gets a one-way hash; the panel also keeps an
			// encrypted copy (ctrl_secret) so the owner can see it again
			$enc = ctrl_secret::Encrypt($password);
			$password = self::HashPassword($password);
			runtime_hook::Execute('OnBeforeResetFTPPassword');
			$rowftpsql = "SELECT * FROM x_ftpaccounts WHERE ft_id_pk=:ftIdPk";
			$rowftpfind = $zdbh->prepare($rowftpsql);
			$rowftpfind->bindParam(':ftIdPk', $ft_id_pk);
			$rowftpfind->execute();
			$rowftp = $rowftpfind->fetch();
	
			$sql = $zdbh->prepare("UPDATE x_ftpaccounts SET ft_password_vc=:password, ft_passenc_tx=:enc WHERE ft_id_pk=:ftpid");
			$sql->bindParam(':password', $password);
			$sql->bindParam(':enc', $enc);
			$sql->bindParam(':ftpid', $ft_id_pk);
			$sql->execute();
	
			self::$reset = true;
			// Include FTP server specific file here.
			$FtpModuleFile = 'modules/' . $controller->GetControllerRequest('URL', 'module') . '/code/' . ctrl_options::GetSystemOption('ftp_php');
			if (file_exists($FtpModuleFile)) {
				include($FtpModuleFile);
			}
			$retval = TRUE;
			runtime_hook::Execute('OnAfterResetFTPPassword');
			return $retval;

		} 
				 
		return false;
		
    }

    /**
     * Create an FTP account (named <account>_<username>) for a panel account.
     *
     * Sets self::$create, includes the FTP server specific file and runs the
     * OnBefore/OnAfterCreateFTPAccount hooks.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $uid The panel account ID.
     * @param string $username The FTP user name without the account prefix.
     * @param string $password The password.
     * @param string $destination The existing folder in the account's home folder (when $home is not 1 or 3).
     * @param string $domainDestination The existing folder in public_html (when $home is 3).
     * @param string $access_type The access: 'RO', 'WO' or 'RW'.
     * @param int|string $home 1: a new folder named after the user; 3: $domainDestination; else $destination.
     * @return bool True when created.
     */
    static function ExecuteCreateFTP($uid, $username, $password, $destination, $domainDestination, $access_type, $home)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
  		$username = $currentuser['username'] . '_' . $username;
        if (!in_array($access_type, array('RO', 'WO', 'RW'), true)) {
            self::$error = true;
            return false;
        }
        runtime_hook::Execute('OnBeforeCreateFTPAccount');
        if (fs_director::CheckForEmptyValue(self::CheckForErrors($username, $password))) {
            // the home folder: new, or one of the account's own (checked to be inside it)
            $homedirectory_to_use = self::HomeFolder($currentuser, $home, $username, (string) $destination, (string) $domainDestination);
            if ($homedirectory_to_use === false) {
                return false;
            }

            // ProFTPD gets a one-way hash; the panel also keeps an encrypted copy
            // (ctrl_secret) so the owner can see it again
            $enc = ctrl_secret::Encrypt($password);
            $password = self::HashPassword($password);
            $sql = $zdbh->prepare("INSERT INTO x_ftpaccounts (ft_acc_fk, ft_user_vc, ft_directory_vc, ft_access_vc, ft_password_vc, ft_passenc_tx, ft_created_ts) VALUES (:userid, :username, :homedir, :accesstype, :password, :enc, :time)");
            $sql->bindParam(':enc', $enc);
            $sql->bindParam(':userid', $currentuser['userid']);
            $sql->bindParam(':username', $username);
            $sql->bindParam(':homedir', $homedirectory_to_use);
            $sql->bindParam(':accesstype', $access_type);
            $sql->bindParam(':password', $password);
            // bindValue: PHP 8 refuses time() in bindParam, so creating an account stopped here
            $sql->bindValue(':time', time(), PDO::PARAM_INT);
            $sql->execute();
            self::$create = true;
            // Include FTP server specific file here.
            $FtpModuleFile = 'modules/' . $controller->GetControllerRequest('URL', 'module') . '/code/' . ctrl_options::GetSystemOption('ftp_php');
            if (file_exists($FtpModuleFile)) {
                include($FtpModuleFile);
            }
            runtime_hook::Execute('OnAfterCreateFTPAccount');
            return true;
        }
        return false;
    }

    /**
     * SHA-512 crypt hash ("$6$", salted, 50,000 rounds) of an FTP password: ProFTPD
     * checks logins against it with the system's crypt() (SQLAuthTypes Crypt).
     *
     * @param string $password The plain password.
     * @return string The crypt hash.
     */
    static function HashPassword($password)
    {
        $salt = substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16);
        return crypt((string) $password, '$6$rounds=50000$' . $salt . '$');
    }

    /**
     * Validate a new FTP account's user name and password and check the name is free.
     *
     * Sets the matching error flags (self::$blank, $badname, $badpass, $alreadyexists).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $username The full FTP user name.
     * @param string $password The password.
     * @return bool True when there is an error.
     */
    static function CheckForErrors($username, $password)
    {
        global $zdbh;
        $retval = FALSE;
        // Check to make sure the username and password is not blank before we go any further...
        if ($username == '' || $password == '') {
            self::$blank = TRUE;
            $retval = TRUE;
        }
        // the shortest password (only a reset checked it)
        if ($password !== '' && strlen($password) < (int) self::getMinPassLength()) {
            self::$badpasswordlength = true;
            $retval = TRUE;
        }
        // Check for invalid username
        if (!self::IsValidUserName($username)) {
            self::$badname = true;
            $retval = TRUE;
        }
		// Check for invalid password
        if (!self::IsValidPassword($password)) {
            self::$badpass = true;
            $retval = TRUE;
        }
        // Check to make sure the cron is not a duplicate...
        $sql = "SELECT COUNT(*) FROM x_ftpaccounts WHERE ft_user_vc=:userid AND ft_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $username);

        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                self::$alreadyexists = TRUE;
                $retval = TRUE;
            }
        }
        return $retval;
    }
	
    /**
     * Check an FTP user name: letters, digits, underscores and dashes, at most 63 characters.
     *
     * @param string $username The user name.
     * @return bool
     */
    static function IsValidUserName($username)
    {
        // (anything ending in "-" passed, slashes and dots included)
        return (bool) preg_match('/^[a-z\d_][a-z\d_-]{0,62}$/i', $username);
    }

	/**
	 * Validate a new FTP password.
	 *
	 * Sets self::$blankpassword, self::$badpasswordlength or self::$badpass.
	 *
	 * @global db_driver $zdbh The panel's database connection (unused).
	 * @param string $password The password.
	 * @return bool True when there is an error (blank, too short, or not valid), false otherwise.
	 */
	static function CheckPasswordForErrors($password)
    {
        global $zdbh;
        $retval = FALSE;
		
		// Check to make sure the password is not blank before we go any further...
        if ($password == '') {
            self::$blankpassword = TRUE;
            $retval = TRUE;
        }
		// Check for password length... (true = there IS an error; this returned false, so a
		// blank or too-short password was accepted on reset)
		if (strlen($password) < (int) self::getMinPassLength()) {
			self::$badpasswordlength = true;
			return true;
		}
        // Check for invalid password
        if (!self::IsValidPassword($password)) {
            self::$badpass = true;
            $retval = TRUE;
        }
		return $retval;
    }
	
	/**
	 * Check that a password has a digit, a lower-case and an upper-case letter.
	 *
	 * @param string $password The password.
	 * @return bool
	 */
	static function IsValidPassword($password)
    {
        //return preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}/', $password) || preg_match('/-$/', $password) == 1;
		// (any password ending in "-" passed)
		return (bool) preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z])/', $password);
    }
	
    /**
     * Delete one of an account's FTP accounts (marks it deleted).
     *
     * Sets self::$delete, includes the FTP server specific file and runs the
     * OnBefore/OnAfterDeleteFTPAccount hooks.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $ft_id_pk The FTP account ID.
     * @param int|null $uid Optional. The panel account ID; null for the logged-in account. Default null.
     * @return true|null True when deleted, null when the FTP account is not the account's.
     */
    static function ExecuteDeleteFTP($ft_id_pk, $uid = null)
    {
        global $zdbh;
        global $controller;

        // Verify if Current user can Edit FTP Account.
        $currentuser = ctrl_users::GetUserDetail($uid);

        $sql = "SELECT * FROM x_ftpaccounts WHERE ft_acc_fk=:userid AND ft_id_pk=:editedUsrID AND ft_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->bindParam(':editedUsrID', $ft_id_pk);
        $numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }

        // Delete User
        runtime_hook::Execute('OnBeforeDeleteFTPAccount');
        $rowftpsql = "SELECT * FROM x_ftpaccounts WHERE ft_id_pk=:ftIdPk";
        $rowftpfind = $zdbh->prepare($rowftpsql);
        $rowftpfind->bindParam(':ftIdPk', $ft_id_pk);
        $rowftpfind->execute();
        $rowftp = $rowftpfind->fetch();

        $sql = $zdbh->prepare("UPDATE x_ftpaccounts SET ft_deleted_ts=:time WHERE ft_id_pk=:ftpid");
        $sql->bindParam(':ftpid', $ft_id_pk);
        // the time it was deleted (this stored the account's ID)
        $sql->bindValue(':time', time(), PDO::PARAM_INT);
        $sql->execute();
        self::$delete = true;
        // Include FTP server specific file here.
        $FtpModuleFile = 'modules/' . $controller->GetControllerRequest('URL', 'module') . '/code/' . ctrl_options::GetSystemOption('ftp_php');
        if (file_exists($FtpModuleFile)) {
            include($FtpModuleFile);
        }
        $retval = TRUE;
        runtime_hook::Execute('OnAfterDeleteFTPAccount');
        return $retval;
    }

    /**
     * The home folder of an FTP account from the form's choice, checked to be inside the account's own folder.
     *
     * @param array<string, mixed> $u The panel account (ctrl_users::GetUserDetail()).
     * @param int|string $home 1: a new folder named after the FTP user; 2: $destination (in the home folder); 3: $domainDestination (in public_html).
     * @param string $username The full FTP user name (for a new folder).
     * @param string $destination A folder in the account's home folder.
     * @param string $domainDestination A folder under public_html ("public_html/<name>" or "").
     * @return string|false The folder, starting with "/", or false (self::$invalidPath set) when it is not the account's.
     */
    static function HomeFolder($u, $home, $username, $destination, $domainDestination)
    {
        if ((int) $home === 1) {
            $dir = '/' . str_replace('.', '_', $username);
            $full = ctrl_options::GetSystemOption('hosted_dir') . $u['username'] . $dir . '/';
            if (!file_exists($full)) {
                @mkdir($full, 0770);
                fs_director::SetFileSystemPermissions($full, 0770);
            }
        } elseif ((int) $home === 3) {
            $dir = '/' . ($domainDestination === '' ? 'public_html' : $domainDestination);
        } else {
            $dir = '/' . $destination;
        }
        $base = ctrl_options::GetSystemOption('hosted_dir') . $u['username'];
        $real = realpath($base . $dir);
        if (ctrl_ownership::SafeSubFolder($dir) === false || $real === false || ($real !== $base && strpos($real, $base . '/') !== 0)) {
            self::$invalidPath = true;
            return false;
        }
        return '/' . trim($dir, '/');
    }

    /**
     * Change the access type and home folder of one of the logged-in account's FTP accounts.
     *
     * Includes the FTP server specific file (its home folder); the access type reaches ProFTPD
     * through the daemon's rules within 5 minutes. Runs the OnBefore/OnAfterUpdateFTPAccount hooks.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $ft_id_pk The FTP account ID.
     * @param string $access_type 'RO', 'WO' or 'RW'.
     * @param int|string $home See HomeFolder().
     * @param string $destination See HomeFolder().
     * @param string $domainDestination See HomeFolder().
     * @return bool True when changed.
     */
    static function ExecuteUpdateFTP($ft_id_pk, $access_type, $home, $destination, $domainDestination)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $q = $zdbh->prepare("SELECT * FROM x_ftpaccounts WHERE ft_id_pk = :id AND ft_acc_fk = :u AND ft_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $ft_id_pk, ':u' => (int) $currentuser['userid']));
        $rowftp = $q->fetch();
        if (!$rowftp || !in_array($access_type, array('RO', 'WO', 'RW'), true)) {
            self::$error = true;
            return false;
        }
        $homedirectory_to_use = (int) $home === 0 ? (string) $rowftp['ft_directory_vc'] : self::HomeFolder($currentuser, $home, $rowftp['ft_user_vc'], $destination, $domainDestination);
        if ($homedirectory_to_use === false) {
            return false;
        }
        runtime_hook::Execute('OnBeforeUpdateFTPAccount');
        $q = $zdbh->prepare("UPDATE x_ftpaccounts SET ft_access_vc = :a, ft_directory_vc = :d WHERE ft_id_pk = :id");
        $q->execute(array(':a' => $access_type, ':d' => $homedirectory_to_use, ':id' => (int) $ft_id_pk));
        self::$update = true;
        $FtpModuleFile = 'modules/' . $controller->GetControllerRequest('URL', 'module') . '/code/' . ctrl_options::GetSystemOption('ftp_php');
        if (file_exists($FtpModuleFile)) {
            include($FtpModuleFile);
        }
        self::$update = null;
        runtime_hook::Execute('OnAfterUpdateFTPAccount');
        return true;
    }

    // End 'worker' methods.

    // Webinterface sudo methods.

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
     * The access types: name and what it allows.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    static function AccessTypes()
    {
        return array(
            'RW' => array('Full access', 'Download, upload, rename and delete.'),
            'RO' => array('Read only', 'Download only: nothing can be changed.'),
            'WO' => array('Upload only', 'Upload and change files, but not download them.'),
        );
    }

    /**
     * The logged-in account's FTP accounts.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>> By name: 'id', 'user', 'dir', 'access', 'created', 'shown' (whether a password can be shown).
     */
    static function Accounts()
    {
        global $zdbh;
        static $rows = null;
        if ($rows === null) {
            $q = $zdbh->prepare("SELECT ft_id_pk, ft_user_vc, ft_directory_vc, ft_access_vc, ft_created_ts, ft_passenc_tx FROM x_ftpaccounts WHERE ft_acc_fk = :u AND ft_deleted_ts IS NULL ORDER BY ft_user_vc");
            $q->execute(array(':u' => (int) ctrl_users::GetUserDetail()['userid']));
            $rows = array();
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = array('id' => (int) $r['ft_id_pk'], 'user' => (string) $r['ft_user_vc'], 'dir' => (string) $r['ft_directory_vc'], 'access' => (string) $r['ft_access_vc'],
                    'created' => (int) $r['ft_created_ts'], 'shown' => strpos((string) $r['ft_passenc_tx'], 'enc1:') === 0);
            }
        }
        return $rows;
    }

    /**
     * One of the logged-in account's FTP accounts.
     *
     * @param mixed $id The FTP account ID.
     * @return array<string, mixed>|false See Accounts().
     */
    static function Mine($id)
    {
        foreach (self::Accounts() as $a) {
            if ($a['id'] === (int) $id) {
                return $a;
            }
        }
        return false;
    }

    /**
     * Which page shows: the list, the new account form, an account's edit form, or a delete confirmation.
     *
     * @global runtime_controller $controller The request controller.
     * @return string 'list', 'new', 'edit' or 'delete'.
     */
    static function View()
    {
        global $controller;
        if (self::$failed !== '') {
            return self::$failed;
        }
        $show = $controller->GetControllerRequest('URL', 'show');
        if ($show === 'New' && self::getQuotaLimit()) {
            return 'new';
        }
        if (in_array($show, array('Edit', 'Delete'), true) && self::Mine($controller->GetControllerRequest('URL', 'other'))) {
            return strtolower($show);
        }
        return 'list';
    }

    /**
     * Template flag <% if IsList %>.
     *
     * @return bool
     */
    static function getIsList()
    {
        return self::View() === 'list';
    }

    /**
     * Template flag <% if IsForm %>: the new account form or an account's edit form.
     *
     * @return bool
     */
    static function getIsForm()
    {
        return in_array(self::View(), array('new', 'edit'), true);
    }

    /**
     * Template flag <% if IsNew %>.
     *
     * @return bool
     */
    static function getIsNew()
    {
        return self::View() === 'new';
    }

    /**
     * Template flag <% if IsEdit %>.
     *
     * @return bool
     */
    static function getIsEdit()
    {
        return self::View() === 'edit';
    }

    /**
     * Template flag <% if IsDelete %>.
     *
     * @return bool
     */
    static function getIsDelete()
    {
        return self::View() === 'delete';
    }

    /**
     * The figures at the top (<@ Tiles @>): accounts against the package's limit.
     *
     * @return string The HTML.
     */
    static function getTiles()
    {
        $u = ctrl_users::GetUserDetail();
        $used = count(self::Accounts());
        $max = (int) $u['ftpaccountsquota'];
        $pct = $max > 0 ? min(100, round($used / $max * 100)) : 0;
        $by = array('RW' => 0, 'RO' => 0, 'WO' => 0);
        foreach (self::Accounts() as $a) {
            if (isset($by[$a['access']])) {
                $by[$a['access']]++;
            }
        }
        $ico = function ($d) { return '<svg class="ft-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>'; };
        return '<div class="ft-tile' . ($max >= 0 && $used >= $max ? ' full' : '') . '">' . $ico('<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 10v6M9 13l3 3 3-3"/>')
            . '<div><b>' . $used . ($max < 0 ? '' : ' <small>/ ' . $max . '</small>') . '</b><span>' . self::t('FTP accounts') . ($max < 0 ? ' &middot; ' . self::t('no limit') : '') . '</span>'
            . ($max > 0 ? '<div class="ft-bar"><i style="width:' . $pct . '%"></i></div>' : '') . '</div></div>'
            . '<div class="ft-tile">' . $ico('<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>') . '<div><b>' . $by['RO'] . '</b><span>' . self::t('Read only') . '</span></div></div>'
            . '<div class="ft-tile">' . $ico('<path d="M12 21V9"/><path d="m7 14 5-5 5 5"/><path d="M5 3h14"/>') . '<div><b>' . $by['WO'] . '</b><span>' . self::t('Upload only') . '</span></div></div>';
    }

    /**
     * The FTP accounts (<@ AccountRows @>): name with Copy, home folder, access, and the actions.
     *
     * @return string The HTML.
     */
    static function getAccountRows()
    {
        $types = self::AccessTypes();
        $out = '';
        foreach (self::Accounts() as $a) {
            $t = isset($types[$a['access']]) ? $types[$a['access']] : array($a['access'], '');
            $out .= '<div class="ft-row" data-s="' . self::h(strtolower($a['user'] . ' ' . $a['dir'])) . '">'
                . '<span class="ft-avatar" aria-hidden="true"><svg class="ft-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span>'
                . '<div class="ft-main"><div class="ft-name"><code data-copy="' . self::h($a['user']) . '">' . self::h($a['user']) . '</code>'
                . '<button type="button" class="btn btn-default btn-xs ft-copy" data-done="' . self::t('Copied') . '">' . self::t('Copy') . '</button>'
                . '<span class="ft-acc ft-acc-' . self::h(strtolower($a['access'])) . '" title="' . self::t($t[1]) . '">' . self::t($t[0]) . '</span></div>'
                . '<div class="ft-sub"><span class="ft-dir"><svg class="ft-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>'
                . '<code>' . self::h($a['dir'] === '' ? '/' : $a['dir']) . '</code></span>'
                . ($a['created'] ? '<span>' . self::t('Added') . ' ' . self::h(date('j M Y', $a['created'])) . '</span>' : '') . '</div></div>'
                . '<div class="ft-act">'
                . '<button class="btn btn-default btn-sm" type="submit" form="ft-act" name="inShow_' . $a['id'] . '" value="inShow_' . $a['id'] . '">'
                . '<svg class="ft-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>' . self::t('Password') . '</button>'
                . '<a class="btn btn-default btn-sm" href="./?module=ftp_management&amp;show=Edit&amp;other=' . $a['id'] . '">' . self::t('Edit') . '</a>'
                . '<a class="btn btn-default btn-sm ft-del" href="./?module=ftp_management&amp;show=Delete&amp;other=' . $a['id'] . '" title="' . self::t('Delete') . '" aria-label="' . self::t('Delete') . ' ' . self::h($a['user']) . '">'
                . '<svg class="ft-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></a>'
                . '</div></div>';
        }
        return $out;
    }

    /**
     * Template flag <% if HasAccounts %>.
     *
     * @return bool
     */
    static function getHasAccounts()
    {
        return count(self::Accounts()) > 0;
    }

    /**
     * Template flag <% if NoAccounts %>.
     *
     * @return bool
     */
    static function getNoAccounts()
    {
        return !self::getHasAccounts();
    }

    /**
     * Template flag <% if AtLimit %>: the package allows no more FTP accounts.
     *
     * @return bool
     */
    static function getAtLimit()
    {
        return !self::getQuotaLimit();
    }

    /**
     * The settings for FTP programs (<@ Connection @>): server, port and security, with Copy.
     *
     * @return string The HTML.
     */
    static function getConnection()
    {
        $host = self::h(ctrl_options::GetSystemOption('sentora_domain'));
        $copy = '<button type="button" class="btn btn-default btn-xs ft-copy" data-done="' . self::t('Copied') . '">' . self::t('Copy') . '</button>';
        return '<dl class="ft-dl"><dt>' . self::t('Server') . '</dt><dd><code data-copy="' . $host . '">' . $host . '</code>' . $copy . '</dd>'
            . '<dt>' . self::t('Port') . '</dt><dd><code>21</code></dd>'
            . '<dt>' . self::t('Encryption') . '</dt><dd>' . self::t('Require explicit FTP over TLS (FTPS)') . '</dd>'
            . '<dt>' . self::t('User name') . '</dt><dd>' . self::t('the FTP account name, as shown') . '</dd>'
            . '<dt>' . self::t('Transfer mode') . '</dt><dd>' . self::t('Passive') . '</dd></dl>'
            . '<p class="ft-sub">' . self::t('For FileZilla or WinSCP: protocol FTP, encryption "Require explicit FTP over TLS". Plain FTP sends the password unencrypted.') . '</p>';
    }

    /**
     * The form's values: what was typed after a refused save, the account being changed, or a new one.
     *
     * @global runtime_controller $controller The request controller.
     * @return array<string, mixed> 'id', 'user' (without the prefix for a new one), 'access', 'home' (1, 2 or 3; 0 = keep), 'dest', 'ddest', 'dir' (current).
     */
    static function FormValues()
    {
        global $controller;
        $f = $controller->GetAllControllerRequests('FORM');
        if (self::$failed !== '') {
            $a = self::Mine($f['inEditID'] ?? 0);
            return array('id' => (int) ($f['inEditID'] ?? 0), 'user' => (string) ($f['inFTPUsername'] ?? ($a ? $a['user'] : '')), 'access' => (string) ($f['inAccess'] ?? 'RW'),
                'home' => (int) ($f['inAutoHome'] ?? 1), 'dest' => (string) ($f['inDestination'] ?? ''), 'ddest' => (string) ($f['inDomainDestination'] ?? ''), 'dir' => $a ? $a['dir'] : '');
        }
        if (self::View() === 'edit') {
            $a = self::Mine($controller->GetControllerRequest('URL', 'other'));
            return array('id' => $a['id'], 'user' => $a['user'], 'access' => $a['access'], 'home' => 0, 'dest' => '', 'ddest' => '', 'dir' => $a['dir']);
        }
        return array('id' => 0, 'user' => '', 'access' => 'RW', 'home' => 1, 'dest' => '', 'ddest' => '', 'dir' => '');
    }

    /**
     * <@ FormId @>: the account's ID in the edit form.
     *
     * @return int
     */
    static function getFormId()
    {
        return (int) self::FormValues()['id'];
    }

    /**
     * <@ FormUser @>: the user name typed (new) or the account's name (edit), escaped.
     *
     * @return string
     */
    static function getFormUser()
    {
        return self::h(self::FormValues()['user']);
    }

    /**
     * The access type choices (<@ AccessChoices @>).
     *
     * @return string The HTML.
     */
    static function getAccessChoices()
    {
        $cur = self::FormValues()['access'];
        $out = '';
        foreach (self::AccessTypes() as $k => $t) {
            $out .= '<label class="ft-choice"><input type="radio" name="inAccess" value="' . $k . '"' . ($k === $cur ? ' checked' : '') . '><span><b>' . self::t($t[0]) . '</b><small>' . self::t($t[1]) . '</small></span></label>';
        }
        return $out;
    }

    /**
     * The home folder choices (<@ HomeChoices @>): keep (edit), a new folder, a folder in the home folder, or a website's folder.
     *
     * @return string The HTML.
     */
    static function getHomeChoices()
    {
        $v = self::FormValues();
        $u = ctrl_users::GetUserDetail();
        $opt = function ($rows, $prefix, $cur) {
            $out = '';
            foreach ($rows as $r) {
                $val = $prefix . $r['domains'];
                $out .= '<option value="' . self::h($val) . '"' . ($val === $cur ? ' selected' : '') . '>/' . self::h($val) . '</option>';
            }
            return $out;
        };
        $edit = self::View() === 'edit';
        $out = '';
        if ($edit) {
            $out .= '<label class="ft-home"><input type="radio" name="inAutoHome" value="0"' . ($v['home'] === 0 ? ' checked' : '') . '><span><b>' . self::t('Keep') . ' <code>' . self::h($v['dir'] === '' ? '/' : $v['dir']) . '</code></b></span></label>';
        } else {
            $out .= '<label class="ft-home"><input type="radio" name="inAutoHome" value="1"' . ($v['home'] === 1 ? ' checked' : '') . '><span><b>' . self::t('A new folder of its own') . '</b><small><code>/' . self::h($u['username']) . '_<span id="ft-newdir">' . self::h($v['user'] !== '' ? $v['user'] : '…') . '</span></code></small></span></label>';
        }
        $out .= '<label class="ft-home"><input type="radio" name="inAutoHome" value="3"' . ($v['home'] === 3 ? ' checked' : '') . '><span><b>' . self::t('A website\'s folder') . '</b>'
            . '<select class="form-control input-sm" name="inDomainDestination"><option value="">/public_html</option>' . $opt(self::ListDomainDirs($u['userid']), 'public_html/', $v['ddest']) . '</select></span></label>'
            . '<label class="ft-home"><input type="radio" name="inAutoHome" value="2"' . ($v['home'] === 2 ? ' checked' : '') . '><span><b>' . self::t('Another folder') . '</b>'
            . '<select class="form-control input-sm" name="inDestination"><option value="">/ (' . self::t('everything') . ')</option>' . $opt(self::ListMasterDirs($u['userid']), '', $v['dest']) . '</select></span></label>';
        return $out;
    }

    /**
     * The account the delete confirmation is for (<@ DeleteName @>), escaped.
     *
     * @global runtime_controller $controller The request controller.
     * @return string
     */
    static function getDeleteName()
    {
        global $controller;
        $a = self::Mine($controller->GetControllerRequest('URL', 'other'));
        return $a ? self::h($a['user']) : '';
    }

    /**
     * The account's ID in the delete confirmation (<@ DeleteId @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int
     */
    static function getDeleteId()
    {
        global $controller;
        $a = self::Mine($controller->GetControllerRequest('URL', 'other'));
        return $a ? $a['id'] : 0;
    }

    /**
     * The account's folder in the delete confirmation (<@ DeleteDir @>), escaped.
     *
     * @global runtime_controller $controller The request controller.
     * @return string
     */
    static function getDeleteDir()
    {
        global $controller;
        $a = self::Mine($controller->GetControllerRequest('URL', 'other'));
        return $a ? self::h($a['dir'] === '' ? '/' : $a['dir']) : '';
    }

    /**
     * Handle the new account form, then back to the list.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool|void False when refused (the form shows again); otherwise ends with a redirect and exit.
     */
    static function doCreateFTP()
    {
        global $controller;
        runtime_csfr::Protect();
        self::$failed = 'new';
        // The package's limit on FTP accounts, checked here as well: the page only hides the
        // form at the limit, so a posted form could create more than the package allows
        if (!self::getQuotaLimit()) {
            return false;
        }
        $currentuser = ctrl_users::GetUserDetail();
        $f = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteCreateFTP($currentuser['userid'], $f['inFTPUsername'] ?? '', $f['inPassword'] ?? '', $f['inDestination'] ?? '', $f['inDomainDestination'] ?? '', $f['inAccess'] ?? '', $f['inAutoHome'] ?? '')) {
            header('location: ./?module=' . $controller->GetCurrentModule() . '&saved=' . rawurlencode($currentuser['username'] . '_' . ($f['inFTPUsername'] ?? '')));
            exit;
        }
        return false;
    }

    /**
     * Handle the edit form: access type, home folder and (when typed) a new password; then back to the list.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool|void False when refused; otherwise ends with a redirect and exit.
     */
    static function doUpdateFTP()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $a = self::Mine($f['inEditID'] ?? 0);
        if (!$a) {
            return false;
        }
        self::$failed = 'edit';
        $pass = (string) ($f['inPassword'] ?? '');
        // the password first: nothing is changed when it is refused
        if ($pass !== '' && !fs_director::CheckForEmptyValue(self::CheckPasswordForErrors($pass))) {
            return false;
        }
        if (!self::ExecuteUpdateFTP($a['id'], (string) ($f['inAccess'] ?? ''), $f['inAutoHome'] ?? 0, (string) ($f['inDestination'] ?? ''), (string) ($f['inDomainDestination'] ?? ''))) {
            return false;
        }
        if ($pass !== '') {
            self::ExecuteResetPassword($a['id'], $pass);
        }
        header('location: ./?module=' . $controller->GetCurrentModule() . '&saved=' . rawurlencode($a['user']));
        exit;
    }

    /**
     * Handle the delete confirmation, then back to the list.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool|void False when it is not the account's; otherwise ends with a redirect and exit.
     */
    static function doDeleteFTP()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $a = self::Mine($f['inDelete'] ?? 0);
        if (!$a || !self::ExecuteDeleteFTP($a['id'])) {
            return false;
        }
        header('location: ./?module=' . $controller->GetCurrentModule() . '&deleted=' . rawurlencode($a['user']));
        exit;
    }

    /**
     * The old password form (Reset Password): set the posted password on the FTP account inReset.
     *
     * @global runtime_controller $controller The request controller.
     * @return true
     */
    static function doResetPassword()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteResetPassword($formvars['inReset'] ?? 0, $formvars['inPassword'] ?? '')) {
            self::$ok = true;
        }
        return true;
    }

    /**
     * Handle Show password (and the old list's Edit and Delete buttons, which redirect and exit).
     *
     * Show password asks for the panel password first, then decrypts it into self::$shownuser / self::$shownpass.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doEditFTP()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::Accounts() as $row) {
            if (isset($formvars['inDelete_' . $row['id']])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['id']);
                exit;
            }
            if (isset($formvars['inReset_' . $row['id']])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $row['id']);
                exit;
            }
            // Show password: one of this account's own FTP accounts (the loop only has those)
            if (isset($formvars['inShow_' . $row['id']])) {
                $q = $zdbh->prepare("SELECT ft_user_vc, ft_passenc_tx FROM x_ftpaccounts WHERE ft_id_pk = :id AND ft_acc_fk = :uid AND ft_deleted_ts IS NULL");
                $q->execute(array(':id' => (int) $row['id'], ':uid' => (int) $currentuser['userid']));
                $f = $q->fetch();
                if ($f) {
                    $gate = ctrl_secret::ConfirmGate();
                    if ($gate !== true) {
                        if ($gate === null) {
                            self::$askpw = array('./?module=ftp_management&action=EditFTP', 'inShow_' . (int) $row['id'], $f['ft_user_vc']);
                        } else {
                            self::$wrongpw = true;
                        }
                        return;
                    }
                    $plain = ctrl_secret::Decrypt((string) $f['ft_passenc_tx']);
                    self::$shownuser = $f['ft_user_vc'];
                    self::$shownpass = ($plain === false) ? '' : $plain;
                    if ($plain !== false) {
                        ctrl_secret::LogReveal('FTP account ' . $f['ft_user_vc']);
                    }
                }
                return;
            }
        }
    }

    /**
     * Whether the account may create another FTP account (the package's limit).
     *
     * @return bool
     */
    static function getQuotaLimit()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['ftpaccountsquota'] < 0) or //-1 = unlimited
                ($currentuser['ftpaccountsquota'] > ctrl_users::GetQuotaUsages('ftpaccounts', $currentuser['userid']));
    }

    /**
     * The password length to ask for: the password_minlength setting, at least 9 (template <@ MinPassLength @>).
     *
     * @return int|string
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
     * The message for the last action, or the panel password form for "show password" (template <@ Result @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string|null The HTML, or null when there is nothing to show.
     */
    static function getResult()
    {
        global $controller;
        $err = function ($m) { return ui_sysmessage::shout(ui_language::translate($m), 'zannounceerror'); };
        if (self::$askpw !== null) {
            return ui_sysmessage::shout(ctrl_secret::ConfirmForm(self::$askpw[0], self::$askpw[1], self::$askpw[2]), "zannounceinfo");
        }
        if (!fs_director::CheckForEmptyValue(self::$wrongpw)) {
            return $err('That is not your panel password: the password was not shown.');
        }
        if (self::$shownuser !== null) {
            if (self::$shownpass === '') {
                return ui_sysmessage::shout(ui_language::translate("The password of") . ' <strong>' . self::h(self::$shownuser) . '</strong> '
                    . ui_language::translate("is not stored (it was set before passwords were kept). Set a new one in Edit; it can be shown after that."), "zannounceerror");
            }
            return ui_sysmessage::shout(ui_language::translate("FTP account") . ' <strong>' . self::h(self::$shownuser) . '</strong>, '
                . ui_language::translate("password:") . ' <code style="user-select: all; font-size: 14px;">' . self::h(self::$shownpass) . '</code>', "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return $err('Enter a name and a password for the FTP account.');
        }
        if (!fs_director::CheckForEmptyValue(self::$blankpassword)) {
            return $err('Enter a password, or use Make one.');
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return $err('An FTP account with that name already exists.');
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return $err('The name may have letters, digits, underscores and hyphens.');
        }
        if (!fs_director::CheckForEmptyValue(self::$badpasswordlength)) {
            return ui_sysmessage::shout(ui_language::translate('The password is too short: it needs at least') . ' ' . (int) self::getMinPassLength() . ' ' . ui_language::translate('characters.'), 'zannounceerror');
        }
        if (!fs_director::CheckForEmptyValue(self::$badpass)) {
            return $err('The password needs a lower case letter, a capital and a digit.');
        }
        if (!fs_director::CheckForEmptyValue(self::$invalidPath)) {
            return $err('That folder is not one of yours.');
        }
        if (!fs_director::CheckForEmptyValue(self::$error)) {
            return $err('Choose what the account may do.');
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate('The FTP account is saved.'), 'zannounceok');
        }
        foreach (array('saved' => 'is saved. A change of what it may do applies within 5 minutes.', 'deleted' => 'is deleted. Its files stay where they are.') as $k => $m) {
            $who = $controller->GetControllerRequest('URL', $k);
            if ($who) {
                return ui_sysmessage::shout(ui_language::translate('The FTP account') . ' <strong>' . self::h($who) . '</strong> ' . ui_language::translate($m), 'zannounceok');
            }
        }
        return;
    }
}
