<?php

/**
 * Dashboard: the page shown after logging in. Each account arranges its own widgets
 * (add from the list, remove, move up or down, back to the defaults); the layout is
 * kept in x_dashboard. "Continue to your panel administration" goes to the panel's
 * usual home page.
 */
require_once dirname(__FILE__) . '/widgets.php';

/**
 * Dashboard module: controller.
 *
 * Shows the logged-in account's widgets and handles adding, removing, moving and
 * resetting them; the layout is a JSON list of widget IDs in x_dashboard.
 */
class module_controller extends ctrl_module
{
    /** @var string 'reset' after the widgets were reset (shown by getResult()), else ''. */
    static $msg = '';

    /**
     * Return the logged-in account.
     *
     * @return array<string, string> See ctrl_users::GetUserDetail().
     */
    static function Me()
    {
        return ctrl_users::GetUserDetail();
    }

    /**
     * Create the x_dashboard table if it is missing (once per request).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if (!$done) {
            $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dashboard (dw_acc_fk INT NOT NULL PRIMARY KEY, dw_widgets_tx TEXT NOT NULL) DEFAULT CHARSET=utf8");
            $done = true;
        }
    }

    /**
     * Return this account's widgets, in order (only ones it may have).
     *
     * The defaults when the account has not saved a layout yet.
     * @global db_driver $zdbh The panel's database connection.
     * @return string[] Widget IDs.
     */
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

    /**
     * Save the account's widget list (duplicates removed).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string[] $list Widget IDs in order.
     * @return void
     */
    static function SaveLayout(array $list)
    {
        global $zdbh;
        self::Ensure();
        $zdbh->prepare("REPLACE INTO x_dashboard (dw_acc_fk, dw_widgets_tx) VALUES (:a, :w)")
            ->execute(array(':a' => (int) self::Me()['userid'], ':w' => json_encode(array_values(array_unique($list)))));
    }

    /**
     * Return the widget ID posted in inWidget, if the account may have that widget.
     *
     * @global runtime_controller $controller The request controller.
     * @return string The widget ID, or ''.
     */
    static function FormWidget()
    {
        global $controller;
        $id = (string) $controller->GetControllerRequest('FORM', 'inWidget');
        return isset(dashboard_widgets::Available(self::Me())[$id]) ? $id : '';
    }

    /**
     * Handle the Add widget form (POST): add the widget to the end of the dashboard.
     *
     * Checks the CSFR token (dies when it is wrong).
     * @return void
     */
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

    /**
     * Handle a widget's remove button (POST).
     *
     * Checks the CSFR token (dies when it is wrong).
     * @return void
     */
    static function doRemoveWidget()
    {
        runtime_csfr::Protect();
        $id = self::FormWidget();
        self::SaveLayout(array_values(array_diff(self::Layout(), array($id))));
    }

    /**
     * Handle a widget's up/down button (POST, inDir 'up' or 'down').
     *
     * Checks the CSFR token (dies when it is wrong).
     * @global runtime_controller $controller The request controller.
     * @return void
     */
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

    /**
     * Handle "Back to the default widgets" (POST).
     *
     * Checks the CSFR token (dies when it is wrong) and sets $msg.
     * @return void
     */
    static function doResetWidgets()
    {
        runtime_csfr::Protect();
        self::SaveLayout(dashboard_widgets::Defaults(self::Me()));
        self::$msg = 'reset';
    }

    /**
     * Return "Good morning/afternoon/evening, <name>" (template: <@ Greeting @>).
     *
     * @return string HTML-escaped text.
     */
    static function getGreeting()
    {
        $me = self::Me();
        $name = trim((string) ($me['fullname'] ?? ''));
        $hour = (int) date('G');
        $part = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
        return dashboard_widgets::h(ui_language::translate($part)) . ', ' . dashboard_widgets::h($name !== '' ? $name : $me['username']);
    }

    /**
     * Return the account's widgets with their move/remove buttons (template: <@ Widgets @>).
     *
     * @return string HTML.
     */
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

    /**
     * Return the Add widget form and the reset button (template: <@ WidgetPicker @>).
     *
     * @return string HTML.
     */
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

    /**
     * Return the message for the last action (template: <@ Result @>).
     *
     * @return string The HTML message, or ''.
     */
    static function getResult()
    {
        return self::$msg === 'reset' ? ui_sysmessage::shout(ui_language::translate('Your dashboard is back to the default widgets.'), 'zannounceok') : '';
    }
}
