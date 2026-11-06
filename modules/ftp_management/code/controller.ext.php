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
        runtime_hook::Execute('OnBeforeCreateFTPAccount');
        if (fs_director::CheckForEmptyValue(self::CheckForErrors($username, $password))) {
            // Check to see if its a new home directory or use a current one...
            if ($home == 1) {
                $homedirectory_to_use = '/' . str_replace('.', '_', $username);
                $full_path = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . $homedirectory_to_use . '/';
                // Create the new home directory... (If it doesnt already exist.)
                if (!file_exists($full_path)) {
                    @mkdir($full_path, 777);
                    fs_director::SetFileSystemPermissions($full_path, 0770);
                }
            } else if ($home == 3) {
                $homedirectory_to_use = '/' . $domainDestination;
            } else {
                $homedirectory_to_use = '/' . $destination;
            }
            // An existing folder inside the account only (no "..", no other account)
            if (ctrl_ownership::SafeSubFolder($homedirectory_to_use) === false) {
                return false;
            }

            // Check if Path is inside user home directory.
            $full_homeDir  = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . $homedirectory_to_use . '/';
            $baseDir       = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'];
            $realPath      = realpath($full_homeDir);

            if( 0 !== strpos($realPath, $baseDir))
            {
                self::$invalidPath = true;
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
            $sql->bindParam(':time', time());
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
        return preg_match('/^[a-z\d_][a-z\d_-]{0,62}$/i', $username) || preg_match('/-$/', $username) == 1;
    }

	/**
	 * Validate a new FTP password.
	 *
	 * Sets self::$blankpassword, self::$badpasswordlength or self::$badpass.
	 *
	 * @global db_driver $zdbh The panel's database connection (unused).
	 * @param string $password The password.
	 * @return bool True when there is an error (but false when the password is too short).
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
		// Check for password length...
		if (strlen($password) < ctrl_options::GetSystemOption('password_minlength')) {
			self::$badpasswordlength = true;
			return false;
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
		return preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z])/', $password) || preg_match('/-$/', $password) == 1;
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
        $sql->bindParam(':time', $ft_id_pk);
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

    // End 'worker' methods.

    // Webinterface sudo methods.

    /**
     * Handle the create form: create the FTP account for the logged-in account.
     *
     * Checks the CSRF token; sets self::$ok on success.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when created.
     */
    static function doCreateFTP()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteCreateFTP($currentuser['userid'], $formvars['inFTPUsername'] ?? '', $formvars['inPassword'] ?? '', $formvars['inDestination'] ?? '', $formvars['inDomainDestination'] ?? '', $formvars['inAccess'] ?? '', $formvars['inAutoHome'] ?? '')) {
            self::$ok = true;
            return true;
        } else {
            return false;
        }
    }

    /**
     * Handle the delete confirmation: delete the posted FTP account (inDelete).
     *
     * Checks the CSRF token; sets self::$ok on success.
     *
     * @global runtime_controller $controller The request controller.
     * @return true
     */
    static function doDeleteFTP()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteDeleteFTP($formvars['inDelete']))
            self::$ok = true;
        return true;
    }

    /**
     * Handle the password form: set the posted password (inPassword) on the FTP account inReset.
     *
     * Checks the CSRF token; sets self::$ok on success.
     *
     * @global runtime_controller $controller The request controller.
     * @return true
     */
    static function doResetPassword()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteResetPassword($formvars['inReset'], $formvars['inPassword']))
            self::$ok = true;
        return true;
    }

    /**
     * The logged-in account's FTP accounts (template <% loop ClientList %>).
     *
     * @return array<int, array<string, mixed>>|false One row per FTP account (see ListClients()), or false when there are none.
     */
    static function getClientList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $clientlist = self::ListClients($currentuser['userid']);
        return (!fs_director::CheckForEmptyValue($clientlist)) ? $clientlist : false;
    }

    /**
     * The folders in the account's public_html (template <% loop DomainDirsList %>).
     *
     * @return array<int, array<string, string>>|false One row per folder ('domains'), or false when there are none.
     */
    static function getDomainDirsList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $domaindirectories = self::ListDomainDirs($currentuser['userid']);
        return (!fs_director::CheckForEmptyValue($domaindirectories)) ? $domaindirectories : false;
    }

    /**
     * The folders in the account's home folder (template <% loop MasterDirsList %>).
     *
     * @return array<int, array<string, string>>|false One row per folder ('domains'), or false when there are none.
     */
    static function getMasterDirsList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $domaindirectories = self::ListMasterDirs($currentuser['userid']);
        return (!fs_director::CheckForEmptyValue($domaindirectories)) ? $domaindirectories : false;
    }

    /**
     * Handle the buttons of the FTP account list: delete, reset password or show password.
     *
     * Checks the CSRF token. Delete and reset redirect to the confirmation page and
     * exit; show password asks for the panel password first, then decrypts it into
     * self::$shownuser / self::$shownpass.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doEditFTP()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListClients($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['id'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['id']);
                exit;
            }
            if (isset($formvars['inReset_' . $row['id'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $row['id']);
                exit;
            }
            // Show password: one of this account's own FTP accounts (the loop only has those)
            if (isset($formvars['inShow_' . $row['id'] . ''])) {
                global $zdbh;
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
        return;
    }

    /**
     * Whether to show the create form: no 'show' in the URL (template <% if isCreateFTP %>).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisCreateFTP()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        return !isset($urlvars['show']);
    }

    /**
     * Whether to show the delete confirmation for the FTP account in the URL ('other') (template <% if isDeleteFTP %>).
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|null $uid Optional. The panel account ID; null for the logged-in account. Default null.
     * @return bool|null Null when the FTP account is not the account's.
     */
    static function getisDeleteFTP($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete FTP Account.
        // This shall avoid exposing ftp username based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

        $sql = " SELECT * FROM x_ftpaccounts WHERE ft_acc_fk=:userid AND ft_id_pk=:editedUsrID AND ft_deleted_ts IS NULL";
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
     * Whether to show the reset password form for the FTP account in the URL ('other') (template <% if isEditFTP %>).
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|null $uid Optional. The panel account ID; null for the logged-in account. Default null.
     * @return bool|null Null when the FTP account is not the account's.
     */
    static function getisEditFTP($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars     = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Edit FTP Account.
        // This shall avoid exposing ftp username based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

        $sql = " SELECT * FROM x_ftpaccounts WHERE ft_acc_fk=:userid AND ft_id_pk=:editedUsrID AND ft_deleted_ts IS NULL";
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
     * The user name of the FTP account in the URL ('other') (template <@ EditCurrentName @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The user name, or '' when there is no 'other'.
     */
    static function getEditCurrentName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['username'];
        } else {
            return "";
        }
    }

    /**
     * The ID of the FTP account in the URL ('other') (template <@ EditCurrentID @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string The ID, or '' when there is no 'other'.
     */
    static function getEditCurrentID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['id'];
        } else {
            return "";
        }
    }

    /**
     * Whether the account may create another FTP account (template <% if QuotaLimit %>).
     *
     * @return bool
     */
    static function getQuotaLimit()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['ftpaccountsquota'] < 0 ) or //-1 = unlimited
                ($currentuser['ftpaccountsquota'] > ctrl_users::GetQuotaUsages('ftpaccounts', $currentuser['userid']));
    }

    /**
     * The FTP accounts quota image: "unlimited", or a pie chart of free and used (template <@ FTPUsagepChart @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML <img> tag.
     */
    static function getFTPUsagepChart()
    {
    global $controller;
    $currentuser = ctrl_users::GetUserDetail();
    $maximum = $currentuser['ftpaccountsquota'];
    if ($maximum < 0) { //-1 = unlimited
            if (file_exists(ui_template::AssetPath('img/misc/unlimited.png'))) {
        return '<img src="' . ui_template::AssetPath('img/misc/unlimited.png') . '" alt="' . ui_language::translate('Unlimited') . '"/>';
      } else {
        return '<img src="modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/unlimited.png" alt="' . ui_language::translate('Unlimited') . '"/>';
      }
        } else {
            $used = ctrl_users::GetQuotaUsages('ftpaccounts', $currentuser['userid']);
            $free = max($maximum - $used, 0);
            return '<img src="etc/lib/pChart2/sentora/z3DPie.php?score=' . $free . '::' . $used
                    . '&labels=Free: ' . $free . '::Used: ' . $used
                    . '&legendfont=verdana&legendfontsize=8&imagesize=240::190&chartsize=120::90&radius=100&legendsize=150::160"'
                    . ' alt="' . ui_language::translate('Pie chart') . '"/>';
        }
    }

    /**
     * The password length to suggest: the password_minlength setting, at least 9 (template <@ MinPassLength @>).
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
     * The message box for the last action, or the panel password form for "show password" (template <@ Result @>).
     *
     * @return string|null The HTML, or null when there is nothing to show.
     */
    static function getResult()
    {
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
            return ui_sysmessage::shout(ui_language::translate("FTP account") . ' <strong>' . $h(self::$shownuser) . '</strong>, '
                . ui_language::translate("password:") . ' <code style="user-select: all; font-size: 14px;">' . $h(self::$shownpass) . '</code>', "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return ui_sysmessage::shout(ui_language::translate("You must enter a valid username and password to create your FTP account."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$blankpassword)) {
            return ui_sysmessage::shout(ui_language::translate("You entered blank a password. Please retry and enter a valid password."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("An FTP account with that name already exists."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$error)) {
            return ui_sysmessage::shout(ui_language::translate("There was an error updating your FTP accounts."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return ui_sysmessage::shout(ui_language::translate("Your ftp account name is not valid. Please enter a valid ftp account name."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$badpass)) {
            return ui_sysmessage::shout(ui_language::translate("Your MySQL password is not valid. Valid characters are A-Z, a-z, 0-9."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$badpasswordlength)) {
            return ui_sysmessage::shout(ui_language::translate("Your password did not meet the minimun length requirements. Characters needed for password length") . ": " . ctrl_options::GetSystemOption('password_minlength'), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$invalidPath)) {
            return ui_sysmessage::shout(ui_language::translate("Invalid Folder."), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("FTP accounts updated successfully."), "zannounceok");
        }
        return;
    }

    // Webinterface sudo methods.
}
