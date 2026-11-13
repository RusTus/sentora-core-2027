<?php

/**
 * Mail Config: applies the SOGo settings (Admin > Mail Config, "SOGo: ...") to
 * /etc/sogo/sogo.conf, the ActiveSync switch in Apache and SOGo's cron jobs,
 * and restarts SOGo when something changed. Runs as root every 5 minutes.
 *
 * Every value is checked (yes/no, a number in range, one of a list, a real
 * time zone) before it is written, and a bad value falls back to the default.
 *
 * Written by RusTus, 2026-11-13
 */
echo fs_filehandler::NewLine() . "START SOGo settings." . fs_filehandler::NewLine();
SentoraApplySOGoSettings();
SentoraSOGoPasswordView();
echo "END SOGo settings." . fs_filehandler::NewLine();
SentoraPostfixMapTypes();
SentoraGlobalSieve();

/**
 * SOGo checks mailbox logins through the view sentora_postfix.sogo_users. Mailbox
 * passwords are now {SHA512-CRYPT} hashes, which keep their prefix in the view (SOGo
 * reads the scheme from it); old {PLAIN-MD5} ones lose it, as before (checked with
 * userPasswordAlgorithm = md5). Views made by older installers strip every prefix.
 *
 * Recreates the view (and says so) when it has no CASE yet; does nothing when there is no view.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function SentoraSOGoPasswordView()
{
    global $zdbh;
    try {
        // (no SOGo view on this server, as where SOGo is not installed: nothing to do, and asked
        // first so the database layer does not print the error of a missing view)
        if ((int) $zdbh->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = 'sentora_postfix' AND TABLE_NAME = 'sogo_users'")->fetchColumn() === 0) {
            return;
        }
        $row = $zdbh->query("SHOW CREATE VIEW sentora_postfix.sogo_users")->fetch(PDO::FETCH_NUM);
    } catch (Exception $e) {
        return; // no SOGo view on this server
    }
    if (!$row || stripos((string) $row[1], 'case') !== false) {
        return;
    }
    $zdbh->exec("CREATE OR REPLACE SQL SECURITY DEFINER VIEW sentora_postfix.sogo_users AS SELECT username AS c_uid, username AS c_name,
        CASE WHEN password LIKE '{PLAIN-MD5}%' THEN SUBSTRING(password, 12) ELSE password END AS c_password,
        COALESCE(NULLIF(name, ''), username) AS c_cn, username AS mail, domain AS c_domain FROM sentora_postfix.mailbox WHERE active = '1'");
    echo "SOGo mailbox view updated for SHA-512 crypt passwords." . fs_filehandler::NewLine();
}

/**
 * Reads a SOGo setting from x_settings, trimmed.
 *
 * @param string $name The setting name (so_name_vc).
 * @param string $default Used when the setting is missing or empty.
 * @return string The value.
 */
function SentoraSOGoOpt($name, $default)
{
    $v = ctrl_options::GetSystemOption($name);
    return ($v === false || $v === null || $v === '') ? $default : trim((string) $v);
}

/**
 * Reads a yes/no SOGo setting ('true' / 'false').
 *
 * @param string $name The setting name.
 * @param bool $default Used when the setting is missing or not 'true'/'false'.
 * @return string 'YES' or 'NO', as sogo.conf writes it.
 */
function SentoraSOGoBool($name, $default)
{
    $v = strtolower(SentoraSOGoOpt($name, $default ? 'true' : 'false'));
    return $v === 'true' ? 'YES' : ($v === 'false' ? 'NO' : ($default ? 'YES' : 'NO'));
}

/**
 * Reads a whole-number SOGo setting within a range.
 *
 * @param string $name The setting name.
 * @param int $default Used when the setting is missing, not a number or out of range.
 * @param int $min Lowest allowed value.
 * @param int $max Highest allowed value.
 * @return int The value.
 */
function SentoraSOGoInt($name, $default, $min, $max)
{
    $v = SentoraSOGoOpt($name, (string) $default);
    return (ctype_digit($v) && (int) $v >= $min && (int) $v <= $max) ? (int) $v : $default;
}

/**
 * Reads a SOGo setting that must be one of a list.
 *
 * @param string $name The setting name.
 * @param string $default Used when the setting is missing or not in the list.
 * @param string[] $allowed The allowed values.
 * @return string The value.
 */
function SentoraSOGoList($name, $default, $allowed)
{
    $v = SentoraSOGoOpt($name, $default);
    return in_array($v, $allowed, true) ? $v : $default;
}

/**
 * Writes the SOGo settings to sogo.conf, the ActiveSync switch and SOGo's cron jobs.
 *
 * Rewrites only the Sentora area of /etc/sogo/sogo.conf, /etc/sentora/configs/sogo/
 * activesync-access.conf (reloads Apache) and /etc/cron.d/sentora-sogo when they
 * changed, restarts SOGo when sogo.conf changed, and echoes what it did.
 *
 * @return void
 */
function SentoraApplySOGoSettings()
{
    $conf = sys_platform::Get('sogo_conf');
    if (!is_file(sys_platform::Get('sogo_bin')) || !is_file($conf)) {
        echo "SOGo is not installed." . fs_filehandler::NewLine();
        return;
    }
    $languages = array('English', 'French', 'German', 'SpanishSpain', 'SpanishArgentina', 'Italian', 'Dutch', 'BrazilianPortuguese',
        'Portuguese', 'Polish', 'Swedish', 'Danish', 'NorwegianBokmal', 'Finnish', 'Czech', 'Russian', 'Ukrainian', 'Japanese',
        'ChineseChina', 'Hungarian', 'TurkishTurkey', 'Catalan');
    $refresh = array('manually', 'every_minute', 'every_2_minutes', 'every_5_minutes', 'every_10_minutes', 'every_20_minutes',
        'every_30_minutes', 'once_per_hour');
    $tz = SentoraSOGoOpt('sogo_timezone', 'UTC');
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        $tz = 'UTC';
    }
    $ping = SentoraSOGoInt('sogo_as_ping_seconds', 3540, 60, 3540);
    $failed = SentoraSOGoInt('sogo_failed_logins', 10, 0, 1000);
    $settings = array(
        'SOGoLanguage' => SentoraSOGoList('sogo_language', 'English', $languages),
        'SOGoTimeZone' => '"' . $tz . '"',
        'SOGoFirstDayOfWeek' => SentoraSOGoList('sogo_first_day', '1', array('0', '1', '6')),
        'SOGoMaximumMessageSizeLimit' => SentoraSOGoInt('sogo_max_message_kb', 20000, 0, 1048576),
        'SOGoVacationEnabled' => SentoraSOGoBool('sogo_vacation', true),
        'SOGoForwardEnabled' => SentoraSOGoBool('sogo_forward', true),
        'SOGoSieveScriptsEnabled' => SentoraSOGoBool('sogo_filters', true),
        'SOGoAppointmentSendEMailNotifications' => SentoraSOGoBool('sogo_invitations', true),
        'SOGoEnableEMailAlarms' => SentoraSOGoBool('sogo_email_alarms', false),
        'SOGoMailAuxiliaryUserAccountsEnabled' => SentoraSOGoBool('sogo_extra_accounts', false),
        'SOGoMailCustomFromEnabled' => SentoraSOGoBool('sogo_custom_from', false),
        'SOGoMaximumFailedLoginCount' => $failed,
        'SOGoMaximumFailedLoginInterval' => 600,
        'SOGoFailedLoginBlockInterval' => 60 * SentoraSOGoInt('sogo_block_minutes', 5, 1, 1440),
        'SOGoRefreshViewCheck' => SentoraSOGoList('sogo_refresh', 'every_5_minutes', $refresh),
        'WOWorkersCount' => SentoraSOGoInt('sogo_workers', 5, 1, 50),
        'SxVMemLimit' => SentoraSOGoInt('sogo_memory_mb', 512, 128, 4096),
        'SOGoMaximumPingInterval' => $ping,
        'SOGoMaximumSyncInterval' => $ping,
        'SOGoMaximumSyncWindowSize' => SentoraSOGoInt('sogo_as_window', 100, 25, 500),
    );
    $block = "  /* --- Settings from Admin > Mail Config (rewritten by the Sentora daemon) --- */\n";
    foreach ($settings as $k => $v) {
        $block .= "  " . $k . " = " . $v . ";\n";
    }
    $block .= "  /* --- end of Sentora settings --- */\n";

    $old = (string) file_get_contents($conf);
    $start = strpos($old, "  /* --- Settings from Admin > Mail Config");
    $end = strpos($old, "  /* --- end of Sentora settings --- */\n");
    if ($start === false || $end === false || $end < $start) {
        echo "sogo.conf has no Sentora settings area, left unchanged." . fs_filehandler::NewLine();
        return;
    }
    $new = substr($old, 0, $start) . $block . substr($old, $end + strlen("  /* --- end of Sentora settings --- */\n"));
    $restart = false;
    $changed = false;
    if ($new !== $old) {
        file_put_contents($conf . '.new', $new);
        chmod($conf . '.new', 0640);
        @chgrp($conf . '.new', sys_platform::Get('sogo_user'));
        rename($conf . '.new', $conf);
        $restart = true;
        echo "sogo.conf updated." . fs_filehandler::NewLine();
        $changed = true;
    }

    // ActiveSync on or off (Apache)
    $access = '/etc/sentora/configs/sogo/activesync-access.conf';
    $want = (SentoraSOGoBool('sogo_activesync', true) === 'YES')
        ? "# ActiveSync is on (Admin > Mail Config)\n"
        : "# ActiveSync is off (Admin > Mail Config)\n<Location /Microsoft-Server-ActiveSync>\n    Require all denied\n</Location>\n";
    if (!is_file($access) || file_get_contents($access) !== $want) {
        @mkdir(dirname($access), 0755, true);
        file_put_contents($access, $want);
        exec(ctrl_system::OwnFiles(escapeshellcmd(sys_platform::Get('apache_ctl')) . ' -t > /dev/null 2>&1 && systemctl reload ' . escapeshellarg((string) ctrl_options::GetSystemOption('apache_sn')) . ' > /dev/null 2>&1'));
        echo "ActiveSync " . (strpos($want, 'denied') ? 'turned off' : 'turned on') . "." . fs_filehandler::NewLine();
        $changed = true;
    }

    // SOGo's own jobs: session expiry, email reminders, nightly backup
    $minutes = SentoraSOGoInt('sogo_session_minutes', 60, 0, 10080);
    // (SOGo's user and the folder of its tools on this Linux system: Sentora's settings, sys_platform)
    $sogoUser = sys_platform::Get('sogo_user');
    $sogoTools = rtrim(sys_platform::Get('sogo_tool_dir'), '/');
    $cron = "# SOGo jobs, from Admin > Mail Config (written by the Sentora daemon)\n";
    if ($minutes > 0) {
        $cron .= "* * * * *\t" . $sogoUser . "\t" . $sogoTools . "/sogo-tool expire-sessions " . $minutes . " > /dev/null 2>&1\n";
    }
    if ($settings['SOGoEnableEMailAlarms'] === 'YES') {
        $cron .= "* * * * *\t" . $sogoUser . "\t" . $sogoTools . "/sogo-ealarms-notify > /dev/null 2>&1\n";
    }
    if (SentoraSOGoBool('sogo_backup', true) === 'YES') {
        $cron .= "30 0 * * *\t" . $sogoUser . "\t" . $sogoTools . "/sogo-backup > /dev/null 2>&1\n";
    }
    $cronFile = '/etc/cron.d/sentora-sogo';
    if (!is_file($cronFile) || file_get_contents($cronFile) !== $cron) {
        file_put_contents($cronFile, $cron);
        chmod($cronFile, 0644);
        echo "SOGo jobs updated." . fs_filehandler::NewLine();
        $changed = true;
    }

    if (!$changed) {
        echo "SOGo settings unchanged (ActiveSync " . (strpos($want, 'denied') ? 'off' : 'on') . "), nothing to do." . fs_filehandler::NewLine();
    }
    if ($restart) {
        exec('systemctl restart ' . escapeshellarg(sys_platform::Get('sogo_service')) . ' > /dev/null 2>&1', $out, $rc);
        echo "SOGo restarted" . ($rc === 0 ? '' : ' (FAILED, check /var/log/sogo/sogo.log)') . "." . fs_filehandler::NewLine();
    }
}

/**
 * Sentora's mail filter for every mailbox (spam into the "Spam" folder): Dovecot's own folder holds a
 * copy of it, kept the same as Sentora's file. The installers used to put a link there, which Dovecot's
 * filter (Pigeonhole) refuses ("Active sieve script symlink ... is broken: Invalid/unknown path to
 * storage"): the filter then did not run and spam stayed in the inbox. Root writes the copy without
 * following anything in that folder (the mail user's): a new file made exclusively, moved into place.
 *
 * @return void
 */
function SentoraGlobalSieve()
{
    $nl = fs_filehandler::NewLine();
    $src = '/etc/sentora/configs/dovecot2/globalfilter.sieve';
    $dst = '/var/sentora/sieve/globalfilter.sieve';
    $dir = dirname($dst);
    if (!is_file($src) || is_link($src) || !is_dir($dir) || is_link($dir)) {
        return;
    }
    $want = (string) file_get_contents($src);
    if (is_file($dst) && !is_link($dst) && (string) file_get_contents($dst) === $want) {
        return;
    }
    $wasLink = is_link($dst);
    $tmp = $dir . '/.globalfilter.sieve.' . getmypid();
    $fh = @fopen($tmp, 'x');   // (x: made new, never through an existing name or link)
    if ($fh === false) {
        return;
    }
    fwrite($fh, $want);
    fclose($fh);
    @chmod($tmp, 0644);
    @chown($tmp, fileowner($dir));
    @chgrp($tmp, filegroup($dir));
    if (!@rename($tmp, $dst)) {
        @unlink($tmp);
        return;
    }
    @unlink($dir . '/globalfilter.svbin');   // (its compiled copy: made again by Dovecot)
    echo 'Dovecot: the mail filter for every mailbox (spam into Spam) is ' . ($wasLink ? 'now a file (Dovecot refused the link to it, so it did not run)' : 'up to date again') . '.' . $nl;
}

/**
 * Postfix's lookup tables: Sentora's main.cf names hash: and btree: tables (Postfix's classic
 * types). A Postfix built without them (AlmaLinux 10 and Fedora dropped Berkeley DB) can not
 * read those: the aliases (also checked for every local address) and the TLS session caches
 * fail with "unsupported dictionary type". Here, when Postfix lists a type as missing, every
 * main.cf line naming it gets this Postfix's own default type instead (postconf -h
 * default_database_type), the aliases are built again and Postfix reloads. Decided by what
 * Postfix itself supports, so it fits any system; on one with both types nothing changes.
 *
 * @return void
 */
function SentoraPostfixMapTypes()
{
    $nl = fs_filehandler::NewLine();
    $dir = rtrim(sys_platform::Get('postfix_cmd_dir'), '/');
    $pc = $dir . '/postconf';
    if ($dir === '' || !is_executable($pc)) {
        return;
    }
    $types = array();
    exec(escapeshellarg($pc) . ' -m 2>/dev/null', $types);
    $types = array_map('trim', $types);
    $missing = array_values(array_diff(array('hash', 'btree'), $types));
    if (count($types) === 0 || count($missing) === 0) {
        return;
    }
    $def = trim((string) shell_exec(escapeshellarg($pc) . ' -h default_database_type 2>/dev/null'));
    $confDir = trim((string) shell_exec(escapeshellarg($pc) . ' -h config_directory 2>/dev/null'));
    $file = realpath($confDir . '/main.cf');
    // (this Postfix's own default may be one of the missing types too, as on AlmaLinux 10: then the
    // first one it has of lmdb and cdb, the types that need no Berkeley DB)
    $setDefault = false;
    if ($def === '' || !in_array($def, $types, true)) {
        $setDefault = true;
        $def = '';
        foreach (array('lmdb', 'cdb') as $t) {
            if (in_array($t, $types, true)) {
                $def = $t;
                break;
            }
        }
    }
    if ($def === '' || $file === false || !is_file($file)) {
        echo 'Postfix: main.cf names ' . implode('/', $missing) . ' tables, which this Postfix can not read, and it has no lmdb or cdb to use instead.' . $nl;
        return;
    }
    $lines = explode("\n", (string) file_get_contents($file));
    $changed = 0;
    $maps = array();
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*#/', $line)) {
            continue;
        }
        $new = preg_replace_callback('/(?<![A-Za-z0-9_])(' . implode('|', $missing) . '):(\S*)/', function ($m) use ($def, &$maps) {
            $maps[] = rtrim($m[2], ',');
            return $def . ':' . $m[2];
        }, $line);
        if ($new !== $line) {
            $lines[$i] = $new;
            $changed++;
        }
    }
    // (a default that names a missing type: commands without a type, such as newaliases' tables,
    // would fail too)
    if ($setDefault) {
        $found = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*default_database_type\s*=\s*(\S*)/', $line, $dm)) {
                $found = true;
                if ($dm[1] !== $def) {
                    $lines[$i] = 'default_database_type = ' . $def;
                    $changed++;
                }
            }
        }
        if (!$found) {
            array_splice($lines, count($lines) - (end($lines) === '' ? 1 : 0), 0, array('', '# (set by Sentora: this Postfix has no ' . implode('/', $missing) . ')', 'default_database_type = ' . $def));
            $changed++;
        }
    }
    if ($changed === 0) {
        return;
    }
    if (!is_file($file . '.sentora-before-maps')) {
        @copy($file, $file . '.sentora-before-maps');
    }
    if (@file_put_contents($file, implode("\n", $lines)) === false) {
        echo 'Postfix: main.cf could not be written (' . implode(', ', $missing) . ' tables stay unreadable).' . $nl;
        return;
    }
    // build the tables again in the new type: the aliases, and any other table with a source file
    // (the TLS session caches are made by Postfix itself)
    exec(escapeshellarg($dir . '/newaliases') . ' 2>&1', $o1);
    $aliasDb = trim((string) shell_exec(escapeshellarg($pc) . ' -h alias_database 2>/dev/null'));
    foreach (array_unique($maps) as $m) {
        if ($m !== '' && $m[0] === '/' && is_file($m) && strpos($aliasDb, $m) === false && strpos($m, '_scache') === false) {
            exec(escapeshellarg($dir . '/postmap') . ' ' . escapeshellarg($def . ':' . $m) . ' 2>&1', $o2);
        }
    }
    exec(escapeshellarg($dir . '/postfix') . ' reload 2>&1', $o3, $rc);
    echo 'Postfix: ' . $changed . ' main.cf line(s) moved from ' . implode('/', $missing) . ' tables (not in this Postfix) to ' . $def
        . ($rc === 0 ? ', Postfix reloaded.' : ', reload failed: ' . implode(' ', array_slice($o3, -1))) . $nl;
}

