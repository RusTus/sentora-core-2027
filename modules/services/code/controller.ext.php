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
 * @changes P.Peyremorte
 * - added display of ports checked (80 may be not used!)
 * - refactored (replacement of duplicate string constructions by a fucntion)
 *
 * Updated by RusTus, 2026-11-07
 */
/**
 * Services module: controller.
 *
 * Shows whether the hosting services answer on their ports, the server uptime and
 * the daemon's last and next run.
 */
class module_controller extends ctrl_module
{
    /**
     * The services checked, in groups: name, port and what it is for.
     *
     * @return array<string, array<int, array{0: string, 1: int, 2: string}>> Group title => list of [name, port, purpose].
     */
    static function Checks()
    {
        return array(
            'Websites' => array(
                array('HTTP', 80, 'Websites and the panel, unencrypted'),
                array('HTTPS', 443, 'Websites and the panel, encrypted'),
            ),
            'Email' => array(
                array('SMTP', 25, 'Mail from other servers'),
                array('SMTP submission', 587, 'Mail sent by your users'),
                array('IMAP', 143, 'Reading mail'),
                array('IMAPS', 993, 'Reading mail, encrypted'),
                array('POP3', 110, 'Downloading mail'),
                array('POP3S', 995, 'Downloading mail, encrypted'),
            ),
            'Files, names and data' => array(
                array('FTP', 21, 'Uploading website files'),
                array('DNS', 53, 'Domain names of hosted sites'),
                array('MySQL', 3306, 'Databases'),
            ),
        );
    }

    /**
     * Check one local TCP port and time it.
     *
     * @param int $port The port on 127.0.0.1.
     * @return array{0: bool, 1: float} Whether it answered, and how long it took in milliseconds.
     */
    static function Probe($port)
    {
        $start = microtime(true);
        $up = sys_monitoring::LocalPortStatus($port);
        return array($up, (microtime(true) - $start) * 1000);
    }

    /**
     * The results of every check, done once per page.
     *
     * @return array<string, array<int, array{0: string, 1: int, 2: string, 3: bool, 4: float}>> Group title => list of [name, port, purpose, up, ms].
     */
    static function Results()
    {
        static $res = null;
        if ($res === null) {
            $res = array();
            foreach (self::Checks() as $group => $list) {
                foreach ($list as $c) {
                    $p = self::Probe($c[1]);
                    $res[$group][] = array($c[0], $c[1], $c[2], $p[0], $p[1]);
                }
            }
        }
        return $res;
    }

    /**
     * Template value <@ Overall @>: one line for all services, with how many answer.
     *
     * @return string The HTML.
     */
    static function getOverall()
    {
        $t = function ($s) { return htmlspecialchars(ui_language::translate($s), ENT_QUOTES, 'UTF-8'); };
        // put numbers into a translated text's %d places, in order
        $n = function ($text, ...$nums) { foreach ($nums as $x) { $text = preg_replace('/%d/', (string) (int) $x, $text, 1); } return $text; };
        $up = 0;
        $all = 0;
        $down = array();
        foreach (self::Results() as $list) {
            foreach ($list as $r) {
                $all++;
                if ($r[3]) {
                    $up++;
                } else {
                    $down[] = $r[0];
                }
            }
        }
        $ok = !$down;
        return '<div class="sv-overall ' . ($ok ? 'ok' : 'bad') . '">'
            . '<svg class="sv-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . ($ok ? '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>' : '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>') . '</svg>'
            . '<div><b>' . ($ok ? $t('All services are answering') : (count($down) === 1 ? $t('1 service is not answering') : str_replace('%d', (string) count($down), $t('%d services are not answering')))) . '</b>'
            . '<span>' . ($ok ? $n($t('%d of %d ports open on this server.'), $up, $all) : htmlspecialchars(implode(', ', $down), ENT_QUOTES, 'UTF-8') . ' &middot; ' . $n($t('%d of %d answer.'), $up, $all)) . '</span></div>'
            . '<div class="sv-checked"><span>' . $t('Checked at') . ' ' . date('H:i:s') . '</span>'
            . '<a class="btn btn-default btn-sm" href="./?module=services"><svg class="sv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>' . $t('Check again') . '</a></div>'
            . '</div>';
    }

    /**
     * Template value <@ Services @>: a card per group with each service, its port, state and answer time.
     *
     * @return string The HTML.
     */
    static public function getServices()
    {
        $t = function ($s) { return htmlspecialchars(ui_language::translate($s), ENT_QUOTES, 'UTF-8'); };
        $out = '';
        foreach (self::Results() as $group => $list) {
            $rows = '';
            $down = 0;
            foreach ($list as $r) {
                if (!$r[3]) {
                    $down++;
                }
                $rows .= '<li class="' . ($r[3] ? 'up' : 'down') . '"><span class="sv-dot" aria-hidden="true"></span>'
                    . '<div class="sv-name"><b>' . $t($r[0]) . '</b><small>' . $t($r[2]) . '</small></div>'
                    . '<code class="sv-port">' . (int) $r[1] . '</code>'
                    . '<span class="sv-state">' . $t($r[3] ? 'Open' : 'Closed') . ($r[3] ? '<small>' . ($r[4] < 1 ? '&lt;1' : round($r[4])) . ' ms</small>' : '') . '</span></li>';
            }
            $out .= '<div class="sv-card"><div class="sv-card-head"><h4>' . $t($group) . '</h4>'
                . '<span class="sv-pill ' . ($down ? 'bad' : 'ok') . '">' . ($down ? str_replace('%d', (string) $down, $t('%d down')) : $t('All open')) . '</span></div>'
                . '<ul class="sv-list">' . $rows . '</ul></div>';
        }
        return $out;
    }

    /**
     * How far a time is from now, in words ("in 4 min", "2 h ago").
     *
     * @param int $time A Unix time.
     * @return string The words.
     */
    static function Ago($time)
    {
        $d = (int) $time - time();
        $a = abs($d);
        if ($a < 60) {
            return ui_language::translate($d >= 0 ? 'in under a minute' : 'just now');
        }
        $w = $a < 3600 ? floor($a / 60) . ' min' : ($a < 172800 ? floor($a / 3600) . ' h' : floor($a / 86400) . ' ' . ui_language::translate('days'));
        return str_replace('%s', $w, ui_language::translate($d >= 0 ? 'in %s' : '%s ago'));
    }

    /**
     * Template value <@ Facts @>: server uptime (and when it started), and the daemon's last and next run.
     *
     * @return string The HTML.
     */
    static function getFacts()
    {
        $t = function ($s) { return htmlspecialchars(ui_language::translate($s), ENT_QUOTES, 'UTF-8'); };
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $df = ctrl_options::GetSystemOption('sentora_df');
        $up = (float) explode(' ', trim((string) @file_get_contents('/proc/uptime')) . ' ')[0];
        $last = (int) ctrl_options::GetSystemOption('daemon_lastrun');
        $next = self::NextRun();
        $tiles = array(
            array('Server uptime', $up > 0 ? self::Span($up) : sys_monitoring::ServerUptime(), $up > 0 ? str_replace('%s', $h(date($df, time() - (int) $up)), $t('since %s')) : '', '<path d="M12 2v4M12 18v4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M2 12h4M18 12h4M4.9 19.1l2.8-2.8M16.3 7.7l2.8-2.8"/>'),
            array('Daemon last ran', $last > 0 ? self::Ago($last) : ui_language::translate('Not since the last reset'), $last > 0 ? $h(date($df, $last)) : $t('A full run was queued in Sentora Config.'), '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>'),
            array('Daemon runs next', self::Ago($next), $h(date($df, $next)), '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>'),
        );
        $out = '';
        foreach ($tiles as $x) {
            $out .= '<div class="sv-tile"><svg class="sv-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $x[3] . '</svg>'
                . '<div><span>' . $t($x[0]) . '</span><b>' . $h($x[1]) . '</b>' . ($x[2] !== '' ? '<small>' . $x[2] . '</small>' : '') . '</div></div>';
        }
        return $out;
    }

    /**
     * A length of time in words, leaving out units that are zero ("2 hours, 3 minutes", "4 days, 1 hour").
     *
     * @param float $seconds The length in seconds.
     * @return string The words.
     */
    static function Span($seconds)
    {
        $s = (int) $seconds;
        $parts = array();
        foreach (array(array(86400, 'day', 'days'), array(3600, 'hour', 'hours'), array(60, 'minute', 'minutes')) as $u) {
            $n = intdiv($s, $u[0]);
            $s -= $n * $u[0];
            if ($n > 0) {
                $parts[] = $n . ' ' . ui_language::translate($n === 1 ? $u[1] : $u[2]);
            }
        }
        // days and hours are enough once it has been up for days
        return $parts ? implode(', ', array_slice($parts, 0, 2)) : ui_language::translate('under a minute');
    }

    /**
     * When the daemon runs next: the last run plus its interval, or the next 5-minute mark (cron starts it then)
     * when it has not run since a reset or is overdue.
     *
     * @return int The Unix time.
     */
    static function NextRun()
    {
        $last = (int) ctrl_options::GetSystemOption('daemon_lastrun');
        $next = $last > 0 ? $last + (int) ctrl_options::GetSystemOption('daemon_run_interval') : 0;
        return $next > time() ? $next : (int) (ceil(time() / 300) * 300);
    }

    /**
     * Whether the web server answers on port 80.
     *
     * @return bool
     */
    static function getIsHTTPServerUp()
    {
        return sys_monitoring::PortStatus(80);
    }

    /**
     * Whether the web server answers on port 443.
     *
     * @return bool
     */
    static function getIsHTTPSServerUp()
    {
        return sys_monitoring::PortStatus(443);
    }

    /**
     * Whether MySQL answers on port 3306.
     *
     * @return bool
     */
    static function getIsMySQLUp()
    {
        return sys_monitoring::PortStatus(3306);
    }

    /**
     * Whether the FTP server answers on port 21.
     *
     * @return bool
     */
    static function getIsFTPUp()
    {
        return sys_monitoring::PortStatus(21);
    }

    /**
     * Whether the SMTP server answers on port 25.
     *
     * @return bool
     */
    static function getIsSMTPUp()
    {
        return sys_monitoring::PortStatus(25);
    }

    /**
     * Whether the POP3 server answers on port 110.
     *
     * @return bool
     */
    static function getIsPOP3Up()
    {
        return sys_monitoring::PortStatus(110);
    }

    /**
     * Whether the IMAP server answers on port 143.
     *
     * @return bool
     */
    static function getIsIMAPUp()
    {
        return sys_monitoring::PortStatus(143);
    }

    /**
     * The server uptime.
     *
     * @return string Human readable uptime.
     */
    static function getUptime()
    {
        return sys_monitoring::ServerUptime();
    }

    /**
     * Template value <@ LastRunTime @>: when the daemon last ran.
     *
     * @return string The time in the panel's date format (option 'sentora_df'), or "Not since the last reset".
     */
    static function getLastRunTime()
    {
        $last = (int) ctrl_options::GetSystemOption('daemon_lastrun');
        return $last > 0 ? date(ctrl_options::GetSystemOption('sentora_df'), $last) : ui_language::translate('Not since the last reset');
    }

    /**
     * Template value <@ NextRunTime @>: when the daemon runs next.
     *
     * @return string The time in the panel's date format (see NextRun()).
     */
    static function getNextRunTime()
    {
        return date(ctrl_options::GetSystemOption('sentora_df'), self::NextRun());
    }

}
