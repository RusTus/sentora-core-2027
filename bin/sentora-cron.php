<?php

/**
 * Cron jobs on Windows (Cron Manager's tasks): run every minute by the task 'Sentora cron' (SYSTEM, made by the Windows
 * installer). Each task due this minute runs as its account, never as SYSTEM: the script is handed to the account's own
 * PHP (phpaccount.ps1: NT SERVICE\SentoraPHP-NAME, fenced to hostdata\NAME) over FastCGI with the account's key, as Apache
 * hands it a page. Like Linux's /etc/cron.d/sentora-accounts: only enabled accounts, only a real .php file inside the
 * account's public_html, no more often than cron_min_interval, never two runs of one task at once, stopped after
 * cron_max_runtime minutes, its output (at most 1 MB) in the account's tmp\cron-<id>.log for the Cron Manager. A task runs
 * with its site's PHP limits (max_execution_time: set_time_limit() in the script for a longer job).
 *
 *   php sentora-cron.php            start the tasks due now (each in its own process, then return)
 *   php sentora-cron.php job ID     run one task (what the line above starts)
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-25
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
set_time_limit(0);
chdir(str_replace('/bin', '/', str_replace('\\', '/', __DIR__)));
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
if (!sys_versions::IsWindows()) {
    fwrite(STDERR, "Windows only (Linux runs the tasks from /etc/cron.d/sentora-accounts).\n");
    exit(1);
}

/**
 * A cron field matches a value (*, n, a-b, lists, steps).
 *
 * @param string $field
 * @param int $v
 * @param int $min
 * @param int $max
 * @return bool
 */
function zc_match($field, $v, $min, $max)
{
    foreach (explode(',', $field) as $part) {
        $step = 1;
        if (strpos($part, '/') !== false) {
            list($part, $s) = explode('/', $part, 2);
            $step = max(1, (int) $s);
        }
        if ($part === '*') {
            $lo = $min;
            $hi = $max;
        } elseif (strpos($part, '-') !== false) {
            list($lo, $hi) = array_map('intval', explode('-', $part, 2));
        } else {
            $lo = $hi = (int) $part;
            if ($step > 1) {
                $hi = $max;
            }
        }
        if ($v >= $lo && $v <= $hi && ($v - $lo) % $step === 0) {
            return true;
        }
    }
    return false;
}

/**
 * A five-field timing is due at a moment (Sunday is 0 or 7).
 *
 * @param string $timing
 * @param int $t
 * @return bool
 */
function zc_due($timing, $t)
{
    $f = explode(' ', $timing);
    $dow = (int) date('w', $t);
    return zc_match($f[0], (int) date('i', $t), 0, 59) && zc_match($f[1], (int) date('G', $t), 0, 23)
        && zc_match($f[2], (int) date('j', $t), 1, 31) && zc_match($f[3], (int) date('n', $t), 1, 12)
        && (zc_match($f[4], $dow, 0, 7) || ($dow === 0 && zc_match($f[4], 7, 0, 7)));
}

/**
 * The tasks that may run: enabled accounts, a plain timing, a real .php file inside the account's public_html.
 *
 * @return array<int, array{id: int, user: string, script: string, timing: string}>
 */
function zc_jobs()
{
    global $zdbh;
    $hosted = str_replace('\\', '/', rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/\\')) . '/';
    $out = array();
    $q = $zdbh->query("SELECT c.ct_id_pk, c.ct_timing_vc, c.ct_fullpath_vc, a.ac_user_vc FROM x_cronjobs c JOIN x_accounts a ON a.ac_id_pk = c.ct_acc_fk
        WHERE c.ct_deleted_ts IS NULL AND a.ac_enabled_in = 1 AND a.ac_deleted_ts IS NULL");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $user = sys_winphp::Name($r['ac_user_vc']);
        $script = str_replace('\\', '/', (string) $r['ct_fullpath_vc']);
        $root = $hosted . $user . '/public_html/';
        if ($user === '' || !preg_match('#^[0-9*/,-]{1,40}( [0-9*/,-]{1,40}){4}$#', (string) $r['ct_timing_vc'])
            || strncasecmp($script, $root, strlen($root)) !== 0 || strpos($script, '..') !== false
            || !preg_match('#^[A-Za-z]:/[A-Za-z0-9_./-]+\.php$#', $script) || is_link($script) || !is_file($script)
            || !sys_winvhosts::SamePath(realpath($script), $script)) {
            continue;
        }
        $out[(int) $r['ct_id_pk']] = array('id' => (int) $r['ct_id_pk'], 'user' => $user, 'script' => $script, 'timing' => (string) $r['ct_timing_vc']);
    }
    return $out;
}

/**
 * Ask the account's own PHP to run a script (FastCGI, as Apache does), with the account's key.
 *
 * @param array<string, mixed> $acct The account's record from phpaccount.ps1 (base, processes, key).
 * @param string $script
 * @param int $timeout Seconds.
 * @return array{0: string, 1: int|string} The output (without the headers) and the exit code, or 'timeout' / 'no PHP'.
 */
function zc_fastcgi($acct, $script, $timeout)
{
    $ports = range((int) $acct['base'], (int) $acct['base'] + max(1, (int) $acct['processes']) - 1);
    shuffle($ports);
    $s = false;
    foreach ($ports as $p) {
        $s = @stream_socket_client('tcp://127.0.0.1:' . $p, $en, $es, 5);
        if ($s !== false) {
            break;
        }
    }
    if ($s === false) {
        return array('', 'no PHP');
    }
    stream_set_timeout($s, $timeout);
    $rec = function ($type, $content) {
        return pack('CCnnCx', 1, $type, 1, strlen($content), 0) . $content;
    };
    $nv = function ($n, $v) {
        $f = function ($l) {
            return $l < 128 ? chr($l) : pack('N', $l | 0x80000000);
        };
        return $f(strlen($n)) . $f(strlen($v)) . $n . $v;
    };
    $root = dirname($script);
    $params = array('GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_SOFTWARE' => 'Sentora cron', 'REQUEST_METHOD' => 'GET', 'SERVER_PROTOCOL' => 'HTTP/1.1',
        'SCRIPT_FILENAME' => $script, 'SCRIPT_NAME' => '/' . basename($script), 'REQUEST_URI' => '/' . basename($script),
        'DOCUMENT_ROOT' => $root, 'QUERY_STRING' => '', 'REMOTE_ADDR' => '127.0.0.1', 'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '0',
        'REDIRECT_STATUS' => '200', 'SENTORA_KEY' => (string) $acct['key'], 'SENTORA_CRON' => '1');
    $body = '';
    foreach ($params as $k => $v) {
        $body .= $nv($k, (string) $v);
    }
    $req = $rec(1, pack('nCx5', 1, 0));
    foreach (str_split($body, 65000) as $chunk) {
        $req .= $rec(4, $chunk);
    }
    $req .= $rec(4, '') . $rec(5, '');
    fwrite($s, $req);
    $out = '';
    $code = 'timeout';
    $deadline = time() + $timeout;
    while (!feof($s) && time() < $deadline) {
        $h = fread($s, 8);
        if ($h === false || strlen($h) < 8) {
            $meta = stream_get_meta_data($s);
            if ($meta['timed_out']) {
                break;
            }
            continue;
        }
        $x = unpack('Cv/Ct/nid/nlen/Cpad', $h);
        $c = '';
        while (strlen($c) < $x['len'] + $x['pad'] && !feof($s)) {
            $c .= fread($s, $x['len'] + $x['pad'] - strlen($c));
        }
        $c = substr($c, 0, $x['len']);
        if (($x['t'] === 6 || $x['t'] === 7) && strlen($out) < 1048576) {
            $out .= $c;
        } elseif ($x['t'] === 3) {
            $code = unpack('N', substr($c, 0, 4))[1];
            break;
        }
    }
    fclose($s);
    // (the headers php-cgi puts first)
    $cut = strpos($out, "\r\n\r\n");
    if ($cut !== false && preg_match('/^(Status|X-Powered-By|Content-type|Set-Cookie|Expires|Cache-Control|Pragma)\s*:/i', $out)) {
        $out = substr($out, $cut + 4);
    }
    return array(substr($out, 0, 1048576), $code);
}

$limits = sys_accountphp::CronSettings();
$accounts = dirname(dirname((string) ctrl_options::GetSystemOption('apache_vhost'))) . '/php/accounts/';
$hosted = str_replace('\\', '/', rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/\\')) . '/';

if (isset($argv[1]) && $argv[1] === 'job') {
    // one task: as its account, never two at once
    $id = isset($argv[2]) ? (int) $argv[2] : 0;
    $jobs = zc_jobs();
    if (!isset($jobs[$id])) {
        exit(0);
    }
    $j = $jobs[$id];
    $tmp = $hosted . $j['user'] . '/tmp/';
    if (!is_dir($tmp) || is_link(rtrim($tmp, '/'))) {
        exit(0);
    }
    $lock = @fopen($tmp . 'cron-' . $id . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        exit(0);
    }
    $acct = is_file($accounts . $j['user'] . '.json') ? json_decode((string) file_get_contents($accounts . $j['user'] . '.json'), true) : null;
    $started = date('c');
    if (!is_array($acct) || empty($acct['key'])) {
        list($out, $code) = array('This account has no PHP of its own yet (it gets one within 5 minutes).', 'no PHP');
    } else {
        list($out, $code) = zc_fastcgi($acct, $j['script'], (int) $limits['cron_max_runtime'] * 60);
    }
    $text = 'Started ' . $started . "\n" . rtrim($out) . "\n\n" . ($code === 'timeout' ? 'Stopped after ' . (int) $limits['cron_max_runtime'] . ' minutes' : 'Finished ' . date('c') . ', exit code ' . (is_int($code) ? $code : 1)) . "\n";
    $log = $tmp . 'cron-' . $id . '.log';
    if (!is_link($log)) {
        @file_put_contents($log, substr($text, 0, 1048576 + 200));
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

// every minute: start each task due now in its own process (a long one never holds up the others)
$now = time();
$every = max(1, (int) $limits['cron_min_interval']);
$php = (string) ctrl_options::GetSystemOption('php_exer');
foreach (zc_jobs() as $j) {
    $timing = $j['timing'];
    if ($every > 1 && sys_accountphp::CronEvery($timing) < $every) {
        $timing = '*/' . $every . ' * * * *';   // (no more often than the server allows, as on Linux)
    }
    if (!zc_due($timing, $now)) {
        continue;
    }
    pclose(popen('start "" /B "' . str_replace('/', '\\', $php) . '" -q "' . str_replace('/', '\\', __FILE__) . '" job ' . (int) $j['id'], 'r'));
}
