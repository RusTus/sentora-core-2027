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
 * Sentora: requests come as XML (<xmws>...</xmws>) or JSON ({"apikey": ..., "request": ...,
 * "content": {...}}) and are answered the same way. The key is the server's own (the administrator)
 * or one made on the API Access page (ctrl_apikeys): a reseller's key acts as the reseller and
 * reaches only its own clients (Target()). Every request is logged (ctrl_apikeys::Log()).
 *
 * Updated by RusTus, 2026-11-09
 */
class ws_xmws {

    /** The API's version, in every answer (2: keys of one's own, JSON, the checks below). */
    const VERSION = 2;

    /** Wrong API keys allowed from one address within ctrl_loginguard::WINDOW (15 minutes); then it waits. */
    const KEY_LIMIT = 10;

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

    /** @var int The account the request acts as (the key's owner; the administrator for the server's key). */
    static $actor = 0;

    /** @var int The key used (x_api_keys; 0 for the server's own key or none). */
    static $keyId = 0;

    /** @var bool Whether the request acts with an administrator's power. */
    static $admin = false;

    /** @var bool Whether the request came as JSON (and is answered so). */
    static $json = false;

    /** @var string The module and request, for the log. */
    static $module = '';
    static $request = '';

    /** @var string A note for the log (the account acted on, what was refused). */
    static $note = '';

    /**
     * Constructs the object setting the web service request data to a class variable.
     * A JSON request is turned into the XML one the modules read.
     * @author Bobby Allen (ballen@bobbyallen.me)
     */
    function __construct() {
        $raw = ws_generic::ProcessRawRequest();
        if (is_string($raw) && strpos(ltrim($raw), '{') === 0) {
            self::$json = true;
            $raw = self::JsonToXmws($raw);
        }
        $this->wsdata = $raw;
        $this->wsdataarray = $this->RawXMWSToArray($this->wsdata);
        $this->currentmodule = new module_controller;
    }

    /**
     * A JSON request as the XML request the modules read: {"apikey", "request", "version", "content"}.
     *
     * @param string $json
     * @return string The XML ('' when it is not a JSON object).
     */
    static function JsonToXmws($json)
    {
        $j = json_decode($json, true, 16);
        if (!is_array($j)) {
            return '';
        }
        $head = '';
        foreach (array('version', 'apikey', 'request') as $k) {
            if (isset($j[$k]) && is_scalar($j[$k])) {
                $head .= '<' . $k . '>' . self::Esc($j[$k]) . '</' . $k . '>';
            }
        }
        $content = isset($j['content']) ? $j['content'] : '';
        $body = is_array($content) ? self::ArrayToXml($content) : self::Esc($content);
        return '<?xml version="1.0" encoding="UTF-8"?><xmws>' . $head . '<content>' . $body . '</content></xmws>';
    }

    /**
     * Values as XML tags (lists repeat the tag; objects nest).
     *
     * @param array $a
     * @param int $depth (nesting is limited)
     * @return string
     */
    static function ArrayToXml(array $a, $depth = 0)
    {
        $out = '';
        if ($depth > 6) {
            return $out;
        }
        foreach ($a as $k => $v) {
            $k = preg_replace('/[^A-Za-z0-9_]/', '', (string) $k);
            if ($k === '' || ctype_digit($k[0])) {
                continue;
            }
            if (is_array($v) && array_keys($v) === range(0, count($v) - 1)) {
                foreach ($v as $item) {
                    $out .= '<' . $k . '>' . (is_array($item) ? self::ArrayToXml($item, $depth + 1) : self::Esc($item)) . '</' . $k . '>';
                }
            } elseif (is_array($v)) {
                $out .= '<' . $k . '>' . self::ArrayToXml($v, $depth + 1) . '</' . $k . '>';
            } else {
                $out .= '<' . $k . '>' . self::Esc($v) . '</' . $k . '>';
            }
        }
        return $out;
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
     * On failure it sends a 1105 response and ends the request (die). (No module uses it; requests
     * can not name it: api.php runs only a module's own requests.)
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
     * @param string $code The response code (1104 too many wrong keys, 1107 a value that is not
     *                     allowed, 1108 not allowed with this key).
     * @param string $text What is wrong.
     * @return array The data object for SendResponse().
     */
    static function Error($code, $text)
    {
        self::$note = mb_substr((string) $text, 0, 255);
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
     * A live account: it exists and is not deleted; with $notAdmin, not an administrator either
     * (the API never deletes, switches off or sets the password of an administrator, so a leaked
     * key can not lock the server's owner out or take the panel over). Not limited to the key's
     * reach: Target() is.
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

    /**
     * An account this request may act on: live (Account()), never an administrator unless $mayBeAdmin,
     * and with a reseller's key one of the reseller's own clients (or theirs, at any depth), never
     * the reseller itself nor an account above it (ctrl_privilege).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param mixed $uid The account ID as sent.
     * @param bool $mayBeAdmin Allow administrators (reading, with an administrator's key).
     * @return int The ID, or 0 when it may not be used.
     */
    static function Target($uid, $mayBeAdmin = false)
    {
        global $zdbh;
        $uid = self::Account($uid, !$mayBeAdmin);
        if ($uid === 0) {
            return 0;
        }
        if (self::$admin) {
            self::$note = 'account ' . $uid;
            return $uid;
        }
        if ($uid === self::$actor || !self::IsBelow($uid, self::$actor)) {
            return 0;
        }
        $g = $zdbh->prepare("SELECT ac_group_fk FROM x_accounts WHERE ac_id_pk = :id");
        $g->execute(array(':id' => self::$actor));
        if (ctrl_privilege::AccountAbove($uid, (int) $g->fetchColumn())) {
            return 0;
        }
        self::$note = 'account ' . $uid;
        return $uid;
    }

    /**
     * The key's own account, or an account it may reach (Target(), administrators allowed): for
     * requests about an account's own things (its domains, records, mailboxes...).
     *
     * @param mixed $uid The account ID as sent.
     * @return int The ID, or 0.
     */
    static function Reach($uid)
    {
        if (is_scalar($uid) && ctype_digit((string) $uid) && (int) $uid === self::$actor && self::Account($uid) !== 0) {
            self::$note = 'account ' . (int) $uid;
            return (int) $uid;
        }
        return self::Target($uid, true);
    }

    /**
     * The account a domain, sub-domain or parked domain belongs to, when the key may reach it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param mixed $id The vhost (x_vhosts) ID as sent.
     * @param int $type 1 domain, 2 sub-domain, 3 parked domain.
     * @return int The account, or 0.
     */
    static function VhostOwner($id, $type)
    {
        global $zdbh;
        if (!is_scalar($id) || !ctype_digit((string) $id)) {
            return 0;
        }
        $q = $zdbh->prepare("SELECT vh_acc_fk FROM x_vhosts WHERE vh_id_pk = :id AND vh_type_in = :t AND vh_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $id, ':t' => (int) $type));
        $owner = $q->fetchColumn();
        return $owner === false ? 0 : self::Reach((string) (int) $owner);
    }

    /**
     * Run the rest of the request as another account, for module code that works only on "the
     * logged-in account" (Sencrypt makes certificates for the account that asks; Backups backs it
     * up). Only ever for an account already checked with Reach() or Target(); in memory only, as
     * the request itself. The log still names the key's owner.
     *
     * @global runtime_controller $controller The request controller.
     * @param int $uid The account.
     * @return void
     */
    static function ActAs($uid)
    {
        global $controller;
        $_SESSION = array('zpuid' => (int) $uid);
        $controller = new runtime_controller();
        $controller->Init();
    }

    /**
     * Is account $uid below account $owner (its client, or a client's client...)?
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid
     * @param int $owner
     * @return bool
     */
    static function IsBelow($uid, $owner)
    {
        global $zdbh;
        $up = $zdbh->prepare("SELECT ac_reseller_fk FROM x_accounts WHERE ac_id_pk = :id");
        $seen = array();
        $cur = (int) $uid;
        while ($cur > 0 && !isset($seen[$cur]) && count($seen) < 12) {
            $seen[$cur] = true;
            $up->execute(array(':id' => $cur));
            $parent = (int) $up->fetchColumn();
            if ($parent === (int) $owner && $cur !== (int) $owner) {
                return true;
            }
            $cur = $parent;
        }
        return false;
    }

    /**
     * May this request do things only an administrator may (the server's settings, its totals, its
     * services, every domain)?
     *
     * @return bool
     */
    static function IsAdmin()
    {
        return self::$admin;
    }

    /**
     * The answer for a request only an administrator's key may make.
     *
     * @return array The data object for SendResponse().
     */
    static function AdminOnly()
    {
        return self::Error('1108', 'Only an administrator\'s key may make this request');
    }

    /**
     * Check the request's key: the server's own (the administrator), or a key made on the API Access
     * page that is live and allowed from this address. Sets who the request acts as.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return bool
     */
    public function CheckServerAPIKey() {
        // Sentora: the server's key must be its own (the installer and the daemon make a
        // random one). The key every Sentora shipped with is public, and with it alone the
        // API resets passwords, creates clients and domains and reads every setting, so it
        // is never accepted, nor a short or empty one; compared in constant time.
        $key = (string) ctrl_options::GetSystemOption('apikey');
        $given = isset($this->wsdataarray['apikey']) ? trim((string) $this->wsdataarray['apikey']) : '';
        if ($given !== '' && strlen($key) >= 32 && !in_array(strtolower($key), self::PublicKeys(), true) && hash_equals($key, $given)) {
            // (the first live administrator: asked directly, nobody is logged in yet)
            global $zdbh;
            $a = (int) $zdbh->query("SELECT ac_id_pk FROM x_accounts WHERE ac_group_fk = 1 AND ac_enabled_in = 1 AND ac_deleted_ts IS NULL ORDER BY ac_id_pk LIMIT 1")->fetchColumn();
            self::$actor = $a > 0 ? $a : 1;
            self::$keyId = 0;
            self::$admin = true;
            runtime_hook::Execute('OnGoodAPIKeyAuth');
            return true;
        }
        $found = $given !== '' ? ctrl_apikeys::Find($given) : null;
        if ($found && ctrl_apikeys::IpAllowed(ctrl_loginguard::Address(), $found['ips'])) {
            self::$actor = $found['uid'];
            self::$keyId = $found['id'];
            self::$admin = ctrl_branding::IsAdmin($found['uid']);
            ctrl_apikeys::Touch($found['id'], ctrl_loginguard::Address());
            runtime_hook::Execute('OnGoodAPIKeyAuth');
            return true;
        }
        if ($found) {
            // a right key from an address it is not allowed from: refused like a wrong one
            self::$keyId = $found['id'];
            self::$note = 'key not allowed from this address';
        }
        // counted for this address (KeyLocked()), and logged for fail2ban, which blocks an
        // address that keeps guessing at the firewall
        ctrl_loginguard::Failed('api:key');
        runtime_hook::Execute('OnBadAPIKeyAuth');
        return false;
    }

    /**
     * Will format and send a valid XMWS XML response (JSON for a JSON request), and log the request.
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
        $code = preg_replace('/[^0-9]/', '', (string) $responsearray['response']);
        ctrl_apikeys::Log(self::$keyId, self::$actor, self::$module, self::$request, $code, self::$note);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        if (self::$json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('version' => self::VERSION, 'response' => $code, 'content' => self::ContentToArray((string) $responsearray['content'])),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }
        header("Content-Type: text/xml; charset=utf-8");
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
        "<xmws>\n" .
        "\t<version>" . self::VERSION . "</version>\n" .
        "\t<response>" . $code . "</response>\n" .
        "\t<content>" . $responsearray['content'] . "</content>\n" .
        "</xmws>";
    }

    /**
     * An answer's content for JSON: its tags as an object (a repeated tag becomes a list), or its text.
     *
     * @param string $content The XML content.
     * @return mixed
     */
    static function ContentToArray($content)
    {
        if (strpos($content, '<') === false) {
            return html_entity_decode(trim($content), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }
        $a = ws_generic::XMLToArray('<xmwscontent>' . $content . '</xmwscontent>');
        if (!is_array($a) || !isset($a['xmwscontent'])) {
            return null;
        }
        // (an empty tag comes back from the XML reader as an empty list: it is an empty value)
        $fix = function ($v) use (&$fix) {
            if (!is_array($v)) {
                return $v;
            }
            if ($v === array()) {
                return '';
            }
            foreach ($v as $k => $x) {
                $v[$k] = $fix($x);
            }
            return $v;
        };
        return $fix($a['xmwscontent']);
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
     * One value of the request's content (decoded: &amp; is &).
     *
     * @param string $name The tag.
     * @return string|null Null when it was not sent.
     */
    function Get($name)
    {
        $v = ws_generic::GetTagValue($name, (string) $this->wsdataarray['content']);
        return $v === null ? null : trim($v);
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
