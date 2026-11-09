<?php

/**
 * Server Monitor data (administrators only): the kernel's live counters from
 * /proc, as JSON. The page turns counters into rates between two samples, so
 * this stays a quick read with no state on the server.
 *
 * Process names only (never command lines: those can hold passwords).
 *
 * Written by RusTus, 2026-11-09
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

session_start();
ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!$user || (int) $user['usergroupid'] !== 1) {
    http_response_code(403);
    exit('{"error":"Administrators only."}');
}

/**
 * Read a whole file, quietly.
 *
 * @param string $f The file path (e.g. /proc/stat).
 * @return string The contents, or '' when it can not be read.
 */
function sm_read($f)
{
    $s = @file_get_contents($f);
    return $s === false ? '' : $s;
}

$out = array('t' => microtime(true));

// CPU time per state, for all CPUs together and for each one
$out['cpu'] = array();
foreach (explode("\n", sm_read('/proc/stat')) as $line) {
    if (preg_match('/^(cpu\d*)\s+(.*)$/', $line, $m)) {
        $v = array_map('intval', preg_split('/\s+/', trim($m[2])));
        $out['cpu'][$m[1]] = array_slice(array_pad($v, 8, 0), 0, 8); // user nice system idle iowait irq softirq steal
    } elseif (preg_match('/^procs_running\s+(\d+)/', $line, $m)) {
        $out['running'] = (int) $m[1];
    }
}

$load = preg_split('/\s+/', trim(sm_read('/proc/loadavg')));
$out['load'] = array((float) ($load[0] ?? 0), (float) ($load[1] ?? 0), (float) ($load[2] ?? 0));
$out['uptime'] = (float) strtok(sm_read('/proc/uptime'), ' ');

$mem = array();
foreach (explode("\n", sm_read('/proc/meminfo')) as $line) {
    if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
        $mem[$m[1]] = (int) $m[2] * 1024;
    }
}
$out['mem'] = array(
    'total' => $mem['MemTotal'] ?? 0, 'available' => $mem['MemAvailable'] ?? 0, 'free' => $mem['MemFree'] ?? 0,
    'buffers' => $mem['Buffers'] ?? 0, 'cached' => ($mem['Cached'] ?? 0) + ($mem['SReclaimable'] ?? 0),
    'swapTotal' => $mem['SwapTotal'] ?? 0, 'swapFree' => $mem['SwapFree'] ?? 0,
);

// Network: bytes in and out per interface (not loopback)
$out['net'] = array();
foreach (array_slice(explode("\n", sm_read('/proc/net/dev')), 2) as $line) {
    if (preg_match('/^\s*([^:\s]+):\s*(.*)$/', $line, $m) && $m[1] !== 'lo') {
        $v = preg_split('/\s+/', trim($m[2]));
        $out['net'][$m[1]] = array((int) $v[0], (int) $v[8]);
    }
}

// Disk I/O: bytes read and written per whole disk
$out['io'] = array();
foreach (explode("\n", sm_read('/proc/diskstats')) as $line) {
    $v = preg_split('/\s+/', trim($line));
    if (count($v) > 9 && preg_match('/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|hd[a-z]+|nvme\d+n\d+|mmcblk\d+)$/', $v[2])) {
        $out['io'][$v[2]] = array((int) $v[5] * 512, (int) $v[9] * 512);
    }
}

// Disk space per mounted file system (each device once)
$out['fs'] = array();
$seen = array();
foreach (explode("\n", sm_read('/proc/mounts')) as $line) {
    $v = explode(' ', $line);
    if (count($v) < 3 || !in_array($v[2], array('ext4', 'ext3', 'xfs', 'btrfs', 'zfs', 'f2fs', 'vfat'), true) || isset($seen[$v[0]])) {
        continue;
    }
    $mount = str_replace('\040', ' ', $v[1]);
    $total = @disk_total_space($mount);
    $free = @disk_free_space($mount);
    if ($total > 0) {
        $seen[$v[0]] = true;
        $out['fs'][] = array('mount' => $mount, 'total' => (int) $total, 'free' => (int) $free);
    }
}

if (!count($out['fs']) && @disk_total_space('/') > 0) {
    // No ordinary disk file systems listed (containers, unusual set-ups): at least the root one
    $out['fs'][] = array('mount' => '/', 'total' => (int) disk_total_space('/'), 'free' => (int) disk_free_space('/'));
}

// Processes: name, owner, CPU ticks and memory, for the busiest-processes table
$users = array();
$out['procs'] = array();
foreach ((array) glob('/proc/[0-9]*', GLOB_ONLYDIR) as $dir) {
    $stat = sm_read($dir . '/stat');
    $l = strrpos($stat, ')');
    if ($l === false) {
        continue;
    }
    $name = substr($stat, strpos($stat, '(') + 1, $l - strpos($stat, '(') - 1);
    $f = explode(' ', substr($stat, $l + 2));
    $rss = (int) ($f[21] ?? 0) * 4096;
    if ($rss === 0) {
        continue; // kernel threads
    }
    $owner = @fileowner($dir);
    if (!isset($users[$owner])) {
        $pw = function_exists('posix_getpwuid') ? posix_getpwuid($owner) : false;
        $users[$owner] = $pw ? $pw['name'] : (string) $owner;
    }
    $out['procs'][] = array((int) basename($dir), $name, $users[$owner], (int) $f[11] + (int) $f[12], $rss);
}

// Sentora's services: running or not (by process name)
$names = array();
foreach ($out['procs'] as $p) {
    $names[$p[1]] = true;
}
$services = array('Apache' => array('apache2'), 'MySQL' => array('mysqld', 'mariadbd'),
    'PHP-FPM' => array('php-fpm' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'php-fpm'),
    'Postfix' => array('master'), 'Dovecot' => array('dovecot'), 'BIND' => array('named'), 'ProFTPD' => array('proftpd'),
    'SOGo' => array('sogod'), 'Memcached' => array('memcached'), 'fail2ban' => array('fail2ban-server', 'fail2ban-serve'),
    'Cron' => array('cron'), 'SSH' => array('sshd'));
$out['services'] = array();
foreach ($services as $label => $procs) {
    $up = false;
    foreach ($procs as $p) {
        $up = $up || isset($names[$p]);
    }
    $out['services'][$label] = $up;
}

if (isset($_GET['info'])) {
    $os = array();
    foreach (explode("\n", sm_read('/etc/os-release')) as $line) {
        if (preg_match('/^(\w+)="?([^"]*)"?$/', $line, $m)) {
            $os[$m[1]] = $m[2];
        }
    }
    preg_match('/^model name\s*:\s*(.+)$/m', sm_read('/proc/cpuinfo'), $cm);
    $out['info'] = array(
        'host' => php_uname('n'), 'kernel' => php_uname('r'), 'os' => $os['PRETTY_NAME'] ?? 'Linux',
        'cpu' => isset($cm[1]) ? trim($cm[1]) : '', 'cores' => max(1, count($out['cpu']) - 1), 'hz' => 100,
    );
}

echo json_encode($out);
