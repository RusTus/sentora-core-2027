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

    /** @var bool|null True when the domain is not one of the account's own (shown by getResult()). */
    static $baddomain;

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
                $res[] = array('domain' => $rowdomains['vh_name_vc']); // a domain name, never translated
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
        // its own forwarders went from Postfix with the mailbox: mark them deleted too, so they no
        // longer show in Forwarders (and count toward the limit) without working
        $fq = $zdbh->prepare("UPDATE x_forwarders SET fw_deleted_ts = :t WHERE fw_address_vc = :a AND fw_acc_fk = :u AND fw_deleted_ts IS NULL");
        $fq->execute(array(':t' => time(), ':a' => $rowmailbox['mb_address_vc'], ':u' => (int) $rowmailbox['mb_acc_fk']));
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
		if (strlen($password) < (int) self::getMinPassLength()) {
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
        // The domain must be one of the account's own: it came from the form unchecked, so a
        // mailbox could be made at another account's domain and receive its mail
        $own = false;
        foreach (self::ListDomains(ctrl_users::GetUserDetail()['userid']) ?: array() as $d) {
            if (strtolower($d['domain']) === strtolower((string) $domain)) {
                $own = true;
            }
        }
        if (!$own) {
            self::$baddomain = true;
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
	 * Note the inverted meaning: true means there is an error. Used when updating a
	 * mailbox, where an empty password keeps the current one.
	 *
	 * @global db_driver $zdbh The panel's database connection (unused).
	 * @param string $password The password ('' = keep the current one).
	 * @return bool True when there is an error (too short, or not valid), false otherwise.
	 */
	static function CheckPasswordForErrors($password)
    {
        global $zdbh;
        $retval = FALSE;
		
		// Updating a mailbox: an empty password keeps the current one (postfix.php only
		// writes a password that is not empty), so it is not an error
        if ((string) $password === '') {
            return false;
        }
		// Check for password length... (true = there IS an error; this returned false, so
		// a password shorter than password_minlength was accepted)
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
        // The package's limit on mailboxes, checked here as well: the page only hides the
        // form at the limit, so a posted form could create more than the package allows
        if (!self::getQuotaLimit()) {
            return false;
        }
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
     * The account's mailboxes as plain rows.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>> x_mailboxes rows, by address.
     */
    static function Rows()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_mailboxes WHERE mb_acc_fk = :u AND mb_deleted_ts IS NULL ORDER BY mb_address_vc");
        $q->execute(array(':u' => (int) ctrl_users::GetUserDetail()['userid']));
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * What else uses each of the account's mailboxes: aliases delivering to it, its forwarders, and the lists it is on.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<string, array{aliases: array<int, string>, forwards: array<int, string>, lists: array<int, string>}> Mailbox address (lower case) => what uses it.
     */
    static function Links()
    {
        global $zdbh;
        $uid = (int) ctrl_users::GetUserDetail()['userid'];
        $out = array();
        $add = function ($box, $kind, $what) use (&$out) {
            $box = strtolower((string) $box);
            if (!isset($out[$box])) {
                $out[$box] = array('aliases' => array(), 'forwards' => array(), 'lists' => array());
            }
            $out[$box][$kind][] = (string) $what;
        };
        $q = $zdbh->prepare("SELECT al_destination_vc, al_address_vc FROM x_aliases WHERE al_acc_fk = :u AND al_deleted_ts IS NULL");
        $q->execute(array(':u' => $uid));
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
            $add($r[0], 'aliases', $r[1]);
        }
        $q = $zdbh->prepare("SELECT fw_address_vc, fw_destination_vc FROM x_forwarders WHERE fw_acc_fk = :u AND fw_deleted_ts IS NULL");
        $q->execute(array(':u' => $uid));
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
            $add($r[0], 'forwards', $r[1]);
        }
        $q = $zdbh->prepare("SELECT u.du_address_vc, l.dl_address_vc FROM x_distlistusers u JOIN x_distlists l ON l.dl_id_pk = u.du_distlist_fk
            WHERE l.dl_acc_fk = :u AND u.du_deleted_ts IS NULL AND l.dl_deleted_ts IS NULL");
        $q->execute(array(':u' => $uid));
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
            $add($r[0], 'lists', $r[1]);
        }
        return $out;
    }

    /**
     * The figures at the top (<@ Tiles @>): mailboxes against the package's limit, switched on, and switched off.
     *
     * @return string The HTML.
     */
    static function getTiles()
    {
        $me = ctrl_users::GetUserDetail();
        $rows = self::Rows();
        $on = count(array_filter($rows, function ($r) { return (int) $r['mb_enabled_in'] === 1; }));
        $max = (int) $me['mailboxquota'];
        $used = count($rows);
        $pct = $max > 0 ? min(100, round($used / $max * 100)) : 0;
        $ico = function ($d) { return '<svg class="mb-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>'; };
        return '<div class="mb-tile' . ($max >= 0 && $used >= $max ? ' full' : '') . '">' . $ico('<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/>')
            . '<div><b>' . $used . ($max < 0 ? '' : ' <small>/ ' . $max . '</small>') . '</b><span>' . self::t('Mailboxes') . ($max < 0 ? ' &middot; ' . self::t('no limit') : '') . '</span>'
            . ($max > 0 ? '<div class="mb-bar"><i style="width:' . $pct . '%"></i></div>' : '') . '</div></div>'
            . '<div class="mb-tile ok">' . $ico('<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>') . '<div><b>' . $on . '</b><span>' . self::t('Receiving mail') . '</span></div></div>'
            . '<div class="mb-tile' . ($used - $on ? ' warn' : '') . '">' . $ico('<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>') . '<div><b>' . ($used - $on) . '</b><span>' . self::t('Switched off') . '</span></div></div>';
    }

    /**
     * One row per mailbox (<@ MailboxRows @>): the address, on or off, when it was made, what else uses it,
     * Change and Delete (buttons of the EditMailbox form).
     *
     * @return string The HTML ('' when there are none).
     */
    static function getMailboxRows()
    {
        $rows = self::Rows();
        if (!$rows) {
            return '';
        }
        $links = self::Links();
        $out = '';
        foreach ($rows as $r) {
            $addr = (string) $r['mb_address_vc'];
            $at = strrpos($addr, '@');
            $on = (int) $r['mb_enabled_in'] === 1;
            $l = isset($links[strtolower($addr)]) ? $links[strtolower($addr)] : array('aliases' => array(), 'forwards' => array(), 'lists' => array());
            $chips = '';
            if ($l['aliases']) {
                $chips .= '<span class="mb-chip" title="' . self::h(implode(', ', $l['aliases'])) . '">' . count($l['aliases']) . ' ' . self::t(count($l['aliases']) === 1 ? 'alias' : 'aliases') . '</span>';
            }
            if ($l['forwards']) {
                $chips .= '<span class="mb-chip fw" title="' . self::h(implode(', ', $l['forwards'])) . '">' . self::t('forwards to') . ' ' . self::h($l['forwards'][0]) . (count($l['forwards']) > 1 ? ' +' . (count($l['forwards']) - 1) : '') . '</span>';
            }
            if ($l['lists']) {
                $chips .= '<span class="mb-chip" title="' . self::h(implode(', ', $l['lists'])) . '">' . self::t('on') . ' ' . count($l['lists']) . ' ' . self::t(count($l['lists']) === 1 ? 'list' : 'lists') . '</span>';
            }
            $out .= '<div class="mb-row' . ($on ? '' : ' is-off') . '" data-s="' . self::h(strtolower($addr)) . '">'
                . '<span class="mb-avatar" aria-hidden="true">' . self::h(strtoupper(substr($addr, 0, 1))) . '</span>'
                . '<div class="mb-main"><div class="mb-addr"><b>' . self::h($at === false ? $addr : substr($addr, 0, $at)) . '</b><span class="mb-dom">' . self::h($at === false ? '' : substr($addr, $at)) . '</span>'
                . '<span class="mb-state ' . ($on ? 'on' : 'off') . '">' . self::t($on ? 'Receiving mail' : 'Switched off') . '</span></div>'
                . '<div class="mb-meta">' . (!empty($r['mb_created_ts']) ? '<span>' . self::t('Made') . ' ' . self::h(date('Y-m-d', (int) $r['mb_created_ts'])) . '</span>' : '') . $chips . '</div></div>'
                . '<div class="mb-act"><button class="btn btn-default btn-sm" type="submit" name="inEdit_' . (int) $r['mb_id_pk'] . '" value="inEdit_' . (int) $r['mb_id_pk'] . '">'
                . '<svg class="mb-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3"/></svg>' . self::t('Password and on/off') . '</button>'
                . '<button class="btn btn-default btn-sm mb-del" type="submit" name="inDelete_' . (int) $r['mb_id_pk'] . '" value="inDelete_' . (int) $r['mb_id_pk'] . '" title="' . self::t('Delete') . '" aria-label="' . self::t('Delete') . ' ' . self::h($addr) . '">'
                . '<svg class="mb-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></button></div>'
                . '</div>';
        }
        return $out;
    }

    /**
     * What deleting the mailbox does (<@ DeleteDetails @>): its mail goes, its forwarders go with it, and what still delivers to it.
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getDeleteDetails()
    {
        global $controller;
        $cur = self::ListCurrentMailboxes($controller->GetControllerRequest('URL', 'other'));
        if (!$cur) {
            return '';
        }
        $links = self::Links();
        $l = isset($links[strtolower($cur[0]['address'])]) ? $links[strtolower($cur[0]['address'])] : array('aliases' => array(), 'forwards' => array(), 'lists' => array());
        $out = '<ul class="mb-lose"><li>' . self::t('It stops receiving mail and can no longer be logged in to. The mail stored in it stays on the server until the administrator removes it.') . '</li>';
        if ($l['forwards']) {
            $out .= '<li>' . self::t('Its forwarders go with it:') . ' <b>' . self::h(implode(', ', $l['forwards'])) . '</b></li>';
        }
        if ($l['aliases']) {
            $out .= '<li class="mb-warnline">' . self::t('These aliases deliver to it and will stop working until you change or delete them in Aliases:') . ' <b>' . self::h(implode(', ', $l['aliases'])) . '</b></li>';
        }
        if ($l['lists']) {
            $out .= '<li class="mb-warnline">' . self::t('It is a member of these distribution lists, which will bounce for it:') . ' <b>' . self::h(implode(', ', $l['lists'])) . '</b></li>';
        }
        return $out . '</ul>';
    }

    /**
     * Whether the mailbox being changed receives mail (<@ EditOnChecked @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string ' checked' or ''.
     */
    static function getEditOnChecked()
    {
        global $controller;
        $cur = self::ListCurrentMailboxes($controller->GetControllerRequest('URL', 'other'));
        return ($cur && $cur[0]['ischeck']) ? ' checked' : '';
    }

    /**
     * The domain choices for a new mailbox, the one picked before selected (<@ DomainOptions @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The <option> HTML.
     */
    static function getDomainOptions()
    {
        global $controller;
        $picked = (string) $controller->GetControllerRequest('FORM', 'inDomain');
        $out = '';
        foreach (self::getDomainList() ?: array() as $d) {
            $out .= '<option value="' . self::h($d['domain']) . '"' . ($d['domain'] === $picked ? ' selected' : '') . '>@' . self::h($d['domain']) . '</option>';
        }
        return $out;
    }

    /**
     * The name typed when making a mailbox failed, so it is not lost (<@ PostedAddress @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The escaped text.
     */
    static function getPostedAddress()
    {
        global $controller;
        return self::$ok ? '' : self::h((string) $controller->GetControllerRequest('FORM', 'inAddress'));
    }

    /**
     * Template flag <% if WebmailOn %>: Webmail is switched on, for its link.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return bool
     */
    static function getWebmailOn()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_modules WHERE mo_folder_vc = 'webmail' AND mo_enabled_en = 'true'");
        $q->execute();
        return (int) $q->fetchColumn() > 0;
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
        if (!fs_director::CheckForEmptyValue(self::$baddomain)) {
            return ui_sysmessage::shout(ui_language::translate("Choose one of your own domains for the mailbox."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate('A mailbox, alias, forwarder or distribution list already has that address.'), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$validemail)) {
            return ui_sysmessage::shout(ui_language::translate("Your email address is not valid."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$password)) {
            return ui_sysmessage::shout(ui_language::translate("Your password cannot be blank."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$badpass)) {
            return ui_sysmessage::shout(ui_language::translate("The password needs a lower case letter, a capital and a digit."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$badpasswordlength)) {
            return ui_sysmessage::shout(ui_language::translate("The password is too short. Characters needed") . ": " . self::getMinPassLength(), "zannounceerror");
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
