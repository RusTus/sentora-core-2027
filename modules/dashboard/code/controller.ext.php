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
     * Save the order the widgets were dragged into (POST inOrder: widget IDs, comma separated).
     *
     * The order must hold exactly the widgets on the account's dashboard. Called from the
     * page in the background: the panel has already checked the form token (and keeps it,
     * so the next drag can be saved too); answers with JSON and ends the request.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doSaveOrder()
    {
        global $controller;
        $order = array_values(array_filter(explode(',', (string) $controller->GetControllerRequest('FORM', 'inOrder')), 'strlen'));
        $layout = self::Layout();
        $same = (count($order) === count($layout) && count(array_unique($order)) === count($order) && !array_diff($order, $layout));
        if ($same) {
            self::SaveLayout($order);
        }
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'fetch') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            exit(json_encode(array('ok' => $same)));
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
     * An inline SVG icon (stroked, in the text colour).
     *
     * @param string $name The icon: flag, gauge, globe, shield, archive, server, filter, puzzle, up, down, x, plus, reset.
     * @return string SVG markup, or '' for an unknown name.
     */
    static function Icon($name)
    {
        $p = array(
            'flag' => '<path d="M5 21V4"/><path d="M5 4h11l-2 4 2 4H5"/>',
            'gauge' => '<path d="M4 18a8 8 0 1 1 16 0"/><path d="m12 18 4-6"/><path d="M12 18h.01"/>',
            'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
            'archive' => '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9"/><path d="M10 13h4"/>',
            'server' => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
            'filter' => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
            'puzzle' => '<path d="M10 4a2 2 0 1 1 4 0v2h4a1 1 0 0 1 1 1v4h-2a2 2 0 1 0 0 4h2v4a1 1 0 0 1-1 1h-4v-2a2 2 0 1 0-4 0v2H6a1 1 0 0 1-1-1v-4h2a2 2 0 1 0 0-4H5V7a1 1 0 0 1 1-1h4z"/>',
            'up' => '<path d="m6 15 6-6 6 6"/>',
            'down' => '<path d="m6 9 6 6 6-6"/>',
            'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'reset' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        );
        return isset($p[$name]) ? '<svg class="dw-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$name] . '</svg>' : '';
    }

    /**
     * A widget's icon and accent colour (uploaded widgets get the puzzle piece).
     *
     * @param string $id The widget ID.
     * @return array{0: string, 1: string} Icon name and CSS colour.
     */
    static function Look($id)
    {
        $look = array('getting_started' => array('flag', '#2db87d'), 'usage' => array('gauge', '#447fb3'), 'domains' => array('globe', '#1fa2b5'),
            'security' => array('shield', '#8e6bd8'), 'backups' => array('archive', '#f5a623'), 'server' => array('server', '#ff6854'), 'spamav' => array('filter', '#5b6cc4'));
        return isset($look[$id]) ? $look[$id] : array('puzzle', '#9aa5b1');
    }

    /**
     * Template HTML: the account's widgets, each in a card with move up / down and remove.
     *
     * @return string HTML.
     */
    static function getWidgets()
    {
        $me = self::Me();
        $all = dashboard_widgets::All();
        $layout = self::Layout();
        $form = function ($action, $id, $label, $icon, $extra = '', $cls = '') {
            return '<form method="post" action="./?module=dashboard&amp;action=' . $action . '">'
                . '<input type="hidden" name="inWidget" value="' . dashboard_widgets::h($id) . '">' . $extra . runtime_csfr::Token()
                . '<button class="dw-tool' . $cls . '" type="submit" title="' . dashboard_widgets::h($label) . '" aria-label="' . dashboard_widgets::h($label) . '">' . self::Icon($icon) . '</button></form>';
        };
        if (!$layout) {
            return '<p class="dw-empty">' . dashboard_widgets::h(ui_language::translate('Your dashboard is empty: add a widget below.')) . '</p>';
        }
        runtime_csfr::Token();   // makes the session's token when it has none yet
        $out = dashboard_widgets::Styles($layout) . '<div class="dw-grid" data-save="./?module=dashboard&amp;action=SaveOrder" data-token="' . dashboard_widgets::h((string) ($_SESSION['zpcsfr'] ?? '')) . '"'
            . ' data-saved="' . dashboard_widgets::h(ui_language::translate('Order saved')) . '" data-failed="' . dashboard_widgets::h(ui_language::translate('The order could not be saved; reload the page.')) . '">';
        $n = count($layout);
        foreach ($layout as $i => $id) {
            $look = self::Look($id);
            $tools = ($i > 0 ? $form('MoveWidget', $id, ui_language::translate('Move up'), 'up', '<input type="hidden" name="inDir" value="up">') : '')
                . ($i < $n - 1 ? $form('MoveWidget', $id, ui_language::translate('Move down'), 'down', '<input type="hidden" name="inDir" value="down">') : '')
                . $form('RemoveWidget', $id, ui_language::translate('Remove from my dashboard'), 'x', '', ' dw-remove');
            $out .= '<section class="dw-card" id="w-' . dashboard_widgets::h($id) . '" data-id="' . dashboard_widgets::h($id) . '" style="--c:' . $look[1] . '"><header class="dw-handle" title="' . dashboard_widgets::h(ui_language::translate('Drag to move')) . '">'
                . '<span class="dw-grip" aria-hidden="true"></span><h4>' . self::Icon($look[0]) . dashboard_widgets::h(ui_language::translate($all[$id][0])) . '</h4>'
                . '<span class="dw-tools">' . $tools . '</span></header>'
                . '<div class="dw-body">' . dashboard_widgets::Render($id, $me) . '</div></section>';
        }
        return $out . '</div>';
    }

    /**
     * Template HTML: the widgets the account can still add, each with an Add button, and the reset button.
     *
     * @return string HTML.
     */
    static function getWidgetPicker()
    {
        $me = self::Me();
        $layout = self::Layout();
        $cards = '';
        foreach (dashboard_widgets::Available($me) as $id => $w) {
            if (!in_array($id, $layout, true)) {
                $look = self::Look($id);
                $cards .= '<form class="dw-add" method="post" action="./?module=dashboard&amp;action=AddWidget" style="--c:' . $look[1] . '">'
                    . '<input type="hidden" name="inWidget" value="' . dashboard_widgets::h($id) . '">' . runtime_csfr::Token() . self::Icon($look[0])
                    . '<div><b>' . dashboard_widgets::h(ui_language::translate($w[0])) . '</b><span>' . dashboard_widgets::h(ui_language::translate($w[1])) . '</span></div>'
                    . '<button class="btn btn-default btn-sm dw-btn" type="submit">' . self::Icon('plus') . '<span>' . dashboard_widgets::h(ui_language::translate('Add')) . '</span></button></form>';
            }
        }
        return '<div class="dw-picker-head"><h4>' . dashboard_widgets::h(ui_language::translate('Add widgets')) . '</h4>'
            . '<form method="post" action="./?module=dashboard&amp;action=ResetWidgets">' . runtime_csfr::Token()
            . '<button class="btn btn-default btn-sm dw-btn" type="submit">' . self::Icon('reset') . '<span>' . dashboard_widgets::h(ui_language::translate('Back to the default widgets')) . '</span></button></form></div>'
            . ($cards === '' ? '<p class="dw-all-on">' . dashboard_widgets::h(ui_language::translate('Every widget is on your dashboard.')) . '</p>' : '<div class="dw-addable">' . $cards . '</div>');
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
