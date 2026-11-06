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
class module_controller extends ctrl_module
{

    /** @var bool|null True after a mailbox change was saved (shown by getResult()). */
    static $ok;
    /** @var bool|null True when the password was left blank. */
    static $password;
	/** @var bool|null True when the password is not strong enough. */
	static $badpass;
	/** @var bool|null True when the password is shorter than password_minlength. */
	static $badpasswordlength;
    /** @var bool|null True when a mailbox, forwarder, distribution list or alias already has the address. */
    static $alreadyexists;
    /** @var bool|null True when the address is not a valid email address. */
    static $validemail;
    /** @var bool|null True when the address was left blank. */
    static $noaddress;
    /** @var bool|null Not used in this module. */
    static $editmailbox;
    /** @var bool|null True after a mailbox was updated. */
    static $update;
    /** @var bool|null True after a mailbox was deleted. */
    static $delete;
    /** @var bool|null True while/after a mailbox is created. */
    static $create;

    // The 'worker' methods.
    /**
     * List the account's mailboxes.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (unused).
     * @param int $uid The account ID.
     * @return array<int, array<string, mixed>>|false One row per mailbox: 'address', 'created', 'status' (HTML), 'id'; false when there are none.
     */
    static function ListMailboxes($uid)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $sql = "SELECT * FROM x_mailboxes WHERE mb_acc_fk=:userid AND mb_deleted_ts IS NULL ORDER BY mb_address_vc ASC";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $currentuser['userid']);
            $res = array();
            $sql->execute();
            while ($rowmailboxes = $sql->fetch()) {
                if ($rowmailboxes['mb_enabled_in'] == 1) {
                    $status = ui_status::Ok('Enabled');
                } else {
                    $status = ui_status::Bad('Disabled');
                }
                $res[] = array('address' => $rowmailboxes['mb_address_vc'],
                    'created' => date(ctrl_options::GetSystemOption('sentora_df'), $rowmailboxes['mb_created_ts']),
                    'status' => $status,
                    'id' => $rowmailboxes['mb_id_pk']);
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Get one mailbox of the logged-in account (for the edit and delete forms).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $mid The mailbox ID (from ?other= or the form).
     * @return array<int, array<string, mixed>>|false One row: 'address', 'created', 'ischeck' ("CHECKED" or null), 'id'; false when not found or not owned.
     */
    static function ListCurrentMailboxes($mid)
    {
        // Only this account's own records (the id comes from ?other= or the form)
        if (!ctrl_ownership::Owns('x_mailboxes', 'mb_id_pk', 'mb_acc_fk', $mid)) {
            return false;
        }
        global $zdbh;
        $sql = "SELECT * FROM x_mailboxes WHERE mb_id_pk=:mid AND mb_deleted_ts IS NULL ORDER BY mb_address_vc ASC";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':mid', $mid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':mid', $mid);
            $res = array();
            $sql->execute();
            while ($rowmailboxes = $sql->fetch()) {
                if ($rowmailboxes['mb_enabled_in'] == 1) {
                    $ischeck = "CHECKED";
                } else {
                    $ischeck = NULL;
                }
                $res[] = array('address' => $rowmailboxes['mb_address_vc'],
                    'created' => date(ctrl_options::GetSystemOption('sentora_df'), $rowmailboxes['mb_created_ts']),
                    'ischeck' => $ischeck,
                    'id' => $rowmailboxes['mb_id_pk']);
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the account's enabled domains (for the new mailbox form).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return array<int, array<string, string>>|false One row per domain: 'domain'; false when there are none.
     */
    static function ListDomains($uid)
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_enabled_in=1 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc ASC";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $currentuser['userid']);
            $res = array();
            $sql->execute();
            while ($rowdomains = $sql->fetch()) {
                $res[] = array('domain' => ui_language::translate($rowdomains['vh_name_vc']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Create a mailbox after checking the input.
     *
     * Runs the mail server's include file (mailserver_php option), inserts the
     * x_mailboxes row and runs the create hooks.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int $uid The account ID.
     * @param string $address The local part of the address (before the @).
     * @param string $domain The domain part of the address.
     * @param string $password The mailbox password.
     * @return bool True when created, false when the input failed a check.
     */
    static function ExecuteAddMailbox($uid, $address, $domain, $password)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrors($address, $domain, $password))) {
            return false;
        }
        runtime_hook::Execute('OnBeforeCreateMailbox');
        $address = strtolower(str_replace(' ', '', $address));
        $fulladdress = strtolower(str_replace(' ', '', $address . "@" . $domain));
        self::$create = true;
        // Include mail server specific file here.
        $MailServerFile = 'modules/' . $controller->GetControllerRequest('URL', 'module') . '/code/' . ctrl_options::GetSystemOption('mailserver_php');
        if (file_exists($MailServerFile))
            include($MailServerFile);

        $sql = "INSERT INTO x_mailboxes (mb_acc_fk,
											 mb_address_vc,
											 mb_created_ts) VALUES (
											 :userid,
											 :fulladdress,
											 :time)";
        $time = time();
        $sql = $zdbh->prepare($sql);
        $sql->bindParam(':time', $time);
        $sql->bindParam(':userid', $currentuser['userid']);
        $sql->bindParam(':fulladdress', $fulladdress);
        $sql->execute();
        runtime_hook::Execute('OnAfterCreateMailbox');
        self::$ok = true;
        return true;
    }

    /**
     * Delete a mailbox (mail server include file, then mark the row deleted).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $mid The mailbox ID.
     * @return void
     */
    static function ExecuteDeleteMailbox($mid)
    {
        global $zdbh;
        global $controller;
        runtime_hook::Execute('OnBeforeDeleteMailbox');
        self::$delete = true;
        //$rowmailbox = $zdbh->query("SELECT * FROM x_mailboxes WHERE mb_id_pk=" . $mid . "")->Fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_mailboxes WHERE mb_id_pk=:mid");
        $numrows->bindParam(':mid', $mid);
        $numrows->execute();
        $rowmailbox = $numrows->fetch();
        // Include mail server specific file here.
        $MailServerFile = 'modules/' . $controller->GetControllerRequest('URL', 'module') . '/code/' . ctrl_options::GetSystemOption('mailserver_php');
        if (file_exists($MailServerFile)) {
            include($MailServerFile);
        }
        $time = time();
        $sql = "UPDATE x_mailboxes SET mb_deleted_ts=:time WHERE mb_id_pk=:mid";
        $sql = $zdbh->prepare($sql);
        $sql->bindParam(':time', $time);
        $sql->bindParam(':mid', $mid);
        $sql->execute();
        runtime_hook::Execute('OnAfterDeleteMailbox');
        self::$ok = true;
    }

    /**
     * Update a mailbox: enable or disable it and pass the password to the mail server include file.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $mid The mailbox ID.
     * @param string $password The new password ('' keeps the old one in the include file).
     * @param int|string $enabled Non-zero to enable the mailbox, 0 to disable it.
     * @return false|null Null when updated, false when the password failed a check.
     */
    static function ExecuteUpdateMailbox($mid, $password, $enabled)
    {
        global $zdbh;
        global $controller;
		
		if (fs_director::CheckForEmptyValue(self::CheckPasswordForErrors($password))) {
            
			runtime_hook::Execute('OnBeforeUpdateMailbox');
			$numrows = $zdbh->prepare("SELECT * FROM x_mailboxes WHERE mb_id_pk=:mid");
			$numrows->bindParam(':mid', $mid);
			$numrows->execute();
			$rowmailbox = $numrows->fetch();
			if ($enabled <> 0) {
				self::ExecuteEnableMailbox($mid);
			} else {
				self::ExecuteDisableMailbox($mid);
			}
			self::$update = true;
			// Include mail server specific file here.
			$MailServerFile = 'modules/' . $controller->GetControllerRequest('URL', 'module') . '/code/' . ctrl_options::GetSystemOption('mailserver_php');
			if (file_exists($MailServerFile)) {
				include($MailServerFile);
			}
			runtime_hook::Execute('OnAfterUpdateMailbox');
			self::$ok = true;
			return;
		
		}
		return false;
		
    }

    /**
     * Enable a mailbox.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $mid The mailbox ID.
     * @return true Always true.
     */
    static function ExecuteEnableMailbox($mid)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeEnableMailbox');
        $sql = $zdbh->prepare("UPDATE x_mailboxes SET mb_enabled_in=1 WHERE mb_id_pk=:mid");
        $sql->bindParam(':mid', $mid);
        $sql->execute();
        $retval = true;
        runtime_hook::Execute('OnAfterEnableMailbox');
        return $retval;
    }

    /**
     * Disable a mailbox.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $mid The mailbox ID.
     * @return true Always true.
     */
    static function ExecuteDisableMailbox($mid)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeDisableMailbox');
        $sql = $zdbh->prepare("UPDATE x_mailboxes SET mb_enabled_in=0 WHERE mb_id_pk=:mid");
        $sql->bindParam(':mid', $mid);
        $sql->execute();
        $retval = true;
        runtime_hook::Execute('OnAfterDisableMailbox');
        return $retval;
    }

    /**
     * Check the new mailbox input; sets the matching error flag on the first problem.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $address The local part of the address.
     * @param string $domain The domain part of the address.
     * @param string $password The mailbox password.
     * @return bool True when the input is fine, false on an error.
     */
    static function CheckCreateForErrors($address, $domain, $password)
    {
        global $zdbh;
        $fulladdress = strtolower(str_replace(' ', '', $address . '@' . $domain));
        if (fs_director::CheckForEmptyValue($address)) {
            self::$noaddress = true;
            return false;
        }
        if (fs_director::CheckForEmptyValue($password)) {
            self::$password = true;
            return false;
        }
		// Check for password length...
		if (strlen($password) < ctrl_options::GetSystemOption('password_minlength')) {
			self::$badpasswordlength = true;
			return false;
		}
		// Check for invalid password
        if (!self::IsValidPassword($password)) {
            self::$badpass = true;
            return false;
        }
        if (!self::IsValidEmail($fulladdress)) {
            self::$validemail = true;
            return false;
        }
        $sql = "SELECT * FROM x_mailboxes WHERE mb_address_vc=:fulladdress AND mb_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':fulladdress', $fulladdress);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            self::$alreadyexists = true;
            return false;
        }
        $sql = "SELECT * FROM x_forwarders WHERE fw_address_vc=:fulladdress AND fw_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':fulladdress', $fulladdress);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            self::$alreadyexists = true;
            return false;
        }
        $sql = "SELECT * FROM x_distlists WHERE dl_address_vc=:fulladdress AND dl_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':fulladdress', $fulladdress);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            self::$alreadyexists = true;
            return false;
        }
        $sql = "SELECT * FROM x_aliases WHERE al_address_vc=:fulladdress AND al_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':fulladdress', $fulladdress);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            self::$alreadyexists = true;
            return false;
        }
        return true;
    }

	// These to help with weak passwords
    /**
     * Check that a string is a valid email address.
     *
     * @param string $email The full email address.
     * @return bool
     */
    static function IsValidEmail($email)
    {
        return preg_match('/^[a-z0-9]+([_\\.-][a-z0-9]+)*@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i', $email) == 1;
    }
	/**
	 * Check password strength: a digit, a lowercase and an uppercase letter, or ending in "-".
	 *
	 * @param string $password The password.
	 * @return bool
	 */
	static function IsValidPassword($password)
    {
        //return preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{17,}/', $password) || preg_match('/-$/', $password) == 1;
		return preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z])/', $password) || preg_match('/-$/', $password) == 1;
    }
	
	/**
	 * Check a new password; sets the matching error flag.
	 *
	 * Note the inverted meaning: true means there is an error.
	 *
	 * @global db_driver $zdbh The panel's database connection (unused).
	 * @param string $password The password.
	 * @return bool True when there is an error, false otherwise (also false when it is too short).
	 */
	static function CheckPasswordForErrors($password)
    {
        global $zdbh;
        $retval = FALSE;
		
		// Check to make sure the password is not blank before we go any further...
        if ($password == '') {
            self::$password = TRUE;
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
	
    // End 'worker' methods.

    // Webinterface sudo methods.
    /**
     * Handle the new mailbox form POST for the logged-in account.
     *
     * @global runtime_controller $controller The request controller.
     * @return true Always true.
     */
    static function doAddMailbox()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteAddMailbox($currentuser['userid'], $formvars['inAddress'], $formvars['inDomain'], $formvars['inPassword']))
            self::$ok = true;
        return true;
    }

    /**
     * Handle the mailbox list POST: redirect to the delete or edit view and exit.
     *
     * @global runtime_controller $controller The request controller.
     * @return true True when no edit or delete button was sent (otherwise it exits).
     */
    static function doEditMailbox()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListMailboxes($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['id']])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . '&show=Delete&other=' . $row['id']);
                exit;
            }
            if (isset($formvars['inEdit_' . $row['id']])) {
                header('location: ./?module=' . $controller->GetCurrentModule() . '&show=Edit&other=' . $row['id']);
                exit;
            }
        }
        return true;
    }

    /**
     * Handle the edit mailbox form POST.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool False when the mailbox is not the account's own, else true.
     */
    static function doUpdateMailbox()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inSave']) || !ctrl_ownership::Owns('x_mailboxes', 'mb_id_pk', 'mb_acc_fk', $formvars['inSave'], ' AND mb_deleted_ts IS NULL')) {
            return false;
        }
        $enabled = (isset($formvars['inEnabled'])) ? fs_director::GetCheckboxValue($formvars['inEnabled']) : 0;
        if (self::ExecuteUpdateMailbox($formvars['inSave'], $formvars['inPassword'], $enabled))
            self::$ok = true;
        return true;
    }

    /**
     * Handle the delete confirmation POST.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when the mailbox is not the account's own, else null (ExecuteDeleteMailbox() returns nothing).
     */
    static function doConfirmDeleteMailbox()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inDelete']) || !ctrl_ownership::Owns('x_mailboxes', 'mb_id_pk', 'mb_acc_fk', $formvars['inDelete'], ' AND mb_deleted_ts IS NULL')) {
            return false;
        }
        return self::ExecuteDeleteMailbox($formvars['inDelete']);
    }

    /**
     * Template loop: the logged-in account's mailboxes.
     *
     * @return array<int, array<string, mixed>>|false Rows from ListMailboxes(), or false when there are none.
     */
    static function getMailboxList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListMailboxes($currentuser['userid']);
    }

    /**
     * Template loop: the logged-in account's domains.
     *
     * @return array<int, array<string, string>>|false Rows from ListDomains(), or false when there are none.
     */
    static function getDomainList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListDomains($currentuser['userid']);
    }

    /**
     * Template loop: the mailbox named by ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return array<int, array<string, mixed>>|false Rows from ListCurrentMailboxes().
     */
    static function getCurrentMailboxList()
    {
        global $controller;
        return self::ListCurrentMailboxes($controller->GetControllerRequest('URL', 'other'));
    }

    /**
     * Read a value from x_mail_settings.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name The setting name (mbs_name_vc).
     * @return string|false The value, or false when not found.
     */
    static function GetMailOption($name)
    {
        global $zdbh;
        $numrows = $zdbh->prepare("SELECT mbs_value_tx FROM x_mail_settings WHERE mbs_name_vc = :name");
        $numrows->bindParam(':name', $name);
        $numrows->execute();
        $result = $numrows->fetch();
        return ($result) ? $result['mbs_value_tx'] : false;
    }

    /**
     * Template flag: show the create form (no ?show= in the URL).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisCreateMailbox()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        return !isset($urlvars['show']);
    }

    /**
     * Template flag: show the delete view for an own mailbox (?show=Delete&other=<id>).
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|null $uid Optional. The account ID; null for the logged-in account. Default null.
     * @return bool|null Null when the mailbox is not the account's own.
     */
    static function getisDeleteMailbox($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete Mail Account.
        // This shall avoid exposing mail username based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

    	$sql = "SELECT * FROM x_mailboxes WHERE mb_acc_fk=:userid AND mb_id_pk=:editedUsrID AND mb_deleted_ts IS NULL";
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
     * Template flag: show the edit view for an own mailbox (?show=Edit&other=<id>).
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|null $uid Optional. The account ID; null for the logged-in account. Default null.
     * @return bool|null Null when the mailbox is not the account's own.
     */
    static function getisEditMailbox($uid = null)
    {
		
        global $controller;
        global $zdbh;

        $urlvars     = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Edit Mail Account.
        // This shall avoid exposing mail username based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

    	$sql = "SELECT * FROM x_mailboxes WHERE mb_acc_fk=:userid AND mb_id_pk=:editedUsrID AND mb_deleted_ts IS NULL";
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
     * Template value: the address of the mailbox named by ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return string|null The address, '' without ?other=, null when not found.
     */
    static function getEditCurrentMailboxName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentMailboxes($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['address'];
        } else {
            return '';
        }
    }

    /**
     * Template value: the ID of the mailbox named by ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string|null The ID, '' without ?other=, null when not found.
     */
    static function getEditCurrentMailboxID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentMailboxes($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['id'];
        } else {
            return "";
        }
    }

    /**
     * Template flag: the account may create another mailbox (unlimited or under quota).
     *
     * @return bool
     */
    static function getQuotaLimit()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['mailboxquota'] < 0) or //-1 = unlimited
                ($currentuser['mailboxquota'] > ctrl_users::GetQuotaUsages('mailboxes', $currentuser['userid']));
    }

    /**
     * Template HTML: the mailbox quota pie chart, or the "unlimited" image.
     *
     * @return string HTML img tag.
     */
    static function getEmailUsagepChart()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $maximum = $currentuser['mailboxquota'];
        if ($maximum < 0) { //-1 = unlimited
            return '<img src="' . ui_template::AssetPath('img/misc/unlimited.png') . '" alt="' . ui_language::translate('Unlimited') . '"/>';
        } else {
            $used = ctrl_users::GetQuotaUsages('mailboxes', $currentuser['userid']);
            $free = max($maximum - $used, 0);
            return '<img src="etc/lib/pChart2/sentora/z3DPie.php?score=' . $free . '::' . $used
                    . '&labels=Free: ' . $free . '::Used: ' . $used
                    . '&legendfont=verdana&legendfontsize=8&imagesize=240::190&chartsize=120::90&radius=100&legendsize=150::160"'
                    . ' alt="' . ui_language::translate('Pie chart') . '"/>';
        }
    }

    /**
     * Template value: the password length to suggest (at least 9, or password_minlength when higher).
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
     * Template HTML: the error or success message of the last action.
     *
     * @return string|null HTML message, or null when there is nothing to show.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate('A mailbox, alias, forwarder or distribution list already exists with that name.'), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$validemail)) {
            return ui_sysmessage::shout(ui_language::translate("Your email address is not valid."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$password)) {
            return ui_sysmessage::shout(ui_language::translate("Your password cannot be blank."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$badpass)) {
            return ui_sysmessage::shout(ui_language::translate("Your password is not valid. Valid characters are A-Z, a-z, 0-9."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$badpasswordlength)) {
            return ui_sysmessage::shout(ui_language::translate("Your password did not meet the minimun length requirements. Characters needed for password length") . ": " . ctrl_options::GetSystemOption('password_minlength'), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$noaddress)) {
            return ui_sysmessage::shout(ui_language::translate("Your email address cannot be blank."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your mailboxes have been saved successfully!"), "zannounceok");
        }
        return;
    }

    // Webinterface sudo methods.
}
