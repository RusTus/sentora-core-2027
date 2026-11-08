<?php

/**
 * GoAccess module: controller for the web traffic reports page.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-07
 */

require_once dirname(__FILE__) . '/goaccess.php';

/**
 * GoAccess (Advanced): web traffic reports, made every hour by GoAccess from the
 * access logs. Each account sees its own domains; administrators also see the
 * panel's own traffic and all domains together.
 */
class module_controller extends ctrl_module
{

    /**
     * Escape a value for HTML.
     *
     * @param mixed $s The value (cast to string).
     * @return string The escaped text.
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Check whether GoAccess is installed (<% if IsInstalled %>).
     *
     * @return bool True when the GoAccess binary exists and can run.
     */
    static function getIsInstalled()
    {
        return goaccess_helper::Installed();
    }

    /**
     * Check whether GoAccess is missing (<% if IsNotInstalled %>).
     *
     * @return bool True when the GoAccess binary is missing.
     */
    static function getIsNotInstalled()
    {
        return !goaccess_helper::Installed();
    }

    /**
     * Check whether the current user may see any report (<% if HasReports %>).
     *
     * @return bool True when there is at least one report.
     */
    static function getHasReports()
    {
        return count(goaccess_helper::ReportsFor(ctrl_users::GetUserDetail())) > 0;
    }

    /**
     * Check whether the current user has no reports (<% if HasNoReports %>).
     *
     * @return bool True when there are none.
     */
    static function getHasNoReports()
    {
        return !self::getHasReports();
    }

    /**
     * Check whether the current user is an administrator (<% if IsAdmin %>).
     *
     * @return bool True for the Administrators group (ID 1).
     */
    static function getIsAdmin()
    {
        $u = ctrl_users::GetUserDetail();
        return (int) $u['usergroupid'] === 1;
    }

    /**
     * Return the key of the report shown.
     *
     * The one chosen (URL 'show'), otherwise the all-domains report for
     * administrators and the first domain for everyone else.
     *
     * @global runtime_controller $controller The request controller.
     * @param array<string,array{0:string,1:string[],2:int}> $reports Reports from goaccess_helper::ReportsFor().
     * @return string The report key, or '' when no report has been made yet.
     */
    static function CurrentKey($reports)
    {
        global $controller;
        $key = (string) $controller->GetControllerRequest('URL', 'show');
        if ($key !== '' && isset($reports[$key])) {
            return $key;
        }
        if (isset($reports['all']) && is_file(goaccess_helper::ReportFile('all'))) {
            return 'all';
        }
        foreach ($reports as $k => $r) {
            if (is_file(goaccess_helper::ReportFile($k))) {
                return $k;
            }
        }
        return '';
    }

    /**
     * How far a time is from now, in words ("23 min ago").
     *
     * @param int $time A Unix time.
     * @return string The words.
     */
    static function Ago($time)
    {
        $d = time() - (int) $time;
        if ($d < 60) {
            return ui_language::translate('just now');
        }
        if ($d < 3600) {
            return floor($d / 60) . ' min ' . ui_language::translate('ago');
        }
        if ($d < 172800) {
            return floor($d / 3600) . ' h ' . ui_language::translate('ago');
        }
        return floor($d / 86400) . ' ' . ui_language::translate('days ago');
    }

    /**
     * The reports the user may see, with the title, group and update time of each, in the order shown.
     *
     * @return array<string, array{title: string, group: string, made: int}> Report key => details ('made' is 0 when not made yet).
     */
    static function Listed()
    {
        static $list = null;
        if ($list !== null) {
            return $list;
        }
        $reports = goaccess_helper::ReportsFor(ctrl_users::GetUserDetail());
        $server = array();
        $groups = array();
        $names = array();
        foreach ($reports as $key => $r) {
            $file = goaccess_helper::ReportFile($key);
            $made = is_file($file) ? (int) filemtime($file) : 0;
            $owner = (int) $r[2];
            if ($owner === 0) {
                // the panel's report names the panel's domain: put in after translating
                $title = ($key === 'panel') ? str_replace('%s', (string) ctrl_options::GetSystemOption('sentora_domain'), ui_language::translate('Control panel (%s)'))
                    : ui_language::translate($r[0]);
                $server[$key] = array('title' => $title, 'group' => ui_language::translate('Server'), 'made' => $made);
                continue;
            }
            if (!isset($names[$owner])) {
                $u = ctrl_users::GetUserDetail($owner);
                $names[$owner] = $u ? $u['username'] : (string) $owner;
            }
            $groups[$names[$owner]][$key] = array('title' => $key, 'group' => $names[$owner], 'made' => $made);
        }
        ksort($groups);
        $list = $server;
        foreach ($groups as $g) {
            ksort($g);
            $list += $g;
        }
        return $list;
    }

    /**
     * The report picker (<@ ReportPicker @>): a button with the report shown that opens a searchable list of every
     * report, grouped by account for administrators (there may be hundreds of domains), each with when it was updated.
     * Without scripts it is a plain drop-down.
     *
     * @return string The HTML.
     */
    static function getReportPicker()
    {
        $list = self::Listed();
        $reports = goaccess_helper::ReportsFor(ctrl_users::GetUserDetail());
        $current = self::CurrentKey($reports);
        $domains = 0;
        $items = '';
        $options = '';
        $group = null;
        foreach ($list as $key => $r) {
            if ($r['group'] !== $group) {
                $multi = count(array_unique(array_column($list, 'group'))) > 1;
                if ($multi) {
                    $items .= '<li class="ga-grp">' . self::h($r['group']) . '</li>';
                }
                $options .= ($group !== null && $multi ? '</optgroup>' : '') . ($multi ? '<optgroup label="' . self::h($r['group']) . '">' : '');
                $group = $r['group'];
            }
            if (!in_array($key, array('all', 'panel'), true)) {
                $domains++;
            }
            $when = $r['made'] ? self::Ago($r['made']) : ui_language::translate('not made yet');
            $items .= '<li><a class="ga-item' . ($key === $current ? ' is-current' : '') . ($r['made'] ? '' : ' is-empty') . '" href="./?module=goaccess_stats&amp;show=' . self::h(rawurlencode($key)) . '"'
                . ' data-s="' . self::h(strtolower($r['title'] . ' ' . $r['group'])) . '"' . ($key === $current ? ' aria-current="true"' : '') . '>'
                . '<span>' . self::h($r['title']) . '</span><small>' . self::h($when) . '</small></a></li>';
            $options .= '<option value="' . self::h($key) . '"' . ($key === $current ? ' selected' : '') . ($r['made'] ? '' : ' disabled') . '>' . self::h($r['title']) . '</option>';
        }
        if ($group !== null && count(array_unique(array_column($list, 'group'))) > 1) {
            $options .= '</optgroup>';
        }
        $cur = isset($list[$current]) ? $list[$current] : null;
        return '<div class="ga-pick">'
            . '<button type="button" class="ga-pick-btn" aria-haspopup="listbox" aria-expanded="false" hidden>'
            . '<svg class="ga-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>'
            . '<span class="ga-pick-label"><small>' . self::h(ui_language::translate('Report')) . '</small><b>' . self::h($cur ? $cur['title'] : ui_language::translate('Choose a report')) . '</b></span>'
            . '<span class="ga-caret" aria-hidden="true">&#9662;</span></button>'
            . '<div class="ga-pop" hidden><input type="search" class="form-control ga-find" placeholder="' . self::h(ui_language::translate('Find a domain or account')) . '" aria-label="' . self::h(ui_language::translate('Find a domain or account')) . '">'
            . '<ul class="ga-list">' . $items . '</ul><p class="ga-none" hidden>' . self::h(ui_language::translate('No report matches.')) . '</p>'
            . '<div class="ga-pop-foot">' . $domains . ' ' . self::h(ui_language::translate($domains === 1 ? 'domain' : 'domains')) . '</div></div>'
            . '<form method="get" action="./" class="ga-plain"><input type="hidden" name="module" value="goaccess_stats">'
            . '<select name="show" class="form-control" aria-label="' . self::h(ui_language::translate('Report')) . '" onchange="this.form.submit()">' . $options . '</select>'
            . '<noscript><button type="submit" class="btn btn-primary">' . self::h(ui_language::translate('Open')) . '</button></noscript></form>'
            . '</div>';
    }

    /**
     * Facts about the report shown, and its buttons (<@ ReportInfo @>): when it was updated and will be next, its size, New window and Reload.
     *
     * @return string The HTML ('' when no report is ready).
     */
    static function getReportInfo()
    {
        $list = self::Listed();
        $key = self::CurrentKey(goaccess_helper::ReportsFor(ctrl_users::GetUserDetail()));
        if ($key === '' || empty($list[$key]['made'])) {
            return '';
        }
        $made = $list[$key]['made'];
        $next = (int) (floor(time() / 3600) + 1) * 3600;
        $size = filesize(goaccess_helper::ReportFile($key));
        $url = 'modules/goaccess_stats/code/report.php?r=' . rawurlencode($key);
        return '<div class="ga-facts">'
            . '<span title="' . self::h(date('Y-m-d H:i', $made)) . '"><b>' . self::h(ui_language::translate('Updated')) . '</b> ' . self::h(self::Ago($made)) . '</span>'
            . '<span><b>' . self::h(ui_language::translate('Next update')) . '</b> ' . self::h(date('H:i', $next)) . '</span>'
            . '<span><b>' . self::h(ui_language::translate('Size')) . '</b> ' . self::h($size >= 1048576 ? round($size / 1048576, 1) . ' MB' : max(1, round($size / 1024)) . ' KB') . '</span>'
            . '</div><div class="ga-btns">'
            . '<button type="button" class="btn btn-default btn-sm ga-reload" title="' . self::h(ui_language::translate('Reload the report')) . '"><svg class="ga-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>' . self::h(ui_language::translate('Reload')) . '</button>'
            . '<a class="btn btn-default btn-sm ga-newwin" href="' . self::h($url) . '" data-src="' . self::h($url) . '" target="_blank" rel="noopener"><svg class="ga-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg>' . self::h(ui_language::translate('New window')) . '</a>'
            . '</div>';
    }

    /**
     * The chosen report has not been made yet (template <% if NotMadeYet %>).
     *
     * @return bool
     */
    static function getNotMadeYet()
    {
        $key = self::CurrentKey(goaccess_helper::ReportsFor(ctrl_users::GetUserDetail()));
        $list = self::Listed();
        return $key === '' || empty($list[$key]['made']);
    }

    /**
     * Build the frame that shows the chosen report (<@ Frame @>).
     *
     * The page's script sets the frame's address, adding the panel's Light or Dark so the report matches.
     *
     * @return string HTML iframe, or '' when no report is ready.
     */
    static function getFrame()
    {
        $reports = goaccess_helper::ReportsFor(ctrl_users::GetUserDetail());
        $key = self::CurrentKey($reports);
        if ($key === '' || !is_file(goaccess_helper::ReportFile($key))) {
            return '';
        }
        $url = 'modules/goaccess_stats/code/report.php?r=' . rawurlencode($key);
        $list = self::Listed();
        $title = ((int) $reports[$key][2] === 0 && isset($list[$key])) ? $list[$key]['title'] : $reports[$key][0];
        return '<iframe class="ga-frame sn-autoheight" data-src="' . self::h($url) . '" title="' . self::h('GoAccess: ' . $title) . '"></iframe>'
            . '<noscript><iframe class="ga-frame" src="' . self::h($url) . '" title="GoAccess"></iframe></noscript>'
            . '<script src="etc/lib/sentora/frame-height.js"></script>';
    }

}
