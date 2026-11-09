<?php

/**
 * Mail Config: applies the SOGo settings (Admin > Mail Config, "SOGo: ...") to
 * /etc/sogo/sogo.conf, the ActiveSync switch in Apache and SOGo's cron jobs,
 * and restarts SOGo when something changed. Runs as root every 5 minutes.
 *
 * Every value is checked (yes/no, a number in range, one of a list, a real
 * time zone) before it is written, and a bad value falls back to the default.
 *
 * Written by RusTus, 2026-11-06
 */
echo fs_filehandler::NewLine() . "START SOGo settings." . fs_filehandler::NewLine();
SentoraApplySOGoSettings();
SentoraSOGoPasswordView();
echo "END SOGo settings." . fs_filehandler::NewLine();

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
        @chgrp($conf . '.new', 'sogo');
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
        exec(escapeshellcmd(sys_platform::Get('apache_ctl')) . ' -t > /dev/null 2>&1 && systemctl reload ' . escapeshellarg((string) ctrl_options::GetSystemOption('apache_sn')) . ' > /dev/null 2>&1');
        echo "ActiveSync " . (strpos($want, 'denied') ? 'turned off' : 'turned on') . "." . fs_filehandler::NewLine();
        $changed = true;
    }

    // SOGo's own jobs: session expiry, email reminders, nightly backup
    $minutes = SentoraSOGoInt('sogo_session_minutes', 60, 0, 10080);
    $cron = "# SOGo jobs, from Admin > Mail Config (written by the Sentora daemon)\n";
    if ($minutes > 0) {
        $cron .= "* * * * *\tsogo\t/usr/sbin/sogo-tool expire-sessions " . $minutes . " > /dev/null 2>&1\n";
    }
    if ($settings['SOGoEnableEMailAlarms'] === 'YES') {
        $cron .= "* * * * *\tsogo\t/usr/sbin/sogo-ealarms-notify > /dev/null 2>&1\n";
    }
    if (SentoraSOGoBool('sogo_backup', true) === 'YES') {
        $cron .= "30 0 * * *\tsogo\t/usr/sbin/sogo-backup > /dev/null 2>&1\n";
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

?>
