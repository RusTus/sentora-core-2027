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
 * Updated by RusTus, 2026-11-06
 */
/**
 * Manage Clients module: XMWS web service methods.
 *
 * Each method reads the request XML in $this->wsdata and returns the response data.
 */
class webservice extends ws_xmws
{

    /**
     * Delete a client (request tags: uid, optional moveid; default move target 1).
     *
     * @return array<string, string> Response data: 'response' (empty) and 'content' (uid and deleted tags).
     */
    function DeleteClient()
    {
        $request_data = $this->RawXMWSToArray($this->wsdata);
        $contenttags = $this->XMLDataToArray($request_data['content']);
        // never an administrator (a leaked key must not lock the server's owner out); what the
        // account owned goes to a live account
        $uid = ws_xmws::Account($contenttags['uid'] ?? '', true);
        if ($uid === 0) {
            return ws_xmws::Error('1107', 'No such account, or an administrator (the API does not delete administrators)');
        }
        $moveid = empty($contenttags['moveid']) ? 0 : ws_xmws::Account($contenttags['moveid']);
        if (!empty($contenttags['moveid']) && ($moveid === 0 || $moveid === $uid)) {
            return ws_xmws::Error('1107', 'moveid is not a live account other than the one deleted');
        }
        module_controller::ExecuteDeleteClient($uid, $moveid);
        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', ws_xmws::NewXMLTag('uid', $contenttags['uid']) . ws_xmws::NewXMLTag('deleted', 'true'));

        return $dataobject->getDataObject();
    }

    /**
     * Enable a client (request tag: uid).
     *
     * @return array<string, string> Response data: 'response' (empty) and 'content' (uid and enabled tags).
     */
    function EnableClient()
    {
        $request_data = $this->RawXMWSToArray($this->wsdata);
        $contenttags = $this->XMLDataToArray($request_data['content']);
        $uid = ws_xmws::Account($contenttags['uid'] ?? '', true);
        if ($uid === 0) {
            return ws_xmws::Error('1107', 'No such account, or an administrator');
        }
        module_controller::EnableClient($uid);
        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', ws_xmws::NewXMLTag('uid', $contenttags['uid']) . ws_xmws::NewXMLTag('enabled', 'true'));
        return $dataobject->getDataObject();
    }

    /**
     * Disable a client (request tag: uid).
     *
     * @return array<string, string> Response data: 'response' (empty) and 'content' (uid and disabled tags).
     */
    function DisableClient()
    {
        $request_data = $this->RawXMWSToArray($this->wsdata);
        $contenttags = $this->XMLDataToArray($request_data['content']);
        $uid = ws_xmws::Account($contenttags['uid'] ?? '', true);
        if ($uid === 0) {
            return ws_xmws::Error('1107', 'No such account, or an administrator (the API does not switch administrators off)');
        }
        module_controller::DisableClient($uid);
        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', ws_xmws::NewXMLTag('uid', $contenttags['uid']) . ws_xmws::NewXMLTag('disabled', 'true'));
        return $dataobject->getDataObject();
    }

    /**
     * List the enabled clients of a reseller (request tag: uid).
     *
     * @return array<string, string> Response data: 'response' (empty) and 'content' (one client section per client).
     */
    public function GetAllClients()
    {
        $request_data = $this->RawXMWSToArray($this->wsdata);
        $contenttags = $this->XMLDataToArray($request_data['content']);
        $response_xml = "\n";
        if (module_controller::ListClients($contenttags['uid'])) {
            $allactiveclients = module_controller::ListClients($contenttags['uid']);
            $currentclient = 0;
            $newsections = "";
            foreach ($allactiveclients as $client) {
                // not the password hash, nor the page's own buttons
                unset($client['password'], $client['tfreset']);
                $newsections = $newsections . ws_xmws::NewXMLContentSection('client', $client);
                $currentclient++;
            }
            $response_xml = $response_xml . $newsections;
        }
        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', $response_xml);
        return $dataobject->getDataObject();
    }

    /**
     * Create a client from the request tags (resellerid, username, packageid, groupid, ...).
     *
     * @return array<string, string> Response data: 'response' (empty) and 'content' ("true" when created, else "false").
     */
    public function CreateClient()
    {
        $request_data = $this->RawXMWSToArray($this->wsdata);
        $response_xml = "false";
        // a client of a live account, never in the Administrators group: a leaked key must not be
        // able to make itself an administrator
        $groupid = ws_generic::GetTagValue('groupid', $request_data['content']);
        if (ws_xmws::Account(ws_generic::GetTagValue('resellerid', $request_data['content'])) === 0) {
            return ws_xmws::Error('1107', 'resellerid is not a live account');
        }
        if (!is_scalar($groupid) || !ctype_digit((string) $groupid) || (int) $groupid === 1) {
            return ws_xmws::Error('1107', 'groupid must be a group other than Administrators');
        }
        $userExits = module_controller::CheckUserExists(ws_generic::GetTagValue('username', $request_data['content']));
        if (!$userExits) {
            if(module_controller::ExecuteCreateClient(ws_generic::GetTagValue('resellerid', $request_data['content']), ws_generic::GetTagValue('username', $request_data['content']), ws_generic::GetTagValue('packageid', $request_data['content']), ws_generic::GetTagValue('groupid', $request_data['content']), ws_generic::GetTagValue('fullname', $request_data['content']), ws_generic::GetTagValue('email', $request_data['content']), ws_generic::GetTagValue('address', $request_data['content']), ws_generic::GetTagValue('postcode', $request_data['content']), ws_generic::GetTagValue('phone', $request_data['content']), ws_generic::GetTagValue('password', $request_data['content']), ws_generic::GetTagValue('sendemail', $request_data['content']), ws_generic::GetTagValue('emailsubject', $request_data['content']), ws_generic::GetTagValue('emailbody', $request_data['content']))){
                $response_xml = "true";
            }
        }
        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', $response_xml);
        return $dataobject->getDataObject();
    }

    /**
     * Check whether a user name is taken (request tag: username).
     *
     * @return array<string, string> Response data: 'response' (empty) and 'content' ("true" or "false").
     */
    public function UsernameExists()
    {
        $request_data = $this->RawXMWSToArray($this->wsdata);
        $contenttags = $this->XMLDataToArray($request_data['content']);
        $UsernameExists = module_controller::CheckUserExists($contenttags['username']);
        $response = "false";
        if ($UsernameExists) {
            $response = "true";
        }
        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', $response);
        return $dataobject->getDataObject();
    }
}

?>
