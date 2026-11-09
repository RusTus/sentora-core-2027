<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * @package zpanelx
 * @subpackage modules
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Manage Clients: the API's client requests. Every account a request names must be one the key may
 * reach (ws_xmws::Target(): a reseller's key only its own clients, never an administrator to change);
 * packages must be the client's reseller's own; groups never Administrators.
 *
 * Updated by RusTus, 2026-11-09
 */
class webservice extends ws_xmws
{

    /**
     * The reason the page's create or update function gave for refusing (its flags).
     *
     * @return string
     */
    private static function Why()
    {
        $why = array(
            'alreadyexists' => 'That user name is taken', 'badname' => 'The user name may have letters, digits, - and _ only',
            'userblank' => 'A user name is needed', 'passwordblank' => 'A password is needed',
            'badpasswordlength' => 'The password is too short', 'badpass' => 'The password has characters that are not allowed',
            'emailblank' => 'An email address is needed', 'bademail' => 'That is not an email address',
            'not_unique_email' => 'Another account has that email address', 'packageblank' => 'No such package', 'groupblank' => 'No such group',
        );
        foreach ($why as $flag => $text) {
            if (!empty(module_controller::$$flag)) {
                return $text;
            }
        }
        return 'The panel refused it';
    }

    /**
     * DeleteClient: <uid>, optional <moveid> (who gets its clients, packages and groups; by default
     * its reseller).
     *
     * @return array
     */
    function DeleteClient()
    {
        $uid = self::Target($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may delete (never an administrator)');
        }
        $moveid = 0;
        if ((string) $this->Get('moveid') !== '') {
            $moveid = (int) $this->Get('moveid') === self::$actor ? self::$actor : self::Target($this->Get('moveid'), true);
            if ($moveid === 0 || $moveid === $uid) {
                return self::Error('1107', 'moveid is not an account you may hand them to (yourself, or one of your clients other than the one deleted)');
            }
        } elseif (!self::$admin) {
            // a reseller's key hands them to the reseller, never further up
            $moveid = self::$actor;
        }
        module_controller::ExecuteDeleteClient($uid, $moveid);
        return $this->Answer(self::NewXMLTag('uid', $uid) . self::NewXMLTag('deleted', 'true'));
    }

    /**
     * EnableClient: <uid> (switched on again; a reason given by SuspendClient is cleared).
     *
     * @return array
     */
    function EnableClient()
    {
        $uid = self::Target($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may switch on');
        }
        module_controller::EnableClient($uid);
        return $this->Answer(self::NewXMLTag('uid', $uid) . self::NewXMLTag('enabled', 'true'));
    }

    /**
     * DisableClient: <uid>.
     *
     * @return array
     */
    function DisableClient()
    {
        $uid = self::Target($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may switch off (never an administrator)');
        }
        module_controller::DisableClient($uid);
        return $this->Answer(self::NewXMLTag('uid', $uid) . self::NewXMLTag('disabled', 'true'));
    }

    /**
     * SuspendClient: <uid>, <reason> (one line, up to 200 characters, such as "not paid"). Switched
     * off like DisableClient; GetClient shows the reason until it is switched on again.
     *
     * @return array
     */
    function SuspendClient()
    {
        $uid = self::Target($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may suspend (never an administrator)');
        }
        $reason = ws_suspend::Clean((string) $this->Get('reason'));
        if ($reason === false || $reason === '') {
            return self::Error('1107', 'reason: one line of up to 200 characters, without < or >');
        }
        module_controller::DisableClient($uid);
        ws_suspend::Set($uid, $reason, self::$actor);
        return $this->Answer(self::NewXMLTag('uid', $uid) . self::NewXMLTag('suspended', 'true') . self::NewXMLTag('reason', $reason));
    }

    /**
     * UnsuspendClient: <uid> (EnableClient, by its billing name).
     *
     * @return array
     */
    function UnsuspendClient()
    {
        $uid = self::Target($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may switch on');
        }
        module_controller::EnableClient($uid);
        return $this->Answer(self::NewXMLTag('uid', $uid) . self::NewXMLTag('suspended', 'false'));
    }

    /**
     * GetAllClients: <uid> (the reseller whose clients; by default the key's own account).
     *
     * @return array
     */
    public function GetAllClients()
    {
        $owner = (string) $this->Get('uid') === '' || (int) $this->Get('uid') === self::$actor ? self::$actor : self::Target($this->Get('uid'), true);
        if ($owner === 0) {
            return self::Error('1107', 'No such account whose clients you may list');
        }
        $xml = "\n";
        foreach ((array) module_controller::ListClients($owner) as $client) {
            if (!is_array($client)) {
                continue;
            }
            // not the password hash, nor the page's own buttons
            unset($client['password'], $client['tfreset']);
            $xml .= self::NewXMLContentSection('client', $client);
        }
        return $this->Answer($xml);
    }

    /**
     * GetClient: <uid>. Its details (never its password hash), its package, and why it was switched
     * off when the API gave a reason.
     *
     * @return array
     */
    public function GetClient()
    {
        $uid = self::Target($this->Get('uid'), true);
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may see');
        }
        return $this->Answer(self::NewXMLContentSection('client', ws_data::Client($uid)));
    }

    /**
     * GetClientUsage: <uid>. Use against the package's limits (disk and this month's bandwidth in
     * MB; -1: no limit).
     *
     * @return array
     */
    public function GetClientUsage()
    {
        $uid = self::Target($this->Get('uid'), true);
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may see');
        }
        return $this->Answer(self::NewXMLContentSection('usage', array('uid' => $uid) + ws_data::Usage($uid)));
    }

    /**
     * CreateClient: <resellerid> (a reseller's key: itself, the default), <username>, <packageid> (the
     * reseller's own), <groupid> (never Administrators; a reseller's key: one its own page offers),
     * <fullname>, <email>, <address>, <postcode>, <phone>, <password>, <sendemail>, <emailsubject>,
     * <emailbody>. Answers the new account's <uid>.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function CreateClient()
    {
        global $zdbh;
        $reseller = (string) $this->Get('resellerid') === '' ? self::$actor : (int) $this->Get('resellerid');
        if (!self::$admin && $reseller !== self::$actor) {
            return self::Error('1107', 'resellerid: a reseller\'s key makes its own clients only');
        }
        if (self::Account($reseller) === 0 || !ctrl_support::CanSet($reseller)) {
            return self::Error('1107', 'resellerid is not a live account that can have clients');
        }
        $package = ws_data::OwnPackage($this->Get('packageid'), $reseller);
        if ($package === 0) {
            return self::Error('1107', 'packageid is not one of the reseller\'s own packages');
        }
        $group = $this->Get('groupid');
        if (!is_scalar($group) || !ctype_digit((string) $group) || (int) $group === 1) {
            return self::Error('1107', 'groupid must be a group other than Administrators');
        }
        if (!self::$admin && !module_controller::AllowedGroup((int) $group)) {
            return self::Error('1107', 'groupid is not one of the groups you may give');
        }
        $username = strtolower(str_replace(' ', '', (string) $this->Get('username')));
        if (module_controller::CheckUserExists($username)) {
            return self::Error('1107', 'That user name is taken');
        }
        $ok = module_controller::ExecuteCreateClient($reseller, $username, $package, (int) $group, (string) $this->Get('fullname'), trim((string) $this->Get('email')),
            (string) $this->Get('address'), (string) $this->Get('postcode'), (string) $this->Get('phone'), (string) $this->Get('password'),
            (int) $this->Get('sendemail'), (string) $this->Get('emailsubject'), (string) $this->Get('emailbody'));
        if (!$ok) {
            return self::Error('1107', self::Why());
        }
        $q = $zdbh->prepare("SELECT ac_id_pk FROM x_accounts WHERE ac_user_vc = :u AND ac_deleted_ts IS NULL");
        $q->execute(array(':u' => $username));
        $uid = (int) $q->fetchColumn();
        self::$note = 'account ' . $uid;
        return $this->Answer(self::NewXMLTag('created', 'true') . self::NewXMLTag('uid', $uid));
    }

    /**
     * UpdateClient: <uid> and any of <fullname>, <email>, <phone>, <address>, <postcode> (the others
     * stay as they are).
     *
     * @return array
     */
    public function UpdateClient()
    {
        $uid = self::Target($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may change (never an administrator)');
        }
        $u = ctrl_users::GetUserDetail($uid);
        $v = array();
        foreach (array('fullname', 'email', 'phone', 'address', 'postcode') as $k) {
            $sent = $this->Get($k);
            $v[$k] = $sent === null ? (string) $u[$k] : $sent;
            if (mb_strlen($v[$k]) > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/u', $v[$k])) {
                return self::Error('1107', $k . ': up to 255 characters, without < or >');
            }
        }
        if (!module_controller::ExecuteUpdateClient($uid, (int) $u['packageid'], (int) $u['enabled'], (int) $u['usergroupid'], $v['fullname'], $v['email'], $v['address'], $v['postcode'], $v['phone'], '')) {
            return self::Error('1107', self::Why());
        }
        return $this->Answer(self::NewXMLTag('uid', $uid) . self::NewXMLTag('updated', 'true'));
    }

    /**
     * ChangePackage: <uid>, <packageid> (one of the client's reseller's own packages). For upgrades and
     * downgrades: the new limits apply at once.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function ChangePackage()
    {
        global $zdbh;
        $uid = self::Target($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may change (never an administrator)');
        }
        $u = ctrl_users::GetUserDetail($uid);
        $package = ws_data::OwnPackage($this->Get('packageid'), (int) $u['resellerid']);
        if ($package === 0) {
            return self::Error('1107', 'packageid is not one of the client\'s reseller\'s packages');
        }
        if (!module_controller::ExecuteUpdateClient($uid, $package, (int) $u['enabled'], (int) $u['usergroupid'], (string) $u['fullname'], (string) $u['email'], (string) $u['address'], (string) $u['postcode'], (string) $u['phone'], '')) {
            return self::Error('1107', self::Why());
        }
        // the web server's settings follow the package (PHP on or off, limits)
        $zdbh->exec("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc = 'apache_changed'");
        return $this->Answer(self::NewXMLTag('uid', $uid) . self::NewXMLTag('packageid', $package) . self::NewXMLTag('changed', 'true'));
    }

    /**
     * CreateLoginLink: <uid>. An address that logs the client into the panel once, within two minutes
     * (two-factor login still asks for its code). Never for an administrator.
     *
     * @return array
     */
    public function CreateLoginLink()
    {
        $uid = self::Target($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may log in as (never an administrator)');
        }
        if ((int) ctrl_users::GetUserDetail($uid)['enabled'] !== 1) {
            return self::Error('1107', 'The account is switched off');
        }
        return $this->Answer(self::NewXMLTag('uid', $uid) . self::NewXMLTag('url', ws_loginlink::Create($uid, self::$keyId))
            . self::NewXMLTag('expires', time() + ws_loginlink::TTL));
    }

    /**
     * UsernameExists: <username>.
     *
     * @return array
     */
    public function UsernameExists()
    {
        $exists = module_controller::CheckUserExists(strtolower(str_replace(' ', '', (string) $this->Get('username'))));
        return $this->Answer($exists ? 'true' : 'false');
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
