<?php

/**
 * MySQL Databases: the API's database requests. The account must be the key's own or one it may reach
 * (ws_xmws::Reach()); a database's owner is looked up and checked the same way. New databases count
 * against the account's package (ws_data::UnderLimit()) and are named "<user name>_<name>", as on the
 * page.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class webservice extends ws_xmws
{

    /**
     * GetDatabases: <uid>. The account's databases: <id>, <name>, <sizebytes>, <users> (how many of
     * its MySQL users may use it).
     *
     * @return array
     */
    public function GetDatabases()
    {
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        $xml = "\n";
        foreach ((array) module_controller::ListDatabases($uid) as $db) {
            if (!is_array($db)) {
                continue;
            }
            $xml .= self::NewXMLContentSection('database', array('id' => $db['mysqlid'], 'name' => $db['mysqlname'],
                'sizebytes' => $db['mysqlsize'], 'users' => $db['totaldb']));
        }
        return $this->Answer($xml);
    }

    /**
     * CreateDatabase: <uid>, <name> (letters, digits and -, without the "<user name>_" the panel puts
     * before it; the whole name at most 32 characters). Answers its <databaseid> and full <name>.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function CreateDatabase()
    {
        global $zdbh;
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        // the package's limit, as the MySQL Databases page checks it
        if (!ws_data::UnderLimit($uid, 'mysql')) {
            return self::Error('1107', 'The account\'s package allows no more databases');
        }
        $user = ctrl_users::GetUserDetail($uid);
        $name = strtolower(str_replace(' ', '', (string) $this->Get('name')));
        if (!module_controller::ExecuteCreateDatabase($uid, $name)) {
            if (!empty(module_controller::$alreadyexists)) {
                return self::Error('1107', 'The account has a database of that name already');
            }
            if (!empty(module_controller::$blank)) {
                return self::Error('1107', 'name is needed');
            }
            if (!empty(module_controller::$badname)) {
                return self::Error('1107', 'name: letters, digits and - (not first or last), up to ' . max(0, 32 - strlen($user['username'] . '_')) . ' characters');
            }
            return self::Error('1107', 'The database could not be made');
        }
        $full = $user['username'] . '_' . $name;
        $q = $zdbh->prepare("SELECT my_id_pk FROM x_mysql_databases WHERE my_acc_fk = :u AND my_name_vc = :n AND my_deleted_ts IS NULL");
        $q->execute(array(':u' => $uid, ':n' => $full));
        $id = (int) $q->fetchColumn();
        self::$note = 'account ' . $uid . ', database ' . $id;
        return $this->Answer(self::NewXMLTag('created', 'true') . self::NewXMLTag('databaseid', $id) . self::NewXMLTag('name', $full));
    }

    /**
     * DeleteDatabase: <databaseid> (of an account the key may reach). The database and everything in
     * it are gone; its users stay, without it.
     *
     * @return array
     */
    public function DeleteDatabase()
    {
        $id = $this->Get('databaseid');
        if ($this->DatabaseOwner($id) === 0) {
            return self::Error('1107', 'No such database you may reach');
        }
        if (!module_controller::ExecuteDeleteDatabase((int) $id)) {
            return self::Error('1107', 'The database could not be deleted');
        }
        return $this->Answer(self::NewXMLTag('databaseid', (int) $id) . self::NewXMLTag('deleted', 'true'));
    }

    /**
     * The account a live database belongs to, when the key may reach it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param mixed $id The database ID as sent.
     * @return int The account, or 0.
     */
    private function DatabaseOwner($id)
    {
        global $zdbh;
        if (!is_scalar($id) || !ctype_digit((string) $id)) {
            return 0;
        }
        $q = $zdbh->prepare("SELECT my_acc_fk FROM x_mysql_databases WHERE my_id_pk = :id AND my_deleted_ts IS NULL");
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
