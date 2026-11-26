<?php

/**
 * Off-server backup on Windows (Admin > Backup Config > Off-server backup, "A folder or network share"): what
 * bin/sentora-offsite-backup is on Linux. Run as SYSTEM by the daemon (at the time set, and for the page's Test
 * connection and Back up now); it writes its state where the page reads it (/var/lib/sentora/offsite-backup.json).
 *
 *   php sentora-offsite-backup.php --check   can the folder be written, and how full is it
 *   php sentora-offsite-backup.php --now     a copy now (also when switched off)
 *   php sentora-offsite-backup.php --cron    the nightly copy, only when switched on
 *
 * Into <folder>\<this server>\:
 *   <date>\databases\<name>.sql.gz   every database, one file each (the panel's own included), each night
 *   <date>\config\configs.zip        C:\zpanel\configs: the panel's settings and keys (keep the folder trusted)
 *   <date>\manifest.json             what the night holds: version, server, accounts
 *   files\hostdata\...               every account's folder, websites and their own dated backups (the latest copy;
 *                                    links and junctions are not followed)
 *   files\mail\...                   the mailboxes, when switched on (Stalwart stops for the copy, then starts again)
 * The oldest nights beyond "Nights kept" are removed.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-26
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
set_time_limit(0);
chdir(str_replace('/bin', '/', str_replace('\\', '/', __DIR__)));
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
if (!sys_versions::IsWindows()) {
    fwrite(STDERR, "Windows only (Linux: bin/sentora-offsite-backup).\n");
    exit(1);
}
$mode = isset($argv[1]) ? $argv[1] : '';
if (!in_array($mode, array('--check', '--now', '--cron'), true)) {
    fwrite(STDERR, "sentora-offsite-backup.php --check | --now | --cron\n");
    exit(2);
}

const OB_STATE = '/var/lib/sentora/offsite-backup.json';

/**
 * Merge values into the state file the page reads.
 *
 * @param array<string, mixed> $v
 * @return void
 */
function ob_state($v)
{
    $d = json_decode((string) @file_get_contents(OB_STATE), true);
    $d = is_array($d) ? $d : array();
    foreach ($v as $k => $x) {
        $d[$k] = is_string($x) ? substr($x, 0, 300) : $x;
    }
    @file_put_contents(OB_STATE, json_encode($d));
}

/**
 * One line in the log (logs\panel\offsite-backup.log).
 *
 * @param string $t
 * @return void
 */
function ob_say($t)
{
    $log = rtrim(str_replace('\\', '/', (string) ctrl_options::GetSystemOption('log_dir')), '/') . '/panel/offsite-backup.log';
    @file_put_contents($log, date('Y-m-d H:i:s') . '  ' . $t . "\r\n", FILE_APPEND);
    echo $t, "\n";
}

$o = array();
foreach (array('offsite_enabled', 'offsite_path', 'offsite_user', 'offsite_password', 'offsite_keep', 'offsite_mail') as $k) {
    $o[$k] = (string) ctrl_options::GetSystemOption($k);
}
if ($mode === '--cron' && $o['offsite_enabled'] !== 'true') {
    exit(0);
}
$root = str_replace('/', '\\', dirname(dirname(dirname(str_replace('\\', '/', (string) ctrl_options::GetSystemOption('apache_vhost'))))));
$path = rtrim(str_replace('/', '\\', $o['offsite_path']), '\\');
$host = preg_replace('/[^A-Za-z0-9._-]/', '', (string) php_uname('n'));
$host = $host !== '' ? $host : 'sentora';

/**
 * Whether a folder may be the destination: a drive path or a network share, plain characters only, never inside
 * C:\zpanel (the copies would copy themselves).
 *
 * @param string $p
 * @param string $root
 * @return bool
 */
function ob_path_ok($p, $root)
{
    if (!preg_match('#^([A-Za-z]:\\\\|\\\\\\\\[A-Za-z0-9._-]+\\\\[A-Za-z0-9$._-]+)([A-Za-z0-9 ._()-]+\\\\?)*$#', $p . '\\') || strpos($p, '..') !== false) {
        return false;
    }
    return stripos($p . '\\', $root . '\\') !== 0;
}

$fail = function ($why) use ($mode) {
    ob_say('FAILED: ' . $why);
    if ($mode === '--check') {
        ob_state(array('check_time' => time(), 'check_ok' => false, 'check_message' => $why));
    } else {
        ob_state(array('last_run' => time(), 'result' => 'failed', 'message' => $why, 'step' => ''));
    }
    exit(1);
};
if ($path === '' || !ob_path_ok($path, $root)) {
    $fail('The folder is not one a copy can go to (a drive path like D:\\Backups or a share like \\\\nas\\backups, not inside ' . $root . ').');
}

// a network share with its own user: connected for this run only (as SYSTEM: the server's own account otherwise)
$share = '';
if (preg_match('#^(\\\\\\\\[^\\\\]+\\\\[^\\\\]+)#', $path, $m) && $o['offsite_user'] !== '') {
    $share = $m[1];
    $pw = $o['offsite_password'] !== '' ? (string) ctrl_secret::Decrypt($o['offsite_password']) : '';
    if (!preg_match('/^[A-Za-z0-9._\\\\@-]{1,104}$/', $o['offsite_user']) || preg_match('/["\r\n]/', $pw)) {
        $fail('The share\'s user or password has characters that can not be passed on.');
    }
    exec('net use ' . $share . ' /delete /y > NUL 2>&1');
    exec('net use ' . $share . ' "' . $pw . '" /user:"' . $o['offsite_user'] . '" /persistent:no 2>&1', $out, $rc);
    if ($rc !== 0) {
        $fail('The share ' . $share . ' refused the login: ' . trim(implode(' ', $out)));
    }
}
$done = function () use ($share) {
    if ($share !== '') {
        exec('net use ' . $share . ' /delete /y > NUL 2>&1');
    }
};
$base = $path . '\\' . $host;
if (!is_dir($base) && !@mkdir($base, 0700, true)) {
    $done();
    $fail('The folder ' . $base . ' could not be made (does ' . $path . ' exist, and may this server write there?).');
}
$space = function () use ($path, $base) {
    $size = (float) @disk_total_space($path);
    $free = (float) @disk_free_space($path);
    $nights = count(array_filter((array) @scandir($base), function ($n) { return preg_match('/^20\d{2}-\d{2}-\d{2}$/', (string) $n); }));
    return array('space_size' => $size, 'space_free' => $free, 'space_used' => 0, 'nights' => $nights);
};

if ($mode === '--check') {
    $t = $base . '\\.sentora-write-test';
    $ok = @file_put_contents($t, 'ok') === 2 && @unlink($t);
    $done();
    if (!$ok) {
        $fail('Nothing can be written in ' . $base . '.');
    }
    ob_state(array_merge(array('check_time' => time(), 'check_ok' => true, 'check_message' => $base . ' can be written'), $space()));
    ob_say('check: ' . $base . ' can be written');
    exit(0);
}

// --- a copy (never two at once)
$lock = @fopen(sys_get_temp_dir() . '\\sentora-offsite-backup.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $done();
    exit(0);
}
$started = time();
ob_state(array('copy_started' => $started, 'step' => 'Databases', 'progress_pct' => 0, 'progress_bytes' => 0, 'progress_rate' => ''));
ob_say('copy to ' . $base . ' started');
$night = $base . '\\' . date('Y-m-d');
foreach (array($night . '\\databases', $night . '\\config', $base . '\\files') as $d) {
    if (!is_dir($d) && !@mkdir($d, 0700, true)) {
        $done();
        $fail('The folder ' . $d . ' could not be made.');
    }
}
$problems = array();

// 1. every database (MySQL's root, whose password only Administrators and SYSTEM may read)
$rootPw = trim((string) @file_get_contents($root . '\\configs\\mysql\\root-password.txt'));
$dump = str_replace('/', '\\', (string) ctrl_options::GetSystemOption('mysqldump_exe'));
$dbs = array();
try {
    $my = new PDO('mysql:host=127.0.0.1', 'root', $rootPw, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    foreach ($my->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN) as $db) {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $db) && !in_array(strtolower($db), array('information_schema', 'performance_schema', 'sys'), true)) {
            $dbs[] = $db;
        }
    }
    $my = null;
} catch (Exception $e) {
    $problems[] = 'the database list could not be read (' . $e->getMessage() . ')';
}
$cnf = sys_get_temp_dir() . '\\sentora-offsite-' . bin2hex(random_bytes(6)) . '.cnf';
file_put_contents($cnf, "[client]\r\nuser=root\r\npassword=\"" . addcslashes($rootPw, "\"\\") . "\"\r\nhost=127.0.0.1\r\n");
foreach ($dbs as $i => $db) {
    ob_state(array('step' => 'Databases: ' . $db, 'progress_pct' => (int) (30 * $i / max(1, count($dbs)))));
    $sql = sys_get_temp_dir() . '\\sentora-offsite-' . $db . '.sql';
    exec('"' . $dump . '" --defaults-extra-file="' . $cnf . '" --single-transaction --routines --events --databases ' . $db . ' > "' . $sql . '" 2> NUL', $o2, $rc);
    if ($rc !== 0 || !is_file($sql)) {
        $problems[] = 'the database ' . $db . ' could not be dumped';
        @unlink($sql);
        continue;
    }
    $in = fopen($sql, 'rb');
    $gz = gzopen($night . '\\databases\\' . $db . '.sql.gz', 'wb6');
    while (!feof($in)) {
        gzwrite($gz, (string) fread($in, 1048576));
    }
    fclose($in);
    gzclose($gz);
    @unlink($sql);
}
@unlink($cnf);

// 2. the panel's settings and keys
ob_state(array('step' => 'Settings', 'progress_pct' => 32));
@unlink($night . '\\config\\configs.zip');
if (!sys_backup::ZipFolder($root . '\\configs', 'configs', $night . '\\config\\configs.zip')) {
    $problems[] = 'the settings could not be zipped';
}

// 3. the accounts' folders (the latest copy; their own dated backups come with them)
$robo = function ($from, $to) use (&$problems) {
    exec('robocopy.exe "' . $from . '" "' . $to . '" /MIR /XJ /R:1 /W:1 /NP /NFL /NDL /NJH /NJS 2>&1', $out, $rc);
    if ($rc >= 8) {
        $problems[] = basename($from) . ' was not copied in full (robocopy ' . $rc . ')';
    }
};
ob_state(array('step' => 'Websites and their backups', 'progress_pct' => 40));
$robo($root . '\\hostdata', $base . '\\files\\hostdata');

// 4. the mailboxes: Stalwart stops for the copy (its store is not copied while it writes), then starts again
if ($o['offsite_mail'] === 'true' && is_dir($root . '\\data\\mail')) {
    ob_state(array('step' => 'Mailboxes', 'progress_pct' => 75));
    $svc = (string) ctrl_options::GetSystemOption('mail_service');
    if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $svc)) {
        exec('powershell.exe -NoProfile -NonInteractive -Command "Stop-Service -Name ' . $svc . ' -Force"');
        $robo($root . '\\data\\mail', $base . '\\files\\mail');
        exec('powershell.exe -NoProfile -NonInteractive -Command "Start-Service -Name ' . $svc . '"');
    } else {
        $problems[] = 'the mail service is not named in the settings: the mailboxes were not copied';
    }
}

// 5. what the night holds
global $zdbh;
$accounts = array();
foreach ($zdbh->query("SELECT ac_user_vc FROM x_accounts WHERE ac_deleted_ts IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $u) {
    $accounts[] = (string) $u;
}
file_put_contents($night . '\\manifest.json', json_encode(array('format' => 'windows-1', 'version' => trim((string) @file_get_contents('VERSION')), 'server' => $host,
    'date' => date('c'), 'databases' => $dbs, 'accounts' => $accounts, 'mail' => $o['offsite_mail'] === 'true', 'problems' => $problems), JSON_PRETTY_PRINT));

// 6. the oldest nights go
$keep = max(1, min(365, (int) $o['offsite_keep']));
$nights = array_values(array_filter((array) @scandir($base), function ($n) { return preg_match('/^20\d{2}-\d{2}-\d{2}$/', (string) $n); }));
sort($nights);
foreach (array_slice($nights, 0, max(0, count($nights) - $keep)) as $old) {
    exec('cmd.exe /c rmdir /s /q "' . $base . '\\' . $old . '"');
    ob_say('removed the night ' . $old);
}
$used = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $used += $f->isFile() ? $f->getSize() : 0;
}
$st = $space();
$st['space_used'] = $used;
$done();
flock($lock, LOCK_UN);
fclose($lock);
if ($problems) {
    ob_state(array_merge($st, array('last_run' => time(), 'result' => 'failed', 'message' => implode('; ', $problems), 'step' => '')));
    ob_say('copy with problems: ' . implode('; ', $problems));
    exit(1);
}
ob_state(array_merge($st, array('last_run' => time(), 'last_ok' => time(), 'result' => 'ok', 'message' => count($dbs) . ' database(s), ' . count($accounts) . ' account(s) in ' . (time() - $started) . ' s', 'step' => '', 'progress_pct' => 100)));
ob_say('copy done: ' . count($dbs) . ' database(s), ' . count($accounts) . ' account(s)');
