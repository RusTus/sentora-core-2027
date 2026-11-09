<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * @package zpanelx
 * @subpackage modules
 * @author Alexey Smirnov (alexey@itri.org.tw)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-09
 */

/**
 * Sub-domains web service: list, create and delete subdomains for other programs (XMWS API).
 */
class webservice extends ws_xmws {

    /**
     * Get the list of subdomains for a given user.
     *
     * The request content is the account ID.
     *
     * @global db_driver $zdbh The panel's database connection (not used).
     * @return array<string, string> The response data object: 'response' and 'content' (one <subdomain> section per subdomain).
     */
    public function GetSubDomainsForUser() {
        global $zdbh;
        $request_data = $this->RawXMWSToArray($this->wsdata);
        $response_xml = "\n";

        // the account: <uid>n</uid>, or the bare number as before
        $uid = ws_generic::GetTagValue('uid', $request_data['content']);
        $uid = ws_xmws::Reach($uid !== null ? $uid : trim((string) $request_data['content']));
        if ($uid === 0) {
            return ws_xmws::Error('1107', 'No such account you may reach');
        }
        $allSubdomains = module_controller::ListSubDomains($uid);
        if (!fs_director::CheckForEmptyValue($allSubdomains)) {
            foreach ($allSubdomains as $domain) {
                $response_xml = $response_xml . ws_xmws::NewXMLContentSection('subdomain', array(
                            'subname' => $domain['subname'],
                            'subdirectory' => $domain['subdirectory'],
                            'subactive' => $domain['subactive'],
                            'subid' => $domain['subid'],
                        ));
            }
        }

        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', $response_xml);
        return $dataobject->getDataObject();
    }

    /**
     * Enables an authenticated user to create a sub-domain.
     *
     * Reads the <subname>, <subdirectory>, <uid> and <autohome> tags; the response
     * echoes them (it does not say whether the subdomain was made).
     *
     * @global db_driver $zdbh The panel's database connection (not used).
     * @return array<string, string> The response data object: 'response' and 'content' (a <subdomain> section).
     */
    public function CreateSubDomain() {
        global $zdbh;
        $request_data = $this->RawXMWSToArray($this->wsdata);
        $response_xml = "\n";

        $subname = ws_generic::GetTagValue('subname', $request_data['content']);
        $subdirectory = ws_generic::GetTagValue('subdirectory', $request_data['content']);
        $uid = ws_xmws::Reach(ws_generic::GetTagValue('uid', $request_data['content']));
        if ($uid === 0) {
            return ws_xmws::Error('1107', 'No such account you may reach');
        }
        // the package's limit, as the Sub Domains page checks it
        if (!ws_data::UnderLimit($uid, 'subdomains')) {
            return ws_xmws::Error('1107', 'The account\'s package allows no more sub-domains');
        }
        $autohome = ws_generic::GetTagValue('autohome', $request_data['content']);

        $retval = module_controller::ExecuteAddSubDomain($uid, $subname, $subdirectory, $autohome);

        $response_xml = $response_xml . ws_xmws::NewXMLContentSection('subdomain', array(
                            'subname' => $subname,
                            'subdirectory' => $subdirectory,
                            'uid' => $uid,
                            'autohome' => $autohome,
        ));

        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', $response_xml);
        return $dataobject->getDataObject();
    }


    /**
     * Delete a specified sub-domain using the content <domainid> tag.
     *
     * @global db_driver $zdbh The panel's database connection (not used).
     * @return array<string, string> The response data object: 'response' and 'content' (<domainid> and <deleted> true or false).
     */
    public function DeleteSubDomain() {
        global $zdbh;
        $request_data = $this->RawXMWSToArray($this->wsdata);
        $contenttags = $this->XMLDataToArray($request_data['content']);
        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');

        $domainid = $contenttags['domainid'] ?? '';
        if (!is_scalar($domainid) || !ctype_digit((string) $domainid)) {
            return ws_xmws::Error('1107', 'domainid must be a number');
        }
        if (ws_xmws::VhostOwner($domainid, 2) === 0) {
            return ws_xmws::Error('1107', 'No such sub-domain you may reach');
        }
        if (module_controller::ExecuteDeleteSubDomain((int) $domainid)) {
            $dataobject->addItemValue('content', ws_xmws::NewXMLTag('domainid', $contenttags['domainid']) . ws_xmws::NewXMLTag('deleted', 'true'));
        } else {
            $dataobject->addItemValue('content', ws_xmws::NewXMLTag('domainid', $contenttags['domainid']) . ws_xmws::NewXMLTag('deleted', 'false'));
        }
        return $dataobject->getDataObject();
    }


}

?>

