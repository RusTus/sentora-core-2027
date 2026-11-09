<?php

/**
 * Customer feedback: a short report about this server, sent once a day (and on the daemon's first
 * run) to the Sentora-2027 project's statistics server, so the project knows on what servers,
 * versions and sizes the panel runs. On by default; Sentora Config > customer_feedback turns it off.
 *
 * Only what is not private is sent: the panel's version, the operating system and the hardware,
 * the software versions, the server's public and internal IP addresses, how busy it is (load,
 * memory, this month's traffic and disk used, all accounts together) and counts (accounts,
 * domains, mailboxes...). Never a domain name, user name, email address, password or file. The
 * report sent last is kept in /var/lib/sentora/feedback-last.json, so an administrator can read
 * exactly what left the server.
 *
 * Where it goes: Sentora Config > feedback_url when set, else the address the project publishes
 * (feedback-endpoint.txt in the installers repository, read once a day); none, nothing is sent.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class ctrl_feedback
{
    /** Where the project publishes its statistics server's address. */
    const ENDPOINT_LIST = 'https://raw.githubusercontent.com/RusTus/sentora-installers-2027/master2027/feedback-endpoint.txt';

    /** Root's folder for its state (the install's random ID, the last report). */
    const DIR = '/var/lib/sentora';

    /**
     * The settings (made when missing, as Sentora Config shows them).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array{on:bool, url:string}
     */
    static function Settings()
    {
        global $zdbh;
        $defs = array(
            array('customer_feedback', 'Customer feedback', 'true', 'true|false', 'Once a day, send the Sentora-2027 project a short report about this server: the panel version, operating system, hardware, software versions, public and internal IP address, how busy it is (load, memory, traffic and disk used of all accounts together) and counts (accounts, domains, mailboxes). Never a domain, user name, email address, password or file. The last report sent is in /var/lib/sentora/feedback-last.json. false: nothing is sent.'),
            array('feedback_url', 'Customer feedback address', '', '', 'Where the report goes. Empty: the address the Sentora-2027 project publishes.'),
        );
        foreach ($defs as $d) {
            $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
                SELECT :n, :c, :v, :dv, :t, 'Sentora Config', 'true' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM x_settings WHERE so_name_vc = :n2)")
                ->execute(array(':n' => $d[0], ':c' => $d[1], ':v' => $d[2], ':dv' => $d[3], ':t' => $d[4], ':n2' => $d[0]));
        }
        return array('on' => strtolower(trim((string) ctrl_options::GetSystemOption('customer_feedback'))) !== 'false',
            'url' => trim((string) ctrl_options::GetSystemOption('feedback_url')));
    }

    /**
     * Daemon (every run): send the report when none went out in the last day (so on the first run
     * too); after a failure, try again an hour later.
     *
     * @return string What happened ('' when nothing was due).
     */
    static function Run()
    {
        $s = self::Settings();
        $state = self::State();
        if (!$s['on']) {
            return '';
        }
        $now = time();
        if (!empty($state['sent']) && $now - (int) $state['sent'] < 86400 - 600) {
            return '';
        }
        if (!empty($state['tried']) && $now - (int) $state['tried'] < 3600) {
            return '';
        }
        $url = self::Endpoint($s['url'], $state);
        if ($url === '') {
            self::Save(array('tried' => $now) + $state);
            return '';
        }
        $report = self::Report($state);
        $code = self::Post($url, $report);
        $state = self::State();
        $state['tried'] = $now;
        @file_put_contents(self::DIR . '/feedback-last.json', json_encode(array('sent' => $code >= 200 && $code < 300 ? date('c') : null, 'to' => $url, 'report' => $report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod(self::DIR . '/feedback-last.json', 0600);
        if ($code >= 200 && $code < 300) {
            $state['sent'] = $now;
            self::Save($state);
            return 'Customer feedback sent (what: /var/lib/sentora/feedback-last.json; to stop: setso --set customer_feedback false)';
        }
        self::Save($state);
        return 'Customer feedback not sent (' . ($code > 0 ? 'HTTP ' . $code : 'no answer') . '): tried again in an hour';
    }

    /**
     * This server's random ID and when it last sent (root's own file).
     *
     * @return array
     */
    static function State()
    {
        $f = self::DIR . '/feedback.json';
        $s = is_file($f) && !is_link($f) ? json_decode((string) file_get_contents($f), true) : null;
        $s = is_array($s) ? $s : array();
        if (empty($s['id']) || !preg_match('/^[0-9a-f]{32}$/', (string) $s['id'])) {
            // a random ID, made once: it tells reports of the same server apart, and says nothing else
            $s['id'] = bin2hex(random_bytes(16));
            $s['first'] = time();
            self::Save($s);
        }
        return $s;
    }

    /**
     * Keep the state.
     *
     * @param array $s
     * @return void
     */
    static function Save(array $s)
    {
        if (!is_dir(self::DIR)) {
            @mkdir(self::DIR, 0755, true);
        }
        $f = self::DIR . '/feedback.json';
        if (is_link($f)) {
            return;
        }
        @file_put_contents($f . '.new', json_encode($s));
        @chmod($f . '.new', 0600);
        @rename($f . '.new', $f);
    }

    /**
     * Where to send: the setting, else the project's published address (read once a day).
     *
     * @param string $set
     * @param array $state
     * @return string '' when there is none.
     */
    static function Endpoint($set, array &$state)
    {
        $ok = function ($u) {
            return preg_match('#^https://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/%-]*)?$#', $u) === 1;
        };
        if ($set !== '') {
            return $ok($set) ? $set : '';
        }
        if (!empty($state['endpoint_read']) && time() - (int) $state['endpoint_read'] < 86400) {
            return isset($state['endpoint']) && $ok((string) $state['endpoint']) ? (string) $state['endpoint'] : '';
        }
        $ctx = stream_context_create(array('http' => array('timeout' => 10, 'user_agent' => 'Sentora-2027')));
        $txt = @file_get_contents(self::ENDPOINT_LIST, false, $ctx);
        $url = '';
        foreach (explode("\n", (string) $txt) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $url = $line;
                break;
            }
        }
        $state['endpoint'] = $ok($url) ? $url : '';
        $state['endpoint_read'] = time();
        self::Save($state);
        return $state['endpoint'];
    }

    /**
     * Send a report (JSON over HTTPS).
     *
     * @param string $url
     * @param array $report
     * @return int The HTTP status (0: no answer).
     */
    static function Post($url, array $report)
    {
        $c = curl_init($url);
        curl_setopt_array($c, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($report), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'User-Agent: Sentora-2027/' . self::Version()),
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2));
        curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        return $code;
    }

    /**
     * The panel's version.
     *
     * @return string
     */
    static function Version()
    {
        $f = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/VERSION';
        $v = is_file($f) ? trim((string) file_get_contents($f)) : '';
        return preg_match('/^[0-9][0-9A-Za-z.-]{0,30}$/', $v) ? $v : 'unknown';
    }

    /**
     * The report: nothing private (no names, addresses of sites or people, no secrets).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $state
     * @return array
     */
    static function Report(array $state)
    {
        global $zdbh;
        $os = array();
        foreach (@file('/etc/os-release', FILE_IGNORE_NEW_LINES) ?: array() as $l) {
            if (preg_match('/^(PRETTY_NAME|ID|VERSION_ID)="?([^"]*)"?$/', $l, $m)) {
                $os[$m[1]] = $m[2];
            }
        }
        $cpu = '';
        $cores = 0;
        foreach (@file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES) ?: array() as $l) {
            if (strpos($l, 'model name') === 0 && $cpu === '') {
                $cpu = trim(substr($l, strpos($l, ':') + 1));
            }
            if (strpos($l, 'processor') === 0) {
                $cores++;
            }
        }
        $mem = 0;
        $memFree = 0;
        foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: array() as $l) {
            if (preg_match('/^MemTotal:\s+(\d+) kB/', $l, $m)) {
                $mem = (int) $m[1] * 1024;
            }
            if (preg_match('/^MemAvailable:\s+(\d+) kB/', $l, $m)) {
                $memFree = (int) $m[1] * 1024;
            }
        }
        $load = function_exists('sys_getloadavg') ? (array) sys_getloadavg() : array(0, 0, 0);
        $run = function ($cmd) {
            $out = @shell_exec($cmd . ' 2>/dev/null');
            return trim((string) $out);
        };
        $internal = '';
        foreach (preg_split('/\s+/', $run('hostname -I')) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($ip, '127.') !== 0) {
                $internal = $ip;
                break;
            }
        }
        $count = function ($sql) use ($zdbh) {
            try {
                return (int) $zdbh->query($sql)->fetchColumn();
            } catch (Exception $e) {
                return -1;
            }
        };
        $mods = array();
        try {
            foreach ($zdbh->query("SELECT mo_folder_vc, mo_version_in FROM x_modules WHERE mo_enabled_en = 'true' ORDER BY mo_folder_vc")->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $mods[(string) $m['mo_folder_vc']] = (int) $m['mo_version_in'];
            }
        } catch (Exception $e) {
        }
        $db = '';
        try {
            $db = (string) $zdbh->query("SELECT VERSION()")->fetchColumn();
        } catch (Exception $e) {
        }
        return array(
            'id' => (string) $state['id'],
            'panel' => self::Version(),
            'installed' => date('c', (int) ($state['first'] ?? time())),
            'os' => mb_substr((string) ($os['PRETTY_NAME'] ?? php_uname('s')), 0, 80),
            'os_id' => mb_substr((string) ($os['ID'] ?? ''), 0, 20) . ' ' . mb_substr((string) ($os['VERSION_ID'] ?? ''), 0, 20),
            'kernel' => mb_substr(php_uname('r'), 0, 60),
            'arch' => mb_substr(php_uname('m'), 0, 20),
            'virt' => mb_substr($run('systemd-detect-virt') ?: 'unknown', 0, 30),
            'cpu' => mb_substr($cpu, 0, 100),
            'cores' => $cores,
            'memory' => $mem,
            'disk' => (int) @disk_total_space('/'),
            'disk_free' => (int) @disk_free_space('/'),
            'memory_free' => $memFree,
            'load' => array(round((float) ($load[0] ?? 0), 2), round((float) ($load[1] ?? 0), 2), round((float) ($load[2] ?? 0), 2)),
            'uptime_days' => (int) (((float) explode(' ', (string) @file_get_contents('/proc/uptime'))[0]) / 86400),
            'php' => PHP_VERSION,
            'db' => mb_substr($db, 0, 60),
            'apache' => mb_substr(preg_replace('/^Server version:\s*/', '', $run('apache2 -v | head -1')), 0, 60),
            'public_ip' => (string) ctrl_options::GetSystemOption('server_ip'),
            'internal_ip' => $internal,
            'timezone' => date_default_timezone_get(),
            'counts' => array(
                'accounts' => $count("SELECT COUNT(*) FROM x_accounts WHERE ac_deleted_ts IS NULL"),
                'resellers' => $count("SELECT COUNT(*) FROM x_accounts WHERE ac_group_fk = 2 AND ac_deleted_ts IS NULL"),
                'domains' => $count("SELECT COUNT(*) FROM x_vhosts WHERE vh_type_in = 1 AND vh_deleted_ts IS NULL"),
                'subdomains' => $count("SELECT COUNT(*) FROM x_vhosts WHERE vh_type_in = 2 AND vh_deleted_ts IS NULL"),
                'parked' => $count("SELECT COUNT(*) FROM x_vhosts WHERE vh_type_in = 3 AND vh_deleted_ts IS NULL"),
                'mailboxes' => $count("SELECT COUNT(*) FROM x_mailboxes WHERE mb_deleted_ts IS NULL"),
                'databases' => $count("SELECT COUNT(*) FROM x_mysql_databases WHERE my_deleted_ts IS NULL"),
                'ftp' => $count("SELECT COUNT(*) FROM x_ftpaccounts WHERE ft_deleted_ts IS NULL"),
                'cron' => $count("SELECT COUNT(*) FROM x_cronjobs WHERE ct_deleted_ts IS NULL"),
            ),
            // how busy the websites are: this month's traffic and the disk used, all accounts together (bytes)
            'bandwidth_month' => max(0, $count("SELECT COALESCE(SUM(bd_transamount_bi), 0) FROM x_bandwidth WHERE bd_month_in = " . (int) date('Ym'))),
            'disk_used_accounts' => max(0, $count("SELECT COALESCE(SUM(bd_diskamount_bi), 0) FROM x_bandwidth WHERE bd_month_in = " . (int) date('Ym'))),
            'modules' => $mods,
        );
    }
}
