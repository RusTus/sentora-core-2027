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
 * Packages: the API's package requests. Packages belong to the key's own account (an administrator's
 * key may also list another account's); each comes with its limits (ws_data::Package(): counts, and
 * disk and bandwidth in MB; -1 is "no limit").
 *
 * Updated by RusTus, 2026-11-09
 */
class webservice extends ws_xmws
{

    /** The limits a package request may set: request tag => is a size (MB). */
    const LIMITS = array('domains' => false, 'subdomains' => false, 'parkeddomains' => false, 'mailboxes' => false, 'forwarders' => false,
        'distlists' => false, 'ftpaccounts' => false, 'mysql' => false, 'diskspacemb' => true, 'bandwidthmb' => true);

    /**
     * GetAllPackages: optional <uid> (whose packages; by default the key's own account). Each with
     * its limits; the old <id> and <pakage> tags are kept.
     *
     * @return array
     */
    public function GetAllPackages()
    {
        $owner = (string) $this->Get('uid') === '' || (int) $this->Get('uid') === self::$actor ? self::$actor : self::Target($this->Get('uid'), true);
        if ($owner === 0) {
            return self::Error('1107', 'No such account whose packages you may list');
        }
        $xml = "\n";
        foreach (ws_data::Packages($owner) as $p) {
            $xml .= self::NewXMLContentSection('packages', array('id' => $p['id'], 'pakage' => $p['name']) + $p);
        }
        return $this->Answer($xml);
    }

    /**
     * GetPackageId: <packagename> (also the old spelling <pakagename>), among the key's own packages.
     *
     * @return array
     */
    public function GetPackageId()
    {
        $name = $this->Get('packagename');
        if ($name === null) {
            $name = $this->Get('pakagename');
        }
        $id = 0;
        foreach (ws_data::Packages(self::$actor) as $p) {
            if ($p['name'] === (string) $name) {
                $id = $p['id'];
            }
        }
        return $this->Answer(self::NewXMLTag('pakageid', $id) . self::NewXMLTag('packageid', $id));
    }

    /**
     * GetPackage: <packageid> (one of the key's own packages; an administrator's key: any).
     *
     * @return array
     */
    public function GetPackage()
    {
        $id = $this->Get('packageid');
        $p = is_scalar($id) && ctype_digit((string) $id) ? ws_data::Package((int) $id) : null;
        if (!$p || (!self::$admin && $p['owner'] !== self::$actor)) {
            return self::Error('1107', 'No such package of yours');
        }
        return $this->Answer(self::NewXMLContentSection('package', $p));
    }

    /**
     * The limits sent (the others from $current), checked: counts -1 (no limit) or 0 and more; sizes
     * in MB, -1 or 0 for no limit.
     *
     * @param array|null $current The package's limits now (null: a new package, all must be sent).
     * @return array|string The limits as the Packages page takes them (sizes: 0 = no limit), or what is wrong.
     */
    private function Limits($current)
    {
        $out = array();
        foreach (self::LIMITS as $k => $size) {
            $v = $this->Get($k);
            if ($v === null || $v === '') {
                if ($current === null) {
                    return $k . ' is needed (-1 for no limit)';
                }
                $v = (string) $current[$k];
            }
            if ($size) {
                if (!preg_match('/^-?\d{1,9}(\.\d{1,3})?$/', $v) || (float) $v < -1) {
                    return $k . ': a size in MB, or -1 for no limit';
                }
                $out[$k] = (float) $v <= 0 ? 0 : (float) $v;
            } else {
                if (!preg_match('/^-?\d{1,6}$/', $v) || (int) $v < -1) {
                    return $k . ': a whole number, 0 for none or -1 for no limit';
                }
                $out[$k] = (int) $v;
            }
        }
        return $out;
    }

    /**
     * What the Packages page said when it refused.
     *
     * @return string
     */
    private static function Why()
    {
        if (!empty(module_controller::$alreadyexists)) {
            return 'You have a package of that name already';
        }
        if (!empty(module_controller::$badname) || !empty(module_controller::$blank)) {
            return 'name: letters, digits, spaces, - and _';
        }
        if (!empty(module_controller::$samepackage)) {
            return 'The package has clients: give moveto, another of your packages for them';
        }
        return 'The panel refused it';
    }

    /**
     * CreatePackage: <name>, <php> (true/false), and every limit: <domains>, <subdomains>,
     * <parkeddomains>, <mailboxes>, <forwarders>, <distlists>, <ftpaccounts>, <mysql>, <diskspacemb>,
     * <bandwidthmb> (-1: no limit). The package is the key's own account's. Answers its <packageid>.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function CreatePackage()
    {
        global $zdbh;
        $L = $this->Limits(null);
        if (is_string($L)) {
            return self::Error('1107', $L);
        }
        $name = (string) $this->Get('name');
        if (!module_controller::ExecuteCreatePackage(self::$actor, $name, $this->Get('php') === 'true' ? 1 : 0, $L['domains'], $L['subdomains'], $L['parkeddomains'],
            $L['mailboxes'], $L['forwarders'], $L['distlists'], $L['ftpaccounts'], $L['mysql'], $L['diskspacemb'], $L['bandwidthmb'])) {
            return self::Error('1107', self::Why());
        }
        $q = $zdbh->prepare("SELECT MAX(pk_id_pk) FROM x_packages WHERE pk_reseller_fk = :u AND pk_name_vc = :n AND pk_deleted_ts IS NULL");
        $q->execute(array(':u' => self::$actor, ':n' => module_controller::CleanName($name)));
        $id = (int) $q->fetchColumn();
        self::$note = 'package ' . $id;
        return $this->Answer(self::NewXMLTag('created', 'true') . self::NewXMLTag('packageid', $id));
    }

    /**
     * UpdatePackage: <packageid> (one of the key's own) and any of <name>, <php> and the limits (the
     * others stay). Its clients get the new limits at once.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function UpdatePackage()
    {
        global $zdbh;
        $id = ws_data::OwnPackage($this->Get('packageid'), self::$actor);
        if ($id === 0) {
            return self::Error('1107', 'No such package of yours');
        }
        $p = ws_data::Package($id);
        $L = $this->Limits($p);
        if (is_string($L)) {
            return self::Error('1107', $L);
        }
        $name = $this->Get('name') !== null && $this->Get('name') !== '' ? (string) $this->Get('name') : $p['name'];
        $php = $this->Get('php') !== null && $this->Get('php') !== '' ? ($this->Get('php') === 'true' ? 1 : 0) : ($p['php'] === 'true' ? 1 : 0);
        if (!module_controller::ExecuteUpdatePackage(self::$actor, $id, $name, $php, $L['domains'], $L['subdomains'], $L['parkeddomains'],
            $L['mailboxes'], $L['forwarders'], $L['distlists'], $L['ftpaccounts'], $L['mysql'], $L['diskspacemb'], $L['bandwidthmb'])) {
            return self::Error('1107', self::Why());
        }
        $zdbh->exec("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc = 'apache_changed'");
        self::$note = 'package ' . $id;
        return $this->Answer(self::NewXMLTag('packageid', $id) . self::NewXMLTag('updated', 'true'));
    }

    /**
     * DeletePackage: <packageid> (one of the key's own), <moveto> (another of the key's own packages,
     * for its clients; needed when it has any).
     *
     * @return array
     */
    public function DeletePackage()
    {
        $id = ws_data::OwnPackage($this->Get('packageid'), self::$actor);
        if ($id === 0) {
            return self::Error('1107', 'No such package of yours');
        }
        $move = 0;
        if ((string) $this->Get('moveto') !== '') {
            $move = ws_data::OwnPackage($this->Get('moveto'), self::$actor);
            if ($move === 0 || $move === $id) {
                return self::Error('1107', 'moveto is not another of your packages');
            }
        }
        if (!module_controller::ExecuteDeletePackage($id, $move)) {
            return self::Error('1107', self::Why());
        }
        self::$note = 'package ' . $id;
        return $this->Answer(self::NewXMLTag('packageid', $id) . self::NewXMLTag('deleted', 'true'));
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
