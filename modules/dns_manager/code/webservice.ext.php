<?php

/**
 * DNS Manager module: web service (XMWS API) for listing, creating and deleting DNS records.
 *
 * Called through bin/api.php?m=dns_manager; each public method reads its
 * parameters from the XML request and returns the response data object.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Updated by RusTus, 2026-11-09
 */

/**
 * DNS Manager web service (methods called by name from the 'request' tag).
 */
class webservice extends ws_xmws
{

    /**
     * Look up the ID of a domain (not a parked one) owned by an account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @param string $domainName The domain name.
     * @return int|string|null The domain (x_vhosts) ID, or null when not found.
     */
    private function GetDomainID($uid, $domainName)
    {
        global $zdbh;

        $sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_type_in !=2 AND vh_deleted_ts IS NULL AND vh_name_vc=:name");
        $sql->bindParam(':uid', $uid);
        $sql->bindParam(':name', $domainName);
        $sql->execute();
        $domainID = $sql->fetch();
        return $domainID['vh_id_pk'];
    }

    /**
     * Get list of all DNS records for a given domain. Parameters of XML request:
     *     uid - user ID
     *     domainName - name of domain
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<string, string> The response data object: 'response' and 'content' (one <dns_record> section per record).
     */
    public function GetAllDNSRecords()
    {
        global $zdbh;

        $request_data = $this->RawXMWSToArray($this->wsdata);
        $response_xml = "\n";
        $domainName = ws_generic::GetTagValue('domainName', $request_data['content']);
        $uid = ws_generic::GetTagValue('uid', $request_data['content']);
        // a live account and a domain it owns
        $uid = ws_xmws::Account($uid);
        $domainID = $uid ? self::GetDomainID($uid, $domainName) : null;
        if (!$domainID) {
            return ws_xmws::Error('1107', 'No such domain for that account');
        }

        $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_acc_fk=:userid AND dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL ORDER BY dn_host_vc ASC");
        $sql->bindParam(':userid', $uid);
        $sql->bindParam(':domainID', $domainID);
        $sql->execute();

        while ($rowdns = $sql->fetch()) {
            $response_xml = $response_xml . ws_xmws::NewXMLContentSection('dns_record', array(
                        'hostName' => $rowdns['dn_host_vc'],
                        'type' => $rowdns['dn_type_vc'],
                        'target' => $rowdns['dn_target_vc'],
                        'ttl' => $rowdns['dn_ttl_in'],
            ));
        }

        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', $response_xml);
        return $dataobject->getDataObject();
    }

    /**
     * Create a new DNS record.  Parameters of XML request:
     *     uid - user ID
     *     domainName - the domain for which DNS record is being created
     *     hostName - the host part of new DNS record (e.g. 'www')
     *     type - type of new DNS record (e.g. 'CNAME')
     *     target - the destination of DNS record (e.g. '@')
     *     ttl - time to live (e.g. '3600')
     *
     * @return array<string, string> The response data object: 'response' and 'content' (a <dns_record> section with created true).
     */
    public function CreateDNSRecord()
    {
        $request_data = $this->RawXMWSToArray($this->wsdata);
        $response_xml = "\n";

        $uid = ws_generic::GetTagValue('uid', $request_data['content']);
        $domainName = ws_generic::GetTagValue('domainName', $request_data['content']);
        $hostName = ws_generic::GetTagValue('hostName', $request_data['content']);
        $type = ws_generic::GetTagValue('type', $request_data['content']);
        $target = ws_generic::GetTagValue('target', $request_data['content']);
        $ttl = ws_generic::GetTagValue('ttl', $request_data['content']);
        // a live account and a domain it owns
        $uid = ws_xmws::Account($uid);
        $domainID = $uid ? self::GetDomainID($uid, $domainName) : null;
        if (!$domainID) {
            return ws_xmws::Error('1107', 'No such domain for that account');
        }

        // a time-to-live in seconds
        if (!is_scalar($ttl) || !ctype_digit((string) $ttl) || (int) $ttl < 60 || (int) $ttl > 604800) {
            return ws_xmws::Error('1107', 'ttl must be a number of seconds from 60 to 604800');
        }
        $ttl = (int) $ttl;
        module_controller::createDNSRecord(array(
            "uid" => $uid,
            "domainName" => $domainName,
            "domainID" => $domainID,
            "type" => $type,
            "hostName" => $hostName,
            "ttl" => $ttl,
            "target" => $target
        ));

        $response_xml = $response_xml . ws_xmws::NewXMLContentSection('dns_record', array(
                    'domainName' => $domainName,
                    'hostName' => $hostName,
                    'type' => $type,
                    'target' => $target,
                    'created' => 'true'
        ));

        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', $response_xml);
        return $dataobject->getDataObject();
    }

    /**
     * Delete one or multiple DNS records
     * Mandatory parameters: uid and domainName
     * Optional parameters: hostName, record type, target
     * The meaning of parameters is same as in CreateDNSRecord()
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<string, string> The response data object: 'response' and 'content' (one <dns_record> section per deleted record).
     */
    public function DeleteDNSRecords()
    {
        global $zdbh;

        $request_data = $this->RawXMWSToArray($this->wsdata);
        $response_xml = "\n";
        $tags = array('hostName' => 'dn_host_vc',
            'type' => 'dn_type_vc',
            'target' => 'dn_target_vc');

        // these are mandatory parameters
        $uid = ws_generic::GetTagValue('uid', $request_data['content']);
        $domainName = ws_generic::GetTagValue('domainName', $request_data['content']);
        // a live account and a domain it owns
        $uid = ws_xmws::Account($uid);
        $domainID = $uid ? self::GetDomainID($uid, $domainName) : null;
        if (!$domainID) {
            return ws_xmws::Error('1107', 'No such domain for that account');
        }

        // (dn_deleted_ts: this named vh_deleted_ts, a column x_dns does not have, so it always failed)
        $sqlstr = "SELECT * FROM x_dns WHERE dn_acc_fk=:userid AND dn_deleted_ts IS NULL AND dn_vhost_fk=:domainID ";

        // iterate through optional parameters
        foreach ($tags as $tag => $sql_param) {
            if (!is_null(ws_generic::GetTagValue($tag, $request_data['content'])))
                $sqlstr .= " AND " . $sql_param . '=:' . $tag;
        }

        $sql = $zdbh->prepare($sqlstr);
        $sql->bindParam(':userid', $uid);
        $sql->bindParam(':domainID', $domainID);

        $params = array();
        foreach ($tags as $tag => $sql_param) {
            if (!is_null($params[$tag] = ws_generic::GetTagValue($tag, $request_data['content'])))
                $sql->bindParam(":" . $tag, $params[$tag]);
        }

        $sql->execute();

        while ($rowdns = $sql->fetch()) {
            $response_xml = $response_xml . ws_xmws::NewXMLContentSection('dns_record', array(
                        'hostName' => $rowdns['dn_host_vc'],
                        'type' => $rowdns['dn_type_vc'],
                        'target' => $rowdns['dn_target_vc'],
                        'ttl' => $rowdns['dn_ttl_in'],
                        'deleted' => 'true'
            ));

            $sql2 = $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=:time WHERE dn_id_pk =:id AND dn_deleted_ts IS NULL");
            $sql2->bindParam(':id', $rowdns['dn_id_pk']);
            $time = time();
            $sql2->bindParam(':time', $time);
            $sql2->execute();
        }

        module_controller::TriggerDNSUpdate($domainID);

        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', $response_xml);
        return $dataobject->getDataObject();
    }

}
?>


