<?php

/**
 * Cron Manager (root): servers installed before the daemon wrote the account jobs
 * kept them in the web server user's crontab, installed by zsudo, and the crontab
 * folder was handed to the web server user (it then owned every user's crontab,
 * root's too). Put back Ubuntu's own permissions and remove that crontab. The jobs
 * are now /etc/cron.d/sentora-accounts, one user per account (sys_accountphp::SyncCron).
 *
 * Written by RusTus, 2026-11-05
 */
$crDir = '/var/spool/cron/crontabs';
if (is_dir($crDir) && !is_link($crDir) && function_exists('posix_getgrnam') && posix_getgrnam('crontab') !== false) {
    $crFixed = 0;
    $st = stat($crDir);
    $crontabGid = posix_getgrnam('crontab')['gid'];
    if ($st['uid'] !== 0 || $st['gid'] !== $crontabGid || ($st['mode'] & 07777) !== 01730) {
        chown($crDir, 'root');
        chgrp($crDir, 'crontab');
        chmod($crDir, 01730);
        $crFixed++;
    }
    foreach ((array) glob($crDir . '/*') as $f) {
        if (is_link($f) || !is_file($f)) {
            continue;
        }
        $owner = basename($f);
        $pw = posix_getpwnam($owner);
        if ($pw === false) {
            continue;
        }
        $fs = stat($f);
        if ($fs['uid'] !== $pw['uid'] || $fs['gid'] !== $crontabGid || ($fs['mode'] & 07777) !== 0600) {
            chown($f, $owner);
            chgrp($f, 'crontab');
            chmod($f, 0600);
            $crFixed++;
        }
    }
    // the web server user's crontab held only Sentora's jobs: remove it
    $wd = $crDir . '/www-data';
    if (is_file($wd) && !is_link($wd) && preg_match('/SENTORA|Sentora|^SHELL=\/bin\/bash/m', (string) file_get_contents($wd))) {
        exec('crontab -r -u www-data 2>&1');
        echo "Cron: removed the web server user's old crontab (the jobs run from /etc/cron.d/sentora-accounts)" . fs_filehandler::NewLine();
    }
    // the file the panel used to write it from: root's now, and empty
    $old = '/var/lib/sentora/crontab-www-data';
    if (is_file($old) && !is_link($old) && (fileowner($old) !== 0 || filesize($old) > 80)) {
        file_put_contents($old, "# Not used: account jobs are in /etc/cron.d/sentora-accounts\n");
        chown($old, 'root');
        chgrp($old, 'root');
        chmod($old, 0644);
        $crFixed++;
    }
    if ($crFixed) {
        echo "Cron: crontab permissions put back to Ubuntu's own ($crFixed change(s))" . fs_filehandler::NewLine();
    }
}
