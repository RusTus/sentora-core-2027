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
 * Manage Clients module: controller.
 *
 * Lets administrators and resellers list, create, edit, disable, delete and move
 * their client accounts, and reset a client's two-factor login.
 */
class module_controller extends ctrl_module
{

    /** @var mixed Not used. */
    static $complete;
    /** @var mixed Not used. */
    static $error;
    /** @var bool|null True when the user name is already taken. */
    static $alreadyexists;
    /** @var bool|null True when the user name has invalid characters. */
    static $badname;
    /** @var bool|null True when the password does not meet the character rules. */
	static $badpass;
    /** @var bool|null True when the email address is not valid. */
    static $bademail;
    /** @var bool|null True when the password is shorter than password_minlength. */
    static $badpasswordlength;
    /** @var bool|null True when no user name was given. */
    static $userblank;
    /** @var bool|null True when no email address was given. */
    static $emailblank;
    /** @var bool|null True when no password was given. */
    static $passwordblank;
    /** @var bool|null True when no (or an unknown) package was chosen. */
    static $packageblank;
    /** @var bool|null True when no (or an unknown) group was chosen. */
    static $groupblank;
    /** @var bool|null True when the last change was saved (shown by getResult()). */
    static $ok;
    /** @var mixed Not used. */
    static $edit;
    /** @var mixed Not used. */
    static $clientid;
    /** @var mixed Not used. */
    static $clientpkgid;
    /** @var bool|null True after a client was created, so the create form is shown empty. */
    static $resetform;
    /** @var bool|null True when the email address is already used by another account. */
    static $not_unique_email;
    /** @var string|null Reset two-factor: the client's name (null when nothing was reset). */
    static $tfreset = null; // Reset two-factor: the client's name
    /** @var string|null The account already using the address, if the viewer may know it ('' when not). */
    static $email_owner; // the account already using the address, if the viewer may know it

    /**
     * List the enabled client accounts of a reseller (start of the 'worker' methods).
     *
     * The 'zadmin' account is left out. Each row is the client's ctrl_users::GetUserDetail()
     * plus 'created', 'diskspacereadable', 'diskspacequotareadable', 'bandwidthreadable',
     * 'bandwidthquotareadable', 'numclients' and 'tfreset' (reset button HTML or '').
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid Optional. The reseller's account ID; 0 lists every enabled account. Default 0.
     * @return array<int, array<string, mixed>>|false One row per client; false when there are none.
     */
    static function ListClients($uid = 0)
    {
        global $zdbh;
        if ($uid == 0) {
            $sql = "SELECT * FROM x_accounts WHERE ac_enabled_in=1 AND ac_deleted_ts IS NULL ORDER BY ac_user_vc";
            $numrows = $zdbh->prepare($sql);
            $numrows->execute();
        } else {
            $sql = "SELECT * FROM x_accounts WHERE ac_reseller_fk=:uid AND ac_enabled_in=1 AND ac_deleted_ts IS NULL ORDER BY ac_user_vc";
            $numrows = $zdbh->prepare($sql);
            $numrows->bindParam(':uid', $uid);
            $numrows->execute();
        }

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            if ($uid == 0) {
                //do not bind as there is no need
            } else {
                //else we bind the pram to the sql statment
                $sql->bindParam(':uid', $uid);
            }
            $res = array();
            $sql->execute();
            while ($rowclients = $sql->fetch()) {
                if ($rowclients['ac_user_vc'] != "zadmin") {
                    //$numrowclients = $zdbh->query("SELECT COUNT(*) FROM x_accounts WHERE ac_reseller_fk=" . $rowclients['ac_id_pk'] . " AND ac_deleted_ts IS NULL")->fetch();
                    $numrows = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_reseller_fk=:ac_id_pk AND ac_deleted_ts IS NULL");
                    $numrows->bindParam(':ac_id_pk', $rowclients['ac_id_pk']);
                    $numrows->execute();
                    $numrowclients = $numrows->fetch();

                    $currentuser = ctrl_users::GetUserDetail($rowclients['ac_id_pk']);
                    $currentuser['created'] = date("m/d/Y", $rowclients['ac_created_ts']);
                    $currentuser['diskspacereadable'] = fs_director::ShowHumanFileSize(ctrl_users::GetQuotaUsages('diskspace', $currentuser['userid']));
                    $currentuser['diskspacequotareadable'] = fs_director::ShowHumanFileSize($currentuser['diskquota']);
                    $currentuser['bandwidthreadable'] = fs_director::ShowHumanFileSize(ctrl_users::GetQuotaUsages('bandwidth', $currentuser['userid']));
                    $currentuser['bandwidthquotareadable'] = fs_director::ShowHumanFileSize($currentuser['bandwidthquota']);
                    $currentuser['numclients'] = $numrowclients[0];
                    // Reset two-factor (lost phone and codes): only when the client has it
                    $tfRow = ctrl_twofactor::Row($currentuser['userid']);
                    $currentuser['tfreset'] = ($tfRow && (int) $tfRow['tf_enabled_in'] === 1)
                        ? '<button class="btn btn-warning" type="submit" name="inResetTwoFactor_' . (int) $currentuser['userid'] . '" value="1" onclick="return confirm(\''
                            . htmlspecialchars(ui_language::translate('Reset the two-factor login of this client? Do this only after checking who is asking (for example by phone).'), ENT_QUOTES, 'UTF-8')
                            . '\');">' . ui_language::translate('Reset two-factor') . '</button>'
                        : '';
                    array_push($res, $currentuser);
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the reseller's clients that a deleted client's accounts can be moved to.
     *
     * Only clients in the Administrators or Resellers group, other than $moveid.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $moveid The ID of the client being deleted (left out of the list).
     * @param int|string $uid The reseller's account ID.
     * @return array<int, array<string, mixed>>|false One row per client: 'moveclientid', 'moveclientname'; false when the reseller has no clients.
     */
    static function ListAllClients($moveid, $uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_accounts WHERE ac_reseller_fk=:uid AND ac_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $skipclients = array();
            $sql->execute();
            while ($rowclients = $sql->fetch()) {
                //$getgroup = $zdbh->query("SELECT * FROM x_groups WHERE ug_id_pk=" . $rowclients['ac_group_fk'] . "")->fetch();
                $numrows = $zdbh->prepare("SELECT * FROM x_groups WHERE ug_id_pk=:ac_group_fk");
                $numrows->bindParam(':ac_group_fk', $rowclients['ac_group_fk']);
                $numrows->execute();
                $getgroup = $numrows->fetch();
                if ($rowclients['ac_id_pk'] != $moveid && $getgroup['ug_name_vc'] == "Administrators" ||
                        $rowclients['ac_id_pk'] != $moveid && $getgroup['ug_name_vc'] == "Resellers") {
                    array_push($res, array('moveclientid' => $rowclients['ac_id_pk'],
                        'moveclientname' => $rowclients['ac_user_vc']));
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the disabled client accounts of a reseller.
     *
     * Each row is the client's ctrl_users::GetUserDetail() plus the readable disk and
     * bandwidth usage and quota fields.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The reseller's account ID.
     * @return array<int, array<string, mixed>>|false One row per client; false when there are none.
     */
    static function ListDisabledClients($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_accounts WHERE ac_reseller_fk=:uid AND ac_enabled_in=0 AND ac_deleted_ts IS NULL";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            while ($rowclients = $sql->fetch()) {
                if ($rowclients['ac_user_vc'] != "zadmin") {
                    $currentuser = ctrl_users::GetUserDetail($rowclients['ac_id_pk']);
                    $currentuser['diskspacereadable'] = fs_director::ShowHumanFileSize(ctrl_users::GetQuotaUsages('diskspace', $currentuser['userid']));
                    $currentuser['diskspacequotareadable'] = fs_director::ShowHumanFileSize($currentuser['diskquota']);
                    $currentuser['bandwidthreadable'] = fs_director::ShowHumanFileSize(ctrl_users::GetQuotaUsages('bandwidth', $currentuser['userid']));
                    $currentuser['bandwidthquotareadable'] = fs_director::ShowHumanFileSize($currentuser['bandwidthquota']);
                    array_push($res, $currentuser);
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Get the profile of one client account (cleaned for HTML output).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|null $uid The client's account ID.
     * @return array<int, array<string, mixed>>|false A single row: 'fullname', 'username', 'userid', 'postcode', 'address', 'phone', 'email'; false when there is no profile.
     */
    static function ListCurrentClient($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_profiles WHERE ud_user_fk=:uid";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            $currentuser = ctrl_users::GetUserDetail($uid);
            while ($rowclients = $sql->fetch()) {
                array_push($res, array('fullname' => runtime_xss::xssClean(strip_tags($rowclients['ud_fullname_vc'])),
                    'username' => runtime_xss::xssClean(strip_tags($currentuser['username'])),
                    'userid' => runtime_xss::xssClean(strip_tags($currentuser['userid'])),
                    'fullname' => runtime_xss::xssClean(strip_tags($rowclients['ud_fullname_vc'])),
                    'postcode' => runtime_xss::xssClean(strip_tags($rowclients['ud_postcode_vc'])),
                    'address' => runtime_xss::xssClean(strip_tags($rowclients['ud_address_tx'])),
                    'phone' => runtime_xss::xssClean(strip_tags($rowclients['ud_phone_vc'])),
                    'email' => runtime_xss::xssClean(strip_tags($currentuser['email']))));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the user groups an account may give its clients.
     *
     * Administrators get every group of their reseller (with 'groupselected'), others
     * only the "Users" group.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return array<int, array<string, mixed>>|false One row per group: 'groupid', 'groupname', 'groupselected'; false when there are none.
     */
    static function ListGroups($uid)
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $sql = "SELECT * FROM x_groups WHERE ug_reseller_fk=:resellerid";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':resellerid', $currentuser['resellerid']);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':resellerid', $currentuser['resellerid']);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                if (strtoupper($currentuser['usergroup']) == "ADMINISTRATORS") {
                    $selected = "";
                    if ($rowgroups['ug_id_pk'] == $currentuser['usergroupid']) {
                        $selected = " selected";
                    }
                    array_push($res, array('groupid' => $rowgroups['ug_id_pk'],
                        'groupname' => runtime_xss::xssClean(ui_language::translate($rowgroups['ug_name_vc'])),
                        'groupselected' => $selected));
                } else {
                    if (strtoupper($rowgroups['ug_name_vc']) == "USERS") {
                        array_push($res, array('groupid' => $rowgroups['ug_id_pk'],
                            'groupname' => runtime_xss::xssClean(ui_language::translate($rowgroups['ug_name_vc'])),
                            'groupselected' => ''));
                    }
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the groups for the edit form, with the client's current group selected.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|false $uid The client's account ID (from ?other=).
     * @param int|string $rid The reseller ID whose groups are listed.
     * @param int|string $id The account ID of the viewer (decides whether all groups are shown).
     * @return array<int, array<string, mixed>>|false One row per group: 'groupid', 'groupname', 'groupselected'; false when there are none.
     */
    static function ListCurrentGroups($uid, $rid, $id)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_groups WHERE ug_reseller_fk=:rid";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':rid', $rid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $currentuser = ctrl_users::GetUserDetail($uid);
            $reseller = ctrl_users::GetUserDetail($id);
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':rid', $rid);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                if (strtoupper($reseller['usergroup']) == "ADMINISTRATORS") {
                    $selected = "";
                    if ($rowgroups['ug_id_pk'] == $currentuser['usergroupid']) {
                        $selected = " selected";
                    }
                    array_push($res, array('groupid' => $rowgroups['ug_id_pk'],
                        'groupname' => htmlspecialchars(ui_language::translate($rowgroups['ug_name_vc']), ENT_QUOTES, 'UTF-8'),
                        'groupselected' => $selected));
                } else {
                    if (strtoupper($rowgroups['ug_name_vc']) == "USERS") {
                        $selected = "";
                        if ($rowgroups['ug_id_pk'] == $currentuser['usergroupid']) {
                            $selected = " selected";
                        }
                        array_push($res, array('groupid' => $rowgroups['ug_id_pk'],
                            'groupname' => htmlspecialchars(ui_language::translate($rowgroups['ug_name_vc']), ENT_QUOTES, 'UTF-8'),
                            'groupselected' => $selected));
                    }
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the packages of a reseller.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The reseller's account ID.
     * @return array<int, array<string, mixed>>|false One row per package: 'packageid', 'packagename'; false when there are none.
     */
    static function ListPackages($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_packages WHERE pk_reseller_fk=:uid AND pk_deleted_ts IS NULL";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                array_push($res, array('packageid' => $rowgroups['pk_id_pk'],
                    'packagename' => ui_language::translate($rowgroups['pk_name_vc'])));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the reseller's packages for the edit form, with the client's package selected.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|false $uid The client's account ID (from ?other=).
     * @param int|string $rid The reseller's account ID.
     * @return array<int, array<string, mixed>>|false One row per package: 'packageid', 'packagename', 'packageselected'; false when there are none.
     */
    static function ListCurrentPackages($uid, $rid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_packages WHERE pk_reseller_fk=:rid AND pk_deleted_ts IS NULL";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':rid', $rid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $currentuser = ctrl_users::GetUserDetail($uid);
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':rid', $rid);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                $selected = "";
                if ($rowgroups['pk_id_pk'] == $currentuser['packageid']) {
                    $selected = " selected";
                }
                array_push($res, array('packageid' => $rowgroups['pk_id_pk'],
                    'packagename' => ui_language::translate($rowgroups['pk_name_vc']),
                    'packageselected' => $selected));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Set one column of a client's x_accounts row.
     *
     * Not called anywhere. The column name is bound as an SQL parameter, which does not work.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $userid The client's account ID.
     * @param string $column The column name.
     * @param mixed $value The new value.
     * @return true Always true.
     */
    static function SetClientAccount($userid, $column, $value)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeSetClientAccount');
        $sql = $zdbh->prepare("UPDATE x_accounts
								SET :column=:value
								WHERE ac_id_pk=:userid");
        $sql->bindParam(':column', $column);
        $sql->bindParam(':value', $value);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        runtime_hook::Execute('OnAfterSetClientAccount');
        return true;
    }

    /**
     * Set one column of a client's x_profiles row.
     *
     * Not called anywhere. The column name is bound as an SQL parameter, which does not work.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $userid The client's account ID.
     * @param string $column The column name.
     * @param mixed $value The new value.
     * @return true Always true.
     */
    static function SetClientProfile($userid, $column, $value)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeSetClientProfile');
        $sql = $zdbh->prepare("UPDATE x_profiles SET :column=:value WHERE ud_user_fk=:userid");
        $sql->bindParam(':column', $column);
        $sql->bindParam(':value', $value);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        runtime_hook::Execute('OnAfterSetClientProfile');
        return true;
    }

    /**
     * Delete a client and move its own clients, packages and groups to another account.
     *
     * Marks the account deleted (the OnAfterDeleteClient hooks then clean up its
     * mailboxes, databases and so on). Sets self::$ok.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $userid The client's account ID.
     * @param int|string $moveid The account that takes over the client's clients, packages and groups.
     * @return true Always true.
     */
    static function ExecuteDeleteClient($userid, $moveid)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeDeleteClient');
        $sql = $zdbh->prepare("
			UPDATE x_accounts
			SET ac_deleted_ts=:time
			WHERE ac_id_pk=:userid");
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        $sql = $zdbh->prepare("
			UPDATE x_accounts
			SET ac_reseller_fk = :moveid
			WHERE ac_reseller_fk = :userid");
        $sql->bindParam(':moveid', $moveid);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        $sql = $zdbh->prepare("
			UPDATE x_packages
			SET pk_reseller_fk = :moveid
			WHERE pk_reseller_fk = :userid");
        $sql->bindParam(':moveid', $moveid);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        $sql = $zdbh->prepare("
			UPDATE x_groups
			SET ug_reseller_fk = :moveid
			WHERE ug_reseller_fk = :userid");
        $sql->bindParam(':moveid', $moveid);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        runtime_hook::Execute('OnAfterDeleteClient');
        self::$ok = true;
        return true;
    }

    /**
     * Save the edit form for a client.
     *
     * Optionally sets a new password, updates the account and profile and enables or
     * disables the account. Sets self::$ok on success.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $clientid The client's account ID.
     * @param int|string $package The package ID.
     * @param int|string $enabled 1 enabled, 0 disabled.
     * @param int|string $group The group ID.
     * @param string $fullname Full name.
     * @param string $email Email address.
     * @param string $address Postal address.
     * @param string $post Postcode.
     * @param string $phone Phone number.
     * @param string $newpass New password, or '' to keep the current one.
     * @return bool True when saved, false when the new password is not valid.
     */
    static function ExecuteUpdateClient($clientid, $package, $enabled, $group, $fullname, $email, $address, $post, $phone, $newpass)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeUpdateClient');
        if ($newpass != "") {
            // Check for password length...
            if (strlen($newpass) < ctrl_options::GetSystemOption('password_minlength')) {
                self::$badpassword = true;
                return false;
            }
			// Check for invalid password
        	if (!self::IsValidPassword($newpass)) {
            	self::$badpass = true;
            	return false;
        	}
            $crypto = new runtime_hash;
            $crypto->SetPassword($newpass);
            $randomsalt = $crypto->RandomSalt();
            $crypto->SetSalt($randomsalt);
            $secure_password = $crypto->CryptParts($crypto->Crypt())->Hash;

            $sql = $zdbh->prepare("UPDATE x_accounts SET ac_pass_vc= :newpass, ac_passsalt_vc= :passsalt WHERE ac_id_pk= :clientid");
            $sql->bindParam(':clientid', $clientid);
            $sql->bindParam(':newpass', $secure_password);
            $sql->bindParam(':passsalt', $randomsalt);
            $sql->execute();
        }
        $sql = $zdbh->prepare("UPDATE x_accounts SET ac_email_vc= :email, ac_package_fk= :package, ac_enabled_in= :isenabled, ac_group_fk= :group WHERE ac_id_pk = :clientid");
        $sql->bindParam(':email', $email);
        $sql->bindParam(':package', $package);
        $sql->bindParam(':isenabled', $enabled);
        $sql->bindParam(':group', $group);
        $sql->bindParam(':clientid', $clientid);
        //$sql->bindParam(':accountid', $clientid);
        $sql->execute();

        $sql = $zdbh->prepare("UPDATE x_profiles SET ud_fullname_vc= :fullname, ud_group_fk= :group, ud_package_fk= :package, ud_address_tx= :address,ud_postcode_vc= :postcode, ud_phone_vc= :phone WHERE ud_user_fk=:accountid");
        $sql->bindParam(':fullname', $fullname);
        $sql->bindParam(':group', $group);
        $sql->bindParam(':package', $package);
        $sql->bindParam(':address', $address);
        $sql->bindParam(':postcode', $post);
        $sql->bindParam(':phone', $phone);
        $sql->bindParam(':accountid', $clientid);
        $sql->execute();
        if ($enabled == 0) {
            self::DisableClient($clientid);
        }
        if ($enabled == 1) {
            self::EnableClient($clientid);
        }
        runtime_hook::Execute('OnAfterUpdateClient');
        self::$ok = true;
        return true;
    }

    /**
     * Enable a client account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $userid The client's account ID.
     * @return true Always true.
     */
    static function EnableClient($userid)
    {
        runtime_hook::Execute('OnBeforeEnableClient');
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_accounts SET ac_enabled_in=1 WHERE ac_id_pk=:userid");
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        runtime_hook::Execute('OnAfterEnableClient');
        return true;
    }

    /**
     * Disable a client account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $userid The client's account ID.
     * @return true Always true.
     */
    static function DisableClient($userid)
    {
        runtime_hook::Execute('OnBeforeDisableClient');
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_accounts SET ac_enabled_in=0 WHERE ac_id_pk=:userid");
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        runtime_hook::Execute('OnAfterDisableClient');
        return true;
    }

    /**
     * Build the checked attributes of the enabled/disabled radio buttons.
     *
     * @param int|string|false $userid The client's account ID.
     * @return array<int, array<string, string>> One row: 'echecked', 'dchecked' ("CHECKED" or '').
     */
    static function CheckEnabledHTML($userid)
    {
        $currentuser = ctrl_users::GetUserDetail($userid);
        $res = array();
        if ($currentuser['enabled'] == 1) {
            $echecked = "CHECKED";
            $dchecked = "";
        } else {
            $echecked = "";
            $dchecked = "CHECKED";
        }
        array_push($res, array('echecked' => $echecked,
            'dchecked' => $dchecked));
        return $res;
    }

    /**
     * Check whether a reseller has at least one package.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $userid The reseller's account ID.
     * @return bool True when it has a package (or the query failed).
     */
    static function CheckHasPackage($userid)
    {
        global $zdbh;
        $sql = "SELECT COUNT(*) FROM x_packages WHERE pk_reseller_fk=:userid AND pk_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $userid);

        if ($numrows->execute()) {
            if ($numrows->fetchColumn() == 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Create a client account.
     *
     * Validates the input, inserts the account, profile and bandwidth rows and, when
     * asked, emails the welcome message. The daemon creates the account's folders.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The reseller's account ID.
     * @param string $username The new user name.
     * @param int|string $packageid The package ID.
     * @param int|string $groupid The group ID.
     * @param string $fullname Full name.
     * @param string $email Email address.
     * @param string $address Postal address.
     * @param string $post Postcode.
     * @param string $phone Phone number.
     * @param string $password The password.
     * @param int|string $sendemail Non-zero to send the welcome email.
     * @param string $emailsubject Welcome email subject ({{username}}, {{password}}, {{fullname}} are replaced).
     * @param string $emailbody Welcome email body (also {{controlpanelurl}}).
     * @return bool True when created, false when validation failed.
     */
    static function ExecuteCreateClient($uid, $username, $packageid, $groupid, $fullname, $email, $address, $post, $phone, $password, $sendemail, $emailsubject, $emailbody)
    {
        global $zdbh;
        // Check for spaces and remove if found...
        $username = strtolower(str_replace(' ', '', $username));
        $reseller = ctrl_users::GetUserDetail($uid);
        // Check for errors before we continue...
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrors($username, $packageid, $groupid, $email, $password))) {
            return false;
        }
        runtime_hook::Execute('OnBeforeCreateClient');

        $crypto = new runtime_hash;
        $crypto->SetPassword($password);
        $randomsalt = $crypto->RandomSalt();
        $crypto->SetSalt($randomsalt);
        $secure_password = $crypto->CryptParts($crypto->Crypt())->Hash;

        // No errors found, so we can add the user to the database...
        $sql = $zdbh->prepare("INSERT INTO x_accounts (ac_user_vc, ac_pass_vc, ac_passsalt_vc, ac_email_vc, ac_package_fk, ac_group_fk, ac_usertheme_vc, ac_usercss_vc, ac_reseller_fk, ac_created_ts) VALUES (
            :username, :password, :passsalt, :email, :packageid, :groupid, :resellertheme, :resellercss, :uid, :time)");
        $sql->bindParam(':uid', $uid);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':username', $username);
        $sql->bindParam(':password', $secure_password);
        $sql->bindParam(':passsalt', $randomsalt);
        $sql->bindParam(':email', $email);
        $sql->bindParam(':packageid', $packageid);
        $sql->bindParam(':groupid', $groupid);
        $sql->bindParam(':resellertheme', $reseller['usertheme']);
        $sql->bindParam(':resellercss', $reseller['usercss']);
        $sql->execute();
        // Now lets pull back the client ID so that we can add their personal address details etc...
        //$client = $zdbh->query("SELECT * FROM x_accounts WHERE ac_reseller_fk=" . $uid . " ORDER BY ac_id_pk DESC")->Fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_accounts WHERE ac_reseller_fk=:uid ORDER BY ac_id_pk DESC");
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        $client = $numrows->fetch();

        $sql = $zdbh->prepare("INSERT INTO x_profiles (ud_user_fk, ud_fullname_vc, ud_group_fk, ud_package_fk, ud_address_tx, ud_postcode_vc, ud_phone_vc, ud_created_ts) VALUES (:userid, :fullname, :packageid, :groupid, :address, :postcode, :phone, :time)");
        $sql->bindParam(':userid', $client['ac_id_pk']);
        $sql->bindParam(':fullname', $fullname);
        $sql->bindParam(':packageid', $packageid);
        $sql->bindParam(':groupid', $groupid);
        $sql->bindParam(':address', $address);
        $sql->bindParam(':postcode', $post);
        $sql->bindParam(':phone', $phone);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        // Now we add an entry into the bandwidth table, for the user for the upcoming month.
        $sql = $zdbh->prepare("INSERT INTO x_bandwidth (bd_acc_fk, bd_month_in, bd_transamount_bi, bd_diskamount_bi) VALUES (:ac_id_pk, :date, 0, 0)");
        $date = date("Ym", time());
        $sql->bindParam(':date', $date);
        $sql->bindParam(':ac_id_pk', $client['ac_id_pk']);
        $sql->execute();
        // Lets create the client diectories
        // The account's folders are made by the daemon (root) within 5 minutes, with
        // the account's own Linux user as owner (sys_accountphp::EnsureUser): the
        // panel can not write to the hosting folder's parent
        // Send the user account details via. email (if requested)...
        if ($sendemail <> 0) {
            if (isset($_SERVER['HTTPS'])) {
                $protocol = 'https://';
            } else {
                $protocol = 'http://';
            }
            $port = ctrl_options::GetSystemOption('sentora_port');
            $domain = ctrl_options::GetSystemOption('sentora_domain');
            // If using non-standard port
            if ($port !== "80" && $port !== "443" && !empty($port)) {
                // Append port to domain
                $domain .= ":" . $port;
            }
            $emailsubject = str_replace("{{username}}", $username, $emailsubject);
            $emailsubject = str_replace("{{password}}", $password, $emailsubject);
            $emailsubject = str_replace("{{fullname}}", $fullname, $emailsubject);
            $emailbody = str_replace("{{username}}", $username, $emailbody);
            $emailbody = str_replace("{{password}}", $password, $emailbody);
            $emailbody = str_replace("{{fullname}}", $fullname, $emailbody);
            $emailbody = str_replace('{{controlpanelurl}}', $protocol . $domain, $emailbody);

            $phpmailer = new sys_email();
            $phpmailer->Subject = $emailsubject;
            $phpmailer->Body = $emailbody;
            $phpmailer->AddAddress($email);
            $phpmailer->SendEmail();
        }
        runtime_hook::Execute('OnAfterCreateClient');
        self::$resetform = true;
        self::$ok = true;
        return true;
    }

    /**
     * Which account already uses an email address, as far as the viewer may know:
     * administrators see any account; a reseller only their own clients (otherwise
     * an empty string, so other resellers' clients can not be found by trying addresses)
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $email The email address.
     * @return string The account's user name, or ''.
     */
    static function EmailOwnerFor($email)
    {
        global $zdbh;
        $me = ctrl_users::GetUserDetail();
        $sql = $zdbh->prepare("SELECT ac_user_vc, ac_reseller_fk FROM x_accounts WHERE ac_email_vc = :email AND ac_deleted_ts IS NULL LIMIT 1");
        $sql->execute(array(':email' => $email));
        $row = $sql->fetch();
        if (!$row) {
            return '';
        }
        if ((int) $me['usergroupid'] === 1 || (int) $row['ac_reseller_fk'] === (int) $me['userid']) {
            return (string) $row['ac_user_vc'];
        }
        return '';
    }

    /**
     * Validate a new client.
     *
     * Sets the matching error flag (self::$userblank, self::$alreadyexists, ...) on failure.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $username The user name.
     * @param int|string $packageid The package ID.
     * @param int|string $groupid The group ID.
     * @param string $email The email address.
     * @param string $password The password.
     * @return bool|null True when the client can be created; false (or null for an unknown group) when not.
     */
    static function CheckCreateForErrors($username, $packageid, $groupid, $email, $password)
    {
        global $zdbh;
        $username = strtolower(str_replace(' ', '', $username));
		// Check to make sure the username is not blank or exists before we go any further...
        if (!fs_director::CheckForEmptyValue($username)) {
            $sql = "SELECT COUNT(*) FROM x_accounts WHERE UPPER(ac_user_vc)=:user AND ac_deleted_ts IS NULL";
            $numrows = $zdbh->prepare($sql);
            $user = strtoupper($username);
            $numrows->bindParam(':user', $user);
            if ($numrows->execute()) {
                if ($numrows->fetchColumn() <> 0) {
                    self::$alreadyexists = true;
                    return false;
                }
            }
		// Check to make sure the password is not blank before we go any further...
        if ($password == '') {
            self::$passwordblank = TRUE;
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
		if (!self::IsValidUserName($username)) {
			self::$badname = true;
			return false;
		}

        } else {
            self::$userblank = true;
            return false;
        }
        // Check to make sure the packagename is not blank and exists before we go any further...
        if (!fs_director::CheckForEmptyValue($packageid)) {
            $sql = "SELECT COUNT(*) FROM x_packages WHERE pk_id_pk=:packageid AND pk_deleted_ts IS NULL";
            $numrows = $zdbh->prepare($sql);
            $numrows->bindParam(':packageid', $packageid);
            if ($numrows->execute()) {
                if ($numrows->fetchColumn() == 0) {
                    self::$packageblank = true;
                    return false;
                }
            }
        } else {
            self::$packageblank = true;
            return false;
        }
        // Check to make sure the groupname is not blank and exists before we go any further...
        if (!fs_director::CheckForEmptyValue($groupid)) {
            $sql = "SELECT COUNT(*) FROM x_groups WHERE ug_id_pk=:groupid";
            $numrows = $zdbh->prepare($sql);
            $numrows->bindParam(':groupid', $groupid);

            if ($numrows->execute()) {
                if ($numrows->fetchColumn() == 0) {
                    self::$groupblank = true;
                    return;
                }
            }
        } else {
            self::$groupblank = true;
            return false;
        }
        // Check for invalid characters in the email and that it exists...
        if (!fs_director::CheckForEmptyValue($email)) {
            if (!self::IsValidEmail($email)) {
                self::$bademail = true;
                return false;
            }
        } else {
            self::$emailblank = true;
            return false;
        }

        // Check that the email address is unique to the user's table
        if (!fs_director::CheckForEmptyValue($email)) {
            if (ctrl_users::CheckUserEmailIsUnique($email)) {
                self::$not_unique_email = false;
                return true;
            } else {
                self::$not_unique_email = true;
                self::$email_owner = self::EmailOwnerFor($email);
                return false;
            }
        } else {
            self::$not_unique_email = true;
            return false;
        }
		
		/*
        // Check for password length...
        if (!fs_director::CheckForEmptyValue($password)) {
            if (strlen($password) < ctrl_options::GetSystemOption('password_minlength')) {
                self::$badpassword = true;
                return false;
            }
        } else {
            self::$passwordblank = true;
            return false;
        }
		*/
        return true;
    }

	/**
	 * Check the password rules: a digit, a lower and an upper case letter (or ends in '-').
	 *
	 * @param string $password The password.
	 * @return bool True when the password is acceptable.
	 */
	static function IsValidPassword($password)
    {
        //return preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{6,}/', $password) || preg_match('/-$/', $password) == 1;
		return preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z])/', $password) || preg_match('/-$/', $password) == 1;
    }
	
	/**
	 * Check an email address with PHP's email filter.
	 *
	 * @param string $email The email address.
	 * @return bool True when valid.
	 */
	static function IsValidEmail($email)
	{
		if (!filter_var($email, FILTER_SANITIZE_EMAIL))
				return false;
		if (!filter_var($email, FILTER_VALIDATE_EMAIL))
				return false;
		return true;
	}

    /**
     * Check a user name: letters, digits and '-', 1-63 characters, not ending in '-'.
     *
     * @param string $username The user name.
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
     * Read the welcome email template (setting 'welcome_message').
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string|false The template, or false when the setting does not exist.
     */
    static function DefaultEmailBody()
    {
        global $zdbh;
	$sql = "SELECT so_value_tx FROM x_settings WHERE so_name_vc = :configName";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindValue(':configName', 'welcome_message');
        $numrows->execute();

        $result = $numrows->fetch();
        
        if ($result) {
            return ($result['so_value_tx']);
        } else {
            return false;
        }
    }

    /**
     * Checks if the user already exists in the x_accounts table.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string|null $username The username to check against.
     * @return bool True when it exists (or the query failed).
     */
    static function CheckUserExists($username)
    {
        global $zdbh;
        $sql = "SELECT COUNT(*) FROM x_accounts WHERE LOWER(ac_user_vc)=:username";
        $uniqueuser = $zdbh->prepare($sql);
        $uniqueuser->bindValue(':username', strtolower($username));
        if ($uniqueuser->execute()) {
            if ($uniqueuser->fetchColumn() > 0) {
                return true;
            } else {
                return false;
            }
        } else {
            return true;
        }
    }

    /**
     * Check if the Shadowing module is enabled/exists.
     *
     * Template flag <% if ShadowEnabled %>.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return bool True when it is enabled (or the query failed).
     */
    static function getShadowEnabled()
    {
        global $zdbh;
        $sql = "SELECT COUNT(*) FROM x_modules WHERE mo_folder_vc='shadowing' AND mo_enabled_en='true'";
        $shadowEnabled = $zdbh->prepare($sql);
        if ($shadowEnabled->execute()) {
            if ($shadowEnabled->fetchColumn() > 0) {
                return true;
            } else {
                return false;
            }
        } else {
            return true;
        }
    }

    // End 'worker' methods.

    /**
     * Handle the create client form (start of the web interface methods).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when the client was created.
     */
    static function doCreateClient()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // A reseller may only use its own packages and the groups it is offered; only an
        // administrator may create administrators (any reseller could, by sending group 1)
        if (!isset($formvars['inNewPackage'], $formvars['inNewGroup']) || !ctrl_ownership::Owns('x_packages', 'pk_id_pk', 'pk_reseller_fk', $formvars['inNewPackage'], ' AND pk_deleted_ts IS NULL') || !self::AllowedGroup($formvars['inNewGroup'])) {
            return false;
        }
        if (isset($formvars['inSWE'])) {
            $sendemail = $formvars['inSWE'];
        } else {
            $sendemail = 0;
        }
        if (self::ExecuteCreateClient($currentuser['userid'], $formvars['inNewUserName'], $formvars['inNewPackage'], $formvars['inNewGroup'], $formvars['inNewFullName'], $formvars['inNewEmailAddress'], $formvars['inNewAddress'], $formvars['inNewPostCode'], $formvars['inNewPhone'], $formvars['inNewPassword'], $sendemail, ($formvars['inEmailSubject'] ?? ''), ($formvars['inEmailBody'] ?? ''))) {
            unset($_POST['inNewUserName']);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Handle the client list buttons: Edit, Delete (redirect and exit) or Reset two-factor.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doEditClient()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListClients($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['userid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['userid'] . "");
                exit;
            }
            if (isset($formvars['inEdit_' . $row['userid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $row['userid'] . "");
                exit;
            }
            // one of this administrator's or reseller's own clients (the list has only those)
            if (isset($formvars['inResetTwoFactor_' . $row['userid']])) {
                ctrl_twofactor::ResetBy($row['userid'], $currentuser['username']);
                self::$tfreset = $row['username'];
                return;
            }
        }
        return;
    }

    /**
     * Handle the disabled client list buttons: Edit or Delete (redirect and exit).
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doEditDisabledClient()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListDisabledClients($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['userid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['userid'] . "");
                exit;
            }
            if (isset($formvars['inEdit_' . $row['userid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $row['userid'] . "");
                exit;
            }
        }
        return;
    }

    /**
     * Handle the delete confirmation form.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when deleted; false when the client or move target is not allowed.
     */
    static function doDeleteClient()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this reseller's own clients, moved to another of its own clients
        if (!isset($formvars['inDelete']) || !ctrl_ownership::Owns('x_accounts', 'ac_id_pk', 'ac_reseller_fk', $formvars['inDelete'], ' AND ac_deleted_ts IS NULL')
            || (!empty($formvars['inMoveClient']) && (string) $formvars['inMoveClient'] !== (string) ctrl_users::GetUserDetail()['userid'] && !ctrl_ownership::Owns('x_accounts', 'ac_id_pk', 'ac_reseller_fk', $formvars['inMoveClient'], ' AND ac_deleted_ts IS NULL'))) {
            return false;
        }
        if (self::ExecuteDeleteClient($formvars['inDelete'], $formvars['inMoveClient']))
            return true;
        return false;
    }

    /**
     * Handle the edit client form.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when saved.
     */
    static function doUpdateClient()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this reseller's own clients, packages and groups
        if (!isset($formvars['inClientID'], $formvars['inPackage'], $formvars['inGroup']) || !ctrl_ownership::Owns('x_accounts', 'ac_id_pk', 'ac_reseller_fk', $formvars['inClientID'], ' AND ac_deleted_ts IS NULL') || !ctrl_ownership::Owns('x_packages', 'pk_id_pk', 'pk_reseller_fk', $formvars['inPackage'], ' AND pk_deleted_ts IS NULL') || !self::AllowedGroup($formvars['inGroup'])) {
            return false;
        }
        if (self::ExecuteUpdateClient($formvars['inClientID'], $formvars['inPackage'], $formvars['inEnabled'], $formvars['inGroup'], $formvars['inFullName'], $formvars['inEmailAddress'], $formvars['inAddress'], $formvars['inPostCode'], $formvars['inPhone'], $formvars['inNewPassword']))
            return true;
        return false;
    }

    /**
     * Template loop <% loop ClientList %>: the logged-in reseller's enabled clients.
     *
     * @return array<int, array<string, mixed>>|false See ListClients().
     */
    static function getClientList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $clientlist = self::ListClients($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($clientlist)) {
            return $clientlist;
        } else {
            return false;
        }
    }

    /**
     * Template loop <% loop AllClientList %>: accounts the client in ?other= can be moved to.
     *
     * @global runtime_controller $controller The request controller.
     * @return array<int, array<string, mixed>>|false See ListAllClients().
     */
    static function getAllClientList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $urlvars = $controller->GetAllControllerRequests('URL');
        $clientlist = self::ListAllClients($urlvars['other'], $currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($clientlist)) {
            return $clientlist;
        } else {
            return false;
        }
    }

    /**
     * Template loop <% loop DisabledClientList %>: the logged-in reseller's disabled clients.
     *
     * @return array<int, array<string, mixed>>|false See ListDisabledClients().
     */
    static function getDisabledClientList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $disabledclientlist = self::ListDisabledClients($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($disabledclientlist)) {
            return $disabledclientlist;
        } else {
            return false;
        }
    }

    /**
     * The profile of the client in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return array<int, array<string, mixed>>|false See ListCurrentClient().
     */
    static function getCurrentClient()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        $client = self::ListCurrentClient($urlvars['other']);
        if (!fs_director::CheckForEmptyValue($client)) {
            return $client;
        } else {
            return false;
        }
    }

    /**
     * A group this account may give a client: one of the groups it is offered
     * (owned by its reseller), and never Administrators unless it is an administrator.
     *
     * @param mixed $gid The group ID from the form.
     * @return bool True when the group may be used.
     */
    static function AllowedGroup($gid)
    {
        $currentuser = ctrl_users::GetUserDetail();
        if (!preg_match('/^[0-9]{1,10}$/', (string) $gid)) {
            return false;
        }
        foreach ((array) self::ListGroups($currentuser['userid']) as $group) {
            if ((string) $group['groupid'] === (string) $gid) {
                return ((int) $gid !== 1) || ((int) $currentuser['usergroupid'] === 1);
            }
        }
        return false;
    }

    /**
     * Template loop <% loop GroupList %>: groups the logged-in user may give a new client.
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return array<int, array<string, mixed>>|false One row per group: 'groupid', 'groupname', 'groupselected'; false when there are none.
     */
    static function getGroupList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $groups = array();
        foreach ((array) self::ListGroups($currentuser['userid']) as $group) {
            if (self::AllowedGroup($group['groupid'])) {
                $groups[] = $group;
            }
        }
        return count($groups) ? $groups : false;
    }

    /**
     * Template loop <% loop CurrentGroupList %>: groups for the edit form.
     *
     * @global runtime_controller $controller The request controller.
     * @return array<int, array<string, mixed>>|false See ListCurrentGroups().
     */
    static function getCurrentGroupList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListCurrentGroups($controller->GetControllerRequest('URL', 'other'), $currentuser['resellerid'], $currentuser['userid']);
    }

    /**
     * Template loop <% loop PackageList %>: the logged-in reseller's packages.
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return array<int, array<string, mixed>>|false See ListPackages().
     */
    static function getPackageList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListPackages($currentuser['userid']);
    }

    /**
     * Template loop <% loop CurrentPackageList %>: packages for the edit form.
     *
     * @global runtime_controller $controller The request controller.
     * @return array<int, array<string, mixed>>|false See ListCurrentPackages().
     */
    static function getCurrentPackageList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListCurrentPackages($controller->GetControllerRequest('URL', 'other'), $currentuser['userid']);
    }

    /**
     * Template loop <% loop CheckEnabledHTML %>: the enabled/disabled radio states.
     *
     * @global runtime_controller $controller The request controller.
     * @return array<int, array<string, string>> See CheckEnabledHTML().
     */
    static function getCheckEnabledHTML()
    {
        global $controller;
        return self::CheckEnabledHTML($controller->GetControllerRequest('URL', 'other'));
    }

    /**
     * Template flag <% if HasPackage %>: does the logged-in reseller have a package?
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return bool
     */
    static function getHasPackage()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::CheckHasPackage($currentuser['userid']);
    }

    /**
     * Whether the logged-in user has packages (same check as getHasPackage()).
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return bool
     */
    static function getIsReseller()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::CheckHasPackage($currentuser['userid']);
    }

    /**
     * Template flag <% if isCreateClient %>: show the list and create form?
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when there is no ?show=.
     */
    static function getisCreateClient()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (!isset($urlvars['show']))
            return true;
        return false;
    }

    /**
     * Template flag <% if isDeleteClient %>: show the delete view?
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|null $uid Optional. The account ID. Default null (the logged-in user).
     * @return bool|null True when ?show=Delete and the client in ?other= is the user's; null when it is not.
     */
    static function getisDeleteClient($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete Selected user.
        // This shall avoid exposing User based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

        $sql = " SELECT * FROM x_accounts WHERE ac_reseller_fk=:userid AND ac_id_pk=:editedUsrID AND ac_deleted_ts IS NULL";
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
     * Template flag <% if isEditClient %>: show the edit view?
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|null $uid Optional. The account ID. Default null (the logged-in user).
     * @return bool|null True when ?show=Edit and the client in ?other= is the user's; null when it is not.
     */
    static function getisEditClient($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars     = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Edit Selected user.
        // This shall avoid exposing User based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

        $sql = " SELECT * FROM x_accounts WHERE ac_reseller_fk=:userid AND ac_id_pk=:editedUsrID AND ac_deleted_ts IS NULL";
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
     * Template value <@ EditCurrentName @>: user name of the client in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The value ('' when there is no ?other=; null when there is no profile).
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
     * Template value <@ EditCurrentEmail @>: email of the client in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The value ('' when there is no ?other=; null when there is no profile).
     */
    static function getEditCurrentEmail()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['email'];
        } else {
            return "";
        }
    }

    /**
     * Template value <@ EditCurrentFullName @>: full name of the client in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The value ('' when there is no ?other=; null when there is no profile).
     */
    static function getEditCurrentFullName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['fullname'];
        } else {
            return "";
        }
    }

    /**
     * Template value <@ EditCurrentPost @>: postcode of the client in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The value ('' when there is no ?other=; null when there is no profile).
     */
    static function getEditCurrentPost()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['postcode'];
        } else {
            return "";
        }
    }

    /**
     * Template value <@ EditCurrentID @>: account ID of the client in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The value ('' when there is no ?other=; null when there is no profile).
     */
    static function getEditCurrentID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['userid'];
        } else {
            return "";
        }
    }

    /**
     * Template value <@ EditCurrentAddress @>: postal address of the client in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The value ('' when there is no ?other=; null when there is no profile).
     */
    static function getEditCurrentAddress()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['address'];
        } else {
            return "";
        }
    }

    /**
     * Template value <@ EditCurrentPhone @>: phone number of the client in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The value ('' when there is no ?other=; null when there is no profile).
     */
    static function getEditCurrentPhone()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['phone'];
        } else {
            return "";
        }
    }

    /**
     * Template value <@ DefaultEmailBody @>: the welcome email template.
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return string|false See DefaultEmailBody().
     */
    static function getDefaultEmailBody()
    {
        global $controller;
        return self::DefaultEmailBody();
    }

    /**
     * Template value <@ FormName @>: the posted user name, to refill the create form.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The posted value, or null after a successful create or when not posted.
     */
    static function getFormName()
    {
        global $controller;
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inNewUserName']) && fs_director::CheckForEmptyValue(self::$resetform)) {
            return $formvars['inNewUserName'];
        }
        return;
    }

    /**
     * Template value <@ FormFullName @>: the posted full name, to refill the create form.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The posted value, or null after a successful create or when not posted.
     */
    static function getFormFullName()
    {
        global $controller;
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inNewFullName']) && fs_director::CheckForEmptyValue(self::$resetform)) {
            return $formvars['inNewFullName'];
        }
        return;
    }

    /**
     * Template value <@ FormEmail @>: the posted email, to refill the create form.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The posted value, or null after a successful create or when not posted.
     */
    static function getFormEmail()
    {
        global $controller;
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inNewEmailAddress']) && fs_director::CheckForEmptyValue(self::$resetform)) {
            return $formvars['inNewEmailAddress'];
        }
        return;
    }

    /**
     * Template value <@ FormAddress @>: the posted address, to refill the create form.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The posted value, or null after a successful create or when not posted.
     */
    static function getFormAddress()
    {
        global $controller;
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inNewAddress']) && fs_director::CheckForEmptyValue(self::$resetform)) {
            return $formvars['inNewAddress'];
        }
        return;
    }

    /**
     * Template value <@ FormPost @>: the posted postcode, to refill the create form.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The posted value, or null after a successful create or when not posted.
     */
    static function getFormPost()
    {
        global $controller;
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inNewPostCode']) && fs_director::CheckForEmptyValue(self::$resetform)) {
            return $formvars['inNewPostCode'];
        }
        return;
    }

    /**
     * Template value <@ FormPhone @>: the posted phone number, to refill the create form.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The posted value, or null after a successful create or when not posted.
     */
    static function getFormPhone()
    {
        global $controller;
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inNewPhone']) && fs_director::CheckForEmptyValue(self::$resetform)) {
            return $formvars['inNewPhone'];
        }
        return;
    }
/*
    static function getRandomPassword()
    {
		$minpasswordlength = "16";
        $trylength = 9;
        if ($trylength < $minpasswordlength) {
            $uselength = $minpasswordlength;
        } else {
            $uselength = $trylength;
        }
        $password = fs_director::GenerateRandomPassword($uselength, 4);
        return $password;
    }
*/
    /**
     * Template value <@ MinPassLength @>: the minimum password length shown in the form.
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
     * @return string|null The error or success message HTML, or null when there is none.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$userblank)) {
            return ui_sysmessage::shout(ui_language::translate("You need to specify a username to create a new client."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$emailblank)) {
            return ui_sysmessage::shout(ui_language::translate("You need to specify an email address to create a new client."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$passwordblank)) {
            return ui_sysmessage::shout(ui_language::translate("Your password cannot be blank."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$packageblank)) {
            return ui_sysmessage::shout(ui_language::translate("You must select a package for your new client."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$groupblank)) {
            return ui_sysmessage::shout(ui_language::translate("You must select a user group for your new client."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return ui_sysmessage::shout(ui_language::translate("Your client name is not valid. Please enter a valid client name."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$badpass)) {
            return ui_sysmessage::shout(ui_language::translate("Your password is not valid. Valid characters are A-Z, a-z, 0-9."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$bademail)) {
            return ui_sysmessage::shout(ui_language::translate("Your email address is not valid. Please enter a valid email address."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badpasswordlength)) {
            return ui_sysmessage::shout(ui_language::translate("Your password did not meet the minimun length requirements. Characters needed for password length") . ": " . ctrl_options::GetSystemOption('password_minlength'), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("A client with that name already appears to exsist on this server."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your client(s) have been saved successfully!"), "zannounceok");
        }
        if (self::$tfreset !== null) {
            return ui_sysmessage::shout(ui_language::translate('The two-factor login of') . ' <strong>' . htmlspecialchars(self::$tfreset, ENT_QUOTES, 'UTF-8') . '</strong> '
                . ui_language::translate(ctrl_twofactor::Forced() ? 'has been reset: they set it up again at their next login.' : 'has been reset: they log in with their password only, and can turn it on again in My Account.'), 'zannounceok');
        }
        if (!fs_director::CheckForEmptyValue(self::$not_unique_email)) {
            $owner = self::$email_owner;
            $msg = ($owner !== null && $owner !== '')
                ? ui_language::translate("This email address is already used by the account") . ' <strong>' . htmlspecialchars($owner, ENT_QUOTES, 'UTF-8') . '</strong>.'
                : ui_language::translate("This email address is already used by an account that is not one of yours.");
            return ui_sysmessage::shout($msg . ' ' . ui_language::translate("Each account needs its own email address."), "zannounceerror");
        }
        return;
    }

    // Webinterface sudo methods.
}
