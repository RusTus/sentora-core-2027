<?php

/**
 * API keys and the API log.
 *
 * Besides the server's own key (the setting apikey, the administrator's), every account that can
 * have clients (ctrl_support::CanSet: administrators and resellers) can make named keys on the API
 * Access page, one per program that uses the API, each revocable on its own:
 * - a key is shown once, when it is made; only its SHA-256 is kept (and its first characters, to
 *   tell keys apart), so neither the database nor a backup holds a usable key;
 * - a key may be limited to addresses (IPs or ranges such as 203.0.113.0/24);
 * - a reseller's key acts as the reseller and reaches only its own clients (ws_xmws::Target());
 *   an administrator's key acts as the administrator;
 * - each use is logged (x_api_log: when, which key, from where, the request and its answer code),
 *   kept for API_LOG_DAYS days.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class ctrl_apikeys
{
    /** Keys an account may have. */
    const MAX = 20;

    /** Days the API log is kept. */
    const LOG_DAYS = 90;

    /** The start of every key made here (the server's own key has none). */
    const PREFIX = 'sk_';

    /**
     * Create the x_api_keys and x_api_log tables if missing (once per request).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if ($done) {
            return;
        }
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_api_keys (
            ak_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ak_acc_fk INT NOT NULL,
            ak_label_vc VARCHAR(120) NOT NULL DEFAULT '',
            ak_hash_vc CHAR(64) NOT NULL,
            ak_start_vc VARCHAR(16) NOT NULL DEFAULT '',
            ak_ips_tx TEXT NULL,
            ak_enabled_in TINYINT NOT NULL DEFAULT 1,
            ak_created_ts INT NULL,
            ak_used_ts INT NULL,
            ak_usedip_vc VARCHAR(45) NOT NULL DEFAULT '',
            UNIQUE KEY ak_hash (ak_hash_vc), KEY ak_acc (ak_acc_fk)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_api_log (
            al_id_pk BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            al_time_in INT NOT NULL,
            al_key_fk INT NOT NULL DEFAULT 0,
            al_acc_fk INT NOT NULL DEFAULT 0,
            al_ip_vc VARCHAR(45) NOT NULL DEFAULT '',
            al_module_vc VARCHAR(60) NOT NULL DEFAULT '',
            al_request_vc VARCHAR(60) NOT NULL DEFAULT '',
            al_code_vc VARCHAR(8) NOT NULL DEFAULT '',
            al_note_vc VARCHAR(255) NOT NULL DEFAULT '',
            KEY al_acc (al_acc_fk, al_time_in), KEY al_time (al_time_in)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    /**
     * Clean a list of allowed addresses: IPv4/IPv6 addresses and ranges (a.b.c.d/n), one per line or
     * separated by commas or spaces.
     *
     * @param string $text What was typed.
     * @return string|false The list (one per line; '' for any address), or false when one is not an address.
     */
    static function CleanIps($text)
    {
        $out = array();
        foreach (preg_split('/[\s,;]+/', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY) as $item) {
            $parts = explode('/', $item, 2);
            $ip = $parts[0];
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return false;
            }
            if (isset($parts[1])) {
                $max = strpos($ip, ':') !== false ? 128 : 32;
                if (!ctype_digit($parts[1]) || (int) $parts[1] > $max) {
                    return false;
                }
                $item = $ip . '/' . (int) $parts[1];
            }
            $out[$item] = true;
        }
        if (count($out) > 50) {
            return false;
        }
        return implode("\n", array_keys($out));
    }

    /**
     * Is the address in the list (addresses and ranges)?
     *
     * @param string $ip The address.
     * @param string $list From CleanIps(); '' allows any address.
     * @return bool
     */
    static function IpAllowed($ip, $list)
    {
        $list = trim((string) $list);
        if ($list === '') {
            return true;
        }
        $bin = @inet_pton((string) $ip);
        if ($bin === false) {
            return false;
        }
        foreach (explode("\n", $list) as $item) {
            $parts = explode('/', trim($item), 2);
            $net = @inet_pton($parts[0]);
            if ($net === false || strlen($net) !== strlen($bin)) {
                continue;
            }
            $bits = isset($parts[1]) ? (int) $parts[1] : strlen($bin) * 8;
            $bytes = intdiv($bits, 8);
            if (substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((ord($bin[$bytes]) ^ ord($net[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Make a key for an account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account (one that can have clients).
     * @param string $label What the key is for (a program's name).
     * @param string $ips Allowed addresses ('' for any).
     * @return array{key?:string, error?:string} The key (shown once) or what is wrong: 'label', 'ips', 'full', 'account'.
     */
    static function Create($uid, $label, $ips)
    {
        global $zdbh;
        self::Ensure();
        if (!ctrl_support::CanSet($uid)) {
            return array('error' => 'account');
        }
        $label = trim(preg_replace('/\s+/u', ' ', (string) $label));
        if ($label === '' || mb_strlen($label) > 60 || preg_match('/[\x00-\x1F\x7F<>\p{Cf}]/u', $label)) {
            return array('error' => 'label');
        }
        $ips = self::CleanIps($ips);
        if ($ips === false) {
            return array('error' => 'ips');
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_api_keys WHERE ak_acc_fk = :u");
        $q->execute(array(':u' => (int) $uid));
        if ((int) $q->fetchColumn() >= self::MAX) {
            return array('error' => 'full');
        }
        $key = self::PREFIX . bin2hex(random_bytes(24));
        $zdbh->prepare("INSERT INTO x_api_keys (ak_acc_fk, ak_label_vc, ak_hash_vc, ak_start_vc, ak_ips_tx, ak_enabled_in, ak_created_ts)
            VALUES (:u, :l, :h, :s, :i, 1, :t)")->execute(array(':u' => (int) $uid, ':l' => $label, ':h' => hash('sha256', $key),
            ':s' => substr($key, 0, 11), ':i' => $ips, ':t' => time()));
        return array('key' => $key);
    }

    /**
     * An account's keys (an administrator: with $all, everyone's).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account.
     * @param bool $all Every account's (administrators).
     * @return array<int, array{id:int, uid:int, user:string, label:string, start:string, ips:string, enabled:bool, created:int, used:int, usedip:string}>
     */
    static function Keys($uid, $all = false)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT k.*, a.ac_user_vc FROM x_api_keys k LEFT JOIN x_accounts a ON a.ac_id_pk = k.ak_acc_fk"
            . ($all ? '' : ' WHERE k.ak_acc_fk = :u') . " ORDER BY a.ac_user_vc, k.ak_id_pk");
        $q->execute($all ? array() : array(':u' => (int) $uid));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = array('id' => (int) $r['ak_id_pk'], 'uid' => (int) $r['ak_acc_fk'], 'user' => (string) $r['ac_user_vc'],
                'label' => (string) $r['ak_label_vc'], 'start' => (string) $r['ak_start_vc'], 'ips' => (string) $r['ak_ips_tx'],
                'enabled' => (int) $r['ak_enabled_in'] === 1, 'created' => (int) $r['ak_created_ts'], 'used' => (int) $r['ak_used_ts'],
                'usedip' => (string) $r['ak_usedip_vc']);
        }
        return $out;
    }

    /**
     * Switch a key off or on, or delete it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account acting (its own keys; an administrator: any).
     * @param int $id The key.
     * @param string $what 'off', 'on' or 'delete'.
     * @return bool Whether a key was changed.
     */
    static function Change($uid, $id, $what)
    {
        global $zdbh;
        self::Ensure();
        $own = ctrl_branding::IsAdmin($uid) ? '' : ' AND ak_acc_fk = :u';
        $a = array(':id' => (int) $id);
        if ($own !== '') {
            $a[':u'] = (int) $uid;
        }
        if ($what === 'delete') {
            $q = $zdbh->prepare("DELETE FROM x_api_keys WHERE ak_id_pk = :id" . $own);
        } elseif ($what === 'off' || $what === 'on') {
            $q = $zdbh->prepare("UPDATE x_api_keys SET ak_enabled_in = " . ($what === 'on' ? 1 : 0) . " WHERE ak_id_pk = :id" . $own);
        } else {
            return false;
        }
        $q->execute($a);
        return $q->rowCount() > 0;
    }

    /**
     * The key a request sent, when it is a live key of a live account that may still have keys.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $key The key as sent.
     * @return array{id:int, uid:int, ips:string}|null
     */
    static function Find($key)
    {
        global $zdbh;
        $key = (string) $key;
        if (strpos($key, self::PREFIX) !== 0 || strlen($key) !== strlen(self::PREFIX) + 48 || !ctype_xdigit(substr($key, strlen(self::PREFIX)))) {
            return null;
        }
        self::Ensure();
        // (looked up by its hash: the key itself is never stored)
        $q = $zdbh->prepare("SELECT k.ak_id_pk, k.ak_acc_fk, k.ak_ips_tx FROM x_api_keys k JOIN x_accounts a ON a.ac_id_pk = k.ak_acc_fk
            WHERE k.ak_hash_vc = :h AND k.ak_enabled_in = 1 AND a.ac_enabled_in = 1 AND a.ac_deleted_ts IS NULL");
        $q->execute(array(':h' => hash('sha256', $key)));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r || !ctrl_support::CanSet((int) $r['ak_acc_fk'])) {
            return null;
        }
        return array('id' => (int) $r['ak_id_pk'], 'uid' => (int) $r['ak_acc_fk'], 'ips' => (string) $r['ak_ips_tx']);
    }

    /**
     * Note a key's use (when and from where).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id The key.
     * @param string $ip The address.
     * @return void
     */
    static function Touch($id, $ip)
    {
        global $zdbh;
        $zdbh->prepare("UPDATE x_api_keys SET ak_used_ts = :t, ak_usedip_vc = :ip WHERE ak_id_pk = :id")
            ->execute(array(':t' => time(), ':ip' => substr((string) $ip, 0, 45), ':id' => (int) $id));
    }

    /**
     * Log one API request.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $keyId The key (0: the server's own key, or none that was right).
     * @param int $uid The account the request acted as (0: none).
     * @param string $module The module.
     * @param string $request The request.
     * @param string $code The answer's code.
     * @param string $note A short note (what was refused, the account acted on).
     * @return void
     */
    static function Log($keyId, $uid, $module, $request, $code, $note = '')
    {
        global $zdbh;
        try {
            self::Ensure();
            $clean = function ($s, $n) { return mb_substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $s), 0, $n); };
            $zdbh->prepare("INSERT INTO x_api_log (al_time_in, al_key_fk, al_acc_fk, al_ip_vc, al_module_vc, al_request_vc, al_code_vc, al_note_vc)
                VALUES (:t, :k, :u, :ip, :m, :r, :c, :n)")->execute(array(':t' => time(), ':k' => (int) $keyId, ':u' => (int) $uid,
                ':ip' => ctrl_loginguard::Address(), ':m' => $clean($module, 60), ':r' => $clean($request, 60), ':c' => $clean($code, 8), ':n' => $clean($note, 255)));
            // about one request in a hundred tidies the old lines
            if (random_int(1, 100) === 1) {
                $zdbh->prepare("DELETE FROM x_api_log WHERE al_time_in < :old")->execute(array(':old' => time() - self::LOG_DAYS * 86400));
            }
        } catch (Exception $e) {
        }
    }

    /**
     * The latest API requests: an account's own keys' (an administrator: with $all, every one,
     * also those with the server's key and the refused ones).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account.
     * @param bool $all Every request (administrators).
     * @param int $limit How many.
     * @return array<int, array{time:int, key:string, user:string, ip:string, module:string, request:string, code:string, note:string}>
     */
    static function Logs($uid, $all = false, $limit = 100)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT l.*, k.ak_label_vc, a.ac_user_vc FROM x_api_log l LEFT JOIN x_api_keys k ON k.ak_id_pk = l.al_key_fk
            LEFT JOIN x_accounts a ON a.ac_id_pk = l.al_acc_fk" . ($all ? '' : ' WHERE l.al_acc_fk = :u AND l.al_key_fk > 0')
            . " ORDER BY l.al_id_pk DESC LIMIT " . max(1, min(500, (int) $limit)));
        $q->execute($all ? array() : array(':u' => (int) $uid));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = (int) $r['al_key_fk'] === 0 ? ((string) $r['al_code_vc'] === '1103' || (string) $r['al_code_vc'] === '1104' ? '' : 'server key') : (string) $r['ak_label_vc'];
            $out[] = array('time' => (int) $r['al_time_in'], 'key' => $key, 'user' => (string) $r['ac_user_vc'], 'ip' => (string) $r['al_ip_vc'],
                'module' => (string) $r['al_module_vc'], 'request' => (string) $r['al_request_vc'], 'code' => (string) $r['al_code_vc'], 'note' => (string) $r['al_note_vc']);
        }
        return $out;
    }
}
