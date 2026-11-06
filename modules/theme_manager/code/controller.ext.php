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
     * List the variations of the logged-in user's current theme.
     *
     * @return array<int, array<string, string>> One entry per variation: 'name'.
     */
    static function ExecuteCSSList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ui_template::ListAvaliableCSS(self::ExecuteShowCurrentTheme($currentuser['userid']));
    }

    /**
     * End 'worker' methods.
     */

    /**
     * The logged-in user's theme (start of the web interface methods).
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return string The theme name.
     */
    static function getCurrentTheme()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ExecuteShowCurrentTheme($currentuser['userid']);
    }

    /**
     * The logged-in user's theme variation.
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return string|false The variation name, or false.
     */
    static function getCurrentCSS()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ExecuteShowCurrentCSS($currentuser['userid']);
    }

    /**
     * Template value <@ SelectThemeMenu @>: the <option> list of themes.
     *
     * @return string The HTML options, the current theme selected.
     */
    static function getSelectThemeMenu()
    {
        $html = "";
        foreach (self::ExecuteStylesList() as $theme) {
            if ($theme['name'] != self::getCurrentTheme()) {
                $html .="<option value = \"" . $theme['name'] . "\">" . $theme['name'] . "</option>\n";
            } else {
                $html .="<option value = \"" . $theme['name'] . "\" selected=\"selected\">" . $theme['name'] . "</option>\n";
            }
        }
        return $html;
    }

    /**
     * Template value <@ SelectCSSMenu @>: the <option> list of variations.
     *
     * @return string The HTML options, the current variation selected.
     */
    static function getSelectCSSMenu()
    {
        $html = "";
        foreach (self::ExecuteCSSList() as $css) {
            $label = htmlspecialchars(ui_template::CSSLabel($css['name']), ENT_QUOTES);
            if ($css['name'] != self::getCurrentCSS()) {
                $html .="<option value = \"" . $css['name'] . "\">" . $label . "</option>\n";
            } else {
                $html .="<option value = \"" . $css['name'] . "\" selected=\"selected\">" . $label . "</option>\n";
            }
        }
        return $html;
    }

    /**
     * Template flag <% if IsSelectCSS %>: does the current theme have more than one variation?
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return bool
     */
    static function getIsSelectCSS()
    {
        global $controller;
        // Whenever the current theme has more than one variation (for example Modern:
        // Light and Dark), so it can be switched without choosing the theme again
        return count(self::ExecuteCSSList()) > 1;
    }

    /**
     * Handle the theme form: save the theme and redirect, then exit.
     *
     * Redirects with selectcss=true when the theme has several variations, else saved=true.
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
        if (!isset($formvars['inTheme']) || ctrl_users::SafeTheme($formvars['inTheme']) !== (string) $formvars['inTheme'] || $formvars['inTheme'] === '') {
            header("location: ./?module=" . $controller->GetCurrentModule());
            exit;
        }
        self::ExecuteUpdateTheme($currentuser['userid'], $formvars['inTheme']);
        if (count(self::ExecuteCSSList($formvars['inTheme'])) > 1) {
            header("location: ./?module=" . $controller->GetCurrentModule() . "&selectcss=true");
        } else {
            self::ExecuteUpdateCSS($currentuser['userid'], "");
            header("location: ./?module=" . $controller->GetCurrentModule() . "&saved=true");
        }
        exit;
    }

    /**
     * Handle the variation form: save it, redirect with saved=true and exit.
     *
     * @global runtime_controller $controller The request controller.
     * @return void Always ends with exit.
     */
    static function doSaveCSS()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (!isset($formvars['inCSS']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $formvars['inCSS'])) {
            header("location: ./?module=" . $controller->GetCurrentModule());
            exit;
        }
        self::ExecuteUpdateCSS($currentuser['userid'], $formvars['inCSS']);
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
        $currentuser = ctrl_users::GetUserDetail();
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (isset($urlvars['saved'])) {
            return ui_sysmessage::shout(ui_language::translate("Your theme configuration has been saved and has been updated for all clients!"), "zannounceok");
        }
        if (isset($urlvars['selectcss'])) {
            return ui_sysmessage::shout(ui_language::translate("This theme has more than one variation, please choose a variation you'd like to use.."), "zannounceerror");
        }
        return false;
    }

    /**
     * Webinterface sudo methods.
     */
}
