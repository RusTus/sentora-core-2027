<?php

/**
 * Forwarders: the API's forwarder requests. The account a request names must be the key's own or one
 * it may reach (ws_xmws::Reach()); a forwarder named by its ID must belong to such an account.
 *
 * The work is done by the Forwarders page's own functions, run as the forwarder's account (AsAccount()):
 * they check what the page checks against the logged-in account (the source must be one of the
 * account's own mailboxes, the package's limit on forwarders and aliases together, a valid
 * destination, no forwarder to itself or twice to the same address) and update the mail server
 * through the module's mail server file, which they find by the page's module name.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class webservice extends ws_xmws
{

    /**
     * Run the page's functions as the account (see Mailboxes' webservice): its mailboxes, its
     * package's limit and its own records are then checked, and the mail server file is found
     * (modules/forwarders/code/postfix.php). The account was checked by Reach() first.
     *
     * @global runtime_controller $controller The request controller.
     * @param int $uid The account.
     * @return void
     */
    private static function AsAccount($uid)
    {
        global $controller;
        $_SESSION = array('zpuid' => (int) $uid);
        $_GET = array('module' => 'forwarders');
        $controller = new runtime_controller();
        $controller->Init();
    }

    /**
     * What the page's functions said when they refused (their flags).
     *
     * @return string
     */
    private static function Why()
    {
        $why = array(
            'noaddress' => 'The mailbox to forward is needed (mailboxid or address)',
            'validmailbox' => 'The address to forward is not one of the account\'s own mailboxes',
            'validemail' => 'destination is not a valid email address',
            'alreadyexistssame' => 'A mailbox can not forward to itself',
            'alreadyexists' => 'That mailbox forwards to that destination already',
        );
        foreach ($why as $flag => $text) {
            if (!empty(module_controller::$$flag)) {
                return $text;
            }
        }
        return 'The panel refused it';
    }

    /**
     * GetForwarders: <uid>. Each forwarder's <id>, <address> (the mailbox), <destination> and
     * <keepcopy> (true: a copy stays in the mailbox).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function GetForwarders()
    {
        global $zdbh;
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        $q = $zdbh->prepare("SELECT fw_id_pk, fw_address_vc, fw_destination_vc, fw_keepmessage_in FROM x_forwarders WHERE fw_acc_fk = :u AND fw_deleted_ts IS NULL ORDER BY fw_address_vc");
        $q->execute(array(':u' => $uid));
        $xml = "\n";
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $xml .= self::NewXMLContentSection('forwarder', array('id' => (int) $r['fw_id_pk'], 'address' => $r['fw_address_vc'],
                'destination' => $r['fw_destination_vc'], 'keepcopy' => (int) $r['fw_keepmessage_in'] === 1 ? 'true' : 'false'));
        }
        return $this->Answer($xml);
    }

    /**
     * CreateForwarder: <uid>, <mailboxid> or <address> (one of the account's own mailboxes),
     * <destination> (any email address), <keepcopy> (true: a copy stays in the mailbox). Within the
     * package's limit on forwarders. Answers the new forwarder's <forwarderid>.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function CreateForwarder()
    {
        global $zdbh;
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        $address = strtolower(trim((string) $this->Get('address')));
        if ((string) $this->Get('mailboxid') !== '') {
            $q = $zdbh->prepare("SELECT mb_address_vc FROM x_mailboxes WHERE mb_id_pk = :id AND mb_acc_fk = :u AND mb_deleted_ts IS NULL");
            $q->execute(array(':id' => (int) $this->Get('mailboxid'), ':u' => $uid));
            $address = strtolower((string) $q->fetchColumn());
            if ($address === '') {
                return self::Error('1107', 'mailboxid is not one of the account\'s own mailboxes');
            }
        }
        $destination = strtolower(str_replace(' ', '', (string) $this->Get('destination')));
        $at = strrpos($destination, '@');
        $dname = $at === false ? $destination : substr($destination, 0, $at);
        $ddomain = $at === false ? '' : substr($destination, $at + 1);
        self::AsAccount($uid);
        // the package's limit (forwarders and aliases together), as the Forwarders page checks it
        if (!module_controller::getQuotaLimit()) {
            return self::Error('1107', 'The account\'s package allows no more forwarders');
        }
        if (!module_controller::ExecuteCreateForwarder($uid, $address, $dname, $ddomain, $this->Get('keepcopy') === 'true' ? 1 : 0)) {
            return self::Error('1107', self::Why());
        }
        $q = $zdbh->prepare("SELECT MAX(fw_id_pk) FROM x_forwarders WHERE fw_acc_fk = :u AND fw_address_vc = :a AND fw_destination_vc = :d AND fw_deleted_ts IS NULL");
        $q->execute(array(':u' => $uid, ':a' => $address, ':d' => $dname . '@' . $ddomain));
        $id = (int) $q->fetchColumn();
        self::$note = 'account ' . $uid . ', forwarder ' . $id;
        return $this->Answer(self::NewXMLTag('created', 'true') . self::NewXMLTag('forwarderid', $id));
    }

    /**
     * DeleteForwarder: <forwarderid>.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function DeleteForwarder()
    {
        global $zdbh;
        $id = $this->Get('forwarderid');
        $owner = 0;
        if (is_scalar($id) && ctype_digit((string) $id)) {
            $q = $zdbh->prepare("SELECT fw_acc_fk FROM x_forwarders WHERE fw_id_pk = :id AND fw_deleted_ts IS NULL");
            $q->execute(array(':id' => (int) $id));
            $acc = $q->fetchColumn();
            $owner = $acc === false ? 0 : self::Reach((string) (int) $acc);
        }
        if ($owner === 0) {
            return self::Error('1107', 'No such forwarder you may reach');
        }
        self::AsAccount($owner);
        module_controller::ExecuteDeleteForwarder((int) $id);
        self::$note = 'account ' . $owner . ', forwarder ' . (int) $id;
        return $this->Answer(self::NewXMLTag('forwarderid', (int) $id) . self::NewXMLTag('deleted', 'true'));
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
