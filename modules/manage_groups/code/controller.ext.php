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
 * Manage Groups module: an administrator's or reseller's own user groups. A group decides which
 * modules its members can use: they are chosen here (a reseller only from the modules its own
 * group has, never the server administration ones; a new group starts with the Users group's
 * modules). The built-in groups (Administrators, Resellers, Users) are shown, not changed here;
 * the administrator sets their modules in Module Admin.
 */
class module_controller extends ctrl_module
{

    /**
     * The names of the built-in groups.
     */
    const BUILTIN = array('Administrators', 'Resellers', 'Users');

    /**
     * The longest group name (x_groups.ug_name_vc).
     */
    const MAX_NAME = 20;

    /**
     * Why the last save was refused, in English ('' when it was not).
     *
     * @var string
     */
    static $error = '';

    /**
     * The view after a refused save ('new' or 'edit', '' for none), so the form shows again with what was typed.
     *
     * @var string
     */
    static $failed = '';

    // The 'worker' methods.

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
     * The logged-in account.
     *
     * @return array<string, string> See ctrl_users::GetUserDetail().
     */
    static function Me()
    {
        return ctrl_users::GetUserDetail();
    }

    /**
     * Whether the logged-in account is an administrator.
     *
     * @return bool
     */
    static function IsAdmin()
    {
        return (int) self::Me()['usergroupid'] === 1;
    }

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
        $q = $zdbh->prepare("SELECT ug_id_pk, ug_name_vc, ug_notes_tx FROM x_groups WHERE ug_id_pk = :gid");
        $q->execute(array(':gid' => (int) $gid));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? array(array('groupid' => (int) $r['ug_id_pk'], 'groupname' => (string) $r['ug_name_vc'], 'groupdesc' => (string) $r['ug_notes_tx'])) : false;
    }

    /**
     * The groups of an account: its own ones, and (built-in) the default ones it owns, with how many
     * of the viewer's accounts are in each (deleted accounts not counted).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @param bool $builtin Optional. True: only the built-in groups; false: only the account's own. Default false.
     * @return array<int, array<string, mixed>>|false One row per group: 'groupid', 'groupname', 'groupdesc', 'usersingroup'; false when there are none.
     */
    static function ListGroups($uid, $builtin = false)
    {
        global $zdbh;
        $admin = (int) ctrl_users::GetUserDetail($uid)['usergroupid'] === 1;
        if ($builtin) {
            // a reseller sees the built-in groups too (its clients are in them), with only its own clients counted
            $q = $zdbh->prepare("SELECT * FROM x_groups WHERE ug_name_vc IN ('Administrators', 'Resellers', 'Users') AND ug_reseller_fk = 1 ORDER BY ug_id_pk");
            $q->execute();
        } else {
            $q = $zdbh->prepare("SELECT * FROM x_groups WHERE ug_reseller_fk = :uid AND NOT (ug_reseller_fk = 1 AND ug_name_vc IN ('Administrators', 'Resellers', 'Users')) ORDER BY ug_name_vc");
            $q->execute(array(':uid' => (int) $uid));
        }
        $count = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_group_fk = :g AND ac_deleted_ts IS NULL" . ($admin ? '' : ' AND ac_reseller_fk = :u'));
        $res = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $count->execute(array(':g' => (int) $r['ug_id_pk']) + ($admin ? array() : array(':u' => (int) $uid)));
            $res[] = array('groupid' => (int) $r['ug_id_pk'], 'groupname' => (string) $r['ug_name_vc'], 'groupdesc' => (string) $r['ug_notes_tx'], 'usersingroup' => (int) $count->fetchColumn());
        }
        return $res ? $res : false;
    }

    /**
     * The built-in groups (Administrators, Resellers, Users), with the viewer's accounts in each.
     *
     * @param int|string $uid The account ID.
     * @return array<int, array<string, mixed>>|false See ListGroups().
     */
    static function ListDefaultGroups($uid)
    {
        return self::ListGroups($uid, true);
    }

    /**
     * The groups the members of a deleted group can move to: the account's other groups and the built-in Users group.
     *
     * @param int|string $uid The account ID.
     * @param int|string $gid The group being deleted (left out).
     * @return array<int, array<string, mixed>>|false One row per group: 'groupid', 'groupname', 'groupdesc'; false when there are none.
     */
    static function GroupMoveTo($uid, $gid)
    {
        $res = array();
        foreach ((array) self::ListDefaultGroups($uid) as $g) {
            if ($g['groupname'] === 'Users') {
                $res[] = $g;
            }
        }
        foreach ((array) self::ListGroups($uid) as $g) {
            if ($g['groupid'] !== (int) $gid) {
                $res[] = $g;
            }
        }
        return $res ? $res : false;
    }

    /**
     * The modules a group of this account may be given, by category: user modules that are switched
     * on; for a reseller only the ones its own group has, and never the server administration ones.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array{name: string, modules: array<int, array{id: int, name: string, desc: string}>}> By category.
     */
    static function Grantable()
    {
        global $zdbh;
        static $out = null;
        if ($out !== null) {
            return $out;
        }
        $admin = self::IsAdmin();
        $q = $zdbh->prepare("SELECT m.mo_id_pk, m.mo_name_vc, m.mo_desc_tx, m.mo_category_fk, c.mc_name_vc FROM x_modules m LEFT JOIN x_modcats c ON c.mc_id_pk = m.mo_category_fk
            WHERE m.mo_type_en = 'user' AND m.mo_enabled_en = 'true'" . ($admin ? '' : " AND m.mo_category_fk <> 2 AND m.mo_id_pk IN (SELECT pe_module_fk FROM x_permissions WHERE pe_group_fk = :g)")
            . " ORDER BY m.mo_category_fk, m.mo_name_vc");
        $q->execute($admin ? array() : array(':g' => (int) self::Me()['usergroupid']));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
            $c = (int) $r[3];
            if (!isset($out[$c])) {
                $out[$c] = array('name' => (string) ($r[4] !== null ? $r[4] : 'Other'), 'modules' => array());
            }
            $out[$c]['modules'][] = array('id' => (int) $r[0], 'name' => (string) $r[1], 'desc' => (string) $r[2]);
        }
        return $out;
    }

    /**
     * The IDs of the modules a group has.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $gid The group ID.
     * @return array<int, int>
     */
    static function GroupModules($gid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT DISTINCT pe_module_fk FROM x_permissions WHERE pe_group_fk = :g");
        $q->execute(array(':g' => (int) $gid));
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Give a group the modules chosen, from those the account may give; the others it has (that the account
     * can not give, such as ones the administrator gave it) are kept.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $gid The group ID.
     * @param array<int, mixed> $chosen The module IDs ticked.
     * @return void
     */
    static function SetModules($gid, $chosen)
    {
        global $zdbh;
        $chosen = array_map('intval', (array) $chosen);
        foreach (self::Grantable() as $cat) {
            foreach ($cat['modules'] as $m) {
                if (in_array($m['id'], $chosen, true)) {
                    ctrl_groups::AddGroupModulePermissions($gid, $m['id']);
                } else {
                    ctrl_groups::DeleteGroupModulePermissions($gid, $m['id']);
                }
            }
        }
        // one of each (as Module Admin keeps them)
        $q = $zdbh->prepare("DELETE p1 FROM x_permissions p1 JOIN x_permissions p2 ON p1.pe_group_fk = p2.pe_group_fk AND p1.pe_module_fk = p2.pe_module_fk AND p1.pe_id_pk > p2.pe_id_pk WHERE p1.pe_group_fk = :g");
        $q->execute(array(':g' => (int) $gid));
    }

    /**
     * Check a group name: not empty, at most MAX_NAME characters, not a built-in group's name, and
     * not the name of another of the account's groups. Sets $error when it is not.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name The name.
     * @param int $uid The account ID.
     * @param int $gid Optional. The group being renamed (0 for a new one). Default 0.
     * @return bool
     */
    static function NameOk($name, $uid, $gid = 0)
    {
        global $zdbh;
        if ($name === '') {
            self::$error = 'Give the group a name.';
        } elseif (mb_strlen($name, 'UTF-8') > self::MAX_NAME) {
            self::$error = 'The name is too long: at most 20 characters.';
        } elseif (in_array(strtolower($name), array_map('strtolower', self::BUILTIN), true)) {
            self::$error = 'That is the name of a built-in group.';
        } else {
            $q = $zdbh->prepare("SELECT COUNT(*) FROM x_groups WHERE ug_reseller_fk = :u AND LOWER(ug_name_vc) = :n AND ug_id_pk <> :g");
            $q->execute(array(':u' => (int) $uid, ':n' => mb_strtolower($name, 'UTF-8'), ':g' => (int) $gid));
            if ((int) $q->fetchColumn() > 0) {
                self::$error = 'You already have a group with that name.';
            }
        }
        return self::$error === '';
    }

    /**
     * Create a group for an account (nothing happens when the name is not allowed; see NameOk()).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name The group name.
     * @param string $desc The description.
     * @param int|string $uid The account ID.
     * @return int|false The new group's ID, or false.
     */
    static function ExectuteCreateGroup($name, $desc, $uid)
    {
        global $zdbh;
        $name = trim((string) $name);
        if (!self::NameOk($name, (int) $uid)) {
            return false;
        }
        $sql = $zdbh->prepare("INSERT INTO x_groups (ug_name_vc, ug_notes_tx, ug_reseller_fk) VALUES (:name, :desc, :uid)");
        $sql->execute(array(':name' => $name, ':desc' => trim((string) $desc), ':uid' => (int) $uid));
        return (int) $zdbh->lastInsertId();
    }

    /**
     * Change a group's name and description (nothing happens when the name is not allowed).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $gid The group ID.
     * @param string $name The new name.
     * @param string $desc The new description.
     * @return bool
     */
    static function ExectuteUpdateGroup($gid, $name, $desc)
    {
        global $zdbh;
        $name = trim((string) $name);
        if (!self::NameOk($name, (int) self::Me()['userid'], (int) $gid)) {
            return false;
        }
        $sql = $zdbh->prepare("UPDATE x_groups SET ug_name_vc = :name, ug_notes_tx = :desc WHERE ug_id_pk = :groupid");
        $sql->execute(array(':name' => $name, ':desc' => trim((string) $desc), ':groupid' => (int) $gid));
        return true;
    }

    /**
     * Delete a group, moving its accounts to another group first, and its module permissions with it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $gid The group ID to delete.
     * @param int|string $mgid Optional. The group to move the accounts to. Default "" (the built-in Users group).
     * @return true
     */
    static function ExecuteDeleteGroup($gid, $mgid = "")
    {
        global $zdbh;
        if ((string) $mgid === '') {
            // its accounts never keep a group that no longer exists
            $mgid = (int) $zdbh->query("SELECT ug_id_pk FROM x_groups WHERE ug_name_vc = 'Users' AND ug_reseller_fk = 1 ORDER BY ug_id_pk LIMIT 1")->fetchColumn();
        }
        foreach (array("UPDATE x_accounts SET ac_group_fk = :mgid WHERE ac_group_fk = :gid", "UPDATE x_profiles SET ud_group_fk = :mgid WHERE ud_group_fk = :gid") as $sqlText) {
            $sql = $zdbh->prepare($sqlText);
            $sql->execute(array(':mgid' => (int) $mgid, ':gid' => (int) $gid));
        }
        foreach (array("DELETE FROM x_permissions WHERE pe_group_fk = :gid", "DELETE FROM x_groups WHERE ug_id_pk = :gid") as $sqlText) {
            $sql = $zdbh->prepare($sqlText);
            $sql->execute(array(':gid' => (int) $gid));
        }
        return true;
    }

    /**
     * The accounts in a group (the viewer's own clients; an administrator: all).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $gid The group ID.
     * @return array<int, string> User names.
     */
    static function Members($gid)
    {
        global $zdbh;
        $admin = self::IsAdmin();
        $q = $zdbh->prepare("SELECT ac_user_vc FROM x_accounts WHERE ac_group_fk = :g AND ac_deleted_ts IS NULL" . ($admin ? '' : ' AND ac_reseller_fk = :u') . " ORDER BY ac_user_vc");
        $q->execute(array(':g' => (int) $gid) + ($admin ? array() : array(':u' => (int) self::Me()['userid'])));
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    // End 'worker' methods.

    // Webinterface sudo methods.

    /**
     * Is this group one of the logged-in account's own groups (never a built-in one, nor another reseller's)?
     *
     * @param mixed $gid The group ID from the form or URL.
     * @return bool True when the current account owns the group.
     */
    static function OwnsGroup($gid)
    {
        if (!is_scalar($gid) || !preg_match('/^\d{1,10}$/', (string) $gid)) {
            return false;
        }
        foreach ((self::ListGroups(self::Me()['userid']) ?: array()) as $row) {
            if ($row['groupid'] === (int) $gid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is this a group the members of a deleted group may be moved to (one the page offers)?
     *
     * @param mixed $mgid The target group ID from the form.
     * @param mixed $gid The group being deleted.
     * @return bool True when the target is one the page offered.
     */
    static function ValidMoveTarget($mgid, $gid)
    {
        if (!is_scalar($mgid) || (string) $mgid === '') {
            return false;
        }
        foreach ((self::GroupMoveTo(self::Me()['userid'], $gid) ?: array()) as $row) {
            if ((string) $row['groupid'] === (string) $mgid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Handle the new group form: create the group with the modules ticked, then back to the list.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool|void False when refused (the form shows again); otherwise ends with a redirect and exit.
     */
    static function doCreateGroup()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $gid = self::ExectuteCreateGroup(isset($f['inGroupName']) ? $f['inGroupName'] : '', isset($f['inDesc']) ? $f['inDesc'] : '', self::Me()['userid']);
        if ($gid === false) {
            self::$failed = 'new';
            return false;
        }
        self::SetModules($gid, isset($f['inModules']) ? (array) $f['inModules'] : array());
        header('location: ./?module=' . $controller->GetCurrentModule() . '&saved=' . rawurlencode(trim((string) $f['inGroupName'])));
        exit;
    }

    /**
     * The old list's Edit and Delete buttons: redirect to that page and exit.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doEditGroup()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach ((self::ListGroups(self::Me()['userid']) ?: array()) as $row) {
            foreach (array('Delete', 'Edit') as $what) {
                if (isset($formvars['in' . $what . '_' . $row['groupid']])) {
                    header('location: ./?module=' . $controller->GetCurrentModule() . '&show=' . $what . '&other=' . $row['groupid']);
                    exit;
                }
            }
        }
    }

    /**
     * Handle the delete form: delete the posted group (inGroupID), moving its accounts to inMoveGroup.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool|void False when the group or target is not allowed; otherwise ends with a redirect and exit.
     */
    static function doDeleteGroup()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        $inMoveGroup = isset($formvars['inMoveGroup']) ? (string) $formvars['inMoveGroup'] : '';
        // Only the account's own group, and members only to a group the page offered
        $gid = isset($formvars['inGroupID']) ? $formvars['inGroupID'] : '';
        if (!self::OwnsGroup($gid) || ($inMoveGroup !== '' && !self::ValidMoveTarget($inMoveGroup, $gid))) {
            return false;
        }
        $name = self::GroupInfo($gid)[0]['groupname'];
        self::ExecuteDeleteGroup($gid, $inMoveGroup);
        header('location: ./?module=' . $controller->GetCurrentModule() . '&deleted=' . rawurlencode($name));
        exit;
    }

    /**
     * Handle the edit form: save the group's name, description and modules, then back to the list.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool|void False when refused (the form shows again); otherwise ends with a redirect and exit.
     */
    static function doUpdateGroup()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        // Only the account's own group (the ID comes from the form)
        if (!isset($f['inGroupID']) || !self::OwnsGroup($f['inGroupID'])) {
            return false;
        }
        if (!self::ExectuteUpdateGroup($f['inGroupID'], isset($f['inGroupName']) ? $f['inGroupName'] : '', isset($f['inDesc']) ? $f['inDesc'] : '')) {
            self::$failed = 'edit';
            return false;
        }
        self::SetModules((int) $f['inGroupID'], isset($f['inModules']) ? (array) $f['inModules'] : array());
        header('location: ./?module=' . $controller->GetCurrentModule() . '&saved=' . rawurlencode(trim((string) $f['inGroupName'])));
        exit;
    }

    /**
     * Which page shows: the list, the new group form, a group's edit form, or a delete confirmation.
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
        if ($show === 'New') {
            return 'new';
        }
        if (in_array($show, array('Edit', 'Delete'), true) && self::OwnsGroup($controller->GetControllerRequest('URL', 'other'))) {
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
     * Template flag <% if IsForm %>: the new group form or a group's edit form.
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
     * Template flag <% if IsAdmin %>: for the link to Module Admin.
     *
     * @return bool
     */
    static function getIsAdmin()
    {
        return self::IsAdmin();
    }

    /**
     * Module names of a group, as chips (at most $max, then "+N more").
     *
     * @param int $gid The group ID.
     * @param int $max Optional. How many to show. Default 8.
     * @return string The HTML.
     */
    static function ModuleChips($gid, $max = 8)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT m.mo_name_vc FROM x_permissions p JOIN x_modules m ON m.mo_id_pk = p.pe_module_fk WHERE p.pe_group_fk = :g AND m.mo_enabled_en = 'true' AND m.mo_type_en = 'user' GROUP BY m.mo_id_pk ORDER BY m.mo_name_vc");
        $q->execute(array(':g' => (int) $gid));
        $names = $q->fetchAll(PDO::FETCH_COLUMN);
        if (!$names) {
            return '<span class="mg-none">' . self::t('No modules: its members see an empty panel.') . '</span>';
        }
        $out = '';
        foreach (array_slice($names, 0, $max) as $n) {
            $out .= '<span class="mg-chip">' . self::t($n) . '</span>';
        }
        return $out . (count($names) > $max ? '<span class="mg-more">+' . (count($names) - $max) . ' ' . self::t('more') . '</span>' : '');
    }

    /**
     * One group as a card.
     *
     * @param array<string, mixed> $g The group (see ListGroups()).
     * @param bool $own Whether it is the account's own (Edit and Delete).
     * @return string The HTML.
     */
    static function Card($g, $own)
    {
        return '<div class="mg-card' . ($own ? '' : ' is-builtin') . '"><div class="mg-card-head"><span class="mg-ico-wrap">' . self::Icon($own ? 'group' : 'lock') . '</span>'
            . '<div class="mg-card-title"><b>' . self::h(ui_language::translate($g['groupname'])) . '</b><span>' . (int) $g['usersingroup'] . ' ' . self::t($g['usersingroup'] === 1 ? 'member' : 'members')
            . (!$own ? ' &middot; ' . self::t('built-in') : '') . '</span></div>'
            . ($own ? '<div class="mg-card-act"><a class="btn btn-default btn-sm" href="./?module=manage_groups&amp;show=Edit&amp;other=' . (int) $g['groupid'] . '">' . self::t('Edit') . '</a>'
                . '<a class="btn btn-default btn-sm mg-del" href="./?module=manage_groups&amp;show=Delete&amp;other=' . (int) $g['groupid'] . '" title="' . self::t('Delete') . '" aria-label="' . self::t('Delete') . ' ' . self::h($g['groupname']) . '">' . self::Icon('trash') . '</a></div>' : '')
            . '</div>' . ($g['groupdesc'] !== '' ? '<p class="mg-desc">' . self::h(ui_language::translate($g['groupdesc'])) . '</p>' : '')
            . '<div class="mg-chips">' . self::ModuleChips($g['groupid']) . '</div></div>';
    }

    /**
     * The account's own groups (<@ OwnGroups @>), as cards.
     *
     * @return string The HTML ('' when there are none).
     */
    static function getOwnGroups()
    {
        $out = '';
        foreach ((array) self::ListGroups(self::Me()['userid']) as $g) {
            $out .= self::Card($g, true);
        }
        return $out;
    }

    /**
     * Template flag <% if HasOwn %>: the account has groups of its own.
     *
     * @return bool
     */
    static function getHasOwn()
    {
        return (bool) self::ListGroups(self::Me()['userid']);
    }

    /**
     * Template flag <% if NoOwn %>.
     *
     * @return bool
     */
    static function getNoOwn()
    {
        return !self::getHasOwn();
    }

    /**
     * The built-in groups (<@ BuiltinGroups @>), as cards (a reseller: not Administrators).
     *
     * @return string The HTML.
     */
    static function getBuiltinGroups()
    {
        $out = '';
        foreach ((array) self::ListDefaultGroups(self::Me()['userid']) as $g) {
            if ($g['groupname'] === 'Administrators' && !self::IsAdmin()) {
                continue;
            }
            $out .= self::Card($g, false);
        }
        return $out;
    }

    /**
     * The form's values: what was typed after a refused save, the group being changed, or a new group (with the Users group's modules).
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return array{id: int, name: string, desc: string, modules: array<int, int>}
     */
    static function FormValues()
    {
        global $controller, $zdbh;
        static $v = null;
        if ($v !== null) {
            return $v;
        }
        $f = $controller->GetAllControllerRequests('FORM');
        if (self::$failed !== '') {
            $v = array('id' => (int) (isset($f['inGroupID']) ? $f['inGroupID'] : 0), 'name' => (string) (isset($f['inGroupName']) ? $f['inGroupName'] : ''),
                'desc' => (string) (isset($f['inDesc']) ? $f['inDesc'] : ''), 'modules' => array_map('intval', isset($f['inModules']) ? (array) $f['inModules'] : array()));
        } elseif (self::View() === 'edit') {
            $gid = (int) $controller->GetControllerRequest('URL', 'other');
            $g = self::GroupInfo($gid)[0];
            $v = array('id' => $gid, 'name' => $g['groupname'], 'desc' => $g['groupdesc'], 'modules' => self::GroupModules($gid));
        } else {
            $users = (int) $zdbh->query("SELECT ug_id_pk FROM x_groups WHERE ug_name_vc = 'Users' AND ug_reseller_fk = 1 ORDER BY ug_id_pk LIMIT 1")->fetchColumn();
            $v = array('id' => 0, 'name' => '', 'desc' => '', 'modules' => self::GroupModules($users));
        }
        return $v;
    }

    /**
     * <@ FormId @>: the group's ID in the edit form.
     *
     * @return int
     */
    static function getFormId()
    {
        return self::FormValues()['id'];
    }

    /**
     * <@ FormName @>: the name, escaped.
     *
     * @return string
     */
    static function getFormName()
    {
        return self::h(self::FormValues()['name']);
    }

    /**
     * <@ FormDesc @>: the description, escaped.
     *
     * @return string
     */
    static function getFormDesc()
    {
        return self::h(self::FormValues()['desc']);
    }

    /**
     * <@ FormTitle @>: the form's heading.
     *
     * @return string
     */
    static function getFormTitle()
    {
        return self::View() === 'edit' ? self::h(self::GroupInfo(self::FormValues()['id'])[0]['groupname']) : self::t('New group');
    }

    /**
     * <@ MaxName @>: the longest name.
     *
     * @return int
     */
    static function getMaxName()
    {
        return self::MAX_NAME;
    }

    /**
     * The modules to tick (<@ ModuleChoices @>), by category, each category with All and None.
     *
     * @return string The HTML.
     */
    static function getModuleChoices()
    {
        $have = self::FormValues()['modules'];
        $out = '';
        foreach (self::Grantable() as $cat) {
            $boxes = '';
            foreach ($cat['modules'] as $m) {
                $boxes .= '<label class="mg-mod" title="' . self::h(ui_language::translate($m['desc'])) . '"><input type="checkbox" name="inModules[]" value="' . $m['id'] . '"' . (in_array($m['id'], $have, true) ? ' checked' : '') . '>'
                    . '<span>' . self::t($m['name']) . '</span></label>';
            }
            $out .= '<fieldset class="mg-cat"><legend><span>' . self::t($cat['name']) . '</span><button type="button" class="mg-all" data-on="1">' . self::t('All') . '</button>'
                . '<button type="button" class="mg-all" data-on="0">' . self::t('None') . '</button></legend><div class="mg-mods">' . $boxes . '</div></fieldset>';
        }
        return $out;
    }

    /**
     * Who is in the group being edited (<@ MemberList @>).
     *
     * @return string The HTML.
     */
    static function getMemberList()
    {
        $m = self::Members(self::FormValues()['id']);
        if (!$m) {
            return '<p class="mg-sub">' . self::t('Nobody yet. Give a client this group in Manage Clients.') . '</p>';
        }
        $out = '';
        foreach ($m as $u) {
            $out .= '<span class="mg-member">' . self::h($u) . '</span>';
        }
        return '<p class="mg-sub">' . count($m) . ' ' . self::t(count($m) === 1 ? 'member' : 'members') . '</p><div class="mg-members">' . $out . '</div>';
    }

    /**
     * The group the delete confirmation is for (<@ DeleteName @>), escaped.
     *
     * @global runtime_controller $controller The request controller.
     * @return string
     */
    static function getDeleteName()
    {
        global $controller;
        $g = self::GroupInfo($controller->GetControllerRequest('URL', 'other'));
        return $g ? self::h($g[0]['groupname']) : '';
    }

    /**
     * The group's ID in the delete confirmation (<@ CurrentID @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int
     */
    static function getCurrentID()
    {
        global $controller;
        return (int) $controller->GetControllerRequest('URL', 'other');
    }

    /**
     * What deleting the group does (<@ DeleteDetails @>): where its members go.
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getDeleteDetails()
    {
        global $controller;
        $gid = (int) $controller->GetControllerRequest('URL', 'other');
        $n = count(self::Members($gid));
        if ($n === 0) {
            return '<p>' . self::t('It has no members. Its module choices go with it.') . '</p>';
        }
        $opts = '';
        foreach ((array) self::GroupMoveTo(self::Me()['userid'], $gid) as $g) {
            $opts .= '<option value="' . (int) $g['groupid'] . '">' . self::h(ui_language::translate($g['groupname'])) . '</option>';
        }
        return '<p>' . self::t('Its members keep their accounts and everything in them; they only get the modules of the group you move them to.') . '</p>'
            . '<div class="mg-move"><label for="inMoveGroup">' . self::t('Move its') . ' ' . $n . ' ' . self::t($n === 1 ? 'member to:' : 'members to:') . '</label>'
            . '<select class="form-control" name="inMoveGroup" id="inMoveGroup">' . $opts . '</select></div>';
    }

    /**
     * The message after an action (<@ Result @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML ('' for none).
     */
    static function getResult()
    {
        global $controller;
        if (self::$error !== '') {
            return ui_sysmessage::shout(ui_language::translate(self::$error), 'zannounceerror');
        }
        foreach (array('saved' => 'is saved.', 'deleted' => 'is deleted.') as $k => $m) {
            $who = $controller->GetControllerRequest('URL', $k);
            if ($who) {
                return ui_sysmessage::shout(ui_language::translate('The group') . ' <strong>' . self::h($who) . '</strong> ' . ui_language::translate($m), 'zannounceok');
            }
        }
        return '';
    }

    /**
     * An inline line icon.
     *
     * @param string $name 'group', 'lock', 'trash' or 'plus'.
     * @return string The SVG.
     */
    static function Icon($name)
    {
        $p = array(
            'group' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"/>',
            'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
            'trash' => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
        );
        return '<svg class="mg-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . (isset($p[$name]) ? $p[$name] : '') . '</svg>';
    }

    // Webinterface sudo methods.
}
