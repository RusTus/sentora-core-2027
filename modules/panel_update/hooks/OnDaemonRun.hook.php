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
