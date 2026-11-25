<?php

/**
 * Mailboxes on Windows (Stalwart): the panel's pages write the same mail database as on Linux (mailserver_db,
 * sentora_postfix: its domain and mailbox tables, through mailserver_php = postfix.php), and the daemon (SYSTEM) brings
 * Stalwart's domains and accounts in line with it through Stalwart's own API, as the administrator whose password only
 * root's jobs can read (configs/stalwart/admin.txt). The mailbox's SHA-512 crypt hash is Stalwart's secret as it is (no
 * plain password is kept anywhere); a mailbox switched off gets a random secret nobody knows until it is on again. Only
 * the accounts the panel made (listed in its state file) are changed or removed: mailboxes added with mailuser.ps1 are
 * left alone. Not built yet: aliases, forwarders and lists. Nothing here runs on Linux.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-25
 */
class sys_winmail
{
    /** What was last sent for each mailbox, and which accounts the panel made (root's jobs only). */
    const STATE = '/var/lib/sentora/phpaccounts/mail-state.json';

    /** @var array{0: string, 1: string}|null The administrator for this run. */
    private static $admin = null;

    /** @var string|null Stalwart's account id for its own API. */
    private static $acct = null;

    /** @var string What Stalwart said about the last call it refused. */
    public static $why = '';

    /**
     * Stalwart's API (mail_api; the installer's: https://127.0.0.1:8443, this server only).
     *
     * @return string
     */
    static function Base()
    {
        $b = rtrim((string) ctrl_options::GetSystemOption('mail_api'), '/');
        return preg_match('#^https://(127\.0\.0\.1|localhost)(:[0-9]{1,5})?$#', $b) ? $b : 'https://127.0.0.1:8443';
    }

    /**
     * Stalwart's administrator, from the file the installer writes (mail_admin; root's jobs only).
     *
     * @return array{0: string, 1: string}|null
     */
    static function Admin()
    {
        $f = (string) ctrl_options::GetSystemOption('mail_admin');
        if ($f === '') {
            $f = dirname(dirname((string) ctrl_options::GetSystemOption('apache_vhost'))) . '/stalwart/admin.txt';
        }
        $s = is_file($f) ? (string) @file_get_contents($f) : '';
        $u = preg_match('/^user=(.+)$/m', $s, $m) ? trim($m[1]) : '';
        $p = preg_match('/^password=(.+)$/m', $s, $m) ? trim($m[1]) : '';
        return ($u !== '' && $p !== '') ? array($u, $p) : null;
    }

    /**
     * One HTTPS request to Stalwart on this server (its certificate is the server's own, so it is not checked: the
     * address is always the loopback one).
     *
     * @param string $path
     * @param string|null $body JSON.
     * @return array{0: int, 1: mixed}
     */
    static function Http($path, $body = null)
    {
        $h = curl_init(self::Base() . $path);
        $opt = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_USERPWD => self::$admin[0] . ':' . self::$admin[1],
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0);
        if ($body !== null) {
            $opt[CURLOPT_POST] = true;
            $opt[CURLOPT_POSTFIELDS] = $body;
            $opt[CURLOPT_HTTPHEADER] = array('Content-Type: application/json; charset=utf-8');
        }
        curl_setopt_array($h, $opt);
        $raw = (string) curl_exec($h);
        $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
        curl_close($h);
        return array($code, json_decode($raw, true));
    }

    /**
     * One call of Stalwart's management API (JMAP, urn:stalwart:jmap).
     *
     * @param string $method Like x:Account/get.
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null The answer, or null when it was refused.
     */
    static function Call($method, $args)
    {
        $args['accountId'] = self::$acct;
        $body = json_encode(array('using' => array('urn:ietf:params:jmap:core', 'urn:stalwart:jmap'), 'methodCalls' => array(array($method, (object) $args, 'c0'))));
        list($code, $r) = self::Http('/jmap/', $body);
        if ($code !== 200 || !isset($r['methodResponses'][0]) || $r['methodResponses'][0][0] === 'error') {
            self::$why = substr($code . ' ' . json_encode(isset($r['methodResponses'][0][1]) ? $r['methodResponses'][0][1] : $r), 0, 300);
            return null;
        }
        $x = (array) $r['methodResponses'][0][1];
        foreach (array('notCreated', 'notUpdated', 'notDestroyed') as $k) {
            if (!empty($x[$k])) {
                self::$why = substr(json_encode($x[$k]), 0, 300);
                return null;
            }
        }
        return $x;
    }

    /**
     * The daemon's part: Stalwart's domains and the panel's mailboxes in line with the mail database.
     *
     * @return string[] Lines for the daemon's log.
     */
    static function Sync()
    {
        global $zdbh;
        $out = array();
        if (!sys_versions::IsWindows()) {
            return $out;
        }
        $db = (string) ctrl_options::GetSystemOption('mailserver_db');
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)) {
            return $out;
        }
        try {
            $boxes = $zdbh->query("SELECT username, password, active FROM `" . $db . "`.mailbox")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return array('Mail: no mail database ' . $db);
        }
        if ($boxes === false) {
            return array('Mail: no mail database ' . $db);
        }
        $state = is_file(self::STATE) ? (array) json_decode((string) @file_get_contents(self::STATE), true) : array();
        $mine = isset($state['mine']) ? (array) $state['mine'] : array();
        $want = array();
        foreach ($boxes as $b) {
            $a = strtolower((string) $b['username']);
            if (!preg_match('/^([a-z0-9][a-z0-9._-]{0,63})@(([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62})$/', $a)) {
                continue;
            }
            $hash = preg_replace('/^\{SHA512-CRYPT\}/', '', (string) $b['password']);
            $want[$a] = array('hash' => strpos($hash, '$6$') === 0 ? $hash : '', 'on' => (int) $b['active'] === 1);
        }
        if (!$want && !$mine) {
            return $out;
        }
        self::$admin = self::Admin();
        if (self::$admin === null) {
            return array('Mail: no Stalwart administrator in its settings file');
        }
        list($code, $s) = self::Http('/jmap/session');
        self::$acct = ($code === 200 && isset($s['primaryAccounts']['urn:stalwart:jmap'])) ? (string) $s['primaryAccounts']['urn:stalwart:jmap'] : null;
        if (self::$acct === null) {
            return array('Mail: Stalwart does not answer (' . $code . '): nothing changed');
        }
        $doms = array();
        $r = self::Call('x:Domain/get', array('properties' => array('name')));
        foreach ((array) (isset($r['list']) ? $r['list'] : array()) as $d) {
            $doms[strtolower((string) $d['name'])] = (string) $d['id'];
        }
        $accts = array();
        $r = self::Call('x:Account/get', array('properties' => array('name', 'emailAddress')));
        if ($r === null) {
            return array('Mail: Stalwart did not list its accounts: nothing changed');
        }
        foreach ((array) (isset($r['list']) ? $r['list'] : array()) as $x) {
            if (!empty($x['emailAddress'])) {
                $accts[strtolower((string) $x['emailAddress'])] = (string) $x['id'];
            }
        }
        $sums = isset($state['sums']) ? (array) $state['sums'] : array();
        $made = $changed = $gone = 0;
        foreach ($want as $a => $w) {
            list($local, $domain) = explode('@', $a, 2);
            if ($w['hash'] === '') {
                $out[] = 'Mail: ' . $a . ' has no password the panel can pass on yet (reset it on the Mailboxes page)';
                continue;
            }
            // (switched off: a random secret nobody knows; on again: the mailbox's own hash, in the form Stalwart takes:
            // with the scheme first, as Dovecot writes it, or the crypt hash alone)
            $secrets = $w['on'] ? array('{SHA512-CRYPT}' . $w['hash'], $w['hash']) : array(bin2hex(random_bytes(24)));
            $sum = hash('sha256', $w['hash'] . '|' . ($w['on'] ? 1 : 0));
            if (isset($accts[$a]) && !isset($mine[$a])) {
                continue;   // (made with mailuser.ps1: not the panel's)
            }
            if (!isset($accts[$a])) {
                if (!isset($doms[$domain])) {
                    $r = self::Call('x:Domain/set', array('create' => array('d' => array('name' => $domain))));
                    if ($r === null || empty($r['created']['d']['id'])) {
                        $out[] = 'Mail: Stalwart refused the domain ' . $domain;
                        continue;
                    }
                    $doms[$domain] = (string) $r['created']['d']['id'];
                    $out[] = 'Mail: domain ' . $domain . ' added';
                }
                $r = null;
                $whys = array();
                foreach ($secrets as $secret) {
                    $r = self::Call('x:Account/set', array('create' => array('u' => array('@type' => 'User', 'name' => $local, 'domainId' => $doms[$domain],
                        'credentials' => array('0' => array('@type' => 'Password', 'secret' => $secret))))));
                    if ($r !== null && !empty($r['created']['u']['id'])) {
                        break;
                    }
                    $whys[] = self::$why;
                    $r = null;
                }
                if ($r === null) {
                    $out[] = 'Mail: Stalwart refused the mailbox ' . $a . ': ' . implode(' | ', $whys);
                    continue;
                }
                $mine[$a] = true;
                $sums[$a] = $sum;
                $made++;
            } elseif (!isset($sums[$a]) || $sums[$a] !== $sum) {
                $r = null;
                $whys = array();
                foreach ($secrets as $secret) {
                    $r = self::Call('x:Account/set', array('update' => array($accts[$a] => array('credentials' => array('0' => array('@type' => 'Password', 'secret' => $secret))))));
                    if ($r !== null) {
                        break;
                    }
                    $whys[] = self::$why;
                }
                if ($r === null) {
                    $out[] = 'Mail: Stalwart refused the change of ' . $a . ': ' . implode(' | ', $whys);
                    continue;
                }
                $sums[$a] = $sum;
                $changed++;
            }
        }
        foreach (array_keys($mine) as $a) {
            if (!isset($want[$a])) {
                if (isset($accts[$a]) && self::Call('x:Account/set', array('destroy' => array($accts[$a]))) === null) {
                    $out[] = 'Mail: Stalwart refused to remove ' . $a . ': ' . self::$why;
                    continue;
                }
                unset($mine[$a], $sums[$a]);
                $gone++;
            }
        }
        $new = array('mine' => $mine, 'sums' => $sums);
        if ($new != $state) {
            if (!is_dir(dirname(self::STATE))) {
                @mkdir(dirname(self::STATE), 0700, true);
            }
            @file_put_contents(self::STATE, json_encode($new));
        }
        if ($made || $changed || $gone) {
            $out[] = 'Mail: Stalwart mailboxes made ' . $made . ', changed ' . $changed . ', removed ' . $gone . ' (' . count($want) . ' in the panel)';
        }
        return $out;
    }
}
