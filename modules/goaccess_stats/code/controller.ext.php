<?php

/**
 * GoAccess module: controller for the web traffic reports page.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
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
     * Build the report drop-down (<@ ReportPicker @>).
     *
     * One drop-down for every report (there may be hundreds of domains): grouped by
     * account for administrators, each with the time it was last updated.
     *
     * @return string HTML form.
     */
    static function getReportPicker()
    {
        $user = ctrl_users::GetUserDetail();
        $reports = goaccess_helper::ReportsFor($user);
        $current = self::CurrentKey($reports);
        $df = 'Y-m-d H:i';
        $option = function ($key, $label) use ($current, $df) {
            $file = goaccess_helper::ReportFile($key);
            $made = is_file($file);
            return '<option value="' . self::h($key) . '"' . ($key === $current ? ' selected' : '') . ($made ? '' : ' disabled') . '>'
                . self::h($label) . ' (' . self::h($made ? ui_language::translate('updated') . ' ' . date($df, filemtime($file)) : ui_language::translate('not made yet')) . ')</option>';
        };
        // server-wide reports first, then each account's domains in alphabetical order
        $server = array();
        $groups = array();
        $names = array();
        foreach ($reports as $key => $r) {
            $owner = (int) $r[2];
            if ($owner === 0) {
                $server[$key] = $r[0];
                continue;
            }
            if (!isset($names[$owner])) {
                $u = ctrl_users::GetUserDetail($owner);
                $names[$owner] = $u ? $u['username'] : (string) $owner;
            }
            $groups[$names[$owner]][$key] = $key; // the domain itself; the account is the group
        }
        ksort($groups);
        $html = '<form method="get" action="./" class="form-inline" style="margin: 10px 0 15px;">'
            . '<input type="hidden" name="module" value="goaccess_stats">'
            . '<label for="goaccess-report" style="margin-right: 8px;">' . ui_language::translate('Report') . ':</label>'
            . '<select id="goaccess-report" name="show" class="form-control" style="min-width: 360px; max-width: 100%;" onchange="this.form.submit()">';
        if ($current === '') {
            $html .= '<option value="">' . ui_language::translate('-- Select a report --') . '</option>';
        }
        if ($server) {
            $html .= '<optgroup label="' . self::h(ui_language::translate('Server')) . '">';
            foreach ($server as $key => $label) {
                $html .= $option($key, ui_language::translate($label));
            }
            $html .= '</optgroup>';
        }
        $count = 0;
        foreach ($groups as $account => $keys) {
            ksort($keys);
            $count += count($keys);
            $inner = '';
            foreach ($keys as $key) {
                $inner .= $option($key, $key);
            }
            // administrators see every account, as a group; an account sees just its own list
            $html .= ($server || count($groups) > 1) ? '<optgroup label="' . self::h($account) . '">' . $inner . '</optgroup>' : $inner;
        }
        $html .= '</select> <button type="submit" class="btn btn-primary">' . ui_language::translate('Open') . '</button>';
        if ($current !== '' && is_file(goaccess_helper::ReportFile($current))) {
            $html .= ' <a class="btn btn-default" href="modules/goaccess_stats/code/report.php?r=' . self::h(rawurlencode($current)) . '" target="_blank" rel="noopener">'
                . ui_language::translate('New window') . '</a>';
        }
        $html .= ' <span class="text-muted" style="margin-left: 8px;">' . $count . ' ' . ui_language::translate($count === 1 ? 'domain' : 'domains') . '</span></form>';
        return $html;
    }

    /**
     * Build the frame that shows the chosen report (<@ Frame @>).
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return string HTML heading and iframe, or '' when no report is ready.
     */
    static function getFrame()
    {
        global $controller;
        $reports = goaccess_helper::ReportsFor(ctrl_users::GetUserDetail());
        $key = self::CurrentKey($reports);
        if ($key === '' || !is_file(goaccess_helper::ReportFile($key))) {
            return '';
        }
        return '<h3>' . self::h($reports[$key][0]) . '</h3>'
            . '<iframe class="sn-autoheight" src="modules/goaccess_stats/code/report.php?r=' . self::h(rawurlencode($key)) . '" title="GoAccess" style="width: 100%; height: 1100px; border: 1px solid var(--sn-line, #ddd);"></iframe>'
            . '<script src="etc/lib/sentora/frame-height.js"></script>';
    }

}

?>
