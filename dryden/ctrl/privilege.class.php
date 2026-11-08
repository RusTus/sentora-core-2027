<?php

/**
 * Which accounts are "above" a reseller: ones it must not shadow, edit, or be handed.
 *
 * A reseller manages its own clients (Manage Clients, Shadowing). An account in the Administrators
 * group, or in a group with an administrator's module (one in Server Admin, or a "modadmin" module
 * such as Apache Config or PHP Versions) that the reseller's own group does not have, would give the
 * reseller more than it has: shadowing it, or setting its password, is taking over an administrator.
 * Such an account can become a reseller's client when an administrator deletes an account and hands
 * its clients to the reseller (Manage Clients > Delete), so it is checked wherever it matters.
 *
 * An administrator (group 1) is above nobody's limits here: it may manage every account.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-07
 */
class ctrl_privilege
{
    /**
     * SQL that is true when the group in column $groupCol is above the group $viewerGroup.
     *
     * @param string $groupCol A group ID column of the query (letters, digits, _ and . only).
     * @param int $viewerGroup The viewer's group ID.
     * @return string The SQL (in brackets); '(0)' for an administrator viewer.
     */
    static function AboveSql($groupCol, $viewerGroup)
    {
        $viewerGroup = (int) $viewerGroup;
        if ($viewerGroup === 1 || !preg_match('/^[A-Za-z0-9_.]+$/', $groupCol)) {
            return $viewerGroup === 1 ? '(0)' : '(1)';
        }
        return "($groupCol = 1 OR EXISTS (SELECT 1 FROM x_permissions pa JOIN x_modules ma ON ma.mo_id_pk = pa.pe_module_fk
            WHERE pa.pe_group_fk = $groupCol AND (ma.mo_category_fk = 2 OR ma.mo_type_en <> 'user')
            AND pa.pe_module_fk NOT IN (SELECT pv.pe_module_fk FROM x_permissions pv WHERE pv.pe_group_fk = $viewerGroup)))";
    }

    /**
     * Is group $group above group $viewerGroup?
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $group The group ID.
     * @param int $viewerGroup The viewer's group ID.
     * @return bool
     */
    static function GroupAbove($group, $viewerGroup)
    {
        global $zdbh;
        if ((int) $viewerGroup === 1) {
            return false;
        }
        // (the group's number stands where AboveSql expects a column)
        return (int) $zdbh->query("SELECT " . self::AboveSql((string) (int) $group, $viewerGroup))->fetchColumn() === 1;
    }

    /**
     * Is account $accountId above group $viewerGroup?
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $accountId The account (x_accounts) ID.
     * @param int $viewerGroup The viewer's group ID.
     * @return bool True also when the account does not exist.
     */
    static function AccountAbove($accountId, $viewerGroup)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT ac_group_fk FROM x_accounts WHERE ac_id_pk = :id");
        $q->execute(array(':id' => (int) $accountId));
        $g = $q->fetchColumn();
        return $g === false ? true : self::GroupAbove((int) $g, $viewerGroup);
    }

    /**
     * An administrator's account to hand accounts and groups to that a reseller may not have:
     * the logged-in account when it is an administrator, else the first administrator.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return int The account ID.
     */
    static function AdminAccount()
    {
        global $zdbh;
        $me = ctrl_users::GetUserDetail();
        if ($me && (int) $me['usergroupid'] === 1) {
            return (int) $me['userid'];
        }
        $id = (int) $zdbh->query("SELECT ac_id_pk FROM x_accounts WHERE ac_group_fk = 1 AND ac_enabled_in = 1 AND ac_deleted_ts IS NULL ORDER BY ac_id_pk LIMIT 1")->fetchColumn();
        return $id > 0 ? $id : 1;
    }
}
