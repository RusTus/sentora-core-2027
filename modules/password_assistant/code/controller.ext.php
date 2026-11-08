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
 * Updated by RusTus, 2026-11-06
 */

/**
 * Password Assistant module: lets users change their own panel password.
 */
class module_controller extends ctrl_module
{

    /** @var string|null Result of the last change: 'ok', 'nomatch' or 'error' (shown by getResult()). */
    static $error;
    /** @var bool|null True when the new password was too short (shown by getResult()). */
    static $badpassword;

    /**
     * Change the current user's password from the posted form.
     *
     * Checks the current password and that the new one matches its confirmation and
     * has the minimum length; sets $error or $badpassword for getResult().
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return false|null False when the new password is too short, otherwise nothing.
     */
    static function doUpdatePassword()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $current_pass = $controller->GetControllerRequest('FORM', 'inCurPass');
        $newpass = $controller->GetControllerRequest('FORM', 'inNewPass');
        $conpass = $controller->GetControllerRequest('FORM', 'inConPass');

        $crypto = new runtime_hash;
        $crypto->SetPassword($newpass);
        $randomsalt = $crypto->RandomSalt();
        $crypto->SetSalt($randomsalt);
        $new_secure_password = $crypto->CryptParts($crypto->Crypt())->Hash;


        $sql = $zdbh->prepare("SELECT ac_pass_vc, ac_passsalt_vc FROM x_accounts WHERE ac_id_pk= :uid");
        $sql->bindParam(':uid', $currentuser['userid']);
        $sql->execute();
        $result = $sql->fetch();
        $userpasshash = new runtime_hash;
        $userpasshash->SetPassword($current_pass);
        $userpasshash->SetSalt($result['ac_passsalt_vc']);
        $current_secure_password = $userpasshash->CryptParts($userpasshash->Crypt())->Hash;

        if (fs_director::CheckForEmptyValue($current_pass) || fs_director::CheckForEmptyValue($newpass)) {
            // A password is blank
            self::$error = "error";
        } elseif (!is_string($result['ac_pass_vc'] ?? null) || !hash_equals((string) $result['ac_pass_vc'], (string) $current_secure_password)) {
            // Current password does not match (compared in constant time)
            self::$error = "nomatch";
        } else {
            if ($newpass == $conpass) {
                // Check for password length...
                if (strlen($newpass) < ctrl_options::GetSystemOption('password_minlength')) {
                    self::$badpassword = true;
                    return false;
                }
                // Check that the new password matches the confirmation box.
                $sql = $zdbh->prepare("UPDATE x_accounts SET ac_pass_vc=:new_secure_password, ac_passsalt_vc= :randomsalt WHERE ac_id_pk=:userid");
                $sql->bindParam(':randomsalt', $randomsalt);
                $sql->bindParam(':new_secure_password', $new_secure_password);
                $sql->bindParam(':userid', $currentuser['userid']);
                $sql->execute();
                self::$error = "ok";
            } else {
                self::$error = "error";
            }
        }
    }

    /**
     * Return the message for the last password change (<@ Result @>).
     *
     * @return string|null HTML message, or null when there is none.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$error)) {
            if (self::$error == "ok") {
                return ui_sysmessage::shout(ui_language::translate("Your password has been changed. Use the new one the next time you log in."), "zannounceok");
            }
            if (self::$error == "nomatch") {
                return ui_sysmessage::shout(ui_language::translate("Your current password is not right. Nothing was changed."), "zannounceerror");
            }
            if (self::$error == "error") {
                return ui_sysmessage::shout(ui_language::translate("Your password was not changed: fill in every field, and type the new password the same way twice."), "zannounceerror");
            }
        } else {
            if (!fs_director::CheckForEmptyValue(self::$badpassword)) {
                return ui_sysmessage::shout(sprintf(ui_language::translate("Your new password is too short: it needs at least %d characters."), (int) ctrl_options::GetSystemOption('password_minlength')), "zannounceerror");
            }
            return;
        }
    }

    /**
     * Template value: the shortest new password allowed (password_minlength option).
     *
     * @return int
     */
    static function getMinLength()
    {
        return max(1, (int) ctrl_options::GetSystemOption('password_minlength'));
    }

    /**
     * Template value: the logged-in account's username, HTML-escaped (the page warns when a new password contains it).
     *
     * @return string
     */
    static function getUsername()
    {
        $me = ctrl_users::GetUserDetail();
        return htmlspecialchars((string) $me['username'], ENT_QUOTES, 'UTF-8');
    }

    /**
     * Set an account's password (new random salt), without any checks.
     *
     * Used by the web service (ResetUserPassword).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @param string $password The new password in plain text.
     * @return bool Always true.
     */
    static function UpdatePassword($uid, $password)
    {
        global $zdbh;
        $crypto = new runtime_hash;
        $crypto->SetPassword($password);
        $randomsalt = $crypto->RandomSalt();
        $crypto->SetSalt($randomsalt);
        $secure_password = $crypto->CryptParts($crypto->Crypt())->Hash;
        $sql = $zdbh->prepare("UPDATE x_accounts SET ac_pass_vc=:secure_password, ac_passsalt_vc= :randomsalt WHERE ac_id_pk=:userid");
        $sql->bindParam(':randomsalt', $randomsalt);
        $sql->bindParam(':secure_password', $secure_password);
        $sql->bindParam(':userid', $uid);
        $sql->execute();
        return true;
    }

}
