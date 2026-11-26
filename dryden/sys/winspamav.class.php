<?php

/**
 * SpamAV on Windows: the same Admin > SpamAV Admin settings (x_settings, module 'SpamAV Admin'), applied to what the Windows
 * installer sets up instead of Linux's milters. Mail reaches ClamAV through Stalwart's MTA hook: a page of this server's
 * Apache (apps/mailscan/scan.php, this server only, with a key) that asks clamd on 127.0.0.1:3310; spam is Stalwart's own
 * filter. The daemon (SYSTEM) writes:
 * - clamd.conf and freshclam.conf (clamd_conf, freshclam_conf): only the lines the settings name, the rest as the installer
 *   wrote them; ClamAV or FreshClam restarts when its file changed;
 * - the virus check's options (mailscan_options: on or off, what to do when ClamAV does not answer), read by scan.php;
 * - Stalwart's spam filter (x:SpamSettings: on or off, spam score, refuse score), through its API, when the panel's values
 *   changed (so a change made in Stalwart's own admin pages stays until the panel's changes);
 * - the status the page shows (spamav-status.json: services, signatures, versions), and the jobs asked for on the page
 *   (restart, signature update).
 * Not on Windows (yet): website file scanning, mailbox owners' own spam settings, the mail figures, senders lists.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-26
 */
class sys_winspamav
{
    /** What was last sent to Stalwart's spam filter (root's jobs only). */
    const STATE = '/var/lib/sentora/phpaccounts/spamav-stalwart.json';

    /**
     * The settings SpamAV Admin shows on Windows, with their defaults there where they differ from Linux's (what the
     * Windows installer set up, so adding them changes nothing).
     *
     * @return array<string, string|null> name => Windows default (null: Linux's).
     */
    static function Settings()
    {
        $clam = (string) ctrl_options::GetSystemOption('clamd_service') !== '' ? 'true' : 'false';
        return array(
            'clam_enabled' => $clam, 'clam_on_fail' => 'Defer', 'clam_max_file_size' => '64', 'clam_max_scan_size' => '200', 'clam_max_recursion' => null,
            'clam_max_files' => null, 'clam_scan_archive' => null, 'clam_scan_pdf' => null, 'clam_scan_ole2' => null, 'clam_block_macros' => null,
            'clam_scan_html' => null, 'clam_phishing_sigs' => null, 'clam_phishing_urls' => null, 'clam_heuristic' => null, 'clam_alert_encrypted' => null,
            'clam_db_checks' => null, 'clam_threads' => '8',
            'sa_enabled' => null, 'sa_required_score' => null, 'sa_reject_score' => '0',
        );
    }

    /**
     * Linux's definitions cut down to the Windows ones, with the Windows defaults and texts.
     *
     * @param array<string, array<int, mixed>> $defs sys_spamav's definitions.
     * @return array<string, array<int, mixed>>
     */
    static function Definitions(array $defs)
    {
        $out = array();
        foreach (self::Settings() as $name => $def) {
            if (!isset($defs[$name])) {
                continue;
            }
            $d = $defs[$name];
            if ($def !== null) {
                $d[3] = $def;
            }
            $out[$name] = $d;
        }
        $out['clam_on_fail'][4] = 'Accept: mail is delivered unscanned. Try later: the sender\'s server is asked to try again later (the mail is not lost).';
        $out['sa_enabled'] = array('spamassassin', 'Check mail for spam', 'bool', $out['sa_enabled'][3], 'The mail server\'s own spam filter scores incoming mail; mail at or above the spam score goes to the Junk Mail folder.', null);
        $out['sa_required_score'][4] = 'Mail scoring this or more is spam. Lower catches more spam but also more good mail.';
        return $out;
    }

    /**
     * This server's memory, from Server Monitor's figures (written by the SentoraStats service).
     *
     * @return int MB, 0 when unknown.
     */
    static function MemoryMB()
    {
        $j = @json_decode((string) @file_get_contents(sys_statedir::DIR . '/monitor/winstats.json'), true);
        return isset($j['mem']['total']) ? (int) round($j['mem']['total'] / 1048576) : 0;
    }

    /**
     * A service's state, asked of Windows (sc query).
     *
     * @param string $name
     * @return bool Running.
     */
    static function Running($name)
    {
        return self::State($name) === 'running';
    }

    /**
     * A service's state, asked of Windows (sc query).
     *
     * @param string $name
     * @return string 'running', 'stopped' (or starting, stopping) or 'missing' (not installed).
     */
    static function State($name)
    {
        if (!preg_match('/^[A-Za-z0-9 _.-]{1,60}$/', $name)) {
            return 'missing';
        }
        $o = array();
        exec('sc.exe query "' . $name . '" 2>&1', $o);
        $t = implode("\n", $o);
        if (preg_match('/STATE\s*:\s*4\s+RUNNING/', $t)) {
            return 'running';
        }
        return preg_match('/STATE\s*:/', $t) ? 'stopped' : 'missing';
    }

    /**
     * Restart a service (the daemon runs as SYSTEM).
     *
     * @param string $name
     * @return bool It runs again.
     */
    static function Restart($name)
    {
        if (!preg_match('/^[A-Za-z0-9 _.-]{1,60}$/', $name)) {
            return false;
        }
        $o = array();
        exec('powershell.exe -NoProfile -NonInteractive -Command "Restart-Service -Name \'' . $name . '\' -Force" 2>&1', $o);
        return self::Running($name);
    }

    /**
     * Set the lines a settings file of ClamAV's names, keeping all the others.
     *
     * @param string $text The file.
     * @param array<string, string> $set Directive => value.
     * @return string The file with them.
     */
    static function SetLines($text, array $set)
    {
        $text = str_replace("\r\n", "\n", $text);
        foreach ($set as $k => $v) {
            $line = $k . ' ' . $v;
            if (preg_match('/^' . preg_quote($k, '/') . '\s.*$/m', $text)) {
                $text = preg_replace('/^' . preg_quote($k, '/') . '\s.*$/m', $line, $text, 1);
            } else {
                $text = rtrim($text, "\n") . "\n" . $line . "\n";
            }
        }
        return str_replace("\n", "\r\n", rtrim($text, "\n") . "\n");
    }

    /**
     * Write a file when its text changed (in place, so its rights stay).
     *
     * @param string $file
     * @param string $text
     * @return bool|null True: written, false: unchanged, null: could not.
     */
    static function Put($file, $text)
    {
        // (the same lines with other line ends are the same file: the installer's may have either)
        if (is_file($file) && str_replace("\r\n", "\n", rtrim((string) @file_get_contents($file))) === str_replace("\r\n", "\n", rtrim($text))) {
            return false;
        }
        return @file_put_contents($file, $text) === strlen($text) ? true : null;
    }

    /**
     * The daemon's part (SYSTEM, every run).
     *
     * @return string[] Lines for the daemon's log.
     */
    static function Apply()
    {
        $out = array();
        if (!sys_versions::IsWindows()) {
            return $out;
        }
        sys_spamav::Ensure();
        $yn = function ($n) { return sys_spamav::On($n) ? 'yes' : 'no'; };
        $int = function ($n) { return (int) sys_spamav::Get($n); };
        $clamOn = sys_spamav::On('clam_enabled');
        $clamSvc = (string) ctrl_options::GetSystemOption('clamd_service');
        $freshSvc = (string) ctrl_options::GetSystemOption('freshclam_service');
        // (services the installer did not set up, as when ClamAV's list could not be downloaded, are left out)
        $clamSvc = ($clamSvc !== '' && self::State($clamSvc) !== 'missing') ? $clamSvc : '';
        $freshSvc = ($freshSvc !== '' && self::State($freshSvc) !== 'missing') ? $freshSvc : '';
        $clamConf = (string) ctrl_options::GetSystemOption('clamd_conf');
        $freshConf = (string) ctrl_options::GetSystemOption('freshclam_conf');
        // ClamAV's own settings
        if ($clamConf !== '' && is_file($clamConf)) {
            $old = (string) @file_get_contents($clamConf);
            $new = self::SetLines($old, array(
                'MaxFileSize' => $int('clam_max_file_size') . 'M', 'MaxScanSize' => $int('clam_max_scan_size') . 'M', 'MaxRecursion' => (string) $int('clam_max_recursion'),
                'MaxFiles' => (string) $int('clam_max_files'), 'MaxThreads' => (string) $int('clam_threads'), 'ScanArchive' => $yn('clam_scan_archive'),
                'ScanPDF' => $yn('clam_scan_pdf'), 'ScanOLE2' => $yn('clam_scan_ole2'), 'OLE2BlockMacros' => $yn('clam_block_macros'), 'ScanHTML' => $yn('clam_scan_html'),
                'PhishingSignatures' => $yn('clam_phishing_sigs'), 'PhishingScanURLs' => $yn('clam_phishing_urls'), 'HeuristicAlerts' => $yn('clam_heuristic'),
                'AlertEncrypted' => $yn('clam_alert_encrypted')));
            $r = self::Put($clamConf, $new);
            if ($r === null) {
                $out[] = 'SpamAV: could not write ' . $clamConf;
            } elseif ($r && $clamOn && $clamSvc !== '') {
                $out[] = 'SpamAV: ClamAV settings written, ClamAV ' . (self::Restart($clamSvc) ? 'restarted' : 'DID NOT START (see its log)');
            } elseif ($r) {
                $out[] = 'SpamAV: ClamAV settings written';
            }
        }
        if ($freshConf !== '' && is_file($freshConf)) {
            $r = self::Put($freshConf, self::SetLines((string) @file_get_contents($freshConf), array('Checks' => (string) $int('clam_db_checks'))));
            if ($r === null) {
                $out[] = 'SpamAV: could not write ' . $freshConf;
            } elseif ($r) {
                $out[] = 'SpamAV: signature checks ' . $int('clam_db_checks') . ' a day' . ($freshSvc !== '' && $clamOn ? ', FreshClam ' . (self::Restart($freshSvc) ? 'restarted' : 'DID NOT START') : '');
            }
        }
        // ClamAV runs only while it is switched on (it holds about 1.5 GB of memory)
        foreach (array($clamSvc, $freshSvc) as $svc) {
            if ($svc === '' || !preg_match('/^[A-Za-z0-9 _.-]{1,60}$/', $svc)) {
                continue;
            }
            $up = self::Running($svc);
            if ($up !== $clamOn) {
                exec('powershell.exe -NoProfile -NonInteractive -Command "' . ($clamOn ? 'Start' : 'Stop') . '-Service -Name \'' . $svc . '\'" 2>&1');
                $out[] = 'SpamAV: ' . $svc . ' ' . ($clamOn ? (self::Running($svc) ? 'started' : 'DID NOT START') : 'stopped (virus scanning is off)');
            }
        }
        // the mail virus check's options (scan.php reads them)
        $opt = (string) ctrl_options::GetSystemOption('mailscan_options');
        if ($opt !== '' && is_dir(dirname($opt))) {
            $r = self::Put($opt, json_encode(array('enabled' => $clamOn, 'on_fail' => sys_spamav::Get('clam_on_fail') === 'Accept' ? 'accept' : 'defer')));
            if ($r === null) {
                $out[] = 'SpamAV: could not write ' . $opt;
            } elseif ($r) {
                $out[] = 'SpamAV: mail virus check ' . ($clamOn ? 'on' : 'off');
            }
        }
        // Stalwart's spam filter
        $want = array('on' => sys_spamav::On('sa_enabled'), 'spam' => (float) sys_spamav::Get('sa_required_score'), 'reject' => (float) sys_spamav::Get('sa_reject_score'));
        $state = is_file(self::STATE) ? (array) json_decode((string) @file_get_contents(self::STATE), true) : array();
        if ((string) ctrl_options::GetSystemOption('mail_api') !== '' && (!isset($state['sent']) || $state['sent'] != $want)) {
            $why = sys_winmail::Connect();
            if ($why !== '') {
                $out[] = $why;
            } else {
                $set = array('enable' => $want['on'], 'scoreSpam' => $want['spam'], 'scoreReject' => $want['reject']);
                if (sys_winmail::Call('x:SpamSettings/set', array('update' => (object) array('singleton' => $set))) === null) {
                    $out[] = 'SpamAV: Stalwart refused its spam settings: ' . sys_winmail::$why;
                } else {
                    $state['sent'] = $want;
                    if (!is_dir(dirname(self::STATE))) {
                        @mkdir(dirname(self::STATE), 0700, true);
                    }
                    @file_put_contents(self::STATE, json_encode($state));
                    $out[] = 'SpamAV: spam filter ' . ($want['on'] ? 'on, spam from ' . $want['spam'] . ($want['reject'] > 0 ? ', refused from ' . $want['reject'] : ', never refused') : 'off');
                }
            }
        }
        // the jobs asked for on the page
        $status = @json_decode((string) @file_get_contents(sys_spamav::STATUS), true);
        $status = is_array($status) ? $status : array();
        $req = @json_decode((string) @file_get_contents(sys_spamav::REQUEST), true);
        @unlink(sys_spamav::REQUEST);
        if (is_array($req) && isset($req['action'])) {
            $what = $req['action'] === 'signatures' ? 'Update virus signatures' : ($req['action'] === 'restart' ? 'Restart the scanners' : '');
            if ($what !== '') {
                $ok = false;
                $text = '';
                if ($req['action'] === 'restart') {
                    $ok = $clamSvc !== '' && self::Restart($clamSvc) && ($freshSvc === '' || self::Restart($freshSvc));
                    $text = $ok ? '' : 'Failed: a service did not start';
                } else {
                    $exe = (string) ctrl_options::GetSystemOption('freshclam_exe');
                    if ($exe !== '' && is_file($exe) && $freshConf !== '') {
                        $o = array();
                        exec('"' . str_replace('/', '\\', $exe) . '" --config-file="' . str_replace('/', '\\', $freshConf) . '" --stdout 2>&1', $o, $rc);
                        $ok = (int) $rc === 0 || (int) $rc === 1;
                        $text = substr(trim(implode(' ', array_slice($o, -2))), 0, 300);
                        if ($ok && $clamSvc !== '' && $clamOn) {
                            self::Restart($clamSvc);
                        }
                    } else {
                        $text = 'Failed: no freshclam_exe setting';
                    }
                }
                $status['last_request'] = array('what' => $what, 'by' => (string) ($req['by'] ?? ''), 'time' => time(), 'ok' => $ok, 'text' => $text);
                $out[] = 'SpamAV: ' . $what . ': ' . ($ok ? 'done' : $text);
            }
        }
        // the status the page shows
        $status['units'] = array();
        foreach (array($clamSvc, $freshSvc) as $s) {
            if ($s !== '') {
                $status['units'][$s] = self::Running($s);
            }
        }
        $status['units']['stalwart-spam'] = $want['on'];
        $db = rtrim((string) ctrl_options::GetSystemOption('clamav_db_dir'), '/\\');
        $status['sigs'] = array();
        foreach (array('daily', 'main') as $n) {
            foreach (array('.cld', '.cvd') as $ext) {
                $f = $db . '/' . $n . $ext;
                if ($db !== '' && is_file($f)) {
                    // (the header: ClamAV-VDB:build time:version:signatures:...)
                    $h = explode(':', (string) @file_get_contents($f, false, null, 0, 512));
                    $status['sigs'][$n] = array('version' => isset($h[2]) ? $h[2] : '?', 'built' => isset($h[1]) ? trim($h[1]) : '', 'signatures' => isset($h[3]) ? (int) $h[3] : 0);
                    break;
                }
            }
        }
        $status['have_sigs'] = !empty($status['sigs']);
        if (empty($status['versions_checked']) || time() - (int) $status['versions_checked'] > 86400) {
            $exe = (string) ctrl_options::GetSystemOption('clamd_exe');
            $o = array();
            if ($exe !== '' && is_file($exe)) {
                exec('"' . str_replace('/', '\\', $exe) . '" --version 2>&1', $o);
            }
            $status['versions'] = array('clamav' => preg_match('/ClamAV ([\d.]+)/', implode(' ', $o), $m) ? $m[1] : '', 'spamassassin' => '');
            $status['versions_checked'] = time();
        }
        $status['memory_mb'] = self::MemoryMB();
        $status['time'] = time();
        @file_put_contents(sys_spamav::STATUS, json_encode($status));
        return $out;
    }
}
