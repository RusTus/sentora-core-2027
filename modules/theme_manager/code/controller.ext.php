<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 *
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
/**
 * Theme Manager module: controller.
 *
 * Lets a reseller choose the panel theme and its variation (CSS) for itself and all
 * of its clients.
 */
class module_controller extends ctrl_module
{

    /**
     * Set the theme for an account and all of its clients (start of the 'worker' methods).
     *
     * Also resets the variation to the new theme's default.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @param string $theme The theme (folder) name, already checked.
     * @return true Always true.
     */
    static function ExecuteUpdateTheme($uid, $theme)
    {
        global $zdbh;

        /* Set CSS back to the new theme's first variation (not every theme has default.css) */
        self::ExecuteUpdateCSS($uid, ui_template::DefaultCSS(ctrl_users::SafeTheme($theme)));

        /* Set new theme */
        $sql = $zdbh->prepare("
            UPDATE x_accounts
            SET ac_usertheme_vc = :theme
            WHERE ac_reseller_fk = :uid
            OR ac_id_pk = :uid2");
        $sql->bindParam(':theme', $theme);
        $sql->bindParam(':uid', $uid);
        $sql->bindParam(':uid2', $uid);
        $sql->execute();
        return true;
    }

    /**
     * Set the theme variation (CSS) for an account and all of its clients.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @param string $css The variation name ('' for none).
     * @return true Always true.
     */
    static function ExecuteUpdateCSS($uid, $css)
    {
        global $zdbh;
        $sql = $zdbh->prepare("
            UPDATE x_accounts
            SET ac_usercss_vc = :css
            WHERE ac_reseller_fk = :uid
            OR ac_id_pk = :uid2");
        $sql->bindParam(':css', $css);
        $sql->bindParam(':uid', $uid);
        $sql->bindParam(':uid2', $uid);
        $sql->execute();
        return true;
    }

    /**
     * Get the theme of the logged-in user.
     *
     * @param int|string $uid Not used (the current user's template is returned).
     * @return string The theme name.
     */
    static function ExecuteShowCurrentTheme($uid)
    {
        return ui_template::GetUserTemplate();
    }

    /**
     * Get the theme variation of an account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return string|false The variation name, or false when the account does not exist.
     */
    static function ExecuteShowCurrentCSS($uid)
    {
        global $zdbh;
        //$result = $zdbh->query("SELECT ac_usercss_vc FROM x_accounts WHERE ac_id_pk = " . $uid . "")->Fetch();
        $numrows = $zdbh->prepare("SELECT ac_usercss_vc FROM x_accounts WHERE ac_id_pk = :uid");
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        $result = $numrows->fetch();
        if ($result) {
            return $result['ac_usercss_vc'];
        } else {
            return false;
        }
    }

    /**
     * List the installed themes.
     *
     * @return array<int, array<string, string>> One entry per theme: 'name'.
     */
    static function ExecuteStylesList()
    {
        return ui_template::ListAvaliableTemplates();
    }

    /**
     * List the variations of a theme.
     *
     * @param string|null $theme Optional. The theme; null for the logged-in user's. Default null.
     * @return array<int, array<string, string>> One entry per variation: 'name'.
     */
    static function ExecuteCSSList($theme = null)
    {
        // (the theme passed in used to be ignored)
        return ui_template::ListAvaliableCSS($theme !== null ? ctrl_users::SafeTheme($theme) : self::ExecuteShowCurrentTheme(0));
    }

    // End 'worker' methods.

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
     * The logged-in user's theme (start of the web interface methods).
     *
     * @return string The theme name.
     */
    static function getCurrentTheme()
    {
        return self::ExecuteShowCurrentTheme(0);
    }

    /**
     * The logged-in user's theme variation (the default it sets for its clients).
     *
     * @return string|false The variation name, or false.
     */
    static function getCurrentCSS()
    {
        return self::ExecuteShowCurrentCSS(ctrl_users::GetUserDetail()['userid']);
    }

    /**
     * What a variation is, in words, for the known ones (Modern's Light, Dark and Auto).
     *
     * @param string $name The variation name.
     * @return string The English text ('' when unknown).
     */
    static function CSSHelp($name)
    {
        $help = array('light' => 'Light backgrounds, dark text.', 'dark' => 'Dark backgrounds, light text: easier on the eyes at night.',
            'auto' => 'Follows each computer\'s own light or dark setting.', 'default' => 'The theme\'s own look.');
        return isset($help[strtolower($name)]) ? $help[strtolower($name)] : '';
    }

    /**
     * The theme choices (<@ ThemeChoices @>): a card per installed theme.
     *
     * @return string The HTML.
     */
    static function getThemeChoices()
    {
        $cur = self::getCurrentTheme();
        $out = '';
        foreach (self::ExecuteStylesList() as $t) {
            $n = count(self::ExecuteCSSList($t['name']));
            $out .= '<label class="tm-theme"><input type="radio" name="inTheme" value="' . self::h($t['name']) . '"' . ($t['name'] === $cur ? ' checked' : '') . '>'
                . '<span class="tm-theme-art" aria-hidden="true"><i></i><i></i><i></i></span><b>' . self::h($t['name']) . '</b>'
                . '<small>' . ($n > 1 ? $n . ' ' . self::t('variations') : self::t('one look')) . '</small></label>';
        }
        return $out;
    }

    /**
     * The variation choices of every theme (<@ CSSChoices @>), each theme's in its own group (the script shows the chosen theme's).
     *
     * @return string The HTML.
     */
    static function getCSSChoices()
    {
        $curTheme = self::getCurrentTheme();
        $curCss = (string) self::getCurrentCSS();
        $out = '';
        foreach (self::ExecuteStylesList() as $t) {
            $list = self::ExecuteCSSList($t['name']);
            $mine = $t['name'] === $curTheme;
            $sel = $mine && in_array($curCss, array_column($list, 'name'), true) ? $curCss : ui_template::DefaultCSS($t['name']);
            $cards = '';
            foreach ($list as $c) {
                $help = self::CSSHelp($c['name']);
                $cards .= '<label class="tm-var tm-v-' . self::h(strtolower($c['name'])) . '"><input type="radio" name="inCSS_' . self::h($t['name']) . '" value="' . self::h($c['name']) . '"' . ($c['name'] === $sel ? ' checked' : '') . '>'
                    . '<span class="tm-swatch" aria-hidden="true"><i></i><i></i><i></i></span><b>' . self::h(ui_language::translate(ui_template::CSSLabel($c['name']))) . '</b>'
                    . ($help !== '' ? '<small>' . self::t($help) . '</small>' : '') . '</label>';
            }
            $out .= '<div class="tm-vars" data-theme="' . self::h($t['name']) . '"' . ($mine ? '' : ' hidden') . '>'
                . ($cards !== '' ? $cards : '<p class="tm-sub">' . self::t('This theme has one look.') . '</p>') . '</div>';
        }
        return $out;
    }

    /**
     * Who the choice reaches (<@ Reach @>): the account and its clients, and how many of them chose their own look in My Account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML.
     */
    static function getReach()
    {
        global $zdbh;
        $me = (int) ctrl_users::GetUserDetail()['userid'];
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_reseller_fk = :u AND ac_id_pk <> :u2 AND ac_deleted_ts IS NULL");
        $q->execute(array(':u' => $me, ':u2' => $me));
        $n = (int) $q->fetchColumn();
        $own = 0;
        if ((int) $zdbh->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'x_user_styles'")->fetchColumn() > 0) {
            $q = $zdbh->prepare("SELECT COUNT(*) FROM x_user_styles s JOIN x_accounts a ON a.ac_id_pk = s.us_acc_fk WHERE a.ac_reseller_fk = :u AND a.ac_id_pk <> :u2 AND a.ac_deleted_ts IS NULL");
            $q->execute(array(':u' => $me, ':u2' => $me));
            $own = (int) $q->fetchColumn();
        }
        $mine = ui_template::GetStylePref($me);
        return '<p class="tm-reach"><b>' . self::t('You and your') . ' ' . $n . ' ' . self::t($n === 1 ? 'client' : 'clients') . '</b> '
            . self::t('get this look. Each person can still pick Light, Dark or Auto for themselves in My Account.') . '</p>'
            . ($own ? '<p class="tm-sub">' . $own . ' ' . self::t($own === 1 ? 'client has chosen its own appearance there; it keeps it.' : 'clients have chosen their own appearance there; they keep it.') . '</p>' : '')
            . ($mine !== '' ? '<p class="tm-sub">' . self::t('You chose') . ' <b>' . self::h(ui_language::translate(ui_template::CSSLabel($mine))) . '</b> ' . self::t('for yourself in My Account, so your own panel keeps that.') . '</p>' : '');
    }

    /**
     * Handle the form: save the theme and its variation for the account and its clients, then back with saved=true.
     *
     * @global runtime_controller $controller The request controller.
     * @return void Always ends with exit.
     */
    static function doSaveTheme()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only one of the installed themes (the name is used as a folder to include
        // templates from, so "../" could run files from anywhere)
        $theme = isset($formvars['inTheme']) ? (string) $formvars['inTheme'] : '';
        if ($theme === '' || ctrl_users::SafeTheme($theme) !== $theme) {
            header("location: ./?module=" . $controller->GetCurrentModule());
            exit;
        }
        $list = array_column(self::ExecuteCSSList($theme), 'name');
        $css = isset($formvars['inCSS_' . $theme]) ? (string) $formvars['inCSS_' . $theme] : (isset($formvars['inCSS']) ? (string) $formvars['inCSS'] : '');
        if (!in_array($css, $list, true)) {
            $css = $list ? ui_template::DefaultCSS($theme) : '';
        }
        self::ExecuteUpdateTheme($currentuser['userid'], $theme);
        self::ExecuteUpdateCSS($currentuser['userid'], $css);
        header("location: ./?module=" . $controller->GetCurrentModule() . "&saved=true");
        exit;
    }

    /**
     * The old second form (a variation alone): save it for the account and its clients.
     *
     * @global runtime_controller $controller The request controller.
     * @return void Always ends with exit.
     */
    static function doSaveCSS()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $css = (string) $controller->GetControllerRequest('FORM', 'inCSS');
        if (in_array($css, array_column(self::ExecuteCSSList(), 'name'), true)) {
            self::ExecuteUpdateCSS($currentuser['userid'], $css);
        }
        header("location: ./?module=" . $controller->GetCurrentModule() . "&saved=true");
        exit;
    }

    /**
     * Template value <@ Result @>: the message after a save.
     *
     * @global runtime_controller $controller The request controller.
     * @return string|false The message HTML, or false when there is none.
     */
    static function getResult()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'saved')) {
            return ui_sysmessage::shout(ui_language::translate('Saved: your clients see the new look on their next page.'), 'zannounceok');
        }
        return false;
    }

    // Webinterface sudo methods.
}
