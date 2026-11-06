<?php

/**
 * GoAccess: builds every report each hour.
 *
 * GoAccess parses text that website visitors control, so it runs as its own user
 * (sentora-stats) that can read nothing else: it never opens the logs. This job
 * (root) reads only the lines added since the last run and passes them to
 * GoAccess, which adds them to the report's stored history (--persist/--restore).
 * A rotated log's last lines are read from <log>.1 before the new log starts.
 */
require_once dirname(__FILE__) . '/../code/goaccess.php';

echo fs_filehandler::NewLine() . "START GoAccess reports." . fs_filehandler::NewLine();
if (ui_module::CheckModuleEnabled('GoAccess') && goaccess_helper::Installed()) {
    GoAccessBuildReports();
} else {
    echo "GoAccess is not installed or the module is disabled." . fs_filehandler::NewLine();
}
echo "END GoAccess reports." . fs_filehandler::NewLine();

/** The new lines of one log since $state (inode, offset), written to $out; returns the new state */
function GoAccessNewLines($log, $state, $out)
{
    $chunks = array();
    clearstatcache(true, $log);
    $inode = @fileinode($log);
    $size = @filesize($log);
    $offset = isset($state['offset']) ? (int) $state['offset'] : 0;
    $oldInode = isset($state['inode']) ? (int) $state['inode'] : 0;
    if ($inode === false || $size === false) {
        return $state;
    }
    if ($oldInode !== 0 && $oldInode !== $inode) {
        // Rotated: finish the old file (now <log>.1) first, then the new one from the top
        $rotated = $log . '.1';
        if (is_file($rotated) && !is_link($rotated) && @fileinode($rotated) === $oldInode) {
            $chunks[] = array($rotated, $offset, filesize($rotated));
        }
        $offset = 0;
    } elseif ($size < $offset) {
        $offset = 0;
    }
    $chunks[] = array($log, $offset, $size);
    $newOffset = $offset;
    $fo = fopen($out, 'ab');
    foreach ($chunks as $i => $c) {
        list($file, $from, $to) = $c;
        if ($to <= $from) {
            if ($file === $log) {
                $newOffset = $from;
            }
            continue;
        }
        $fh = fopen($file, 'rb');
        fseek($fh, $from);
        $data = (string) fread($fh, min($to - $from, 512 * 1024 * 1024));
        fclose($fh);
        // Whole lines only; a line still being written waits for the next run
        $end = strrpos($data, "\n");
        $data = ($end === false) ? '' : substr($data, 0, $end + 1);
        fwrite($fo, $data);
        if ($file === $log) {
            $newOffset = $from + strlen($data);
        }
    }
    fclose($fo);
    return array('inode' => $inode, 'offset' => $newOffset);
}

function GoAccessBuildReports()
{
    $base = goaccess_helper::DIR;
    $user = goaccess_helper::USER;
    if (!ctrl_statsdomains::EnsureStatsUser()) {
        echo "Could not create the " . $user . " user." . fs_filehandler::NewLine();
        return;
    }
    // Reports belong to the GoAccess user; the panel (group) may read them
    @mkdir($base, 0750, true);
    @chown($base, $user);
    @chgrp($base, 'sentora');
    @chmod($base, 0750);
    $stateDir = '/var/lib/sentora/goaccess/';
    @mkdir($stateDir, 0700, true);
    $stateFile = $stateDir . 'offsets.json';
    $states = is_file($stateFile) ? (array) json_decode((string) file_get_contents($stateFile), true) : array();
    $keep = array();
    foreach (goaccess_helper::Reports() as $key => $report) {
        list($title, $logs) = $report;
        $keep[$key] = true;
        $dir = $base . $key . '/';
        if (!is_dir($dir . 'db')) {
            @mkdir($dir . 'db', 0750, true);
        }
        // Owned by the GoAccess user (also reports made by an earlier version as the panel user)
        exec('chown -R ' . escapeshellarg($user . ':sentora') . ' ' . escapeshellarg($dir) . ' && chmod 750 ' . escapeshellarg($dir));
        // The new lines of this report's logs, in one file only GoAccess's user may read
        $feed = tempnam($stateDir, 'feed');
        foreach ($logs as $log) {
            if (!is_file($log) || is_link($log)) {
                continue;
            }
            $sk = $key . '|' . $log;
            if (!isset($states[$sk]) && is_dir($dir . 'db') && count((array) glob($dir . 'db/*'))) {
                // A report the earlier version built from the whole log: it has these lines
                // already, so start from the log's current end instead of counting them twice
                clearstatcache(true, $log);
                $states[$sk] = array('inode' => (int) fileinode($log), 'offset' => (int) filesize($log));
                continue;
            }
            $states[$sk] = GoAccessNewLines($log, isset($states[$sk]) ? $states[$sk] : array(), $feed);
        }
        $haveReport = is_file($dir . 'report.html');
        if (filesize($feed) === 0 && $haveReport) {
            @unlink($feed);
            continue;
        }
        $cmd = ctrl_statsdomains::AsStatsUser(escapeshellarg(goaccess_helper::BIN) . ' - --log-format=COMBINED --no-global-config --no-progress --no-query-string'
            . ' --persist --restore --db-path=' . escapeshellarg($dir . 'db/') . ' --keep-last=366'
            . ' --html-report-title=' . escapeshellarg('GoAccess: ' . $title)
            // The Bright theme by default (GoAccess otherwise picks dark purple when the
            // computer is set to dark mode); the theme can still be changed in the report
            . ' --html-prefs=' . escapeshellarg('{"theme":"bright"}')
            . ' -o ' . escapeshellarg($dir . 'report.new.html')) . ' < ' . escapeshellarg($feed) . ' 2>&1';
        exec($cmd, $out, $rc);
        @unlink($feed);
        if ($rc === 0 && is_file($dir . 'report.new.html')) {
            rename($dir . 'report.new.html', $dir . 'report.html');
            echo "Built GoAccess report: " . $key . fs_filehandler::NewLine();
        } else {
            echo "GoAccess report FAILED for " . $key . ": " . substr(implode(' ', array_slice($out, -3)), 0, 300) . fs_filehandler::NewLine();
        }
        $out = array();
    }
    // Reports (and positions) of domains that are gone
    foreach ((array) glob($base . '*', GLOB_ONLYDIR) as $d) {
        $k = basename($d);
        if (!isset($keep[$k]) && goaccess_helper::ValidKey($k)) {
            exec('rm -rf ' . escapeshellarg($d));
        }
    }
    foreach (array_keys($states) as $sk) {
        if (!isset($keep[strstr($sk, '|', true)])) {
            unset($states[$sk]);
        }
    }
    file_put_contents($stateFile . '.new', json_encode($states));
    chmod($stateFile . '.new', 0600);
    rename($stateFile . '.new', $stateFile);
}

?>
