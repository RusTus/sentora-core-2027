<?php

/**
 * Mail Config: applies the SOGo settings (Admin > Mail Config, "SOGo: ...") to
 * /etc/sogo/sogo.conf, the ActiveSync switch in Apache and SOGo's cron jobs,
 * and restarts SOGo when something changed. Runs as root every 5 minutes.
 *
 * Every value is checked (yes/no, a number in range, one of a list, a real
 * time zone) before it is written, and a bad value falls back to the default.
 */
echo fs_filehandler::NewLine() . "START SOGo settings." . fs_filehandler::NewLine();
SentoraApplySOGoSettings();
echo "END SOGo settings." . fs_filehandler::NewLine();

function SentoraSOGoOpt($name, $default)
{
    $v = ctrl_options::GetSystemOption($name);
    return ($v === false || $v === null || $v === '') ? $default : trim((string) $v);
}

function SentoraSOGoBool($name, $default)
{
    $v = strtolower(SentoraSOGoOpt($name, $default ? 'true' : 'false'));
    return $v === 'true' ? 'YES' : ($v === 'false' ? 'NO' : ($default ? 'YES' : 'NO'));
}

function SentoraSOGoInt($name, $default, $min, $max)
{
    $v = SentoraSOGoOpt($name, (string) $default);
    return (ctype_digit($v) && (int) $v >= $min && (int) $v <= $max) ? (int) $v : $default;
}

function SentoraSOGoList($name, $default, $allowed)
{
    $v = SentoraSOGoOpt($name, $default);
    return in_array($v, $allowed, true) ? $v : $default;
}

function SentoraApplySOGoSettings()
{
    $conf = '/etc/sogo/sogo.conf';
    if (!is_file('/usr/sbin/sogod') || !is_file($conf)) {
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
    if ($new !== $old) {
        file_put_contents($conf . '.new', $new);
        chmod($conf . '.new', 0640);
        @chgrp($conf . '.new', 'sogo');
        rename($conf . '.new', $conf);
        $restart = true;
        echo "sogo.conf updated." . fs_filehandler::NewLine();
    }

    // ActiveSync on or off (Apache)
    $access = '/etc/sentora/configs/sogo/activesync-access.conf';
    $want = (SentoraSOGoBool('sogo_activesync', true) === 'YES')
        ? "# ActiveSync is on (Admin > Mail Config)\n"
        : "# ActiveSync is off (Admin > Mail Config)\n<Location /Microsoft-Server-ActiveSync>\n    Require all denied\n</Location>\n";
    if (!is_file($access) || file_get_contents($access) !== $want) {
        @mkdir(dirname($access), 0755, true);
        file_put_contents($access, $want);
        exec('apache2ctl -t > /dev/null 2>&1 && systemctl reload apache2 > /dev/null 2>&1');
        echo "ActiveSync " . (strpos($want, 'denied') ? 'turned off' : 'turned on') . "." . fs_filehandler::NewLine();
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
    }

    if ($restart) {
        exec('systemctl restart sogo > /dev/null 2>&1', $out, $rc);
        echo "SOGo restarted" . ($rc === 0 ? '' : ' (FAILED, check /var/log/sogo/sogo.log)') . "." . fs_filehandler::NewLine();
    }
}

?>
