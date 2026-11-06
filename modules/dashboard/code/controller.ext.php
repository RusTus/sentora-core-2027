<?php

/**
 * Dashboard: the page shown after logging in. Each account arranges its own widgets
 * (add from the list, remove, move up or down, back to the defaults); the layout is
 * kept in x_dashboard. "Continue to your panel administration" goes to the panel's
 * usual home page.
 */
require_once dirname(__FILE__) . '/widgets.php';

class module_controller extends ctrl_module
{
    static $msg = '';

    static function Me()
    {
        return ctrl_users::GetUserDetail();
    }

    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if (!$done) {
            $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dashboard (dw_acc_fk INT NOT NULL PRIMARY KEY, dw_widgets_tx TEXT NOT NULL) DEFAULT CHARSET=utf8");
            $done = true;
        }
    }

    /** this account's widgets, in order (only ones it may have) */
    static function Layout()
    {
        global $zdbh;
        self::Ensure();
        $me = self::Me();
        $q = $zdbh->prepare("SELECT dw_widgets_tx FROM x_dashboard WHERE dw_acc_fk = :a");
        $q->execute(array(':a' => (int) $me['userid']));
        $raw = $q->fetchColumn();
        $list = ($raw === false) ? dashboard_widgets::Defaults($me) : (array) json_decode((string) $raw, true);
        $ok = dashboard_widgets::Available($me);
        return array_values(array_filter($list, function ($id) use ($ok) { return is_string($id) && isset($ok[$id]); }));
    }

    static function SaveLayout(array $list)
    {
        global $zdbh;
        self::Ensure();
        $zdbh->prepare("REPLACE INTO x_dashboard (dw_acc_fk, dw_widgets_tx) VALUES (:a, :w)")
            ->execute(array(':a' => (int) self::Me()['userid'], ':w' => json_encode(array_values(array_unique($list)))));
    }

    static function FormWidget()
    {
        global $controller;
        $id = (string) $controller->GetControllerRequest('FORM', 'inWidget');
        return isset(dashboard_widgets::Available(self::Me())[$id]) ? $id : '';
    }

    static function doAddWidget()
    {
        runtime_csfr::Protect();
        $id = self::FormWidget();
        if ($id !== '') {
            $l = self::Layout();
            if (!in_array($id, $l, true)) {
                $l[] = $id;
                self::SaveLayout($l);
            }
        }
    }

    static function doRemoveWidget()
    {
        runtime_csfr::Protect();
        $id = self::FormWidget();
        self::SaveLayout(array_values(array_diff(self::Layout(), array($id))));
    }

    static function doMoveWidget()
    {
        global $controller;
        runtime_csfr::Protect();
        $id = self::FormWidget();
        $l = self::Layout();
        $i = array_search($id, $l, true);
        $j = ((string) $controller->GetControllerRequest('FORM', 'inDir') === 'up') ? $i - 1 : $i + 1;
        if ($i !== false && $j >= 0 && $j < count($l)) {
            list($l[$i], $l[$j]) = array($l[$j], $l[$i]);
            self::SaveLayout($l);
        }
    }

    static function doResetWidgets()
    {
        runtime_csfr::Protect();
        self::SaveLayout(dashboard_widgets::Defaults(self::Me()));
        self::$msg = 'reset';
    }

    static function getGreeting()
    {
        $me = self::Me();
        $name = trim((string) ($me['fullname'] ?? ''));
        $hour = (int) date('G');
        $part = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
        return dashboard_widgets::h(ui_language::translate($part)) . ', ' . dashboard_widgets::h($name !== '' ? $name : $me['username']);
    }

    static function getWidgets()
    {
        $me = self::Me();
        $all = dashboard_widgets::All();
        $layout = self::Layout();
        $form = function ($action, $id, $label, $extra = '', $cls = 'btn-link') {
            return '<form method="post" action="./?module=dashboard&amp;action=' . $action . '" style="display:inline">'
                . '<input type="hidden" name="inWidget" value="' . dashboard_widgets::h($id) . '">' . $extra . runtime_csfr::Token()
                . '<button class="btn ' . $cls . ' btn-xs" type="submit" title="' . dashboard_widgets::h($label) . '" aria-label="' . dashboard_widgets::h($label) . '">' . $label . '</button></form>';
        };
        if (!$layout) {
            return '<p class="text-muted">' . ui_language::translate('Your dashboard is empty: add a widget below.') . '</p>';
        }
        $out = dashboard_widgets::Styles($layout) . '<div class="dw-grid">';
        $n = count($layout);
        foreach ($layout as $i => $id) {
            $tools = ($i > 0 ? $form('MoveWidget', $id, '&uarr;', '<input type="hidden" name="inDir" value="up">') : '')
                . ($i < $n - 1 ? $form('MoveWidget', $id, '&darr;', '<input type="hidden" name="inDir" value="down">') : '')
                . $form('RemoveWidget', $id, '&times;');
            $out .= '<section class="dw-card" id="w-' . dashboard_widgets::h($id) . '"><header><h4>' . dashboard_widgets::h(ui_language::translate($all[$id][0])) . '</h4><span class="dw-tools">' . $tools . '</span></header>'
                . '<div class="dw-body">' . dashboard_widgets::Render($id, $me) . '</div></section>';
        }
        return $out . '</div>';
    }

    static function getWidgetPicker()
    {
        $me = self::Me();
        $layout = self::Layout();
        $opts = '';
        foreach (dashboard_widgets::Available($me) as $id => $w) {
            if (!in_array($id, $layout, true)) {
                $opts .= '<option value="' . dashboard_widgets::h($id) . '">' . dashboard_widgets::h(ui_language::translate($w[0])) . ' &mdash; ' . dashboard_widgets::h(ui_language::translate($w[1])) . '</option>';
            }
        }
        $add = ($opts === '') ? '<span class="text-muted">' . ui_language::translate('Every widget is on your dashboard.') . '</span>'
            : '<form method="post" action="./?module=dashboard&amp;action=AddWidget" class="form-inline" style="display:inline">'
              . '<select name="inWidget" class="form-control" style="max-width: 560px;">' . $opts . '</select> '
              . runtime_csfr::Token() . '<button class="btn btn-primary" type="submit">' . ui_language::translate('Add widget') . '</button></form>';
        return $add . ' <form method="post" action="./?module=dashboard&amp;action=ResetWidgets" style="display:inline; margin-left: 10px;">' . runtime_csfr::Token()
            . '<button class="btn btn-default" type="submit">' . ui_language::translate('Back to the default widgets') . '</button></form>';
    }

    static function getResult()
    {
        return self::$msg === 'reset' ? ui_sysmessage::shout(ui_language::translate('Your dashboard is back to the default widgets.'), 'zannounceok') : '';
    }
}
