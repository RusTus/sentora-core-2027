<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * The daemon initiator file.
 * @package zpanelx
 * @subpackage core -> daemon
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Changes P.Peyremorte
 * - added timestamp of begin and end of dameon run for logfile
 * - corrected OnDaemonHour that occured on each cron run (5 min,)
 *
 * Updated by RusTus, 2026-11-09
 */
// Only ever run from the command line (cron, sentora-update): bin/.htaccess keeps it off the web,
// and this keeps it so should that file not be read
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

set_time_limit(0);

$rawPath = str_replace("\\", "/", dirname(__FILE__));
$rootPath = str_replace("/bin", "/", $rawPath);
chdir($rootPath);

require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

// /var/lib/sentora must be root's before root opens or writes anything there (the lock below too)
foreach (sys_statedir::Secure() as $statedirChange) {
    echo 'State folder: ' . $statedirChange . fs_filehandler::NewLine();
}

// One run at a time. A run that takes longer than 5 minutes (a big backup, many sites) overlapped
// the next one from cron, and both then wrote the same files and settings. A second run now waits
// for the first (up to 15 minutes), so the tools that run the daemon themselves (sentora-update,
// the move from an old server, the smoke test) still get their run; it is skipped only when the
// other run is still busy after that.
$daemonLock = @fopen('/var/lib/sentora/daemon.lock', 'ce');   // (e: not passed on to programs it starts)
if ($daemonLock !== false) {
    $waited = 0;
    while (!flock($daemonLock, LOCK_EX | LOCK_NB)) {
        if ($waited === 0) {
            echo "Another daemon run is busy: waiting for it (up to 15 minutes)...\n";
        }
        if ($waited >= 900) {
            echo "The other daemon run is still busy after 15 minutes: this run is skipped.\n";
            exit(0);
        }
        sleep(5);
        $waited += 5;
    }
    ftruncate($daemonLock, 0);
    fwrite($daemonLock, getmypid() . "\n");
}

$daemonLog = new debug_logger();
$daemonLog->method = "file";
$daemonLog->logcode = "001";

$dateformat = ctrl_options::GetSystemOption('Sentora_df');

if (!runtime_controller::IsCLI())
    echo "<pre>";
echo "Daemon is now running... (".date($dateformat).")\n";

$daemonLog->detail = "Daemon execution started...";
$daemonLog->writeLog();

runtime_hook::Execute("OnStartDaemonRun");
runtime_hook::Execute("OnDaemonRun");
runtime_hook::Execute("OnEndDaemonRun");

if (time() >= ctrl_options::GetSystemOption('daemon_hourrun') + 3600) {
    ctrl_options::SetSystemOption('daemon_hourrun', time());
    runtime_hook::Execute("OnStartDaemonHour");
    runtime_hook::Execute("OnDaemonHour");
    runtime_hook::Execute("OnEndDaemonHour");
}

if (time() >= ctrl_options::GetSystemOption('daemon_dayrun') + 24*3600) {
    ctrl_options::SetSystemOption('daemon_dayrun', time());
    runtime_hook::Execute("OnStartDaemonDay");
    runtime_hook::Execute("OnDaemonDay");
    runtime_hook::Execute("OnEndDaemonDay");
}

if (time() >= ctrl_options::GetSystemOption('daemon_weekrun') + 7*24*3600) {
    ctrl_options::SetSystemOption('daemon_weekrun', time());
    runtime_hook::Execute("OnStartDaemonWeek");
    runtime_hook::Execute("OnDaemonWeek");
    runtime_hook::Execute("OnEndDaemonWeek");
}

if (time() >= ctrl_options::GetSystemOption('daemon_monthrun') + 30*24*3600) {
    ctrl_options::SetSystemOption('daemon_monthrun', time());
    runtime_hook::Execute("OnStartDaemonMonth");
    runtime_hook::Execute("OnDaemonMonth");
    runtime_hook::Execute("OnEndDaemonMonth");
}
echo "\nDaemon run complete! (" . date($dateformat) . ")\n";

ctrl_options::SetSystemOption('daemon_lastrun', time());

$daemonLog->detail = "Daemon execution completed!";
$daemonLog->writeLog();

if (!runtime_controller::IsCLI())
    echo "</pre>";
exit;
