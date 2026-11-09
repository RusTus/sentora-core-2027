<?php

/**
 * MySQL Users: the API's database user requests. The account must be the key's own or one it may
 * reach (ws_xmws::Reach()); a user's owner is looked up and checked the same way, and a user and a
 * database must always belong to the same account. Names get the "<user name>_" prefix, as on the
 * page; a new user's password is made by the panel and answered once (the panel keeps only an
 * encrypted copy). Passwords are never in any other answer.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class webservice extends ws_xmws
{

    /**
     * GetDatabaseUsers: <uid>. The account's MySQL users: <id>, <name>, <access> ("%" any address,
     * "localhost" or an IP address), and a <database> (<id>, <name>) for each one it may use.
     *
     * @return array
     */
    public function GetDatabaseUsers()
    {
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        $xml = "\n";
        foreach ((array) module_controller::ListUsers($uid) as $u) {
            if (!is_array($u)) {
                continue;
            }
            $xml .= "\t<user>\n" . self::NewXMLTag('id', $u['userid']) . self::NewXMLTag('name', $u['username']) . self::NewXMLTag('access', $u['access']);
            foreach ((array) module_controller::ListUserDatabases($u['userid']) as $m) {
                if (is_array($m)) {
                    $xml .= "\t<database>\n" . self::NewXMLTag('id', $m['mmdbid']) . self::NewXMLTag('name', $m['mmdbname']) . "\t</database>\n";
                }
            }
            $xml .= "\t</user>\n";
        }
        return $this->Answer($xml);
    }

    /**
     * CreateDatabaseUser: <uid>, <name> (letters, digits, - and _, without the "<user name>_" the panel
     * puts before it; the whole name at most 32 characters), <databaseid> (one of the account's own
     * databases, the first it may use), <access>: "any" (the default), "localhost" (this server only)
     * or an IP address. Answers its <userid>, full <name> and <password> (shown this once).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function CreateDatabaseUser()
    {
        global $zdbh;
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        $db = $this->Get('databaseid');
        if (!module_controller::AccountOwnsDatabase($uid, (string) $db)) {
            return self::Error('1107', 'databaseid is not one of the account\'s databases');
        }
        $access = strtolower(trim((string) $this->Get('access')));
        if ($access === '' || $access === 'any' || $access === '%') {
            $access = '%';
        } elseif ($access !== 'localhost' && !sys_monitoring::IsAnyValidIP($access)) {
            return self::Error('1107', 'access: "any", "localhost" or an IP address');
        }
        $name = (string) $this->Get('name');
        if (!module_controller::ExecuteCreateUser($uid, $name, (int) $db, $access)) {
            $why = array('alreadyexists' => 'That user name is taken', 'rootabuse' => 'That user name is not allowed', 'blank' => 'name is needed',
                'badname' => 'name: letters, digits, - and _ (not - first or last); the whole name with the prefix at most 32 characters',
                'badIP' => 'access: "any", "localhost" or an IP address');
            foreach ($why as $flag => $text) {
                if (!empty(module_controller::$$flag)) {
                    return self::Error('1107', $text);
                }
            }
            return self::Error('1107', 'The user could not be made');
        }
        $full = (string) module_controller::$newuser;
        $q = $zdbh->prepare("SELECT mu_id_pk FROM x_mysql_users WHERE mu_acc_fk = :u AND mu_name_vc = :n AND mu_deleted_ts IS NULL");
        $q->execute(array(':u' => $uid, ':n' => $full));
        $id = (int) $q->fetchColumn();
        self::$note = 'account ' . $uid . ', MySQL user ' . $id;
        return $this->Answer(self::NewXMLTag('created', 'true') . self::NewXMLTag('userid', $id) . self::NewXMLTag('name', $full)
            . self::NewXMLTag('password', (string) module_controller::$newpass));
    }

    /**
     * DeleteDatabaseUser: <userid> (of an account the key may reach).
     *
     * @return array
     */
    public function DeleteDatabaseUser()
    {
        $id = $this->Get('userid');
        if ($this->UserOwner($id) === 0) {
            return self::Error('1107', 'No such MySQL user you may reach');
        }
        module_controller::ExecuteDeleteUser((int) $id);
        return $this->Answer(self::NewXMLTag('userid', (int) $id) . self::NewXMLTag('deleted', 'true'));
    }

    /**
     * SetDatabaseUserPassword: <userid>, <password> (the panel's rules: its minimum length, with a digit,
     * a small and a capital letter).
     *
     * @return array
     */
    public function SetDatabaseUserPassword()
    {
        $id = $this->Get('userid');
        if ($this->UserOwner($id) === 0) {
            return self::Error('1107', 'No such MySQL user you may reach');
        }
        if (!module_controller::ExecuteResetPassword((int) $id, (string) $this->Get('password'))) {
            if (!empty(module_controller::$badpasswordlength)) {
                return self::Error('1107', 'The password needs at least ' . (int) module_controller::getMinPassLength() . ' characters');
            }
            return self::Error('1107', 'The password needs a digit, a small and a capital letter');
        }
        return $this->Answer(self::NewXMLTag('userid', (int) $id) . self::NewXMLTag('changed', 'true'));
    }

    /**
     * GrantDatabase: <userid>, <databaseid> (both the same account's). The user may then use the database.
     *
     * @return array
     */
    public function GrantDatabase()
    {
        $id = $this->Get('userid');
        $owner = $this->UserOwner($id);
        if ($owner === 0) {
            return self::Error('1107', 'No such MySQL user you may reach');
        }
        $db = $this->Get('databaseid');
        if (!module_controller::AccountOwnsDatabase($owner, (string) $db)) {
            return self::Error('1107', 'databaseid is not a database of the same account');
        }
        if (!module_controller::ExecuteAddDB($owner, (int) $id, (int) $db)) {
            return self::Error('1107', !empty(module_controller::$dbalreadyadded) ? 'The user may use that database already' : 'The access could not be given');
        }
        return $this->Answer(self::NewXMLTag('userid', (int) $id) . self::NewXMLTag('databaseid', (int) $db) . self::NewXMLTag('granted', 'true'));
    }

    /**
     * RevokeDatabase: <userid>, <databaseid>. The user may no longer use the database.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function RevokeDatabase()
    {
        global $zdbh;
        $id = $this->Get('userid');
        $owner = $this->UserOwner($id);
        if ($owner === 0) {
            return self::Error('1107', 'No such MySQL user you may reach');
        }
        $db = $this->Get('databaseid');
        $q = $zdbh->prepare("SELECT mm_id_pk FROM x_mysql_dbmap WHERE mm_user_fk = :u AND mm_database_fk = :d AND mm_acc_fk = :a");
        $q->execute(array(':u' => (int) $id, ':d' => is_scalar($db) && ctype_digit((string) $db) ? (int) $db : 0, ':a' => $owner));
        $map = (int) $q->fetchColumn();
        if ($map === 0) {
            return self::Error('1107', 'The user may not use that database');
        }
        module_controller::ExecuteRemoveDB((int) $id, $map);
        return $this->Answer(self::NewXMLTag('userid', (int) $id) . self::NewXMLTag('databaseid', (int) $db) . self::NewXMLTag('revoked', 'true'));
    }

    /**
     * The account a live MySQL user belongs to, when the key may reach it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param mixed $id The MySQL user ID as sent.
     * @return int The account, or 0.
     */
    private function UserOwner($id)
    {
        global $zdbh;
        if (!is_scalar($id) || !ctype_digit((string) $id)) {
            return 0;
        }
        $q = $zdbh->prepare("SELECT mu_acc_fk FROM x_mysql_users WHERE mu_id_pk = :id AND mu_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $id));
        $owner = $q->fetchColumn();
        return $owner === false ? 0 : self::Reach((string) (int) $owner);
    }

    /**
     * A done answer.
     *
     * @param string $content The XML content.
     * @return array
     */
    private function Answer($content)
    {
        $d = new runtime_dataobject();
        $d->addItemValue('response', '');
        $d->addItemValue('content', $content);
        return $d->getDataObject();
    }
}
