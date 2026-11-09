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

    /** The Sentora-2027 add-on repository (Repo Browser's own). */
    const ADDON_REPO = 'https://raw.githubusercontent.com/RusTus/sentora-installers-2027/master2027/sentora-repo';

    /** The feedback server's address, for the panel and zppy to read (no secret in it). */
    const BASE_FILE = '/var/lib/sentora/feedback-server.txt';

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
            array('feedback_url', 'Feedback server', '', '', 'The feedback server\'s address (https://stats.example.com): reports go to its api.php, and it counts the downloads of the Sentora-2027 add-ons. Empty: the one the Sentora-2027 project publishes.'),
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
            // the server's address (https://stats.example.com) or its api.php
            $set = preg_match('#\.php$#', $set) ? $set : rtrim($set, '/') . '/api.php';
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
     * Daemon (every run): note the feedback server's address for the panel and zppy (BASE_FILE):
     * the setting, else the address the project published (read by Run() once a day); none when
     * customer feedback is off. Its download counts and Repo Browser's numbers use it when it is
     * there, and do without it when it is not.
     *
     * @return void
     */
    static function NoteBase()
    {
        $s = self::Settings();
        $state = self::State();
        $api = '';
        if ($s['on']) {
            $api = $s['url'] !== '' ? self::Endpoint($s['url'], $state) : (isset($state['endpoint']) ? (string) $state['endpoint'] : '');
        }
        $base = preg_match('#^(https://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/%-]*)?)/api\.php$#', $api, $m) ? $m[1] : '';
        $now = is_file(self::BASE_FILE) ? trim((string) @file_get_contents(self::BASE_FILE)) : '';
        if ($base === $now || is_link(self::BASE_FILE)) {
            return;
        }
        if ($base === '') {
            @unlink(self::BASE_FILE);
            return;
        }
        @file_put_contents(self::BASE_FILE . '.new', $base . "\n");
        @chmod(self::BASE_FILE . '.new', 0644);
        @rename(self::BASE_FILE . '.new', self::BASE_FILE);
    }

    /**
     * The feedback server's address ('' when there is none), as the daemon noted it.
     *
     * @return string Such as https://stats.example.com
     */
    static function Base()
    {
        $b = is_file(self::BASE_FILE) && !is_link(self::BASE_FILE) ? trim((string) @file_get_contents(self::BASE_FILE, false, null, 0, 300)) : '';
        return preg_match('#^https://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/%-]*)?$#', $b) ? rtrim($b, '/') : '';
    }

    /**
     * zppy (root), after downloading a module of the Sentora-2027 repository: tell the feedback server,
     * when there is one, so it counts the download (an install or an update; this panel's random ID
     * with it, as its reports carry it). Five seconds at most, the answer not waited for beyond that:
     * the download never depends on it.
     *
     * @param string $repo The repository the module came from.
     * @param string $module
     * @param string $for install or upgrade
     * @return void
     */
    static function CountDownload($repo, $module, $for)
    {
        $base = self::Base();
        if ($base === '' || rtrim((string) $repo, '/') !== self::ADDON_REPO || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $module)) {
            return;
        }
        $q = 'for=' . ($for === 'upgrade' ? 'upgrade' : 'install');
        $f = self::DIR . '/feedback.json';
        $s = is_file($f) && !is_link($f) ? json_decode((string) @file_get_contents($f), true) : null;
        if (is_array($s) && isset($s['id']) && preg_match('/^[0-9a-f]{32}$/', (string) $s['id'])) {
            $q .= '&panel=' . $s['id'];
        }
        $c = curl_init($base . '/repo/' . $module . '.zpp?' . $q);
        curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'ZppyClient (Sentora-2027 ' . self::Version() . ')'));
        @curl_exec($c);
        curl_close($c);
    }

    /**
     * This panel's ratings of the Sentora-2027 add-ons (Repo Browser), kept until the daemon has sent
     * them: the table is made when missing.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function EnsureRatings()
    {
        global $zdbh;
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_repo_ratings (rr_module_vc VARCHAR(64) NOT NULL PRIMARY KEY, rr_stars_in TINYINT NOT NULL,
            rr_comment_tx TEXT NULL, rr_acc_fk INT NOT NULL, rr_at_in INT NOT NULL, rr_sent_in INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Daemon (every run): send the ratings not sent yet to the feedback server, with this panel's
     * random ID (it accepts them only from a panel that reports to it, from that panel's address).
     * Without a feedback server they wait; a rating it refuses for good is not sent again.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string What happened ('' when nothing).
     */
    static function SendRatings()
    {
        global $zdbh;
        self::EnsureRatings();
        $base = self::Base();
        $state = self::State();
        if ($base === '' || empty($state['sent'])) {
            return '';
        }
        $rows = $zdbh->query("SELECT r.*, m.mo_version_in FROM x_repo_ratings r LEFT JOIN x_modules m ON m.mo_folder_vc = r.rr_module_vc
            WHERE r.rr_sent_in IS NULL OR r.rr_sent_in < r.rr_at_in")->fetchAll(PDO::FETCH_ASSOC);
        $sent = 0;
        foreach ($rows as $r) {
            $c = curl_init($base . '/repo/rate.php');
            curl_setopt_array($c, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'User-Agent: Sentora-2027/' . self::Version()),
                CURLOPT_POSTFIELDS => json_encode(array('panel' => (string) $state['id'], 'module' => (string) $r['rr_module_vc'], 'stars' => (int) $r['rr_stars_in'],
                    'comment' => (string) $r['rr_comment_tx'], 'version' => (string) $r['mo_version_in']))));
            curl_exec($c);
            $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
            curl_close($c);
            // sent; or refused for good (not a rating, no such module): not tried again
            if (($code >= 200 && $code < 300) || $code === 400 || $code === 404) {
                $zdbh->prepare("UPDATE x_repo_ratings SET rr_sent_in = :t WHERE rr_module_vc = :m")->execute(array(':t' => time(), ':m' => $r['rr_module_vc']));
                $sent += $code < 300 ? 1 : 0;
            }
        }
        return $sent ? 'Add-on ratings sent to the feedback server: ' . $sent : '';
    }

    /**
     * What the feedback server says other panels think of a module: the newest comments.
     *
     * @param string $module
     * @return array<int, array{stars:int, comment:string, at:int, version:string}>|null Null when it can not be asked.
     */
    static function Reviews($module)
    {
        $base = self::Base();
        if ($base === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $module)) {
            return null;
        }
        $c = curl_init($base . '/repo/reviews.php?module=' . rawurlencode($module));
        curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Sentora-2027 Repo Browser'));
        $j = json_decode((string) curl_exec($c), true);
        curl_close($c);
        if (!is_array($j)) {
            return null;
        }
        $out = array();
        foreach (array_slice($j, 0, 20) as $r) {
            if (is_array($r) && isset($r['stars'], $r['comment'])) {
                $out[] = array('stars' => max(1, min(5, (int) $r['stars'])), 'comment' => mb_substr((string) $r['comment'], 0, 500), 'at' => (int) ($r['at'] ?? 0),
                    'version' => preg_match('/^[0-9][0-9.]{0,20}$/', (string) ($r['version'] ?? '')) ? (string) $r['version'] : '');
            }
        }
        return $out;
    }

    /**
     * The download counts and ratings the feedback server keeps of the Sentora-2027 repository's
     * modules, for Repo Browser; empty when there is no feedback server or it does not answer.
     *
     * @return array{counts: array<string, int>, stars: array<string, array{0: float, 1: int}>}
     */
    static function Downloads()
    {
        $base = self::Base();
        if ($base === '') {
            return array();
        }
        $c = curl_init($base . '/repo/repostats.php?json=true');
        curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Sentora-2027 Repo Browser'));
        $j = json_decode((string) curl_exec($c), true);
        curl_close($c);
        $out = array('counts' => array(), 'stars' => array());
        foreach (is_array($j) ? $j : array() as $r) {
            if (is_array($r) && isset($r['package'], $r['downloads']) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $r['package'])) {
                $out['counts'][(string) $r['package']] = max(0, (int) $r['downloads']);
                if (isset($r['rating'], $r['ratings']) && (int) $r['ratings'] > 0) {
                    $out['stars'][(string) $r['package']] = array(max(1.0, min(5.0, round((float) $r['rating'], 1))), (int) $r['ratings']);
                }
            }
        }
        return $out;
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
     * A key for this computer, the same through every reinstall on it (of the panel, and of the
     * operating system too): a one-way hash, with Sentora-2027's own label, of the hardware's ID. It
     * can not be turned back into the ID, nor matched with what other software sends. The install's
     * random ID (feedback.json) tells installs apart; this tells computers apart, so a reinstall shows
     * as a new install on a known computer (and cloned virtual machines as one computer).
     *
     * Taken from, in turn: the motherboard's UUID (DMI; virtual machines keep theirs too), skipping
     * the placeholders cheap boards carry; else the built-in addresses of the network cards (real
     * ones only, sorted); else the operating system's machine ID (which a reinstall of the operating
     * system changes). Read as root (the daemon).
     *
     * @return string 32 hex characters, or '' when the computer has none of them.
     */
    static function MachineKey()
    {
        $key = function ($what, $v) {
            return substr(hash('sha256', 'sentora-2027 machine|' . $what . '|' . $v), 0, 32);
        };
        $uuid = strtolower(trim((string) @file_get_contents('/sys/class/dmi/id/product_uuid', false, null, 0, 100)));
        $placeholder = array('03000200-0400-0500-0006-000700080009', '00020003-0004-0005-0006-000700080009', '12345678-1234-5678-90ab-cddeefaabbcc');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid) && trim($uuid, '0-') !== '' && trim($uuid, 'f-') !== ''
            && !in_array($uuid, $placeholder, true)) {
            return $key('uuid', $uuid);
        }
        // the network cards' own addresses (a card with a device behind it: no bridge, VPN or container's)
        $macs = array();
        foreach ((array) glob('/sys/class/net/*') as $dev) {
            if (!is_dir($dev . '/device')) {
                continue;
            }
            $mac = strtolower(trim((string) @file_get_contents($dev . '/address', false, null, 0, 40)));
            // (the card's own address: a bond or a team may have put another on it)
            $perm = trim((string) @shell_exec('ethtool -P ' . escapeshellarg(basename($dev)) . ' 2>/dev/null'));
            if (preg_match('/([0-9a-f]{2}(:[0-9a-f]{2}){5})$/i', $perm, $m) && $m[1] !== '00:00:00:00:00:00') {
                $mac = strtolower($m[1]);
            }
            // (a locally made address, second digit 2, 6, a or e, is no card's own)
            if (preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $mac) && $mac !== '00:00:00:00:00:00' && !(hexdec(substr($mac, 0, 2)) & 2)) {
                $macs[$mac] = true;
            }
        }
        if ($macs) {
            ksort($macs);
            return $key('mac', implode(',', array_keys($macs)));
        }
        foreach (array('/etc/machine-id', '/var/lib/dbus/machine-id') as $f) {
            $v = strtolower(trim((string) @file_get_contents($f, false, null, 0, 100)));
            if (preg_match('/^[0-9a-f]{32}$/', $v) && trim($v, '0') !== '') {
                return $key('os', $v);
            }
        }
        return '';
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
            'machine' => self::MachineKey(),
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
