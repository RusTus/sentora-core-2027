<?php

/**
 * Widget Admin (administrators): which dashboard widgets are on, which are on new
 * dashboards and in what order, which groups may use them; upload and remove widgets.
 * Uploads and removals are queued for the daemon (root), which checks and installs
 * them into etc/dashboard-widgets/ (the panel can not write its own code).
 *
 * Written by RusTus, 2026-11-07
 */
require_once dirname(__FILE__) . '/../../dashboard/code/widgets.php';

/**
 * A widget's look on the dashboard: icon name, colour and the icon's SVG (the dashboard's own table).
 *
 * @param string $id The widget ID.
 * @return array{0: string, 1: string, 2: string}
 */
function module_controller_dashboard_look($id)
{
    $look = array('getting_started' => array('flag', '#2db87d', '<path d="M5 21V4"/><path d="M5 4h11l-2 4 2 4H5"/>'), 'usage' => array('gauge', '#447fb3', '<path d="M4 18a8 8 0 1 1 16 0"/><path d="m12 18 4-6"/>'),
        'domains' => array('globe', '#1fa2b5', '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18"/>'),
        'security' => array('shield', '#8e6bd8', '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>'),
        'backups' => array('archive', '#f5a623', '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9"/>'),
        'server' => array('server', '#ff6854', '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/>'),
        'spamav' => array('filter', '#5b6cc4', '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>'));
    $l = isset($look[$id]) ? $look[$id] : array('puzzle', '#9aa5b1', '<path d="M10 4a2 2 0 1 1 4 0v2h4a1 1 0 0 1 1 1v4h-2a2 2 0 1 0 0 4h2v4a1 1 0 0 1-1 1h-4v-2a2 2 0 1 0-4 0v2H6a1 1 0 0 1-1-1v-4h2a2 2 0 1 0 0-4H5V7a1 1 0 0 1 1-1h4z"/>');
    $l[2] = '<svg class="dw-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $l[2] . '</svg>';
    return $l;
}

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

    /** @var array<int, array{0: string, 1: string, 2?: string}> Messages for getResult(): array('ok'|'error', English text, and the value for its %s). */
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
        self::$msg[] = array('ok', 'Every dashboard now shows the default widgets (%s arranged dashboard(s) reset).', (string) (int) $n);
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
            self::$msg[] = array('error', 'That package can not be used: %s', $check);
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
        self::$msg[] = array('ok', 'Widget "%s" is queued: the daemon checks and installs it within 5 minutes.', (string) $check['name']);
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
        self::$msg[] = array('ok', 'Widget "%s" is queued for removal (within 5 minutes).', (string) $up[$id]['name']);
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
            return '<p>' . self::h(ui_language::translate('Only administrators can manage the dashboard widgets.')) . '</p>';
        }
        $t = function ($s) { return self::h(ui_language::translate($s)); };
        $groups = self::Groups();
        $set = dashboard_widgets::Settings(true);   // as saved, also right after a save
        $all = dashboard_widgets::All();
        uksort($all, function ($a, $b) use ($set) { return $set[$a]['order'] - $set[$b]['order']; });
        $head = '<tr><th>' . $t('Widget') . '</th><th class="wa-c">' . $t('On') . '</th><th class="wa-c">' . $t('Default') . '</th><th class="wa-c">' . $t('Order') . '</th>';
        foreach ($groups as $gid => $gname) {
            $head .= '<th class="wa-c">' . self::h(ui_language::translate($gname)) . '</th>';
        }
        $head .= '<th></th></tr>';
        $rows = '';
        foreach ($all as $id => $w) {
            $s = $set[$id];
            $look = module_controller_dashboard_look($id);
            $src = ($w[4] === 'uploaded') ? '<span class="label label-info">' . $t('uploaded') . ($w[5] !== '' ? ' ' . self::h($w[5]) : '') . '</span>' : '<span class="label label-default">' . $t('built-in') . '</span>';
            $rows .= '<tr' . ($s['enabled'] ? '' : ' class="wa-off"') . '><td><div class="wa-name" style="--c:' . $look[1] . '">' . $look[2] . '<div><b>' . self::h(ui_language::translate($w[0])) . '</b> ' . $src
                . ($w[2] ? ' <span class="label label-warning">' . $t('administrators only') . '</span>' : '') . '<span>' . self::h(ui_language::translate($w[1])) . '</span></div></div></td>'
                // on/off: a switch that sends 0 when off (doSaveWidgets() reads "0" as off)
                . '<td class="wa-c"><label class="bk-switch wa-switch" title="' . $t('Enabled') . '"><input type="hidden" name="inEnabled_' . self::h($id) . '" value="0"><input type="checkbox" name="inEnabled_' . self::h($id) . '" value="1"' . ($s['enabled'] ? ' checked' : '') . '><span class="bk-toggle" aria-hidden="true"></span></label></td>'
                . '<td class="wa-c"><input type="checkbox" name="inDefault_' . self::h($id) . '" value="1"' . ($s['default'] ? ' checked' : '') . ' title="' . $t('On a new dashboard') . '"></td>'
                . '<td class="wa-c"><input class="form-control wa-order" type="text" name="inOrder_' . self::h($id) . '" value="' . (int) $s['order'] . '" inputmode="numeric" maxlength="3" aria-label="' . $t('Order') . '"></td>';
            foreach ($groups as $gid => $gname) {
                $locked = $w[2] && (int) $gid !== 1;
                $rows .= '<td class="wa-c"><input type="checkbox" name="inGroup_' . self::h($id) . '_' . (int) $gid . '" value="1"'
                    . (in_array((int) $gid, $s['groups'], true) ? ' checked' : '') . ($locked ? ' disabled title="' . $t('Administrators only') . '"' : '') . '></td>';
            }
            $rows .= '<td class="wa-c">' . ($w[4] === 'uploaded' ? '<button class="dw-tool dw-remove" type="submit" form="wa-remove-' . self::h($id) . '" title="' . $t('Remove this widget from the server') . '" aria-label="' . $t('Remove') . '">'
                . '<svg class="dw-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="m6 7 1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/></svg></button>' : '') . '</td></tr>';
        }
        $removeForms = '';
        foreach ($all as $id => $w) {
            if ($w[4] === 'uploaded') {
                $removeForms .= '<form id="wa-remove-' . self::h($id) . '" method="post" action="./?module=widget_admin&amp;action=RemoveWidget" data-confirm="' . $t('Remove this widget from the server?') . '">'
                    . '<input type="hidden" name="inWidget" value="' . self::h($id) . '">' . runtime_csfr::Token() . '</form>';
            }
        }
        return '<form method="post" action="./?module=widget_admin&amp;action=SaveWidgets"><div class="bk-scroll"><table class="bk-table wa-table"><thead>' . $head . '</thead><tbody>' . $rows . '</tbody></table></div>'
            . runtime_csfr::Token() . '<div class="wa-foot"><button class="btn btn-primary" type="submit">' . $t('Save changes') . '</button></div></form>' . $removeForms
            . '<form class="wa-defaults" method="post" action="./?module=widget_admin&amp;action=ApplyDefaults" data-confirm="' . $t('Replace every dashboard with the default widgets? Accounts lose the layouts they arranged.') . '">'
            . runtime_csfr::Token() . '<div><b>' . $t('Apply the defaults to every account now') . '</b><span>' . $t('Every dashboard then shows the default widgets above, in that order. Accounts lose the layouts they arranged.') . '</span></div>'
            . '<button class="btn btn-default" type="submit">' . $t('Apply to every account') . '</button></form>';
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
        $t = function ($s) { return self::h(ui_language::translate($s)); };
        $out = '<form class="wa-upload" method="post" action="./?module=widget_admin&amp;action=UploadWidget" enctype="multipart/form-data">'
            . '<label class="wa-drop"><svg class="dw-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 15v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/><path d="m16 8-4-4-4 4"/><path d="M12 4v11"/></svg>'
            . '<span><b>' . $t('Choose a widget package (.zip)') . '</b><small>' . $t('A .zip with widget.xml and widget.php (and widget.css if it needs styles), at most 512 KB. The format is described in etc/dashboard-widgets/README.md. A new version of a widget replaces the old one and keeps its settings.') . '</small></span>'
            . '<input type="file" name="inWidgetFile" accept=".zip" required></label>' . runtime_csfr::Token()
            . '<button class="btn btn-primary" type="submit">' . $t('Upload widget') . '</button></form>';
        $pending = self::Pending();
        $res = json_decode((string) @file_get_contents(self::RESULTS), true);
        if ($pending || is_array($res)) {
            $out .= '<div class="wa-results"><h5>' . $t('Recent uploads') . '</h5>';
            foreach ($pending as $p) {
                $out .= '<div class="bk-state info"><span class="label label-warning">' . $t('queued') . '</span><div><b>' . str_replace('%s', self::h($p['name']), $t($p['action'] === 'remove' ? 'Remove %s' : 'Install %s')) . '</b><span class="bk-sub">'
                    . self::h(date('Y-m-d H:i', (int) $p['time']) . ', ' . $p['by']) . ' · ' . $t('the daemon does it within 5 minutes') . '</span></div></div>';
            }
            foreach (array_slice(is_array($res) ? array_reverse($res) : array(), 0, 8) as $r) {
                $out .= '<div class="bk-state">' . ($r['ok'] ? '<span class="label label-success">' . $t('OK') . '</span>' : '<span class="label label-danger">' . $t('FAILED') . '</span>') . '<div><b>'
                    . self::h($r['text']) . '</b><span class="bk-sub">' . self::h(date('Y-m-d H:i', (int) $r['time']) . ', ' . $r['by']) . '</span></div></div>';
            }
            $out .= '</div>';
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
            $text = self::h(ui_language::translate($m[1]));
            $out .= ui_sysmessage::shout(isset($m[2]) ? str_replace('%s', self::h($m[2]), $text) : $text, $m[0] === 'ok' ? 'zannounceok' : 'zannounceerror');
        }
        return $out;
    }
}
