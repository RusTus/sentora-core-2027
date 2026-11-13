<?php

/**
 * Core class: SpamAV Admin's file scanning. Every night (or when asked on the page) root reads the
 * hosted accounts' files and hands each one's contents to ClamAV's own scanner (clamd) over its
 * socket (INSTREAM): clamd needs no access to the websites (no SELinux or AppArmor change), and only
 * root opens the files, never through a link. One night: the files changed since the last scan; on
 * the full-scan day: every file. Bigger files than the limit are skipped (and counted).
 *
 * What is found: noted with its account, file and signature; the account's owner and the
 * administrators get a notice; with quarantine on, the file is moved into a folder only root can
 * read (never deleted), to be put back or deleted from the page. The results are a JSON file the
 * page reads (root:<panel user> 0640); the page's buttons are requests the daemon (root) carries out.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-13
 */
class sys_filescan
{
    /** The results the page shows (root writes it, the panel's user reads it). */
    const STATE = '/var/sentora/logs/filescan.json';
    /** A request from the page (scan, full, restore, delete), carried out by the daemon. */
    const REQUEST = '/var/lib/sentora/tmp/filescan-request.json';
    /** Where quarantined files go (root only). */
    const QUARANTINE = '/var/sentora/quarantine';
    /** One scan at a time. */
    const LOCK = '/var/lib/sentora/filescan.lock';
    /** Nights kept in the history, findings kept in the list. */
    const NIGHTS = 60;
    const FINDINGS = 500;

    /**
     * The results so far ('runs', 'findings', 'running', 'last'...), or an empty set.
     *
     * @return array<string, mixed>
     */
    static function State()
    {
        $s = json_decode((string) @file_get_contents(self::STATE), true);
        return is_array($s) ? $s + array('runs' => array(), 'findings' => array()) : array('runs' => array(), 'findings' => array());
    }

    /**
     * Write the results (root).
     *
     * @param array<string, mixed> $s
     * @return void
     */
    static function Save(array $s)
    {
        $tmp = self::STATE . '.new';
        @unlink($tmp);
        if (@file_put_contents($tmp, json_encode($s), LOCK_EX) === false) {
            return;
        }
        @chown($tmp, 0);
        @chgrp($tmp, sys_accountphp::PANEL_USER);
        @chmod($tmp, 0640);
        @rename($tmp, self::STATE);
    }

    /**
     * Ask clamd about one file: its contents are sent over the socket (INSTREAM).
     *
     * @param resource $fh The open file.
     * @param string $socket clamd's socket (sys_platform clamd_socket).
     * @return array{0: string, 1: string} array('ok'|'found'|'error', the signature or the error)
     */
    static function Ask($fh, $socket)
    {
        $c = @stream_socket_client('unix://' . $socket, $errno, $err, 10);
        if ($c === false) {
            return array('error', 'clamd does not answer (' . $err . ')');
        }
        stream_set_timeout($c, 120);
        fwrite($c, "zINSTREAM\0");
        while (!feof($fh)) {
            $chunk = fread($fh, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            if (@fwrite($c, pack('N', strlen($chunk)) . $chunk) === false) {
                break;   // (clamd stops reading when the file is over its StreamMaxLength: its answer says so)
            }
        }
        @fwrite($c, pack('N', 0));
        $answer = trim((string) stream_get_contents($c), "\0\r\n ");
        fclose($c);
        if (preg_match('/^stream: (.+) FOUND$/', $answer, $m)) {
            return array('found', substr($m[1], 0, 200));
        }
        if ($answer === 'stream: OK') {
            return array('ok', '');
        }
        return array('error', substr($answer !== '' ? $answer : 'no answer', 0, 200));
    }

    /**
     * Scan the hosted accounts (root, from bin/sentora-file-scan).
     *
     * @param bool $full Every file (else the ones changed since the last scan).
     * @param callable|null $say Progress lines.
     * @return array<string, mixed> This run.
     */
    static function Scan($full, $say = null)
    {
        global $zdbh;
        $say = $say ?: function ($s) {};
        $socket = (string) sys_platform::Get('clamd_socket');
        $maxBytes = max(1, (int) sys_spamav::Get('fs_max_file_size')) * 1048576;
        $streamMax = max(1, (int) sys_spamav::Get('clam_max_scan_size')) * 1048576;
        $maxBytes = min($maxBytes, $streamMax);   // (clamd reads no more than its StreamMaxLength)
        $backups = sys_spamav::On('fs_backups');
        $state = self::State();
        $since = $full ? 0 : (int) ($state['last_scan_started'] ?? 0);
        if ($since === 0) {
            $full = true;
        }
        $run = array('started' => time(), 'full' => $full, 'files' => 0, 'bytes' => 0, 'skipped_big' => 0, 'found' => 0, 'errors' => 0, 'accounts' => 0);
        $state['running'] = array('started' => $run['started'], 'full' => $full);
        self::Save($state);
        $hosted = rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . '/';
        $accounts = $zdbh->query("SELECT ac_id_pk, ac_user_vc FROM x_accounts WHERE ac_deleted_ts IS NULL ORDER BY ac_user_vc")->fetchAll(PDO::FETCH_ASSOC);
        $found = array();
        foreach ($accounts as $a) {
            $user = (string) $a['ac_user_vc'];
            if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $user)) {
                continue;
            }
            $home = $hosted . $user;
            if (!is_dir($home) || is_link($home)) {
                continue;
            }
            $run['accounts']++;
            $say('scanning ' . $user);
            $it = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
                    function ($f) use ($home, $backups) {
                        if ($f->isLink()) {
                            return false;   // never through a link
                        }
                        // (the account's backups: zip files of the site, scanned only when chosen)
                        return $backups || $f->getPathname() !== $home . '/backups';
                    }
                ),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($it as $f) {
                if (!$f->isFile() || $f->isLink()) {
                    continue;
                }
                $p = $f->getPathname();
                $size = (int) $f->getSize();
                if (!$full && max((int) $f->getMTime(), (int) $f->getCTime()) < $since) {
                    continue;
                }
                if ($size > $maxBytes) {
                    $run['skipped_big']++;
                    continue;
                }
                $fh = @fopen($p, 'rb');
                if ($fh === false) {
                    continue;
                }
                $st = fstat($fh);
                // (the file must still be the one that was listed: not exchanged for something else)
                if (!$st || ($st['mode'] & 0170000) !== 0100000 || $st['ino'] !== $f->getInode()) {
                    fclose($fh);
                    continue;
                }
                list($r, $what) = self::Ask($fh, $socket);
                fclose($fh);
                $run['files']++;
                $run['bytes'] += $size;
                if ($r === 'error') {
                    $run['errors']++;
                    if ($run['errors'] === 1) {
                        $run['first_error'] = $what;
                    }
                    if (strpos($what, 'does not answer') !== false) {
                        $run['stopped'] = 'ClamAV\'s scanner does not answer: is "Scan mail for viruses" switched on?';
                        break 2;
                    }
                    continue;
                }
                if ($r === 'found') {
                    $run['found']++;
                    $found[] = array('time' => time(), 'acc' => (int) $a['ac_id_pk'], 'user' => $user, 'path' => substr($p, strlen($hosted)), 'sig' => $what, 'size' => $size, 'status' => 'found');
                    $say('FOUND ' . $what . ' in ' . $p);
                }
            }
        }
        $run['finished'] = time();
        // what was found: quarantined when chosen, told to the owner and the administrators
        $quarantine = sys_spamav::On('fs_quarantine');
        foreach ($found as $i => $x) {
            if ($quarantine) {
                $id = self::Quarantine($hosted . $x['path'], $x);
                if ($id !== '') {
                    $found[$i]['status'] = 'quarantined';
                    $found[$i]['id'] = $id;
                }
            }
        }
        self::Notices($found);
        $state = self::State();
        unset($state['running']);
        $state['last_scan_started'] = $run['started'];
        if ($full) {
            $state['last_full'] = $run['started'];
        }
        $state['runs'][] = $run;
        $state['runs'] = array_slice($state['runs'], -self::NIGHTS);
        $state['findings'] = array_slice(array_merge($found, $state['findings']), 0, self::FINDINGS);
        self::Save($state);
        return $run;
    }

    /**
     * Move a found file into the quarantine (root only), with what is needed to put it back.
     *
     * @param string $path
     * @param array<string, mixed> $x The finding.
     * @return string The quarantine id, or '' when it could not be moved.
     */
    static function Quarantine($path, array $x)
    {
        if (!is_file($path) || is_link($path)) {
            return '';
        }
        if (!is_dir(self::QUARANTINE)) {
            @mkdir(self::QUARANTINE, 0700, true);
        }
        @chmod(self::QUARANTINE, 0700);
        if (is_link(self::QUARANTINE) || fileowner(self::QUARANTINE) !== 0) {
            return '';
        }
        $id = bin2hex(random_bytes(8));
        $st = lstat($path);
        $dst = self::QUARANTINE . '/' . $id;
        if (!@rename($path, $dst)) {
            if (!@copy($path, $dst) || !@unlink($path)) {
                @unlink($dst);
                return '';
            }
        }
        @chown($dst, 0);
        @chgrp($dst, 0);
        @chmod($dst, 0000);
        file_put_contents($dst . '.json', json_encode(array('path' => $path, 'uid' => $st['uid'], 'gid' => $st['gid'], 'mode' => $st['mode'] & 07777, 'sig' => $x['sig'], 'time' => time())));
        @chmod($dst . '.json', 0600);
        return $id;
    }

    /**
     * Put a quarantined file back where it was (with its owner and permissions), or delete it.
     *
     * @param string $id
     * @param bool $restore True: put back; false: delete for good.
     * @return string '' when done, else why not.
     */
    static function Release($id, $restore)
    {
        if (!preg_match('/^[a-f0-9]{16}$/', (string) $id)) {
            return 'unknown file';
        }
        $f = self::QUARANTINE . '/' . $id;
        $meta = json_decode((string) @file_get_contents($f . '.json'), true);
        if (!is_file($f) || is_link($f) || !is_array($meta)) {
            return 'not in quarantine';
        }
        if ($restore) {
            $to = (string) $meta['path'];
            $hosted = rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . '/';
            $dir = dirname($to);
            $realDir = realpath($dir);
            // only back into the hosting folder, into the folder it came from, never over something
            if (strpos($to, '..') !== false || $realDir === false || strpos($realDir . '/', $hosted) !== 0 || $realDir !== $dir || file_exists($to) || is_link($to)) {
                return 'its folder is gone or something else is there now';
            }
            if (!@rename($f, $to)) {
                return 'could not be moved back';
            }
            @chown($to, (int) $meta['uid']);
            @chgrp($to, (int) $meta['gid']);
            @chmod($to, (int) $meta['mode']);
        } else {
            @unlink($f);
        }
        @unlink($f . '.json');
        $s = self::State();
        foreach ($s['findings'] as $i => $x) {
            if (($x['id'] ?? '') === $id) {
                $s['findings'][$i]['status'] = $restore ? 'restored' : 'deleted';
                $s['findings'][$i]['done'] = time();
            }
        }
        self::Save($s);
        return '';
    }

    /**
     * Notices: the account's owner (when chosen) about their own files, the administrators about all.
     *
     * @param array<int, array<string, mixed>> $found
     * @return void
     */
    static function Notices(array $found)
    {
        if (!$found || !class_exists('ctrl_notices') || !method_exists('ctrl_notices', 'Send')) {
            return;
        }
        $by = array();
        foreach ($found as $x) {
            $by[$x['acc']][] = $x;
        }
        if (sys_spamav::On('fs_notify_owner')) {
            foreach ($by as $acc => $list) {
                $lines = array();
                foreach (array_slice($list, 0, 10) as $x) {
                    $lines[] = '- ' . preg_replace('#^[^/]+/#', '', $x['path']) . ' (' . $x['sig'] . ($x['status'] === 'quarantined' ? ', moved to quarantine' : '') . ')';
                }
                ctrl_notices::Send(array('scope' => 'user', 'targets' => array($acc), 'type' => 'warning', 'key' => 'filescan-' . date('Ymd'),
                    'title' => 'Malware found in your website files', 'source' => 'SpamAV Admin', 'closable' => true,
                    'body' => "The nightly virus scan found " . count($list) . " file(s) in your hosting account that look like malware:\n" . implode("\n", $lines)
                        . (count($list) > 10 ? "\n..." : '') . "\nCheck your website (often an outdated plugin or a stolen FTP password), and change your passwords."));
            }
        }
        ctrl_notices::Send(array('scope' => 'groups', 'targets' => array(1), 'type' => 'warning', 'key' => 'filescan-admin', 'source' => 'SpamAV Admin', 'closable' => true,
            'title' => 'The file scan found malware', 'end' => time() + 7 * 86400,
            'body' => 'The scan of ' . date('Y-m-d') . ' found ' . count($found) . ' file(s) in ' . count($by) . ' account(s): Admin > SpamAV Admin > File scanning.'));
    }

    /**
     * Daemon (root, every run): carry out a request from the page, and start the nightly scan at its
     * hour (once a day). The scan runs on its own (bin/sentora-file-scan, low priority).
     *
     * @return string What was started, for the daemon's log ('' for nothing).
     */
    static function Daemon()
    {
        $bin = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/bin/sentora-file-scan';
        if (!is_file($bin)) {
            return '';
        }
        $start = function ($args) use ($bin) {
            $php = str_replace('{v}', '', (string) sys_platform::Get('php_cli'));
            $php = is_executable($php) ? $php : 'php';
            exec('setsid nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($bin) . ' ' . $args . ' > /var/sentora/logs/filescan-last.log 2>&1 < /dev/null &');
        };
        $req = self::REQUEST;
        if (is_file($req) && !is_link($req)) {
            $r = json_decode((string) @file_get_contents($req), true);
            @unlink($req);
            $what = is_array($r) ? (string) ($r['action'] ?? '') : '';
            $id = is_array($r) ? (string) ($r['id'] ?? '') : '';
            if ($what === 'scan' || $what === 'full') {
                $start('--' . ($what === 'full' ? 'full' : 'changed'));
                return 'file scan started (asked on the page)';
            }
            if (($what === 'restore' || $what === 'delete') && preg_match('/^[a-f0-9]{16}$/', $id)) {
                $why = self::Release($id, $what === 'restore');
                return 'quarantined file ' . $id . ': ' . ($why === '' ? ($what === 'restore' ? 'put back' : 'deleted') : $why);
            }
        }
        if (!sys_spamav::On('fs_enabled') || (int) date('G') !== (int) sys_spamav::Get('fs_hour')) {
            return '';
        }
        $s = self::State();
        if (date('Y-m-d', (int) ($s['last_auto'] ?? 0)) === date('Y-m-d')) {
            return '';
        }
        $s['last_auto'] = time();
        self::Save($s);
        $day = (string) sys_spamav::Get('fs_full_day');
        $full = $day === 'every' || $day === date('w') || empty($s['last_full']);
        $start($full ? '--full' : '--changed');
        return 'nightly file scan started (' . ($full ? 'every file' : 'files changed since the last scan') . ')';
    }
}
