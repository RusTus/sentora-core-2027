<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * The ZPanel(X) (M)odular (W)eb (S)ervice class.
 * @package zpanelx
 * @subpackage dryden -> webservices
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
class ws_xmws {

    /**
     * @var string Used to store the current RAW XML request data.
     */
    var $wsdata;

    /**
     * @var string Used to store the array of request variables.
     */
    var $wsdataarray;

    /**
     * @var obj Current module controller
     */
    var $currentmodule;

    /**
     * @var int Authenticated User ID
     */
    var $authuserid;

    /**
     * Constructs the object setting the web service request data to a class variable.
     * @author Bobby Allen (ballen@bobbyallen.me)
     */
    function __construct() {
        $this->wsdata = ws_generic::ProcessRawRequest();
        $this->wsdataarray = $this->RawXMWSToArray($this->wsdata);
        $this->currentmodule = new module_controller;
    }

    /** API keys that are public (the default in Sentora's and ZPanel's database templates) */
    static function PublicKeys()
    {
        return array('ee8795c8c53bfdb3b2cc595186b68912');
    }

    /**
     * Requests that the web service method requires that the user must be authenticated wth the server.
     * @author Bobby Allen (ballen@bobbyallen.me) 
     */
    public function RequireUserAuth() {
        $ws_auth = new ctrl_auth;
        $user = $ws_auth->Authenticate($this->wsdataarray['authuser'], $this->wsdataarray['authpass']);
        if ($user) {
            $this->authuserid = $user;
            return true;
        } else {
            $dataobject = new runtime_dataobject();
            $dataobject->addItemValue('response', '1105');
            $dataobject->addItemValue('content', 'User authentication failed');
            die($this->SendResponse($dataobject->getDataObject()));
        }
    }

    /**
     * Checks that the Server API given in the webservice request XML is valid and matches the one stored in the x_settings table.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return boolean
     */
    public function CheckServerAPIKey() {
        // Sentora: the key must be this server's own (the installer and the daemon make a
        // random one). The key every Sentora shipped with is public, and with it alone the
        // API resets passwords, creates clients and domains and reads every setting, so it
        // is never accepted, nor a short or empty one; compared in constant time.
        $key = (string) ctrl_options::GetSystemOption('apikey');
        $given = isset($this->wsdataarray['apikey']) ? (string) $this->wsdataarray['apikey'] : '';
        if (strlen($key) < 32 || in_array(strtolower($key), self::PublicKeys(), true) || $given === '' || !hash_equals($key, $given)) {
            runtime_hook::Execute('OnBadAPIKeyAuth');
            return false;
        } else {
            runtime_hook::Execute('OnGoodAPIKeyAuth');
            return true;
        }
    }

    /**
     * Will format and send a valid XMWS XML response.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param array $responsearray Array of data to send.
     */
    public function SendResponse($responsearray) {
        if ($responsearray['response'] == '')
            $responsearray['response'] = '1101';
        header("Content-Type:text/xml");
        echo "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\n" .
        "<xmws>\n" .
        "\t<response>" . $responsearray['response'] . "</response>\n" .
        "\t<content>" . $responsearray['content'] . "</content>\n" .
        "</xmws>";
    }

    /**
     * Takes RAW XMWS XML request data and converts its contents into a usable data array.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $xml The RAW XML content.
     * @return array Array containing all the request data that has been received.
     */
    public function RawXMWSToArray($xml) {
        $return_dataobject = new runtime_dataobject();
        $return_dataobject->addItemValue('version', runtime_haystack::GetValueBetween($xml, '<version>', '</version>'));
        $return_dataobject->addItemValue('apikey', runtime_haystack::GetValueBetween($xml, '<apikey>', '</apikey>'));
        $return_dataobject->addItemValue('request', runtime_haystack::GetValueBetween($xml, '<request>', '</request>'));
        $return_dataobject->addItemValue('response', runtime_haystack::GetValueBetween($xml, '<response>', '</response>'));
        $return_dataobject->addItemValue('authuser', runtime_haystack::GetValueBetween($xml, '<authuser>', '</authuser>'));
        $return_dataobject->addItemValue('authpass', runtime_haystack::GetValueBetween($xml, '<authpass>', '</authpass>'));
        $return_dataobject->addItemValue('content', runtime_haystack::GetValueBetween($xml, '<content>', '</content>'));
        return $return_dataobject->getDataObject();
    }

    /**
     * A simple way to build an XML section for the <content> tag, perfect for multiple data lines etc.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $name The name of the section <tag>.
     * @param array $tags An associated array of the tag names and values to be added.
     * @return string A formatted XML section block which can then be used in the <content> tag if required.
     */
    static function NewXMLContentSection($name, $tags) {
        $xml = "\t<" . $name . ">\n";
        foreach ($tags as $tagname => $tagval) {
            $xml .="\t\t<" . $tagname . ">" . $tagval . "</" . $tagname . ">\n";
        }
        $xml .= "\t</" . $name . ">\n";
        return $xml;
    }

    /**
     * A simple way to build an XML tag, for simple single line XML data.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $name The name of the <tag>.
     * @param string $value The value of the <tag>.
     * @return string A formatted (single tab indented) XML line designed to be used in the <content> tag.
     */
    static function NewXMLTag($name, $value) {
        $xml = "\t<" . $name . ">" . $value . "</" . $name . ">\n";
        return $xml;
    }

    /**
     * Takes an XML string and converts it into a usable PHP array.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $contents The XML content to convert to a PHP array.
     * @param int $get_arrtibutes Retieve the tag attrubtes too (1 = yes, 0 =no)?
     * @param string $priotiry What should take priority? tag or attributes?
     * @return array Associated array of the XML tag data.
     */
    function XMLDataToArray($contents, $get_attributes = 1, $priority = 'tag') {
        return ws_generic::XMLToArray($contents, $get_attributes, $priority);
    }

}

?>