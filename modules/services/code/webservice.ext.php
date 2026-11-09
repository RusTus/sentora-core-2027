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
 * Services module: XMWS web service methods.
 */
class webservice extends ws_xmws
{

    /**
     * @var array<string, int> Service name => local TCP port checked by GetServiceStatus().
     */
    static $localports = array(
        'http' => 80,
		'https' => 443,
        'ftp' => 21,
        'dns' => 53,
        'smtp' => 25,
        'pop3' => 110,
        'imap' => 143,
        'imaps' => 993,
        'pop3s' => 995,
        'submission' => 587,
        'mysql' => 3306,
    );

    /**
     * Returns the status of all standard Sentora hosting ports and the current server uptime.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return array<string, string> Response data: 'response' (empty) and 'content' (portstatus section and serveruptime tag).
     */
    function GetServiceStatus()
    {
        $port_status = array();
        foreach (self::$localports as $key => $value) {
            $up = 0;
            if (sys_monitoring::LocalPortStatus($value)) {
                $up = 1;
            }
            $port_status[$key] = $up;
        }

        $response_xml = ws_xmws::NewXMLContentSection('portstatus', $port_status);
        $response_xml .= ws_xmws::NewXMLTag('serveruptime', sys_monitoring::ServerUptime());

        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', $response_xml);

        return $dataobject->getDataObject();
    }

    /**
     * Check one local port (request tag: port).
     *
     * @return array<string, string> Response data: 'response' (empty) and 'content' (portstatus section: port, status 1 or 0).
     */
    function GetPortStatus()
    {
        $request_data = $this->RawXMWSToArray($this->wsdata);
        $contenttags = $this->XMLDataToArray($this->wsdata);
        $port = $contenttags['xmws']['content']['port'] ?? '';
        if (!is_scalar($port) || !ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 65535) {
            return ws_xmws::Error('1107', 'The port must be a number from 1 to 65535');
        }
        if (sys_monitoring::LocalPortStatus((int) $port)) {
            $port_response = 1;
        } else {
            $port_response = 0;
        }
        $response_xml = ws_xmws::NewXMLContentSection('portstatus', array(
                'port' => $contenttags['xmws']['content']['port'],
                'status' => $port_response,
        ));
        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', $response_xml);

        return $dataobject->getDataObject();
    }
}

?>