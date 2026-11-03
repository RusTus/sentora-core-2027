<?php

/**
 * Ownership checks for panel actions.
 *
 * Many modules took a record id from the submitted form (inDelete, inSave,
 * inUser...) and acted on it without checking whose it was, so any account
 * could delete or change another account's domains, databases, mailboxes and
 * so on by sending a different id. Every such action now asks this first.
 */
class ctrl_ownership
{

    /**
     * Does the logged-in account own row $id of $table?
     * $table, $idField and $ownerField are fixed names from the calling code.
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

}

?>
