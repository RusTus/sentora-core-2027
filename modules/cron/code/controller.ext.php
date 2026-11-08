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
 *
 * Updated by RusTus, 2026-11-07
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
     * @var array<int, array<int, mixed>> Messages for getResult(): array('ok'|'error', English text, optional values
     *      that take the place of each %s in the translated text, in order).
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
     * The account's tasks, oldest first.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>> The x_cronjobs rows.
     */
    static function Jobs()
    {
        global $zdbh;
        static $rows = null;
        if ($rows === null) {
            $q = $zdbh->prepare("SELECT * FROM x_cronjobs WHERE ct_acc_fk = :a AND ct_deleted_ts IS NULL ORDER BY ct_id_pk");
            $q->execute(array(':a' => (int) self::Me()['userid']));
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }

    /**
     * The timing the server really uses: a task set to run more often than allowed runs at the shortest interval.
     *
     * @param string $timing The stored crontab timing.
     * @return string The timing used.
     */
    static function Effective($timing)
    {
        $min = (int) sys_accountphp::CronSettings()['cron_min_interval'];
        return sys_accountphp::CronEvery($timing) < $min ? '*/' . $min . ' * * * *' : $timing;
    }

    /**
     * Whether a value matches one crontab field (*, n, a-b, lists and steps).
     *
     * @param string $field The field, for example '*' or '0,30' or '*' . '/15'.
     * @param int $v The value to test.
     * @param int $lo The lowest value the field can have.
     * @return bool
     */
    static function FieldMatches($field, $v, $lo)
    {
        foreach (explode(',', $field) as $part) {
            $step = 1;
            if (strpos($part, '/') !== false) {
                list($part, $st) = explode('/', $part, 2);
                $step = max(1, (int) $st);
            }
            if ($part === '*') {
                $a = $lo;
                $b = 1000;
            } elseif (strpos($part, '-') !== false) {
                list($a, $b) = array_map('intval', explode('-', $part, 2));
            } else {
                $a = $b = (int) $part;
            }
            if ($v >= $a && $v <= $b && ($v - $a) % $step === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * When a crontab timing next runs, in server time.
     *
     * Days and hours that do not match are skipped whole, so even a monthly task takes few steps.
     *
     * @param string $timing The crontab timing (five fields).
     * @param int|null $from Optional. Start after this time. Default now.
     * @return int|false The Unix time, or false when none in the next year.
     */
    static function NextRun($timing, $from = null)
    {
        $f = preg_split('/\s+/', trim((string) $timing));
        if (count($f) !== 5) {
            return false;
        }
        $t = (int) (floor(($from === null ? time() : $from) / 60) * 60) + 60;
        $limit = $t + 366 * 86400;
        while ($t < $limit) {
            $dow = (int) date('w', $t);
            $dayOk = self::FieldMatches($f[3], (int) date('n', $t), 1)
                && (($f[2] === '*' || $f[4] === '*') ? (self::FieldMatches($f[2], (int) date('j', $t), 1) && self::FieldMatches($f[4], $dow, 0) || ($f[4] !== '*' && $dow === 0 && self::FieldMatches($f[4], 7, 0)))
                    : (self::FieldMatches($f[2], (int) date('j', $t), 1) || self::FieldMatches($f[4], $dow, 0)));
            if (!$dayOk) {
                $t = mktime(0, 0, 0, (int) date('n', $t), (int) date('j', $t) + 1, (int) date('Y', $t));
                continue;
            }
            if (!self::FieldMatches($f[1], (int) date('G', $t), 0)) {
                $t = mktime((int) date('G', $t) + 1, 0, 0, (int) date('n', $t), (int) date('j', $t), (int) date('Y', $t));
                continue;
            }
            if (self::FieldMatches($f[0], (int) date('i', $t), 0)) {
                return $t;
            }
            $t += 60;
        }
        return false;
    }

    /**
     * How far a time is from now, in words ("in 12 min", "3 h ago").
     *
     * @param int $time A Unix time.
     * @return string The words.
     */
    static function Ago($time)
    {
        $d = (int) $time - time();
        $a = abs($d);
        if ($a < 60) {
            return self::t($d >= 0 ? 'in under a minute' : 'just now');
        }
        if ($a < 3600) {
            $w = floor($a / 60) . ' min';
        } elseif ($a < 172800) {
            $w = floor($a / 3600) . ' h';
        } else {
            $w = str_replace('%d', (string) floor($a / 86400), self::t('%d days'));
        }
        return str_replace('%s', $w, self::t($d >= 0 ? 'in %s' : '%s ago'));
    }

    /**
     * The last run of a task: its state, when and the short text (from its log).
     *
     * @param array<string, mixed> $row The x_cronjobs row.
     * @param int $bytes Optional. How much of the log to read. Default 4096.
     * @return array{0: string, 1: string, 2: int, 3: string}|null array(state ok|error|timeout|running, short text (translated), time, log text), or null when it has not run.
     */
    static function LastRun($row, $bytes = 4096)
    {
        $me = self::Me();
        $log = cron_safe::ReadLog($me['username'], $me['userid'], (int) $row['ct_id_pk'], $bytes);
        if ($log === false) {
            return null;
        }
        $result = cron_safe::LogResult($log[0]);
        // the short text translated, with the exit code (if any) put in afterwards
        $text = str_replace('%d', isset($result[2]) ? (string) $result[2] : '', self::t($result[1]));
        return array($result[0], $text, (int) $log[1], $log[0]);
    }

    /**
     * The figures at the top (<@ Summary @>): tasks, last runs that went well or not, the shortest interval and the server time.
     *
     * @return string The HTML.
     */
    static function getSummary()
    {
        $jobs = self::Jobs();
        $ok = 0;
        $bad = 0;
        $never = 0;
        foreach ($jobs as $r) {
            $l = self::LastRun($r, 1024);
            if ($l === null) {
                $never++;
            } elseif ($l[0] === 'ok' || $l[0] === 'running') {
                $ok++;
            } else {
                $bad++;
            }
        }
        $lim = sys_accountphp::CronSettings();
        $tiles = array(
            array('Tasks', count($jobs) . ' <small>/ ' . self::MAX_JOBS . '</small>', '', '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>'),
            array('Last run went well', (string) $ok, $ok ? 'ok' : '', '<path d="M20 6 9 17l-5-5"/>'),
            array('Last run had a problem', (string) $bad, $bad ? 'bad' : '', '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>'),
            array('Server time', self::h(date('H:i')) . ' <small>' . self::h(date('T')) . '</small>', '', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>'),
        );
        $out = '';
        foreach ($tiles as $t) {
            $out .= '<div class="cr-tile ' . $t[2] . '"><svg class="cr-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $t[3] . '</svg>'
                . '<div><b>' . $t[1] . '</b><span>' . self::h(self::t($t[0])) . '</span></div></div>';
        }
        $every = self::t($lim['cron_min_interval'] == 1 ? 'Tasks run at most every %d minute and are stopped after %d minutes.' : 'Tasks run at most every %d minutes and are stopped after %d minutes.');
        $every = preg_replace('/%d/', (string) (int) $lim['cron_max_runtime'], preg_replace('/%d/', (string) (int) $lim['cron_min_interval'], $every, 1), 1);
        $note = $every . ' ' . self::t('A run is skipped while the previous one is still running.')
            . ($never ? ' ' . str_replace('%d', (string) $never, self::t($never === 1 ? '%d task has not run yet.' : '%d tasks have not run yet.')) : '');
        return '<div class="cr-tiles">' . $out . '</div><p class="cr-note">' . self::h($note) . '</p>';
    }

    /**
     * Radio choices for how often a task runs (only those the server allows).
     *
     * @param string $name The field name.
     * @param string $current The timing chosen now.
     * @param string $idp A prefix for the ids, unique on the page.
     * @return string The HTML.
     */
    static function TimingChoices($name, $current, $idp)
    {
        $out = '';
        foreach (self::Allowed() as $value => $label) {
            $id = $idp . substr(md5($value), 0, 6);
            $out .= '<label class="cr-chip" for="' . $id . '"><input type="radio" id="' . $id . '" name="' . $name . '" value="' . self::h($value) . '"' . ($value === $current ? ' checked' : '') . ' required>'
                . '<span>' . self::h(self::t($label)) . '</span></label>';
        }
        return '<div class="cr-chips">' . $out . '</div>';
    }

    /**
     * One card per task (<@ Tasks @>): the script, how often and when it runs next, the PHP version, the last run,
     * and its output, a change form and Delete. The output of the task in ?log= and the form of the task in ?edit= start open.
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getTasks()
    {
        global $controller;
        $me = self::Me();
        $rows = self::Jobs();
        if (!$rows) {
            return '<div class="cr-empty"><svg class="cr-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>'
                . '<b>' . self::h(self::t('You have no scheduled tasks yet.')) . '</b><span>' . self::h(self::t('Schedule a PHP script below, for example to clear a cache every hour or send a newsletter every week.')) . '</span></div>';
        }
        $logId = (int) $controller->GetControllerRequest('URL', 'log');
        $editId = (int) $controller->GetControllerRequest('URL', 'edit');
        $min = (int) sys_accountphp::CronSettings()['cron_min_interval'];
        $states = array('ok' => array('Finished', 'ok'), 'running' => array('Running', 'run'), 'timeout' => array('Stopped', 'bad'), 'error' => array('Problem', 'bad'));
        $out = '';
        foreach ($rows as $r) {
            $id = (int) $r['ct_id_pk'];
            $path = self::ShownPath($r, $me['username']);
            $timing = (string) $r['ct_timing_vc'];
            $next = self::NextRun(self::Effective($timing));
            $slower = sys_accountphp::CronEvery($timing) < $min;
            $last = self::LastRun($r, $logId === $id ? 65536 : 4096);
            $dir = dirname($path);
            $label = cron_safe::TimingLabel($timing);
            if ($last === null) {
                $lastHtml = '<span class="cr-state none">' . self::h(self::t('Not run yet')) . '</span>';
            } else {
                $st = isset($states[$last[0]]) ? $states[$last[0]] : array('Problem', 'bad');
                $lastHtml = '<span class="cr-state ' . $st[1] . '">' . self::h(self::t($st[0])) . '</span>'
                    . '<span class="cr-when" title="' . self::h(date('Y-m-d H:i', $last[2])) . '">' . self::h(self::Ago($last[2])) . '</span>'
                    . ($last[0] !== 'ok' ? '<small>' . self::h($last[1]) . '</small>' : '');
            }
            $out .= '<div class="cr-task' . ($last !== null && in_array($last[0], array('error', 'timeout'), true) ? ' is-bad' : '') . '" id="task' . $id . '">'
                . '<div class="cr-task-row">'
                . '<div class="cr-task-main"><div class="cr-path"><svg class="cr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>'
                . '<span class="cr-dir">' . self::h($dir) . '/</span><b>' . self::h(basename($path)) . '</b></div>'
                . ($r['ct_description_tx'] !== '' && $r['ct_description_tx'] !== null ? '<p class="cr-desc">' . self::h($r['ct_description_tx']) . '</p>' : '')
                . '<div class="cr-meta"><span class="cr-every">' . self::h($label === $timing ? $timing : self::t($label)) . '</span>'
                . ($slower ? '<span class="cr-slow" title="' . self::h(str_replace('%d', (string) $min, self::t('This server runs tasks at most every %d minutes'))) . '">' . self::h(str_replace('%d', (string) $min, self::t('runs every %d min'))) . '</span>' : '')
                . '<span>' . self::h(self::PhpFor($me['userid'], $me['username'], (string) $r['ct_fullpath_vc'])) . '</span>'
                . ($next !== false ? '<span title="' . self::h(date('Y-m-d H:i', $next)) . '">' . self::h(self::t('Next') . ': ' . date(date('Y-m-d', $next) === date('Y-m-d') ? 'H:i' : 'D j M H:i', $next) . ' (' . self::Ago($next) . ')') . '</span>' : '')
                . '</div></div>'
                . '<div class="cr-last">' . $lastHtml . '</div>'
                . '<div class="cr-act">'
                . ($last !== null ? '<a class="btn btn-default btn-sm cr-toggle" href="./?module=cron&amp;log=' . $id . '#task' . $id . '" data-panel="out">' . self::h(self::t('Output')) . '</a>' : '')
                . '<a class="btn btn-default btn-sm cr-toggle" href="./?module=cron&amp;edit=' . $id . '#task' . $id . '" data-panel="edit">' . self::h(self::t('Change')) . '</a>'
                . '<form method="post" action="./?module=cron&amp;action=DeleteCron" class="cr-del"><input type="hidden" name="inJob" value="' . $id . '">' . runtime_csfr::Token()
                . '<button class="btn btn-default btn-sm cr-delbtn" type="submit" title="' . self::h(self::t('Delete')) . '" aria-label="' . self::h(self::t('Delete') . ' ' . $path) . '">'
                . '<svg class="cr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></button></form>'
                . '</div></div>';
            // last output
            if ($last !== null) {
                $out .= '<div class="cr-panel cr-out"' . ($logId === $id ? '' : ' hidden') . '><div class="cr-panel-head"><b>' . self::h(self::t('Last output')) . '</b>'
                    . '<span>' . self::h(date('Y-m-d H:i', $last[2])) . '</span></div>'
                    . '<pre class="cr-log">' . self::h($last[3]) . '</pre></div>';
            }
            // change how often and the description
            $out .= '<form class="cr-panel cr-edit" method="post" action="./?module=cron&amp;action=UpdateCron&amp;edit=' . $id . '#task' . $id . '"' . ($editId === $id ? '' : ' hidden') . '>'
                . '<input type="hidden" name="inJob" value="' . $id . '">'
                . '<div class="cr-field"><span class="cr-label">' . self::h(self::t('How often')) . '</span>' . self::TimingChoices('inTiming', $timing, 'e' . $id . '_') . '</div>'
                . '<div class="cr-field"><label for="d' . $id . '">' . self::h(self::t('Description')) . '</label><input class="form-control" type="text" id="d' . $id . '" name="inDescription" maxlength="100" value="' . self::h($r['ct_description_tx']) . '"></div>'
                . '<div class="cr-foot">' . runtime_csfr::Token() . '<button class="btn btn-primary" type="submit">' . self::h(self::t('Save')) . '</button>'
                . '<button class="btn btn-default cr-cancel" type="button">' . self::h(self::t('Cancel')) . '</button>'
                . '<span class="cr-hint">' . self::h(self::t('To run a different script, schedule it below and delete this task.')) . '</span></div></form>';
            $out .= '</div>';
        }
        return $out;
    }

    /**
     * The timing choices of the new task form (<@ NewTimings @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getNewTimings()
    {
        global $controller;
        $posted = (string) $controller->GetControllerRequest('FORM', 'inTiming');
        $allowed = self::Allowed();
        return self::TimingChoices('inTiming', isset($allowed[$posted]) && self::$msg && self::$msg[0][0] === 'error' ? $posted : (isset($allowed['0 * * * *']) ? '0 * * * *' : (string) key($allowed)), 'n_');
    }

    /**
     * Whether the account may add another task (template <% if CanAdd %>).
     *
     * @return bool
     */
    static function getCanAdd()
    {
        return count(self::Jobs()) < self::MAX_JOBS;
    }

    /**
     * The task limit, for the template (<@ MaxJobs @>).
     *
     * @return int
     */
    static function getMaxJobs()
    {
        return self::MAX_JOBS;
    }

    /**
     * Change how often one of the account's tasks runs, and its description (POST action=UpdateCron).
     *
     * The script stays the same. Checks the CSRF token, that the task is the account's own,
     * the timing and duplicates; the daemon writes the change within 5 minutes.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doUpdateCron()
    {
        global $zdbh, $controller;
        runtime_csfr::Protect();
        $me = self::Me();
        $id = (int) $controller->GetControllerRequest('FORM', 'inJob');
        $timing = (string) $controller->GetControllerRequest('FORM', 'inTiming');
        $desc = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string) $controller->GetControllerRequest('FORM', 'inDescription')));
        $desc = function_exists('mb_substr') ? mb_substr($desc, 0, 100) : substr($desc, 0, 100);
        $q = $zdbh->prepare("SELECT * FROM x_cronjobs WHERE ct_id_pk = :id AND ct_acc_fk = :a AND ct_deleted_ts IS NULL");
        $q->execute(array(':id' => $id, ':a' => (int) $me['userid']));
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            self::$msg[] = array('error', 'That task was not found.');
            return;
        }
        if (!isset(self::Allowed()[$timing])) {
            self::$msg[] = array('error', 'Choose how often it runs.');
            return;
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_cronjobs WHERE ct_acc_fk = :a AND ct_fullpath_vc = :f AND ct_timing_vc = :t AND ct_deleted_ts IS NULL AND ct_id_pk <> :id");
        $q->execute(array(':a' => (int) $me['userid'], ':f' => $row['ct_fullpath_vc'], ':t' => $timing, ':id' => $id));
        if ((int) $q->fetchColumn() > 0) {
            self::$msg[] = array('error', 'That script already runs at that timing.');
            return;
        }
        $zdbh->prepare("UPDATE x_cronjobs SET ct_timing_vc = :t, ct_description_tx = :d WHERE ct_id_pk = :id AND ct_acc_fk = :a")
            ->execute(array(':t' => $timing, ':d' => $desc, ':id' => $id, ':a' => (int) $me['userid']));
        self::$msg[] = array('ok', 'Task changed: %s. The change applies within 5 minutes.', array(strtolower(self::t(cron_safe::Timings()[$timing]))));
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
            self::$msg[] = array('error', 'You have the most tasks an account can have (%s). Delete one first.', array(self::MAX_JOBS));
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
        self::$msg[] = array('ok', 'Scheduled: %s, %s. It starts within 5 minutes.', array('public_html/' . $rel, strtolower(self::t(cron_safe::Timings()[$timing]))));
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
            $text = self::h(self::t($m[1]));
            // the values (a path, a number) go in after translating, each in place of the next %s
            $at = 0;
            foreach (isset($m[2]) ? (array) $m[2] : array() as $v) {
                $p = strpos($text, '%s', $at);
                if ($p === false) {
                    break;
                }
                $v = self::h($v);
                $text = substr_replace($text, $v, $p, 2);
                $at = $p + strlen($v);
            }
            $out .= ui_sysmessage::shout($text, $m[0] === 'ok' ? 'zannounceok' : 'zannounceerror');
        }
        return $out;
    }
}
