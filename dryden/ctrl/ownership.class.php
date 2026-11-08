<?php

/**
 * Ownership checks for panel actions.
 *
 * Many modules took a record id from the submitted form (inDelete, inSave,
 * inUser...) and acted on it without checking whose it was, so any account
 * could delete or change another account's domains, databases, mailboxes and
 * so on by sending a different id. Every such action now asks this first.
 *
 * Written by RusTus, 2026-11-06
 */
class ctrl_ownership
{

    /**
     * Does the logged-in account own row $id of $table?
     *
     * $table, $idField and $ownerField are fixed names from the calling code.
     * @global db_driver $zdbh The panel's database connection.
     * @param string $table The table name (lowercase letters and _ only).
     * @param string $idField The table's ID column.
     * @param string $ownerField The column holding the owner's account ID.
     * @param int|string $id The record ID from the form (digits only, else false).
     * @param string $extraWhere Optional. SQL appended to the WHERE clause as is (e.g. " AND x_deleted_ts IS NULL"). Default ''.
     * @return bool True when the record exists and belongs to the logged-in account.
     */
    static function Owns($table, $idField, $ownerField, $id, $extraWhere = '')
    {
        global $zdbh;
        if (!preg_match('/^[a-z_]+$/', $table . $idField . $ownerField) || !preg_match('/^[0-9]{1,10}$/', (string) $id)) {
            return false;
        }
        $currentuser = ctrl_users::GetUserDetail();
        if (!$currentuser || empty($currentuser['userid'])) {
            return false;
        }
        $sql = $zdbh->prepare("SELECT COUNT(*) FROM " . $table . " WHERE " . $idField . " = :id AND " . $ownerField . " = :uid" . $extraWhere);
        $sql->bindValue(':id', (int) $id);
        $sql->bindValue(':uid', (int) $currentuser['userid']);
        $sql->execute();
        return (int) $sql->fetchColumn() > 0;
    }


    /**
     * A folder chosen in a form ("use an existing folder" for a domain, subdomain or
     * FTP account): plain folder names only, no "..", so it can never point outside
     * the account's own folder (it used to: "../../otheraccount/public_html").
     * @param string $path The folder from the form, relative to the account's home.
     * @return string|false The path without leading/trailing slashes ('' when empty), or false when not allowed.
     */
    static function SafeSubFolder($path)
    {
        $path = trim((string) $path, '/');
        if ($path === '') {
            return '';
        }
        if (strlen($path) > 255 || !preg_match('#^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$#', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '.' || $part === '..') {
                return false;
            }
        }
        return $path;
    }

}

?>
