<?php

/**
 * SpamAV Admin module: controller.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * SpamAV Admin (administrators): ClamAV and SpamAssassin settings, status, updates.
 * Settings are saved here and applied by the daemon (modules/spamav_admin/hooks).
 */
class module_controller extends ctrl_module
{
    /** @var array<int, array{0: string, 1: string}> Messages for getResult(): array('ok' or 'error', English text). */
    static $msg = array();

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
                $errors[] = $val;
            }
        }
        if ($section === 'spamassassin' && isset($clean['sa_reject_score'], $clean['sa_required_score']) && (float) $clean['sa_reject_score'] > 0 && (float) $clean['sa_reject_score'] <= (float) $clean['sa_required_score']) {
            $errors[] = 'Reject at score must be above the spam score (or 0).';
        }
        if ($errors) {
            foreach ($errors as $e) {
                self::$msg[] = array('error', $e);
            }
            return;
        }
        $up = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = :n AND so_module_vc = '" . sys_spamav::MODULE . "'");
        foreach ($clean as $name => $val) {
            $up->execute(array(':v' => $val, ':n' => $name));
        }
        self::$msg[] = array('ok', ($section === 'clamav' ? 'ClamAV' : 'SpamAssassin') . ' settings saved. The daemon applies them within 5 minutes.');
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
     * Build the form field for one setting.
     *
     * @param string $name The setting name.
     * @param array<int,mixed> $d The setting's definition from sys_spamav::Definitions().
     * @return string HTML input, select or textarea ('' for an unknown type).
     */
    static function Field($name, $d)
    {
        $v = sys_spamav::Get($name);
        $n = 'in_' . $name;
        switch ($d[2]) {
            case 'bool':
                return '<label class="sa-switch"><input type="checkbox" name="' . $n . '" value="1"' . ($v === 'true' ? ' checked' : '') . '> ' . self::t('On') . '</label>';
            case 'int':
            case 'float':
                return '<input class="form-control" name="' . $n . '" value="' . self::h($v) . '" inputmode="decimal" style="max-width: 130px;"> <span class="text-muted">' . self::h($d[5][0] . ' – ' . $d[5][1]) . '</span>';
            case 'select':
                $o = '';
                foreach ($d[5] as $k => $label) {
                    $o .= '<option value="' . self::h($k) . '"' . ((string) $k === $v ? ' selected' : '') . '>' . self::h(self::t($label)) . '</option>';
                }
                return '<select class="form-control" name="' . $n . '" style="max-width: 340px;">' . $o . '</select>';
            case 'text':
                return '<input class="form-control" name="' . $n . '" value="' . self::h($v) . '" maxlength="' . (int) $d[5][0] . '" style="max-width: 340px;">';
            case 'list':
                return '<textarea class="form-control" name="' . $n . '" rows="3" style="max-width: 420px; font-family: monospace;">' . self::h($v) . '</textarea>';
        }
        return '';
    }

    /**
     * Build the settings form of one section.
     *
     * @param string $key Section key: 'clamav' or 'spamassassin'.
     * @param string $title Heading.
     * @param string $intro Introduction (translated here).
     * @return string HTML heading and form.
     */
    static function Section($key, $title, $intro)
    {
        $rows = '';
        foreach (sys_spamav::Definitions() as $name => $d) {
            if ($d[0] === $key) {
                $rows .= '<tr><th style="width: 260px;">' . self::h(self::t($d[1])) . '</th><td style="width: 440px;">' . self::Field($name, $d) . '</td><td class="text-muted">' . self::h(self::t($d[4])) . '</td></tr>';
            }
        }
        return '<h2 id="' . $key . '">' . self::h($title) . '</h2><p class="text-muted">' . self::h(self::t($intro)) . '</p>'
            . '<form method="post" action="./?module=spamav_admin&amp;action=Save#' . $key . '"><input type="hidden" name="inSection" value="' . $key . '">'
            . '<table class="table table-striped">' . $rows . '</table>' . runtime_csfr::Token()
            . '<button class="btn btn-primary" type="submit">' . self::t('Save Changes') . '</button></form>';
    }

    /**
     * Build the status of the scanners from the daemon's status file, with the action buttons.
     *
     * @return string HTML.
     */
    static function StatusBlock()
    {
        $s = @json_decode((string) @file_get_contents(sys_spamav::STATUS), true);
        if (!is_array($s)) {
            return '<p class="text-muted">' . self::t('The daemon has not checked the scanners yet (within 5 minutes after installing).') . '</p>';
        }
        $u = isset($s['units']) ? $s['units'] : array();
        $svc = function ($unit, $label, $wanted) use ($u) {
            if (!$wanted) {
                return '<tr><th>' . self::h(self::t($label)) . '</th><td class="text-muted">' . self::t('off') . '</td></tr>';
            }
            return '<tr><th>' . self::h(self::t($label)) . '</th><td>' . (!empty($u[$unit]) ? ui_status::Ok('Running') . ' ' . self::t('running') : ui_status::Bad('Not running') . ' ' . self::t('not running')) . '</td></tr>';
        };
        $clam = sys_spamav::On('clam_enabled');
        $sa = sys_spamav::On('sa_enabled');
        $sig = '';
        foreach (array('daily', 'main') as $db) {
            if (isset($s['sigs'][$db])) {
                $sig .= self::h($db . ' ' . $s['sigs'][$db]['version'] . ' (' . $s['sigs'][$db]['built'] . ', ' . number_format($s['sigs'][$db]['signatures']) . ' signatures)') . '<br>';
            }
        }
        $left = '<table class="table table-condensed"><colgroup><col style="width: 190px;"><col></colgroup>'
            . '<tr><th colspan="2">ClamAV ' . self::h($s['versions']['clamav'] ?? '') . '</th></tr>'
            . $svc('clamav-daemon', 'Virus scanner', $clam) . $svc('clamav-milter', 'Mail connection', $clam) . $svc('clamav-freshclam', 'Signature updates', $clam)
            . '<tr><th>' . self::t('Signatures') . '</th><td>' . ($sig !== '' ? $sig : ($clam ? ui_status::Warn('Waiting') . ' ' . self::t('waiting for the first download (the scanner starts when it is done)') : '-')) . '</td></tr>'
            . ($clam && (int) ($s['memory_mb'] ?? 0) > 0 && (int) $s['memory_mb'] < 1900 ? '<tr><td colspan="2" class="text-warning">' . ui_status::Warn('Low memory') . ' ' . self::t('This server has less than 2 GB of memory; ClamAV may not have enough.') . '</td></tr>' : '')
            . '</table>';
        $right = '<table class="table table-condensed"><colgroup><col style="width: 190px;"><col></colgroup>'
            . '<tr><th colspan="2">SpamAssassin ' . self::h($s['versions']['spamassassin'] ?? '') . '</th></tr>'
            . $svc('spamd', 'Spam checker', $sa) . $svc('spamass-milter', 'Mail connection', $sa)
            . '<tr><th>' . self::t('Rules updated') . '</th><td>' . (!empty($s['rules_updated']) ? self::h(date('Y-m-d H:i', (int) $s['rules_updated'])) : '-') . '</td></tr>'
            . '<tr><th>' . self::t('Postfix scans with') . '</th><td>' . (!empty($s['milters']) ? self::h(implode(', ', array_map(function ($m) { return strpos($m, 'clamav') !== false ? 'ClamAV' : 'SpamAssassin'; }, $s['milters']))) : self::t('nothing')) . '</td></tr>'
            . '</table>';
        $btn = function ($what, $label) {
            return '<form method="post" action="./?module=spamav_admin&amp;action=Request" style="display:inline"><input type="hidden" name="inWhat" value="' . $what . '">'
                . runtime_csfr::Token() . '<button class="btn btn-default" type="submit">' . self::h(self::t($label)) . '</button></form> ';
        };
        // the last job asked for here (an update or a restart: not a service, so no status icon while it runs)
        $last = '';
        if (isset($s['last_request'])) {
            $r = $s['last_request'];
            $jobs = array(
                'Update virus signatures' => array('Updating virus signatures', 'Virus signatures updated', 'Virus signature update failed'),
                'Update spam rules' => array('Updating spam rules', 'Spam rules updated', 'Spam rule update failed'),
                'Restart the scanners' => array('Restarting the scanners', 'Scanners restarted', 'Restart failed'),
            );
            $j = isset($jobs[$r['what']]) ? $jobs[$r['what']] : array($r['what'], $r['what'] . ': done', $r['what'] . ': failed');
            $when = date('H:i', (int) $r['time']) . (date('Y-m-d', (int) $r['time']) !== date('Y-m-d') ? ' ' . date('Y-m-d', (int) $r['time']) : '');
            if ($r['ok'] === null) {
                $last = '<p class="sa-job"><span class="sa-spin" aria-hidden="true"></span> ' . self::h(self::t($j[0])) . '&hellip; '
                    . '<span class="text-muted">' . self::h(self::t('started') . ' ' . $when . ' ' . self::t('by') . ' ' . $r['by'] . '. ' . self::t('This can take a few minutes; reload the page to see when it is done.')) . '</span></p>';
            } elseif ($r['ok']) {
                $last = '<p class="text-muted">' . ui_status::Ok('Done') . ' ' . self::h(self::t($j[1]) . ', ' . $when . ' (' . $r['by'] . ')') . '</p>';
            } else {
                $last = '<p>' . ui_status::Bad('Failed') . ' ' . self::h(self::t($j[2]) . ', ' . $when . ' (' . $r['by'] . '): ' . preg_replace('/^Failed:\s*/i', '', (string) $r['text'])) . '</p>';
            }
        }
        return '<div class="row"><div class="col-md-6">' . $left . '</div><div class="col-md-6">' . $right . '</div></div>'
            . '<p>' . $btn('signatures', 'Update virus signatures now') . $btn('rules', 'Update spam rules now') . $btn('restart', 'Restart the scanners')
            . '<span class="text-muted">' . self::t('Checked') . ' ' . self::h(date('H:i', (int) $s['time'])) . '</span></p>' . $last;
    }

    /**
     * Build the table of mail checked, spam, viruses and rejections (today, 7 and 30 days).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string HTML table, with the most common viruses when there are any.
     */
    static function Totals()
    {
        global $zdbh;
        $row = function ($days) use ($zdbh) {
            $q = $zdbh->prepare("SELECT COALESCE(SUM(dd_scanned_in),0), COALESCE(SUM(dd_spam_in),0), COALESCE(SUM(dd_virus_in),0), COALESCE(SUM(dd_rejected_in),0) FROM x_spamav_daily WHERE dd_day >= :d");
            $q->execute(array(':d' => date('Y-m-d', time() - ($days - 1) * 86400)));
            return $q->fetch(PDO::FETCH_NUM);
        };
        $out = '<table class="table table-condensed" style="max-width: 700px;"><tr><th></th><th>' . self::t('Checked for spam') . '</th><th>' . self::t('Spam') . '</th><th>' . self::t('Viruses') . '</th><th>' . self::t('Rejected') . '</th></tr>';
        foreach (array(1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days') as $d => $label) {
            $r = $row($d);
            $out .= '<tr><th>' . self::t($label) . '</th><td>' . number_format($r[0]) . '</td><td>' . number_format($r[1]) . '</td><td>' . number_format($r[2]) . '</td><td>' . number_format($r[3]) . '</td></tr>';
        }
        $out .= '</table>';
        $v = $zdbh->prepare("SELECT ev_detail_vc, COUNT(*) n FROM x_spamav_events WHERE ev_kind_vc = 'virus' AND ev_ts >= :t GROUP BY ev_detail_vc ORDER BY n DESC LIMIT 5");
        $v->execute(array(':t' => time() - 30 * 86400));
        $top = $v->fetchAll(PDO::FETCH_NUM);
        if ($top) {
            $out .= '<p><strong>' . self::t('Most common viruses (30 days)') . ':</strong> ' . implode(', ', array_map(function ($r) { return self::h($r[0]) . ' (' . (int) $r[1] . ')'; }, $top)) . '</p>';
        }
        return $out;
    }

    /**
     * Build the whole page (<@ Page @>): status, totals and both settings forms.
     *
     * @return string HTML (a short note for anyone but administrators).
     */
    static function getPage()
    {
        if (!self::IsAdmin()) {
            return '<p>' . self::t('Only administrators can manage spam and virus filtering.') . '</p>';
        }
        sys_spamav::Ensure();
        return '<style>.sa-switch{font-weight:normal;margin:0}'
            . '.sa-job{color:var(--sn-info, #31708f)}.sa-spin{display:inline-block;width:14px;height:14px;vertical-align:-2px;border:2px solid var(--sn-info-line, #bcdcec);border-top-color:var(--sn-info, #31708f);border-radius:50%;animation:sa-spin .9s linear infinite}'
            . '@keyframes sa-spin{to{transform:rotate(360deg)}}@media (prefers-reduced-motion: reduce){.sa-spin{animation-duration:2.5s}}</style>'
            . '<p><a href="#clamav">ClamAV</a> &middot; <a href="#spamassassin">SpamAssassin</a></p>'
            . '<h2>' . self::t('Status') . '</h2>' . self::StatusBlock()
            . '<h3>' . self::t('All mail on this server') . '</h3>' . self::Totals()
            . self::Section('clamav', 'ClamAV', 'Virus scanning for all mail. Changes are applied by the daemon within 5 minutes; only the parts that changed are restarted.')
            . '<div style="height: 18px"></div>'
            . self::Section('spamassassin', 'SpamAssassin', 'Spam filtering for incoming mail. Users can fine-tune it for their own mailboxes in Mail > SpamAV if you allow it.');
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
            $out .= ui_sysmessage::shout(self::h(self::t($m[1])), $m[0] === 'ok' ? 'zannounceok' : 'zannounceerror');
        }
        return $out;
    }
}
