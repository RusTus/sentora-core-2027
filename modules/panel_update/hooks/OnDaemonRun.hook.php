<?php
/**
 * Panel Update, every daemon run (root): keep zsudo's queue working.
 *
 * zsudo leaves the panel's root actions (Panel Update, Run the daemon now, BIND start/stop, FTP kick)
 * in a queue that root's sentora-zsudo service runs (bin/sentora-zsudo-setup). A server updated to
 * it by an older sentora-update got the new zsudo built the old way (set-uid) and no queue or
 * service, so those actions did not run. Here, when zsudo is still set-uid, its queue is missing or
 * its watcher does not run, the setup is run again (at most once an hour while it keeps failing).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-12
 */
$zsNl = fs_filehandler::NewLine();
$zsRoot = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/');
$zsSetup = $zsRoot . '/bin/sentora-zsudo-setup';
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $zsRoot !== '' && is_file($zsSetup) && is_file($zsRoot . '/bin/zsudo.c')) {
    clearstatcache();
    $zsBin = $zsRoot . '/bin/zsudo';
    $zsSetuid = is_file($zsBin) && (fileperms($zsBin) & 04000);
    $zsQueue = sys_statedir::DIR . '/zsudo/queue';
    $zsO = array(); exec('systemctl is-active --quiet sentora-zsudo.path 2>/dev/null', $zsO, $zsActive);
    if ($zsSetuid || !is_dir($zsQueue) || $zsActive !== 0) {
        $zsStamp = sys_statedir::DIR . '/zsudo-setup-tried';
        if (!is_file($zsStamp) || time() - (int) @filemtime($zsStamp) > 3600) {
            @touch($zsStamp);
            $zsOut = array(); exec('/bin/bash ' . escapeshellarg($zsSetup) . ' ' . escapeshellarg(sys_accountphp::PANEL_USER) . ' 2>&1', $zsOut, $zsRc);
            echo 'Panel Update: zsudo\'s queue ' . ($zsRc === 0 ? 'set up (the panel\'s root actions run through it now)' : 'could NOT be set up: ' . implode(' ', array_slice($zsOut, -2))) . $zsNl;
            if ($zsRc === 0) {
                @unlink($zsStamp);
            }
        }
    }
}

/*
 * Once a day (and on the first run after an update): settings of this Linux system that came after
 * this server was installed get the value this system's installer sets today, when they are still
 * at the default (bin/sentora-platform-fill, with platform-settings.json from the installers'
 * release). sentora-update does it too, but an update runs the OLD sentora-update, so the first
 * update to this release relies on this.
 */
$pfRoot = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/');
$pfTool = $pfRoot . '/bin/sentora-platform-fill';
$pfStamp = sys_statedir::DIR . '/platform-fill-checked';
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $pfRoot !== '' && is_file($pfTool)
        && (!is_file($pfStamp) || time() - (int) @filemtime($pfStamp) > 86400 || (int) @filemtime($pfStamp) < (int) @filemtime($pfTool))) {
    @touch($pfStamp);
    $pfJson = sys_phpbundles::Fetch('https://github.com/RusTus/sentora-installers-2027/releases/download/current/platform-settings.json');
    if ($pfJson !== false && is_array(json_decode($pfJson, true))) {
        $pfFile = sys_statedir::DIR . '/platform-settings.json';
        @unlink($pfFile);
        if (@file_put_contents($pfFile, $pfJson, LOCK_EX) !== false) {
            @chmod($pfFile, 0600);
            $pfOut = array(); exec(escapeshellarg(PHP_BINARY !== '' ? PHP_BINARY : 'php') . ' -q ' . escapeshellarg($pfTool) . ' ' . escapeshellarg($pfFile) . ' 2>&1', $pfOut, $pfRc);
            echo 'Panel Update: ' . ($pfRc === 0 ? (string) end($pfOut) : 'this system\'s settings were not checked: ' . implode(' ', array_slice($pfOut, -1))) . $zsNl;
        }
    }
}

/*
 * The panel's command-line tools: runnable and in root's PATH (/usr/local/sbin). sentora-update
 * does this for the tools it knows, but an update runs the OLD sentora-update, which does not know
 * the tools that came with the new release.
 */
$tlRoot = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/');
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $tlRoot !== '' && is_dir('/usr/local/sbin')) {
    foreach (array('sentora-firewall', 'sentora-apparmor') as $tlName) {
        $tlFile = $tlRoot . '/bin/' . $tlName;
        if (!is_file($tlFile) || is_link($tlFile)) {
            continue;
        }
        if ((fileperms($tlFile) & 0777) !== 0755) {
            @chmod($tlFile, 0755);
        }
        $tlLink = '/usr/local/sbin/' . $tlName;
        if (!is_link($tlLink) && !file_exists($tlLink)) {
            @symlink($tlFile, $tlLink);
        }
    }
}


// The daemon's cron line opens its report for adding (">>", after emptying it), not for overwriting
// (">"): the programs the daemon starts inherit it, and SELinux lets Apache's helpers only add to log
// files, so ">" made it refuse them and log each refusal. Servers installed before get the new line
// here (installers write it since 2026-11-12); a line changed by hand is left alone.
$zdCron = '/etc/cron.d/zdaemon';
if (is_file($zdCron) && !is_link($zdCron) && fileowner($zdCron) === 0) {
    $zdText = (string) file_get_contents($zdCron);
    $zdNew = preg_replace('#^(\S+(?:\s+\S+){5}\s+)(nice -2 /usr/bin/php -q /etc/sentora/panel/bin/daemon\.php) > (/var/sentora/logs/daemon-last-run\.log) 2>&1$#m', '$1: > $3; $2 >> $3 2>&1', $zdText);
    if (is_string($zdNew) && $zdNew !== $zdText && @file_put_contents($zdCron . '.sentora-new', $zdNew) !== false) {
        @chmod($zdCron . '.sentora-new', 0644);
        if (@rename($zdCron . '.sentora-new', $zdCron)) {
            echo "Daemon cron line: its report is now opened for adding (SELinux)." . fs_filehandler::NewLine();
        }
    }
}

/*
 * "Keep the panel up to date" (Admin > Sentora Config > Updates; off unless the administrator turns it
 * on). The settings are added here on servers that do not have them yet. When it is on and a newer
 * release is published (the hourly check: sentora-update --status), the release is installed at the
 * chosen hour once it has been out for a day (counted from when this server first saw it), by
 * bin/sentora-autoupdate through zsudo's queue: it backs up, updates, checks the panel and goes back
 * on a problem, and tells the administrators. A release it had to undo is not tried again by itself.
 * Not while an update runs, nor with under 1 GB free where the panel lives.
 */
global $zdbh;
foreach (array(
    array('panel_autoupdate', 'Keep the panel up to date', 'false', 'true|false', 'Install new panel releases by themselves, every night at the chosen hour.'),
    array('panel_autoupdate_hour', 'Update hour', '3', '0|1|2|3|4|5|6|7|8|9|10|11|12|13|14|15|16|17|18|19|20|21|22|23', 'The hour (server time) automatic updates are installed.'),
    array('addons_autoupdate', 'Keep add-on modules up to date', 'false', 'true|false', 'Install new versions of add-on modules by themselves, at the same hour.'),
) as $auRow) {
    $auHas = $zdbh->prepare("SELECT COUNT(*) FROM x_settings WHERE so_name_vc = :n");
    $auHas->execute(array(':n' => $auRow[0]));
    if ((int) $auHas->fetchColumn() === 0) {
        $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
            VALUES (:n, :c, :v, :d, :s, 'Sentora Config', 'true')")
            ->execute(array(':n' => $auRow[0], ':c' => $auRow[1], ':v' => $auRow[2], ':d' => $auRow[3], ':s' => $auRow[4]));
    }
}
$auState = sys_statedir::DIR . '/panel-autoupdate.json';
$auS = json_decode((string) @file_get_contents($auState), true);
$auS = is_array($auS) ? $auS : array();
$auSave = function () use (&$auS, $auState) {
    @file_put_contents($auState . '.new', json_encode($auS));
    @chmod($auState . '.new', 0600);
    @rename($auState . '.new', $auState);
};
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && strtolower((string) ctrl_options::GetSystemOption('panel_autoupdate')) === 'true') {
    $auHour = (int) ctrl_options::GetSystemOption('panel_autoupdate_hour');
    $auAvail = trim((string) @file_get_contents('/var/lib/sentora-update/available'));
    $auSum = trim((string) @file_get_contents('/var/lib/sentora-update/available-sum'));
    $auLast = explode(' ', trim((string) @file_get_contents('/var/lib/sentora-update/autoupdate-last')));
    // the result of the last automatic update, once: a release it had to undo is not tried again
    if (isset($auLast[1], $auS['tried'], $auS['tried_at']) && (int) $auLast[0] >= (int) $auS['tried_at'] && in_array($auLast[1], array('rolledback', 'failed'), true)) {
        $auS['skip'] = $auS['tried'];
    }
    if (preg_match('/^\d+ available /', $auAvail) && preg_match('/^[0-9a-f]{64}$/', $auSum)) {
        if (!isset($auS['sum']) || $auS['sum'] !== $auSum) {
            $auS['sum'] = $auSum;
            $auS['seen'] = time();
        }
        $auDue = max((int) $auS['seen'] + 86400, 0);
        // the first chosen hour after it has been out for a day
        $auAt = mktime($auHour, 0, 0, (int) date('n', $auDue), (int) date('j', $auDue), (int) date('Y', $auDue));
        if ($auAt < $auDue) {
            $auAt += 86400;
        }
        $auS['at'] = $auAt;
        $auRun = trim((string) @file_get_contents('/var/lib/sentora-update/status'));
        $auRunning = $auRun !== '' && strpos($auRun, "\ndone\t") === false && strpos($auRun, "\nfail\t") === false && time() - (int) @filemtime('/var/lib/sentora-update/status') < 1200;
        $auFree = @disk_free_space(rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/'));
        if (isset($auS['skip']) && $auS['skip'] === $auSum) {
            // (undone once: waits for the administrator or the next release)
        } elseif (time() >= $auAt && (int) date('G') === $auHour && (!isset($auS['tried']) || $auS['tried'] !== $auSum || time() - (int) $auS['tried_at'] > 86400)
                && !$auRunning && ($auFree === false || $auFree > 1073741824)) {
            $auZ = (string) ctrl_options::GetSystemOption('zsudo');
            $auO = array();
            exec(ctrl_system::OwnFiles(escapeshellcmd($auZ) . ' panel-autoupdate 2>&1'), $auO, $auRc);
            if ($auRc === 0) {
                $auS['tried'] = $auSum;
                $auS['tried_at'] = time();
                echo 'Panel Update: Keep the panel up to date: installing the new release now (bin/sentora-autoupdate).' . $zsNl;
                ctrl_notices::Send(array('scope' => 'groups', 'targets' => array(1), 'type' => 'info', 'title' => 'The panel is updating itself',
                    'body' => '**Keep the panel up to date** is installing the newest Sentora release now (the panel is backed up first). Another notice says how it went, in a few minutes.',
                    'closable' => true, 'key' => 'panel-autoupdate', 'source' => 'sentora-autoupdate'));
            } else {
                echo 'Panel Update: Keep the panel up to date: zsudo did not take the update (' . $auRc . ': ' . implode(' ', $auO) . ').' . $zsNl;
            }
        }
    }
    $auSave();
} elseif ($auS) {
    // switched off: what it planned is forgotten (nothing else changes)
    @unlink($auState);
}
