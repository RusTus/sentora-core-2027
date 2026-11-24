<?php

/**
 * zsudo for the panel on Windows (2026-11-24): what bin/zsudo.c is on Linux. The panel (Apache, a low-rights
 * account) can not restart a service or run the daemon itself: it asks, and the Sentora service (SentoraZsudo,
 * run as SYSTEM by the Windows installer) does the job, but only one from the fixed list below, built only from
 * the constants here.
 *
 *   bin\zsudo.bat <action> [words]   (the panel's side; the 'zsudo' setting names it; .bat, as PHP on Windows takes only .exe, .com and .bat for runnable): leaves the request in
 *                                   the queue, written whole in new\ before it appears in queue\
 *   php bin\zsudo.php --run-queue    (the service's side): takes the requests one by one, oldest first
 *
 * The same actions and words as on Linux, so the modules ask the same way on both:
 *   service <Apache2|CoreDNS|SFTPGo|Stalwart> <start|stop|restart|reload|graceful>   (Apache2 graceful/reload: httpd -k restart)
 *   daemon-run
 *   phpaccount-sync   each hosting account's PHP in line with the list the daemon wrote where only root's jobs write
 *                     (\var\lib\sentora\phpaccounts\wanted.txt): bin\phpaccount.ps1 sync beside the panel
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-24
 */

// (only from the command line: the panel's folder is served by Apache, and this file must never run as a page)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** The queue: \var\lib\sentora\zsudo on the panel's drive (a junction to C:\zpanel\data\sentora), named with that
 *  drive so it is the same folder whatever drive the caller is on. */
define('ZS_DIR', (preg_match('/^[A-Za-z]:/', __DIR__) ? substr(__DIR__, 0, 2) : '') . '/var/lib/sentora/zsudo');

/** The services a request may name (the Windows installer's service names). */
const ZS_SERVICES = array('Apache2', 'CoreDNS', 'SFTPGo', 'Stalwart');

/** What may be done to them. Apache's "graceful" and everyone's "reload" are a restart on Windows. */
const ZS_SERVICE_ACTIONS = array('start', 'stop', 'restart', 'reload', 'graceful');

const ZS_MAXWORDS = 4;

/**
 * The command for an allowed request, or null when it is not allowed. Every word in the command is one of the
 * constants here or a path made from this file's own place.
 *
 * @param string[] $w The request's words.
 * @return string|null
 */
function zs_command(array $w)
{
    $panel = str_replace('/', '\\', dirname(__DIR__));
    $php = PHP_BINARY;
    if (count($w) === 3 && $w[0] === 'service' && in_array($w[1], ZS_SERVICES, true) && in_array($w[2], ZS_SERVICE_ACTIONS, true)) {
        if ($w[1] === 'Apache2' && in_array($w[2], array('graceful', 'reload'), true)) {
            // (gently: the pages being served finish; httpd and its settings beside the panel, as the installer put them)
            $top = str_replace('/', '\\', dirname(dirname(__DIR__)));
            return '"' . $top . '\\bin\\apache\\bin\\httpd.exe" -k restart -n Apache2 -f "' . $top . '\\configs\\apache\\httpd.conf"';
        }
        $verb = array('start' => 'Start-Service', 'stop' => 'Stop-Service')[$w[2]] ?? 'Restart-Service';
        return 'powershell.exe -NoProfile -NonInteractive -Command "' . $verb . ' -Name ' . $w[1] . ($verb === 'Start-Service' ? '' : ' -Force') . '"';
    }
    if (count($w) === 1 && $w[0] === 'phpaccount-sync') {
        // (no names come with the request: phpaccount.ps1 reads them from the list only root's jobs may write)
        $tool = dirname(dirname(__DIR__)) . '\\bin\\phpaccount.ps1';
        if (!is_file($tool)) {
            return null;
        }
        return 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' . str_replace('/', '\\', $tool) . '" sync';
    }
    if (count($w) === 1 && $w[0] === 'daemon-run') {
        // (the daemon's own lock keeps it from overlapping a scheduled run; its report for the panel)
        $log = 'C:\\var\\sentora\\logs\\daemon-panel-run.log';
        $logs = zs_logdir();
        if ($logs !== '') {
            $log = $logs . '\\daemon-panel-run.log';
        }
        return 'cmd.exe /c ""' . $php . '" -q "' . $panel . '\\bin\\daemon.php" > "' . $log . '" 2>&1"';
    }
    return null;
}

/**
 * Where the panel's logs go (C:\zpanel\logs\panel beside the panel), '' when not there.
 *
 * @return string
 */
function zs_logdir()
{
    $d = dirname(dirname(__DIR__)) . '\\logs\\panel';
    return is_dir($d) ? str_replace('/', '\\', $d) : '';
}

/**
 * A request's words: plain words only (letters, digits, dash), at most a few.
 *
 * @return string[]|null
 */
function zs_words(array $w)
{
    if (!$w || count($w) > ZS_MAXWORDS) {
        return null;
    }
    foreach ($w as $x) {
        if (!is_string($x) || !preg_match('/^[A-Za-z0-9-]{1,40}$/', $x)) {
            return null;
        }
    }
    return array_values($w);
}

/**
 * The panel's side: leave an allowed request in the queue.
 *
 * @return int Exit code (0 queued, 1 not allowed, not written or no queue: the page says it could not start it).
 */
function zs_enqueue(array $w)
{
    $w = zs_words($w);
    if ($w === null || zs_command($w) === null) {
        fwrite(STDERR, "zsudo: not an allowed action\n");
        return 1;
    }
    if (!is_dir(ZS_DIR . '/new') || !is_dir(ZS_DIR . '/queue')) {
        fwrite(STDERR, "zsudo: the queue of root actions is not there (" . ZS_DIR . ")\n");
        return 1;
    }
    $name = sprintf('%s-%05d-%s', gmdate('YmdHis'), getmypid() % 100000, bin2hex(random_bytes(4)));
    $tmp = ZS_DIR . '/new/' . $name;
    if (@file_put_contents($tmp, implode(' ', $w) . "\n") === false || !@rename($tmp, ZS_DIR . '/queue/' . $name)) {
        @unlink($tmp);
        fwrite(STDERR, "zsudo: the request could not be written\n");
        return 1;
    }
    echo "queued: " . implode(' ', $w) . "\n";
    return 0;
}

/**
 * The service's side: every request in the queue, oldest first, each read once and removed before it is done.
 *
 * @param bool $once One pass (for tests), not the service's loop.
 * @return int
 */
function zs_run_queue($once)
{
    do {
        $files = @scandir(ZS_DIR . '/queue') ?: array();
        foreach ($files as $f) {
            $p = ZS_DIR . '/queue/' . $f;
            if (!preg_match('/^[0-9]{14}-[0-9]{5}-[0-9a-f]{8}$/', $f) || !is_file($p) || is_link($p)) {
                if ($f !== '.' && $f !== '..') {
                    @unlink($p);
                }
                continue;
            }
            $text = (string) @file_get_contents($p, false, null, 0, 256);
            @unlink($p);
            $w = zs_words(preg_split('/ +/', trim($text)));
            $cmd = $w === null ? null : zs_command($w);
            if ($cmd === null) {
                zs_log('refused: ' . substr(preg_replace('/[^\x20-\x7e]/', '?', $text), 0, 120));
                continue;
            }
            zs_log('running: ' . implode(' ', $w));
            if ($w[0] === 'daemon-run') {
                // (in the background: a long run does not hold up the queue)
                pclose(popen('start "" /B ' . $cmd, 'r'));
            } else {
                exec($cmd . ' 2>&1', $out, $rc);
                zs_log('done (' . $rc . '): ' . implode(' ', $w) . ($rc ? ' - ' . substr(implode(' ', $out), 0, 200) : ''));
            }
        }
        if (!$once) {
            sleep(2);
        }
    } while (!$once);
    return 0;
}

/**
 * The service's log (beside the panel's logs).
 *
 * @return void
 */
function zs_log($text)
{
    $d = zs_logdir();
    if ($d !== '') {
        @file_put_contents($d . '\\zsudo.log', date('Y-m-d H:i:s') . ' ' . $text . "\r\n", FILE_APPEND);
    }
}

$args = array_slice($argv, 1);
if ($args === array('--run-queue')) {
    exit(zs_run_queue(false));
}
if ($args === array('--run-queue-once')) {
    exit(zs_run_queue(true));
}
exit(zs_enqueue($args));
