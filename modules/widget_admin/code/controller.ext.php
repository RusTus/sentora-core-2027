<?php

/**
 * Widget Admin (administrators): which dashboard widgets are on, which are on new
 * dashboards and in what order, which groups may use them; upload and remove widgets.
 * Uploads and removals are queued for the daemon (root), which checks and installs
 * them into etc/dashboard-widgets/ (the panel can not write its own code).
 */
require_once dirname(__FILE__) . '/../../dashboard/code/widgets.php';

/**
 * Widget Admin module: controller.
 *
 * Saves the widget settings (x_dashboard_widgets), resets every dashboard, and queues
 * widget uploads and removals as files in QUEUE for the daemon.
 */
class module_controller extends ctrl_module
{
    const QUEUE = '/var/lib/sentora/tmp/';
    const RESULTS = '/var/lib/sentora/widget-results.json';
    const MAX_ZIP = 524288;

    /** @var array<int, array{0: string, 1: string}> Messages for getResult(): array('ok'|'error', English text). */
    static $msg = array();

    /**
     * Escape text for HTML (UTF-8, quotes too).
     *
     * @param mixed $s The text.
     * @return string
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Is the logged-in user an administrator (group 1)?
     *
     * @return bool
     */
    static function IsAdmin()
    {
        $me = ctrl_users::GetUserDetail();
        return (int) $me['usergroupid'] === 1;
    }

    /**
     * Return the user groups.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int|string, string> Group ID => group name, by ID.
     */
    static function Groups()
    {
        global $zdbh;
        return $zdbh->query("SELECT ug_id_pk, ug_name_vc FROM x_groups ORDER BY ug_id_pk")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Handle the widget settings form (POST): save on/off, default, order and groups of every widget.
     *
     * Checks the CSFR token (dies when it is wrong); does nothing for non-administrators.
     * Widgets marked administrators-only keep group 1 only.
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doSaveWidgets()
    {
        global $zdbh, $controller;
        runtime_csfr::Protect();
        if (!self::IsAdmin()) {
            return;
        }
        $f = $controller->GetAllControllerRequests('FORM');
        $groups = array_map('intval', array_keys(self::Groups()));
        $set = dashboard_widgets::Settings();
        $up = $zdbh->prepare("UPDATE x_dashboard_widgets SET dwc_enabled_in = :e, dwc_default_in = :d, dwc_order_in = :o, dwc_groups_tx = :g WHERE dwc_id_vc = :i");
        foreach (dashboard_widgets::All() as $id => $w) {
            $g = array();
            foreach ($groups as $gid) {
                if (!empty($f['inGroup_' . $id . '_' . $gid])) {
                    $g[] = $gid;
                }
            }
            if ($w[2]) {
                $g = array(1); // administrators only
            }
            $order = isset($f['inOrder_' . $id]) && ctype_digit((string) $f['inOrder_' . $id]) ? min(999, (int) $f['inOrder_' . $id]) : $set[$id]['order'];
            $up->execute(array(':e' => (isset($f['inEnabled_' . $id]) && $f['inEnabled_' . $id] === '0') ? 0 : 1,
                ':d' => !empty($f['inDefault_' . $id]) ? 1 : 0, ':o' => $order, ':g' => json_encode($g), ':i' => $id));
        }
        self::$msg[] = array('ok', 'The widget settings have been saved. They apply on the next page every account opens.');
    }

    /**
     * Handle "Apply the defaults to every account now" (POST): delete every saved dashboard layout.
     *
     * Checks the CSFR token (dies when it is wrong); does nothing for non-administrators.
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doApplyDefaults()
    {
        global $zdbh;
        runtime_csfr::Protect();
        if (!self::IsAdmin()) {
            return;
        }
        // every dashboard goes back to the default widgets (each account's next page)
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dashboard (dw_acc_fk INT NOT NULL PRIMARY KEY, dw_widgets_tx TEXT NOT NULL) DEFAULT CHARSET=utf8");
        $n = $zdbh->exec("DELETE FROM x_dashboard");
        self::$msg[] = array('ok', 'Every dashboard now shows the default widgets (' . (int) $n . ' arranged dashboard(s) reset).');
    }

    /**
     * Handle the widget upload form (POST, file inWidgetFile): check the zip and queue it for the daemon.
     *
     * Quick checks here; the daemon checks the package fully before installing it.
     * Checks the CSFR token (dies when it is wrong); does nothing for non-administrators.
     * The result is added to $msg.
     * @return void
     */
    static function doUploadWidget()
    {
        runtime_csfr::Protect();
        if (!self::IsAdmin()) {
            return;
        }
        $file = isset($_FILES['inWidgetFile']) ? $_FILES['inWidgetFile'] : null;
        if (!$file || (int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            self::$msg[] = array('error', 'Choose a widget package (.zip) to upload.');
            return;
        }
        if ((int) $file['size'] > self::MAX_ZIP) {
            self::$msg[] = array('error', 'A widget package is at most 512 KB.');
            return;
        }
        $check = dashboard_widgets::CheckPackage($file['tmp_name']);
        if (is_string($check)) {
            self::$msg[] = array('error', 'That package can not be used: ' . $check);
            return;
        }
        $token = bin2hex(random_bytes(8));
        if (!@move_uploaded_file($file['tmp_name'], self::QUEUE . 'widget-queue-' . $token . '.zip')) {
            self::$msg[] = array('error', 'The package could not be stored for the daemon.');
            return;
        }
        $me = ctrl_users::GetUserDetail();
        file_put_contents(self::QUEUE . 'widget-queue-' . $token . '.json', json_encode(array('action' => 'install', 'id' => $check['id'],
            'name' => $check['name'], 'version' => $check['version'], 'by' => $me['username'], 'time' => time())));
        self::$msg[] = array('ok', 'Widget "' . $check['name'] . '" is queued: the daemon checks and installs it within 5 minutes.');
    }

    /**
     * Handle a widget's Remove button (POST, inWidget): queue an uploaded widget for removal.
     *
     * Checks the CSFR token (dies when it is wrong); does nothing for non-administrators.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doRemoveWidget()
    {
        global $controller;
        runtime_csfr::Protect();
        if (!self::IsAdmin()) {
            return;
        }
        $id = (string) $controller->GetControllerRequest('FORM', 'inWidget');
        $up = dashboard_widgets::Uploaded();
        if (!isset($up[$id])) {
            self::$msg[] = array('error', 'Only uploaded widgets can be removed.');
            return;
        }
        $me = ctrl_users::GetUserDetail();
        file_put_contents(self::QUEUE . 'widget-queue-' . bin2hex(random_bytes(8)) . '.json', json_encode(array('action' => 'remove', 'id' => $id,
            'name' => $up[$id]['name'], 'by' => $me['username'], 'time' => time())));
        self::$msg[] = array('ok', 'Widget "' . $up[$id]['name'] . '" is queued for removal (within 5 minutes).');
    }

    /**
     * Return the queued uploads and removals that the daemon has not handled yet.
     *
     * @return array<int, array<string, mixed>> The queue files' contents: 'action' ('install'|'remove'), 'id', 'name', 'by', 'time' (and 'version' for installs).
     */
    static function Pending()
    {
        $out = array();
        foreach ((array) glob(self::QUEUE . 'widget-queue-*.json') as $f) {
            $j = json_decode((string) @file_get_contents($f), true);
            if (is_array($j)) {
                $out[] = $j;
            }
        }
        return $out;
    }

    /**
     * Return the widget settings form, the remove forms and the apply-defaults form (template: <@ WidgetTable @>).
     *
     * @return string HTML (a short notice for non-administrators).
     */
    static function getWidgetTable()
    {
        if (!self::IsAdmin()) {
            return '<p>' . ui_language::translate('Only administrators can manage the dashboard widgets.') . '</p>';
        }
        $h = 'self::h';
        $groups = self::Groups();
        $set = dashboard_widgets::Settings();
        $all = dashboard_widgets::All();
        uksort($all, function ($a, $b) use ($set) { return $set[$a]['order'] - $set[$b]['order']; });
        $head = '<tr><th>' . ui_language::translate('Widget') . '</th><th>' . ui_language::translate('On/Off') . '</th><th style="text-align:center">' . ui_language::translate('Default') . '</th><th>' . ui_language::translate('Order') . '</th>';
        foreach ($groups as $gid => $gname) {
            $head .= '<th style="text-align:center">' . self::h(ui_language::translate($gname)) . '</th>';
        }
        $head .= '<th></th></tr>';
        $rows = '';
        foreach ($all as $id => $w) {
            $s = $set[$id];
            $src = ($w[4] === 'uploaded') ? '<span class="label label-info">' . ui_language::translate('uploaded') . ($w[5] !== '' ? ' ' . self::h($w[5]) : '') . '</span>' : '<span class="label label-default">' . ui_language::translate('built-in') . '</span>';
            $rows .= '<tr><td><strong>' . self::h(ui_language::translate($w[0])) . '</strong> ' . $src . '<br><span class="text-muted">' . self::h(ui_language::translate($w[1])) . '</span></td>'
                . '<td><select class="form-control" style="min-width: 118px;" name="inEnabled_' . self::h($id) . '"><option value="1"' . ($s['enabled'] ? ' selected' : '') . '>' . ui_language::translate('Enabled') . '</option><option value="0"' . (!$s['enabled'] ? ' selected' : '') . '>' . ui_language::translate('Disabled') . '</option></select></td>'
                . '<td style="text-align:center"><input type="checkbox" name="inDefault_' . self::h($id) . '" value="1"' . ($s['default'] ? ' checked' : '') . '></td>'
                . '<td><input class="form-control" name="inOrder_' . self::h($id) . '" value="' . (int) $s['order'] . '" inputmode="numeric" maxlength="3" style="width: 70px;"></td>';
            foreach ($groups as $gid => $gname) {
                $locked = $w[2] && (int) $gid !== 1;
                $rows .= '<td style="text-align:center"><input type="checkbox" name="inGroup_' . self::h($id) . '_' . (int) $gid . '" value="1"'
                    . (in_array((int) $gid, $s['groups'], true) ? ' checked' : '') . ($locked ? ' disabled title="' . self::h(ui_language::translate('Administrators only')) . '"' : '') . '></td>';
            }
            $rows .= '<td>' . ($w[4] === 'uploaded' ? '<button class="btn btn-danger btn-xs" type="submit" form="wa-remove-' . self::h($id) . '">' . ui_language::translate('Remove') . '</button>' : '') . '</td></tr>';
        }
        $removeForms = '';
        foreach ($all as $id => $w) {
            if ($w[4] === 'uploaded') {
                $removeForms .= '<form id="wa-remove-' . self::h($id) . '" method="post" action="./?module=widget_admin&amp;action=RemoveWidget" onsubmit="return confirm(\'' . self::h(ui_language::translate('Remove this widget from the server')) . '\');">'
                    . '<input type="hidden" name="inWidget" value="' . self::h($id) . '">' . runtime_csfr::Token() . '</form>';
            }
        }
        return '<form method="post" action="./?module=widget_admin&amp;action=SaveWidgets"><table class="table table-striped">' . $head . $rows . '</table>'
            . runtime_csfr::Token() . '<button class="btn btn-primary" type="submit">' . ui_language::translate('Save Changes') . '</button></form>' . $removeForms
            . '<form method="post" action="./?module=widget_admin&amp;action=ApplyDefaults" style="margin-top: 10px;" onsubmit="return confirm(\'' . self::h(ui_language::translate('Replace every dashboard with the default widgets? Accounts lose the layouts they arranged.')) . '\');">'
            . runtime_csfr::Token() . '<button class="btn btn-default" type="submit">' . ui_language::translate('Apply the defaults to every account now') . '</button>'
            . ' <span class="text-muted">' . ui_language::translate('Every dashboard then shows the default widgets above, in that order.') . '</span></form>';
    }

    /**
     * Return the upload form with the queued and recent results (template: <@ UploadForm @>).
     *
     * @return string HTML, or '' for non-administrators.
     */
    static function getUploadForm()
    {
        if (!self::IsAdmin()) {
            return '';
        }
        $out = '<form method="post" action="./?module=widget_admin&amp;action=UploadWidget" enctype="multipart/form-data" class="form-inline">'
            . '<input type="file" name="inWidgetFile" accept=".zip" class="form-control" required> ' . runtime_csfr::Token()
            . '<button class="btn btn-primary" type="submit">' . ui_language::translate('Upload widget') . '</button></form>'
            . '<p class="text-muted" style="margin-top: 6px;">' . ui_language::translate('A .zip with widget.xml and widget.php (and widget.css if it needs styles), at most 512 KB. The format is described in etc/dashboard-widgets/README.md. A new version of a widget replaces the old one and keeps its settings.') . '</p>';
        $pending = self::Pending();
        $res = json_decode((string) @file_get_contents(self::RESULTS), true);
        if ($pending || is_array($res)) {
            $out .= '<table class="table table-condensed" style="max-width: 900px;">';
            foreach ($pending as $p) {
                $out .= '<tr><td><span class="label label-warning">' . ui_language::translate('queued') . '</span></td><td>' . self::h(($p['action'] === 'remove' ? 'Remove ' : 'Install ') . $p['name'])
                    . '</td><td class="text-muted">' . self::h(date('Y-m-d H:i', (int) $p['time']) . ', ' . $p['by']) . '</td></tr>';
            }
            foreach (array_slice(is_array($res) ? array_reverse($res) : array(), 0, 8) as $r) {
                $out .= '<tr><td>' . ($r['ok'] ? '<span class="label label-success">OK</span>' : '<span class="label label-danger">' . ui_language::translate('FAILED') . '</span>') . '</td><td>'
                    . self::h($r['text']) . '</td><td class="text-muted">' . self::h(date('Y-m-d H:i', (int) $r['time']) . ', ' . $r['by']) . '</td></tr>';
            }
            $out .= '</table>';
        }
        return $out;
    }

    /**
     * Return the messages of the last action (template: <@ Result @>).
     *
     * @return string HTML messages, or ''.
     */
    static function getResult()
    {
        $out = '';
        foreach (self::$msg as $m) {
            $out .= ui_sysmessage::shout(self::h(ui_language::translate($m[1])), $m[0] === 'ok' ? 'zannounceok' : 'zannounceerror');
        }
        return $out;
    }
}
