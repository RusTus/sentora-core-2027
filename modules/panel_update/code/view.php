<?php

/**
 * Panel Update: the parts of the page, as HTML (the page draws them, and code/status.php draws the
 * progress again while a run goes on).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-11
 */
class panel_update_view
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
     * Translate and escape a text.
     *
     * @param string $s The English text.
     * @return string The escaped translation.
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * A line icon.
     *
     * @param string $name 'ok', 'warn', 'fail', 'run', 'wait', 'box', 'cloud', 'clock', 'back' or 'term'.
     * @param string $class Optional. The class. Default 'pu-ico'.
     * @return string The SVG.
     */
    static function Icon($name, $class = 'pu-ico')
    {
        $d = array(
            'ok' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
            'warn' => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
            'fail' => '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>',
            'run' => '<path d="M21 12a9 9 0 1 1-6.2-8.6"/>',
            'wait' => '<circle cx="12" cy="12" r="9" stroke-dasharray="2 3"/>',
            'box' => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
            'cloud' => '<path d="M7 18a5 5 0 1 1 1-9.9A6 6 0 0 1 19 10a4 4 0 0 1-1 8z"/><path d="M12 11v6M9 14l3 3 3-3"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'back' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
            'term' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 3 3-3 3M13 15h4"/>',
        );
        return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . (isset($d[$name]) ? $d[$name] : '') . '</svg>';
    }

    /**
     * How long ago, in words.
     *
     * @param int $t A Unix time.
     * @return string For example '12 minutes ago' (translated, escaped).
     */
    static function Ago($t)
    {
        $s = max(0, time() - (int) $t);
        if ($s < 90) {
            return self::t('just now');
        }
        if ($s < 5400) {
            return str_replace('%d', (string) (int) round($s / 60), self::t('%d minutes ago'));
        }
        if ($s < 129600) {
            return str_replace('%d', (string) (int) round($s / 3600), self::t('%d hours ago'));
        }
        return self::t('on') . ' ' . self::h(ui_language::Date('j M Y', (int) $t));
    }

    /**
     * The box that says how to go back from the server's console (the panel may not answer after a bad update).
     *
     * @param string $backup Optional. The backup to name in the command. Default '' (the command that lists them).
     * @param string $lead Optional. The sentence before the command (English). Default: if the panel stops answering.
     * @return string The HTML.
     */
    static function Console($backup = '', $lead = '')
    {
        $lead = $lead !== '' ? $lead : 'If the panel stops answering, go back to the version before from the server\'s console, as root:';
        $cmd = panel_update_state::RollbackCommand($backup);
        return '<div class="pu-console">' . self::Icon('term') . '<div><span>' . self::t($lead) . '</span>'
            . '<code class="pu-cmd" data-copy="' . self::h($cmd) . '">' . self::h($cmd) . '</code>'
            . ($backup === '' ? '<small>' . self::t('It lists the backups, newest first; run it again with the newest one.') . '</small>' : '')
            . '</div></div>';
    }

    /**
     * The password field and button of an action (update or rollback).
     *
     * @param string $action The form action (UpdatePanel or RollbackPanel).
     * @param string $button The button's text (English).
     * @param string $class The button's class.
     * @param string $backup Optional. The backup, for a rollback. Default ''.
     * @return string The HTML (the form, with the CSRF token).
     */
    static function ActionForm($action, $button, $class, $backup = '')
    {
        return '<form class="pu-act" method="post" action="./?module=panel_update&amp;action=' . $action . '">'
            . ($backup !== '' ? '<input type="hidden" name="inBackup" value="' . self::h($backup) . '">' : '')
            . '<label class="pu-pw"><span>' . self::t('Your panel password') . '</span>'
            . '<input class="form-control" type="password" name="inPassword" autocomplete="current-password" required></label>'
            . '<button class="btn ' . $class . '" type="submit">' . self::t($button) . '</button>'
            . runtime_csfr::Token() . '</form>';
    }

    /**
     * The last update or rollback: its steps, with what each did, and how it ended.
     *
     * @param array<string, mixed>|null $run See panel_update_state::Run().
     * @param int $asked When this administrator last asked for a run (Unix time, 0 = not in this session).
     * @param bool $compact Optional. A run that is over, as one line at the bottom of the page (it opens to show its
     *        steps): the page's own state is in the tiles, so an old "updated" must not read as the current one. Default false.
     * @return array<string, mixed> 'html' and 'live' (whether the page should keep watching).
     */
    static function Run($run, $asked, $compact = false)
    {
        // asked from here, and the run has not started yet (root's at job starts within seconds)
        if ($asked > 0 && (!$run || $run['started'] < $asked - 5)) {
            if (time() - $asked < 120) {
                return array('live' => true, 'html' => '<div class="pu-box pu-run"><div class="pu-wait">' . self::Icon('run', 'pu-ico pu-spin')
                    . '<div><b>' . self::t('Starting') . '</b><span>' . self::t('The server starts the job within a few seconds.') . '</span></div></div></div>');
            }
            return array('live' => false, 'html' => '<div class="pu-box pu-run"><div class="pu-end fail">' . self::Icon('fail', 'pu-ico-lg')
                . '<div><b>' . self::t('The job did not start') . '</b><span>' . self::t('Nothing was changed. Check that the queue of root actions runs (systemctl status sentora-zsudo.path), or run sentora-update from the server\'s console.') . '</span></div></div></div>');
        }
        if (!$run) {
            return array('live' => false, 'html' => '');
        }
        $roll = $run['mode'] === 'rollback';
        $live = $run['result'] === 'running';
        if ($compact && !$live) {
            return array('live' => false, 'html' => self::History($run));
        }
        $done = 0;
        foreach ($run['steps'] as $s) {
            if ($s['state'] !== 'running') {
                $done++;
            }
        }
        $total = max(1, (int) $run['total'], count($run['steps']));
        $pct = $live ? (int) floor($done * 100 / $total) : 100;
        $title = $live ? ($roll ? 'Going back to the version before' : 'Updating the panel') : ($roll ? 'The last rollback' : 'The last update');
        $out = '<div class="pu-box pu-run' . ($live ? ' is-live' : '') . '"><div class="pu-head"><h4>' . self::t($title) . '</h4>'
            . '<small>' . self::t('started') . ' ' . self::h(ui_language::Date('j M Y H:i', (int) $run['started'])) . ' ' . self::t($run['from'] === 'panel' ? 'from the panel' : 'from the console') . '</small></div>';
        if ($live) {
            $out .= '<div class="pu-bar"><i style="width:' . $pct . '%"></i></div><p class="pu-sub">' . $done . ' / ' . $total . ' ' . self::t('steps')
                . ' &middot; ' . self::t('You can leave this page: the job carries on on the server.') . '</p>';
        }
        $out .= '<ol class="pu-steps">';
        foreach ($run['steps'] as $s) {
            $icon = array('running' => 'run', 'ok' => 'ok', 'warn' => 'warn', 'fail' => 'fail');
            $out .= '<li class="pu-step is-' . $s['state'] . '">' . self::Icon($icon[$s['state']], 'pu-ico' . ($s['state'] === 'running' ? ' pu-spin' : ''))
                . '<div><div class="pu-step-head"><b>' . self::t($s['title']) . '</b>'
                . ($s['state'] !== 'running' ? '<small>' . (int) floor($s['took'] / 60) . ':' . sprintf('%02d', $s['took'] % 60) . '</small>' : '') . '</div>';
            foreach ($s['lines'] as $l) {
                $out .= '<span class="pu-line' . ($l[0] === 'warn' ? ' warn' : '') . '">' . self::h($l[1]) . '</span>';
            }
            $out .= '</div></li>';
        }
        $out .= '</ol>';
        $back = $run['backup'];
        if ($live) {
            $out .= self::Console($roll ? '' : $back);
        } elseif ($run['result'] === 'ok' || $run['result'] === 'warn') {
            // green only while the panel really is up to date: a newer release found after this run
            // turns it neutral, so it never contradicts "Update available" in the tiles
            $a = panel_update_state::Available();
            $newer = !$roll && $a['state'] === 'available' && $a['checked'] > (int) $run['updated'];
            $tone = $newer ? 'info' : $run['result'];
            $out .= '<div class="pu-end ' . $tone . '">' . self::Icon($newer ? 'clock' : ($run['result'] === 'ok' ? 'ok' : 'warn'), 'pu-ico-lg') . '<div><b>'
                . self::t($roll ? 'Restored' : ($newer ? 'The update finished'
                    : ($run['result'] === 'warn' ? 'Last panel update finished, with warnings (see above)' : 'Last panel update finished successfully')))
                . ($run['result'] === 'warn' && ($roll || $newer) ? ', ' . self::t('with warnings (see above)') : '') . '</b>'
                . self::LastUpdate($run['release'], $roll || $newer)
                . ($newer ? '<span>' . self::t('A newer release has been published since: update again above.') . '</span>' : '') . '</div></div>';
            if (!$roll && $back !== '') {
                $out .= self::Console($back, 'Something wrong since the update? Go back to the version before from the server\'s console, as root:');
            }
        } elseif ($run['result'] === 'same') {
            $out .= '<div class="pu-end ok">' . self::Icon('ok', 'pu-ico-lg') . '<div><b>' . self::t('Already up to date') . '</b><span>'
                . self::t('Nothing was changed.') . '</span></div></div>';
        } else {
            $at = '';
            foreach ($run['steps'] as $s) {
                if ($s['state'] === 'fail' || $s['state'] === 'running') {
                    $at = $s['title'];
                }
            }
            $stalled = $run['result'] === 'stalled';
            $out .= '<div class="pu-end fail">' . self::Icon('fail', 'pu-ico-lg') . '<div><b>'
                . ($stalled ? self::t('The job stopped answering') : self::t('It stopped at') . ': ' . self::t($at)) . '</b><span>'
                . self::h($stalled ? ui_language::translate('No word from it for 20 minutes (the server restarted, or it was stopped).') : $run['message'])
                . ($back === '' && !$roll ? ' ' . self::t('Nothing was changed: it stopped before the backup.') : '') . '</span></div></div>';
            if ($back !== '' || $roll) {
                $out .= self::Console($roll ? '' : $back, 'To go back to the version before, run this on the server\'s console, as root:');
            }
        }
        return array('live' => $live, 'html' => $out . '</div>');
    }

    /**
     * "Last panel update: 6 Nov 2026 23:11" (or "Installed: ..." for the installer's), from a release line.
     *
     * @param string $text The release line, as panel_update_state::Installed() gives it.
     * @param bool $label Optional. With "Last panel update:" in front (false: the date alone, under a title that
     *        already says it). Default true.
     * @return string The HTML (a span; '' when there is no date in it).
     */
    static function LastUpdate($text, $label = true)
    {
        $i = panel_update_state::ParseInstalled($text);
        if (!$i['when']) {
            return '';
        }
        $date = self::h(ui_language::Date('j M Y H:i', $i['when']));
        return '<span>' . ($label ? str_replace('%s', $date, self::t($i['installer'] ? 'Installed: %s' : 'Last panel update: %s')) : $date) . '</span>';
    }

    /**
     * A run that is over, as one neutral line ("The last update · 6 Nov 2026 22:56, from the panel ·
     * finished") that opens to show its steps. One that failed says so, with the console command.
     *
     * @param array<string, mixed> $run See panel_update_state::Run().
     * @return string The HTML.
     */
    static function History($run)
    {
        $roll = $run['mode'] === 'rollback';
        $failed = !in_array($run['result'], array('ok', 'warn', 'same'), true);
        $at = '';
        foreach ($run['steps'] as $s) {
            if ($s['state'] === 'fail' || $s['state'] === 'running') {
                $at = $s['title'];
            }
        }
        $what = array('ok' => self::t('finished'), 'warn' => self::t('finished with warnings'), 'same' => self::t('Already up to date'),
            'stalled' => self::t('stopped answering'));
        $result = isset($what[$run['result']]) ? $what[$run['result']] : str_replace('%s', self::t($at), self::t('stopped at %s'));
        $out = '<details class="pu-box pu-hist' . ($failed ? ' is-fail' : '') . '"' . ($failed ? ' open' : '') . '><summary>'
            . self::Icon($failed ? 'fail' : 'clock') . '<b>' . self::t($roll ? 'The last rollback' : 'The last update') . '</b>'
            . '<span>' . self::h(ui_language::Date('j M Y H:i', (int) $run['started'])) . ', ' . self::t($run['from'] === 'panel' ? 'from the panel' : 'from the console')
            . ' &middot; ' . $result . '</span></summary><ol class="pu-steps">';
        foreach ($run['steps'] as $s) {
            $icon = array('running' => 'wait', 'ok' => 'ok', 'warn' => 'warn', 'fail' => 'fail');
            $out .= '<li class="pu-step is-' . $s['state'] . '">' . self::Icon($icon[$s['state']])
                . '<div><div class="pu-step-head"><b>' . self::t($s['title']) . '</b>'
                . ($s['state'] !== 'running' ? '<small>' . (int) floor($s['took'] / 60) . ':' . sprintf('%02d', $s['took'] % 60) . '</small>' : '') . '</div>';
            foreach ($s['lines'] as $l) {
                $out .= '<span class="pu-line' . ($l[0] === 'warn' ? ' warn' : '') . '">' . self::h($l[1]) . '</span>';
            }
            $out .= '</div></li>';
        }
        $out .= '</ol>';
        if ($failed && $run['result'] !== 'stalled' && $run['message'] !== '') {
            $out .= '<p class="pu-sub">' . self::h($run['message']) . '</p>';
        }
        if ($failed && ($run['backup'] !== '' || $roll)) {
            $out .= self::Console($roll ? '' : $run['backup'], 'To go back to the version before, run this on the server\'s console, as root:');
        }
        return $out . '</details>';
    }
}
