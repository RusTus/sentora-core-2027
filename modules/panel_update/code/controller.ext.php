<?php

/**
 * Panel Update module: controller.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
require_once dirname(__FILE__) . '/state.php';
require_once dirname(__FILE__) . '/view.php';

/**
 * Panel Update (administrators): is a newer panel published, update to it, watch the update step
 * by step, and go back to the version before an update. Root does the work: the panel asks through
 * zsudo (panel-check, panel-update, panel-rollback), which runs sentora-update as on the console.
 * Update and rollback ask for the panel password again, and every request is logged in
 * /var/lib/sentora/panel-update.log.
 */
class module_controller extends ctrl_module
{

    /**
     * The logged-in account, when it is a panel administrator.
     *
     * @return array<string, mixed>|false The account's details, or false.
     */
    static function Admin()
    {
        $u = ctrl_users::GetUserDetail();
        return ($u && (int) $u['usergroupid'] === 1) ? $u : false;
    }

    /**
     * Keep a message for the next page (after the redirect).
     *
     * @param string $type 'ok', 'warn' or 'fail'.
     * @param string $text The text (English; translated when shown).
     * @param string $extra Optional. More text, not translated (a command). Default ''.
     * @param string $value Optional. A value put in place of %s in the translated text. Default ''.
     * @return void
     */
    static function Flash($type, $text, $extra = '', $value = '')
    {
        $_SESSION['pu_msg'] = array($type, $text, $extra, $value);
    }

    /**
     * Back to the page (so a reload does not send the form again).
     *
     * @return void
     */
    static function Back()
    {
        header('Location: ./?module=panel_update');
        exit;
    }

    /**
     * What a refused zsudo call means, as a message.
     *
     * @param int $rc zsudo's exit code.
     * @return void
     */
    static function FlashRefused($rc)
    {
        if ($rc === 2) {
            self::Flash('fail', 'This server\'s zsudo does not know this action yet. Run sentora-update once on the server\'s console, as root: it builds the new zsudo, and then this works from here.', 'sentora-update');
        } else {
            self::Flash('fail', 'The server did not take the job (zsudo answered %s). Nothing was changed. Check that the at service runs (systemctl status atd), or use the console.', '', (string) (int) $rc);
        }
    }

    /**
     * Action: ask GitHub now whether a newer panel is published (the answer shows within seconds).
     *
     * @return void
     */
    static function doCheckNow()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $rc = panel_update_state::Ask('panel-check');
        if ($rc === 0) {
            $_SESSION['pu_checking'] = time();
        } else {
            self::FlashRefused($rc);
        }
        self::Back();
    }

    /**
     * Action: update the panel (after the panel password), through zsudo panel-update.
     *
     * @return void
     */
    static function doUpdatePanel()
    {
        runtime_csfr::Protect();
        $u = self::Admin();
        if (!$u) {
            return;
        }
        $run = panel_update_state::Run();
        if ($run && $run['result'] === 'running') {
            self::Flash('warn', 'An update or rollback is already running: see its steps below.');
            self::Back();
        }
        if (!panel_update_state::PasswordOk($u['userid'], isset($_POST['inPassword']) ? $_POST['inPassword'] : '')) {
            panel_update_state::Log($u, 'update refused: wrong panel password');
            self::Flash('fail', 'That is not your panel password. Nothing was changed.');
            self::Back();
        }
        $asked = time(); // before asking: the job may start (and even end) before Ask() returns
        $rc = panel_update_state::Ask('panel-update');
        panel_update_state::Log($u, $rc === 0 ? 'started an update' : 'asked for an update, zsudo answered ' . $rc);
        if ($rc === 0) {
            $_SESSION['pu_asked'] = $asked;
        } else {
            self::FlashRefused($rc);
        }
        self::Back();
    }

    /**
     * Action: go back to the version in one of sentora-update's backups (after the panel password).
     *
     * @return void
     */
    static function doRollbackPanel()
    {
        runtime_csfr::Protect();
        $u = self::Admin();
        if (!$u) {
            return;
        }
        $name = isset($_POST['inBackup']) ? (string) $_POST['inBackup'] : '';
        $known = false;
        foreach (panel_update_state::Backups() as $b) {
            $known = $known || $b['name'] === $name;
        }
        $run = panel_update_state::Run();
        if (!$known) {
            self::Flash('fail', 'That backup is not in the list. Nothing was changed.');
            self::Back();
        }
        if ($run && $run['result'] === 'running') {
            self::Flash('warn', 'An update or rollback is already running: see its steps below.');
            self::Back();
        }
        if (!panel_update_state::PasswordOk($u['userid'], isset($_POST['inPassword']) ? $_POST['inPassword'] : '')) {
            panel_update_state::Log($u, 'rollback to ' . $name . ' refused: wrong panel password');
            self::Flash('fail', 'That is not your panel password. Nothing was changed.');
            self::Back();
        }
        $asked = time(); // before asking: the job may start (and even end) before Ask() returns
        $rc = panel_update_state::Ask('panel-rollback', $name);
        panel_update_state::Log($u, $rc === 0 ? 'started a rollback to ' . $name : 'asked for a rollback to ' . $name . ', zsudo answered ' . $rc);
        if ($rc === 0) {
            $_SESSION['pu_asked'] = $asked;
        } else {
            self::FlashRefused($rc);
        }
        self::Back();
    }

    /**
     * The message from the last action (<@ Result @>).
     *
     * @return string The HTML ('' when none).
     */
    static function getResult()
    {
        // (the template engine calls each getter twice: once while compiling, once to show it)
        static $msg = null;
        if ($msg === null) {
            $msg = empty($_SESSION['pu_msg']) ? false : $_SESSION['pu_msg'];
            unset($_SESSION['pu_msg']);
        }
        if (!$msg) {
            return '';
        }
        list($type, $text, $extra) = $msg;
        $value = isset($msg[3]) ? (string) $msg[3] : '';
        $cls = array('ok' => 'alert-success', 'warn' => 'alert-warning', 'fail' => 'alert-danger');
        $shown = panel_update_view::t($text);
        if ($value !== '') {
            $shown = str_replace('%s', panel_update_view::h($value), $shown);
        }
        return '<div class="alert ' . (isset($cls[$type]) ? $cls[$type] : 'alert-info') . '" role="status">' . $shown
            . ($extra !== '' ? ' <code>' . panel_update_view::h($extra) . '</code>' : '') . '</div>';
    }

    /**
     * The three figures at the top (<@ Tiles @>): installed, published, last checked (with Check now).
     *
     * @return string The HTML.
     */
    static function getTiles()
    {
        return self::Tiles(isset($_SESSION['pu_checking']) ? (int) $_SESSION['pu_checking'] : 0);
    }

    /**
     * The three figures (also for code/status.php).
     *
     * @param int $checking When a Check now was asked for (0 = not waiting for one).
     * @return string The HTML.
     */
    static function Tiles($checking)
    {
        $v = 'panel_update_view';
        $a = panel_update_state::Available();
        $wait = $checking > 0 && $a['checked'] < $checking && time() - $checking < 60;
        $ver = panel_update_state::Version();
        $inst = panel_update_state::Installed();
        $release = ($a['tag'] !== '' ? ui_language::translate('release') . ' ' . $a['tag'] : '') . ($a['date'] !== '' ? ', ' . date('j M Y', strtotime($a['date'])) : '');
        $pub = array(
            'available' => array('warn', 'cloud', 'Update available', ''),
            'current' => array('ok', 'ok', 'Up to date', 'the newest release is installed'),
            'unknown' => array('bad', 'warn', 'Could not check', 'GitHub did not answer; it tries again within the hour'),
            'never' => array('', 'clock', 'Not checked yet', 'the daemon checks once an hour'),
        );
        $p = $pub[$a['state']];
        // the published release is a value (tag and date): shown as it is, not translated as a whole
        $sub = ($a['state'] === 'available') ? $release : ($p[3] !== '' ? ui_language::translate($p[3]) : '');
        return '<div class="pu-tile">' . $v::Icon('box', 'pu-ico-lg') . '<div><b>Sentora-' . $v::h($ver !== '' ? $ver : '?') . '</b><span>'
            . $v::t('installed') . ($inst !== '' ? ': ' . $v::h($inst) : '') . '</span></div></div>'
            . '<div class="pu-tile ' . $p[0] . '">' . $v::Icon($p[1], 'pu-ico-lg') . '<div><b>' . $v::t($p[2]) . '</b><span>' . $v::h($sub) . '</span></div></div>'
            . '<div class="pu-tile">' . $v::Icon($wait ? 'run' : 'clock', 'pu-ico-lg' . ($wait ? ' pu-spin' : '')) . '<div><b>' . ($wait ? $v::t('Checking') : ($a['checked'] ? $v::Ago($a['checked']) : $v::t('never'))) . '</b><span>'
            . $v::t('last checked with GitHub') . '</span>'
            . '<form method="post" action="./?module=panel_update&amp;action=CheckNow"><button class="btn btn-default btn-xs" type="submit"' . ($wait ? ' disabled' : '') . '>' . $v::t('Check now') . '</button>'
            . runtime_csfr::Token() . '</form></div></div>';
    }

    /**
     * The last update or rollback (<@ Run @>), drawn again by the page while it runs.
     *
     * @return string The HTML.
     */
    static function getRun()
    {
        $r = panel_update_view::Run(panel_update_state::Run(), isset($_SESSION['pu_asked']) ? (int) $_SESSION['pu_asked'] : 0);
        return '<div id="pu-run" data-live="' . ($r['live'] ? '1' : '0') . '">' . $r['html'] . '</div>';
    }

    /**
     * Whether a job is running (so the update and rollback buttons are not offered).
     *
     * @return bool
     */
    static function Busy()
    {
        $run = panel_update_state::Run();
        $asked = isset($_SESSION['pu_asked']) ? (int) $_SESSION['pu_asked'] : 0;
        return ($run && $run['result'] === 'running') || ($asked > 0 && time() - $asked < 120 && (!$run || $run['started'] < $asked - 5));
    }

    /**
     * The update card (<@ UpdateCard @>): what is published, what an update does, the console command, and Update now.
     *
     * @return string The HTML.
     */
    static function getUpdateCard()
    {
        $v = 'panel_update_view';
        $a = panel_update_state::Available();
        $out = '<div class="pu-box"><h4>' . $v::t('Update the panel') . '</h4>';
        if (self::Busy()) {
            return $out . '<p class="pu-sub">' . $v::t('A job is running: see its steps above. Update and rollback are back when it is done.') . '</p></div>';
        }
        if ($a['state'] === 'current') {
            $out .= '<p class="pu-lead">' . $v::Icon('ok') . ' ' . $v::t('The panel is up to date.') . '</p>';
        } elseif ($a['state'] === 'available') {
            $out .= '<p class="pu-lead">' . $v::t('A newer panel is published') . ': <b>' . $v::h(($a['tag'] !== '' ? ui_language::translate('release') . ' ' . $a['tag'] : '') . ($a['date'] !== '' ? ', ' . date('j M Y', strtotime($a['date'])) : '')) . '</b>'
                . ($a['repo'] !== '' ? ' &middot; <a href="https://github.com/' . $v::h($a['repo']) . '/blob/master2027/CHANGELOG.md" target="_blank" rel="noopener noreferrer">' . $v::t('what changed') . '</a>' : '') . '</p>';
        } else {
            $out .= '<p class="pu-lead">' . $v::t('It is not known yet whether a newer panel is published. Updating anyway is safe: when the newest release is already installed, nothing is changed.') . '</p>';
        }
        if ($a['state'] !== 'current') {
            $out .= '<ul class="pu-what"><li>' . $v::t('The panel is backed up first; the last 5 backups are kept.') . '</li>'
                . '<li>' . $v::t('It takes about a minute. PHP and Apache reload at the end, so the panel may not answer for a few seconds; this page keeps watching.') . '</li>'
                . '<li>' . $v::t('Only the panel changes: websites, mail, databases and this server\'s settings are not touched.') . '</li></ul>'
                . $v::Console('', 'If the panel does not answer after the update, go back to the version before from the server\'s console, as root:')
                . $v::ActionForm('UpdatePanel', 'Update now', 'btn-primary');
        }
        return $out . '<p class="pu-sub">' . $v::t('The same from the server\'s console, as root:') . ' <code>sentora-update</code></p></div>';
    }

    /**
     * The backups card (<@ BackupsCard @>): each backup with Go back.
     *
     * @return string The HTML.
     */
    static function getBackupsCard()
    {
        $v = 'panel_update_view';
        $list = panel_update_state::Backups();
        $out = '<div class="pu-box"><h4>' . $v::t('Go back') . '</h4><p class="pu-sub">' . $v::t('Each update keeps a backup of the panel as it was before. Going back puts the panel\'s files back from it; websites, mail, databases and this server\'s settings are not touched.') . '</p>';
        if (!$list) {
            return $out . '<p class="pu-empty">' . $v::t('No backups yet: the first update makes one.') . '</p></div>';
        }
        $busy = self::Busy();
        $out .= '<ul class="pu-backups">';
        foreach ($list as $i => $b) {
            $out .= '<li><details' . ($busy ? ' class="is-off"' : '') . '><summary>' . $v::Icon('back') . '<span><b>' . $v::t('Before the update of') . ' ' . $v::h(date('j M Y H:i', $b['time'])) . '</b>'
                . '<small>' . $v::h($b['name']) . ' &middot; ' . $v::h(round($b['size'] / 1048576)) . ' MB' . ($i === 0 ? ' &middot; ' . $v::t('the newest') : '') . '</small></span></summary>'
                . ($busy ? '' : '<div class="pu-confirm">' . $v::Console($b['name'], 'The same from the server\'s console, as root (it works also when the panel does not):')
                    . $v::ActionForm('RollbackPanel', 'Go back to this version', 'btn-danger', $b['name']) . '</div>')
                . '</details></li>';
        }
        return $out . '</ul></div>';
    }

    /**
     * When GitHub was last asked (<@ Checked @>), so the page knows when a Check now has its answer.
     *
     * @return string A Unix time ('0' when never).
     */
    static function getChecked()
    {
        return (string) panel_update_state::Available()['checked'];
    }

    /**
     * Whether the page should keep watching (<@ Live @>): a run or a Check now is under way.
     *
     * @return string '1' or '0'.
     */
    static function getWatch()
    {
        $checking = isset($_SESSION['pu_checking']) ? (int) $_SESSION['pu_checking'] : 0;
        $a = panel_update_state::Available();
        return (self::Busy() || ($checking > 0 && $a['checked'] < $checking && time() - $checking < 60)) ? '1' : '0';
    }
}
