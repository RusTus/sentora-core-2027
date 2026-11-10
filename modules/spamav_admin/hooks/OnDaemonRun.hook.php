<?php

/**
 * SpamAV (root, every daemon run): applies Admin > SpamAV Admin and Mail > SpamAV to
 * SpamAssassin, ClamAV, Postfix and Dovecot, and reads the mail log into the activity
 * that users see. See dryden/sys/spamav.class.php for how mail flows.
 *
 * Written by RusTus, 2026-11-10
 */
global $zdbh; // hooks are included inside a function
if (!is_file(sys_platform::Get('spamd_bin')) && !is_file(sys_platform::Get('clamd_bin'))) {
    return; // not installed on this server
}
sys_spamav::Ensure();
// Mail > SpamAV is for every group (sentora-update registers new modules for administrators only)
if (ctrl_options::GetSystemOption('spamav_groups_done') === false) {
    $svMod = (int) $zdbh->query("SELECT mo_id_pk FROM x_modules WHERE mo_folder_vc = 'spamav'")->fetchColumn();
    if ($svMod > 0) {
        foreach ($zdbh->query("SELECT ug_id_pk FROM x_groups")->fetchAll(PDO::FETCH_COLUMN) as $svG) {
            if (!ctrl_groups::CheckGroupModulePermissions((int) $svG, $svMod)) {
                $zdbh->prepare("INSERT INTO x_permissions (pe_group_fk, pe_module_fk) VALUES (:g, :m)")->execute(array(':g' => (int) $svG, ':m' => $svMod));
            }
        }
        $zdbh->exec("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
            VALUES ('spamav_groups_done', 'spamav_groups_done', '1', NULL, 'SpamAV given to every group', 'Sentora Config', 'false')");
    }
}
$sv = function ($cmd) { exec($cmd . ' 2>&1', $o, $rc); return array($rc, implode("\n", $o)); };
$svWrite = function ($file, $content, $mode = 0644, $owner = null) {
    if (is_file($file) && @file_get_contents($file) === $content) {
        return false;
    }
    @mkdir(dirname($file), 0755, true);
    $tmp = $file . '.sentora-new';
    file_put_contents($tmp, $content);
    chmod($tmp, $mode);
    if ($owner !== null) {
        @chown($tmp, explode(':', $owner)[0]);
        @chgrp($tmp, explode(':', $owner)[1]);
    }
    rename($tmp, $file);
    return true;
};
$svLog = function ($text) { echo "SpamAV: " . $text . fs_filehandler::NewLine(); };
$clamOn = sys_spamav::On('clam_enabled') && is_file(sys_platform::Get('clamd_bin'));
$saOn = sys_spamav::On('sa_enabled') && is_file(sys_platform::Get('spamd_bin'));

// --- 1. spamd's per-mailbox settings database (read-only user for spamd) -------------------
$svSecret = '/etc/sentora/spamav-db.secret';
if (!is_file($svSecret)) {
    $svWrite($svSecret, bin2hex(random_bytes(18)) . "\n", 0600, 'root:root');
}
$svPass = trim((string) file_get_contents($svSecret));
try {
    $zdbh->exec("CREATE DATABASE IF NOT EXISTS `" . sys_spamav::SA_DB . "` DEFAULT CHARACTER SET utf8mb4");
    $zdbh->exec("CREATE TABLE IF NOT EXISTS `" . sys_spamav::SA_DB . "`.userpref (prefid INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) NOT NULL, preference VARCHAR(50) NOT NULL, value VARCHAR(255) NOT NULL, KEY username (username))");
    $zdbh->exec("CREATE USER IF NOT EXISTS 'sentora_spamav'@'localhost' IDENTIFIED BY " . $zdbh->quote($svPass));
    $zdbh->exec("ALTER USER 'sentora_spamav'@'localhost' IDENTIFIED BY " . $zdbh->quote($svPass));
    $zdbh->exec("GRANT SELECT ON `" . sys_spamav::SA_DB . "`.* TO 'sentora_spamav'@'localhost'");
} catch (Exception $e) {
    $svLog('could not prepare the settings database: ' . $e->getMessage());
}

// --- 2. every mailbox: its spamd settings and its Sieve script ------------------------------
$svBoxes = $zdbh->query("SELECT LOWER(mb_address_vc) FROM x_mailboxes WHERE mb_deleted_ts IS NULL")->fetchAll(PDO::FETCH_COLUMN);
$svRows = array();
foreach ($svBoxes as $box) {
    foreach (sys_spamav::UserPrefRows($box) as $r) {
        $svRows[] = array($box, $r[0], $r[1]);
    }
}
$svHash = sha1(json_encode($svRows));
$svHashFile = '/var/lib/sentora/spamav-userpref.sha1';
if (@file_get_contents($svHashFile) !== $svHash) {
    try {
        $zdbh->beginTransaction();
        $zdbh->exec("DELETE FROM `" . sys_spamav::SA_DB . "`.userpref");
        $ins = $zdbh->prepare("INSERT INTO `" . sys_spamav::SA_DB . "`.userpref (username, preference, value) VALUES (:u, :p, :v)");
        foreach ($svRows as $r) {
            $ins->execute(array(':u' => $r[0], ':p' => $r[1], ':v' => $r[2]));
        }
        $zdbh->commit();
        file_put_contents($svHashFile, $svHash);
        $svLog(count($svRows) . ' mailbox setting(s) given to SpamAssassin');
    } catch (Exception $e) {
        $zdbh->rollBack();
        $svLog('could not write the mailbox settings: ' . $e->getMessage());
    }
}
$svDir = sys_spamav::SIEVE_DIR;
if (!is_dir($svDir)) {
    mkdir($svDir, 0750, true);
}
@chown($svDir, 'vmail');
@chgrp($svDir, 'mail');
$svKeep = array();
$svSieveChanged = 0;
foreach ($svBoxes as $box) {
    if (!preg_match('/^[a-z0-9._+-]+@[a-z0-9.-]+$/', $box)) {
        continue;
    }
    $f = $svDir . $box . '.sieve';
    $svKeep[basename($f)] = true;
    $svKeep[$box . '.svbin'] = true;
    if ($svWrite($f, sys_spamav::SieveScript($box), 0640, 'vmail:mail') || !is_file($svDir . $box . '.svbin')) {
        $sv('sievec ' . escapeshellarg($f));
        @chown($svDir . $box . '.svbin', 'vmail');
        @chgrp($svDir . $box . '.svbin', 'mail');
        $svSieveChanged++;
    }
}
foreach ((array) glob($svDir . '*') as $f) {
    if (!isset($svKeep[basename($f)]) && is_file($f) && !is_link($f)) {
        @unlink($f); // mailboxes that were deleted
    }
}
if ($svSieveChanged) {
    $svLog($svSieveChanged . ' mailbox spam filter(s) written');
}

// --- 3. Dovecot: the per-mailbox script runs before the user's own filters -------------------
// (the setting's wording is the Dovecot version's: sys_platform dovecot_spamav_block)
$svDove = realpath(sys_platform::Get('dovecot_conf'));
$svDoveMark = "# Spam handling chosen in Mail > SpamAV";
if ($svDove && is_file($svDove) && strpos((string) file_get_contents($svDove), $svDoveMark) === false && strpos((string) file_get_contents($svDove), 'sieve_script spamav') === false) {
    $block = "\n" . $svDoveMark . " (one script per mailbox, run before the user's own)\n"
        . sys_platform::Get('dovecot_spamav_block', array('dir' => $svDir)) . "\n";
    file_put_contents($svDove, $block, FILE_APPEND);
    list($rc, $out) = $sv('doveconf -n > /dev/null');
    if ($rc === 0) {
        $sv('systemctl reload ' . escapeshellarg(sys_platform::Get('dovecot_service')));
        $svLog('Dovecot now runs the mailbox spam filters');
    } else {
        file_put_contents($svDove, str_replace($block, '', (string) file_get_contents($svDove)));
        $svLog('Dovecot did not accept the spam filter setting, left as it was: ' . substr($out, 0, 200));
    }
}

// --- 4. the configuration files; restart what changed -----------------------------------------
// (files, services and users of this Linux system: Sentora's settings, sys_platform)
$svP = function ($name) { return sys_platform::Get($name); };
$svClamd = $svP('clamd_service');
$svClamMilter = $svP('clamav_milter_service');
$svFresh = $svP('freshclam_service');
$svSpamd = $svP('spamd_service');
$svSpamMilter = $svP('spamass_milter_service');
$svRestart = array();
if (is_file(sys_platform::Get('clamd_bin'))) {
    // the mail connection's socket folder: its user's, Postfix's group may reach the socket
    $d = dirname($svP('clamav_milter_socket'));
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
    }
    @chown($d, $svP('clamav_milter_user'));
    @chgrp($d, 'postfix');
    $d = $svP('clamav_log_dir');
    @mkdir($d, 0775, true);
    @chown($d, $svP('clamav_user'));
    // the signature updater writes its own log there, as its own user (made in the folder, so it
    // gets the folder's labels where the system has them)
    if (!is_file($d . '/freshclam.log')) {
        @touch($d . '/freshclam.log');
    }
    @chown($d . '/freshclam.log', $svP('freshclam_user'));
    if ($svWrite($svP('clamd_conf'), sys_spamav::ClamdConf())) { $svRestart[$svClamd] = true; }
    if ($svWrite($svP('clamav_milter_conf'), sys_spamav::MilterConf())) { $svRestart[$svClamMilter] = true; }
    if ($svWrite($svP('freshclam_conf'), sys_spamav::FreshclamConf())) { $svRestart[$svFresh] = true; }
    $u = escapeshellarg($svP('clamav_milter_user'));
    $sv('id -nG ' . $u . ' | grep -qw postfix || usermod -aG postfix ' . $u);
}
if (is_file(sys_platform::Get('spamd_bin'))) {
    $svSaUser = $svP('spamd_user');
    @mkdir('/var/lib/spamassassin/bayes', 0770, true);
    @chown('/var/lib/spamassassin/bayes', $svSaUser);
    @chgrp('/var/lib/spamassassin/bayes', $svSaUser);
    if ($svWrite(rtrim($svP('spamassassin_dir'), '/') . '/60_sentora.cf', sys_spamav::SaConf($svPass), 0640, 'root:' . $svSaUser)) { $svRestart[$svSpamd] = true; }
    if ($svWrite($svP('spamd_defaults_file'), sys_spamav::SpamdDefaults())) { $svRestart[$svSpamd] = true; }
    if ($svWrite($svP('spamass_milter_defaults_file'), sys_spamav::MilterDefaults())) { $svRestart[$svSpamMilter] = true; }
    $u = escapeshellarg($svP('spamass_milter_user'));
    $sv('id -nG ' . $u . ' | grep -qw postfix || usermod -aG postfix ' . $u);
    $svTimer = $svP('spamd_maintenance_timer');
    if ($svTimer !== '') {
        list($rc) = $sv('systemctl is-enabled ' . escapeshellarg($svTimer));
        if (sys_spamav::On('sa_rules_update') !== ($rc === 0)) {
            $sv('systemctl ' . (sys_spamav::On('sa_rules_update') ? 'enable --now' : 'disable --now') . ' ' . escapeshellarg($svTimer));
        }
    }
}
// scanners on or off as set; ClamAV waits for its first signatures
$svJustStarted = array();
$svHaveSigs = (bool) glob(sys_platform::Get('clamav_db_dir') . '/{daily,main}.{cvd,cld}', GLOB_BRACE);
$svWant = array($svFresh => $clamOn, $svClamd => $clamOn && $svHaveSigs, $svClamMilter => $clamOn && $svHaveSigs, $svSpamd => $saOn, $svSpamMilter => $saOn);
$svIsClam = array($svFresh => true, $svClamd => true, $svClamMilter => true);
foreach ($svWant as $unit => $want) {
    if ((isset($svIsClam[$unit]) && !is_file(sys_platform::Get('clamd_bin'))) || (!isset($svIsClam[$unit]) && !is_file(sys_platform::Get('spamd_bin')))) {
        continue;
    }
    list($rc) = $sv('systemctl is-active --quiet ' . escapeshellarg($unit));
    $active = ($rc === 0);
    if ($unit === $svClamMilter && $want && !$active) {
        // the milter gives up when ClamAV does not answer, and ClamAV takes a while to load
        // its signatures after it starts: wait (up to 90 seconds) until it answers PING
        for ($i = 0; $i < 45; $i++) {
            $sock = @stream_socket_client('unix://' . $svP('clamd_socket'), $en, $es, 2);
            if ($sock) {
                fwrite($sock, "zPING\0");
                stream_set_timeout($sock, 2);
                $pong = (string) fread($sock, 16);
                fclose($sock);
                if (strpos($pong, 'PONG') === 0) {
                    break;
                }
            }
            sleep(2);
        }
    }
    if ($want && !$active) {
        $sv('systemctl enable --now ' . escapeshellarg($unit));
        $svJustStarted[] = $unit;
        $svLog('started ' . $unit);
    } elseif (!$want && $active) {
        $sv('systemctl disable --now ' . escapeshellarg($unit));
        $svLog('stopped ' . $unit);
    } elseif ($want && isset($svRestart[$unit])) {
        $sv('systemctl restart ' . escapeshellarg($unit));
        $svJustStarted[] = $unit;
        $svLog('restarted ' . $unit . ' (settings changed)');
    }
}

// --- 5. Postfix: the milters of the scanners that are on ----------------------------------------
$svMilters = array();
if ($saOn) { $svMilters[] = 'unix:' . sys_platform::Get('spamass_milter_socket'); }
if ($clamOn && $svHaveSigs) { $svMilters[] = 'unix:' . sys_platform::Get('clamav_milter_socket'); }
$svPostfix = array('smtpd_milters' => implode(', ', $svMilters), 'milter_default_action' => 'accept', 'milter_protocol' => '6',
    'milter_mail_macros' => 'i {auth_type} {auth_authen} {auth_author} {mail_addr} {mail_host} {mail_mailer}');
$svPfChanged = false;
foreach ($svPostfix as $k => $v) {
    list(, $cur) = $sv('postconf -h ' . escapeshellarg($k));
    if (trim($cur) !== $v) {
        $sv('postconf -e ' . escapeshellarg($k . ' = ' . $v));
        $svPfChanged = true;
    }
}
if ($svPfChanged) {
    $sv('systemctl reload postfix');
    $svLog('Postfix scans with: ' . ($svMilters ? implode(', ', array_map('basename', $svMilters)) : 'nothing (both scanners are off)'));
}

// --- 6. what the administrator asked for (Update signatures, Update rules, Restart) -------------
$svReq = @json_decode((string) @file_get_contents(sys_spamav::REQUEST), true);
@unlink(sys_spamav::REQUEST);
$svStatus = @json_decode((string) @file_get_contents(sys_spamav::STATUS), true);
$svStatus = is_array($svStatus) ? $svStatus : array();
if (is_array($svReq) && isset($svReq['action'])) {
    $by = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($svReq['by'] ?? ''));
    // updates can take minutes: in the background, the result is read on a later run
    if ($svReq['action'] === 'signatures' && $clamOn) {
        $sv('setsid nohup sh -c ' . escapeshellarg('systemctl stop ' . escapeshellarg($svFresh) . '; timeout 900 freshclam --quiet; r=$?; systemctl start ' . escapeshellarg($svFresh) . '; systemctl reload ' . escapeshellarg($svClamd) . ' 2>/dev/null; echo $r > /var/lib/sentora/spamav-request.rc') . ' > /var/lib/sentora/spamav-request.log 2>&1 < /dev/null &');
        $svStatus['last_request'] = array('time' => time(), 'by' => $by, 'what' => 'Update virus signatures', 'ok' => null, 'text' => 'Running.');
    } elseif ($svReq['action'] === 'rules' && $saOn) {
        $sv('setsid nohup sh -c ' . escapeshellarg('timeout 900 sa-update; r=$?; [ $r -le 1 ] && systemctl reload ' . escapeshellarg($svSpamd) . '; [ $r -le 1 ] && r=0; echo $r > /var/lib/sentora/spamav-request.rc') . ' > /var/lib/sentora/spamav-request.log 2>&1 < /dev/null &');
        $svStatus['last_request'] = array('time' => time(), 'by' => $by, 'what' => 'Update spam rules', 'ok' => null, 'text' => 'Running.');
    } elseif ($svReq['action'] === 'restart') {
        foreach ($svWant as $unit => $want) {
            if ($want) { $sv('systemctl restart ' . escapeshellarg($unit)); }
        }
        $svStatus['last_request'] = array('time' => time(), 'by' => $by, 'what' => 'Restart the scanners', 'ok' => true, 'text' => 'Done.');
    }
    $svLog('ran "' . $svReq['action'] . '" for ' . $by);
}

// a background update that has finished
if (isset($svStatus['last_request']) && $svStatus['last_request']['ok'] === null && is_file('/var/lib/sentora/spamav-request.rc')) {
    $rc = (int) trim((string) file_get_contents('/var/lib/sentora/spamav-request.rc'));
    $svStatus['last_request']['ok'] = ($rc === 0);
    $svStatus['last_request']['text'] = ($rc === 0) ? 'Done.' : 'Failed: ' . substr(trim((string) @file_get_contents('/var/lib/sentora/spamav-request.log')), -300);
    @unlink('/var/lib/sentora/spamav-request.rc');
}
// --- 7. status for the admin page --------------------------------------------------------------
// services started a moment ago (spamd, the milters) take a few seconds: wait for them
for ($i = 0; $i < 15 && $svJustStarted; $i++) {
    foreach ($svJustStarted as $k => $unit) {
        list($rc) = $sv('systemctl is-active --quiet ' . escapeshellarg($unit));
        if ($rc === 0) {
            unset($svJustStarted[$k]);
        }
    }
    if ($svJustStarted) {
        sleep(1);
    }
}
foreach ($svJustStarted as $unit) {
    // ClamAV's milter needs ClamAV answering, which takes minutes while it loads its first
    // virus signatures (right after an install): the next daemon runs start it then
    $clamReady = false;
    if ($unit === $svClamMilter && ($sock = @stream_socket_client('unix://' . $svP('clamd_socket'), $en, $es, 2))) {
        fwrite($sock, "zPING\0");
        stream_set_timeout($sock, 2);
        $clamReady = (strpos((string) fread($sock, 16), 'PONG') === 0);
        fclose($sock);
    }
    if ($unit === $svClamMilter && !$clamReady) {
        $svLog('clamav-milter waits for ClamAV to load its virus signatures; it is started on a later daemon run');
        continue;
    }
    // a slow first start (right after an install): one more try before it counts as a problem
    $sv('systemctl restart ' . escapeshellarg($unit));
    for ($i = 0; $i < 20; $i++) {
        list($rc) = $sv('systemctl is-active --quiet ' . escapeshellarg($unit));
        if ($rc === 0) {
            break;
        }
        sleep(1);
    }
    if ($rc === 0) {
        $svLog($unit . ' started on the second try');
    } else {
        $svLog($unit . ' did not start (systemctl status ' . $unit . ')');
    }
}
foreach (array_keys($svWant) as $unit) {
    list($rc) = $sv('systemctl is-active --quiet ' . escapeshellarg($unit));
    $svStatus['units'][$unit] = ($rc === 0);
}
$svStatus['have_sigs'] = $svHaveSigs;
foreach (array('daily', 'main', 'bytecode') as $db) {
    $f = glob(sys_platform::Get('clamav_db_dir') . '/' . $db . '.c[vl]d');
    if ($f) {
        list(, $out) = $sv('sigtool --info ' . escapeshellarg($f[0]));
        $svStatus['sigs'][$db] = array('version' => preg_match('/Version: (\d+)/', $out, $m) ? $m[1] : '?',
            'built' => preg_match('/Build time: (.+)/', $out, $m) ? trim($m[1]) : '', 'signatures' => preg_match('/Signatures: (\d+)/', $out, $m) ? (int) $m[1] : 0);
    }
}
if (empty($svStatus['versions_checked']) || time() - (int) $svStatus['versions_checked'] > 86400) {
    list(, $o1) = $sv('clamd --version');
    list(, $o2) = $sv('spamassassin --version');
    $svStatus['versions'] = array('clamav' => preg_match('/ClamAV ([\d.]+)/', $o1, $m) ? $m[1] : '', 'spamassassin' => preg_match('/version ([\d.]+)/', $o2, $m) ? $m[1] : '');
    $svStatus['versions_checked'] = time();
}
$svRules = glob('/var/lib/spamassassin/*/updates_spamassassin_org.cf');
$svStatus['rules_updated'] = $svRules ? filemtime($svRules[0]) : 0;
$svStatus['milters'] = $svMilters;
$svStatus['memory_mb'] = sys_spamav::MemoryMB();
$svStatus['time'] = time();

// --- 8. the mail log: activity for each account --------------------------------------------------
$svCursorFile = '/var/lib/sentora/spamav-journal.cursor';
$svStateFile = '/var/lib/sentora/spamav-parse.json';
$svCursor = trim((string) @file_get_contents($svCursorFile));
$cmd = 'journalctl -q -o json --no-pager ' . ($svCursor !== '' ? '--after-cursor=' . escapeshellarg($svCursor) : '--since=-15min')
    . ' -n 50000 SYSLOG_IDENTIFIER=spamd SYSLOG_IDENTIFIER=clamav-milter + _SYSTEMD_UNIT=postfix@-.service + _SYSTEMD_UNIT=postfix.service 2>/dev/null';
$svLines = array();
$svLast = '';
$h = popen($cmd, 'r');
while ($h && ($line = fgets($h)) !== false) {
    $j = json_decode($line, true);
    if (!is_array($j) || !isset($j['MESSAGE']) || !is_string($j['MESSAGE'])) {
        continue;
    }
    $svLast = (string) $j['__CURSOR'];
    $msg = $j['MESSAGE'];
    $prog = (string) ($j['SYSLOG_IDENTIFIER'] ?? '');
    // only the lines the parser uses
    if ($prog === 'spamd' ? strpos($msg, 'result: ') === 0 : ($prog === 'clamav-milter' ? strpos($msg, 'infected by') !== false
        : (strpos($msg, 'message-id=') !== false || strpos($msg, ': from=<') !== false || strpos($msg, 'milter-reject') !== false))) {
        $svLines[] = array((int) floor(((int) ($j['__REALTIME_TIMESTAMP'] ?? 0)) / 1000000), $prog, $msg);
    }
}
if ($h) {
    pclose($h);
}
if ($svLast !== '') {
    file_put_contents($svCursorFile, $svLast);
}
$svState = @json_decode((string) @file_get_contents($svStateFile), true);
$svState = is_array($svState) ? $svState : array();
list($svEvents, $svScanned, $svUpdates) = sys_spamav::ParseLog($svLines, $svState);
file_put_contents($svStateFile, json_encode($svState));
sys_spamav::Store($svEvents, $svScanned, $svUpdates);
if ($svEvents || $svUpdates) {
    $svLog(count($svEvents) . ' new spam / virus event(s) from the mail log');
}
// activity older than the administrator's limit
$svDays = max(1, (int) sys_spamav::Get('spamav_keep_days'));
$zdbh->prepare("DELETE FROM x_spamav_events WHERE ev_ts < :t")->execute(array(':t' => time() - $svDays * 86400));
$zdbh->prepare("DELETE FROM x_spamav_daily WHERE dd_day < :d")->execute(array(':d' => date('Y-m-d', time() - $svDays * 86400)));
$svStatus['parsed'] = time();
file_put_contents(sys_spamav::STATUS, json_encode($svStatus));
chmod(sys_spamav::STATUS, 0644);
