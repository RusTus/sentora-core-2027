<?php

/**
 * Repo Browser / Module Admin: runs the queued module commands as root.
 *
 * The panel (user "sentora") can only queue a command; the modules folder and
 * the zppy package cache belong to root. Every command is checked again here
 * against the same rules before it runs.
 */
require_once dirname(__FILE__) . '/../code/repohelper.php';

echo fs_filehandler::NewLine() . "START Repo Browser queued commands." . fs_filehandler::NewLine();
RepoBrowserRunQueue();
echo "END Repo Browser queued commands." . fs_filehandler::NewLine();

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

/** a queued command in words, for the daemon log */
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

/** the result line of a command's output (its last meaningful line, not download progress) */
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

function RepoBrowserDuration($seconds)
{
    $seconds = max(0, (float) $seconds);
    if ($seconds < 60) {
        return ($seconds < 10 ? number_format($seconds, 1) : (string) round($seconds)) . 's';
    }
    return floor($seconds / 60) . 'm ' . ((int) $seconds % 60) . 's';
}

/** repositories, available packages and failed jobs still waiting for attention */
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

/** an installed module's files belong to root and can not be changed by the panel or the sites */
function RepoBrowserSecureModule($root, $module)
{
    $dir = $root . 'modules/' . $module;
    if (repo_browser_helper::ValidModule($module) && is_dir($dir)) {
        exec('chown -R root:root ' . escapeshellarg($dir) . ' && chmod -R u+rwX,go-w,go+rX ' . escapeshellarg($dir));
    }
}

/** new modules start out for administrators only */
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

/** a .zpp uploaded in Module Admin */
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
