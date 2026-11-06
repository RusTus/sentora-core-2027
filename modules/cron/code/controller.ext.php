<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 *
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Change P.Peyremorte:
 * - cleaned WriteCronFile() (removed duplicate parts).
 * - reformated header inserted in crontab file (heading spaces and wrong EOL encoding)
 * - removed daemon task that is handled by independant crontab /etc/cron.d/zdaemon (linux)
 */
require_once dirname(__FILE__) . '/cronsafe.php';

/**
 * Cron Manager: an account's scheduled PHP scripts. A script is picked from the
 * account's own public_html in a file browser (code/files.php); everything is checked
 * again here when it is saved (cron_safe). The daemon writes the jobs to
 * /etc/cron.d/sentora-accounts within 5 minutes, each running as the account's own
 * user, limited to its folder, with the PHP version of the domain the script is in
 * (sys_accountphp::SyncCron). Server paths are never shown: only public_html/...
 */
class module_controller extends ctrl_module
{
    /** Most tasks one account can have. */
    const MAX_JOBS = 50;

    /**
     * @var array<int, array<int, string>> Messages for getResult(): array('ok'|'error', English text).
     */
    static $msg = array();

    /**
     * Escapes text for HTML.
     *
     * @param mixed $s The value (cast to string).
     * @return string The escaped text.
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translates text into the user's language.
     *
     * @param string $s The English text.
     * @return string The translated text.
     */
    static function t($s)
    {
        return ui_language::translate($s);
    }

    /**
     * Returns the logged-in user's details.
     *
     * @return array<string, mixed> From ctrl_users::GetUserDetail() ('userid', 'username', ...).
     */
    static function Me()
    {
        return ctrl_users::GetUserDetail();
    }

    /**
     * Returns the path shown for a job: relative to public_html, from what was stored.
     *
     * @param array<string,mixed> $row The x_cronjobs row (uses 'ct_fullpath_vc' and 'ct_script_vc').
     * @param string $username The account's user name.
     * @return string The path, starting with 'public_html/'.
     */
    static function ShownPath($row, $username)
    {
        $root = pd_safe::PublicRoot($username) . '/';
        $full = (string) $row['ct_fullpath_vc'];
        $rel = (strpos($full, $root) === 0) ? substr($full, strlen($root)) : ltrim((string) $row['ct_script_vc'], '/');
        return 'public_html/' . $rel;
    }

    /**
     * Returns which PHP runs a job: the version of the domain its script is in, else the system PHP.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $accountId The account ID.
     * @param string $username The account's user name.
     * @param string $full The script's full server path.
     * @return string For example 'PHP 8.3'.
     */
    static function PhpFor($accountId, $username, $full)
    {
        global $zdbh;
        $root = pd_safe::PublicRoot($username) . '/';
        $best = '';
        $version = '';
        $q = $zdbh->prepare("SELECT vh_directory_vc, vh_php_version_vc FROM x_vhosts WHERE vh_acc_fk = :a AND vh_deleted_ts IS NULL AND vh_type_in IN (1, 2)");
        $q->execute(array(':a' => (int) $accountId));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $dir = $root . trim((string) $d['vh_directory_vc'], '/') . '/';
            if (strpos($full, $dir) === 0 && strlen($dir) > strlen($best)) {
                $best = $dir;
                $version = (string) $d['vh_php_version_vc'];
            }
        }
        $default = sys_phpversions::DefaultVersion();
        if ($version !== '' && $version !== $default && sys_phpversions::IsEnabled($version) && is_executable(sys_phpversions::BASE_DIR . $version . '/bin/php')) {
            return 'PHP ' . $version;
        }
        return 'PHP ' . $default;
    }

    /**
     * Builds the table of the account's tasks (<@ Crons @>), with each task's last run.
     *
     * Also includes the output of the task given by ?log=<id> (see Output()).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML.
     */
    static function getCrons()
    {
        global $zdbh;
        $me = self::Me();
        $q = $zdbh->prepare("SELECT * FROM x_cronjobs WHERE ct_acc_fk = :a AND ct_deleted_ts IS NULL ORDER BY ct_id_pk");
        $q->execute(array(':a' => (int) $me['userid']));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $out = '<h2>' . self::t('Current tasks') . '</h2>';
        if (!$rows) {
            return $out . '<p class="text-muted">' . self::t('You have no scheduled tasks yet.') . '</p>';
        }
        $min = sys_accountphp::CronSettings()['cron_min_interval'];
        $out .= '<table class="table table-striped"><tr><th>' . self::t('Script') . '</th><th>' . self::t('Runs') . '</th><th>' . self::t('With') . '</th><th>'
            . self::t('Last run') . '</th><th>' . self::t('Description') . '</th><th></th></tr>';
        foreach ($rows as $r) {
            $slower = (sys_accountphp::CronEvery((string) $r['ct_timing_vc']) < $min)
                ? '<br><span class="text-muted">' . self::h(self::t('the server runs it every') . ' ' . $min . ' ' . self::t('minutes')) . '</span>' : '';
            $log = cron_safe::ReadLog($me['username'], $me['userid'], (int) $r['ct_id_pk'], 4096);
            if ($log === false) {
                $last = '<span class="text-muted">' . self::t('not yet') . '</span>';
            } else {
                list($state, $text) = cron_safe::LogResult($log[0]);
                $icon = ($state === 'ok') ? ui_status::Ok('Finished') : (($state === 'running') ? ui_status::Warn('Running') : ui_status::Bad('Problem'));
                $last = $icon . ' ' . self::h(date('Y-m-d H:i', (int) $log[1])) . '<br><span class="text-muted">' . self::h(self::t($text)) . '</span>'
                    . ' <a href="./?module=cron&amp;log=' . (int) $r['ct_id_pk'] . '#output">' . self::t('Output') . '</a>';
            }
            $out .= '<tr><td><code>' . self::h(self::ShownPath($r, $me['username'])) . '</code></td>'
                . '<td>' . self::h(self::t(cron_safe::TimingLabel((string) $r['ct_timing_vc']))) . $slower . '</td>'
                . '<td>' . self::h(self::PhpFor($me['userid'], $me['username'], (string) $r['ct_fullpath_vc'])) . '</td>'
                . '<td>' . $last . '</td>'
                . '<td>' . self::h($r['ct_description_tx']) . '</td>'
                . '<td><form method="post" action="./?module=cron&amp;action=DeleteCron" style="display:inline" onsubmit="return confirm(\'' . self::h(self::t('Delete this task?')) . '\');">'
                . '<input type="hidden" name="inJob" value="' . (int) $r['ct_id_pk'] . '">' . runtime_csfr::Token()
                . '<button class="btn btn-danger btn-sm" type="submit">' . self::t('Delete') . '</button></form></td></tr>';
        }
        $lim = sys_accountphp::CronSettings();
        $out .= '</table><p class="text-muted">' . self::h(self::t('New tasks and changes start within 5 minutes. A run is skipped while the previous one is still running, and stopped after') . ' '
            . $lim['cron_max_runtime'] . ' ' . self::t('minutes') . '.') . '</p>';
        return $out . self::Output($rows, $me);
    }

    /**
     * Returns the last run's output of one of the account's tasks (?log=<id>).
     *
     * @global runtime_controller $controller The request controller.
     * @param array<int,array<string,mixed>> $rows The account's x_cronjobs rows.
     * @param array<string,mixed> $me The user's details (uses 'username' and 'userid').
     * @return string The output box HTML, or '' when no (or another account's) task is asked for.
     */
    static function Output($rows, $me)
    {
        global $controller;
        $id = (int) $controller->GetControllerRequest('URL', 'log');
        if ($id < 1) {
            return '';
        }
        foreach ($rows as $r) {
            if ((int) $r['ct_id_pk'] === $id) {
                $log = cron_safe::ReadLog($me['username'], $me['userid'], $id);
                return '<div id="output" style="margin: 10px 0 20px;"><h3>' . self::t('Last output of') . ' <code>' . self::h(self::ShownPath($r, $me['username'])) . '</code></h3>'
                    . ($log === false ? '<p class="text-muted">' . self::t('It has not run yet.') . '</p>'
                        : '<pre style="max-height: 420px; overflow: auto; white-space: pre-wrap;">' . self::h($log[0]) . '</pre>')
                    . '<a class="btn btn-default btn-sm" href="./?module=cron">' . self::t('Close') . '</a></div>';
            }
        }
        return '';
    }

    /**
     * Builds the form to schedule a script (<@ CreateCron @>), with the file browser.
     *
     * @return string The HTML.
     */
    static function getCreateCron()
    {
        $opts = '';
        $min = sys_accountphp::CronSettings()['cron_min_interval'];
        foreach (self::Allowed() as $value => $label) {
            $opts .= '<option value="' . self::h($value) . '"' . ($value === '0 * * * *' ? ' selected' : '') . '>' . self::h(self::t($label)) . '</option>';
        }
        return '<link rel="stylesheet" href="modules/protected_directories/assets/tree.css">'
            . '<h2>' . self::t('Schedule a script') . '</h2>'
            . '<form id="cr-form" method="post" action="./?module=cron&amp;action=CreateCron"><table class="table">'
            . '<tr><th style="width: 180px;">' . self::t('Script') . '</th><td>'
            . '<p class="text-muted" style="margin-top: 0;">' . self::t('Open your folders and click the PHP file to run.') . '</p>'
            . '<div class="pd-tree" id="cr-tree" data-url="modules/cron/code/files.php"><div class="pd-loading">' . self::t('Loading...') . '</div></div>'
            . '<div class="pd-chosen">' . self::t('Chosen') . ': <code id="cr-chosen" class="text-muted">' . self::t('nothing yet') . '</code></div>'
            . '<input type="hidden" name="inScript" id="cr-path" value=""></td></tr>'
            . '<tr><th>' . self::t('Runs') . '</th><td><select class="form-control" name="inTiming" style="max-width: 320px;">' . $opts . '</select>'
            . '<span class="text-muted">' . self::t('Server time') . ': ' . self::h(date('H:i T')) . '</span></td></tr>'
            . '<tr><th>' . self::t('Description') . '</th><td><input class="form-control" name="inDescription" maxlength="100" style="max-width: 420px;" placeholder="' . self::h(self::t('optional, for example: clear the cache')) . '"></td></tr>'
            . '<tr><td colspan="2">' . runtime_csfr::Token() . '<button class="btn btn-primary" type="submit" id="cr-submit" disabled>' . self::t('Schedule') . '</button> '
            . '<span class="text-muted">' . self::t('The script runs as your account, with your domain\'s PHP version, and can only use files in your account.') . '</span></td></tr>'
            . '</table></form><script src="modules/cron/assets/files.js"></script>';
    }

    /**
     * Returns the timings offered, without those more often than the server allows.
     *
     * @return array<string, string> Crontab timing => English label.
     */
    static function Allowed()
    {
        $min = sys_accountphp::CronSettings()['cron_min_interval'];
        return array_filter(cron_safe::Timings(), function ($t) use ($min) { return sys_accountphp::CronEvery($t) >= $min; }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Saves a new task from the form (POST action=CreateCron).
     *
     * Checks the CSRF token (runtime_csfr::Protect()), the script path (cron_safe::ResolveFile()),
     * the timing, the task limit and duplicates, then inserts the x_cronjobs row. The result
     * is added to $msg.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doCreateCron()
    {
        global $zdbh, $controller;
        runtime_csfr::Protect();
        $me = self::Me();
        $rel = trim((string) $controller->GetControllerRequest('FORM', 'inScript'), '/');
        $timing = (string) $controller->GetControllerRequest('FORM', 'inTiming');
        $desc = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string) $controller->GetControllerRequest('FORM', 'inDescription')));
        $desc = function_exists('mb_substr') ? mb_substr($desc, 0, 100) : substr($desc, 0, 100);
        if ($rel === '') {
            self::$msg[] = array('error', 'Choose the script to run.');
            return;
        }
        $full = cron_safe::ResolveFile($me['username'], $rel);
        if ($full === false) {
            self::$msg[] = array('error', 'That script can not be scheduled: it must be a .php file in your public_html (not a link), with only letters, digits, dots, dashes and underscores in its path.');
            return;
        }
        if (!isset(self::Allowed()[$timing])) {
            self::$msg[] = array('error', 'Choose how often it runs.');
            return;
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_cronjobs WHERE ct_acc_fk = :a AND ct_deleted_ts IS NULL");
        $q->execute(array(':a' => (int) $me['userid']));
        if ((int) $q->fetchColumn() >= self::MAX_JOBS) {
            self::$msg[] = array('error', 'You have the most tasks an account can have (' . self::MAX_JOBS . '). Delete one first.');
            return;
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_cronjobs WHERE ct_acc_fk = :a AND ct_fullpath_vc = :f AND ct_timing_vc = :t AND ct_deleted_ts IS NULL");
        $q->execute(array(':a' => (int) $me['userid'], ':f' => $full, ':t' => $timing));
        if ((int) $q->fetchColumn() > 0) {
            self::$msg[] = array('error', 'That script already runs at that timing.');
            return;
        }
        $zdbh->prepare("INSERT INTO x_cronjobs (ct_acc_fk, ct_script_vc, ct_description_tx, ct_timing_vc, ct_fullpath_vc, ct_created_ts) VALUES (:a, :s, :d, :t, :f, :c)")
            ->execute(array(':a' => (int) $me['userid'], ':s' => $rel, ':d' => $desc, ':t' => $timing, ':f' => $full, ':c' => time()));
        self::$msg[] = array('ok', 'Scheduled: public_html/' . $rel . ', ' . strtolower(self::t(cron_safe::Timings()[$timing])) . '. It starts within 5 minutes.');
    }

    /**
     * Deletes one of the account's tasks (POST action=DeleteCron, field inJob).
     *
     * Marks the row deleted (ct_deleted_ts); the result is added to $msg.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doDeleteCron()
    {
        global $zdbh, $controller;
        runtime_csfr::Protect();
        $me = self::Me();
        $id = (int) $controller->GetControllerRequest('FORM', 'inJob');
        // only one of this account's own tasks
        $q = $zdbh->prepare("UPDATE x_cronjobs SET ct_deleted_ts = :t WHERE ct_id_pk = :id AND ct_acc_fk = :a AND ct_deleted_ts IS NULL");
        $q->execute(array(':t' => time(), ':id' => $id, ':a' => (int) $me['userid']));
        self::$msg[] = ($q->rowCount() === 1) ? array('ok', 'Task deleted. It stops within 5 minutes.') : array('error', 'That task was not found.');
    }

    /**
     * Returns the messages of this request as alert boxes (<@ Result @>).
     *
     * @return string The HTML ('' when there are none).
     */
    static function getResult()
    {
        $out = '';
        foreach (self::$msg as $m) {
            $out .= ui_sysmessage::shout(self::h(self::t($m[1])), $m[0] === 'ok' ? 'zannounceok' : 'zannounceerror');
        }
        return $out;
    }
}
