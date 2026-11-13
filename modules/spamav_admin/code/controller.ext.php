<?php

/**
 * SpamAV Admin module: controller.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-11
 */

/**
 * SpamAV Admin (administrators): ClamAV and SpamAssassin settings, status, updates.
 * Settings are saved here and applied by the daemon (modules/spamav_admin/hooks).
 */
class module_controller extends ctrl_module
{
    /** @var array<int, array{0: string, 1: string, 2?: bool}> Messages for getResult(): array('ok' or 'error', English text), or with true third: the text already translated. */
    static $msg = array();

    /** @var array<string, string>|null The settings as posted when saving was refused, so the form shows what was typed. */
    static $posted = null;

    /** @var string|null The section ('clamav' or 'spamassassin') saved or refused in this request: its tab opens. */
    static $section = null;

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
     * Translate a text.
     *
     * @param string $s The English text.
     * @return string The translation.
     */
    static function t($s)
    {
        return ui_language::translate($s);
    }

    /**
     * Check whether the current user is an administrator.
     *
     * @return bool True for the Administrators group (ID 1).
     */
    static function IsAdmin()
    {
        return (int) ctrl_users::GetUserDetail()['usergroupid'] === 1;
    }

    /**
     * Save the posted settings of one section (ClamAV or SpamAssassin) to x_settings.
     *
     * Every value is checked with sys_spamav::Clean(); nothing is saved when one is
     * wrong. The daemon applies the settings.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doSave()
    {
        global $zdbh, $controller;
        runtime_csfr::Protect();
        if (!self::IsAdmin()) {
            return;
        }
        $section = (string) $controller->GetControllerRequest('FORM', 'inSection');
        if (!in_array($section, array('clamav', 'spamassassin', 'filescan'), true)) {
            return;
        }
        self::$section = $section;
        $f = $controller->GetAllControllerRequests('FORM');
        $errors = array();
        $clean = array();
        foreach (sys_spamav::Definitions() as $name => $d) {
            if ($d[0] !== $section) {
                continue;
            }
            $v = ($d[2] === 'bool') ? (isset($f['in_' . $name]) ? 'true' : 'false') : (isset($f['in_' . $name]) ? (string) $f['in_' . $name] : '');
            list($ok, $val) = sys_spamav::Clean($name, $v);
            if ($ok) {
                $clean[$name] = $val;
            } else {
                $errors[] = array(self::CleanError($name, $d, $val), true);
            }
        }
        if ($section === 'spamassassin' && isset($clean['sa_reject_score'], $clean['sa_required_score']) && (float) $clean['sa_reject_score'] > 0 && (float) $clean['sa_reject_score'] <= (float) $clean['sa_required_score']) {
            $errors[] = array('Reject at score must be above the spam score (or 0).', false);
        }
        if ($errors) {
            // keep what was typed for the form
            self::$posted = array();
            foreach (sys_spamav::Definitions() as $name => $d) {
                if ($d[0] === $section) {
                    self::$posted[$name] = ($d[2] === 'bool') ? (isset($f['in_' . $name]) ? 'true' : 'false') : (isset($f['in_' . $name]) ? (string) $f['in_' . $name] : '');
                }
            }
            foreach ($errors as $e) {
                self::$msg[] = array('error', $e[0], $e[1]);
            }
            return;
        }
        $up = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = :n AND so_module_vc = '" . sys_spamav::MODULE . "'");
        foreach ($clean as $name => $val) {
            $up->execute(array(':v' => $val, ':n' => $name));
        }
        self::$msg[] = array('ok', $section === 'filescan' ? 'File scanning settings saved.' : ($section === 'clamav' ? 'ClamAV' : 'SpamAssassin') . ' settings saved. The daemon applies them within 5 minutes.');
    }

    /**
     * Say in the account's language why a value was refused (sys_spamav::Clean() gives it in
     * English with the setting's label and the limits in it, which are not translated as a whole).
     *
     * @param string $name The setting name.
     * @param array<int, mixed> $d The setting's definition from sys_spamav::Definitions().
     * @param string $english Clean()'s message (shown as it is when its form is not known).
     * @return string The translated message (plain text, not escaped).
     */
    static function CleanError($name, $d, $english)
    {
        $label = self::t($d[1]);
        $prefix = $d[1] . ': ';
        if (strpos($english, $prefix) !== 0) {
            return $english;
        }
        $rest = substr($english, strlen($prefix));
        $fill = function ($text, $values) use ($label) {
            return str_replace(array_merge(array('%label%'), array_keys($values)), array_merge(array($label), array_values($values)), self::t($text));
        };
        if ($d[2] === 'int' && $rest === 'a whole number from ' . $d[5][0] . ' to ' . $d[5][1] . '.') {
            return $fill('%label%: a whole number from %min% to %max%.', array('%min%' => $d[5][0], '%max%' => $d[5][1]));
        }
        if ($d[2] === 'float' && $rest === 'a number from ' . $d[5][0] . ' to ' . $d[5][1] . '.') {
            return $fill('%label%: a number from %min% to %max%.', array('%min%' => $d[5][0], '%max%' => $d[5][1]));
        }
        if ($rest === 'choose one of the options.') {
            return $fill('%label%: choose one of the options.', array());
        }
        if ($d[2] === 'text' && $rest === 'at most ' . $d[5][0] . ' characters, on one line.') {
            return $fill('%label%: at most %max% characters, on one line.', array('%max%' => $d[5][0]));
        }
        if ($rest === 'at most 500 lines.') {
            return $fill('%label%: at most %max% lines.', array('%max%' => '500'));
        }
        if (preg_match('/^"(.*)" is not (a network like 192\.0\.2\.0\/24\.|an address or a pattern like \*@example\.com\.)$/s', $rest, $m)) {
            $text = ($name === 'sa_trusted_networks') ? '%label%: "%value%" is not a network like 192.0.2.0/24.' : '%label%: "%value%" is not an address or a pattern like *@example.com.';
            return $fill($text, array('%value%' => $m[1]));
        }
        return $english;
    }

    /**
     * Queue an action for the daemon: update signatures, update rules or restart the scanners.
     *
     * Writes sys_spamav::REQUEST as JSON.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doRequest()
    {
        global $controller;
        runtime_csfr::Protect();
        if (!self::IsAdmin()) {
            return;
        }
        $what = (string) $controller->GetControllerRequest('FORM', 'inWhat');
        if (!in_array($what, array('signatures', 'rules', 'restart'), true)) {
            return;
        }
        file_put_contents(sys_spamav::REQUEST, json_encode(array('action' => $what, 'by' => ctrl_users::GetUserDetail()['username'], 'time' => time())));
        self::$msg[] = array('ok', array('signatures' => 'Virus signature update', 'rules' => 'Spam rule update', 'restart' => 'Restart of the scanners')[$what] . ' queued: it starts within 5 minutes.');
    }

    /**
     * The groups of settings within each section, in order. A setting not listed here goes under "Other".
     *
     * @return array<string, array<int, array{0: string, 1: array<int, string>}>> Section => list of [group title, setting names].
     */
    static function Groups()
    {
        return array(
            'clamav' => array(
                array('Scanning', array('clam_enabled', 'clam_on_infected', 'clam_on_fail', 'clam_scan_outgoing', 'clam_add_header')),
                array('What is checked', array('clam_scan_archive', 'clam_scan_pdf', 'clam_scan_ole2', 'clam_scan_html', 'clam_phishing_sigs', 'clam_phishing_urls', 'clam_heuristic', 'clam_block_macros', 'clam_alert_encrypted')),
                array('Limits and updates', array('clam_max_file_size', 'clam_max_scan_size', 'clam_max_recursion', 'clam_max_files', 'clam_threads', 'clam_db_checks')),
            ),
            'filescan' => array(
                array('The nightly scan', array('fs_enabled', 'fs_hour', 'fs_full_day')),
                array('What is scanned', array('fs_max_file_size', 'fs_backups')),
                array('When something is found', array('fs_quarantine', 'fs_notify_owner')),
            ),
            'spamassassin' => array(
                array('Filtering', array('sa_enabled', 'sa_required_score', 'sa_reject_score', 'sa_subject_tag', 'sa_report_safe', 'sa_default_action', 'sa_skip_outgoing')),
                array('Mailbox owners', array('sa_user_settings')),
                array('Senders and networks', array('sa_allow_from', 'sa_block_from', 'sa_trusted_networks')),
                array('Learning', array('sa_use_bayes', 'sa_bayes_auto_learn', 'sa_auto_learn_spam', 'sa_auto_learn_ham')),
                array('Performance and upkeep', array('sa_network_tests', 'sa_rules_update', 'sa_max_size', 'sa_children', 'spamav_keep_days')),
            ),
        );
    }

    /**
     * The value to show for a setting: what was posted when saving was refused, else the saved one.
     *
     * @param string $name The setting name.
     * @return string The value.
     */
    static function Value($name)
    {
        return (self::$posted !== null && isset(self::$posted[$name])) ? self::$posted[$name] : (string) sys_spamav::Get($name);
    }

    /**
     * Build one setting: label, field and help, or a switch row for on/off settings.
     *
     * @param string $name The setting name.
     * @param array<int, mixed> $d The setting's definition from sys_spamav::Definitions().
     * @return string The HTML ('' for an unknown type).
     */
    static function Field($name, $d)
    {
        $v = self::Value($name);
        $n = 'in_' . $name;
        $id = 'sa_' . $name;
        // a help text may name this server's memory (%s, in GB)
        $help = $d[4] !== '' ? '<small>' . self::h(str_replace('%s', (string) round(sys_spamav::MemoryMB() / 1024, 1), self::t($d[4]))) . '</small>' : '';
        $label = self::h(self::t($d[1]));
        $wrap = function ($field, $extra = '', $cls = '') use ($id, $label, $help, $name) {
            return '<div class="sa-field' . $cls . '" data-name="' . self::h($name) . '"><label for="' . $id . '">' . $label . '</label>' . $field . $extra . $help . '</div>';
        };
        switch ($d[2]) {
            case 'bool':
                return '<div class="sa-field sa-switchrow" data-name="' . self::h($name) . '"><div><label for="' . $id . '">' . $label . '</label>' . $help . '</div>'
                    . '<label class="sa-switch"><input type="checkbox" id="' . $id . '" name="' . $n . '" value="1"' . ($v === 'true' ? ' checked' : '') . '><span class="sa-toggle"></span></label></div>';
            case 'int':
            case 'float':
                $step = $d[2] === 'int' ? '1' : 'any';
                return $wrap('<div class="sa-num"><input class="form-control" type="number" id="' . $id . '" name="' . $n . '" value="' . self::h($v) . '" min="' . self::h($d[5][0]) . '" max="' . self::h($d[5][1]) . '" step="' . $step . '" required>'
                    . '<span>' . self::h($d[5][0] . ' – ' . $d[5][1]) . '</span></div>');
            case 'select':
                $o = '';
                foreach ($d[5] as $k => $l) {
                    $o .= '<option value="' . self::h($k) . '"' . ((string) $k === $v ? ' selected' : '') . '>' . self::h(self::t($l)) . '</option>';
                }
                return $wrap('<select class="form-control" id="' . $id . '" name="' . $n . '">' . $o . '</select>');
            case 'text':
                return $wrap('<input class="form-control" type="text" id="' . $id . '" name="' . $n . '" value="' . self::h($v) . '" maxlength="' . (int) $d[5][0] . '" autocomplete="off">');
            case 'list':
                $count = count(array_filter(preg_split('/[\r\n]+/', $v), function ($l) { return trim($l) !== ''; }));
                return '<div class="sa-field sa-listf" data-name="' . self::h($name) . '"><label for="' . $id . '">' . $label . ' <span class="sa-n">' . $count . '</span></label>'
                    . '<textarea class="form-control sa-list" id="' . $id . '" name="' . $n . '" rows="5" spellcheck="false">' . self::h($v) . '</textarea>' . $help . '</div>';
        }
        return '';
    }

    /**
     * Build the settings form of one section, in groups (<@ ClamForm @>, <@ SpamForm @>).
     *
     * @param string $key Section key: 'clamav' or 'spamassassin'.
     * @return string The HTML.
     */
    static function Section($key)
    {
        $defs = sys_spamav::Definitions();
        $groups = self::Groups();
        $groups = isset($groups[$key]) ? $groups[$key] : array();
        $done = array();
        foreach ($groups as $g) {
            $done = array_merge($done, $g[1]);
        }
        $other = array();
        foreach ($defs as $name => $d) {
            if ($d[0] === $key && !in_array($name, $done, true)) {
                $other[] = $name;
            }
        }
        if ($other) {
            $groups[] = array('Other', $other);
        }
        $out = '';
        foreach ($groups as $g) {
            $fields = '';
            foreach ($g[1] as $name) {
                if (isset($defs[$name]) && $defs[$name][0] === $key) {
                    $fields .= self::Field($name, $defs[$name]);
                }
            }
            if ($fields !== '') {
                $out .= '<fieldset class="sa-group"><legend>' . self::h(self::t($g[0])) . '</legend>'
                    . ($g[0] === 'Filtering' ? self::ScoreScale() : '') . '<div class="sa-fields">' . $fields . '</div></fieldset>';
            }
        }
        return '<form class="sa-form" method="post" action="./?module=spamav_admin&amp;action=Save"><input type="hidden" name="inSection" value="' . $key . '">' . $out
            . '<div class="sa-foot"><span class="sa-dirty" hidden>' . self::h(self::t('Unsaved changes')) . '</span><span class="sa-hint">'
            . self::h(self::t($key === 'filescan' ? 'Saved at once: the next scan uses them.' : 'The daemon applies changes within 5 minutes; only the parts that changed are restarted.')) . '</span>'
            . runtime_csfr::Token() . '<button class="btn btn-primary" type="submit">' . self::h(self::t('Save changes')) . '</button></div></form>';
    }

    /**
     * A scale from 0 to 25 showing where the spam score and the reject score are (they move as the fields change).
     *
     * @return string The HTML.
     */
    static function ScoreScale()
    {
        return '<div class="sa-scale" aria-hidden="true"><div class="sa-scale-bar"><i class="sa-z-ok"></i><i class="sa-z-spam"></i><i class="sa-z-reject"></i></div>'
            . '<div class="sa-scale-keys"><span class="sa-k-ok">' . self::h(self::t('Delivered')) . '</span><span class="sa-k-spam">' . self::h(self::t('Marked as spam')) . '</span><span class="sa-k-reject">' . self::h(self::t('Refused')) . '</span></div></div>';
    }

    /**
     * The ClamAV settings form (<@ ClamForm @>).
     *
     * @return string The HTML.
     */
    static function getClamForm()
    {
        return self::Section('clamav');
    }

    /**
     * The SpamAssassin settings form (<@ SpamForm @>).
     *
     * @return string The HTML.
     */
    static function getSpamForm()
    {
        return self::Section('spamassassin');
    }

    /**
     * The tab to open first: the section just saved or refused, else none (template data attribute <@ OpenTab @>).
     *
     * @return string 'clamav', 'spamassassin' or ''.
     */
    static function getOpenTab()
    {
        global $controller;
        if (self::$section === 'filescan' || (string) $controller->GetControllerRequest('URL', 'tab') === 'files') {
            return 'files';
        }
        return (string) self::$section;
    }

    /**
     * Whether the current user may see this module's page (template <% if Admin %>).
     *
     * @return bool
     */
    static function getAdmin()
    {
        if (self::IsAdmin()) {
            sys_spamav::Ensure();
            return true;
        }
        return false;
    }

    /**
     * The daemon's status file, or null when it has not written one yet.
     *
     * @return array<string, mixed>|null
     */
    static function Status()
    {
        static $s = false;
        if ($s === false) {
            $s = @json_decode((string) @file_get_contents(sys_spamav::STATUS), true);
            if (!is_array($s)) {
                $s = null;
            }
        }
        return $s;
    }

    /**
     * A button that queues a job for the daemon.
     *
     * @param string $what 'signatures', 'rules' or 'restart'.
     * @param string $label The button text.
     * @param string $icon SVG path data for the icon.
     * @return string The HTML.
     */
    static function JobButton($what, $label, $icon)
    {
        return '<form class="sa-job-form" method="post" action="./?module=spamav_admin&amp;action=Request"><input type="hidden" name="inWhat" value="' . $what . '">'
            . runtime_csfr::Token() . '<button class="btn btn-default btn-sm" type="submit"><svg class="sa-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icon . '</svg>'
            . self::h(self::t($label)) . '</button></form>';
    }

    /**
     * The two scanner cards: services with their state, versions, signatures or rules, and the update buttons (<@ Scanners @>).
     *
     * @return string The HTML.
     */
    static function getScanners()
    {
        $s = self::Status();
        if ($s === null) {
            return '<p class="sa-empty">' . self::h(self::t('The daemon has not checked the scanners yet (within 5 minutes after installing).')) . '</p>';
        }
        $u = isset($s['units']) ? $s['units'] : array();
        $svc = function ($unit, $label, $wanted) use ($u) {
            if (!$wanted) {
                return '<li><span class="sa-dot off"></span>' . self::h(self::t($label)) . '<em>' . self::h(self::t('switched off')) . '</em></li>';
            }
            $up = !empty($u[$unit]);
            return '<li><span class="sa-dot ' . ($up ? 'up' : 'down') . '"></span>' . self::h(self::t($label)) . ' <code>' . self::h($unit) . '</code><em class="' . ($up ? '' : 'bad') . '">' . self::h(self::t($up ? 'running' : 'not running')) . '</em></li>';
        };
        // each service under this system's own name (the daemon records them so: clamd@scan on some systems)
        $n = array();
        foreach (array('clamd_service', 'clamav_milter_service', 'freshclam_service', 'spamd_service', 'spamass_milter_service') as $k) {
            $n[$k] = sys_platform::Get($k);
        }
        $clam = sys_spamav::On('clam_enabled');
        $sa = sys_spamav::On('sa_enabled');
        $clamUp = !$clam || (!empty($u[$n['clamd_service']]) && !empty($u[$n['clamav_milter_service']]));
        $saUp = !$sa || (!empty($u[$n['spamd_service']]) && !empty($u[$n['spamass_milter_service']]));
        $state = function ($on, $up) {
            return !$on ? '<span class="sa-pill off">' . self::h(self::t('Off')) . '</span>'
                : ($up ? '<span class="sa-pill on">' . self::h(self::t('Working')) . '</span>' : '<span class="sa-pill bad">' . self::h(self::t('Needs attention')) . '</span>');
        };
        $sig = '';
        foreach (array('daily', 'main') as $db) {
            if (isset($s['sigs'][$db])) {
                $x = $s['sigs'][$db];
                $sig .= '<div><b>' . self::h($db) . '</b> ' . self::h(self::t('version')) . ' ' . self::h($x['version'] ?? '?') . (isset($x['built']) ? ' &middot; ' . self::h($x['built']) : '')
                    . (isset($x['signatures']) ? ' &middot; ' . number_format((int) $x['signatures']) . ' ' . self::h(self::t('signatures')) : '') . '</div>';
            }
        }
        if ($sig === '') {
            $sig = $clam ? '<div class="sa-warn">' . self::h(self::t('Waiting for the first download; the scanner starts when it is done.')) . '</div>' : '<div>-</div>';
        }
        $scanWith = !empty($s['milters']) ? implode(', ', array_map(function ($m) { return strpos($m, 'clamav') !== false ? 'ClamAV' : 'SpamAssassin'; }, $s['milters'])) : self::t('nothing');
        $lowmem = $clam && (int) ($s['memory_mb'] ?? 0) > 0 && (int) $s['memory_mb'] < 1900;

        $out = '<div class="sa-scanner"><div class="sa-sc-head"><svg class="sa-ico-lg sa-ico-clam" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><circle cx="12" cy="11" r="3"/><path d="M12 6v2M12 14v2M7 11h2M15 11h2"/></svg>'
            . '<div><h4>' . self::h(self::t('Virus scanning')) . '</h4><span>ClamAV ' . self::h($s['versions']['clamav'] ?? '') . '</span></div>' . $state($clam, $clamUp) . '</div>'
            . '<ul class="sa-svcs">' . $svc($n['clamd_service'], 'Scanner', $clam) . $svc($n['clamav_milter_service'], 'Mail connection', $clam) . $svc($n['freshclam_service'], 'Signature updates', $clam) . '</ul>'
            . '<div class="sa-facts"><span>' . self::h(self::t('Signatures')) . '</span>' . $sig . '</div>'
            . ($lowmem ? '<div class="sa-warn">' . self::h(self::t('This server has less than 2 GB of memory; ClamAV may not have enough.')) . '</div>' : '')
            . '<div class="sa-sc-foot">' . self::JobButton('signatures', 'Update signatures now', '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>') . '</div></div>';

        $out .= '<div class="sa-scanner"><div class="sa-sc-head"><svg class="sa-ico-lg sa-ico-spam" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="m4 7 8 6 8-6"/><path d="M3 21 21 3"/></svg>'
            . '<div><h4>' . self::h(self::t('Spam filtering')) . '</h4><span>SpamAssassin ' . self::h($s['versions']['spamassassin'] ?? '') . '</span></div>' . $state($sa, $saUp) . '</div>'
            . '<ul class="sa-svcs">' . $svc($n['spamd_service'], 'Spam checker', $sa) . $svc($n['spamass_milter_service'], 'Mail connection', $sa) . '</ul>'
            . '<div class="sa-facts"><span>' . self::h(self::t('Rules updated')) . '</span><div>' . (!empty($s['rules_updated']) ? self::h(date('Y-m-d H:i', (int) $s['rules_updated'])) : '-') . '</div></div>'
            . '<div class="sa-facts"><span>' . self::h(self::t('Postfix scans with')) . '</span><div>' . self::h($scanWith) . '</div></div>'
            . '<div class="sa-sc-foot">' . self::JobButton('rules', 'Update rules now', '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>') . '</div></div>';
        return $out;
    }

    /**
     * The bar above the scanners: when the daemon last checked, the last job asked for here, and the restart button (<@ JobBar @>).
     *
     * @return string The HTML.
     */
    static function getJobBar()
    {
        $s = self::Status();
        $last = '';
        if ($s !== null && isset($s['last_request'])) {
            $r = $s['last_request'];
            $jobs = array(
                'Update virus signatures' => array('Updating virus signatures', 'Virus signatures updated', 'Virus signature update failed'),
                'Update spam rules' => array('Updating spam rules', 'Spam rules updated', 'Spam rule update failed'),
                'Restart the scanners' => array('Restarting the scanners', 'Scanners restarted', 'Restart failed'),
            );
            // a job this page does not know is named as the daemon wrote it (not translated)
            $j = isset($jobs[$r['what']]) ? array_map(array(__CLASS__, 't'), $jobs[$r['what']]) : array($r['what'], $r['what'] . ': ' . self::t('done'), $r['what'] . ': ' . self::t('failed'));
            $when = date('H:i', (int) $r['time']) . (date('Y-m-d', (int) $r['time']) !== date('Y-m-d') ? ' ' . date('Y-m-d', (int) $r['time']) : '');
            $by = self::t('by') . ' ' . $r['by'] . ', ' . $when;
            if ($r['ok'] === null) {
                $last = '<div class="sa-job run"><span class="sa-spin" aria-hidden="true"></span><b>' . self::h($j[0]) . '&hellip;</b><span>' . self::h(self::t('started') . ' ' . $by . '. ' . self::t('This can take a few minutes; reload the page to see when it is done.')) . '</span></div>';
            } elseif ($r['ok']) {
                $last = '<div class="sa-job ok"><span class="sa-dot up"></span><b>' . self::h($j[1]) . '</b><span>' . self::h($by) . '</span></div>';
            } else {
                $last = '<div class="sa-job bad"><span class="sa-dot down"></span><b>' . self::h($j[2]) . '</b><span>' . self::h($by . ': ' . preg_replace('/^Failed:\s*/i', '', (string) $r['text'])) . '</span></div>';
            }
        }
        return '<div class="sa-jobbar">' . $last . '<span class="sa-checked">' . ($s !== null && isset($s['time']) ? self::h(self::t('Checked by the daemon at') . ' ' . date('H:i', (int) $s['time'])) : '') . '</span>'
            . self::JobButton('restart', 'Restart the scanners', '<path d="M12 3v9"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/>') . '</div>';
    }

    /**
     * Sums of the daily counts over every account since a number of days ago.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $days 1 for today, 7 for the last 7 days, and so on.
     * @return array<int, int> Checked, spam, viruses, refused.
     */
    static function Sums($days)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COALESCE(SUM(dd_scanned_in),0), COALESCE(SUM(dd_spam_in),0), COALESCE(SUM(dd_virus_in),0), COALESCE(SUM(dd_rejected_in),0) FROM x_spamav_daily WHERE dd_day >= :d");
        $q->execute(array(':d' => date('Y-m-d', time() - ($days - 1) * 86400)));
        return array_map('intval', $q->fetch(PDO::FETCH_NUM));
    }

    /**
     * The figures for all mail on the server (<@ Totals @>): the last 7 days, with today and 30 days under each.
     *
     * @return string The HTML.
     */
    static function getTotals()
    {
        $w = self::Sums(7);
        $d = self::Sums(1);
        $m = self::Sums(30);
        $pct = $w[0] > 0 ? round($w[1] / $w[0] * 100, 1) : 0;
        $tiles = array(
            array('Checked', 0, 'sa-t-checked', '<path d="M4 4h16v16H4z"/><path d="m4 7 8 6 8-6"/>', ''),
            array('Spam caught', 1, 'sa-t-spam', '<path d="M12 3 2 21h20z"/><path d="M12 10v4M12 17h.01"/>', $pct . '% ' . self::t('of the mail checked')),
            array('Viruses blocked', 2, 'sa-t-virus', '<circle cx="12" cy="12" r="5"/><path d="M12 2v5M12 17v5M2 12h5M17 12h5M5 5l3.5 3.5M15.5 15.5 19 19M5 19l3.5-3.5M15.5 8.5 19 5"/>', ''),
            array('Refused outright', 3, 'sa-t-refused', '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>', ''),
        );
        $out = '';
        foreach ($tiles as $t) {
            $out .= '<div class="sa-tile ' . $t[2] . '"><svg class="sa-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $t[3] . '</svg>'
                . '<div><b>' . number_format($w[$t[1]]) . '</b><span>' . self::h(self::t($t[0])) . '</span>'
                . '<small>' . self::h(self::t('Today')) . ' ' . number_format($d[$t[1]]) . ' &middot; ' . self::h(self::t('30 days')) . ' ' . number_format($m[$t[1]]) . '</small>'
                . ($t[4] !== '' ? '<small>' . self::h($t[4]) . '</small>' : '') . '</div></div>';
        }
        return $out;
    }

    /**
     * A bar for each of the last 14 days: spam and viruses caught on the whole server (<@ Chart @>).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML.
     */
    static function getChart()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT dd_day, SUM(dd_spam_in), SUM(dd_virus_in) FROM x_spamav_daily WHERE dd_day >= :d GROUP BY dd_day");
        $q->execute(array(':d' => date('Y-m-d', time() - 13 * 86400)));
        $by = array();
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
            $by[$r[0]] = array((int) $r[1], (int) $r[2]);
        }
        $max = 1;
        foreach ($by as $v) {
            $max = max($max, $v[0] + $v[1]);
        }
        $bars = '';
        for ($i = 13; $i >= 0; $i--) {
            $t = time() - $i * 86400;
            $v = isset($by[date('Y-m-d', $t)]) ? $by[date('Y-m-d', $t)] : array(0, 0);
            $tip = ($i === 0 ? self::t('Today') : ui_language::Date('D j M', $t)) . ': ' . $v[0] . ' ' . self::t('spam') . ', ' . $v[1] . ' ' . self::t($v[1] === 1 ? 'virus' : 'viruses');
            $bars .= '<div class="sa-bar" title="' . self::h($tip) . '"><div class="sa-stack"><i class="sa-b-virus" style="height:' . round($v[1] / $max * 100, 1) . '%"></i>'
                . '<i class="sa-b-spam" style="height:' . round($v[0] / $max * 100, 1) . '%"></i></div><span>' . date('j', $t) . '</span></div>';
        }
        return '<div class="sa-chart" role="img" aria-label="' . self::h(self::t('Spam and viruses caught, last 14 days')) . '">' . $bars . '</div>';
    }

    /**
     * The most common viruses and the mailboxes that get the most spam, over 30 days (<@ TopLists @>).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML.
     */
    static function getTopLists()
    {
        global $zdbh;
        $v = $zdbh->prepare("SELECT ev_detail_vc, COUNT(*) n FROM x_spamav_events WHERE ev_kind_vc = 'virus' AND ev_ts >= :t GROUP BY ev_detail_vc ORDER BY n DESC LIMIT 5");
        $v->execute(array(':t' => time() - 30 * 86400));
        $viruses = $v->fetchAll(PDO::FETCH_NUM);
        $b = $zdbh->prepare("SELECT dd_mailbox_vc, SUM(dd_spam_in) s, SUM(dd_virus_in) v FROM x_spamav_daily WHERE dd_day >= :d GROUP BY dd_mailbox_vc ORDER BY s DESC LIMIT 5");
        $b->execute(array(':d' => date('Y-m-d', time() - 29 * 86400)));
        $boxes = $b->fetchAll(PDO::FETCH_NUM);
        $list = function ($rows, $empty, $fmt) {
            if (!$rows) {
                return '<p class="sa-empty">' . self::h(self::t($empty)) . '</p>';
            }
            $max = max(1, (int) $rows[0][1]);
            $o = '<ol class="sa-top">';
            foreach ($rows as $r) {
                $o .= '<li><span class="sa-top-name">' . self::h($r[0] !== '' ? $r[0] : '-') . '</span><span class="sa-top-n">' . $fmt($r) . '</span>'
                    . '<i style="width:' . round((int) $r[1] / $max * 100, 1) . '%"></i></li>';
            }
            return $o . '</ol>';
        };
        return '<div class="sa-card"><h4>' . self::h(self::t('Most common viruses')) . ' <small>' . self::h(self::t('30 days')) . '</small></h4>'
            . $list($viruses, 'No viruses in the last 30 days.', function ($r) { return number_format((int) $r[1]); }) . '</div>'
            . '<div class="sa-card"><h4>' . self::h(self::t('Mailboxes with the most spam')) . ' <small>' . self::h(self::t('30 days')) . '</small></h4>'
            . $list($boxes, 'No spam in the last 30 days.', function ($r) { return number_format((int) $r[1]) . ' ' . self::h(self::t('spam')) . ((int) $r[2] ? ' &middot; ' . (int) $r[2] . ' ' . self::h(self::t((int) $r[2] === 1 ? 'virus' : 'viruses')) : ''); }) . '</div>';
    }

    // --- File scanning (the hosted websites; dryden/sys/filescan.class.php) ------------------------

    /**
     * The file scanning settings form (<@ FileForm @>).
     *
     * @return string The HTML.
     */
    static function getFileForm()
    {
        return self::Section('filescan');
    }

    /**
     * The file scanning buttons and the quarantine's Put back / Delete: a request the daemon (root)
     * carries out, started at once (zsudo daemon-run, with the note that keeps it when a run is busy).
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doFileRequest()
    {
        global $controller;
        runtime_csfr::Protect();
        if (!self::IsAdmin()) {
            return;
        }
        self::$section = 'filescan';
        $what = (string) $controller->GetControllerRequest('FORM', 'inWhat');
        $id = (string) $controller->GetControllerRequest('FORM', 'inId');
        if (!in_array($what, array('scan', 'full', 'restore', 'delete'), true) || (in_array($what, array('restore', 'delete'), true) && !preg_match('/^[a-f0-9]{16}$/', $id))) {
            return;
        }
        if (in_array($what, array('scan', 'full'), true) && !sys_spamav::On('clam_enabled')) {
            self::$msg[] = array('error', 'File scanning uses the virus scanner: switch on "Scan mail for viruses" (Virus scanning) first.');
            return;
        }
        file_put_contents(sys_filescan::REQUEST, json_encode(array('action' => $what, 'id' => $id, 'by' => ctrl_users::GetUserDetail()['username'], 'time' => time())));
        $zsudo = (string) ctrl_options::GetSystemOption('zsudo');
        if ($zsudo !== '' && is_executable($zsudo) && function_exists('exec')) {
            @touch('/var/lib/sentora/tmp/daemon-again');
            exec(escapeshellcmd($zsudo) . ' daemon-run > /dev/null 2>&1');
        }
        $m = array('scan' => 'Scan of the changed files started: the results show here when it is done.', 'full' => 'Scan of every file started: the results show here when it is done (this can take a while).',
            'restore' => 'The file is put back where it was in a moment.', 'delete' => 'The file is deleted from the quarantine in a moment.');
        self::$msg[] = array('ok', $m[$what]);
    }

    /**
     * A small form button for the file scan's requests.
     *
     * @param string $what 'scan', 'full', 'restore' or 'delete'.
     * @param string $label
     * @param string $icon SVG path data.
     * @param string $id The quarantined file (restore, delete).
     * @param string $confirm A question asked first ('' for none).
     * @return string The HTML.
     */
    static function FileButton($what, $label, $icon, $id = '', $confirm = '')
    {
        return '<form class="sa-job-form sa-fs-form" method="post" action="./?module=spamav_admin&amp;action=FileRequest"' . ($confirm !== '' ? ' data-confirm="' . self::h(self::t($confirm)) . '"' : '') . '>'
            . '<input type="hidden" name="inWhat" value="' . $what . '"><input type="hidden" name="inId" value="' . self::h($id) . '">'
            . runtime_csfr::Token() . '<button class="btn btn-default btn-sm" type="submit"><svg class="sa-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icon . '</svg>'
            . self::h(self::t($label)) . '</button></form>';
    }

    /**
     * Bytes in words (MB, GB).
     *
     * @param float|int $b
     * @return string
     */
    static function Size($b)
    {
        return $b >= 1073741824 ? sprintf('%.1f GB', $b / 1073741824) : ($b >= 1048576 ? sprintf('%.1f MB', $b / 1048576) : ($b >= 1024 ? sprintf('%d KB', $b / 1024) : sprintf('%d bytes', $b)));
    }

    /**
     * The malware family of a signature, for the ring: "Php.Webshell.Generic-123" -> "Php.Webshell".
     *
     * @param string $sig
     * @return string
     */
    static function Family($sig)
    {
        $p = explode('.', preg_replace('/-\d+(-\d+)?$/', '', (string) $sig));
        return implode('.', array_slice($p, 0, 2));
    }

    /**
     * The File scanning tab's results (<@ FileScan @>): its state and buttons, the last scan as tiles,
     * the last 14 scans as bars, what was found by family as a ring, and the list of findings with Put
     * back and Delete for the quarantined ones.
     *
     * @return string The HTML.
     */
    static function getFileScan()
    {
        $st = sys_filescan::State();
        $runs = (array) $st['runs'];
        $last = $runs ? end($runs) : null;
        $on = sys_spamav::On('fs_enabled');
        $clam = sys_spamav::On('clam_enabled');
        $running = isset($st['running']) && (int) $st['running']['started'] > time() - 6 * 3600;
        $hour = sprintf('%02d:00', (int) sys_spamav::Get('fs_hour'));
        // the state and the buttons
        $pill = !$clam ? '<span class="sa-pill bad">' . self::h(self::t('Needs the virus scanner')) . '</span>'
            : ($running ? '<span class="sa-pill run">' . self::h(self::t('Scanning now')) . '</span>'
            : ($on ? '<span class="sa-pill on">' . self::h(self::t('On')) . '</span>' : '<span class="sa-pill off">' . self::h(self::t('Off')) . '</span>'));
        $line = !$clam ? self::t('It uses ClamAV\'s scanner: switch on "Scan mail for viruses" on the Virus scanning tab.')
            : ($running ? sprintf(self::t('Started at %s; this page shows the results when it is done.'), date('H:i', (int) $st['running']['started']))
            : ($on ? sprintf(self::t('Every night at %s.'), $hour) : self::t('Not scanned at night: switch it on below, or scan now.')));
        $refresh = $running ? '<script>setTimeout(function () { location.replace("./?module=spamav_admin&tab=files"); }, 15000);</script>' : '';
        $out = '<div class="sa-card sa-fs-head"><svg class="sa-ico-lg sa-ico-clam" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><circle cx="11" cy="14" r="3"/><path d="m13.5 16.5 2 2"/></svg>'
            . '<div><h4>' . self::h(self::t('Website files')) . '</h4><span>' . self::h($line) . '</span></div>' . $pill . '</div>'
            . '<div class="sa-fs-actions">' . self::FileButton('scan', 'Scan changed files now', '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>')
            . self::FileButton('full', 'Scan every file now', '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>') . '</div>' . $refresh;
        // the last scan as tiles
        $findings = (array) $st['findings'];
        $recent = 0;
        $inQ = 0;
        foreach ($findings as $f) {
            if ((int) $f['time'] >= time() - 30 * 86400) {
                $recent++;
            }
            if (($f['status'] ?? '') === 'quarantined') {
                $inQ++;
            }
        }
        $tile = function ($cls, $icon, $value, $label, $small) {
            return '<div class="sa-tile ' . $cls . '"><svg class="sa-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icon . '</svg>'
                . '<div><b>' . $value . '</b><span>' . self::h(self::t($label)) . '</span>' . ($small !== '' ? '<small>' . $small . '</small>' : '') . '</div></div>';
        };
        $dur = $last ? max(1, (int) $last['finished'] - (int) $last['started']) : 0;
        $tiles = $tile('sa-t-checked', '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/>', $last ? number_format((int) $last['files']) : '-', 'Files checked',
                $last ? self::h(self::t($last['full'] ? 'every file' : 'changed files')) . ' &middot; ' . self::h(self::Size((int) $last['bytes'])) . ' &middot; ' . self::h($dur >= 60 ? sprintf(self::t('%d min'), round($dur / 60)) : sprintf(self::t('%d s'), $dur)) : self::h(self::t('no scan yet')))
            . $tile('sa-t-virus', '<circle cx="12" cy="12" r="5"/><path d="M12 2v5M12 17v5M2 12h5M17 12h5M5 5l3.5 3.5M15.5 15.5 19 19M5 19l3.5-3.5M15.5 8.5 19 5"/>', number_format($last ? (int) $last['found'] : 0), 'Found in the last scan',
                self::h(self::t('30 days')) . ' ' . number_format($recent))
            . $tile('sa-t-refused', '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>', number_format($inQ), 'In quarantine',
                self::h(self::t(sys_spamav::On('fs_quarantine') ? 'found files are moved there' : 'quarantine is off: only reported')))
            . $tile('sa-t-spam', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', $last ? self::h(date('H:i', (int) $last['started'])) : '-', 'Last scan',
                $last ? self::h(ui_language::Date('D j M', (int) $last['started'])) . ((int) $last['skipped_big'] ? ' &middot; ' . sprintf(self::h(self::t('%d too big')), (int) $last['skipped_big']) : '')
                    . (isset($last['stopped']) ? '<br><span class="sa-bad">' . self::h(self::t($last['stopped'])) . '</span>' : ((int) $last['errors'] ? '<br>' . sprintf(self::h(self::t('%d not scanned')), (int) $last['errors']) : '')) : '');
        // the last 14 scans as bars (files checked; the ones that found something in red, with the number).
        // The square root of the number of files: a scan of every file (tens of thousands) and the nightly
        // ones (hundreds) both stay readable; the exact number is on each bar's tip
        $show = array_slice($runs, -14);
        $max = 1;
        foreach ($show as $r) {
            $max = max($max, (int) $r['files']);
        }
        $bars = '';
        for ($i = 0; $i < 14; $i++) {
            $r = $show[$i - (14 - count($show))] ?? null;
            if ($r === null) {
                $bars .= '<div class="sa-bar sa-none"><div class="sa-stack"></div><span>&nbsp;</span></div>';
                continue;
            }
            $tip = ui_language::Date('D j M H:i', (int) $r['started']) . ': ' . number_format((int) $r['files']) . ' ' . self::t('files') . ', ' . (int) $r['found'] . ' ' . self::t('found') . ($r['full'] ? ' (' . self::t('every file') . ')' : '');
            $bars .= '<div class="sa-bar" title="' . self::h($tip) . '"><div class="sa-stack">' . ((int) $r['found'] ? '<em class="sa-fs-n">' . (int) $r['found'] . '</em>' : '')
                . '<i class="' . ((int) $r['found'] ? 'sa-b-virus' : ($r['full'] ? 'sa-b-full' : 'sa-b-files')) . '" style="height:' . max(3, round(sqrt((int) $r['files']) / sqrt($max) * 100, 1)) . '%"></i></div>'
                . '<span>' . date('j', (int) $r['started']) . '</span></div>';
        }
        $chart = '<div class="sa-card sa-trend"><div class="sa-trend-head"><h4>' . self::h(self::t('Last 14 scans')) . '</h4><span class="sa-key"><i class="sa-b-files"></i>' . self::h(self::t('Changed files'))
            . ' <i class="sa-b-full"></i>' . self::h(self::t('Every file')) . ' <i class="sa-b-virus"></i>' . self::h(self::t('Found something')) . '</span></div>'
            . '<div class="sa-chart" role="img" aria-label="' . self::h(self::t('Files checked by the last 14 scans')) . '">' . $bars . '</div></div>';
        // what was found, by family (90 days), as a ring
        $fam = array();
        foreach ($findings as $f) {
            if ((int) $f['time'] >= time() - 90 * 86400) {
                $k = self::Family($f['sig']);
                $fam[$k] = ($fam[$k] ?? 0) + 1;
            }
        }
        arsort($fam);
        if (count($fam) > 5) {
            $rest = array_sum(array_slice($fam, 5));
            $fam = array_slice($fam, 0, 5, true) + array(self::t('Others') => $rest);
        }
        $colors = array('#d6457a', '#e08a1e', '#7c5cc4', '#1fa2b5', '#447fb3', '#8a9a5b');
        $total = array_sum($fam);
        $r = 52;
        $c = 2 * M_PI * $r;
        $off = 0.0;
        $arcs = '';
        $legend = '';
        $n = 0;
        foreach ($fam as $k => $v) {
            $len = $c * $v / max(1, $total);
            $arcs .= '<circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="' . $colors[$n % 6] . '" stroke-width="16" stroke-dasharray="' . round(max(0.5, $len - 2), 2) . ' ' . round($c, 2) . '" stroke-dashoffset="' . round(-$off, 2) . '" transform="rotate(-90 70 70)"><title>' . self::h($k) . ': ' . $v . '</title></circle>';
            $legend .= '<li><i style="background:' . $colors[$n % 6] . '"></i><span>' . self::h($k) . '</span><b>' . $v . '</b></li>';
            $off += $len;
            $n++;
        }
        $ring = '<div class="sa-card sa-fs-ring"><h4>' . self::h(self::t('What was found')) . ' <small>' . self::h(self::t('90 days')) . '</small></h4>'
            . '<div class="sa-ring"><svg viewBox="0 0 140 140" role="img" aria-label="' . self::h(self::t('What was found, by kind')) . '"><circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="var(--sa-line)" stroke-width="16"/>' . $arcs . '</svg>'
            . '<div class="sa-ring-mid"><b>' . $total . '</b><span>' . self::h(self::t($total === 1 ? 'file' : 'files')) . '</span></div></div>'
            . ($legend !== '' ? '<ul class="sa-legend">' . $legend . '</ul>' : '<p class="sa-empty">' . self::h(self::t('Nothing found: the websites look clean.')) . '</p>') . '</div>';
        $out .= '<div class="sa-row sa-fs-row"><div class="sa-tiles">' . $tiles . '</div>' . $chart . '</div>';
        // the findings
        $rows = '';
        foreach (array_slice($findings, 0, 100) as $f) {
            $status = (string) ($f['status'] ?? 'found');
            $pills = array('found' => array('bad', 'Found'), 'quarantined' => array('run', 'In quarantine'), 'restored' => array('off', 'Put back'), 'deleted' => array('off', 'Deleted'));
            $p = $pills[$status] ?? $pills['found'];
            $act = $status === 'quarantined' ? self::FileButton('restore', 'Put back', '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>', (string) $f['id'], 'Put this file back where it was? Only when you are sure it is not malware.')
                . self::FileButton('delete', 'Delete', '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="m6 7 1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>', (string) $f['id'], 'Delete this file for good?') : '';
            $rows .= '<li class="sa-fs-item"><div class="sa-fs-what"><b>' . self::h($f['sig']) . '</b><code>' . self::h($f['path']) . '</code>'
                . '<small>' . self::h($f['user']) . ' &middot; ' . self::h(self::Size((int) $f['size'])) . ' &middot; ' . self::h(ui_language::Date('D j M H:i', (int) $f['time'])) . '</small></div>'
                . '<span class="sa-pill ' . $p[0] . '">' . self::h(self::t($p[1])) . '</span>' . ($act !== '' ? '<div class="sa-fs-acts">' . $act . '</div>' : '') . '</li>';
        }
        $list = '<div class="sa-card sa-fs-list"><h4>' . self::h(self::t('Files found')) . '</h4>'
            . ($rows !== '' ? '<ul class="sa-fs-items">' . $rows . '</ul>' : '<p class="sa-empty">' . self::h(self::t('None so far.')) . '</p>') . '</div>';
        return $out . '<div class="sa-tops sa-fs-tops">' . $ring . $list . '</div>';
    }

    /**
     * File scanning on the Overview: one line with its state and the last scan (<@ FileSummary @>).
     *
     * @return string The HTML.
     */
    static function getFileSummary()
    {
        $st = sys_filescan::State();
        $last = $st['runs'] ? end($st['runs']) : null;
        $on = sys_spamav::On('fs_enabled');
        return '<div class="sa-card sa-fs-sum"><svg class="sa-ico-lg sa-ico-clam" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><circle cx="11" cy="14" r="3"/><path d="m13.5 16.5 2 2"/></svg>'
            . '<div><b>' . self::h(self::t('Website files')) . '</b><span>'
            . ($last ? self::h(sprintf(self::t('Last scan %1$s: %2$s files, %3$d found.'), ui_language::Date('D j M H:i', (int) $last['started']), number_format((int) $last['files']), (int) $last['found'])) : self::h(self::t('Not scanned yet.')))
            . '</span></div><span class="sa-pill ' . ($on ? 'on' : 'off') . '">' . self::h(self::t($on ? 'Nightly' : 'Off')) . '</span>'
            . '<button type="button" class="btn btn-default btn-sm" data-goto="files">' . self::h(self::t('Open')) . '</button></div>';
    }

    /**
     * The File scanning tab's dot (green: on, grey: off, red: on without the virus scanner).
     *
     * @return string The HTML.
     */
    static function getFileState()
    {
        $on = sys_spamav::On('fs_enabled');
        return '<span class="sa-dot ' . (!$on ? 'off' : (sys_spamav::On('clam_enabled') ? 'up' : 'down')) . '"></span>';
    }

    /**
     * The question asked before restarting the scanners (template data attribute <@ RestartConfirm @>).
     *
     * @return string The translated text, escaped for an HTML attribute.
     */
    static function getRestartConfirm()
    {
        return self::h(self::t('Restart ClamAV and SpamAssassin? Mail waits a moment while they start again.'));
    }

    /**
     * The introduction of the spam filtering tab (<@ SpamIntro @>).
     *
     * @return string The translated text, escaped.
     */
    static function getSpamIntro()
    {
        return self::h(self::t('Spam filtering for incoming mail. Mailbox owners can fine-tune it for their own mailboxes in Mail > SpamAV if you allow it.'));
    }

    /**
     * Whether ClamAV and SpamAssassin are switched on, for the tab labels (<@ ClamState @>, <@ SpamState @>).
     *
     * @return string A small dot.
     */
    static function getClamState()
    {
        return '<span class="sa-dot ' . (sys_spamav::On('clam_enabled') ? 'up' : 'off') . '"></span>';
    }

    /**
     * Whether SpamAssassin is switched on, for its tab label (<@ SpamState @>).
     *
     * @return string A small dot.
     */
    static function getSpamState()
    {
        return '<span class="sa-dot ' . (sys_spamav::On('sa_enabled') ? 'up' : 'off') . '"></span>';
    }

    /**
     * Return the messages for the last action (<@ Result @>).
     *
     * @return string HTML messages ('' when none).
     */
    static function getResult()
    {
        $out = '';
        foreach (self::$msg as $m) {
            $out .= ui_sysmessage::shout(self::h(!empty($m[2]) ? $m[1] : self::t($m[1])), $m[0] === 'ok' ? 'zannounceok' : 'zannounceerror');
        }
        return $out;
    }
}
