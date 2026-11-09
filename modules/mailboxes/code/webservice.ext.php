<?php

/**
 * Mailboxes: the API's mailbox requests. The account a request names must be the key's own or one
 * it may reach (ws_xmws::Reach()); a mailbox named by its ID must belong to such an account.
 *
 * The work is done by the Mailboxes page's own functions, run as the mailbox's account (AsAccount()):
 * they check what the page checks against the logged-in account (the domain must be one of the
 * account's own, the package's limit on mailboxes, the password rules, addresses already in use)
 * and update the mail server through the module's mail server file, which they find by the page's
 * module name.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class webservice extends ws_xmws
{

    /**
     * Run the page's functions as the account: what they check against the logged-in account (its
     * domains, its package's limit, its own records) is then checked against this one, and they find
     * the mail server file by the module name (modules/mailboxes/code/postfix.php). The account was
     * checked by Reach() first.
     *
     * @global runtime_controller $controller The request controller.
     * @param int $uid The account.
     * @return void
     */
    private static function AsAccount($uid)
    {
        global $controller;
        $_SESSION = array('zpuid' => (int) $uid);
        $_GET = array('module' => 'mailboxes');
        $controller = new runtime_controller();
        $controller->Init();
    }

    /**
     * The account a mailbox belongs to, when the key may reach it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param mixed $id The mailbox ID as sent.
     * @return int The account, or 0.
     */
    private static function Owner($id)
    {
        global $zdbh;
        if (!is_scalar($id) || !ctype_digit((string) $id)) {
            return 0;
        }
        $q = $zdbh->prepare("SELECT mb_acc_fk FROM x_mailboxes WHERE mb_id_pk = :id AND mb_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $id));
        $owner = $q->fetchColumn();
        return $owner === false ? 0 : self::Reach((string) (int) $owner);
    }

    /**
     * What the page's functions said when they refused (their flags).
     *
     * @return string
     */
    private static function Why()
    {
        $why = array(
            'noaddress' => 'address is needed', 'password' => 'password is needed',
            'badpasswordlength' => 'The password is too short (at least ' . module_controller::getMinPassLength() . ' characters)',
            'badpass' => 'The password needs a lower case letter, an upper case letter and a digit',
            'validemail' => 'address is not a valid email address', 'baddomain' => 'The domain is not one of the account\'s own',
            'beingremoved' => 'A mailbox with that address was deleted moments ago: try again in 5 minutes',
            'alreadyexists' => 'That address is in use already (a mailbox, forwarder, list or alias)',
        );
        foreach ($why as $flag => $text) {
            if (!empty(module_controller::$$flag)) {
                return $text;
            }
        }
        return 'The panel refused it';
    }

    /**
     * GetMailboxes: <uid>. Each mailbox's <id>, <address>, <enabled> and <created> (a Unix time).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function GetMailboxes()
    {
        global $zdbh;
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        $q = $zdbh->prepare("SELECT mb_id_pk, mb_address_vc, mb_enabled_in, mb_created_ts FROM x_mailboxes WHERE mb_acc_fk = :u AND mb_deleted_ts IS NULL ORDER BY mb_address_vc");
        $q->execute(array(':u' => $uid));
        $xml = "\n";
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $xml .= self::NewXMLContentSection('mailbox', array('id' => (int) $r['mb_id_pk'], 'address' => $r['mb_address_vc'],
                'enabled' => (int) $r['mb_enabled_in'] === 1 ? 'true' : 'false', 'created' => (int) $r['mb_created_ts']));
        }
        return $this->Answer($xml);
    }

    /**
     * CreateMailbox: <uid>, <address> (the full address, on one of the account's own domains),
     * <password> (the panel's password rules). Within the package's limit on mailboxes. Answers the
     * new mailbox's <mailboxid>.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function CreateMailbox()
    {
        global $zdbh;
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        $address = strtolower(str_replace(' ', '', (string) $this->Get('address')));
        $at = strrpos($address, '@');
        if ($at === false || $at === 0 || $at === strlen($address) - 1) {
            return self::Error('1107', 'address must be a full address, such as info@example.com');
        }
        $local = substr($address, 0, $at);
        $domain = substr($address, $at + 1);
        self::AsAccount($uid);
        // the package's limit, as the Mailboxes page checks it
        if (!module_controller::getQuotaLimit()) {
            return self::Error('1107', 'The account\'s package allows no more mailboxes');
        }
        if (!module_controller::ExecuteAddMailbox($uid, $local, $domain, (string) $this->Get('password'))) {
            return self::Error('1107', self::Why());
        }
        $q = $zdbh->prepare("SELECT MAX(mb_id_pk) FROM x_mailboxes WHERE mb_acc_fk = :u AND mb_address_vc = :a AND mb_deleted_ts IS NULL");
        $q->execute(array(':u' => $uid, ':a' => $local . '@' . $domain));
        $id = (int) $q->fetchColumn();
        self::$note = 'account ' . $uid . ', mailbox ' . $id;
        return $this->Answer(self::NewXMLTag('created', 'true') . self::NewXMLTag('mailboxid', $id) . self::NewXMLTag('address', $local . '@' . $domain));
    }

    /**
     * DeleteMailbox: <mailboxid>, optional <dropaliases> and <leavelists> (true: also remove the
     * aliases that deliver to it, and take it off mailing lists). Its forwarders go with it.
     *
     * @return array
     */
    public function DeleteMailbox()
    {
        $id = $this->Get('mailboxid');
        $owner = self::Owner($id);
        if ($owner === 0) {
            return self::Error('1107', 'No such mailbox you may reach');
        }
        self::AsAccount($owner);
        module_controller::ExecuteDeleteMailbox((int) $id, $this->Get('dropaliases') === 'true', $this->Get('leavelists') === 'true');
        self::$note = 'account ' . $owner . ', mailbox ' . (int) $id;
        return $this->Answer(self::NewXMLTag('mailboxid', (int) $id) . self::NewXMLTag('deleted', 'true'));
    }

    /**
     * SetMailboxPassword: <mailboxid>, <password> (the panel's password rules). The mailbox stays on
     * or off as it was.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function SetMailboxPassword()
    {
        global $zdbh;
        $id = $this->Get('mailboxid');
        $owner = self::Owner($id);
        if ($owner === 0) {
            return self::Error('1107', 'No such mailbox you may reach');
        }
        $password = (string) $this->Get('password');
        if ($password === '') {
            // (an empty password means "keep it" on the page; here it is a mistake)
            return self::Error('1107', 'password is needed');
        }
        $q = $zdbh->prepare("SELECT mb_enabled_in FROM x_mailboxes WHERE mb_id_pk = :id");
        $q->execute(array(':id' => (int) $id));
        $enabled = (int) $q->fetchColumn();
        self::AsAccount($owner);
        if (module_controller::ExecuteUpdateMailbox((int) $id, $password, $enabled) === false) {
            return self::Error('1107', self::Why());
        }
        self::$note = 'account ' . $owner . ', mailbox ' . (int) $id;
        return $this->Answer(self::NewXMLTag('mailboxid', (int) $id) . self::NewXMLTag('updated', 'true'));
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
