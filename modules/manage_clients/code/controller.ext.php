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
 * Updated by RusTus, 2026-11-07
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
    /** @var string[] Accounts that went to an administrator instead of the chosen account at the last delete (ctrl_privilege). */
    static $movedtoadmin = array();

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
                        ? '<button class="btn btn-warning" type="submit" name="inResetTwoFactor_' . (int) $currentuser['userid'] . '" value="1" onclick="return confirm('
                            . htmlspecialchars((string) json_encode(ui_language::translate('Reset the two-factor login of this client? Do this only after checking who is asking (for example by phone).')), ENT_QUOTES, 'UTF-8')
                            . ');">' . htmlspecialchars(ui_language::translate('Reset two-factor'), ENT_QUOTES, 'UTF-8') . '</button>'
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
     * Administrators: every built-in group and their own groups. Others: the built-in Users
     * group and their own groups (Manage Groups). Before, only the groups of the account's own
     * reseller were looked at, so a reseller's own groups were never offered.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return array<int, array<string, mixed>>|false One row per group: 'groupid', 'groupname', 'groupselected'; false when there are none.
     */
    static function ListGroups($uid)
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $admin = (int) $currentuser['usergroupid'] === 1;
        $q = $zdbh->prepare("SELECT * FROM x_groups WHERE (ug_reseller_fk = 1 AND ug_name_vc IN (" . ($admin ? "'Administrators', 'Resellers', 'Users'" : "'Users'") . "))
            OR (ug_reseller_fk = :me AND NOT (ug_reseller_fk = 1 AND ug_name_vc IN ('Administrators', 'Resellers', 'Users'))) ORDER BY ug_id_pk > 3, ug_name_vc");
        $q->execute(array(':me' => (int) $currentuser['userid']));
        $res = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $rowgroups) {
            $res[] = array('groupid' => $rowgroups['ug_id_pk'],
                'groupname' => htmlspecialchars(ui_language::GroupName($rowgroups['ug_name_vc']), ENT_QUOTES, 'UTF-8'),
                'groupselected' => '');
        }
        return $res ? $res : false;
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
                        'groupname' => htmlspecialchars(ui_language::GroupName($rowgroups['ug_name_vc']), ENT_QUOTES, 'UTF-8'),
                        'groupselected' => $selected));
                } else {
                    if (strtoupper($rowgroups['ug_name_vc']) == "USERS") {
                        $selected = "";
                        if ($rowgroups['ug_id_pk'] == $currentuser['usergroupid']) {
                            $selected = " selected";
                        }
                        array_push($res, array('groupid' => $rowgroups['ug_id_pk'],
                            'groupname' => htmlspecialchars(ui_language::GroupName($rowgroups['ug_name_vc']), ENT_QUOTES, 'UTF-8'),
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
                    'packagename' => $rowgroups['pk_name_vc']));
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
                    'packagename' => $rowgroups['pk_name_vc'],
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
        // its clients, packages and groups need a new owner: by default its own reseller (an
        // empty one made them owned by nobody); never the account being deleted
        $q = $zdbh->prepare("SELECT ac_reseller_fk FROM x_accounts WHERE ac_id_pk = :id");
        $q->execute(array(':id' => (int) $userid));
        $parent = (int) $q->fetchColumn();
        if (empty($moveid) || (int) $moveid === (int) $userid) {
            $moveid = $parent > 0 && $parent !== (int) $userid ? $parent : 1;
        }
        // what would be above the new owner (administrators, accounts and groups with an administrator's
        // module it lacks; see ctrl_privilege) goes to an administrator instead: handing an
        // administrator to a reseller let the reseller shadow it or set its password
        $q = $zdbh->prepare("SELECT ac_group_fk FROM x_accounts WHERE ac_id_pk = :id");
        $q->execute(array(':id' => (int) $moveid));
        $moveGroup = (int) $q->fetchColumn();
        $admin = ctrl_privilege::AdminAccount();
        self::$movedtoadmin = array();
        if ($moveGroup !== 1 && $admin !== (int) $moveid) {
            $q = $zdbh->prepare("SELECT a.ac_id_pk, a.ac_user_vc FROM x_accounts a WHERE a.ac_reseller_fk = :u AND a.ac_id_pk <> :u2 AND a.ac_deleted_ts IS NULL AND " . ctrl_privilege::AboveSql('a.ac_group_fk', $moveGroup));
            $q->execute(array(':u' => (int) $userid, ':u2' => (int) $userid));
            $set = $zdbh->prepare("UPDATE x_accounts SET ac_reseller_fk = :a WHERE ac_id_pk = :id");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $set->execute(array(':a' => $admin, ':id' => (int) $r['ac_id_pk']));
                self::$movedtoadmin[] = (string) $r['ac_user_vc'];
            }
            $q = $zdbh->prepare("UPDATE x_groups g SET g.ug_reseller_fk = :a WHERE g.ug_reseller_fk = :u AND " . ctrl_privilege::AboveSql('g.ug_id_pk', $moveGroup));
            $q->execute(array(':a' => $admin, ':u' => (int) $userid));
        }
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
        // its notices go with it: the ones it sent and the personal ones to it (Client Notice Manager)
        ctrl_notices::ForgetAccount((int) $userid);
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
     * @return bool True when saved, false when the email address or the new password is not valid (a flag says which).
     */
    static function ExecuteUpdateClient($clientid, $package, $enabled, $group, $fullname, $email, $address, $post, $phone, $newpass)
    {
        global $zdbh;
        $email = trim((string) $email);
        if ($email === '') {
            self::$emailblank = true;
            return false;
        }
        if (!self::IsValidEmail($email)) {
            self::$bademail = true;
            return false;
        }
        // another account may not have the address (it may keep its own)
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE LOWER(ac_email_vc) = :e AND ac_id_pk <> :id AND ac_deleted_ts IS NULL");
        $q->execute(array(':e' => strtolower($email), ':id' => (int) $clientid));
        if ((int) $q->fetchColumn() > 0) {
            self::$not_unique_email = true;
            self::$email_owner = self::EmailOwnerFor($email);
            return false;
        }
        $q = $zdbh->prepare("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = :id");
        $q->execute(array(':id' => (int) $clientid));
        $wasEnabled = (int) $q->fetchColumn();
        $enabled = (int) $enabled === 1 ? 1 : 0;
        runtime_hook::Execute('OnBeforeUpdateClient');
        if ($newpass != "") {
            // Check for password length (this set an undeclared property, a fatal error in PHP 8)
            if (strlen($newpass) < (int) self::getMinPassLength()) {
                self::$badpasswordlength = true;
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

        // an account made elsewhere may have no profile row yet
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_profiles WHERE ud_user_fk = :id");
        $q->execute(array(':id' => (int) $clientid));
        if ((int) $q->fetchColumn() === 0) {
            $q = $zdbh->prepare("INSERT INTO x_profiles (ud_user_fk, ud_created_ts) VALUES (:id, :t)");
            $q->execute(array(':id' => (int) $clientid, ':t' => time()));
        }
        $sql = $zdbh->prepare("UPDATE x_profiles SET ud_fullname_vc= :fullname, ud_group_fk= :group, ud_package_fk= :package, ud_address_tx= :address,ud_postcode_vc= :postcode, ud_phone_vc= :phone WHERE ud_user_fk=:accountid");
        $sql->bindParam(':fullname', $fullname);
        $sql->bindParam(':group', $group);
        $sql->bindParam(':package', $package);
        $sql->bindParam(':address', $address);
        $sql->bindParam(':postcode', $post);
        $sql->bindParam(':phone', $phone);
        $sql->bindParam(':accountid', $clientid);
        $sql->execute();
        // the enable and disable hooks (they rewrite the web server's settings) only when it changes
        if ($enabled === 0 && $wasEnabled === 1) {
            self::DisableClient($clientid);
        }
        if ($enabled === 1 && $wasEnabled !== 1) {
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
        // the new row's own ID (the reseller's newest account could be another one made at the same time)
        $client = array('ac_id_pk' => (int) $zdbh->lastInsertId());

        $sql = $zdbh->prepare("INSERT INTO x_profiles (ud_user_fk, ud_fullname_vc, ud_group_fk, ud_package_fk, ud_address_tx, ud_postcode_vc, ud_phone_vc, ud_created_ts) VALUES (:userid, :fullname, :groupid, :packageid, :address, :postcode, :phone, :time)");
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
            // the reseller's own panel address when it has one (Branding)
            $panelUrl = ctrl_branding::PanelUrlFor((int) $uid);
            $emailbody = str_replace('{{controlpanelurl}}', $panelUrl !== '' ? $panelUrl : $protocol . $domain, $emailbody);

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
		if (strlen($password) < (int) self::getMinPassLength()) {
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

    // End 'worker' methods.

    /**
     * The view after a refused save: 'new' or 'edit' ('' for none), so the form shows again with what was typed.
     *
     * @var string
     */
    static $failed = '';

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
     * An inline line icon.
     *
     * @param string $name 'user', 'off', 'disk', 'plus', 'edit', 'shadow', 'notice', 'key', 'trash', 'back', 'search' or 'users'.
     * @param string $class Optional. The SVG's class. Default 'mc-ico'.
     * @return string The SVG.
     */
    static function Icon($name, $class = 'mc-ico')
    {
        $p = array(
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"/>',
            'off' => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
            'disk' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
            'shadow' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
            'notice' => '<path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/>',
            'key' => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.8-9.8M17 6l3 3M14.5 8.5l2 2"/>',
            'trash' => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
            'back' => '<path d="M15 18l-6-6 6-6"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
            'warn' => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        );
        return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . (isset($p[$name]) ? $p[$name] : '') . '</svg>';
    }

    /**
     * The logged-in account's own clients (enabled and switched off), with what the list shows.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>> By user name: 'id', 'user', 'name', 'email', 'package', 'group', 'enabled', 'created', 'lastlogon',
     *         'clients' (its own clients), 'disk', 'diskmax', 'bw', 'bwmax' (bytes; max 0 = no limit), 'twofactor'.
     */
    static function Rows()
    {
        global $zdbh;
        static $rows = null;
        if ($rows !== null) {
            return $rows;
        }
        $me = (int) ctrl_users::GetUserDetail()['userid'];
        // never a client above a reseller (an administrator, or one with an administrator's module the
        // reseller's group lacks; see ctrl_privilege): setting its password would be taking it over
        $above = ' AND NOT ' . ctrl_privilege::AboveSql('a.ac_group_fk', (int) ctrl_users::GetUserDetail()['usergroupid']);
        $q = $zdbh->prepare("SELECT a.ac_id_pk, a.ac_user_vc, a.ac_email_vc, a.ac_enabled_in, a.ac_created_ts, a.ac_lastlogon_ts, p.ud_fullname_vc, k.pk_name_vc, g.ug_name_vc,
            (SELECT COUNT(*) FROM x_accounts c WHERE c.ac_reseller_fk = a.ac_id_pk AND c.ac_id_pk <> a.ac_id_pk AND c.ac_deleted_ts IS NULL) AS clients
            FROM x_accounts a LEFT JOIN x_profiles p ON p.ud_user_fk = a.ac_id_pk LEFT JOIN x_packages k ON k.pk_id_pk = a.ac_package_fk LEFT JOIN x_groups g ON g.ug_id_pk = a.ac_group_fk
            WHERE a.ac_reseller_fk = :me AND a.ac_id_pk <> :me2 AND a.ac_deleted_ts IS NULL" . $above . " ORDER BY a.ac_user_vc");
        $q->execute(array(':me' => $me, ':me2' => $me));
        $rows = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['ac_id_pk'];
            $u = ctrl_users::GetUserDetail($id);
            $tf = ctrl_twofactor::Row($id);
            $rows[] = array(
                'id' => $id, 'user' => (string) $r['ac_user_vc'], 'name' => (string) $r['ud_fullname_vc'], 'email' => (string) $r['ac_email_vc'],
                'package' => (string) $r['pk_name_vc'], 'group' => (string) $r['ug_name_vc'], 'enabled' => (int) $r['ac_enabled_in'] === 1,
                'created' => (int) $r['ac_created_ts'], 'lastlogon' => (int) $r['ac_lastlogon_ts'], 'clients' => (int) $r['clients'],
                'disk' => (float) ctrl_users::GetQuotaUsages('diskspace', $id), 'diskmax' => (float) $u['diskquota'],
                'bw' => (float) ctrl_users::GetQuotaUsages('bandwidth', $id), 'bwmax' => (float) $u['bandwidthquota'],
                'twofactor' => $tf && (int) $tf['tf_enabled_in'] === 1,
            );
        }
        return $rows;
    }

    /**
     * One of the logged-in account's own clients (never the account itself).
     *
     * @param int|string|null $id The account ID.
     * @return array<string, mixed>|false The client (see Rows()), or false.
     */
    static function Mine($id)
    {
        foreach (self::Rows() as $r) {
            if ($r['id'] === (int) $id) {
                return $r;
            }
        }
        return false;
    }

    /**
     * Which page shows: the list, the new client form, a client's edit form, or a delete confirmation.
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
        if ($show === 'New' && self::CheckHasPackage(ctrl_users::GetUserDetail()['userid'])) {
            return 'new';
        }
        if (in_array($show, array('Edit', 'Delete'), true) && self::Mine($controller->GetControllerRequest('URL', 'other'))) {
            return strtolower($show);
        }
        return 'list';
    }

    /**
     * Template flag <% if IsList %>: the client list.
     *
     * @return bool
     */
    static function getIsList()
    {
        return self::View() === 'list';
    }

    /**
     * Template flag <% if IsForm %>: the new client form or a client's edit form.
     *
     * @return bool
     */
    static function getIsForm()
    {
        return in_array(self::View(), array('new', 'edit'), true);
    }

    /**
     * Template flag <% if IsNew %>: the new client form.
     *
     * @return bool
     */
    static function getIsNew()
    {
        return self::View() === 'new';
    }

    /**
     * Template flag <% if IsEdit %>: a client's edit form.
     *
     * @return bool
     */
    static function getIsEdit()
    {
        return self::View() === 'edit';
    }

    /**
     * Template flag <% if IsDelete %>: a delete confirmation.
     *
     * @return bool
     */
    static function getIsDelete()
    {
        return self::View() === 'delete';
    }

    /**
     * Template flag <% if NoPackage %>: no package yet, so no client can be made.
     *
     * @return bool
     */
    static function getNoPackage()
    {
        return !self::CheckHasPackage(ctrl_users::GetUserDetail()['userid']);
    }

    /**
     * Template flag <% if HasPackage %>: the account has a package, so it can make clients.
     *
     * @return bool
     */
    static function getHasPackage()
    {
        return !self::getNoPackage();
    }

    /**
     * Template flag <% if PackagesOn %>: the Package Manager is switched on, for its link.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return bool
     */
    static function getPackagesOn()
    {
        global $zdbh;
        return (int) $zdbh->query("SELECT COUNT(*) FROM x_modules WHERE mo_folder_vc = 'packages' AND mo_enabled_en = 'true'")->fetchColumn() > 0;
    }

    /**
     * A size in words, such as '1.2 GB'.
     *
     * @param float $bytes The size.
     * @return string The escaped text.
     */
    static function Size($bytes)
    {
        return self::h(fs_director::ShowHumanFileSize($bytes));
    }

    /**
     * A usage bar: how much of the limit is used.
     *
     * @param float $used Bytes used.
     * @param float $max The limit (0 = no limit).
     * @param string $label 'Disk' or 'Bandwidth'.
     * @return string The HTML.
     */
    static function Bar($used, $max, $label)
    {
        $pct = $max > 0 ? min(100, round($used / $max * 100)) : 0;
        return '<div class="mc-use' . ($max > 0 && $pct >= 90 ? ' full' : ($max > 0 && $pct >= 75 ? ' high' : '')) . '"><span class="mc-use-l">' . self::t($label) . '</span>'
            . '<span class="mc-use-v">' . self::Size($used) . ' <small>' . ($max > 0 ? str_replace('%s', self::Size($max), self::t('of %s')) : self::t('no limit')) . '</small></span>'
            . '<div class="mc-bar"><i style="width:' . ($max > 0 ? $pct : 0) . '%"></i></div></div>';
    }

    /**
     * The figures at the top (<@ Tiles @>): clients, switched off, and the disk space they use.
     *
     * @return string The HTML.
     */
    static function getTiles()
    {
        $rows = self::Rows();
        $on = count(array_filter($rows, function ($r) { return $r['enabled']; }));
        $disk = array_sum(array_map(function ($r) { return $r['disk']; }, $rows));
        $tile = function ($cls, $icon, $num, $text) {
            return '<div class="mc-tile ' . $cls . '">' . self::Icon($icon, 'mc-ico-lg') . '<div><b>' . $num . '</b><span>' . self::t($text) . '</span></div></div>';
        };
        return $tile('', 'users', $on, 'Active clients') . $tile(count($rows) - $on ? 'warn' : '', 'off', count($rows) - $on, 'Switched off')
            . $tile('', 'disk', self::Size($disk), 'Disk space they use');
    }

    /**
     * The client list (<@ ClientRows @>): a row each with its package, usage and actions.
     *
     * @return string The HTML.
     */
    static function getClientRows()
    {
        $rows = self::Rows();
        if (!$rows) {
            return '';
        }
        $shadow = self::getShadowEnabled();
        $notices = self::getNoticesEnabled();
        $out = '';
        foreach ($rows as $r) {
            $id = $r['id'];
            $search = strtolower($r['user'] . ' ' . $r['name'] . ' ' . $r['email'] . ' ' . $r['package'] . ' ' . $r['group']);
            $out .= '<div class="mc-row' . ($r['enabled'] ? '' : ' is-off') . '" data-s="' . self::h($search) . '" data-state="' . ($r['enabled'] ? 'on' : 'off') . '">'
                . '<span class="mc-avatar" aria-hidden="true">' . self::h(strtoupper(substr($r['user'], 0, 1))) . '</span>'
                . '<div class="mc-main"><div class="mc-name"><a href="./?module=manage_clients&amp;show=Edit&amp;other=' . $id . '"><b>' . self::h($r['user']) . '</b></a>'
                . ($r['name'] !== '' ? '<span class="mc-full">' . self::h($r['name']) . '</span>' : '')
                . (!$r['enabled'] ? '<span class="mc-badge off">' . self::t('Switched off') . '</span>' : '')
                . ($r['clients'] ? '<span class="mc-badge">' . ($r['clients'] === 1 ? self::t('1 client') : str_replace('%d', (string) $r['clients'], self::t('%d clients'))) . '</span>' : '')
                . ($r['twofactor'] ? '<span class="mc-badge ok" title="' . self::t('Two-factor login is on') . '">2FA</span>' : '') . '</div>'
                . '<div class="mc-sub">' . ($r['email'] !== '' ? '<span>' . self::h($r['email']) . '</span>' : '')
                . '<span class="mc-chip">' . self::h($r['package'] !== '' ? $r['package'] : '?') . '</span><span>' . self::h(ui_language::GroupName($r['group'])) . '</span>'
                . '<span>' . ($r['lastlogon'] ? str_replace('%s', self::h(ui_language::Date('j M Y', $r['lastlogon'])), self::t('Last login %s')) : self::t('Never logged in')) . '</span></div></div>'
                . '<div class="mc-uses">' . self::Bar($r['disk'], $r['diskmax'], 'Disk') . self::Bar($r['bw'], $r['bwmax'], 'Bandwidth') . '</div>'
                . '<div class="mc-act">'
                . '<a class="btn btn-default btn-sm" href="./?module=manage_clients&amp;show=Edit&amp;other=' . $id . '">' . self::t('Edit') . '</a>'
                . ($shadow && $r['enabled'] ? '<button class="btn btn-default btn-sm mc-ib" type="submit" form="mc-act" formaction="./?module=shadowing&amp;action=ShadowUser" name="inShadow_' . $id . '" value="inShadow_' . $id . '" title="' . self::t('Log in as this client (shadow)') . '" aria-label="' . str_replace('%s', self::h($r['user']), self::t('Shadow %s')) . '">' . self::Icon('shadow') . '</button>' : '')
                . ($notices ? '<a class="btn btn-default btn-sm mc-ib" href="./?module=client_notices&amp;show=New&amp;user=' . $id . '" title="' . self::t('Send this client a personal notice') . '" aria-label="' . str_replace('%s', self::h($r['user']), self::t('Send notice %s')) . '">' . self::Icon('notice') . '</a>' : '')
                . '<a class="btn btn-default btn-sm mc-ib mc-del" href="./?module=manage_clients&amp;show=Delete&amp;other=' . $id . '" title="' . self::t('Delete') . '" aria-label="' . str_replace('%s', self::h($r['user']), self::t('Delete %s')) . '">' . self::Icon('trash') . '</a>'
                . '</div></div>';
        }
        return $out;
    }

    /**
     * Template flag <% if HasClients %>: the account has a client.
     *
     * @return bool
     */
    static function getHasClients()
    {
        return count(self::Rows()) > 0;
    }

    /**
     * Template flag <% if NoClients %>: no client yet.
     *
     * @return bool
     */
    static function getNoClients()
    {
        return !self::getHasClients();
    }

    /**
     * Template flag <% if HasOff %>: a client is switched off, for the filter.
     *
     * @return bool
     */
    static function getHasOff()
    {
        foreach (self::Rows() as $r) {
            if (!$r['enabled']) {
                return true;
            }
        }
        return false;
    }

    /**
     * The values in the form: what was typed after a refused save, the client being changed, or empty for a new one.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return array<string, string> 'id', 'user', 'name', 'email', 'address', 'post', 'phone', 'group', 'package', 'enabled', 'subject', 'body', 'send'.
     */
    static function FormValues()
    {
        global $controller, $zdbh;
        static $v = null;
        if ($v !== null) {
            return $v;
        }
        $f = $controller->GetAllControllerRequests('FORM');
        $g = function ($k, $d = '') use ($f) { return isset($f[$k]) ? (string) $f[$k] : $d; };
        if (self::$failed === 'new') {
            $v = array('id' => '', 'user' => $g('inNewUserName'), 'name' => $g('inNewFullName'), 'email' => $g('inNewEmailAddress'), 'address' => $g('inNewAddress'),
                'post' => $g('inNewPostCode'), 'phone' => $g('inNewPhone'), 'group' => $g('inNewGroup'), 'package' => $g('inNewPackage'), 'enabled' => '1',
                'subject' => $g('inEmailSubject'), 'body' => $g('inEmailBody'), 'send' => isset($f['inSWE']) ? '1' : '');
        } elseif (self::$failed === 'edit') {
            $v = array('id' => $g('inClientID'), 'user' => '', 'name' => $g('inFullName'), 'email' => $g('inEmailAddress'), 'address' => $g('inAddress'), 'post' => $g('inPostCode'),
                'phone' => $g('inPhone'), 'group' => $g('inGroup'), 'package' => $g('inPackage'), 'enabled' => $g('inEnabled') === '1' ? '1' : '0', 'subject' => '', 'body' => '', 'send' => '');
            $c = self::Mine($v['id']);
            $v['user'] = $c ? $c['user'] : '';
        } elseif (self::View() === 'edit') {
            $id = (int) $controller->GetControllerRequest('URL', 'other');
            $q = $zdbh->prepare("SELECT a.ac_user_vc, a.ac_email_vc, a.ac_enabled_in, a.ac_group_fk, a.ac_package_fk, p.ud_fullname_vc, p.ud_address_tx, p.ud_postcode_vc, p.ud_phone_vc
                FROM x_accounts a LEFT JOIN x_profiles p ON p.ud_user_fk = a.ac_id_pk WHERE a.ac_id_pk = :id");
            $q->execute(array(':id' => $id));
            $r = $q->fetch(PDO::FETCH_ASSOC);
            $v = array('id' => (string) $id, 'user' => (string) $r['ac_user_vc'], 'name' => (string) $r['ud_fullname_vc'], 'email' => (string) $r['ac_email_vc'],
                'address' => (string) $r['ud_address_tx'], 'post' => (string) $r['ud_postcode_vc'], 'phone' => (string) $r['ud_phone_vc'], 'group' => (string) $r['ac_group_fk'],
                'package' => (string) $r['ac_package_fk'], 'enabled' => (int) $r['ac_enabled_in'] === 1 ? '1' : '0', 'subject' => '', 'body' => '', 'send' => '');
        } else {
            $v = array('id' => '', 'user' => '', 'name' => '', 'email' => '', 'address' => '', 'post' => '', 'phone' => '', 'group' => '', 'package' => '', 'enabled' => '1',
                'subject' => ui_language::translate('Your Sentora Account details'), 'body' => (string) self::DefaultEmailBody(), 'send' => '1');
        }
        return $v;
    }

    /**
     * Template value <@ F_x @>: one form value, escaped (the template tags call these getters).
     *
     * @param string $k The key (see FormValues()).
     * @return string The escaped value.
     */
    static function F($k)
    {
        $v = self::FormValues();
        return self::h(isset($v[$k]) ? $v[$k] : '');
    }

    /**
     * <@ FId @>: the client's account ID in the edit form.
     *
     * @return string
     */
    static function getFId()
    {
        return self::F('id');
    }

    /**
     * <@ FUser @>: the user name.
     *
     * @return string
     */
    static function getFUser()
    {
        return self::F('user');
    }

    /**
     * <@ FName @>: the full name.
     *
     * @return string
     */
    static function getFName()
    {
        return self::F('name');
    }

    /**
     * <@ FEmail @>: the email address.
     *
     * @return string
     */
    static function getFEmail()
    {
        return self::F('email');
    }

    /**
     * <@ FAddress @>: the postal address.
     *
     * @return string
     */
    static function getFAddress()
    {
        return self::F('address');
    }

    /**
     * <@ FPost @>: the postal code.
     *
     * @return string
     */
    static function getFPost()
    {
        return self::F('post');
    }

    /**
     * <@ FPhone @>: the phone number.
     *
     * @return string
     */
    static function getFPhone()
    {
        return self::F('phone');
    }

    /**
     * <@ FSubject @>: the welcome email's subject.
     *
     * @return string
     */
    static function getFSubject()
    {
        return self::F('subject');
    }

    /**
     * <@ FBody @>: the welcome email's text.
     *
     * @return string
     */
    static function getFBody()
    {
        return self::F('body');
    }

    /**
     * <@ FSendChecked @>: ' checked' when the welcome email is sent.
     *
     * @return string
     */
    static function getFSendChecked()
    {
        return self::FormValues()['send'] === '1' ? ' checked' : '';
    }

    /**
     * <@ FEnabledChecked @>: ' checked' when the client may log in.
     *
     * @return string
     */
    static function getFEnabledChecked()
    {
        return self::FormValues()['enabled'] === '1' ? ' checked' : '';
    }

    /**
     * The groups the account may give a client, as <option>s with the form's one selected (<@ GroupOptions @>).
     *
     * @return string The HTML.
     */
    static function getGroupOptions()
    {
        $cur = self::FormValues()['group'];
        $groups = (array) self::getGroupList();
        // a client keeps the group it has, even one this account can not give (shown so a save does not change it unseen)
        $out = $cur === '' ? '<option value="">' . self::t('Choose a group') . '</option>' : '';
        foreach ($groups as $g) {
            $out .= '<option value="' . (int) $g['groupid'] . '"' . ((string) $g['groupid'] === $cur ? ' selected' : '') . '>' . $g['groupname'] . '</option>';
        }
        return $out;
    }

    /**
     * The account's packages as <option>s with the form's one selected (<@ PackageOptions @>).
     *
     * @return string The HTML.
     */
    static function getPackageOptions()
    {
        $cur = self::FormValues()['package'];
        $out = $cur === '' ? '<option value="">' . self::t('Choose a package') . '</option>' : '';
        foreach ((array) self::ListPackages(ctrl_users::GetUserDetail()['userid']) as $k) {
            $out .= '<option value="' . (int) $k['packageid'] . '"' . ((string) $k['packageid'] === $cur ? ' selected' : '') . '>' . self::h($k['packagename']) . '</option>';
        }
        return $out;
    }

    /**
     * The form's heading (<@ FormTitle @>).
     *
     * @return string The escaped text.
     */
    static function getFormTitle()
    {
        return self::View() === 'edit' ? self::h(self::FormValues()['user']) : self::t('New client');
    }

    /**
     * The card beside the edit form (<@ ClientSummary @>): usage, dates and the quick actions.
     *
     * @return string The HTML.
     */
    static function getClientSummary()
    {
        $r = self::Mine(self::FormValues()['id']);
        if (!$r) {
            return '';
        }
        $d = function ($t) { return $t ? self::h(ui_language::Date('j M Y, H:i', $t)) : self::t('never'); };
        $out = '<div class="mc-sum-head"><span class="mc-avatar lg" aria-hidden="true">' . self::h(strtoupper(substr($r['user'], 0, 1))) . '</span><div><b>' . self::h($r['user']) . '</b>'
            . '<span>' . self::h($r['package']) . ' &middot; ' . self::h(ui_language::GroupName($r['group'])) . '</span></div></div>'
            . self::Bar($r['disk'], $r['diskmax'], 'Disk') . self::Bar($r['bw'], $r['bwmax'], 'Bandwidth')
            . '<dl class="mc-dl"><dt>' . self::t('Added') . '</dt><dd>' . $d($r['created']) . '</dd><dt>' . self::t('Last login') . '</dt><dd>' . $d($r['lastlogon']) . '</dd>'
            . '<dt>' . self::t('Its own clients') . '</dt><dd>' . $r['clients'] . '</dd><dt>' . self::t('Two-factor login') . '</dt><dd>' . self::t($r['twofactor'] ? 'on' : 'off') . '</dd></dl>'
            . '<div class="mc-quick">';
        if (self::getShadowEnabled() && $r['enabled']) {
            $out .= '<button class="btn btn-default btn-sm" type="submit" form="mc-act" formaction="./?module=shadowing&amp;action=ShadowUser" name="inShadow_' . $r['id'] . '" value="inShadow_' . $r['id'] . '">' . self::Icon('shadow') . self::t('Log in as this client') . '</button>';
        }
        if (self::getNoticesEnabled()) {
            $out .= '<a class="btn btn-default btn-sm" href="./?module=client_notices&amp;show=New&amp;user=' . $r['id'] . '">' . self::Icon('notice') . self::t('Send a notice') . '</a>';
        }
        if ($r['twofactor']) {
            $out .= '<button class="btn btn-default btn-sm" type="submit" form="mc-act" formaction="./?module=manage_clients&amp;action=EditClient" name="inResetTwoFactor_' . $r['id'] . '" value="1" data-confirm="'
                . self::t('Reset the two-factor login of this client? Do this only after checking who is asking (for example by phone).') . '">' . self::Icon('key') . self::t('Reset two-factor login') . '</button>';
        }
        return $out . '<a class="btn btn-default btn-sm mc-delbtn" href="./?module=manage_clients&amp;show=Delete&amp;other=' . $r['id'] . '">' . self::Icon('trash') . self::t('Delete this client') . '</a></div>';
    }

    /**
     * The client the delete confirmation is for (<@ DeleteName @>), escaped.
     *
     * @global runtime_controller $controller The request controller.
     * @return string
     */
    static function getDeleteName()
    {
        global $controller;
        $r = self::Mine($controller->GetControllerRequest('URL', 'other'));
        return $r ? self::h($r['user']) : '';
    }

    /**
     * The client's ID in the delete confirmation (<@ DeleteId @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int
     */
    static function getDeleteId()
    {
        global $controller;
        $r = self::Mine($controller->GetControllerRequest('URL', 'other'));
        return $r ? $r['id'] : 0;
    }

    /**
     * What deleting the client removes, counted (<@ DeleteDetails @>), and where its own clients and packages go.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML.
     */
    static function getDeleteDetails()
    {
        global $controller, $zdbh;
        $r = self::Mine($controller->GetControllerRequest('URL', 'other'));
        if (!$r) {
            return '';
        }
        $id = $r['id'];
        $count = function ($sql) use ($zdbh, $id) {
            $q = $zdbh->prepare($sql);
            $q->execute(array(':id' => $id));
            return (int) $q->fetchColumn();
        };
        $what = array(
            array($count("SELECT COUNT(*) FROM x_vhosts WHERE vh_acc_fk = :id AND vh_deleted_ts IS NULL"), '1 website (domains, sub-domains and parked domains), with its files', '%d websites (domains, sub-domains and parked domains), with their files'),
            array($count("SELECT COUNT(*) FROM x_mailboxes WHERE mb_acc_fk = :id AND mb_deleted_ts IS NULL"), '1 mailbox, with its mail', '%d mailboxes, with their mail'),
            array($count("SELECT COUNT(*) FROM x_mysql_databases WHERE my_acc_fk = :id AND my_deleted_ts IS NULL"), '1 MySQL database', '%d MySQL databases'),
            array($count("SELECT COUNT(*) FROM x_ftpaccounts WHERE ft_acc_fk = :id AND ft_deleted_ts IS NULL"), '1 FTP account', '%d FTP accounts'),
            array($count("SELECT COUNT(*) FROM x_cronjobs WHERE ct_acc_fk = :id AND ct_deleted_ts IS NULL"), '1 cron job', '%d cron jobs'),
        );
        $li = '';
        foreach ($what as $w) {
            if ($w[0] > 0) {
                $li .= '<li>' . ($w[0] === 1 ? self::t($w[1]) : str_replace('%d', '<b>' . $w[0] . '</b>', self::t($w[2]))) . '</li>';
            }
        }
        $out = '<p>' . self::t('The account can no longer log in, and everything it has is removed from the server within the hour. This can not be undone.') . '</p>'
            . ($li !== '' ? '<ul class="mc-lose">' . $li . '</ul>' : '<p class="mc-sub">' . self::t('It has no websites, mailboxes, databases, FTP accounts or cron jobs.') . '</p>');
        $owned = $r['clients'] + $count("SELECT COUNT(*) FROM x_packages WHERE pk_reseller_fk = :id AND pk_deleted_ts IS NULL");
        if ($owned > 0) {
            $me = ctrl_users::GetUserDetail();
            $opts = '<option value="' . (int) $me['userid'] . '">' . str_replace('%s', self::h($me['username']), self::t('%s (you)')) . '</option>';
            foreach ((array) self::ListAllClients($id, $me['userid']) as $m) {
                if ((int) $m['moveclientid'] === (int) $me['userid']) {
                    continue;
                }
                $opts .= '<option value="' . (int) $m['moveclientid'] . '">' . self::h($m['moveclientname']) . '</option>';
            }
            $out .= '<div class="mc-move"><label for="inMoveClient">' . ($r['clients'] === 1 ? self::t('It has 1 client and its own packages. Give them to:')
                : str_replace('%d', (string) $r['clients'], self::t('It has %d clients and its own packages. Give them to:'))) . '</label><select class="form-control" name="inMoveClient" id="inMoveClient">' . $opts . '</select></div>';
        }
        return $out;
    }

    /**
     * Handle the create client form (start of the web interface methods). When refused, the form shows again with what was typed.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool|void False when refused; otherwise ends with a redirect to the list and exit.
     */
    static function doCreateClient()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        self::$failed = 'new';
        // A reseller may only use its own packages and the groups it is offered; only an
        // administrator may create administrators (any reseller could, by sending group 1)
        if (empty($formvars['inNewPackage']) || !ctrl_ownership::Owns('x_packages', 'pk_id_pk', 'pk_reseller_fk', $formvars['inNewPackage'], ' AND pk_deleted_ts IS NULL')) {
            self::$packageblank = true;
            return false;
        }
        if (empty($formvars['inNewGroup']) || !self::AllowedGroup($formvars['inNewGroup'])) {
            self::$groupblank = true;
            return false;
        }
        $sendemail = isset($formvars['inSWE']) ? $formvars['inSWE'] : 0;
        if (self::ExecuteCreateClient($currentuser['userid'], (string) ($formvars['inNewUserName'] ?? ''), $formvars['inNewPackage'], $formvars['inNewGroup'], (string) ($formvars['inNewFullName'] ?? ''), trim((string) ($formvars['inNewEmailAddress'] ?? '')),
            (string) ($formvars['inNewAddress'] ?? ''), (string) ($formvars['inNewPostCode'] ?? ''), (string) ($formvars['inNewPhone'] ?? ''), (string) ($formvars['inNewPassword'] ?? ''), $sendemail, ($formvars['inEmailSubject'] ?? ''), ($formvars['inEmailBody'] ?? ''))) {
            header('location: ./?module=' . $controller->GetCurrentModule() . '&created=' . rawurlencode(strtolower(str_replace(' ', '', (string) $formvars['inNewUserName']))));
            exit;
        }
        return false;
    }

    /**
     * Handle Reset two-factor (and the old list's Edit and Delete buttons, which redirect and exit).
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
        foreach (self::Rows() as $row) {
            if (isset($formvars['inDelete_' . $row['id']])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['id']);
                exit;
            }
            if (isset($formvars['inEdit_' . $row['id']])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $row['id']);
                exit;
            }
            // one of this administrator's or reseller's own clients (the list has only those)
            if (isset($formvars['inResetTwoFactor_' . $row['id']])) {
                ctrl_twofactor::ResetBy($row['id'], $currentuser['username']);
                self::$tfreset = $row['user'];
                return;
            }
        }
        return;
    }

    /**
     * The old disabled client list's buttons (the list is one now): the same as doEditClient().
     *
     * @return void
     */
    static function doEditDisabledClient()
    {
        self::doEditClient();
    }

    /**
     * Handle the delete confirmation form: one of the account's own clients, its clients and
     * packages given to the account itself or one of its other reseller clients.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool|void False when the client or the new owner is not allowed; otherwise ends with a redirect and exit.
     */
    static function doDeleteClient()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        $me = (int) ctrl_users::GetUserDetail()['userid'];
        $client = isset($formvars['inDelete']) ? self::Mine($formvars['inDelete']) : false;
        $move = !empty($formvars['inMoveClient']) ? (int) $formvars['inMoveClient'] : $me;
        if (!$client || ($move !== $me && (!self::Mine($move) || $move === $client['id']))) {
            return false;
        }
        self::ExecuteDeleteClient($client['id'], $move);
        header('location: ./?module=' . $controller->GetCurrentModule() . '&deleted=' . rawurlencode($client['user'])
            . (self::$movedtoadmin ? '&toadmin=' . rawurlencode(implode(', ', self::$movedtoadmin)) : ''));
        exit;
    }

    /**
     * Handle the edit client form. When refused, the form shows again with what was typed.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool|void False when refused; otherwise ends with a redirect to the list and exit.
     */
    static function doUpdateClient()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        $client = isset($formvars['inClientID']) ? self::Mine($formvars['inClientID']) : false;
        if (!$client) {
            return false;
        }
        self::$failed = 'edit';
        // Only this reseller's own packages and groups
        if (empty($formvars['inPackage']) || !ctrl_ownership::Owns('x_packages', 'pk_id_pk', 'pk_reseller_fk', $formvars['inPackage'], ' AND pk_deleted_ts IS NULL')) {
            self::$packageblank = true;
            return false;
        }
        if (empty($formvars['inGroup']) || !self::AllowedGroup($formvars['inGroup'])) {
            self::$groupblank = true;
            return false;
        }
        if (self::ExecuteUpdateClient($client['id'], $formvars['inPackage'], !empty($formvars['inEnabled']) ? 1 : 0, $formvars['inGroup'], (string) ($formvars['inFullName'] ?? ''), (string) ($formvars['inEmailAddress'] ?? ''),
            (string) ($formvars['inAddress'] ?? ''), (string) ($formvars['inPostCode'] ?? ''), (string) ($formvars['inPhone'] ?? ''), (string) ($formvars['inNewPassword'] ?? ''))) {
            header('location: ./?module=' . $controller->GetCurrentModule() . '&saved=' . rawurlencode($client['user']));
            exit;
        }
        return false;
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
                // not a group above the account (ctrl_privilege): one of its own groups may still hold an
                // administrator's module, when it was handed one by an administrator
                return !ctrl_privilege::GroupAbove((int) $gid, (int) $currentuser['usergroupid']);
            }
        }
        return false;
    }

    /**
     * The groups the logged-in user may give a client.
     *
     * @return array<int, array<string, mixed>>|false One row per group: 'groupid', 'groupname', 'groupselected'; false when there are none.
     */
    static function getGroupList()
    {
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
     * Template value <@ MinPassLength @>: the shortest password allowed, and the one the form asks for.
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
     * @global runtime_controller $controller The request controller.
     * @return string|null The error or success message HTML, or null when there is none.
     */
    static function getResult()
    {
        global $controller;
        $err = function ($m) { return ui_sysmessage::shout(ui_language::translate($m), 'zannounceerror'); };
        if (!fs_director::CheckForEmptyValue(self::$userblank)) {
            return $err('Enter a user name for the new client.');
        }
        if (!fs_director::CheckForEmptyValue(self::$emailblank)) {
            return $err('Enter the client\'s email address: the panel sends its notices and password resets there.');
        }
        if (!fs_director::CheckForEmptyValue(self::$passwordblank)) {
            return $err('Enter a password, or use Make one.');
        }
        if (!fs_director::CheckForEmptyValue(self::$packageblank)) {
            return $err('Choose one of your packages for the client.');
        }
        if (!fs_director::CheckForEmptyValue(self::$groupblank)) {
            return $err('Choose a user group for the client.');
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return $err('The user name may have letters, digits and hyphens (not at the start or end), at most 63 characters.');
        }
        if (!fs_director::CheckForEmptyValue(self::$badpass)) {
            return $err('The password needs a lower case letter, a capital and a digit.');
        }
        if (!fs_director::CheckForEmptyValue(self::$bademail)) {
            return $err('That is not a valid email address.');
        }
        if (!fs_director::CheckForEmptyValue(self::$badpasswordlength)) {
            return ui_sysmessage::shout(sprintf(ui_language::translate('The password is too short: it needs at least %d characters.'), (int) self::getMinPassLength()), 'zannounceerror');
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return $err('An account with that user name already exists on this server.');
        }
        if (self::$tfreset !== null) {
            return ui_sysmessage::shout(str_replace('%s', '<strong>' . htmlspecialchars(self::$tfreset, ENT_QUOTES, 'UTF-8') . '</strong>',
                self::t(ctrl_twofactor::Forced() ? 'The two-factor login of %s has been reset: they set it up again at their next login.' : 'The two-factor login of %s has been reset: they log in with their password only, and can turn it on again in My Account.')), 'zannounceok');
        }
        if (!fs_director::CheckForEmptyValue(self::$not_unique_email)) {
            $owner = self::$email_owner;
            $msg = ($owner !== null && $owner !== '')
                ? str_replace('%s', '<strong>' . htmlspecialchars($owner, ENT_QUOTES, 'UTF-8') . '</strong>', self::t("This email address is already used by the account %s."))
                : ui_language::translate("This email address is already used by an account that is not one of yours.");
            return ui_sysmessage::shout($msg . ' ' . ui_language::translate("Each account needs its own email address."), "zannounceerror");
        }
        foreach (array('created' => 'The client %s is created. Its folders are ready within 5 minutes.', 'saved' => 'The client %s is saved.', 'deleted' => 'The client %s is deleted. Its websites, mail and databases are removed within the hour.') as $k => $m) {
            $who = $controller->GetControllerRequest('URL', $k);
            if ($who) {
                $to = $k === 'deleted' ? (string) $controller->GetControllerRequest('URL', 'toadmin') : '';
                return ui_sysmessage::shout(str_replace('%s', '<strong>' . self::h($who) . '</strong>', self::t($m))
                    . ($to !== '' ? ' ' . str_replace('%s', '<strong>' . self::h($to) . '</strong>', self::t('Its clients %s went to an administrator (you, when you are one), not to the account you chose: they have administrator rights that account does not have.')) : ''), 'zannounceok');
            }
        }
        return;
    }

    /**
     * Template flag <% if NoticesEnabled %>: the Client Notice Manager is switched on and the
     * logged-in account's group may use it, for the "Send notice" buttons.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return bool
     */
    static function getNoticesEnabled()
    {
        global $zdbh;
        static $on = null;
        if ($on === null) {
            $q = $zdbh->prepare("SELECT mo_id_pk FROM x_modules WHERE mo_folder_vc = 'client_notices' AND mo_enabled_en = 'true'");
            $q->execute();
            $mid = (int) $q->fetchColumn();
            $on = $mid > 0 && ctrl_groups::CheckGroupModulePermissions((int) ctrl_users::GetUserDetail()['usergroupid'], $mid);
        }
        return $on;
    }

    /**
     * Whether the Shadowing module is switched on, for the "log in as this client" buttons.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return bool
     */
    static function getShadowEnabled()
    {
        global $zdbh;
        static $on = null;
        if ($on === null) {
            $on = (int) $zdbh->query("SELECT COUNT(*) FROM x_modules WHERE mo_folder_vc = 'shadowing' AND mo_enabled_en = 'true'")->fetchColumn() > 0;
        }
        return $on;
    }

    // Webinterface sudo methods.
}
