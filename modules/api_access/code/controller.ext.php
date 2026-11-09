<?php

/**
 * API Access: an account that can have clients makes keys for the programs that use the panel's
 * API (ctrl_apikeys), sees them, switches them off or deletes them, and reads what they did (the
 * API log). An administrator also sees everyone's keys and the whole log, and can replace the
 * server's own key.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class module_controller extends ctrl_module
{
    /** @var array<int, array{0:string, 1:string}> Messages: [type ('ok'|'error'), text]. */
    static $msg = array();

    /** @var string A key just made (shown this once). */
    static $newKey = '';

    /** @var string What it is for. */
    static $newLabel = '';

    /**
     * Escape for HTML.
     *
     * @param mixed $s
     * @return string
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translate and escape.
     *
     * @param string $s English.
     * @return string
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * The logged-in account.
     *
     * @return int
     */
    static function Uid()
    {
        return (int) ctrl_users::GetUserDetail()['userid'];
    }

    /**
     * An administrator?
     *
     * @return bool
     */
    static function Admin()
    {
        return (int) ctrl_users::GetUserDetail()['usergroupid'] === 1;
    }

    /**
     * Make a key.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doCreateKey()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $r = ctrl_apikeys::Create(self::Uid(), (string) ($f['inLabel'] ?? ''), (string) ($f['inIps'] ?? ''));
        if (isset($r['key'])) {
            self::$newKey = $r['key'];
            self::$newLabel = trim((string) ($f['inLabel'] ?? ''));
            return;
        }
        $text = array(
            'label' => 'Name the key (what uses it): up to 60 characters, without < or > or invisible characters.',
            'ips' => 'The addresses must be IP addresses or ranges (such as 203.0.113.0/24), at most 50.',
            'full' => 'You have 20 keys already: delete one first.',
            'account' => 'API keys are for accounts that can have clients.',
        );
        self::$msg[] = array('error', ui_language::translate($text[$r['error']]));
    }

    /**
     * Switch a key off or on, or delete it.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doChangeKey()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $what = (string) ($f['inWhat'] ?? '');
        if (ctrl_apikeys::Change(self::Uid(), (int) ($f['inKey'] ?? 0), $what)) {
            $done = array('off' => 'The key is switched off: requests with it are refused.', 'on' => 'The key is switched on again.', 'delete' => 'The key is deleted.');
            self::$msg[] = array('ok', ui_language::translate($done[$what]));
        }
    }

    /**
     * Replace the server's own key (administrators): the old one stops working at once.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doReplaceServerKey()
    {
        global $zdbh;
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $new = bin2hex(random_bytes(24));
        $zdbh->prepare("UPDATE x_settings SET so_value_tx = :k WHERE so_name_vc = 'apikey'")->execute(array(':k' => $new));
        self::$newKey = $new;
        self::$newLabel = ui_language::translate('The server\'s own key');
    }

    /** <% if CanUse %>. @return bool */
    static function getCanUse()
    {
        return ctrl_support::CanSet(self::Uid());
    }

    /** <% if CanNotUse %>. @return bool */
    static function getCanNotUse()
    {
        return !self::getCanUse();
    }

    /**
     * <@ Result @>.
     *
     * @return string HTML.
     */
    static function getResult()
    {
        $out = '';
        foreach (self::$msg as $m) {
            $out .= '<div class="ak-msg ' . ($m[0] === 'ok' ? 'is-ok' : 'is-bad') . '" role="' . ($m[0] === 'ok' ? 'status' : 'alert') . '">' . self::h($m[1]) . '</div>';
        }
        if (self::$newKey !== '') {
            $out .= '<div class="ak-card ak-new"><h4>' . self::t('Your new key') . ': ' . self::h(self::$newLabel) . '</h4>'
                . '<p>' . self::t('Copy it now and keep it somewhere safe: it is shown only this once (the panel keeps only a fingerprint of it). Anyone who has it can act as you through the API.') . '</p>'
                . '<div class="ak-keybox"><code id="akNewKey">' . self::h(self::$newKey) . '</code>'
                . '<button class="btn btn-default btn-sm" type="button" onclick="navigator.clipboard &amp;&amp; navigator.clipboard.writeText(document.getElementById(\'akNewKey\').textContent);this.textContent=this.dataset.done" data-done="' . self::t('Copied') . '">' . self::t('Copy') . '</button></div></div>';
        }
        return $out;
    }

    /**
     * <@ Intro @>: what a key may do.
     *
     * @return string HTML.
     */
    static function getIntro()
    {
        $panel = (string) ctrl_options::GetSystemOption('sentora_domain');
        $text = self::Admin()
            ? self::t('A key made here acts as you, an administrator: it may do everything the API offers, except deleting, switching off or setting the password of an administrator, or making one. Make one key per program, so each can be switched off on its own and the log shows which one did what.')
            : self::t('A key made here acts as you: it reaches only your own clients (and theirs), your own packages and groups, and only the modules your account has. It can never touch your own account, an administrator, or the server\'s settings. Make one key per program, so each can be switched off on its own and the log shows which one did what.');
        return '<p class="ak-intro">' . $text . ' ' . str_replace('%s', '<code>https://' . self::h($panel) . '/api/&lt;module&gt;</code>', self::t('Programs send their requests to %s (XML or JSON); the README has the list of requests.')) . '</p>';
    }

    /**
     * <@ NewKey @>: the form to make a key.
     *
     * @return string HTML.
     */
    static function getNewKey()
    {
        return '<form class="ak-card" action="./?module=api_access&amp;action=CreateKey" method="post"><h4>' . self::t('Make a key') . '</h4>'
            . '<div class="ak-fields"><label class="ak-field"><span>' . self::t('What uses it') . '</span><input class="form-control" type="text" name="inLabel" maxlength="60" placeholder="' . self::t('For example: billing system') . '" required></label>'
            . '<label class="ak-field"><span>' . self::t('Only from these addresses (optional)') . '</span><input class="form-control" type="text" name="inIps" placeholder="203.0.113.10, 198.51.100.0/24">'
            . '<small>' . self::t('The addresses the program connects from: requests from any other address are refused. Leave empty for any address.') . '</small></label></div>'
            . '<div class="ak-foot">' . runtime_csfr::Token() . '<button class="btn btn-primary" type="submit">' . self::t('Make the key') . '</button></div></form>';
    }

    /**
     * <@ KeysCard @>: the account's keys (an administrator: everyone's).
     *
     * @return string HTML.
     */
    static function getKeysCard()
    {
        $admin = self::Admin();
        $keys = ctrl_apikeys::Keys(self::Uid(), $admin);
        $tok = runtime_csfr::Token();
        $rows = '';
        foreach ($keys as $k) {
            $btn = function ($what, $label, $cls, $ask = '') use ($k, $tok) {
                return '<form action="./?module=api_access&amp;action=ChangeKey" method="post"' . ($ask !== '' ? ' onsubmit="return confirm(this.dataset.ask)" data-ask="' . self::t($ask) . '"' : '') . '>'
                    . '<input type="hidden" name="inKey" value="' . (int) $k['id'] . '"><input type="hidden" name="inWhat" value="' . $what . '">' . $tok
                    . '<button class="btn btn-sm ' . $cls . '" type="submit">' . self::t($label) . '</button></form>';
            };
            $used = $k['used'] ? ui_language::Date('j M Y, H:i', $k['used']) . ' · ' . $k['usedip'] : ui_language::translate('never');
            $rows .= '<tr><td><b>' . self::h($k['label']) . '</b><br><code class="ak-start">' . self::h($k['start']) . '…</code></td>'
                . ($admin ? '<td>' . self::h($k['user']) . '</td>' : '')
                . '<td>' . ($k['ips'] !== '' ? nl2br(self::h($k['ips'])) : '<span class="ak-sub">' . self::t('any address') . '</span>') . '</td>'
                . '<td><span class="ak-pill ' . ($k['enabled'] ? 'is-ok' : '') . '">' . self::t($k['enabled'] ? 'On' : 'Off') . '</span></td>'
                . '<td class="ak-sub">' . self::h($used) . '</td>'
                . '<td><div class="ak-act">' . ($k['enabled'] ? $btn('off', 'Switch off', 'btn-default') : $btn('on', 'Switch on', 'btn-default'))
                . $btn('delete', 'Delete', 'btn-link ak-del', 'Delete this key? Programs using it stop working at once.') . '</div></td></tr>';
        }
        $body = $rows === '' ? '<p class="ak-sub">' . self::t('No keys yet.') . '</p>'
            : '<div class="ak-scroll"><table class="ak-table"><thead><tr><th>' . self::t('Key') . '</th>' . ($admin ? '<th>' . self::t('Account') . '</th>' : '')
            . '<th>' . self::t('Addresses') . '</th><th>' . self::t('State') . '</th><th>' . self::t('Last used') . '</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        return '<div class="ak-card"><h4>' . self::t($admin ? 'Keys (every account\'s)' : 'Your keys') . '</h4>' . $body . '</div>';
    }

    /**
     * <@ ServerKeyCard @>: the server's own key (administrators).
     *
     * @return string HTML.
     */
    static function getServerKeyCard()
    {
        if (!self::Admin()) {
            return '';
        }
        return '<form class="ak-card" action="./?module=api_access&amp;action=ReplaceServerKey" method="post" onsubmit="return confirm(this.dataset.ask)" data-ask="'
            . self::t('Replace the server\'s own key? Programs using the old one stop working at once.') . '"><h4>' . self::t('The server\'s own key') . '</h4>'
            . '<p class="ak-sub">' . self::t('The key the API always had (an administrator\'s, for programs set up before these keys). As root, setso --show apikey prints it. Replacing it shows the new one here once; a key of its own per program is better.') . '</p>'
            . '<div class="ak-foot">' . runtime_csfr::Token() . '<button class="btn btn-default" type="submit">' . self::t('Replace the server\'s key') . '</button></div></form>';
    }

    /**
     * <@ LogCard @>: the latest requests (an administrator: all, also those refused).
     *
     * @return string HTML.
     */
    static function getLogCard()
    {
        $admin = self::Admin();
        $codes = array('1101' => 'done', '1102' => 'no such request', '1103' => 'wrong key', '1104' => 'locked out', '1106' => 'no request', '1107' => 'refused value', '1108' => 'not allowed');
        $rows = '';
        foreach (ctrl_apikeys::Logs(self::Uid(), $admin, 100) as $l) {
            $ok = $l['code'] === '1101';
            $rows .= '<tr><td class="ak-sub">' . self::h(ui_language::Date('j M, H:i:s', $l['time'])) . '</td>'
                . '<td>' . ($l['key'] !== '' ? self::h($l['key']) : '<span class="ak-sub">' . self::t('none') . '</span>') . ($admin && $l['user'] !== '' ? '<br><span class="ak-sub">' . self::h($l['user']) . '</span>' : '') . '</td>'
                . '<td>' . self::h($l['ip']) . '</td><td><code>' . self::h($l['module']) . '</code> ' . self::h($l['request']) . '</td>'
                . '<td><span class="ak-pill ' . ($ok ? 'is-ok' : 'is-bad') . '">' . self::h($l['code']) . ' ' . self::t(isset($codes[$l['code']]) ? $codes[$l['code']] : '') . '</span></td>'
                . '<td class="ak-sub">' . self::h($l['note']) . '</td></tr>';
        }
        $body = $rows === '' ? '<p class="ak-sub">' . self::t('No requests yet.') . '</p>'
            : '<div class="ak-scroll"><table class="ak-table"><thead><tr><th>' . self::t('When') . '</th><th>' . self::t('Key') . '</th><th>' . self::t('From') . '</th><th>'
            . self::t('Request') . '</th><th>' . self::t('Answer') . '</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        return '<div class="ak-card"><h4>' . self::t('Latest requests') . '</h4><p class="ak-sub">'
            . self::t($admin ? 'The last 100, with every key (also refused ones), kept 90 days.' : 'The last 100 made with your keys, kept 90 days.') . '</p>' . $body . '</div>';
    }

    /**
     * Nothing to set up before the page.
     *
     * @return void
     */
    static function getInit()
    {
    }
}
