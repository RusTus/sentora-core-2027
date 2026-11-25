<?php

/**
 * Mailboxes on Windows (Stalwart): the panel's pages write the same mail database as on Linux (mailserver_db,
 * sentora_postfix: its domain and mailbox tables, through mailserver_php = postfix.php), and the daemon (SYSTEM) brings
 * Stalwart's domains and accounts in line with it through Stalwart's own API, as the administrator whose password only
 * root's jobs can read (configs/stalwart/admin.txt). The mailbox's SHA-512 crypt hash is Stalwart's secret as it is (no
 * plain password is kept anywhere); a mailbox switched off has signing in, receiving and sending switched off by Stalwart's
 * own permissions (its password kept, as with active = 0 on Linux). Only
 * the accounts the panel made (listed in its state file) are changed or removed: mailboxes added with mailuser.ps1 are
 * left alone. Aliases and distribution lists come from the same database's alias table: an address that delivers to one of
 * the panel's mailboxes becomes an alias of that Stalwart account, any other address with its recipients a Stalwart
 * mailing list (only the lists the panel made are changed or removed). Not built yet: forwarders (a mailbox's own row
 * with outside addresses: Stalwart forwards from a mailbox only by a Sieve script). Nothing here runs on Linux.
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
     * Stalwart's id of a domain, added when it is not there yet.
     *
     * @param string $domain
     * @param array<string, string> $doms Stalwart's domains (name => id), kept up to date.
     * @param string[] $out Lines for the daemon's log.
     * @return string|null
     */
    static function Domain($domain, &$doms, &$out)
    {
        if (isset($doms[$domain])) {
            return $doms[$domain];
        }
        $r = self::Call('x:Domain/set', array('create' => array('d' => array('name' => $domain))));
        if ($r === null || empty($r['created']['d']['id'])) {
            $out[] = 'Mail: Stalwart refused the domain ' . $domain . ': ' . self::$why;
            return null;
        }
        $doms[$domain] = (string) $r['created']['d']['id'];
        $out[] = 'Mail: domain ' . $domain . ' added';
        return $doms[$domain];
    }

    /** The name of the panel's Sieve script on a mailbox with a forwarder. */
    const FWD_SCRIPT = 'sentora-forward';

    /**
     * The list address a forwarder to several addresses goes through (Stalwart redirects once per script).
     *
     * @param string $addr The mailbox.
     * @return string
     */
    static function ForwardList($addr)
    {
        return 'sentora-fwd-' . substr(hash('sha256', 'sentora-forward:' . $addr), 0, 12) . '@' . substr($addr, strpos($addr, '@') + 1);
    }

    /**
     * The Sieve script of a forwarder (the addresses were checked: no quotes or line breaks can be in them).
     *
     * @param string $addr The mailbox.
     * @param array{keep: bool, to: string[]} $f
     * @return string
     */
    static function ForwardScript($addr, $f)
    {
        $to = count($f['to']) === 1 ? $f['to'][0] : self::ForwardList($addr);
        return "# Made by the Sentora panel (Forwarders): written over at the next change\r\n"
            . ($f['keep'] ? "require [\"copy\"];\r\nredirect :copy \"" . $to . "\";\r\n" : "redirect \"" . $to . "\";\r\n");
    }

    /**
     * One JMAP request with several calls (standard JMAP, here Blob and Sieve), as Stalwart's administrator.
     *
     * @param string[] $using
     * @param array<int, array> $calls
     * @return array<int, array>|null The method responses, or null when the request failed.
     */
    static function Jmap($using, $calls)
    {
        list($code, $r) = self::Http('/jmap/', json_encode(array('using' => $using, 'methodCalls' => $calls)));
        if ($code !== 200 || !isset($r['methodResponses']) || !is_array($r['methodResponses'])) {
            self::$why = substr($code . ' ' . json_encode($r), 0, 300);
            return null;
        }
        return $r['methodResponses'];
    }

    /**
     * Put a forwarder's script on a mailbox and switch it on, or (script null) switch it off and remove it. A script the
     * mailbox's owner made (their own filters) and switched on is never replaced.
     *
     * @param string $acctId The mailbox's Stalwart id (its JMAP account).
     * @param string|null $script
     * @return string '' when done, else why not.
     */
    static function SetForward($acctId, $script)
    {
        $sieve = array('urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:sieve');
        $r = self::Jmap($sieve, array(array('SieveScript/get', (object) array('accountId' => $acctId), 'g')));
        if ($r === null || $r[0][0] !== 'SieveScript/get') {
            return 'Stalwart did not list its scripts (' . ($r === null ? self::$why : json_encode($r[0])) . ')';
        }
        $ours = null;
        $other = false;
        foreach ((array) (isset($r[0][1]['list']) ? $r[0][1]['list'] : array()) as $x) {
            if ((string) $x['name'] === self::FWD_SCRIPT) {
                $ours = $x;
            } elseif (!empty($x['isActive'])) {
                $other = true;
            }
        }
        if ($script === null) {
            if ($ours === null) {
                return '';
            }
            $args = array('accountId' => $acctId, 'destroy' => array($ours['id']));
            if (!empty($ours['isActive'])) {
                $args['onSuccessDeactivateScript'] = true;
            }
            $r = self::Jmap($sieve, array(array('SieveScript/set', (object) $args, 's')));
            return ($r !== null && $r[0][0] === 'SieveScript/set' && empty($r[0][1]['notDestroyed'])) ? '' : 'Stalwart refused (' . json_encode($r === null ? self::$why : $r[0]) . ')';
        }
        if ($other) {
            return 'the mailbox has its own Sieve script switched on (its filters): it is not replaced';
        }
        $set = array('accountId' => $acctId);
        if ($ours === null) {
            $set['create'] = (object) array('s' => array('name' => self::FWD_SCRIPT, 'blobId' => '#b'));
            $set['onSuccessActivateScript'] = '#s';
        } else {
            $set['update'] = (object) array($ours['id'] => array('blobId' => '#b'));
            $set['onSuccessActivateScript'] = $ours['id'];
        }
        $r = self::Jmap(array('urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:blob', 'urn:ietf:params:jmap:sieve'), array(
            array('Blob/upload', (object) array('accountId' => $acctId, 'create' => (object) array('b' => array('data' => array(array('data:asText' => $script)), 'type' => 'application/sieve'))), 'u'),
            array('SieveScript/set', (object) $set, 's')));
        if ($r === null) {
            return 'Stalwart did not answer (' . self::$why . ')';
        }
        foreach ($r as $x) {
            if ($x[0] === 'error' || !empty($x[1]['notCreated']) || !empty($x[1]['notUpdated'])) {
                return 'Stalwart refused (' . substr(json_encode($x), 0, 300) . ')';
            }
        }
        return '';
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
        $aliasesFor = array();
        $lists = array();
        $fwds = array();
        $re = '/^([a-z0-9][a-z0-9._+-]{0,63})@(([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62})$/';
        try {
            $rows = $zdbh->query("SELECT address, goto, active FROM `" . $db . "`.alias")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $rows = array();
        }
        foreach ((array) $rows as $row) {
            $addr = strtolower(trim((string) $row['address']));
            if ((int) $row['active'] !== 1 || !preg_match($re, $addr)) {
                continue;
            }
            $to = array();
            foreach (explode(',', strtolower((string) $row['goto'])) as $g) {
                $g = trim($g);
                if ($g !== '' && preg_match($re, $g)) {
                    $to[$g] = true;
                }
            }
            $to = array_keys($to);
            sort($to);
            if (isset($want[$addr])) {
                // a forwarder (Forwarders page): the mailbox's own address in goto keeps a copy
                $dest = array_values(array_diff($to, array($addr)));
                if ($dest) {
                    $fwds[$addr] = array('keep' => in_array($addr, $to, true), 'to' => $dest);
                    if (count($dest) > 1) {
                        // (Stalwart lets a script redirect once: more addresses go through a list of the panel's)
                        $lists[self::ForwardList($addr)] = $dest;
                    }
                }
                continue;
            }
            if (count($to) === 1 && isset($want[$to[0]])) {
                $aliasesFor[$to[0]][] = $addr;
            } elseif ($to) {
                $lists[$addr] = $to;
            }
        }
        $lmine = isset($state['lists']) ? (array) $state['lists'] : array();
        if (!$want && !$mine && !$lists && !$lmine) {
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
            // (the crypt hash alone, which Stalwart keeps as it is; switched off: signing in, receiving and sending
            // switched off by Stalwart's own permissions, the password kept, as Dovecot/Postfix do with active = 0)
            $secrets = array($w['hash']);
            $perm = $w['on'] ? array('@type' => 'Inherit')
                : array('@type' => 'Merge', 'enabledPermissions' => (object) array(), 'disabledPermissions' => (object) array('authenticate' => true, 'emailReceive' => true, 'emailSend' => true));
            if (isset($accts[$a]) && !isset($mine[$a])) {
                continue;   // (made with mailuser.ps1: not the panel's)
            }
            // its aliases (an object keyed by position, as Stalwart writes them)
            $al = isset($aliasesFor[$a]) ? $aliasesFor[$a] : array();
            sort($al);
            $amap = array();
            foreach ($al as $x) {
                list($an, $ad) = explode('@', $x, 2);
                $adId = self::Domain($ad, $doms, $out);
                if ($adId !== null) {
                    $amap[(string) count($amap)] = array('name' => $an, 'domainId' => $adId);
                }
            }
            $amap = (object) $amap;
            $sum = hash('sha256', $w['hash'] . '|' . ($w['on'] ? 1 : 0) . '|' . implode(',', $al));
            if (!isset($accts[$a])) {
                if (self::Domain($domain, $doms, $out) === null) {
                    continue;
                }
                $r = null;
                $whys = array();
                foreach ($secrets as $secret) {
                    $r = self::Call('x:Account/set', array('create' => array('u' => array('@type' => 'User', 'name' => $local, 'domainId' => $doms[$domain],
                        'credentials' => (object) array('0' => array('@type' => 'Password', 'secret' => $secret)), 'permissions' => $perm, 'aliases' => $amap))));
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
                    $r = self::Call('x:Account/set', array('update' => (object) array($accts[$a] => array('credentials' => (object) array('0' => array('@type' => 'Password', 'secret' => $secret)), 'permissions' => $perm, 'aliases' => $amap))));
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
        // distribution lists: Stalwart mailing lists, only the ones the panel made
        $lmade = $lchanged = $lgone = 0;
        if ($lists || $lmine) {
            $have = array();
            $r = self::Call('x:MailingList/get', array('properties' => array('name', 'emailAddress')));
            if ($r === null) {
                $out[] = 'Mail: Stalwart did not list its mailing lists: ' . self::$why;
            } else {
                foreach ((array) (isset($r['list']) ? $r['list'] : array()) as $x) {
                    if (!empty($x['emailAddress'])) {
                        $have[strtolower((string) $x['emailAddress'])] = (string) $x['id'];
                    }
                }
                foreach ($lists as $addr => $to) {
                    $sum = hash('sha256', implode(',', $to));
                    if (isset($have[$addr]) && !isset($lmine[$addr])) {
                        continue;   // (not the panel's)
                    }
                    if (isset($accts[$addr]) || isset($want[$addr])) {
                        continue;   // (an address is a mailbox or a list, never both)
                    }
                    $rcpt = (object) array_fill_keys($to, true);
                    if (!isset($have[$addr])) {
                        list($ln, $ld) = explode('@', $addr, 2);
                        $ldId = self::Domain($ld, $doms, $out);
                        if ($ldId === null) {
                            continue;
                        }
                        $r = self::Call('x:MailingList/set', array('create' => array('l' => array('name' => $ln, 'domainId' => $ldId, 'recipients' => $rcpt))));
                        if ($r === null || empty($r['created']['l']['id'])) {
                            $out[] = 'Mail: Stalwart refused the list ' . $addr . ': ' . self::$why;
                            continue;
                        }
                        $lmade++;
                    } elseif ($lmine[$addr] !== $sum) {
                        if (self::Call('x:MailingList/set', array('update' => (object) array($have[$addr] => array('recipients' => $rcpt)))) === null) {
                            $out[] = 'Mail: Stalwart refused the change of the list ' . $addr . ': ' . self::$why;
                            continue;
                        }
                        $lchanged++;
                    }
                    $lmine[$addr] = $sum;
                }
                foreach (array_keys($lmine) as $addr) {
                    if (!isset($lists[$addr])) {
                        if (isset($have[$addr]) && self::Call('x:MailingList/set', array('destroy' => array($have[$addr]))) === null) {
                            $out[] = 'Mail: Stalwart refused to remove the list ' . $addr . ': ' . self::$why;
                            continue;
                        }
                        unset($lmine[$addr]);
                        $lgone++;
                    }
                }
            }
        }
        // forwarders: a Sieve script on the mailbox (its own JMAP account, which Stalwart's administrator may manage)
        $fstate = isset($state['fwd']) ? (array) $state['fwd'] : array();
        $fnew = array();
        $fset = $fgone = 0;
        foreach (array_unique(array_merge(array_keys($fwds), array_keys($fstate))) as $addr) {
            if (!isset($accts[$addr]) || !isset($mine[$addr])) {
                continue;   // (only the panel's mailboxes; a new one gets its forwarder at the next run)
            }
            $script = isset($fwds[$addr]) ? self::ForwardScript($addr, $fwds[$addr]) : null;
            $sum = $script === null ? null : hash('sha256', $script);
            if ($sum !== null && isset($fstate[$addr]) && $fstate[$addr] === $sum) {
                $fnew[$addr] = $sum;
                continue;
            }
            $why = self::SetForward($accts[$addr], $script);
            if ($why !== '') {
                $out[] = 'Mail: the forwarder of ' . $addr . ' was not ' . ($script === null ? 'removed' : 'set') . ': ' . $why;
                if (isset($fstate[$addr])) {
                    $fnew[$addr] = $fstate[$addr];
                }
                continue;
            }
            if ($script === null) {
                $fgone++;
            } else {
                $fnew[$addr] = $sum;
                $fset++;
            }
        }
        if ($fset || $fgone) {
            $out[] = 'Mail: forwarders set ' . $fset . ', removed ' . $fgone;
        }
        if ($lmade || $lchanged || $lgone) {
            $out[] = 'Mail: Stalwart lists made ' . $lmade . ', changed ' . $lchanged . ', removed ' . $lgone;
        }
        $new = array('mine' => $mine, 'sums' => $sums, 'lists' => $lmine, 'fwd' => $fnew);
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
