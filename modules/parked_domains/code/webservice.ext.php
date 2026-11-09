<?php

/**
 * Parked Domains: the API's parked-domain requests. The account must be the key's own or one it may
 * reach (ws_xmws::Reach()); a parked domain is only deleted when its account is (VhostOwner()). The
 * checks the page makes before it adds one are made here too: the package's limit, and (in
 * ExecuteAddParkedDomain) the name, the panel's own name, a name in use anywhere on the server and
 * a name under another account's domain.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class webservice extends ws_xmws
{

    /**
     * GetParkedDomains: <uid>.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function GetParkedDomains()
    {
        global $zdbh;
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        $q = $zdbh->prepare("SELECT vh_id_pk, vh_name_vc, vh_active_in, vh_created_ts FROM x_vhosts WHERE vh_acc_fk = :u AND vh_type_in = 3 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc");
        $q->execute(array(':u' => $uid));
        $xml = "\n";
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $xml .= self::NewXMLContentSection('parkeddomain', array('id' => (int) $r['vh_id_pk'], 'domain' => (string) $r['vh_name_vc'],
                'active' => (int) $r['vh_active_in'], 'created' => (int) $r['vh_created_ts']));
        }
        return $this->Answer($xml);
    }

    /**
     * CreateParkedDomain: <uid>, <domain>. Answers its <domainid>.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function CreateParkedDomain()
    {
        global $zdbh;
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        // the package's limit, as the Parked Domains page checks it
        if (!ws_data::UnderLimit($uid, 'parkeddomains')) {
            return self::Error('1107', 'The account\'s package allows no more parked domains');
        }
        $domain = strtolower(str_replace(' ', '', (string) $this->Get('domain')));
        if (!module_controller::ExecuteAddParkedDomain($uid, $domain)) {
            return self::Error('1107', self::Why());
        }
        $q = $zdbh->prepare("SELECT MAX(vh_id_pk) FROM x_vhosts WHERE vh_acc_fk = :u AND vh_name_vc = :d AND vh_type_in = 3 AND vh_deleted_ts IS NULL");
        $q->execute(array(':u' => $uid, ':d' => $domain));
        $id = (int) $q->fetchColumn();
        self::$note = 'parked domain ' . $id;
        return $this->Answer(self::NewXMLTag('created', 'true') . self::NewXMLTag('domainid', $id) . self::NewXMLTag('domain', $domain));
    }

    /**
     * DeleteParkedDomain: <domainid>.
     *
     * @return array
     */
    public function DeleteParkedDomain()
    {
        $id = $this->Get('domainid');
        if (self::VhostOwner($id, 3) === 0) {
            return self::Error('1107', 'No such parked domain you may reach');
        }
        module_controller::ExecuteDeleteParkedDomain((int) $id);
        return $this->Answer(self::NewXMLTag('domainid', (int) $id) . self::NewXMLTag('deleted', 'true'));
    }

    /**
     * What the page's checks said when they refused.
     *
     * @return string
     */
    private static function Why()
    {
        $why = array('blank' => 'A domain name is needed', 'badname' => 'That is not a domain name',
            'error' => 'Leave out www.', 'alreadyexists' => 'That domain is already on this server',
            'nosub' => 'That name is under another account\'s domain');
        foreach ($why as $flag => $text) {
            if (property_exists('module_controller', $flag) && !empty(module_controller::$$flag)) {
                return $text;
            }
        }
        return 'The panel refused it';
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
