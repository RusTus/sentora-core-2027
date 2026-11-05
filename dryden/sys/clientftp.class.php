<?php

/**
 * Clients' own off-site backups (Advanced > Backup): a backup of the account's
 * websites and databases (sys_backup::Create, no mail) uploaded to an FTP server the
 * client names, at the hour they choose, keeping the number of copies they choose.
 *
 * Safety: the server's address is checked after the name is looked up and then
 * pinned (curl --resolve), so a later DNS answer can not point it elsewhere; addresses
 * of this server's own loopback and link-local networks are always refused, private
 * networks unless the administrator allows them (Backup Config), and on this server's
 * own addresses only the FTP ports; the FTP server can not send the data connection
 * elsewhere (--ftp-skip-pasv-ip); the password is stored encrypted (ctrl_secret) and
 * given to curl in a root-only file, never on a command line; folder and file names
 * are checked so nothing is added to FTP commands. Uploads run as root, from the daemon.
 */
class sys_clientftp
{
    const STATE_DIR = '/var/lib/sentora/clientftp/';

    static function Ensure()
    {
        global $zdbh;
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_backup_ftp (
            bf_acc_fk INT NOT NULL PRIMARY KEY,
            bf_enabled_in TINYINT NOT NULL DEFAULT 0,
            bf_host_vc VARCHAR(253) NOT NULL DEFAULT '',
            bf_port_in INT NOT NULL DEFAULT 21,
            bf_user_vc VARCHAR(128) NOT NULL DEFAULT '',
            bf_pass_tx TEXT NULL,
            bf_path_vc VARCHAR(255) NOT NULL DEFAULT '/',
            bf_tls_vc VARCHAR(10) NOT NULL DEFAULT 'explicit',
            bf_insecure_in TINYINT NOT NULL DEFAULT 0,
            bf_hour_in TINYINT NOT NULL DEFAULT 3,
            bf_keep_in INT NOT NULL DEFAULT 7,
            bf_request_vc VARCHAR(20) NOT NULL DEFAULT '',
            bf_status_tx TEXT NULL
        ) DEFAULT CHARSET=utf8");
        foreach (array(
            array('client_ftp_backup', 'Client FTP backups', 'true', 'true|false', 'Let clients copy their backups (websites and databases) to their own FTP server (Advanced > Backup).'),
            array('client_ftp_private', 'Client FTP private networks', 'false', 'true|false', 'Allow client FTP backups to private network addresses (10.x, 172.16-31.x, 192.168.x and similar). Loopback and link-local addresses are always refused.'),
        ) as $s) {
            $q = $zdbh->prepare("SELECT COUNT(*) FROM x_settings WHERE so_name_vc = :n");
            $q->execute(array(':n' => $s[0]));
            if ((int) $q->fetchColumn() === 0) {
                $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
                    VALUES (:n, :c, :v, :d, :t, 'Backup Config', 'true')")->execute(array(':n' => $s[0], ':c' => $s[1], ':v' => $s[2], ':d' => $s[3], ':t' => $s[4]));
            }
        }
    }

    static function Allowed()
    {
        return strtolower((string) ctrl_options::GetSystemOption('client_ftp_backup')) !== 'false';
    }

    static function Get($accountId)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_backup_ftp WHERE bf_acc_fk = :a");
        $q->execute(array(':a' => (int) $accountId));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : array('bf_acc_fk' => (int) $accountId, 'bf_enabled_in' => 0, 'bf_host_vc' => '', 'bf_port_in' => 21, 'bf_user_vc' => '',
            'bf_pass_tx' => '', 'bf_path_vc' => '/backups', 'bf_tls_vc' => 'explicit', 'bf_insecure_in' => 0, 'bf_hour_in' => 3, 'bf_keep_in' => 7,
            'bf_request_vc' => '', 'bf_status_tx' => '');
    }

    static function Save($accountId, array $v)
    {
        global $zdbh;
        $zdbh->prepare("INSERT INTO x_backup_ftp (bf_acc_fk, bf_enabled_in, bf_host_vc, bf_port_in, bf_user_vc, bf_pass_tx, bf_path_vc, bf_tls_vc, bf_insecure_in, bf_hour_in, bf_keep_in)
            VALUES (:a, :e, :h, :p, :u, :pw, :path, :tls, :ins, :hr, :k)
            ON DUPLICATE KEY UPDATE bf_enabled_in = VALUES(bf_enabled_in), bf_host_vc = VALUES(bf_host_vc), bf_port_in = VALUES(bf_port_in), bf_user_vc = VALUES(bf_user_vc),
            bf_pass_tx = VALUES(bf_pass_tx), bf_path_vc = VALUES(bf_path_vc), bf_tls_vc = VALUES(bf_tls_vc), bf_insecure_in = VALUES(bf_insecure_in),
            bf_hour_in = VALUES(bf_hour_in), bf_keep_in = VALUES(bf_keep_in)")
            ->execute(array(':a' => (int) $accountId, ':e' => (int) $v['bf_enabled_in'], ':h' => $v['bf_host_vc'], ':p' => (int) $v['bf_port_in'],
                ':u' => $v['bf_user_vc'], ':pw' => $v['bf_pass_tx'], ':path' => $v['bf_path_vc'], ':tls' => $v['bf_tls_vc'],
                ':ins' => (int) $v['bf_insecure_in'], ':hr' => (int) $v['bf_hour_in'], ':k' => (int) $v['bf_keep_in']));
    }

    static function SetRequest($accountId, $what)
    {
        global $zdbh;
        $zdbh->prepare("UPDATE x_backup_ftp SET bf_request_vc = :r WHERE bf_acc_fk = :a")->execute(array(':r' => (string) $what, ':a' => (int) $accountId));
    }

    static function Status($accountId)
    {
        $s = json_decode((string) self::Get($accountId)['bf_status_tx'], true);
        return is_array($s) ? $s : array();
    }

    static function SetStatus($accountId, array $changes)
    {
        global $zdbh;
        $s = array_merge(self::Status($accountId), $changes);
        $zdbh->prepare("UPDATE x_backup_ftp SET bf_status_tx = :s WHERE bf_acc_fk = :a")->execute(array(':s' => json_encode($s), ':a' => (int) $accountId));
    }

    // --- checks ------------------------------------------------------------------------------

    static function ValidHost($h)
    {
        return is_string($h) && (preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $h) === 1
            || filter_var($h, FILTER_VALIDATE_IP) !== false);
    }

    static function ValidPath($p)
    {
        return is_string($p) && preg_match('#^/[A-Za-z0-9._ /-]{0,200}$#', $p) === 1 && strpos($p, '..') === false && strpos($p, '//') === false;
    }

    static function ValidUser($u)
    {
        return is_string($u) && $u !== '' && strlen($u) <= 128 && !preg_match('/[\x00-\x20\x7f:@\/\\\\]/', $u);
    }

    /** this server's own addresses */
    static function OwnAddresses()
    {
        $own = array();
        exec('hostname -I 2>/dev/null', $o);
        foreach (preg_split('/\s+/', trim(implode(' ', (array) $o))) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $own[$ip] = true;
            }
        }
        return $own;
    }

    /**
     * The address to connect to for host:port, or an error text. Looked up once here and
     * then pinned for the transfer.
     */
    static function ResolveTarget($host, $port)
    {
        if (!self::ValidHost($host)) {
            return array(false, 'the server name is not valid');
        }
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            return array(false, 'the port is not valid');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : array();
        if (!$ips) {
            foreach ((array) @dns_get_record($host, DNS_A) as $r) {
                if (!empty($r['ip'])) { $ips[] = $r['ip']; }
            }
            foreach ((array) @dns_get_record($host, DNS_AAAA) as $r) {
                if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; }
            }
            if (!$ips) {
                $g = @gethostbynamel($host);
                $ips = is_array($g) ? $g : array();
            }
        }
        if (!$ips) {
            return array(false, 'the server name could not be found');
        }
        $privateOk = strtolower((string) ctrl_options::GetSystemOption('client_ftp_private')) === 'true';
        $own = self::OwnAddresses();
        foreach ($ips as $ip) {
            $bin = @inet_pton($ip);
            if ($bin === false) {
                continue;
            }
            $v4 = (strlen($bin) === 4);
            $b = array_values(unpack('C*', $bin));
            // always refused: unspecified, loopback, link-local, multicast, broadcast
            $never = $v4 ? ($b[0] === 0 || $b[0] === 127 || ($b[0] === 169 && $b[1] === 254) || $b[0] >= 224)
                : ($ip === '::' || $ip === '::1' || ($b[0] === 0xfe && ($b[1] & 0xc0) === 0x80) || $b[0] === 0xff
                   || (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff")); // IPv4-mapped
            if ($never) {
                return array(false, 'that address (' . $ip . ') is not allowed');
            }
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
                && !($v4 && $b[0] === 100 && ($b[1] & 0xc0) === 64)       // carrier-grade NAT
                && !($v4 && $b[0] === 192 && $b[1] === 0 && $b[2] === 2)   // documentation ranges
                && !($v4 && $b[0] === 198 && $b[1] === 51 && $b[2] === 100)
                && !($v4 && $b[0] === 203 && $b[1] === 0 && $b[2] === 113)
                && !($v4 && $b[0] === 198 && ($b[1] & 0xfe) === 18);       // benchmarking
            if (!$public && !$privateOk) {
                return array(false, 'that address (' . $ip . ') is on a private network, which the administrator has not allowed');
            }
            if (isset($own[$ip]) && $port !== 21 && $port !== 990) {
                return array(false, 'on this server itself only the FTP ports (21, 990) are allowed');
            }
            return array($ip, '');
        }
        return array(false, 'the server name could not be found');
    }

    // --- the transfer (root, from the daemon) ---------------------------------------------------------

    /** run curl with the login in a root-only file; returns array(exit code, output) */
    static function Curl($cfg, $ip, array $args)
    {
        $pw = ctrl_secret::Decrypt((string) $cfg['bf_pass_tx']);
        $pw = ($pw === false) ? '' : $pw;
        $netrc = tempnam('/var/lib/sentora/tmp', 'ftpnetrc');
        chmod($netrc, 0600);
        // netrc: quoted, with " and \ escaped (no line breaks: they are refused on save)
        $q = function ($s) { return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $s) . '"'; };
        file_put_contents($netrc, 'machine ' . $cfg['bf_host_vc'] . ' login ' . $q($cfg['bf_user_vc']) . ' password ' . $q($pw) . "\n");
        $scheme = ($cfg['bf_tls_vc'] === 'implicit') ? 'ftps' : 'ftp';
        $base = array('curl', '-sS', '--netrc-file', $netrc, '--resolve', $cfg['bf_host_vc'] . ':' . (int) $cfg['bf_port_in'] . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip),
            '--proto', '=ftp,ftps', '--ftp-skip-pasv-ip', '--ftp-pasv', '--connect-timeout', '20', '--max-redirs', '0');
        if ($cfg['bf_tls_vc'] === 'explicit') {
            $base[] = '--ssl-reqd';
        }
        if ((int) $cfg['bf_insecure_in'] === 1 && $cfg['bf_tls_vc'] !== 'none') {
            $base[] = '--insecure';
        }
        $url = $scheme . '://' . $cfg['bf_host_vc'] . ':' . (int) $cfg['bf_port_in'] . rtrim(str_replace(' ', '%20', $cfg['bf_path_vc']), '/') . '/';
        $cmd = implode(' ', array_map('escapeshellarg', array_merge($base, str_replace('{URL}', $url, $args)))) . ' 2>&1';
        exec($cmd, $out, $rc);
        @unlink($netrc);
        $text = trim(implode(' ', $out));
        $text = str_replace($pw === '' ? "\0" : $pw, '***', $text);
        return array($rc, $text);
    }

    /** "check" (connect, write and remove a small file) or "run" (back up and upload) */
    static function Run($accountId, $mode)
    {
        $cfg = self::Get($accountId);
        $user = ctrl_users::GetUserDetail((int) $accountId);
        if (!$user || $cfg['bf_host_vc'] === '') {
            return false;
        }
        @mkdir(self::STATE_DIR, 0700, true);
        $lock = fopen(self::STATE_DIR . 'acc-' . (int) $accountId . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return false;
        }
        list($ip, $err) = self::ResolveTarget($cfg['bf_host_vc'], $cfg['bf_port_in']);
        $key = ($mode === 'check') ? 'check' : 'run';
        if ($ip === false) {
            self::SetStatus($accountId, array($key . '_time' => time(), $key . '_ok' => 0, $key . '_message' => 'Not started: ' . $err . '.'));
            return false;
        }
        if ($mode === 'check') {
            $probe = tempnam('/var/lib/sentora/tmp', 'ftpprobe');
            file_put_contents($probe, "Sentora connection test\n");
            $name = '.sentora-test-' . bin2hex(random_bytes(4));
            list($rc, $out) = self::Curl($cfg, $ip, array('--ftp-create-dirs', '-T', $probe, '-Q', '-DELE ' . $name, '{URL}' . $name));
            @unlink($probe);
            $ok = ($rc === 0);
            self::SetStatus($accountId, array('check_time' => time(), 'check_ok' => $ok ? 1 : 0,
                'check_message' => $ok ? 'Connected to ' . $cfg['bf_host_vc'] . ' (' . $ip . ')' . ($cfg['bf_tls_vc'] === 'none' ? ' without encryption' : ' over TLS') . ' and wrote to ' . $cfg['bf_path_vc'] . '.'
                    : 'Failed: ' . self::Explain($rc, $out)));
            return $ok;
        }
        // a backup of the websites and databases (no mail), uploaded, then old copies removed
        $file = sys_backup::Create((int) $accountId, $user['username']);
        if ($file === false) {
            self::SetStatus($accountId, array('run_time' => time(), 'run_ok' => 0, 'run_message' => 'The backup could not be made.'));
            return false;
        }
        $name = basename($file);
        $opened = sys_backup::OpenFile($user['username'], $name);
        if ($opened === false) {
            self::SetStatus($accountId, array('run_time' => time(), 'run_ok' => 0, 'run_message' => 'The new backup could not be read.'));
            return false;
        }
        fclose($opened[0]);
        list($rc, $out) = self::Curl($cfg, $ip, array('--ftp-create-dirs', '--max-time', '21600', '-T', $file, '{URL}' . $name));
        if ($rc !== 0) {
            self::SetStatus($accountId, array('run_time' => time(), 'run_ok' => 0, 'run_message' => 'Upload failed: ' . self::Explain($rc, $out)));
            return false;
        }
        // copies kept: this account's backup names only, oldest removed
        $removed = 0;
        list($rc2, $list) = self::Curl($cfg, $ip, array('--list-only', '{URL}'));
        if ($rc2 === 0) {
            $names = array();
            foreach (preg_split('/\s+/', $list) as $n) {
                if (sys_backup::ValidFile($user['username'], $n)) {
                    $names[] = $n;
                }
            }
            usort($names, function ($a, $b) { return self::NameTime($a) - self::NameTime($b); });
            $extra = count($names) - max(1, (int) $cfg['bf_keep_in']);
            for ($i = 0; $i < $extra; $i++) {
                list($rc3) = self::Curl($cfg, $ip, array('-Q', '-DELE ' . $names[$i], '--list-only', '{URL}'));
                if ($rc3 === 0) { $removed++; }
            }
        }
        self::SetStatus($accountId, array('run_time' => time(), 'run_ok' => 1, 'last_ok' => time(),
            'run_message' => 'Uploaded ' . $name . ' (' . fs_director::ShowHumanFileSize(filesize($file)) . ')' . ($removed ? ', removed ' . $removed . ' older copy(ies)' : '') . '.'));
        return true;
    }

    /** a backup name's time (user_Mon-dd-YYYY_HHMMSS_xxxx.zip), for sorting */
    static function NameTime($n)
    {
        return preg_match('/_([A-Za-z]{3}-[0-9]{2}-[0-9]{4})_([0-9]{2})([0-9]{2})([0-9]{2})/', $n, $m) ? (int) strtotime($m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . $m[4]) : 0;
    }

    static function Explain($rc, $out)
    {
        $known = array(6 => 'the server name could not be found', 7 => 'could not connect (is the server and port right, and is it running?)',
            9 => 'the folder could not be opened or made', 28 => 'the connection timed out', 35 => 'the TLS connection failed',
            60 => 'the server\'s certificate is not trusted (tick "accept a self-signed certificate" if you trust it)',
            64 => 'the server does not offer FTPS (choose plain FTP only if you accept sending the password unencrypted)',
            77 => 'the server\'s certificate could not be checked (the CA certificates on this server are missing: apt-get install ca-certificates)',
            67 => 'the username or password was refused', 25 => 'the server refused the upload (no permission, or the disk is full)');
        $t = isset($known[$rc]) ? $known[$rc] : 'error ' . (int) $rc;
        return $t . ($out !== '' ? ' (' . substr($out, 0, 160) . ')' : '') . '.';
    }
}
