<?php

/**
 * Matomo: every hour, imports the new lines of each domain's access log (for
 * domains that use the logs, not the tracking code) and builds the reports.
 *
 * Written by RusTus, 2026-11-12
 */
require_once dirname(__FILE__) . '/../code/matomohelper.php';

echo fs_filehandler::NewLine() . "START Matomo log import." . fs_filehandler::NewLine();
if (ui_module::CheckModuleEnabled('Matomo') && matomo_helper::Installed()) {
    MatomoImportLogs();
} else {
    echo "Matomo is not installed or the module is disabled." . fs_filehandler::NewLine();
}
echo "END Matomo log import." . fs_filehandler::NewLine();

/**
 * Import the new access log lines of each domain that uses log import, then build the reports.
 *
 * Keeps each log's offset and inode in x_matomo_sites so only new complete lines are sent.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function MatomoImportLogs()
{
    global $zdbh;
    matomo_helper::Ensure();
    $token = matomo_helper::ImporterToken();
    if (!preg_match('/^[0-9a-f]{32,64}$/', $token)) {
        echo "No importer token yet (the 5-minute sync makes one)." . fs_filehandler::NewLine();
        return;
    }
    $dir = matomo_helper::SPEC_DIR;
    @mkdir($dir, 0750, true);
    // The panel user (which runs Matomo) reads the files the daemon leaves here
    @chgrp(matomo_helper::SPEC_DIR, 'sentora');
    @chmod(matomo_helper::SPEC_DIR, 0750);
    // The token goes to the importer in a file only the panel user can read, never on
    // a command line (where every user on the server could see it with ps)
    $tokFile = $dir . 'importer.token';
    file_put_contents($tokFile, $token);
    chown($tokFile, 'sentora');
    chmod($tokFile, 0600);
    $owner = ctrl_statsdomains::OwnerName();
    $panel = ctrl_statsdomains::PanelDomain();
    $sql = $zdbh->prepare("SELECT s.*, a.ac_user_vc FROM x_matomo_sites s JOIN x_accounts a ON a.ac_id_pk = s.ms_acc_fk
        WHERE s.ms_deleted_in = 0 AND s.ms_source_vc = 'log' AND s.ms_idsite_in > 0");
    $sql->execute();
    $imported = 0;
    foreach ($sql->fetchAll() as $s) {
        $domain = $s['ms_domain_vc'];
        $log = ($domain === $panel) ? ctrl_statsdomains::PanelLog()
            : rtrim(ctrl_options::GetSystemOption('log_dir'), '/') . '/domains/' . $s['ac_user_vc'] . '/' . $domain . '-access.log';
        if (!is_file($log)) {
            MatomoReport($domain . ": no access log yet (" . $log . ").");
            continue;
        }
        clearstatcache(true, $log);
        $size = filesize($log);
        $inode = fileinode($log);
        $offset = (int) $s['ms_offset_bi'];
        // A rotated or truncated log starts again from the beginning
        if ((int) $s['ms_inode_bi'] !== $inode || $size < $offset) {
            $offset = 0;
        }
        if ($size <= $offset) {
            MatomoReport($domain . ": no new log lines since the last import.");
            continue;
        }
        // New complete lines only (an unfinished last line waits for the next run)
        $fh = fopen($log, 'rb');
        fseek($fh, $offset);
        $chunk = (string) fread($fh, min($size - $offset, 256 * 1024 * 1024));
        fclose($fh);
        $end = strrpos($chunk, "\n");
        if ($end === false) {
            continue;
        }
        $chunk = substr($chunk, 0, $end + 1);
        $part = $dir . 'import-' . (int) $s['ms_idsite_in'] . '.log';
        file_put_contents($part, $chunk);
        chown($part, 'sentora');
        chmod($part, 0600);
        // The importer sends the visits in batches of 50 (its own 200 made a request of
        // over 128 KB, refused with 413 "Request Entity Too Large" on the owner's panel).
        // The importer sends the lines to Matomo on this server: the panel's name is
        // pointed at 127.0.0.1 inside the importer, so it works whether or not the
        // server itself can resolve that name in DNS
        $py = "import sys,runpy,socket\n"
            . "_h=" . var_export((string) ctrl_options::GetSystemOption('sentora_domain'), true) . "\n"
            . "_g=socket.getaddrinfo\n"
            . "socket.getaddrinfo=lambda h,*a,**k: _g('127.0.0.1' if h==_h else h,*a,**k)\n"
            . "t=open(" . var_export($tokFile, true) . ").read().strip()\n"
            . "sys.argv=['import_logs.py','--url=" . matomo_helper::Url() . "','--token-auth='+t,'--idsite=" . (int) $s['ms_idsite_in'] . "',"
            . "'--recorders=2','--recorder-max-payload-size=50','--enable-http-errors','--enable-http-redirects','--accept-invalid-ssl-certificate'," . var_export($part, true) . "]\n"
            . "runpy.run_path(" . var_export(matomo_helper::APP_DIR . 'misc/log-analytics/import_logs.py', true) . ", run_name='__main__')\n";
        exec('setpriv --reuid=sentora --regid=sentora --init-groups --reset-env /usr/bin/python3 -c ' . escapeshellarg($py) . ' 2>&1', $out, $rc);
        @unlink($part);
        if ($rc === 0) {
            $zdbh->prepare("UPDATE x_matomo_sites SET ms_offset_bi = :o, ms_inode_bi = :i WHERE ms_domain_vc = :d")
                ->execute(array(':o' => $offset + strlen($chunk), ':i' => $inode, ':d' => $domain));
            $imported++;
            // Matomo's own summary: what became visits and what it left out (bots,
            // static files such as images and CSS, and so on)
            $text = implode("\n", $out);
            $ok = preg_match('/(\d+) requests imported successfully/', $text, $m1) ? (int) $m1[1] : null;
            $ign = preg_match('/(\d+) requests ignored/', $text, $m2) ? (int) $m2[1] : null;
            MatomoReport($domain . ": read " . substr_count($chunk, "\n") . " new log line(s)"
                . ($ok !== null ? ", " . $ok . " recorded in Matomo" : "")
                . ($ign !== null ? ", " . $ign . " left out (bots, static files and the like)" : "") . ".");
        } else {
            // The cause: Matomo's importer says "Fatal error: why" and then only how to
            // restart it; a Python error ends with its own line "SomeError: why"
            $why = array_values(array_filter(array_map('trim', $out), 'strlen'));
            $cause = (string) end($why);
            foreach ($why as $i => $l) {
                if (strpos($l, 'Fatal error:') === 0) {
                    $cause = $l;
                    for ($j = $i + 1; $j < count($why) && strpos($why[$j], 'You can restart') !== 0; $j++) {
                        $cause .= ' ' . $why[$j];
                    }
                    break;
                }
            }
            MatomoReport($domain . ": import FAILED (the lines are tried again next hour): " . substr(preg_replace('/token_auth=[0-9a-f]+/i', 'token_auth=...', $cause), 0, 400)
                . " (the importer's whole answer: " . MatomoKeepError($domain, $out) . ")");
        }
        $out = array();
    }
    @unlink($tokFile);
    // Domains set to "Use tracking code": their visits come only from the JavaScript
    // snippet on their pages, never from the logs
    foreach ($zdbh->query("SELECT ms_domain_vc FROM x_matomo_sites WHERE ms_deleted_in = 0 AND ms_source_vc <> 'log' ORDER BY ms_domain_vc")->fetchAll(PDO::FETCH_COLUMN) as $d) {
        MatomoReport($d . ": uses the tracking code (no log import): visits appear only if the Matomo snippet is on its pages.");
    }
    // Build the reports (also for domains using the tracking code)
    list($rc, $o) = matomo_helper::Console(array('core:archive', '--url=' . matomo_helper::Url(), '--accept-invalid-ssl-certificate'));
    MatomoReport("reports " . ($rc === 0 ? 'built' : 'FAILED: ' . substr($o, -300)) . ".");
}

/**
 * Keep the importer's whole answer of a failed import in
 * /var/sentora/logs/matomo-import-error.log (only the newest one, root and the
 * panel's group may read it), so the cause can be read in full.
 *
 * @param string $domain The domain whose import failed.
 * @param array $out The importer's output lines.
 * @return string The file's path.
 */
function MatomoKeepError($domain, $out)
{
    $file = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/') . '/matomo-import-error.log';
    $text = date('Y-m-d H:i') . '  ' . $domain . "\n" . implode("\n", array_slice((array) $out, -200)) . "\n";
    // Never a token in a log
    @file_put_contents($file, preg_replace('/(token_auth[=:\'" ]+)[0-9a-f]{16,}/i', '$1...', $text));
    @chown($file, 'root');
    @chgrp($file, 'sentora');
    @chmod($file, 0640);
    return $file;
}

/**
 * A line for the daemon log and for /var/sentora/logs/matomo-import.log, which
 * keeps the hourly results (the daemon log is replaced every 5 minutes).
 *
 * @param string $line The message (without a line ending).
 * @return void
 */
function MatomoReport($line)
{
    echo $line . fs_filehandler::NewLine();
    $file = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/') . '/matomo-import.log';
    $lines = is_file($file) ? (array) @file($file, FILE_IGNORE_NEW_LINES) : array();
    $lines[] = date('Y-m-d H:i') . '  ' . $line;
    @file_put_contents($file, implode("\n", array_slice($lines, -2000)) . "\n");
    @chown($file, 'root');
    @chgrp($file, 'sentora');
    @chmod($file, 0640);
}

?>
