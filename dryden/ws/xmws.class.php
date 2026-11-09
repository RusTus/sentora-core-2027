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
 *
 * Updated by RusTus, 2026-11-09
 */
class ws_xmws {

    /**
     * @var string|false Used to store the current RAW XML request data (false when the request had no body).
     */
    var $wsdata;

    /**
     * @var array<string, string> Used to store the array of request variables ('version', 'apikey', 'request', 'response', 'authuser', 'authpass', 'content').
     */
    var $wsdataarray;

    /**
     * @var module_controller Current module controller (the called module's webservice class)
     */
    var $currentmodule;

    /**
     * @var int|string Authenticated User ID (set by RequireUserAuth())
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

    /**
     * Return the API keys that are public (the default in Sentora's and ZPanel's database templates).
     * @return string[]
     */
    static function PublicKeys()
    {
        return array('ee8795c8c53bfdb3b2cc595186b68912');
    }

    /**
     * Requests that the web service method requires that the user must be authenticated wth the server.
     *
     * On failure it sends a 1105 response and ends the request (die).
     * @author Bobby Allen (ballen@bobbyallen.me) 
     * @return bool True when authenticated (otherwise the request ends).
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
     * @return bool
     */
    /** Wrong API keys allowed from one address within ctrl_loginguard::WINDOW (15 minutes); then it waits. */
    const KEY_LIMIT = 10;

    /**
     * Too many wrong API keys from this address lately? (Counted per address, not for the API as a
     * whole: otherwise anyone could lock the server's own integrations out with a few wrong keys.)
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return bool True when this address must wait.
     */
    static function KeyLocked()
    {
        global $zdbh;
        ctrl_loginguard::Ensure();
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_login_failures WHERE lf_user_vc = 'api:key' AND lf_ip_vc = :ip AND lf_time_in > :t");
        $q->execute(array(':ip' => ctrl_loginguard::Address(), ':t' => time() - ctrl_loginguard::WINDOW));
        return (int) $q->fetchColumn() >= self::KEY_LIMIT;
    }

    /**
     * An error answer.
     *
     * @param string $code The response code (1104 too many wrong keys, 1107 a value that is not allowed).
     * @param string $text What is wrong.
     * @return array The data object for SendResponse().
     */
    static function Error($code, $text)
    {
        $d = new runtime_dataobject();
        $d->addItemValue('response', $code);
        $d->addItemValue('content', self::Esc($text));
        return $d->getDataObject();
    }

    /**
     * Text for the XML answer: escaped, and valid UTF-8.
     *
     * @param mixed $v
     * @return string
     */
    static function Esc($v)
    {
        if (is_bool($v)) {
            $v = $v ? 'true' : 'false';
        }
        return (is_scalar($v) || $v === null) ? htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
    }

    /**
     * A live account the API may act on: it exists and is not deleted; with $notAdmin, not an
     * administrator either (the API never deletes, switches off or sets the password of an
     * administrator, so a leaked key can not lock the server's owner out or take the panel over).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param mixed $uid The account ID as sent.
     * @param bool $notAdmin Refuse administrators.
     * @return int The ID, or 0 when it may not be used.
     */
    static function Account($uid, $notAdmin = false)
    {
        global $zdbh;
        if (!is_scalar($uid) || !ctype_digit((string) $uid) || (int) $uid < 1) {
            return 0;
        }
        $q = $zdbh->prepare("SELECT ac_group_fk FROM x_accounts WHERE ac_id_pk = :id AND ac_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $uid));
        $g = $q->fetchColumn();
        if ($g === false || ($notAdmin && (int) $g === 1)) {
            return 0;
        }
        return (int) $uid;
    }

    public function CheckServerAPIKey() {
        // Sentora: the key must be this server's own (the installer and the daemon make a
        // random one). The key every Sentora shipped with is public, and with it alone the
        // API resets passwords, creates clients and domains and reads every setting, so it
        // is never accepted, nor a short or empty one; compared in constant time.
        $key = (string) ctrl_options::GetSystemOption('apikey');
        $given = isset($this->wsdataarray['apikey']) ? (string) $this->wsdataarray['apikey'] : '';
        if (strlen($key) < 32 || in_array(strtolower($key), self::PublicKeys(), true) || $given === '' || !hash_equals($key, $given)) {
            // counted for this address (KeyLocked()), and logged for fail2ban, which blocks an
            // address that keeps guessing at the firewall
            ctrl_loginguard::Failed('api:key');
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
     * Sends the Content-Type header and echoes the XML; an empty 'response' becomes '1101'.
     * @param array<string,string> $responsearray Array of data to send: 'response' (code) and 'content' (XML or text).
     * @return void
     */
    public function SendResponse($responsearray) {
        if ($responsearray['response'] == '')
            $responsearray['response'] = '1101';
        // (the panel's data is UTF-8; the answer said ISO-8859-1, which garbled accented names)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $GLOBALS['xmwsAnswered'] = true;
        header("Content-Type: text/xml; charset=utf-8");
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        $responsearray['response'] = preg_replace('/[^0-9]/', '', (string) $responsearray['response']);
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
        "<xmws>\n" .
        "\t<response>" . $responsearray['response'] . "</response>\n" .
        "\t<content>" . $responsearray['content'] . "</content>\n" .
        "</xmws>";
    }

    /**
     * Takes RAW XMWS XML request data and converts its contents into a usable data array.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $xml The RAW XML content.
     * @return array<string, string> Array containing all the request data that has been received.
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
     * @param array<string,string|int> $tags An associated array of the tag names and values to be added.
     * @return string A formatted XML section block which can then be used in the <content> tag if required.
     */
    static function NewXMLContentSection($name, $tags) {
        // values are escaped: a name or record with < or & broke the answer, or could add tags to it
        $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
        $xml = "\t<" . $name . ">\n";
        foreach ((array) $tags as $tagname => $tagval) {
            $tagname = preg_replace('/[^A-Za-z0-9_]/', '', (string) $tagname);
            if ($tagname === '' || ctype_digit($tagname[0])) {
                continue;
            }
            $xml .="\t\t<" . $tagname . ">" . self::Esc($tagval) . "</" . $tagname . ">\n";
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
        $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
        $xml = "\t<" . $name . ">" . self::Esc($value) . "</" . $name . ">\n";
        return $xml;
    }

    /**
     * Takes an XML string and converts it into a usable PHP array.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $contents The XML content to convert to a PHP array.
     * @param int $get_attributes Retieve the tag attrubtes too (1 = yes, 0 =no)?
     * @param string $priority What should take priority? 'tag' or attributes?
     * @return array<string, mixed>|null Associated array of the XML tag data; null when the XML could not be parsed.
     */
    function XMLDataToArray($contents, $get_attributes = 1, $priority = 'tag') {
        // A request's <content> part holds several tags side by side (<uid>3</uid><moveid>5</moveid>),
        // and an XML reader stops after the first one: the others were silently ignored (a client
        // was deleted with its accounts given to the default owner, not the one asked for). The
        // part is read inside one wrapper, so every tag counts; a whole request is read as it is.
        $contents = (string) $contents;
        if (strpos(ltrim($contents), '<?xml') === 0 || strpos(ltrim($contents), '<xmws') === 0) {
            return ws_generic::XMLToArray($contents, $get_attributes, $priority);
        }
        $all = ws_generic::XMLToArray('<xmwscontent>' . $contents . '</xmwscontent>', $get_attributes, $priority);
        return (is_array($all) && isset($all['xmwscontent']) && is_array($all['xmwscontent'])) ? $all['xmwscontent'] : array();
    }

}

?>