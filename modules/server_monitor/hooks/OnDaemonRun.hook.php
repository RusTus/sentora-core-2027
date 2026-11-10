<?php

/**
 * Server alerts (every daemon run, 5 minutes): emails the administrator when a disk
 * is getting full, a core service has stopped, or the outgoing mail queue grows (often
 * a site sending spam). One email when a problem starts, a reminder every 24 hours
 * while it lasts, and one when it is resolved; never one every 5 minutes.
 *
 * Settings (Admin > Sentora Config): "Alert email address" (empty: the administrator
 * account's email address) and "Alert disk use percent" (85). Every alert is also
 * written to /var/sentora/logs/alerts.log.
 *
 * Written by RusTus, 2026-11-10
 */
global $zdbh; // hooks are included inside a function
// Sentora Config > Two-factor login: disabled | enabled | enabled-forced (was true | false)
ctrl_twofactor::EnsureSetting();
echo fs_filehandler::NewLine() . "START Server alerts" . fs_filehandler::NewLine();
// not while the installer is still setting up the services (a flag older than
// 2 hours is a crashed install: alerts run again)
$saInstalling = '/etc/sentora/.installing';
if (is_file($saInstalling) && time() - (int) filemtime($saInstalling) < 7200) {
    echo "The installer is still running: server alerts start when it is done." . fs_filehandler::NewLine();
} else {
    SentoraServerAlerts();
}
echo "END Server alerts" . fs_filehandler::NewLine();

/**
 * Read a Sentora Config setting, adding it with its default when it does not exist.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @param string $name The setting name (so_name_vc).
 * @param string $clean The label shown in Sentora Config.
 * @param string $default The value to store and return when the setting is new.
 * @param string $desc The description shown in Sentora Config.
 * @return string The setting's value.
 */
function SentoraAlertSetting($name, $clean, $default, $desc)
{
    global $zdbh;
    $q = $zdbh->prepare("SELECT so_value_tx FROM x_settings WHERE so_name_vc = :n");
    $q->execute(array(':n' => $name));
    $row = $q->fetch();
    if ($row === false) {
        $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
            VALUES (:n, :c, :v, NULL, :d, 'Sentora Config', 'true')")->execute(array(':n' => $name, ':c' => $clean, ':v' => $default, ':d' => $desc));
        return $default;
    }
    return (string) $row['so_value_tx'];
}

/**
 * Check disks, services, the mail queue and the off-server backup, and email alerts.
 *
 * Compares with the state in /var/lib/sentora/alerts.json so each problem is emailed
 * when it starts, daily while it lasts and once when resolved; also writes alerts.log
 * and echoes a summary for the daemon log.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function SentoraServerAlerts()
{
    global $zdbh;
    $to = trim(SentoraAlertSetting('alert_email', 'Alert email address', '',
        'Where server alerts go (disk space, stopped services, mail queue). Empty: the administrator account\'s email address.'));
    $limit = (int) SentoraAlertSetting('alert_disk_percent', 'Alert disk use percent', '85',
        'Send an alert when a disk is fuller than this (percent).');
    $limit = ($limit >= 50 && $limit <= 99) ? $limit : 85;
    if ($to === '') {
        $to = (string) $zdbh->query("SELECT ac_email_vc FROM x_accounts WHERE ac_id_pk = 1")->fetchColumn();
    }

    // --- what is wrong now -------------------------------------------------------------
    $now = array();
    $seen = array();
    foreach ((array) @file('/proc/mounts', FILE_IGNORE_NEW_LINES) as $line) {
        $f = explode(' ', $line);
        if (count($f) < 3 || !in_array($f[2], array('ext4', 'ext3', 'xfs', 'btrfs', 'zfs', 'f2fs'), true) || isset($seen[$f[0]])) {
            continue;
        }
        $seen[$f[0]] = true;
        $total = @disk_total_space($f[1]);
        $free = @disk_free_space($f[1]);
        if ($total > 0) {
            $used = (int) round(100 * ($total - $free) / $total);
            if ($used >= $limit) {
                $now['disk:' . $f[1]] = 'Disk ' . $f[1] . ' is ' . $used . '% full (' . round($free / 1073741824, 1) . ' GB free).';
            }
        }
    }
    if (!$seen && @disk_total_space('/') > 0) {
        // no ordinary disk file system listed (containers and the like): at least /
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        $used = (int) round(100 * ($total - $free) / $total);
        if ($used >= $limit) {
            $now['disk:/'] = 'Disk / is ' . $used . '% full (' . round($free / 1073741824, 1) . ' GB free).';
        }
    }
    $systemd = is_dir('/run/systemd/system');
    $php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    // (service and process names of this Linux system: Sentora's settings, sys_platform)
    $smOpt = function ($name, $default) { $v = (string) ctrl_options::GetSystemOption($name); return $v !== '' ? $v : $default; };
    $smApache = $smOpt('apache_sn', 'apache2');
    $smCron = sys_platform::Get('cron_service');
    $services = array('Apache' => array($smApache, $smApache), 'PHP ' . $php => array(sys_platform::Get('php_fpm_service', array('v' => $php)), sys_platform::Get('php_fpm_process', array('v' => $php))),
        'MySQL' => array(sys_platform::Get('db_service'), 'mysqld'), 'Postfix' => array('postfix', 'master'), 'Dovecot' => array(sys_platform::Get('dovecot_service'), 'dovecot'),
        'ProFTPD' => array($smOpt('ftp_service', 'proftpd'), 'proftpd'), 'BIND' => array($smOpt('bind_service', 'named'), 'named'), 'fail2ban' => array('fail2ban', 'fail2ban-server'),
        'cron' => array($smCron, $smCron));
    if (is_file(sys_platform::Get('sogo_bin'))) {
        $services['SOGo'] = array('sogo', 'sogod');
        $services['memcached'] = array('memcached', 'memcached');
    }
    foreach ($services as $label => $s) {
        $rc = 1;
        if ($systemd) {
            exec('systemctl is-active --quiet ' . escapeshellarg($s[0]) . ' 2>/dev/null', $o, $rc);
        } else {
            exec('pgrep -x ' . escapeshellarg($s[1]) . ' > /dev/null 2>&1', $o, $rc);
        }
        if ($rc !== 0) {
            $now['service:' . $s[0]] = $label . ' is not running (systemctl status ' . $s[0] . ').';
        }
    }
    $pq = sys_platform::Get('postqueue_bin');
    if ($pq !== '' && is_executable($pq)) {
        $queued = 0;
        exec(escapeshellarg($pq) . ' -j 2>/dev/null', $lines);
        $queued = count(array_filter((array) $lines, 'strlen'));
        if ($queued > 100) {
            $now['mailqueue'] = $queued . ' messages are waiting in the outgoing mail queue (often a site sending spam: mailq shows them).';
        }
    }

    // off-server backup (Admin > Backup Config): switched on, but no good copy for 26 hours
    if (ctrl_options::GetSystemOption('offsite_enabled') === 'true') {
        $ob = json_decode((string) @file_get_contents('/var/lib/sentora/offsite-backup.json'), true);
        $lastOk = is_array($ob) && isset($ob['last_ok']) ? (int) $ob['last_ok'] : 0;
        $since = $lastOk > 0 ? $lastOk : (int) @filemtime('/etc/cron.d/sentora-offsite-backup');
        if ($since > 0 && time() - $since > 26 * 3600) {
            $now['offsite'] = 'The off-server backup has not succeeded ' . ($lastOk > 0 ? 'since ' . date('Y-m-d H:i', $lastOk) : 'yet')
                . (is_array($ob) && !empty($ob['message']) && isset($ob['result']) && $ob['result'] !== 'ok' ? ': ' . $ob['message'] : '') . ' (Admin > Backup Config: Test connection).';
        }
    }

    // SSL certificates close to expiring: Sencrypt renews Let's Encrypt certificates 30 days
    // ahead, so one with under 14 days left means renewal has been failing for two weeks
    // (DNS no longer pointing here, a blocked challenge, a rate limit). Third-party
    // certificates are renewed by hand, so they get the same warning.
    foreach (SentoraExpiringCertificates(14) as $key => $msg) {
        $now[$key] = $msg;
    }

    // --- compare with what was already reported -------------------------------------------------
    $stateFile = '/var/lib/sentora/alerts.json';
    $state = json_decode((string) @file_get_contents($stateFile), true);
    $state = is_array($state) ? $state : array();
    $t = time();
    $new = array();
    $still = array();
    $resolved = array();
    foreach ($now as $key => $msg) {
        if (!isset($state[$key])) {
            $state[$key] = array('since' => $t, 'sent' => 0, 'msg' => $msg);
            $new[] = $msg;
        } else {
            $state[$key]['msg'] = $msg;
            if ($t - (int) $state[$key]['sent'] >= 86400) {
                $still[] = $msg . ' (since ' . date('Y-m-d H:i', (int) $state[$key]['since']) . ')';
            }
        }
    }
    foreach ($state as $key => $info) {
        if (!isset($now[$key])) {
            $resolved[] = 'Resolved after ' . max(1, (int) round(($t - (int) $info['since']) / 60)) . ' minute(s): ' . $info['msg'];
            unset($state[$key]);
        }
    }
    foreach ($now as $key => $msg) {
        echo "PROBLEM: " . $msg . fs_filehandler::NewLine();
    }
    if (!$now) {
        echo "No problems (disks below " . $limit . "%, core services running, mail queue normal)." . fs_filehandler::NewLine();
    }

    // --- one email for everything new, still open (daily) or resolved --------------------------------
    if ($new || $still || $resolved) {
        $host = (string) ctrl_options::GetSystemOption('sentora_domain');
        $subject = 'Sentora ' . $host . ': ' . ($new ? count($new) . ' new problem(s)' : ($still ? count($still) . ' problem(s) still open' : 'problem(s) resolved'));
        $body = "Sentora server alerts for " . $host . " (" . date('Y-m-d H:i T') . ")\n\n";
        if ($new) {
            $body .= "New problems:\n  - " . implode("\n  - ", $new) . "\n\n";
        }
        if ($still) {
            $body .= "Still open:\n  - " . implode("\n  - ", $still) . "\n\n";
        }
        if ($resolved) {
            $body .= "Resolved:\n  - " . implode("\n  - ", $resolved) . "\n\n";
        }
        $body .= "Server Monitor (Admin > Server Admin) shows the server live; sentora-post-install-check checks the whole installation.\n";
        $sent = false;
        if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $sent = @mail($to, $subject, $body, 'From: Sentora <root@' . $host . '>');
        }
        foreach ($state as $key => $info) {
            if (in_array($info['msg'], $new, true) || strpos(implode("\n", $still), $info['msg']) !== false) {
                $state[$key]['sent'] = $t;
            }
        }
        $log = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/') . '/alerts.log';
        @file_put_contents($log, date('Y-m-d H:i') . '  ' . str_replace("\n", "\n                  ", trim($subject . "\n" . $body))
            . "\n                  " . ($sent ? 'emailed to ' . $to : 'NOT emailed (' . ($to === '' ? 'no alert email address set' : 'mail to ' . $to . ' failed') . ')') . "\n", FILE_APPEND);
        echo ($sent ? "Alert emailed to " . $to : "Alert NOT emailed (set an alert email address in Sentora Config)") . "." . fs_filehandler::NewLine();
    }
    @file_put_contents($stateFile, json_encode($state));
    @chmod($stateFile, 0600);
}

/**
 * SSL certificates of active sites (and the panel) that expire within a number of days.
 *
 * Reads each active SSL vhost's Let's Encrypt (Sencrypt) or third-party certificate from
 * the owner's ssl/sencrypt folder, and the panel's own Let's Encrypt certificate.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @param int $days Warn when fewer than this many days are left.
 * @return array<string, string> Alert key ('cert:<domain>') => message.
 */
function SentoraExpiringCertificates($days)
{
    global $zdbh;
    $out = array();
    $hosted = (string) ctrl_options::GetSystemOption('hosted_dir');
    $sites = array();
    $q = $zdbh->query("SELECT vh_name_vc, ac_user_vc FROM x_vhosts JOIN x_accounts ON ac_id_pk = vh_acc_fk
        WHERE vh_active_in = '1' AND vh_enabled_in = '1' AND vh_deleted_ts IS NULL AND vh_ssl_tx IS NOT NULL AND vh_ssl_tx <> ''");
    foreach (($q ? $q->fetchAll() : array()) as $row) {
        $sites[] = array((string) $row['vh_name_vc'], (string) $row['ac_user_vc']);
    }
    if (ctrl_options::GetSystemOption('panel_ssl_tx') != null) {
        $sites[] = array((string) ctrl_options::GetSystemOption('sentora_domain'), 'zadmin');
    }
    foreach ($sites as $site) {
        list($domain, $user) = $site;
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $domain) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $user)) {
            continue;
        }
        foreach (array('letsencrypt' => 'Let\'s Encrypt renewal has been failing (see modules/sencrypt/sencrypt.log)',
            'third_party' => 'it is a third-party certificate: upload a renewed one in Sencrypt') as $kind => $why) {
            $file = $hosted . $user . '/ssl/sencrypt/' . $kind . '/' . $domain . '/cert.pem';
            $cert = is_file($file) ? @openssl_x509_parse((string) @file_get_contents($file)) : false;
            if (!$cert || empty($cert['validTo_time_t'])) {
                continue;
            }
            $left = (int) floor(((int) $cert['validTo_time_t'] - time()) / 86400);
            if ($left < $days) {
                $out['cert:' . $domain] = 'The SSL certificate for ' . $domain . ' (' . $user . ') '
                    . ($left < 0 ? 'EXPIRED ' . date('Y-m-d', (int) $cert['validTo_time_t']) : 'expires in ' . $left . ' day(s), ' . date('Y-m-d', (int) $cert['validTo_time_t']))
                    . ': ' . $why . '.';
            }
            break;
        }
    }
    return $out;
}
