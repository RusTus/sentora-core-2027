<?php

/**
 * Site Health: whether each website works, and what needs attention.
 *
 * The daemon (root, every hour) calls Collect(): for each live domain and sub-domain it
 * - asks the site for its home page from this server (status, time to answer, where it redirects),
 *   over HTTPS when the site has a certificate of its own, and reads that certificate;
 * - notes which PHP it runs (sys_phpversions::LiveState) and how long that PHP gets security fixes;
 * - reads only the NEW lines of the site's own logs since the last run: PHP errors from its error log,
 *   requests and error pages (4xx, 5xx) from its access log.
 * It keeps one summary per account (ACCOUNT_FILE: 30 days of daily counts, a week of hourly checks,
 * the latest PHP errors), which the panel reads (Load(), Assess()); the panel never reads the logs.
 *
 * Files: /var/lib/sentora/sitehealth/, root's with the panel's group (the panel reads, only root writes);
 * the summaries are written to a new temporary file and renamed (never through a planted link), and
 * the read positions in the logs (STATE_FILE) are root's only.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class sys_sitehealth
{
    const DIR = '/var/lib/sentora/sitehealth';
    const STATE_FILE = 'state.json';
    /** Days of daily counts kept; hourly checks kept. */
    const KEEP_DAYS = 30;
    const KEEP_CHECKS = 168;
    /** At most this much of a log is read in one run (a huge backlog: the newest part). */
    const MAX_READ = 67108864;
    /** The whole hourly run stops asking sites after this many seconds (the rest keep their last check). */
    const MAX_RUN = 300;
    /** What the check calls itself (its requests are left out of the counts). */
    const AGENT = 'Sentora-SiteHealth/1.0';

    // ---------------------------------------------------------------------------------------------
    // The panel's side: reading and judging
    // ---------------------------------------------------------------------------------------------

    /**
     * The summary file of an account.
     *
     * @param int $accountId The account ID.
     * @return string
     */
    static function AccountFile($accountId)
    {
        return self::DIR . '/acct-' . (int) $accountId . '.json';
    }

    /**
     * An account's summary, as the last hourly run left it.
     *
     * @param int $accountId The account ID.
     * @return array{time: int, domains: array<string, array>} ('time' 0 and no domains when there is none yet).
     */
    static function Load($accountId)
    {
        $j = @json_decode((string) @file_get_contents(self::AccountFile($accountId)), true);
        return array('time' => is_array($j) && isset($j['time']) ? (int) $j['time'] : 0,
            'domains' => is_array($j) && isset($j['domains']) && is_array($j['domains']) ? $j['domains'] : array());
    }

    /**
     * An account's live domains and sub-domains (parked ones show a holding page: left out).
     *
     * @param int $accountId The account ID.
     * @return array<int, array{id: int, name: string, php: string, sub: bool}>
     */
    static function Domains($accountId)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT vh_id_pk, vh_name_vc, vh_php_version_vc, vh_type_in FROM x_vhosts
            WHERE vh_acc_fk = :a AND vh_deleted_ts IS NULL AND vh_active_in = 1 AND vh_enabled_in = 1 AND vh_type_in IN (1, 2) ORDER BY vh_name_vc");
        $q->execute(array(':a' => (int) $accountId));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = array('id' => (int) $r['vh_id_pk'], 'name' => (string) $r['vh_name_vc'], 'php' => (string) $r['vh_php_version_vc'], 'sub' => (int) $r['vh_type_in'] === 2);
        }
        return $out;
    }

    /**
     * May a user group open a module (for the links next to the reasons)?
     *
     * @param int $groupId The user group.
     * @param string $folder The module's folder.
     * @return bool
     */
    static function CanOpen($groupId, $folder)
    {
        global $zdbh;
        static $ids = null;
        if ($ids === null) {
            $ids = array();
            foreach ($zdbh->query("SELECT mo_id_pk, mo_folder_vc FROM x_modules WHERE mo_enabled_en = 'true'")->fetchAll() as $r) {
                $ids[$r['mo_folder_vc']] = (int) $r['mo_id_pk'];
            }
        }
        return $folder !== '' && isset($ids[$folder]) && ctrl_groups::CheckGroupModulePermissions((int) $groupId, $ids[$folder]);
    }

    /**
     * The words for a level.
     *
     * @param string $level ok, warn, bad or unknown.
     * @return string Translated.
     */
    static function LevelText($level)
    {
        $w = array('ok' => 'Working', 'warn' => 'Needs attention', 'bad' => 'Not working', 'unknown' => 'Not checked yet');
        return ui_language::translate(isset($w[$level]) ? $w[$level] : $w['unknown']);
    }

    /**
     * Today's date key (the logs' own time zone is the server's).
     *
     * @param int $offset Days back (0 = today).
     * @return string 'Y-m-d'
     */
    static function Day($offset = 0)
    {
        return date('Y-m-d', time() - 86400 * (int) $offset);
    }

    /**
     * Judge one domain: ok, warn (needs attention), bad (not working) or unknown (not checked yet),
     * with the reasons in words, worst first, each with the panel module that helps.
     *
     * @param array $d The domain's part of the summary (may be empty).
     * @return array{level: string, reasons: array<int, array{level: string, text: string, module: string}>}
     */
    static function Assess($d)
    {
        $t = function ($s) { return ui_language::translate($s); };
        $r = array();
        $add = function ($level, $text, $module = '') use (&$r) { $r[] = array('level' => $level, 'text' => $text, 'module' => $module); };
        $c = isset($d['check']) && is_array($d['check']) ? $d['check'] : null;
        if ($c === null) {
            return array('level' => 'unknown', 'reasons' => array(array('level' => 'unknown', 'text' => $t('Not checked yet: the first check is within the hour.'), 'module' => '')));
        }
        $at = date('H:i', (int) $c['time']);
        if (empty($c['code'])) {
            $add('bad', str_replace(array('%1', '%2'), array($at, (string) $c['error']), $t('Did not answer at %1 (%2).')), 'domains');
        } elseif ($c['code'] >= 500) {
            $add('bad', str_replace(array('%1', '%2'), array((string) $c['code'], $at), $t('Answered %1 (a server error) at %2: the site shows visitors an error.')));
        } elseif ($c['code'] >= 400) {
            $add('warn', str_replace(array('%1', '%2'), array((string) $c['code'], $at), $t('Answered %1 at %2 instead of the home page.')));
        } elseif ((int) $c['ms'] > 3000) {
            $add('warn', str_replace('%1', number_format($c['ms'] / 1000, 1), $t('Slow: took %1 s to answer.')));
        }
        $cert = isset($d['cert']) && is_array($d['cert']) ? $d['cert'] : null;
        if ($cert !== null) {
            $days = (int) floor(((int) $cert['until'] - time()) / 86400);
            if ($days < 0) {
                $add('bad', str_replace('%1', date('Y-m-d', (int) $cert['until']), $t('The certificate expired on %1: browsers warn visitors.')), 'sencrypt');
            } elseif ($days < 14) {
                $add('warn', str_replace('%1', (string) $days, $t('The certificate expires in %1 days.')), 'sencrypt');
            }
            if (!empty($cert['self'])) {
                $add('warn', $t('Self-signed certificate: browsers warn visitors.'), 'sencrypt');
            }
        }
        $php = isset($d['php']) && is_array($d['php']) ? $d['php'] : array();
        if (isset($php['state']) && $php['state'] === 'down') {
            $add('bad', (string) $php['text'], 'domains');
        }
        if (!empty($php['ended'])) {
            $add('warn', str_replace(array('%1', '%2'), array((string) $php['version'], (string) $php['ended']), $t('PHP %1 has had no security fixes since %2.')), 'domains');
        }
        $today = self::Day();
        $e = isset($d['errors']['days'][$today]) ? $d['errors']['days'][$today] : array(0, 0, 0);
        if ((int) $e[0] > 0) {
            $add('warn', str_replace('%1', (string) (int) $e[0], $t((int) $e[0] === 1 ? '1 fatal PHP error today.' : '%1 fatal PHP errors today.')));
        }
        $tr = isset($d['traffic']['days'][$today]) ? $d['traffic']['days'][$today] : array(0, 0, 0, 0, 0);
        if ((int) $tr[4] >= 5 && (int) $tr[4] > 0.05 * max(1, (int) $tr[0])) {
            $add('warn', str_replace('%1', (string) (int) $tr[4], $t('%1 visits got a server error (5xx) today.')));
        }
        $rank = array('bad' => 3, 'warn' => 2, 'unknown' => 1, 'ok' => 0);
        usort($r, function ($a, $b) use ($rank) { return $rank[$b['level']] - $rank[$a['level']]; });
        $level = 'ok';
        foreach ($r as $x) {
            if ($rank[$x['level']] > $rank[$level]) {
                $level = $x['level'];
            }
        }
        return array('level' => $level, 'reasons' => $r);
    }

    // ---------------------------------------------------------------------------------------------
    // The daemon's side: collecting (root only)
    // ---------------------------------------------------------------------------------------------

    /**
     * Root, every hour: check every live site and read the new part of its logs; write each
     * account's summary. Does nothing for anyone but root.
     *
     * @return string[] What it did, for the daemon log.
     */
    static function Collect()
    {
        global $zdbh;
        $say = array();
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return array('Skipped (needs root).');
        }
        if (!self::PrepareDir()) {
            return array('Could not set up ' . self::DIR . '.');
        }
        $started = time();
        $state = @json_decode((string) @file_get_contents(self::DIR . '/' . self::STATE_FILE), true);
        $state = is_array($state) ? $state : array();
        $logDir = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/') . '/domains/';
        $hosted = rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . '/';
        $accounts = $zdbh->query("SELECT ac_id_pk, ac_user_vc FROM x_accounts WHERE ac_deleted_ts IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        $sites = 0;
        $late = false;
        $keep = array(self::STATE_FILE => true);
        foreach ($accounts as $a) {
            $uid = (int) $a['ac_id_pk'];
            $user = (string) $a['ac_user_vc'];
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $user)) {
                continue;
            }
            $domains = self::Domains($uid);
            if (!$domains) {
                continue;
            }
            $keep[basename(self::AccountFile($uid))] = true;
            $old = self::Load($uid);
            $sum = array('time' => time(), 'domains' => array());
            foreach ($domains as $dom) {
                $name = $dom['name'];
                if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $name)) {
                    continue;
                }
                $d = isset($old['domains'][$name]) && is_array($old['domains'][$name]) ? $old['domains'][$name] : array();
                // the logs: only what is new since the last run
                $d = self::ReadAccessLog($logDir . $user . '/' . $name . '-access.log', $d, $state);
                $d = self::ReadErrorLog($logDir . $user . '/' . $name . '-error.log', $d, $state, $hosted . $user . '/');
                // the check (the run as a whole has a time limit: the rest keep their last check)
                if (!$late && time() - $started < self::MAX_RUN) {
                    $d = self::Check($name, $d);
                } else {
                    $late = true;
                }
                $d['php'] = self::PhpState($name, $dom['php']);
                $sum['domains'][$name] = self::Trim($d);
                $sites++;
            }
            self::WriteJson(self::AccountFile($uid), $sum, 0640);
        }
        // accounts that are gone, and their logs' read positions
        foreach ((array) glob(self::DIR . '/acct-*.json') as $f) {
            if (!isset($keep[basename($f)]) && !is_link($f)) {
                @unlink($f);
            }
        }
        foreach (array_keys($state) as $f) {
            if (!is_file($f)) {
                unset($state[$f]);
            }
        }
        self::WriteJson(self::DIR . '/' . self::STATE_FILE, $state, 0600);
        $say[] = 'Checked ' . $sites . ' site(s) in ' . (time() - $started) . ' s' . ($late ? ' (time limit reached: the rest keep their last check)' : '') . '.';
        return $say;
    }

    /**
     * Make the folder: root's, the panel's group may read it; nothing in it may be a link.
     *
     * @return bool True when it is ready.
     */
    static function PrepareDir()
    {
        $dir = self::DIR;
        if (is_link($dir)) {
            @unlink($dir);
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750)) {
            return false;
        }
        @chown($dir, 0);
        @chgrp($dir, 'sentora');
        @chmod($dir, 0750);
        foreach ((array) scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..' && is_link($dir . '/' . $f)) {
                @unlink($dir . '/' . $f);
            }
        }
        return is_dir($dir) && !is_link($dir);
    }

    /**
     * Write JSON safely: a new temporary file in the folder, then renamed over the name.
     *
     * @param string $file The file.
     * @param array $data The data.
     * @param int $mode 0640 (panel may read) or 0600 (root only).
     * @return bool
     */
    static function WriteJson($file, $data, $mode)
    {
        $tmp = @tempnam(self::DIR, 'sh.');
        if ($tmp === false || dirname($tmp) !== self::DIR) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            return false;
        }
        $ok = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)) !== false
            && @chmod($tmp, $mode) && ($mode === 0600 || @chgrp($tmp, 'sentora')) && @rename($tmp, $file);
        if (!$ok) {
            @unlink($tmp);
        }
        return $ok;
    }

    /**
     * The new lines of a log since the last run (a rotated or shorter log is read from its start).
     *
     * @param string $file The log.
     * @param array $state The read positions (updated).
     * @return string[] Complete lines.
     */
    static function NewLines($file, array &$state)
    {
        if (!is_file($file) || is_link($file)) {
            return array();
        }
        $st = @stat($file);
        if ($st === false) {
            return array();
        }
        $pos = (isset($state[$file]) && (int) $state[$file][0] === (int) $st['ino'] && (int) $state[$file][1] <= (int) $st['size']) ? (int) $state[$file][1] : 0;
        $first = !isset($state[$file]);
        // the first time a log is seen: only what was written today, not its whole past
        if ($first && $st['size'] > 2 * 1048576) {
            $pos = (int) $st['size'] - 2 * 1048576;
        }
        if ((int) $st['size'] - $pos > self::MAX_READ) {
            $pos = (int) $st['size'] - self::MAX_READ;
        }
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return array();
        }
        fseek($fh, $pos);
        $data = (string) stream_get_contents($fh, (int) $st['size'] - $pos);
        fclose($fh);
        $end = strrpos($data, "\n");
        if ($end === false) {
            $state[$file] = array((int) $st['ino'], $pos);
            return array();
        }
        $state[$file] = array((int) $st['ino'], $pos + $end + 1);
        $lines = explode("\n", substr($data, 0, $end));
        // started inside a line: drop that piece
        if ($pos > 0 && ($first || (int) $st['size'] - $pos >= self::MAX_READ)) {
            array_shift($lines);
        }
        return $lines;
    }

    /**
     * Count the new requests of a site's access log (combined or common format): per day the
     * requests and their 2xx, 3xx, 4xx and 5xx answers, and the pages most often not found (404)
     * or failing (5xx). The check's own requests are left out.
     *
     * @param string $file The access log.
     * @param array $d The domain's summary so far.
     * @param array $state The read positions.
     * @return array The summary with the counts added.
     */
    static function ReadAccessLog($file, $d, array &$state)
    {
        $mon = array('Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12);
        foreach (self::NewLines($file, $state) as $line) {
            if (!preg_match('~^\S+ \S+ \S+ \[(\d{2})/(\w{3})/(\d{4}):[^\]]*\] "(?:[A-Z]+ )?(\S*)[^"]*" (\d{3}) ~', $line, $m) || !isset($mon[$m[2]])) {
                continue;
            }
            if (strpos($line, self::AGENT) !== false) {
                continue;
            }
            $day = sprintf('%04d-%02d-%02d', $m[3], $mon[$m[2]], $m[1]);
            $code = (int) $m[5];
            if (!isset($d['traffic']['days'][$day])) {
                $d['traffic']['days'][$day] = array(0, 0, 0, 0, 0);
            }
            $d['traffic']['days'][$day][0]++;
            $class = (int) floor($code / 100);
            if ($class >= 2 && $class <= 5) {
                $d['traffic']['days'][$day][$class - 1]++;
            }
            if ($code === 404 || $class === 5) {
                $path = substr((string) strtok($m[4], '?'), 0, 120);
                if ($path === '' || $path[0] !== '/') {
                    continue;
                }
                $k = $code === 404 ? 'missed' : 'failed';
                if (!isset($d['traffic'][$k][$day][$path])) {
                    $d['traffic'][$k][$day][$path] = 0;
                }
                $d['traffic'][$k][$day][$path]++;
            }
        }
        return $d;
    }

    /**
     * Count the new PHP errors in a site's error log (PHP writes them there through PHP-FPM): per day
     * fatal errors, warnings and notices, and the latest distinct messages, with the account's own
     * folder shortened to ~/.
     *
     * @param string $file The error log.
     * @param array $d The domain's summary so far.
     * @param array $state The read positions.
     * @param string $home The account's hosting folder (ends in /).
     * @return array The summary with the errors added.
     */
    static function ReadErrorLog($file, $d, array &$state, $home)
    {
        $mon = array('Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12);
        $latest = isset($d['errors']['latest']) && is_array($d['errors']['latest']) ? $d['errors']['latest'] : array();
        foreach (self::NewLines($file, $state) as $line) {
            if (strpos($line, 'PHP ') === false
                || !preg_match('/^\[\w{3} (\w{3}) +(\d{1,2}) (\d{2}):(\d{2}):(\d{2})(?:\.\d+)? (\d{4})\]/', $line, $t) || !isset($mon[$t[1]])) {
                continue;
            }
            $when = mktime((int) $t[3], (int) $t[4], (int) $t[5], $mon[$t[1]], (int) $t[2], (int) $t[6]);
            $day = date('Y-m-d', $when);
            if (!preg_match_all('/PHP (Fatal error|Parse error|Recoverable fatal error|Warning|Notice|Deprecated):\s+(.*?)(?=\\\\n|PHP message:|\', referer:|"?\s*$)/', $line, $all, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($all as $x) {
                $kind = (strpos($x[1], 'atal') !== false || strpos($x[1], 'Parse') !== false) ? 0 : ($x[1] === 'Warning' ? 1 : 2);
                if (!isset($d['errors']['days'][$day])) {
                    $d['errors']['days'][$day] = array(0, 0, 0);
                }
                $d['errors']['days'][$day][$kind]++;
                if ($kind === 2) {
                    continue; // notices and deprecations are counted, not listed
                }
                $msg = trim(str_replace(array($home . 'public_html/', $home), array('', '~/'), $x[2]));
                $msg = substr(preg_replace('/\s+/', ' ', $msg), 0, 300);
                $key = $x[1] . '|' . $msg;
                if (isset($latest[$key])) {
                    $latest[$key]['n']++;
                    $latest[$key]['t'] = $when;
                } else {
                    $latest[$key] = array('level' => $x[1], 'msg' => $msg, 't' => $when, 'n' => 1);
                }
            }
        }
        // the 8 most recent distinct messages
        uasort($latest, function ($a, $b) { return $b['t'] - $a['t']; });
        $d['errors']['latest'] = array_slice($latest, 0, 8, true);
        return $d;
    }

    /**
     * Ask the site for its home page from this server: over HTTPS when the site has a certificate
     * of its own here (its name in the certificate), else over HTTP. Notes the answer, the time to
     * the first line of it, where it redirects, and the certificate.
     *
     * @param string $domain The domain.
     * @param array $d The domain's summary so far.
     * @return array The summary with this check added.
     */
    static function Check($domain, $d)
    {
        $res = self::Probe($domain, 443, true);
        $cert = null;
        if ($res['cert'] !== null && self::CertCovers($res['cert'], $domain)) {
            $cert = $res['cert'];
        } else {
            $res = self::Probe($domain, 80, false);
        }
        $check = array('time' => time(), 'code' => $res['code'], 'ms' => $res['ms'], 'error' => $res['error'], 'https' => $cert !== null, 'location' => $res['location']);
        $d['check'] = $check;
        $d['cert'] = $cert === null ? null : array('until' => (int) $cert['validTo_time_t'],
            'issuer' => self::CertIssuer($cert), 'self' => self::CertSelfSigned($cert));
        $hist = isset($d['history']) && is_array($d['history']) ? $d['history'] : array();
        $hist[] = array($check['time'], (int) $check['code'], (int) $check['ms']);
        $d['history'] = array_slice($hist, -self::KEEP_CHECKS);
        return $d;
    }

    /**
     * One request for "/" to this server's own web server, as the named site.
     *
     * @param string $domain The site's name (Host, and the TLS name).
     * @param int $port 443 or 80.
     * @param bool $tls Over TLS.
     * @return array{code: int, ms: int, error: string, location: string, cert: array|null}
     */
    static function Probe($domain, $port, $tls)
    {
        $out = array('code' => 0, 'ms' => 0, 'error' => '', 'location' => '', 'cert' => null);
        $ctx = stream_context_create(array('ssl' => array('peer_name' => $domain, 'SNI_enabled' => true, 'verify_peer' => false,
            'verify_peer_name' => false, 'allow_self_signed' => true, 'capture_peer_cert' => true)));
        $t0 = microtime(true);
        $fp = @stream_socket_client(($tls ? 'ssl' : 'tcp') . '://127.0.0.1:' . (int) $port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            $out['error'] = $errstr !== '' ? $errstr : 'no connection';
            return $out;
        }
        if ($tls) {
            $p = stream_context_get_params($fp);
            if (isset($p['options']['ssl']['peer_certificate'])) {
                $parsed = @openssl_x509_parse($p['options']['ssl']['peer_certificate']);
                $out['cert'] = is_array($parsed) ? $parsed : null;
            }
        }
        stream_set_timeout($fp, 20);
        fwrite($fp, "GET / HTTP/1.1\r\nHost: " . $domain . "\r\nUser-Agent: " . self::AGENT . "\r\nAccept: text/html\r\nConnection: close\r\n\r\n");
        $status = (string) fgets($fp, 512);
        $out['ms'] = (int) round((microtime(true) - $t0) * 1000);
        if (preg_match('~^HTTP/\d(?:\.\d)? (\d{3})~', $status, $m)) {
            $out['code'] = (int) $m[1];
            // the headers, for a redirect's target
            for ($i = 0; $i < 60 && !feof($fp); $i++) {
                $h = (string) fgets($fp, 2048);
                if (trim($h) === '') {
                    break;
                }
                if (stripos($h, 'Location:') === 0) {
                    $out['location'] = substr(trim(substr($h, 9)), 0, 200);
                }
            }
        } else {
            $meta = stream_get_meta_data($fp);
            $out['error'] = !empty($meta['timed_out']) ? 'no answer within 20 s' : 'not a web answer';
        }
        fclose($fp);
        return $out;
    }

    /**
     * Does a certificate name the domain (its alternative names, or its common name)?
     *
     * @param array $cert openssl_x509_parse() output.
     * @param string $domain The domain.
     * @return bool
     */
    static function CertCovers($cert, $domain)
    {
        $names = array();
        if (isset($cert['extensions']['subjectAltName'])) {
            foreach (explode(',', (string) $cert['extensions']['subjectAltName']) as $n) {
                $n = trim($n);
                if (stripos($n, 'DNS:') === 0) {
                    $names[] = strtolower(substr($n, 4));
                }
            }
        }
        if (!$names && isset($cert['subject']['CN'])) {
            $names[] = strtolower((string) $cert['subject']['CN']);
        }
        $domain = strtolower($domain);
        foreach ($names as $n) {
            if ($n === $domain || (strpos($n, '*.') === 0 && substr($domain, strpos($domain, '.')) === substr($n, 1))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Who issued a certificate, in a few words.
     *
     * @param array $cert openssl_x509_parse() output.
     * @return string
     */
    static function CertIssuer($cert)
    {
        $o = isset($cert['issuer']['O']) ? (string) (is_array($cert['issuer']['O']) ? reset($cert['issuer']['O']) : $cert['issuer']['O']) : '';
        if ($o === '' && isset($cert['issuer']['CN'])) {
            $o = (string) (is_array($cert['issuer']['CN']) ? reset($cert['issuer']['CN']) : $cert['issuer']['CN']);
        }
        return substr($o, 0, 80);
    }

    /**
     * Is a certificate self-signed (issued by itself)?
     *
     * @param array $cert openssl_x509_parse() output.
     * @return bool
     */
    static function CertSelfSigned($cert)
    {
        return isset($cert['issuer'], $cert['subject']) && $cert['issuer'] == $cert['subject'];
    }

    /**
     * The PHP a site runs (the badge's check) and how long that version gets security fixes.
     *
     * @param string $domain The domain.
     * @param string $chosen Its chosen version ('' = the default).
     * @return array{version: string, state: string, text: string, ended: string}
     */
    static function PhpState($domain, $chosen)
    {
        $live = sys_phpversions::LiveState($domain, $chosen);
        $version = sys_phpversions::Effective((string) $chosen);
        $left = sys_phpversions::DaysLeft($version);
        $ends = $left !== null && isset(sys_phpversions::$supportEnds[$version]) ? sys_phpversions::$supportEnds[$version] : '';
        return array('version' => $version, 'state' => (string) $live['state'], 'text' => (string) $live['text'],
            'ended' => ($left !== null && $left < 0) ? $ends : '', 'until' => ($left !== null && $left >= 0) ? $ends : '');
    }

    /**
     * Keep a domain's summary small: 30 days of counts, the error-page lists of today and yesterday
     * (the 15 most frequent), a week of checks.
     *
     * @param array $d The domain's summary.
     * @return array
     */
    static function Trim($d)
    {
        $oldest = self::Day(self::KEEP_DAYS);
        foreach (array(array('traffic', 'days'), array('errors', 'days')) as $p) {
            if (isset($d[$p[0]][$p[1]]) && is_array($d[$p[0]][$p[1]])) {
                foreach (array_keys($d[$p[0]][$p[1]]) as $day) {
                    if ($day < $oldest) {
                        unset($d[$p[0]][$p[1]][$day]);
                    }
                }
            }
        }
        $recent = array(self::Day(0) => true, self::Day(1) => true);
        foreach (array('missed', 'failed') as $k) {
            if (isset($d['traffic'][$k]) && is_array($d['traffic'][$k])) {
                foreach ($d['traffic'][$k] as $day => $paths) {
                    if (!isset($recent[$day])) {
                        unset($d['traffic'][$k][$day]);
                        continue;
                    }
                    arsort($paths);
                    $d['traffic'][$k][$day] = array_slice($paths, 0, 15, true);
                }
            }
        }
        return $d;
    }
}
