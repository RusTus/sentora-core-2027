<?php

/**
 * Cron Manager: which scripts an account may schedule, shared by the module and its
 * file browser (files.php). A script is a real .php file (not a symbolic link) in a
 * real folder inside the account's public_html (pd_safe::ResolveDir: no links, no
 * hidden folders, no ".."), whose path the cron line can carry as it is.
 */
require_once dirname(__FILE__) . '/../../protected_directories/code/pdsafe.php';

class cron_safe
{
    /** the path relative to public_html can be written on a cron line as it is */
    static function ValidRel($rel)
    {
        $rel = (string) $rel;
        return strlen($rel) <= 250 && preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.-]*(/[A-Za-z0-9_][A-Za-z0-9_.-]*)*\.php$#', $rel) === 1
            && strpos($rel, '..') === false;
    }

    /** a script given relative to public_html: its full path, or false */
    static function ResolveFile($username, $rel)
    {
        $rel = trim(str_replace('\\', '/', (string) $rel), '/');
        if (!self::ValidRel($rel)) {
            return false;
        }
        $dirRel = (strpos($rel, '/') === false) ? '' : substr($rel, 0, strrpos($rel, '/'));
        $dir = pd_safe::ResolveDir($username, $dirRel);
        if ($dir === false) {
            return false;
        }
        $file = $dir . '/' . basename($rel);
        clearstatcache(true, $file);
        $st = @lstat($file);
        if ($st === false || ($st['mode'] & 0170000) !== 0100000) {
            return false; // missing, a link or not a plain file
        }
        return (realpath($file) === $file) ? $file : false;
    }

    /**
     * The last run's log of one of the account's tasks (the account's tmp/cron-<id>.log,
     * written by the job as the account's user): array(text, modified time) or false.
     * Never through a link (a link there could show the panel any file it can read):
     * a plain file owned by the account's user, and the file opened is the one checked.
     */
    static function ReadLog($username, $accountId, $jobId, $maxBytes = 65536)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $username)) {
            return false;
        }
        $home = pd_safe::Home($username);
        $tmp = $home . '/tmp';
        if (is_link($home) || is_link($tmp) || !is_dir($tmp)) {
            return false;
        }
        $file = $tmp . '/cron-' . (int) $jobId . '.log';
        clearstatcache(true, $file);
        $st = @lstat($file);
        $pw = function_exists('posix_getpwnam') ? posix_getpwnam(sys_accountphp::UserName((int) $accountId)) : false;
        if ($st === false || ($st['mode'] & 0170000) !== 0100000 || $pw === false || $st['uid'] !== $pw['uid']) {
            return false;
        }
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return false;
        }
        $fs = fstat($fh);
        if ($fs['dev'] !== $st['dev'] || $fs['ino'] !== $st['ino']) {
            fclose($fh);
            return false;
        }
        if ($fs['size'] > $maxBytes) {
            fseek($fh, -$maxBytes, SEEK_END);
        }
        $text = (string) fread($fh, $maxBytes);
        fclose($fh);
        return array(($fs['size'] > $maxBytes ? "[... the start is cut: only the last " . round($maxBytes / 1024) . " KB are shown]\n" : '') . $text, $fs['mtime']);
    }

    /** the result of a run from its log: array(state ok|error|timeout|running, short text) */
    static function LogResult($text)
    {
        if (preg_match('/Finished (\S+), exit code (\d+)\s*$/', $text, $m)) {
            $code = (int) $m[2];
            if ($code === 0) {
                return array('ok', 'finished');
            }
            if ($code === 124 || $code === 137) {
                return array('timeout', 'stopped: it ran longer than the server allows');
            }
            return array('error', 'ended with error code ' . $code);
        }
        return preg_match('/^Started /', ltrim(preg_replace('/^\[\.\.\..*\n/', '', $text))) ? array('running', 'running now, or stopped without finishing') : array('error', 'no result');
    }

    /** the timings offered: crontab value => label */
    static function Timings()
    {
        return array(
            '* * * * *' => 'Every minute',
            '*/5 * * * *' => 'Every 5 minutes',
            '*/10 * * * *' => 'Every 10 minutes',
            '*/15 * * * *' => 'Every 15 minutes',
            '*/30 * * * *' => 'Every 30 minutes',
            '0 * * * *' => 'Every hour',
            '0 */2 * * *' => 'Every 2 hours',
            '0 */6 * * *' => 'Every 6 hours',
            '0 */12 * * *' => 'Every 12 hours',
            '0 0 * * *' => 'Every day (midnight)',
            '0 0 * * 0' => 'Every week (Sunday midnight)',
            '0 0 1 * *' => 'Every month (the 1st, midnight)',
        );
    }

    /** a label for a timing, also for the ones the old module wrote */
    static function TimingLabel($timing)
    {
        $t = self::Timings();
        $old = array('0,5,10,15,20,25,30,35,40,45,50,55 * * * *' => 'Every 5 minutes', '0,10,20,30,40,50 * * * *' => 'Every 10 minutes',
            '0,30 * * * *' => 'Every 30 minutes', '0 0,2,4,6,8,10,12,14,16,18,20,22 * * *' => 'Every 2 hours', '0 0,8,16 * * *' => 'Every 8 hours',
            '0 0,12 * * *' => 'Every 12 hours');
        return isset($t[$timing]) ? $t[$timing] : (isset($old[$timing]) ? $old[$timing] : $timing);
    }
}
