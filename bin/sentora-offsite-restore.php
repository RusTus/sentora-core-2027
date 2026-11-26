<?php

/**
 * Restore from the off-server backup on Windows (Admin > Backup Config > Restore): what bin/sentora-panel-restore is on
 * Linux, for the copies bin/sentora-offsite-backup.php makes. Run as SYSTEM by the daemon; it writes the files the page
 * reads (/var/lib/sentora/restore-catalog.json, /var/lib/sentora/panel-restore.json).
 *
 *   php sentora-offsite-restore.php --list                                   the nights in the backup folder
 *   php sentora-offsite-restore.php --account SERVER DATE USER [files] [databases] [exact]
 *
 * One account: its websites come from the folder's up-to-date copy (files\hostdata\USER: the Windows copy keeps one,
 * not one per night), its databases from the night chosen (the ones the account has in the panel now). What the account
 * has now is saved first, in C:\zpanel\data\before-restore (Administrators and SYSTEM only). The mailboxes are one copy
 * of Stalwart's whole store, so they are not restored per account (by hand: stop Stalwart, copy files\mail back).
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
    fwrite(STDERR, "Windows only (Linux: bin/sentora-panel-restore).\n");
    exit(1);
}
const RS_STATE = '/var/lib/sentora/panel-restore.json';
const RS_CATALOG = '/var/lib/sentora/restore-catalog.json';
$mode = isset($argv[1]) ? $argv[1] : '';
if (!in_array($mode, array('--list', '--account'), true)) {
    fwrite(STDERR, "sentora-offsite-restore.php --list | --account SERVER DATE USER [files] [databases] [exact]\n");
    exit(2);
}

/**
 * Merge values into the restore state the page reads.
 *
 * @param array<string, mixed> $v
 * @param bool $fresh Start from nothing.
 * @return void
 */
function rs_state($v, $fresh = false)
{
    $d = $fresh ? array() : json_decode((string) @file_get_contents(RS_STATE), true);
    $d = is_array($d) ? $d : array();
    foreach ($v as $k => $x) {
        $d[$k] = is_string($x) ? substr($x, 0, 400) : $x;
    }
    @file_put_contents(RS_STATE, json_encode($d));
}

/**
 * One line in the log (logs\panel\panel-restore.log).
 *
 * @param string $t
 * @return void
 */
function rs_say($t)
{
    $log = rtrim(str_replace('\\', '/', (string) ctrl_options::GetSystemOption('log_dir')), '/') . '/panel/panel-restore.log';
    @file_put_contents($log, date('Y-m-d H:i:s') . '  ' . $t . "\r\n", FILE_APPEND);
    echo $t, "\n";
}

register_shutdown_function(function () use ($mode) {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        $why = 'stopped: ' . $e['message'] . ' (line ' . $e['line'] . ')';
        rs_say('FAILED: ' . $why);
        rs_state(array('running' => false, 'listing' => false, 'result' => 'failed', 'message' => $why, 'finished' => time()));
    }
});

global $zdbh;
$o = array();
foreach (array('offsite_path', 'offsite_user', 'offsite_password') as $k) {
    $o[$k] = (string) ctrl_options::GetSystemOption($k);
}
$root = str_replace('/', '\\', dirname(dirname(dirname(str_replace('\\', '/', (string) ctrl_options::GetSystemOption('apache_vhost'))))));
$path = rtrim(str_replace('/', '\\', $o['offsite_path']), '\\');
$host = preg_replace('/[^A-Za-z0-9._-]/', '', (string) php_uname('n'));
$host = $host !== '' ? $host : 'sentora';
$fail = function ($why) use ($mode) {
    rs_say('FAILED: ' . $why);
    if ($mode === '--list') {
        @file_put_contents(RS_CATALOG, json_encode(array('time' => time(), 'error' => $why)));
        rs_state(array('listing' => false));
    } else {
        rs_state(array('running' => false, 'result' => 'failed', 'message' => $why, 'finished' => time()));
    }
    exit(1);
};
// (the same rules as the copy: a drive path or a share, never inside C:\zpanel)
if ($path === '' || !preg_match('#^([A-Za-z]:\\\\|\\\\\\\\[A-Za-z0-9._-]+\\\\[A-Za-z0-9$._-]+)([A-Za-z0-9 ._()-]+\\\\?)*$#', $path . '\\')
    || strpos($path, '..') !== false || stripos($path . '\\', $root . '\\') === 0) {
    $fail('No off-server backup folder is set (Off-server backup tab).');
}
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
$dirSize = function ($d) {
    $n = 0;
    if (is_dir($d)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) {
            $n += $f->isFile() ? $f->getSize() : 0;
        }
    }
    return $n;
};

// --- the nights in the folder, of every server that copies there
if ($mode === '--list') {
    rs_state(array('listing' => true, 'step' => 'Reading ' . $path));
    $snaps = array();
    foreach ((array) @scandir($path) as $srv) {
        if (!preg_match('/^[A-Za-z0-9._-]{1,253}$/', (string) $srv) || $srv[0] === '.' || !is_dir($path . '\\' . $srv)) {
            continue;
        }
        $sizes = array();
        foreach ((array) @scandir($path . '\\' . $srv) as $night) {
            $mf = $path . '\\' . $srv . '\\' . $night . '\\manifest.json';
            if (!preg_match('/^20\d{2}-\d{2}-\d{2}$/', (string) $night) || !is_file($mf)) {
                continue;
            }
            $m = json_decode((string) @file_get_contents($mf), true);
            if (!is_array($m)) {
                continue;
            }
            $dbs = array_values(array_filter((array) ($m['databases'] ?? array()), function ($d) { return preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $d); }));
            $accounts = array();
            foreach ((array) ($m['accounts'] ?? array()) as $u) {
                $u = (string) $u;
                if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $u)) {
                    continue;
                }
                if (!isset($sizes[$u])) {
                    $sizes[$u] = (int) round($dirSize($path . '\\' . $srv . '\\files\\hostdata\\' . $u) / 1048576);
                }
                $q = $zdbh->prepare("SELECT v.vh_name_vc FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk WHERE a.ac_user_vc = :u AND a.ac_deleted_ts IS NULL AND v.vh_deleted_ts IS NULL ORDER BY v.vh_name_vc");
                $q->execute(array(':u' => $u));
                $accounts[] = array('username' => $u, 'domains' => $q->fetchAll(PDO::FETCH_COLUMN),
                    'databases' => array_values(array_filter($dbs, function ($d) use ($u) { return stripos($d, $u . '_') === 0; })),
                    'mailboxes' => 0, 'size_mb' => $sizes[$u]);
            }
            $snaps[] = array('server' => $srv, 'date' => $night, 'format' => 2, 'windows' => true, 'sentora' => (string) ($m['version'] ?? ''), 'panel' => '',
                'accounts' => $accounts, 'databases' => $dbs, 'mail' => false);
        }
    }
    usort($snaps, function ($a, $b) { return strcmp($b['date'] . $b['server'], $a['date'] . $a['server']); });
    $done();
    @file_put_contents(RS_CATALOG, json_encode(array('time' => time(), 'target' => $path, 'this_server' => $host, 'snapshots' => $snaps)));
    rs_state(array('listing' => false, 'step' => ''));
    rs_say('read ' . count($snaps) . ' night(s) in ' . $path);
    exit(0);
}

// --- one account
$srv = (string) ($argv[2] ?? '');
$date = (string) ($argv[3] ?? '');
$user = (string) ($argv[4] ?? '');
$parts = array_slice($argv, 5);
if (!preg_match('/^[A-Za-z0-9._-]{1,253}$/', $srv) || $srv[0] === '.' || !preg_match('/^20\d{2}-\d{2}-\d{2}$/', $date) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $user)) {
    $fail('The restore request is not valid.');
}
$lock = @fopen(sys_get_temp_dir() . '\\sentora-offsite-restore.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $fail('A restore is already running.');
}
rs_state(array('running' => true, 'listing' => false, 'snapshot' => $srv . ' ' . $date, 'step' => 'Starting', 'progress_pct' => 0, 'progress_bytes' => 0, 'started' => time()), true);
rs_say('restore of ' . $user . ' from ' . $srv . ' ' . $date . ' (' . implode(', ', $parts) . ')');
$acc = $zdbh->prepare("SELECT ac_id_pk FROM x_accounts WHERE ac_user_vc = :u AND ac_deleted_ts IS NULL");
$acc->execute(array(':u' => $user));
$accId = (int) $acc->fetchColumn();
if ($accId <= 0) {
    $done();
    $fail('The account ' . $user . ' is not on this server: make it in Manage Clients first.');
}
$night = $path . '\\' . $srv . '\\' . $date;
if (!is_file($night . '\\manifest.json')) {
    $done();
    $fail('The night ' . $date . ' of ' . $srv . ' is not in ' . $path . '.');
}
// what the account has now is kept first (Administrators and SYSTEM only)
$saved = $root . '\\data\\before-restore';
if (!is_dir($saved)) {
    @mkdir($saved, 0700, true);
    exec('icacls.exe "' . $saved . '" /inheritance:r /grant:r *S-1-5-18:(OI)(CI)F *S-1-5-32-544:(OI)(CI)F 2>&1');
}
$keep = $saved . '\\' . $user . '-' . date('Ymd-His');
@mkdir($keep . '\\databases', 0700, true);
$problems = array();
$did = array();

if (in_array('databases', $parts, true)) {
    rs_state(array('step' => 'Databases', 'progress_pct' => 5));
    $rootPw = trim((string) @file_get_contents($root . '\\configs\\mysql\\root-password.txt'));
    $dump = str_replace('/', '\\', (string) ctrl_options::GetSystemOption('mysqldump_exe'));
    $mysql = dirname($dump) . '\\mysql.exe';
    $cnf = sys_get_temp_dir() . '\\sentora-restore-' . bin2hex(random_bytes(6)) . '.cnf';
    file_put_contents($cnf, "[client]\r\nuser=root\r\npassword=\"" . addcslashes($rootPw, "\"\\") . "\"\r\nhost=127.0.0.1\r\n");
    $q = $zdbh->prepare("SELECT my_name_vc FROM x_mysql_databases WHERE my_acc_fk = :a AND my_deleted_ts IS NULL");
    $q->execute(array(':a' => $accId));
    $n = 0;
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $db) {
        $db = (string) $db;
        $gz = $night . '\\databases\\' . $db . '.sql.gz';
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $db) || !is_file($gz)) {
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)) {
                $problems[] = 'the database ' . $db . ' is not in that night';
            }
            continue;
        }
        rs_say('database ' . $db);
        rs_state(array('step' => 'Databases: ' . $db));
        exec('"' . $dump . '" --defaults-extra-file="' . $cnf . '" --single-transaction --routines --events --databases ' . $db . ' > "' . $keep . '\\databases\\' . $db . '.sql" 2> NUL', $o2, $rc);
        if ($rc !== 0) {
            $problems[] = 'the database ' . $db . ' could not be saved first, so it was left as it is';
            continue;
        }
        $sql = sys_get_temp_dir() . '\\sentora-restore-' . $db . '.sql';
        $in = gzopen($gz, 'rb');
        $out = fopen($sql, 'wb');
        // (the dump makes the database again; it goes first, so no table the night did not have is left)
        fwrite($out, 'DROP DATABASE IF EXISTS `' . $db . "`;\n");
        while (!gzeof($in)) {
            fwrite($out, (string) gzread($in, 1048576));
        }
        gzclose($in);
        fclose($out);
        exec('cmd.exe /c ""' . $mysql . '" --defaults-extra-file="' . $cnf . '" < "' . $sql . '" 2>&1"', $o3, $rc);
        @unlink($sql);
        if ($rc !== 0) {
            $problems[] = 'the database ' . $db . ' could not be restored (' . substr(trim(implode(' ', $o3)), 0, 200) . '): its copy from before is in ' . $keep;
            continue;
        }
        $n++;
    }
    @unlink($cnf);
    $did[] = $n . ' database(s)';
}

if (in_array('files', $parts, true)) {
    $src = $path . '\\' . $srv . '\\files\\hostdata\\' . $user;
    $dst = $root . '\\hostdata\\' . $user;
    if (!is_dir($src)) {
        $problems[] = 'the backup has no websites of ' . $user;
    } else {
        rs_state(array('step' => 'Saving the websites as they are now', 'progress_pct' => 40));
        exec('robocopy.exe "' . $dst . '" "' . $keep . '\\hostdata" /E /XJ /R:1 /W:1 /NP /NFL /NDL /NJH /NJS 2>&1', $o4, $rc);
        if ($rc >= 8) {
            $problems[] = 'the websites could not be saved first (robocopy ' . $rc . '), so they were left as they are';
        } else {
            rs_state(array('step' => 'Websites', 'progress_pct' => 60));
            // (the files take the account folder's rights, not the copy's; links and junctions are not followed)
            exec('robocopy.exe "' . $src . '" "' . $dst . '" ' . (in_array('exact', $parts, true) ? '/MIR' : '/E') . ' /XJ /COPY:DAT /R:1 /W:1 /NP /NFL /NDL /NJH /NJS 2>&1', $o5, $rc);
            if ($rc >= 8) {
                $problems[] = 'the websites were not copied back in full (robocopy ' . $rc . ')';
            } else {
                $did[] = 'the websites (the latest copy)';
            }
        }
    }
}
$done();
flock($lock, LOCK_UN);
fclose($lock);
$msg = ($did ? 'Restored ' . implode(' and ', $did) . ' of ' . $user . '.' : 'Nothing was restored.') . ' What it had before is in ' . $keep . '.'
    . ($problems ? ' Problems: ' . implode('; ', $problems) . '.' : '');
rs_state(array('running' => false, 'result' => $problems ? ($did ? 'partial' : 'failed') : 'ok', 'message' => $msg, 'finished' => time(), 'step' => '', 'progress_pct' => 100));
rs_say($msg);
exit($problems ? 1 : 0);
