<?php

/**
 * FTP accounts on Windows (SFTPGo): the panel keeps its FTP accounts in x_ftpaccounts as on Linux, and the daemon (SYSTEM)
 * brings SFTPGo's users in line with them through SFTPGo's own API, as an administrator whose password only root's jobs
 * can read (configs/sftpgo/env.d/sftpgo.env); a page (Apache's account) only asks for a daemon run. Only the users the
 * panel made (their description says so) are changed or removed: users added with ftpuser.ps1 are left alone. The
 * access type becomes SFTPGo's permissions, a switched-off client's logins are switched off (and their open sessions
 * ended), and the panel's SHA-512 crypt hash is SFTPGo's password as it is (no plain password leaves the panel).
 * Nothing here runs on Linux (ProFTPD there: ftp_php = proftpd.php).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-25
 */
class sys_winftp
{
    /** SFTPGo's description of the users the panel made. */
    const MARK = 'Made by the Sentora panel (FTP Accounts)';

    /** What was last sent for each user (root's jobs only): a user is sent again only when it changed. */
    const STATE = '/var/lib/sentora/phpaccounts/ftp-state.json';

    /** @var string|null SFTPGo's API token for this run. */
    private static $token = null;

    /**
     * After an FTP account was made, changed or deleted on a page: ask for a daemon run.
     *
     * @return void
     */
    static function Soon()
    {
        if (sys_versions::IsWindows() && class_exists('sys_winphp')) {
            sys_winphp::Soon();
        }
    }

    /**
     * SFTPGo's API, from the ftp_api setting (the installer's: http://127.0.0.1:8080/api/v2).
     *
     * @return string
     */
    static function Base()
    {
        $b = rtrim((string) ctrl_options::GetSystemOption('ftp_api'), '/');
        return preg_match('#^http://(127\.0\.0\.1|localhost)(:[0-9]{1,5})?/api/v2$#', $b) ? $b : 'http://127.0.0.1:8080/api/v2';
    }

    /**
     * SFTPGo's administrator and password, from the file the installer writes (ftp_env; root's jobs only).
     *
     * @return array{0: string, 1: string}|null
     */
    static function Admin()
    {
        $f = (string) ctrl_options::GetSystemOption('ftp_env');
        if ($f === '') {
            $f = dirname(dirname((string) ctrl_options::GetSystemOption('apache_vhost'))) . '/sftpgo/env.d/sftpgo.env';
        }
        $s = is_file($f) ? (string) @file_get_contents($f) : '';
        $u = preg_match('/^SFTPGO_DEFAULT_ADMIN_USERNAME=(.+)$/m', $s, $m) ? trim($m[1]) : '';
        $p = preg_match('/^SFTPGO_DEFAULT_ADMIN_PASSWORD=(.+)$/m', $s, $m) ? trim($m[1]) : '';
        return ($u !== '' && $p !== '') ? array($u, $p) : null;
    }

    /**
     * One call to SFTPGo's API.
     *
     * @param string $method GET, POST, PUT or DELETE.
     * @param string $path After /api/v2.
     * @param array<string, mixed>|null $body Sent as JSON.
     * @return array{0: int, 1: mixed} The HTTP status and the decoded answer.
     */
    static function Call($method, $path, $body = null)
    {
        if (self::$token === null) {
            $a = self::Admin();
            if ($a === null) {
                return array(0, 'no SFTPGo administrator in its settings file');
            }
            $h = curl_init(self::Base() . '/token');
            curl_setopt_array($h, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERPWD => $a[0] . ':' . $a[1]));
            $r = json_decode((string) curl_exec($h), true);
            $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
            curl_close($h);
            if ($code !== 200 || empty($r['access_token'])) {
                return array($code, 'SFTPGo refused the token (' . $code . ')');
            }
            self::$token = (string) $r['access_token'];
        }
        $h = curl_init(self::Base() . $path);
        $opt = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . self::$token, 'Content-Type: application/json'));
        if ($body !== null) {
            $opt[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($h, $opt);
        $raw = (string) curl_exec($h);
        $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
        curl_close($h);
        return array($code, json_decode($raw, true));
    }

    /**
     * SFTPGo's permissions for the panel's access types.
     *
     * @param string $access RO, WO or RW.
     * @return array<string, array<int, string>>
     */
    static function Permissions($access)
    {
        if ($access === 'RO') {
            return array('/' => array('list', 'download'));
        }
        if ($access === 'WO') {
            return array('/' => array('list', 'upload', 'overwrite', 'create_dirs', 'rename'));
        }
        return array('/' => array('*'));
    }

    /**
     * The daemon's part: SFTPGo's users made by the panel, in line with x_ftpaccounts.
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
        $hosted = str_replace('\\', '/', rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/\\')) . '/';
        $want = array();
        $q = $zdbh->query("SELECT f.ft_user_vc, f.ft_directory_vc, f.ft_access_vc, f.ft_password_vc, a.ac_user_vc, a.ac_enabled_in
            FROM x_ftpaccounts f JOIN x_accounts a ON a.ac_id_pk = f.ft_acc_fk WHERE f.ft_deleted_ts IS NULL AND a.ac_deleted_ts IS NULL");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = (string) $r['ft_user_vc'];
            $acc = sys_winphp::Name($r['ac_user_vc']);
            $dir = sys_winvhosts::Folder($r['ft_directory_vc']);
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $name) || $acc === '' || $dir === null) {
                $out[] = 'FTP: ' . $name . ' skipped (its name or folder is not one SFTPGo may get)';
                continue;
            }
            $home = $hosted . $acc . $dir;
            $u = array('username' => $name, 'status' => (int) $r['ac_enabled_in'] === 1 ? 1 : 0, 'home_dir' => str_replace('/', '\\', $home),
                'permissions' => self::Permissions((string) $r['ft_access_vc']), 'description' => self::MARK);
            if (strpos((string) $r['ft_password_vc'], '$6$') === 0) {
                $u['password'] = (string) $r['ft_password_vc'];
            }
            $want[$name] = $u;
        }
        // SFTPGo's users now
        $have = array();
        for ($off = 0; $off < 20000; $off += 500) {
            list($code, $list) = self::Call('GET', '/users?limit=500&offset=' . $off);
            if ($code !== 200 || !is_array($list)) {
                $out[] = 'FTP: SFTPGo does not answer (' . (is_string($list) ? $list : $code) . '): nothing changed';
                return $out;
            }
            foreach ($list as $x) {
                if (isset($x['username'])) {
                    $have[(string) $x['username']] = $x;
                }
            }
            if (count($list) < 500) {
                break;
            }
        }
        $state = is_file(self::STATE) ? (array) json_decode((string) @file_get_contents(self::STATE), true) : array();
        $new = array();
        $made = $changed = $gone = $off = 0;
        foreach ($want as $name => $u) {
            $sum = hash('sha256', json_encode($u));
            if (isset($have[$name]) && (string) (isset($have[$name]['description']) ? $have[$name]['description'] : '') !== self::MARK) {
                $out[] = 'FTP: ' . $name . ' is a user the panel did not make (ftpuser.ps1): left as it is';
                continue;
            }
            if (!is_dir($u['home_dir'])) {
                @mkdir($u['home_dir'], 0755, true);
            }
            if (!isset($have[$name])) {
                list($code, $r) = self::Call('POST', '/users', $u);
                if ($code === 201 || $code === 200) {
                    $made++;
                    $new[$name] = $sum;
                } else {
                    $out[] = 'FTP: SFTPGo refused ' . $name . ' (' . $code . ': ' . (is_array($r) && isset($r['error']) ? $r['error'] : '') . ')';
                }
            } elseif (!isset($state[$name]) || $state[$name] !== $sum) {
                list($code, $r) = self::Call('PUT', '/users/' . rawurlencode($name), $u);
                if ($code === 200) {
                    $changed++;
                    $new[$name] = $sum;
                } else {
                    $out[] = 'FTP: SFTPGo refused the change of ' . $name . ' (' . $code . ': ' . (is_array($r) && isset($r['error']) ? $r['error'] : '') . ')';
                }
            } else {
                $new[$name] = $sum;
            }
            if ($u['status'] === 0) {
                $off++;
            }
        }
        foreach ($have as $name => $x) {
            if (!isset($want[$name]) && (string) (isset($x['description']) ? $x['description'] : '') === self::MARK) {
                list($code) = self::Call('DELETE', '/users/' . rawurlencode($name));
                if ($code === 200) {
                    $gone++;
                }
            }
        }
        // the open sessions of logins switched off (or removed) end now
        $ended = 0;
        list($code, $conns) = self::Call('GET', '/connections');
        if ($code === 200 && is_array($conns)) {
            foreach ($conns as $c) {
                $n = isset($c['username']) ? (string) $c['username'] : '';
                $stop = (isset($want[$n]) && $want[$n]['status'] === 0) || (!isset($want[$n]) && isset($have[$n]) && (string) (isset($have[$n]['description']) ? $have[$n]['description'] : '') === self::MARK);
                if ($stop && isset($c['connection_id'])) {
                    list($dc) = self::Call('DELETE', '/connections/' . rawurlencode((string) $c['connection_id']));
                    if ($dc === 200) {
                        $ended++;
                    }
                }
            }
        }
        if ($new != $state) {
            if (!is_dir(dirname(self::STATE))) {
                @mkdir(dirname(self::STATE), 0700, true);
            }
            @file_put_contents(self::STATE, json_encode($new));
        }
        if ($made || $changed || $gone || $ended) {
            $out[] = 'FTP: SFTPGo users made ' . $made . ', changed ' . $changed . ', removed ' . $gone . ($ended ? ', sessions ended ' . $ended : '') . ' (' . count($want) . ' in the panel, ' . $off . ' switched off)';
        }
        return $out;
    }
}
