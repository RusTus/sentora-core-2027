<?php

/**
 * Widget Admin: installs and removes uploaded dashboard widgets (root), queued by the
 * page in /var/lib/sentora/tmp/widget-queue-*. Every package is checked again here
 * (dashboard_widgets::CheckPackage, then a PHP syntax check); its files are written by
 * this hook from their contents, never unpacked to paths taken from the zip.
 *
 * Written by RusTus, 2026-11-05
 */
global $zdbh; // hooks are included inside a function
require_once rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/modules/dashboard/code/widgets.php';
$waRoot = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/' . dashboard_widgets::UPLOADED_DIR;
$waResults = '/var/lib/sentora/widget-results.json';
$waReport = function ($ok, $text, $by) use ($waResults) {
    $r = json_decode((string) @file_get_contents($waResults), true);
    $r = is_array($r) ? $r : array();
    $r[] = array('ok' => $ok, 'text' => $text, 'by' => $by, 'time' => time());
    file_put_contents($waResults, json_encode(array_slice($r, -30)));
    chmod($waResults, 0644);
    echo "Widget Admin: " . ($ok ? '' : 'FAILED: ') . $text . fs_filehandler::NewLine();
};
if (!is_dir($waRoot)) {
    @mkdir($waRoot, 0755, true);
}
foreach ((array) glob('/var/lib/sentora/tmp/widget-queue-*.json') as $waJob) {
    $j = json_decode((string) @file_get_contents($waJob), true);
    $zip = substr($waJob, 0, -5) . '.zip';
    @unlink($waJob);
    if (!is_array($j) || !isset($j['action'], $j['id']) || !preg_match('/^[a-z][a-z0-9_]{2,31}$/', (string) $j['id'])) {
        @unlink($zip);
        continue;
    }
    $by = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($j['by'] ?? ''));
    $id = $j['id'];
    if ($j['action'] === 'remove') {
        $dir = $waRoot . $id;
        if (is_dir($dir) && !is_link($dir) && !isset(dashboard_widgets::BuiltIn()[$id])) {
            foreach ((array) glob($dir . '/*') as $f) { @unlink($f); }
            @rmdir($dir);
            $zdbh->prepare("DELETE FROM x_dashboard_widgets WHERE dwc_id_vc = :i")->execute(array(':i' => $id));
            $waReport(true, 'Removed the widget ' . $id . '.', $by);
        }
        continue;
    }
    if ($j['action'] !== 'install' || !is_file($zip) || is_link($zip)) {
        continue;
    }
    $info = dashboard_widgets::CheckPackage($zip);
    if (is_string($info) || $info['id'] !== $id) {
        $waReport(false, 'Widget ' . $id . ' not installed: ' . (is_string($info) ? $info : 'its id changed.'), $by);
        @unlink($zip);
        continue;
    }
    $z = new ZipArchive();
    $z->open($zip, ZipArchive::RDONLY);
    $files = array();
    foreach (array('widget.xml', 'widget.php', 'widget.css') as $name) {
        $c = $z->getFromName($name);
        if ($c !== false) { $files[$name] = $c; }
    }
    $z->close();
    @unlink($zip);
    // the PHP must at least be valid PHP before it goes anywhere near the panel
    $tmp = tempnam('/var/lib/sentora/tmp', 'widgetlint');
    file_put_contents($tmp, $files['widget.php']);
    exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $rc);
    @unlink($tmp);
    if ($rc !== 0) {
        $waReport(false, 'Widget ' . $id . ' not installed: widget.php has a PHP error (' . substr(preg_replace('/\s+/', ' ', implode(' ', $lint)), 0, 160) . ').', $by);
        continue;
    }
    // written into a new folder, then swapped in (a new version replaces the old one)
    $new = $waRoot . '.' . $id . '.new';
    foreach ((array) glob($new . '/*') as $f) { @unlink($f); }
    @rmdir($new);
    if (!@mkdir($new, 0755)) {
        $waReport(false, 'Widget ' . $id . ' not installed: could not write to ' . $waRoot . '.', $by);
        continue;
    }
    foreach ($files as $name => $c) {
        file_put_contents($new . '/' . $name, $c);
        chmod($new . '/' . $name, 0644);
    }
    $old = $waRoot . $id;
    $existed = is_dir($old);
    if ($existed) {
        foreach ((array) glob($old . '/*') as $f) { @unlink($f); }
        @rmdir($old);
    }
    rename($new, $old);
    $waReport(true, ($existed ? 'Updated' : 'Installed') . ' the widget "' . $info['name'] . '"' . ($info['version'] !== '' ? ' ' . $info['version'] : '') . ' (' . $id . ').', $by);
}
