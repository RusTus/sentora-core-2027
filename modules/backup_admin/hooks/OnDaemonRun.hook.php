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
    'offsite_mail' => 'true', 'offsite_request' => '', 'offsite_hostkey' => '', 'offsite_pubkey' => '',
    'offsite_nodekey' => '', 'offsite_pairing' => '', 'offsite_seenkey' => '', 'offsite_target' => '');
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
    $obSet = function ($n, $v) use ($zdbh, &$obHave) {
        $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = :n")->execute(array(':v' => (string) $v, ':n' => $n));
        $obHave[$n] = (string) $v;
    };
    // "Add the backup node" (2027.86, the node framework: dryden/sys/nodes.class.php): the key the node
    // answers with must be the one its installer showed; only then is it kept and used
    // (whenever one was added and not checked yet: a Test connection pressed meanwhile does not lose it)
    if ($obHave['offsite_nodekey'] !== '' && class_exists('sys_nodes')) {
        if (strtok($obHave['offsite_request'], ' ') === 'pair') {
            $obSet('offsite_request', '');
        }
        $obN = explode(' ', $obHave['offsite_nodekey']);
        $obSet('offsite_nodekey', '');
        if (count($obN) === 3) {
            $obLine = sys_nodes::ScanSshKey($obN[0], (int) $obN[1]);
            if ($obLine === '') {
                $obSet('offsite_pairing', 'fail ' . time() . ' ' . $obN[0] . ' did not answer on SSH port ' . (int) $obN[1] . ': is the node on, and may this panel reach it?');
            } elseif (sys_nodes::SshKeyCode($obLine) !== $obN[2]) {
                $obSet('offsite_pairing', 'fail ' . time() . ' ' . $obN[0] . ' answered with another key than the one added: check the address and the key (sentora-backup-node key on the node shows it). Nothing was changed.');
            } else {
                foreach (array('offsite_server' => $obN[0], 'offsite_port' => (string) (int) $obN[1], 'offsite_user' => 'sentora-backup', 'offsite_password' => '', 'offsite_path' => '/',
                    'offsite_hostkey' => sys_nodes::SshFingerprint($obLine), 'offsite_seenkey' => '', 'offsite_enabled' => 'true', 'offsite_target' => 'node') as $obK => $obV) {
                    $obSet($obK, $obV);
                }
                // the key just checked is the one every copy expects: kept as the known key at once, so
                // the connection test does not scan the node again (a node refuses for a while an
                // address that opens many connections without logging in)
                @mkdir('/root/.ssh', 0700, true);
                @file_put_contents('/root/.ssh/sentora_offsite_known_hosts', trim($obLine) . "\n");
                @chmod('/root/.ssh/sentora_offsite_known_hosts', 0600);
                $obSet('offsite_pairing', 'ok ' . time() . ' ' . $obN[0] . ' (' . $obHave['offsite_hostkey'] . '); the copies go there every night at ' . $obHave['offsite_time']);
                $obSet('offsite_request', 'check ' . time());
            }
            echo "Off-server backup: backup node " . $obN[0] . ': ' . strtok($obHave['offsite_pairing'], ' ') . fs_filehandler::NewLine();
        }
    }
    // a remote backup machine is only used with its key confirmed (2027.86): one that worked before
    // keeps the key root already trusted for it; for a new one, the key it answers with is shown on
    // the page to compare and trust (a Sentora backup node gets its key when it is added)
    if ($obHave['offsite_server'] !== '' && $obHave['offsite_hostkey'] === '' && class_exists('sys_nodes')) {
        $obEntry = ($obHave['offsite_port'] === '22' || $obHave['offsite_port'] === '') ? $obHave['offsite_server'] : '[' . $obHave['offsite_server'] . ']:' . (int) $obHave['offsite_port'];
        $obKnown = array();
        if (is_file('/root/.ssh/known_hosts')) {
            exec('ssh-keygen -F ' . escapeshellarg($obEntry) . ' -f /root/.ssh/known_hosts 2> /dev/null', $obKnown);
        }
        $obFp = '';
        foreach ($obKnown as $obL) {
            if ($obL !== '' && $obL[0] !== '#' && strpos($obL, 'ssh-ed25519') !== false) {
                $obFp = sys_nodes::SshFingerprint($obL);
            }
        }
        if ($obFp !== '') {
            $obSet('offsite_hostkey', $obFp);
            echo "Off-server backup: kept the key root already trusted for " . $obHave['offsite_server'] . " (" . $obFp . ")" . fs_filehandler::NewLine();
        } else {
            $obSeen = sys_nodes::SshFingerprint(sys_nodes::ScanSshKey($obHave['offsite_server'], (int) $obHave['offsite_port']));
            if ($obSeen !== '' && $obHave['offsite_seenkey'] !== $obHave['offsite_server'] . ' ' . $obSeen) {
                $obSet('offsite_seenkey', $obHave['offsite_server'] . ' ' . $obSeen);
            }
        }
    }
    // a Sentora backup node's status (Backup Config's card): a connection test every 30 minutes, which
    // also says how full its disk is
    if ($obHave['offsite_request'] === '' && $obHave['offsite_target'] === 'node' && $obHave['offsite_server'] !== '' && $obHave['offsite_hostkey'] !== '') {
        $obSt = json_decode((string) @file_get_contents('/var/lib/sentora/offsite-backup.json'), true);
        if (!is_array($obSt) || (int) ($obSt['check_time'] ?? 0) < time() - 1800) {
            $obSet('offsite_request', 'check ' . time());
        }
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
