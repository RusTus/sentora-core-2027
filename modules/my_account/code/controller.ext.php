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
class module_controller extends ctrl_module
{

    static $ok;
    static $blank;
    static $emailerror;

    static function getAccountSettings()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $res = array();
        array_push($res, array('fullname' => runtime_xss::xssClean($currentuser['fullname']),
            'email' => runtime_xss::xssClean($currentuser['email']),
            'phone' => runtime_xss::xssClean($currentuser['phone']),
            'address' => runtime_xss::xssClean($currentuser['address']),
            'postcode' => runtime_xss::xssClean($currentuser['postcode'])));
        return $res;
    }

    static function getLangList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $res = array();
        $column_names = ui_language::GetColumnNames('x_translations');
        foreach ($column_names as $column_name) {
            if ($column_name != 'tr_id_pk') {
                $column_name = explode('_', $column_name);
                $lang = $column_name[1];
                if ($lang == $currentuser['language']) {
                    $selected = "SELECTED";
                } else {
                    $selected = "";
                }
                array_push($res, array('language' => $lang, 'selected' => $selected));
            }
        }
        return $res;
    }

    static function doUpdateAccountSettings()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $userid = $currentuser['userid'];
        $email = $controller->GetControllerRequest('FORM', 'inEmail');
        $fullname = $controller->GetControllerRequest('FORM', 'inFullname');
        $language = $controller->GetControllerRequest('FORM', 'inLanguage');
        // Only a language the panel has (it is used as part of a column name)
        if (!preg_match('/^[a-z]{2}$/', (string) $language)) {
            $language = 'en';
        }
        $phone = $controller->GetControllerRequest('FORM', 'inPhone');
        $address = $controller->GetControllerRequest('FORM', 'inAddress');
        $postalCode = $controller->GetControllerRequest('FORM', 'inPostalCode');

        if (!fs_director::CheckForEmptyValue(self::ExecuteUpdateAccountSettings($userid, $email, $fullname, $language, $phone, $address, $postalCode))) {
            runtime_hook::Execute('OnAfterUpdateMyAccount');
            self::$ok = true;
        }
    }

    static function ExecuteUpdateAccountSettings($userid, $email, $fullname, $language, $phone, $address, $postalCode)
    {
        global $zdbh;
        $email = strtolower(str_replace(' ', '', $email));
        $fullname = ucwords($fullname);
        if (fs_director::CheckForEmptyValue(self::CheckUpdateForErrors($email, $fullname, $language, $phone, $address, $postalCode))) {
            return false;
        }
        $currentuser = ctrl_users::GetUserDetail();
        $sql = $zdbh->prepare("UPDATE x_accounts SET ac_email_vc = :email WHERE ac_id_pk = :userid");
        $sql->bindParam(':email', $email);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        $sql = $zdbh->prepare("UPDATE x_profiles SET ud_fullname_vc = :fullname, ud_language_vc = :language, ud_phone_vc = :phone, ud_address_tx  = :address, ud_postcode_vc = :postcode WHERE ud_user_fk = :userid");
        $sql->bindParam(':fullname', $fullname);
        $sql->bindParam(':language', $language);
        $sql->bindParam(':phone', $phone);
        $sql->bindParam(':address', $address);
        $sql->bindParam(':postcode', $postalCode);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        return true;
    }

    static function CheckUpdateForErrors($email, $fullname, $language, $phone, $address, $postalCode)
    {
        global $zdbh;
        if (fs_director::CheckForEmptyValue($email) ||
                fs_director::CheckForEmptyValue($fullname) ||
                fs_director::CheckForEmptyValue($language) ||
                fs_director::CheckForEmptyValue($phone) ||
                fs_director::CheckForEmptyValue($address) ||
                fs_director::CheckForEmptyValue($postalCode)) {
            self::$blank = true;
            return false;
        }
        if (!self::IsValidEmail($email)) {
            self::$emailerror = true;
            return false;
        }
        return true;
    }

    static function IsValidEmail($email)
    {
        if (!preg_match('/^[a-z0-9]+([_\\.-][a-z0-9]+)*@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i', $email)) {
            return false;
        }
        return true;
    }

    static $tfmsg = null;

    /* ---------- Two-factor login (only when switched on in Sentora Config) ---------- */

    /** new recovery codes: only with a current code from the app (not just an open browser) */
    static function doTwoFactorNewCodes()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        if (ctrl_twofactor::Required($currentuser['userid']) && ctrl_twofactor::Verify($currentuser['userid'], $controller->GetControllerRequest('FORM', 'inTwoFactorCode'))) {
            $_SESSION['zp2fa_newcodes'] = ctrl_twofactor::NewRecoveryCodes($currentuser['userid']);
            self::$tfmsg = 'newcodes';
        } else {
            self::$tfmsg = 'wrong';
        }
        return true;
    }

    /** the recovery codes, once: straight after they are made */
    static function getTwoFactorCodes()
    {
        // the template engine calls this twice per page (to compile it, then to show
        // it): the codes are taken from the session once and kept for this request
        static $html = null;
        if ($html !== null) {
            return $html;
        }
        if (empty($_SESSION['zp2fa_newcodes']) || !is_array($_SESSION['zp2fa_newcodes'])) {
            return $html = '';
        }
        $codes = $_SESSION['zp2fa_newcodes'];
        unset($_SESSION['zp2fa_newcodes']);
        $items = '';
        foreach ($codes as $c) {
            $items .= '<li><code>' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . '</code></li>';
        }
        return $html = '<div class="alert alert-warning" style="max-width: 640px;"><strong>' . ui_language::translate('Your recovery codes') . '</strong><br>'
            . ui_language::translate('If you lose your phone, each of these logs you in once instead of the app. Save them now (print them, or keep them in a password manager): they are not shown again.')
            . '<ol style="columns: 2; font-size: 16px; margin: 10px 0;">' . $items . '</ol>'
            . '<button type="button" class="btn btn-default btn-sm" onclick="window.print()">' . ui_language::translate('Print') . '</button></div>';
    }

    static function getTwoFactorRecoveryLeft()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return (string) ctrl_twofactor::RecoveryLeft($currentuser['userid']);
    }

    static function getTwoFactorForced()
    {
        return ctrl_twofactor::Forced();
    }

    static function getTwoFactorAvailable()
    {
        return ctrl_twofactor::Enabled();
    }

    static function TwoFactorRow()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ctrl_twofactor::Row($currentuser['userid']);
    }

    static function getTwoFactorOn()
    {
        $row = self::TwoFactorRow();
        return $row && (int) $row['tf_enabled_in'] === 1;
    }

    static function getTwoFactorPending()
    {
        $row = self::TwoFactorRow();
        return $row && (int) $row['tf_enabled_in'] === 0;
    }

    static function getTwoFactorOff()
    {
        return !self::TwoFactorRow();
    }

    static function getTwoFactorSecret()
    {
        $row = self::TwoFactorRow();
        return ($row && (int) $row['tf_enabled_in'] === 0) ? trim(chunk_split($row['tf_secret_vc'], 4, ' ')) : '';
    }

    static function getTwoFactorUri()
    {
        $row = self::TwoFactorRow();
        $currentuser = ctrl_users::GetUserDetail();
        return ($row && (int) $row['tf_enabled_in'] === 0) ? htmlspecialchars(ctrl_twofactor::OtpauthUri($row['tf_secret_vc'], $currentuser['username']), ENT_QUOTES, 'UTF-8') : '';
    }

    static function getTwoFactorQr()
    {
        $row = self::TwoFactorRow();
        $currentuser = ctrl_users::GetUserDetail();
        return ($row && (int) $row['tf_enabled_in'] === 0) ? ctrl_twofactor::QrSvg(ctrl_twofactor::OtpauthUri($row['tf_secret_vc'], $currentuser['username'])) : '';
    }

    static function doTwoFactorBegin()
    {
        runtime_csfr::Protect();
        if (!ctrl_twofactor::Enabled()) {
            return false;
        }
        $currentuser = ctrl_users::GetUserDetail();
        ctrl_twofactor::Begin($currentuser['userid']);
        return true;
    }

    static function doTwoFactorConfirm()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        if (ctrl_twofactor::Enabled() && ctrl_twofactor::Verify($currentuser['userid'], $controller->GetControllerRequest('FORM', 'inTwoFactorCode'), false)) {
            ctrl_twofactor::Activate($currentuser['userid']);
            $_SESSION['zp2fa_newcodes'] = ctrl_twofactor::NewRecoveryCodes($currentuser['userid']);
            self::$tfmsg = 'on';
        } else {
            self::$tfmsg = 'wrong';
        }
        return true;
    }

    static function doTwoFactorDisable()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $row = ctrl_twofactor::Row($currentuser['userid']);
        if (ctrl_twofactor::Forced() && $row && (int) $row['tf_enabled_in'] === 1) {
            self::$tfmsg = 'forced';   // required on this server: it can not be turned off
            return;
        }
        // Turning it off needs a current code (a pending, unconfirmed setup can just be cancelled)
        if ($row && ((int) $row['tf_enabled_in'] === 0 || ctrl_twofactor::Verify($currentuser['userid'], $controller->GetControllerRequest('FORM', 'inTwoFactorCode')))) {
            ctrl_twofactor::Disable($currentuser['userid']);
            self::$tfmsg = 'off';
        } else {
            self::$tfmsg = 'wrong';
        }
        return true;
    }

    /** the current theme's variations, when it has more than one (Modern: Light, Dark, Auto) */
    private static function AppearanceStyles()
    {
        return ui_template::ListAvaliableCSS(ctrl_users::SafeTheme(ui_template::GetUserTemplate()));
    }

    static function getAppearanceAvailable()
    {
        return count(self::AppearanceStyles()) > 1;
    }

    static function getAppearanceList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $mine = ui_template::GetStylePref($currentuser['userid']);
        $styles = self::AppearanceStyles();
        $names = array_map(function ($s) { return $s['name']; }, $styles);
        if (!in_array($mine, $names, true)) {
            $mine = '';
        }
        // "Theme default" names the variation the reseller chose (or the theme's first)
        $default = (string) $currentuser['usercss'];
        if (!in_array($default, $names, true)) {
            $default = ui_template::DefaultCSS(ctrl_users::SafeTheme(ui_template::GetUserTemplate()));
        }
        $res = array(array('value' => '', 'label' => htmlspecialchars(ui_language::translate('Theme default') . ' (' . ui_template::CSSLabel($default) . ')', ENT_QUOTES),
            'selected' => $mine === '' ? 'selected' : ''));
        foreach ($names as $n) {
            $res[] = array('value' => htmlspecialchars($n, ENT_QUOTES), 'label' => htmlspecialchars(ui_language::translate(ui_template::CSSLabel($n)), ENT_QUOTES),
                'selected' => $mine === $n ? 'selected' : '');
        }
        return $res;
    }

    static function doSetAppearance()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $want = (string) $controller->GetControllerRequest('FORM', 'inAppearance');
        $names = array_map(function ($s) { return $s['name']; }, self::AppearanceStyles());
        if ($want !== '' && !in_array($want, $names, true)) {
            return false;
        }
        ui_template::SetStylePref($currentuser['userid'], $want);
        // a full reload, so the new stylesheet is used on this very page
        header('location: ./?module=my_account&appearance=saved');
        exit;
    }

    static function getResult()
    {
        if (isset($_GET['appearance']) && $_GET['appearance'] === 'saved') {
            return ui_sysmessage::shout(ui_language::translate('Your appearance has been saved.'), 'zannounceok');
        }
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return ui_sysmessage::shout(ui_language::translate("You must fill out all fields!"), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$emailerror)) {
            return ui_sysmessage::shout(ui_language::translate("Your email address is not valid!"), "zannounceerror");
        }
        if (self::$tfmsg === 'on') {
            return ui_sysmessage::shout(ui_language::translate("Two-factor login is now on: you will be asked for a code from your app when you log in."), "zannounceok");
        }
        if (self::$tfmsg === 'off') {
            return ui_sysmessage::shout(ui_language::translate("Two-factor login is now off for your account."), "zannounceok");
        }
        static $recovered = null;
        if ($recovered === null) {
            $recovered = !empty($_SESSION['zp2fa_recovered']);
            unset($_SESSION['zp2fa_recovered']);
        }
        if ($recovered) {
            $left = ctrl_twofactor::RecoveryLeft(ctrl_users::GetUserDetail()['userid']);
            return ui_sysmessage::shout(ui_language::translate('You logged in with a recovery code. Codes left') . ': <strong>' . (int) $left . '</strong>. '
                . ui_language::translate('If you have a new phone, set it up again below and make a new set of codes.'), 'zannounceinfo');
        }
        if (self::$tfmsg === 'newcodes') {
            return ui_sysmessage::shout(ui_language::translate('New recovery codes made: the old ones no longer work.'), 'zannounceok');
        }
        if (self::$tfmsg === 'forced') {
            return ui_sysmessage::shout(ui_language::translate('Two-factor login is required on this server and can not be turned off.'), 'zannounceerror');
        }
        if (self::$tfmsg === 'wrong') {
            return ui_sysmessage::shout(ui_language::translate("That code is not right. Check the time on your phone and try the next code."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your account settings have been saved successfully!"), "zannounceok");
        }
        return;
    }

}
