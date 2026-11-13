<?php

/**
 * Off-server backup (Admin > Backup Config): every daemon run (root)
 *  - makes the settings on servers updated from an earlier version
 *  - keeps /etc/cron.d/sentora-offsite-backup in step with the time and the switch
 *  - runs "Test connection" and "Back up now" from the page, in the background
 *
 * Written by RusTus, 2026-11-13
 */
global $zdbh; // hooks are included inside a function
$obDefaults = array('offsite_enabled' => 'false', 'offsite_server' => '', 'offsite_port' => '22', 'offsite_user' => 'sentora-backup',
    'offsite_password' => '', 'offsite_path' => '/srv/sentora-backups', 'offsite_time' => '02:30', 'offsite_keep' => '14',
    'offsite_mail' => 'true', 'offsite_request' => '', 'offsite_hostkey' => '', 'offsite_pubkey' => '');
$obHave = $zdbh->query("SELECT so_name_vc, so_value_tx FROM x_settings WHERE so_name_vc LIKE 'offsite\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($obDefaults as $obName => $obValue) {
    if (!array_key_exists($obName, $obHave)) {
        $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
            VALUES (:n, :n2, :v, NULL, 'Off-server backup (set in Admin > Backup Config)', 'Backup Config', 'false')")
            ->execute(array(':n' => $obName, ':n2' => $obName, ':v' => $obValue));
        $obHave[$obName] = $obValue;
    }
}
// the settings belong to Backup Config (a test build had them under Sentora Config)
$zdbh->exec("UPDATE x_settings SET so_module_vc = 'Backup Config', so_desc_tx = 'Off-server backup (set in Admin > Backup Config)' WHERE so_name_vc LIKE 'offsite\\_%' AND so_module_vc <> 'Backup Config'");
// this panel's backup key (root's SSH key for the backup machine): made when missing, its public half
// kept in the settings for the page (the command that installs a Sentora backup node carries it)
$obKey = '/root/.ssh/sentora_offsite';
if (!is_file($obKey) && function_exists('posix_getuid') && posix_getuid() === 0) {
    @mkdir('/root/.ssh', 0700, true);
    $obHost = trim((string) @php_uname('n'));
    exec('ssh-keygen -q -t ed25519 -N "" -C ' . escapeshellarg('sentora-offsite@' . preg_replace('/[^A-Za-z0-9._-]/', '', $obHost)) . ' -f ' . escapeshellarg($obKey) . ' 2>&1', $obOut, $obRc);
    echo "Off-server backup: this panel's backup key " . ($obRc === 0 ? 'made' : 'could not be made') . fs_filehandler::NewLine();
}
$obPub = is_file($obKey . '.pub') ? trim((string) @file_get_contents($obKey . '.pub')) : '';
if ($obPub !== '' && preg_match('/^ssh-ed25519 [A-Za-z0-9+\/]{40,100}={0,3}( [A-Za-z0-9@._-]{1,100})?$/', $obPub) && $obPub !== (string) $obHave['offsite_pubkey']) {
    $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = 'offsite_pubkey'")->execute(array(':v' => $obPub));
}
$obBin = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/bin/sentora-offsite-backup';
$obCron = '/etc/cron.d/sentora-offsite-backup';
if (is_file($obBin) && is_dir('/etc/cron.d')) {
    $obWant = '';
    if ($obHave['offsite_enabled'] === 'true' && preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $obHave['offsite_time'], $obT)) {
        $obWant = "# Sentora: off-server backup (Admin > Backup Config)\n" . (int) $obT[2] . ' ' . (int) $obT[1] . ' * * * root ' . $obBin . " --cron > /dev/null 2>&1\n";
    }
    $obNow = is_file($obCron) ? (string) @file_get_contents($obCron) : '';
    if ($obWant === '' && $obNow !== '') {
        @unlink($obCron);
        echo "Off-server backup: switched off, nightly job removed" . fs_filehandler::NewLine();
    } elseif ($obWant !== '' && $obNow !== $obWant) {
        @file_put_contents($obCron, $obWant);
        @chmod($obCron, 0644);
        echo "Off-server backup: every day at " . $obHave['offsite_time'] . fs_filehandler::NewLine();
    }
    // a request from the page: run it in the background (a copy can take a while)
    if ($obHave['offsite_request'] !== '') {
        $obWhat = strtok($obHave['offsite_request'], ' ');
        $zdbh->exec("UPDATE x_settings SET so_value_tx = '' WHERE so_name_vc = 'offsite_request'");
        $obArg = ($obWhat === 'check') ? '--check' : '--now';
        exec('setsid nohup ' . escapeshellarg($obBin) . ' ' . $obArg . ' > /dev/null 2>&1 < /dev/null &');
        echo "Off-server backup: " . ($obWhat === 'check' ? 'connection test' : 'backup') . " started (from the panel)" . fs_filehandler::NewLine();
    }
}

// Restore (Admin > Backup Config > Restore): a request from the page, checked again here
// and run in the background by sentora-panel-restore (root)
$rsReq = '/var/lib/sentora/tmp/panel-restore-request.json';
if (is_file($rsReq) && !is_link($rsReq)) {
    $r = json_decode((string) file_get_contents($rsReq), true);
    @unlink($rsReq);
    $rsBin = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/bin/sentora-panel-restore';
    $ok = is_array($r) && is_file($rsBin);
    $args = array();
    if ($ok && $r['action'] === 'list') {
        $args = array('--list');
    } elseif ($ok && in_array($r['action'], array('account', 'full'), true)
        && preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', (string) ($r['server'] ?? ''))
        && preg_match('/^20\d{2}-\d{2}-\d{2}$/', (string) ($r['date'] ?? ''))) {
        if ($r['action'] === 'full') {
            $args = array('--full', $r['server'], $r['date'], '--yes');
        } elseif (preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) ($r['username'] ?? ''))) {
            $parts = array_values(array_intersect((array) ($r['parts'] ?? array()), array('files', 'databases', 'mail', 'exact')));
            $args = array_merge(array('--account', $r['server'], $r['date'], $r['username']), $parts);
        }
    }
    if ($args) {
        exec('setsid nohup ' . escapeshellarg($rsBin) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' > /dev/null 2>&1 < /dev/null &');
        echo "Restore: started " . implode(' ', $args) . " (asked by " . preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($r['by'] ?? '')) . ")" . fs_filehandler::NewLine();
    } else {
        echo "Restore: a request that was not valid was ignored" . fs_filehandler::NewLine();
    }
}
