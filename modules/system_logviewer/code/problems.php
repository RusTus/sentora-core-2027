<?php

/**
 * System Log Viewer: the "Problems" log. The daemon (root) gathers the error and warning lines of logs
 * the panel can not read (mail, Dovecot, ClamAV, SOGo, fail2ban's bans) into one log it can:
 * /var/sentora/logs/problems.log, root:<panel user> 0640.
 *
 * Only errors, warnings and bans are kept: normal deliveries and logins are not, nor any line that
 * mentions a password, secret, token or key. The sources are fixed here (never read from the panel's
 * settings, which the panel itself can change), the output folder must be root's alone, lines are
 * stripped of control characters and cut to 400 characters, at most 400 lines are added per run, and
 * the file rolls over at 1 MB (one old copy kept). Where it got to in each source is kept in a folder
 * only root can read.
 *
 * Written by RusTus, 2026-11-11
 */
class system_logviewer_problems
{
    const MAX_LINE = 400;
    const MAX_PER_RUN = 400;
    const MAX_SIZE = 1048576;
    const FIRST_READ = 65536;   // a file seen for the first time: its last 64 KB

    /**
     * The fixed sources: files (glob => [label, regex a line must match, or '' for every line]).
     * @return array<string, array{0: string, 1: string}>
     */
    static function Files()
    {
        return array(
            // Dovecot's error log (Sentora's dovecot.conf sends info lines, logins included, elsewhere)
            '/var/log/dovecot.log' => array('dovecot', ''),
            '/var/log/fail2ban.log' => array('fail2ban', '/\s(Ban|Unban|ERROR|CRITICAL)\s/'),
            '/var/log/clamav/*.log' => array('clamav', '/\b(ERROR|WARNING|FOUND)\b/'),
            '/var/log/sogo/*.log' => array('sogo', '/\[(ERROR|WARN(ING)?)\]|EXCEPTION/i'),
        );
    }

    /**
     * The fixed journal matches: the mail channel (Postfix, Dovecot, SpamAssassin, the milters) and
     * ClamAV's programs, errors and warnings only.
     * @return string[] journalctl arguments
     */
    static function JournalArgs()
    {
        return array('-p', 'warning', 'SYSLOG_FACILITY=2', '+', 'SYSLOG_IDENTIFIER=clamd', '+', 'SYSLOG_IDENTIFIER=freshclam', '+', 'SYSLOG_IDENTIFIER=clamav-milter');
    }

    /** A line that may hold something private, or only noise: never copied. */
    static function Private($line)
    {
        return (bool) preg_match('/pass(word|wd)?\s*[=:]|secret|token|api[_-]?key|private key|authentication failed|SASL [A-Z]+ authentication/i', $line);
    }

    /** One clean line: no control characters or terminal escapes, at most MAX_LINE characters. */
    static function Clean($line)
    {
        $line = preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]|[\x00-\x1f\x7f]/', ' ', (string) $line);
        $line = trim(preg_replace('/\s+/', ' ', $line));
        if (strlen($line) > self::MAX_LINE) {
            $line = substr($line, 0, self::MAX_LINE) . '...';
        }
        return $line;
    }

    /**
     * Gather new lines and add them to the problems log.
     *
     * @param string $out The problems log (its folder must be root's alone).
     * @param string $stateDir Root's folder for where each source got to.
     * @param string $group The group that may read the log (the panel's).
     * @param string $journalctl The journalctl command ('' to skip the journal).
     * @param array|null $files Sources instead of Files() (for the test).
     * @return string What happened, for the daemon's report.
     */
    static function Run($out, $stateDir, $group, $journalctl = 'journalctl', $files = null)
    {
        $dir = dirname($out);
        $st = @lstat($dir);
        if ($st === false || is_link($dir) || $st['uid'] !== 0 || ($st['mode'] & 0022)) {
            return 'not written: ' . $dir . ' is not root\'s alone';
        }
        if (is_link($out) || (file_exists($out) && !is_file($out))) {
            return 'not written: ' . $out . ' is not a plain file';
        }
        if (!is_dir($stateDir) && !@mkdir($stateDir, 0700, true)) {
            return 'not written: no ' . $stateDir;
        }
        @chmod($stateDir, 0700);
        $stateFile = $stateDir . '/state.json';
        $state = @json_decode((string) @file_get_contents($stateFile), true);
        $state = is_array($state) ? $state : array('files' => array(), 'cursor' => '');
        $lines = array();

        foreach (($files === null ? self::Files() : $files) as $pattern => $how) {
            foreach ((array) glob($pattern) as $path) {
                if (!is_file($path) || is_link($path) || count($lines) >= self::MAX_PER_RUN) {
                    continue;
                }
                $size = (int) filesize($path);
                $ino = (int) fileinode($path);
                $was = isset($state['files'][$path]) ? $state['files'][$path] : null;
                $from = ($was !== null && (int) $was['ino'] === $ino && (int) $was['pos'] <= $size) ? (int) $was['pos'] : ($was === null ? max(0, $size - self::FIRST_READ) : 0);
                $fh = @fopen($path, 'r');
                if ($fh === false) {
                    continue;
                }
                fseek($fh, $from);
                if ($from > 0 && $was === null) {
                    fgets($fh);   // (the first, cut-off line of a file seen for the first time)
                }
                while (($l = fgets($fh, 8192)) !== false && count($lines) < self::MAX_PER_RUN) {
                    $l = rtrim($l, "\r\n");
                    if ($l === '' || ($how[1] !== '' && !preg_match($how[1], $l)) || self::Private($l)) {
                        continue;
                    }
                    $lines[] = $how[0] . ': ' . self::Clean($l);
                }
                $state['files'][$path] = array('ino' => $ino, 'pos' => (int) ftell($fh));
                fclose($fh);
            }
        }

        if ($journalctl !== '' && count($lines) < self::MAX_PER_RUN) {
            $args = array_merge(array('--no-pager', '-o', 'json', '-n', (string) self::MAX_PER_RUN),
                $state['cursor'] !== '' ? array('--after-cursor', $state['cursor']) : array('--since', '-1h'), self::JournalArgs());
            $cmd = escapeshellcmd($journalctl) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
            $rows = array();
            exec($cmd, $rows);
            foreach ($rows as $row) {
                $j = json_decode($row, true);
                if (!is_array($j)) {
                    continue;
                }
                if (isset($j['__CURSOR'])) {
                    $state['cursor'] = (string) $j['__CURSOR'];
                }
                // (errors and warnings only, whatever journalctl made of the matches: 0 emergency ... 4 warning)
                if (!isset($j['PRIORITY']) || !ctype_digit((string) $j['PRIORITY']) || (int) $j['PRIORITY'] > 4) {
                    continue;
                }
                $msg = isset($j['MESSAGE']) && is_string($j['MESSAGE']) ? $j['MESSAGE'] : '';
                if ($msg === '' || self::Private($msg) || count($lines) >= self::MAX_PER_RUN) {
                    continue;
                }
                $when = isset($j['__REALTIME_TIMESTAMP']) ? date('Y-m-d H:i:s', (int) ((int) $j['__REALTIME_TIMESTAMP'] / 1000000)) : '';
                $who = isset($j['SYSLOG_IDENTIFIER']) && is_string($j['SYSLOG_IDENTIFIER']) ? preg_replace('/[^A-Za-z0-9._\/-]/', '', $j['SYSLOG_IDENTIFIER']) : 'mail';
                $lines[] = self::Clean($who . ': ' . $when . ' ' . $msg);
            }
        }

        if ($lines) {
            if (is_file($out) && filesize($out) > self::MAX_SIZE) {
                @rename($out, $out . '.1');
            }
            $fh = @fopen($out, 'a');
            if ($fh === false) {
                return 'not written: can not open ' . $out;
            }
            $stamp = date('Y-m-d H:i:s');
            foreach ($lines as $l) {
                fwrite($fh, $stamp . '  ' . $l . "\n");
            }
            fclose($fh);
        }
        foreach (array($out, $out . '.1') as $f) {
            if (is_file($f) && !is_link($f)) {
                @chown($f, 0);
                @chgrp($f, $group);
                @chmod($f, 0640);
            }
        }
        @file_put_contents($stateFile . '.new', json_encode($state));
        @chmod($stateFile . '.new', 0600);
        @rename($stateFile . '.new', $stateFile);
        return count($lines) . ' new line(s)';
    }
}
