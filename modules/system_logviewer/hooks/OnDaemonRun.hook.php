<?php
/**
 * System Log Viewer, every daemon run (root): add the new error and warning lines of the logs the
 * panel can not read (mail, Dovecot, ClamAV, SOGo, fail2ban's bans) to /var/sentora/logs/problems.log,
 * which it can (see code/problems.php for what is kept and what never is).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-11
 */
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    require_once __DIR__ . '/../code/problems.php';
    $slvJournal = '';
    foreach (array('/usr/bin/journalctl', '/bin/journalctl') as $slvJ) {
        if (is_executable($slvJ)) {
            $slvJournal = $slvJ;
            break;
        }
    }
    echo 'System Log Viewer: problems log, ' . system_logviewer_problems::Run('/var/sentora/logs/problems.log', '/var/lib/sentora-problems', sys_accountphp::PANEL_USER, $slvJournal) . fs_filehandler::NewLine();
}
