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
 * Manage Groups module (resellers): create, edit and delete the reseller's user groups.
 *
 * The built-in groups (Administrators, Resellers, Users) are listed separately and
 * cannot be edited here.
 */
class module_controller extends ctrl_module
{

    // The 'worker' methods.

    /**
     * Get one group by its ID.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $gid The group ID.
     * @return array<int, array<string, mixed>>|false A list with the one row ('groupid', 'groupname', 'groupdesc'), or false when not found.
     */
    static function GroupInfo($gid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_groups WHERE ug_id_pk=:gid";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':gid', $gid);
        $numrows->execute();
        //$numrows = $zdbh->query($sql);
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':gid', $gid);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                array_push($res, array('groupid' => $rowgroups['ug_id_pk'], 'groupname' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_name_vc'])), 'groupdesc' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_notes_tx']))));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List a reseller's own groups (not the built-in ones), with the number of accounts in each.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The reseller's account ID.
     * @return array<int, array<string, mixed>>|false One row per group: 'groupid', 'groupname', 'groupdesc', 'usersingroup'; false when the reseller has no groups at all.
     */
    static function ListGroups($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_groups WHERE ug_reseller_fk=:uid";
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
                if ($rowgroups['ug_name_vc'] != "Administrators" &&
                        $rowgroups['ug_name_vc'] != "Resellers" &&
                        $rowgroups['ug_name_vc'] != "Users") {
                    $noaccs = "SELECT COUNT(*) AS total FROM x_accounts WHERE ac_group_fk=" . $rowgroups['ug_id_pk'] . "";
                    $totalnoaccs = $zdbh->query($noaccs)->fetch();
                    array_push($res, array('groupid' => $rowgroups['ug_id_pk'], 'groupname' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_name_vc'])), 'groupdesc' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_notes_tx'])), 'usersingroup' => runtime_xss::xssClean($totalnoaccs['total'])));
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List a reseller's built-in groups (Administrators, Resellers, Users), with the number of accounts in each.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The reseller's account ID.
     * @return array<int, array<string, mixed>>|false One row per group: 'groupid', 'groupname', 'groupdesc', 'usersingroup'; false when the reseller has no groups at all.
     */
    static function ListDefaultGroups($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_groups WHERE ug_reseller_fk=:uid";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        //$numrows = $zdbh->query($sql);
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                if ($rowgroups['ug_name_vc'] == "Administrators" ||
                        $rowgroups['ug_name_vc'] == "Resellers" ||
                        $rowgroups['ug_name_vc'] == "Users") {
                    $noaccs = "SELECT COUNT(*) AS total FROM x_accounts WHERE ac_group_fk=" . $rowgroups['ug_id_pk'] . "";
                    $totalnoaccs = $zdbh->query($noaccs)->fetch();
                    array_push($res, array('groupid' => $rowgroups['ug_id_pk'], 'groupname' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_name_vc'])), 'groupdesc' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_notes_tx'])), 'usersingroup' => runtime_xss::xssClean($totalnoaccs['total'])));
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List a reseller's groups other than one, to move that group's accounts to.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The reseller's account ID.
     * @param int|string $gid The group ID to leave out.
     * @return array<int, array<string, mixed>>|false One row per group: 'groupid', 'groupname', 'groupdesc'; false when there are none.
     */
    static function GroupMoveTo($uid, $gid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_groups WHERE ug_reseller_fk=:uid AND ug_id_pk <> :gid";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->bindParam(':gid', $gid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $sql->bindParam(':gid', $gid);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                array_push($res, array('groupid' => $rowgroups['ug_id_pk'], 'groupname' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_name_vc'])), 'groupdesc' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_notes_tx']))));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Create a group for a reseller (nothing happens when the name is empty).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name The group name.
     * @param string $desc The description.
     * @param int|string $uid The reseller's account ID.
     * @return true
     */
    static function ExectuteCreateGroup($name, $desc, $uid)
    {
        global $zdbh;
        if (!fs_director::CheckForEmptyValue($name)) {
            $sql = $zdbh->prepare("INSERT INTO x_groups (ug_name_vc, ug_notes_tx, ug_reseller_fk) VALUES (:name, :desc, :uid)");
            $sql->bindParam(':name', $name);
            $sql->bindParam(':desc', $desc);
            $sql->bindParam(':uid', $uid);
            $sql->execute();
        }
        return true;
    }

    /**
     * Change a group's name and description.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $gid The group ID.
     * @param string $name The new name.
     * @param string $desc The new description.
     * @return true
     */
    static function ExectuteUpdateGroup($gid, $name, $desc)
    {
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_groups SET ug_name_vc = :name, ug_notes_tx = :desc WHERE ug_id_pk = :groupid");
        $sql->bindParam(':name', $name);
        $sql->bindParam(':desc', $desc);
        $sql->bindParam(':groupid', $gid);
        $sql->execute();
        return true;
    }

    /**
     * Delete a group, first moving its accounts to another group when one is given.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $gid The group ID to delete.
     * @param int|string $mgid Optional. The group to move the accounts to. Default "" (do not move).
     * @return true
     */
    static function ExecuteDeleteGroup($gid, $mgid = "")
    {
        global $zdbh;
        if ($mgid != "") {
            $sql = $zdbh->prepare("
            UPDATE x_accounts
            SET ac_group_fk = :mgid
            WHERE ac_group_fk = :gid");
            $sql->bindParam(':mgid', $mgid);
            $sql->bindParam(':gid', $gid);
            $sql->execute();
            $sql = $zdbh->prepare("
            DELETE FROM x_groups
            WHERE ug_id_pk = :gid");
            $sql->bindParam(':gid', $gid);
            $sql->execute();
            return true;
        } else {
            $sql = $zdbh->prepare("
            DELETE FROM x_groups
            WHERE ug_id_pk = :gid");
            $sql->bindParam(':gid', $gid);
            $sql->execute();
            return true;
        }
    }

    // End 'worker' methods.

    // Webinterface sudo methods.

    /**
     * The reseller's own groups (template <% loop GroupList %>).
     *
     * @global runtime_controller $controller The request controller (unused).
     * @return array<int, array<string, mixed>>|false See ListGroups().
     */
    static function getGroupList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListGroups($currentuser['userid']);
    }

    /**
     * The reseller's built-in groups (template <% loop DefaultGroupList %>).
     *
     * @global runtime_controller $controller The request controller (unused).
     * @return array<int, array<string, mixed>>|false See ListDefaultGroups().
     */
    static function getDefaultGroupList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListDefaultGroups($currentuser['userid']);
    }

    /**
     * The groups the accounts of the group in the URL ('other') can be moved to (template <% loop GroupMoveToList %>).
     *
     * @global runtime_controller $controller The request controller.
     * @return array<int, array<string, mixed>>|false See GroupMoveTo().
     */
    static function getGroupMoveToList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $urlvars = $controller->GetAllControllerRequests('URL');
        return self::GroupMoveTo($currentuser['userid'], $urlvars['other']);
    }

    /**
     * Handle the create form: create the posted group for the logged-in reseller.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool Always true (ExectuteCreateGroup() always returns true).
     */
    static function doCreateGroup()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExectuteCreateGroup($formvars['inGroupName'], $formvars['inDesc'], $currentuser['userid'])) {
            return true;
        } else {
            return false;
        }
        return;
    }

    /**
     * Handle the buttons of the group list: redirect to the delete or edit page and exit.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doEditGroup()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListGroups($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['groupid'] . ''])) {
                header("location: ./?module=" . runtime_xss::xssClean($controller->GetCurrentModule()) . "&show=Delete&other=" . runtime_xss::xssClean($row['groupid']) . "");
                exit;
            }
            if (isset($formvars['inEdit_' . $row['groupid'] . ''])) {
                header("location: ./?module=" . runtime_xss::xssClean($controller->GetCurrentModule()) . "&show=Edit&other=" . runtime_xss::xssClean($row['groupid']) . "");
                exit;
            }
        }
        return;
    }

    /**
     * Handle the delete form: delete the posted group (inGroupID), moving its accounts to inMoveGroup.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function doDeleteGroup()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inMoveGroup'])) {
            $inMoveGroup = $formvars['inMoveGroup'];
        } else {
            $inMoveGroup = "";
        }
        if (self::ExecuteDeleteGroup($formvars['inGroupID'], $inMoveGroup))
            return true;
        return false;
    }

    /**
     * Handle the edit form: save the posted group's name and description.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function doUpdateGroup()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExectuteUpdateGroup($formvars['inGroupID'], $formvars['inGroupName'], $formvars['inDesc']))
            return true;
        return false;
    }

    /**
     * Whether to show the create form: no 'show' in the URL (template <% if isCreateGroup %>).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisCreateGroup()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (!isset($urlvars['show']))
            return true;
        return false;
    }

    /**
     * Whether to show the delete page (template <% if isDeleteGroup %>).
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|null $uid Optional. The reseller's account ID; null for the logged-in account. Default null.
     * @return bool|null Null when the reseller has no groups.
     */
    static function getisDeleteGroup($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete Group Account.
        // This shall avoid exposing Group based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

        $sql = " SELECT * FROM x_groups WHERE ug_reseller_fk=:userid";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }

        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Delete");
    }

    /**
     * Whether to show the edit page (template <% if isEditGroup %>).
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|null $uid Optional. The reseller's account ID; null for the logged-in account. Default null.
     * @return bool|null Null when the reseller has no groups.
     */
    static function getisEditGroup($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars     = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Edit Group Account.
        // This shall avoid exposing Group based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

        $sql = " SELECT * FROM x_groups WHERE ug_reseller_fk=:userid";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }

        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Edit");
    }

    /**
     * The ID of the group in the URL ('other') (template <@ CurrentID @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string The group ID, or '' when there is no 'other'.
     */
    static function getCurrentID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::GroupInfo($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['groupid'];
        } else {
            return "";
        }
    }

    /**
     * The name of the group in the URL ('other') (template <@ EditCurrentName @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The name, or '' when there is no 'other'.
     */
    static function getEditCurrentName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::GroupInfo($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['groupname'];
        } else {
            return "";
        }
    }

    /**
     * The description of the group in the URL ('other') (template <@ EditCurrentDesc @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The description, or '' when there is no 'other'.
     */
    static function getEditCurrentDesc()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::GroupInfo($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['groupdesc'];
        } else {
            return "";
        }
    }

    // Webinterface sudo methods.
}
