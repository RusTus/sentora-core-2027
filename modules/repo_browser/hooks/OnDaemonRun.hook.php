<?php

/**
 * Repo Browser / Module Admin: runs the queued module commands as root.
 *
 * The panel (user "sentora") can only queue a command; the modules folder and
 * the zppy package cache belong to root. Every command is checked again here
 * against the same rules before it runs.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
require_once dirname(__FILE__) . '/../code/repohelper.php';

echo fs_filehandler::NewLine() . "START Repo Browser queued commands." . fs_filehandler::NewLine();
RepoBrowserAutoUpdate();
RepoBrowserRunQueue();
echo "END Repo Browser queued commands." . fs_filehandler::NewLine();

/**
 * "Keep add-on modules up to date" (Admin > Sentora Config > Updates; off unless turned on): at the
 * hour chosen there (panel_autoupdate_hour), queue the upgrade of every add-on module with a newer
 * version noted by the hourly check (x_modules.mo_updatever_vc) once that version has been seen for a
 * day; this run's queue then installs them. When they have all run, the administrators get a notice
 * saying which were updated and which failed (key addons-autoupdate).
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function RepoBrowserAutoUpdate()
{
    global $zdbh;
    $file = sys_statedir::DIR . '/addons-autoupdate.json';
    if (strtolower((string) ctrl_options::GetSystemOption('addons_autoupdate')) !== 'true') {
        @unlink($file);
        return;
    }
    $st = json_decode((string) @file_get_contents($file), true);
    $st = is_array($st) ? $st : array();
    $st += array('seen' => array(), 'day' => '', 'batch' => array(), 'batch_at' => 0);
    repo_browser_helper::QueueEnsure();
    // 1. the last batch: once every upgrade in it has run, say how it went
    if ($st['batch']) {
        $done = array();
        $failed = array();
        $waiting = 0;
        $q = $zdbh->prepare("SELECT zq_status_vc FROM x_zppy_queue WHERE zq_args_tx = :a AND zq_created_in >= :t ORDER BY zq_id_pk DESC LIMIT 1");
        foreach ($st['batch'] as $module => $ver) {
            $q->execute(array(':a' => json_encode(array('upgrade', (string) $module)), ':t' => (int) $st['batch_at']));
            $status = (string) $q->fetchColumn();
            if ($status === 'queued' || $status === 'running') {
                $waiting++;
            } elseif ($status === 'done') {
                $done[] = $module . ' (' . $ver . ')';
            } else {
                $failed[] = $module;
            }
        }
        if ($waiting === 0) {
            $body = '**Keep add-on modules up to date** ran on ' . date('j M Y \a\t H:i', (int) $st['batch_at']) . '.'
                . ($done ? "\n- Updated: " . implode(', ', $done) : '')
                . ($failed ? "\n- **Not updated:** " . implode(', ', $failed) . ' (see Admin > Repo Browser > Recent ZPPY commands)' : '');
            ctrl_notices::Send(array('scope' => 'groups', 'targets' => array(1), 'type' => $failed ? 'warning' : 'info',
                'title' => $failed ? 'Add-on modules: some updates failed' : 'Add-on modules were updated', 'body' => $body,
                'closable' => true, 'key' => 'addons-autoupdate', 'source' => 'repo_browser'));
            echo 'Keep add-on modules up to date: ' . count($done) . ' updated, ' . count($failed) . ' failed.' . fs_filehandler::NewLine();
            $st['batch'] = array();
        }
    }
    // 2. newer versions, and since when each has been seen
    $now = time();
    $newer = array();
    foreach ($zdbh->query("SELECT mo_folder_vc, mo_updatever_vc FROM x_modules WHERE mo_updatever_vc IS NOT NULL AND mo_updatever_vc <> ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = (string) $r['mo_folder_vc'];
        $v = (string) $r['mo_updatever_vc'];
        if (repo_browser_helper::IsCoreModule($m) || !repo_browser_helper::ValidModule($m)) {
            continue;
        }
        if (!isset($st['seen'][$m]) || $st['seen'][$m][0] !== $v) {
            $st['seen'][$m] = array($v, $now);
        }
        $newer[$m] = $v;
    }
    $st['seen'] = array_intersect_key($st['seen'], $newer);
    // 3. at the chosen hour, once a day: the ones out for a day are queued (as Update all does)
    $today = date('Y-m-d');
    if ((int) date('G') === (int) ctrl_options::GetSystemOption('panel_autoupdate_hour') && $st['day'] !== $today && !$st['batch']) {
        $st['day'] = $today;
        $add = $zdbh->prepare("INSERT INTO x_zppy_queue (zq_acc_fk, zq_args_tx, zq_created_in) VALUES (1, :c, :t)");
        foreach ($newer as $m => $v) {
            if ($now - (int) $st['seen'][$m][1] < 86400 || repo_browser_helper::Pending($m) !== false) {
                continue;
            }
            $add->execute(array(':c' => json_encode(array('upgrade', $m)), ':t' => $now));
            $st['batch'][$m] = $v;
        }
        if ($st['batch']) {
            $add->execute(array(':c' => json_encode(array('update')), ':t' => $now));
            $st['batch_at'] = $now;
            echo 'Keep add-on modules up to date: queued ' . implode(', ', array_keys($st['batch'])) . '.' . fs_filehandler::NewLine();
        }
    }
    @file_put_contents($file . '.new', json_encode($st));
    @chmod($file . '.new', 0600);
    @rename($file . '.new', $file);
}

/**
 * Run every queued zppy command as root, one at a time, and store each result in x_zppy_queue.
 *
 * Each job is claimed first (status 'running') so two daemon runs never run the same
 * one; installed modules are made root's and given to the administrators.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function RepoBrowserRunQueue()
{
    global $zdbh;
    repo_browser_helper::QueueEnsure();
    $sql = $zdbh->prepare("SELECT * FROM x_zppy_queue WHERE zq_status_vc = 'queued' ORDER BY zq_id_pk ASC");
    $sql->execute();
    $rows = $sql->fetchAll();
    if (count($rows) == 0) {
        echo "Nothing queued." . fs_filehandler::NewLine();
        RepoBrowserSummary();
        return;
    }
    echo count($rows) . " command(s) queued." . fs_filehandler::NewLine();
    $root = ctrl_options::GetSystemOption('sentora_root');
    $tally = array('done' => 0, 'failed' => 0);
    foreach ($rows as $row) {
        // Claim it, so two daemon runs never run the same command
        $claim = $zdbh->prepare("UPDATE x_zppy_queue SET zq_status_vc = 'running' WHERE zq_id_pk = :id AND zq_status_vc = 'queued'");
        $claim->execute(array(':id' => $row['zq_id_pk']));
        if ($claim->rowCount() !== 1) {
            continue;
        }
        $args = json_decode((string) $row['zq_args_tx'], true);
        $started = microtime(true);
        $who = ctrl_users::GetUserDetail((int) $row['zq_acc_fk']);
        echo "Running: " . RepoBrowserDescribe($args) . " (queued by " . ($who ? $who['username'] : 'account ' . (int) $row['zq_acc_fk'])
            . ", waited " . RepoBrowserDuration(time() - (int) $row['zq_created_in']) . ")" . fs_filehandler::NewLine();
        $status = 'failed';
        if (!is_array($args) || !repo_browser_helper::ValidZPPYArguments(array_values($args))) {
            $output = 'Refused: not an allowed zppy command.';
        } elseif ($args[0] === 'install-upload') {
            list($ok, $output) = RepoBrowserInstallUpload($root, $args[1]);
            $status = $ok ? 'done' : 'failed';
        } else {
            $cmd = 'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($root . 'bin/zppy') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
            $out = array();
            exec($cmd, $out, $rc);
            $output = implode("\n", $out);
            $status = ($rc === 0) ? 'done' : 'failed';
            if (in_array($args[0], array('install', 'upgrade'), true)) {
                RepoBrowserSecureModule($root, $args[1]);
            }
            // an upgrade: the administrators' "module update available" notice follows at once
            // (and after the repositories' lists were read again: a module may have a newer version)
            if (in_array($args[0], array('upgrade', 'update'), true) && $status === 'done') {
                echo "  " . repo_browser_helper::UpdateCheck() . fs_filehandler::NewLine();
            }
        }
        if (isset($args[0], $args[1]) && in_array($args[0], array('install', 'install-upload'), true)) {
            RepoBrowserGiveToAdmins($args[1]);
        }
        $done = $zdbh->prepare("UPDATE x_zppy_queue SET zq_status_vc = :s, zq_output_tx = :o, zq_done_in = :t WHERE zq_id_pk = :id");
        $output = substr((string) $output, 0, 65536);
        $now = time();
        $done->bindParam(':s', $status);
        $done->bindParam(':o', $output);
        $done->bindParam(':t', $now);
        $done->bindParam(':id', $row['zq_id_pk']);
        $done->execute();
        $tally[$status === 'done' ? 'done' : 'failed']++;
        echo "  " . ($status === 'done' ? 'Done' : 'FAILED') . " in " . RepoBrowserDuration(microtime(true) - $started)
            . ": " . RepoBrowserLastLine($output) . fs_filehandler::NewLine();
    }
    echo "Ran " . ($tally['done'] + $tally['failed']) . " command(s): " . $tally['done'] . " done, " . $tally['failed'] . " failed"
        . ($tally['failed'] ? " (the full output is under Recent ZPPY commands in Repo Browser)" : "") . "." . fs_filehandler::NewLine();
    RepoBrowserSummary();
}

/**
 * A queued command in words, for the daemon log.
 *
 * @param mixed $args The decoded zppy arguments (anything but an array is "unreadable").
 * @return string
 */
function RepoBrowserDescribe($args)
{
    if (!is_array($args) || !isset($args[0])) {
        return 'an unreadable command';
    }
    $a = array_values($args);
    switch ($a[0]) {
        case 'install-upload': return 'install of uploaded package ' . ($a[1] ?? '?') . '.zpp';
        case 'install': return 'install of module ' . ($a[1] ?? '?') . ' from a repository';
        case 'remove': return 'removal of module ' . ($a[1] ?? '?');
        case 'upgrade': return 'upgrade of module ' . ($a[1] ?? '?');
        case 'update': return 'refresh of the package lists from every repository';
        case 'repo': return (($a[1] ?? '') === 'add' ? 'adding repository ' : 'removing repository ') . ($a[2] ?? '?');
    }
    return 'zppy ' . implode(' ', $a);
}

/**
 * The result line of a command's output (its last meaningful line, not download progress).
 *
 * Adds the "Failed to contact" lines when there are any; at most 300 characters.
 *
 * @param string $output The command's output.
 * @return string
 */
function RepoBrowserLastLine($output)
{
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $output)), function ($l) {
        return $l !== '' && !preg_match('/^\d+K [. ]+|^--\d{4}-|^Saving to:|^HTTP request sent|^Connecting to|^Resolving|^Length:|^Warning:|^Getting list from|^Remember to execute/', $l);
    }));
    if (!count($lines)) {
        return 'no output';
    }
    // a failed repository contact says more than the final "up-to-date" line
    $failed = array_values(array_filter($lines, function ($l) { return stripos($l, 'Failed to contact') !== false; }));
    $last = $lines[count($lines) - 1];
    if (count($failed)) {
        $last .= ' (' . count($failed) . ' repository list(s) could not be fetched: ' . implode('; ', array_slice($failed, 0, 3)) . ')';
    }
    return strlen($last) > 300 ? substr($last, 0, 300) . '...' : $last;
}

/**
 * A duration for the daemon log, such as "4.2s", "35s" or "2m 5s".
 *
 * @param int|float $seconds The duration in seconds.
 * @return string
 */
function RepoBrowserDuration($seconds)
{
    $seconds = max(0, (float) $seconds);
    if ($seconds < 60) {
        return ($seconds < 10 ? number_format($seconds, 1) : (string) round($seconds)) . 's';
    }
    return floor($seconds / 60) . 'm ' . ((int) $seconds % 60) . 's';
}

/**
 * Print the repositories, available packages and failed jobs still waiting for attention.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function RepoBrowserSummary()
{
    global $zdbh;
    $repos = repo_browser_helper::Repos();
    $list = ctrl_options::GetSystemOption('sentora_root') . 'etc/zppy-cache/package.list';
    $packages = 0;
    foreach ((array) @file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $packages += (strpos($line, "\t") !== false) ? 1 : 0;
    }
    $failed = (int) $zdbh->query("SELECT COUNT(*) FROM x_zppy_queue WHERE zq_status_vc = 'failed' AND zq_done_in > UNIX_TIMESTAMP() - 7 * 86400")->fetchColumn();
    echo count($repos) . " repositor" . (count($repos) == 1 ? 'y' : 'ies') . ", " . $packages . " package(s) available"
        . ($failed ? "; " . $failed . " command(s) failed in the last 7 days (see Repo Browser)" : '') . "." . fs_filehandler::NewLine();
}

/**
 * An installed module's files belong to root and can not be changed by the panel or the sites.
 *
 * @param string $root The panel's root folder (sentora_root, with a trailing slash).
 * @param string $module The module folder name.
 * @return void
 */
function RepoBrowserSecureModule($root, $module)
{
    $dir = $root . 'modules/' . $module;
    if (repo_browser_helper::ValidModule($module) && is_dir($dir)) {
        exec('chown -R root:root ' . escapeshellarg($dir) . ' && chmod -R u+rwX,go-w,go+rX ' . escapeshellarg($dir));
    }
}

/**
 * New modules start out for administrators only (group 1 gets the permission if it has none).
 *
 * @global db_driver $zdbh The panel's database connection.
 * @param string $module The module folder name.
 * @return void
 */
function RepoBrowserGiveToAdmins($module)
{
    global $zdbh;
    $sql = $zdbh->prepare("SELECT mo_id_pk FROM x_modules WHERE mo_folder_vc = :f");
    $sql->execute(array(':f' => $module));
    $mod = $sql->fetch();
    if (!$mod) {
        return;
    }
    $has = $zdbh->prepare("SELECT COUNT(*) FROM x_permissions WHERE pe_group_fk = 1 AND pe_module_fk = :m");
    $has->execute(array(':m' => $mod['mo_id_pk']));
    if ((int) $has->fetchColumn() === 0) {
        ctrl_groups::AddGroupModulePermissions("1", $mod['mo_id_pk']);
    }
}

/**
 * Install a .zpp uploaded in Module Admin: unzip it (refusing paths outside the module folder), register it and run its deploy/install.run.
 *
 * @param string $root The panel's root folder (sentora_root, with a trailing slash).
 * @param string $module The module folder name.
 * @return array{0: bool, 1: string} array(success, output message).
 */
function RepoBrowserInstallUpload($root, $module)
{
    $zpp = repo_browser_helper::UPLOAD_DIR . $module . '.zpp';
    $dir = $root . 'modules/' . $module;
    if (!is_file($zpp)) {
        return array(false, 'The uploaded package is no longer there.');
    }
    if (file_exists($dir)) {
        @unlink($zpp);
        return array(false, 'A module with that folder name is already installed.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zpp) !== true) {
        @unlink($zpp);
        return array(false, 'Not a valid .zpp (zip) package.');
    }
    // No entry may point outside the module folder
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || strpos($name, '..') !== false || $name[0] === '/' || strpos($name, "\0") !== false) {
            $zip->close();
            @unlink($zpp);
            return array(false, 'The package contains a path outside the module folder: refused.');
        }
    }
    @mkdir($dir, 0755);
    $zip->extractTo($dir . '/');
    $zip->close();
    @unlink($zpp);
    if (!is_file($dir . '/module.xml')) {
        exec('rm -rf ' . escapeshellarg($dir));
        return array(false, 'No module.xml file found in the package.');
    }
    RepoBrowserSecureModule($root, $module);
    ui_module::ModuleInfoToDB($module);
    $out = array();
    if (is_file($dir . '/deploy/install.run')) {
        exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(ctrl_options::GetSystemOption('php_exer')) . ' ' . escapeshellarg($dir . '/deploy/install.run') . ' 2>&1', $out);
    }
    return array(true, "Installed " . $module . "." . (count($out) ? "\n" . implode("\n", $out) : ''));
}

?>
