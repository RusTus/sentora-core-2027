<?php

/**
 *
 * Sentora - A Cross-Platform Open-Source Web Hosting Control panel.
 *
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (Sentora) is free software: you can redistribute it and/or modify
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
 * Sentora Config module (Admin): the panel's general settings and the daemon run times.
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True after the settings were saved or a daemon run was forced (shown by getResult()). */
    static $ok;

    /**
     * List the module's user-editable settings for the settings form (template <% loop Config %>).
     *
     * The SMTP password gets a password field.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, string>>|false One row per setting: 'cleanname', 'name', 'description', 'value', 'fieldhtml'; false when there are none.
     */
    static function getConfig()
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail();
        $sql = "SELECT * FROM x_settings WHERE so_module_vc=:module AND so_usereditable_en = 'true' ORDER BY so_cleanname_vc";
        $module = ui_module::GetModuleName();
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':module', $module);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':module', $module);
            $res = array();
            $sql->execute();
            while ($rowsettings = $sql->fetch()) {
                if (ctrl_options::CheckForPredefinedOptions($rowsettings['so_defvalues_tx'])) {
                    $fieldhtml = ctrl_options::OuputSettingMenuField($rowsettings['so_name_vc'], $rowsettings['so_defvalues_tx'], $rowsettings['so_value_tx']);
                } else {
                    $fieldhtml = ctrl_options::OutputSettingTextArea($rowsettings['so_name_vc'], $rowsettings['so_value_tx']);
                }
                if (strpos(ctrl_options::OutputSettingTextArea($rowsettings['so_name_vc']),'smtp_password') !== false) {
                    $fieldhtml = '<input type="password" name="smtp_password" value="'.$rowsettings['so_value_tx'].'">';
                }
                array_push($res, array('cleanname' => ui_language::translate($rowsettings['so_cleanname_vc']),
                    'name' => $rowsettings['so_name_vc'],
                    'description' => ui_language::translate($rowsettings['so_desc_tx']),
                    'value' => $rowsettings['so_value_tx'],
                    'fieldhtml' => $fieldhtml));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * When the daemon last ran, formatted with the sentora_df setting (template <@ LastRunTime @>).
     *
     * @return string|false The date, or false when it has not run (or a full run was forced).
     */
    static function getLastRunTime()
    {
        $time = ctrl_options::GetSystemOption('daemon_lastrun');
        if ($time != '0') {
            return date(ctrl_options::GetSystemOption('sentora_df'), $time);
        } else {
            return false;
        }
    }

    /**
     * When the daemon runs next: the last run plus daemon_run_interval, else the next 5-minute mark (template <@ NextRunTime @>).
     *
     * @return string The formatted date.
     */
    static function getNextRunTime()
    {
        if (ctrl_options::GetSystemOption('daemon_lastrun') > 0) {
            $new_time = ctrl_options::GetSystemOption('daemon_lastrun') + ctrl_options::GetSystemOption('daemon_run_interval');
            return date(ctrl_options::GetSystemOption('sentora_df'), $new_time);
        } else {
            // The default cron is set to run every 5 minutes on the 5 minute mark!
            return date(ctrl_options::GetSystemOption('sentora_df'), ceil(time() / 300) * 300);
        }
    }

    /**
     * When the daily daemon jobs last ran (template <@ LastDayRunTime @>).
     *
     * @return string|false The formatted date, or false when they have not run.
     */
    static function getLastDayRunTime()
    {
        $time = ctrl_options::GetSystemOption('daemon_dayrun');
        if ($time != '0') {
            return date(ctrl_options::GetSystemOption('sentora_df'), $time);
        } else {
            return false;
        }
    }

    /**
     * When the weekly daemon jobs last ran (template <@ LastWeekRunTime @>).
     *
     * @return string|false The formatted date, or false when they have not run.
     */
    static function getLastWeekRunTime()
    {
        $time = ctrl_options::GetSystemOption('daemon_weekrun');
        if ($time != '0') {
            return date(ctrl_options::GetSystemOption('sentora_df'), $time);
        } else {
            return false;
        }
    }

    /**
     * When the monthly daemon jobs last ran (template <@ LastMonthRunTime @>).
     *
     * @return string|false The formatted date, or false when they have not run.
     */
    static function getLastMonthRunTime()
    {
        $time = ctrl_options::GetSystemOption('daemon_monthrun');
        if ($time != '0') {
            return date(ctrl_options::GetSystemOption('sentora_df'), $time);
        } else {
            return false;
        }
    }

    /**
     * Save the posted values of the module's settings (empty values are skipped) and have the vhosts rewritten.
     *
     * Checks the CSRF token and sets self::$ok.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doUpdateConfig()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $sql = "SELECT * FROM x_settings WHERE so_module_vc=:module AND so_usereditable_en = 'true'";
        $module = ui_module::GetModuleName();
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':module', $module);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':module', $module);
            $sql->execute();
            while ($row = $sql->fetch()) {
                if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', $row['so_name_vc']))) {
                    $updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :so_name_vc");
                    $value = $controller->GetControllerRequest('FORM', $row['so_name_vc']);
                    $updatesql->bindParam(':value', $value);
                    $updatesql->bindParam(':so_name_vc', $row['so_name_vc']);
                    $updatesql->execute();
                }
            }
            self::SetWriteApacheConfigTrue(); //sentora apache port changed require rewrite of vhosts
        }
        self::$ok = true;
    }

    /**
     * Set apache_changed so the daemon rewrites the Apache vhosts on its next run.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function SetWriteApacheConfigTrue()
    {
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx='true' WHERE so_name_vc='apache_changed'");
        $sql->execute();
    }

    /**
     * Handle "force full daemon run": reset the last run times so every daily, weekly and monthly job runs next time.
     *
     * Checks the CSRF token and sets self::$ok.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doForceDaemon()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inForceFull'])) {
            $sql = $zdbh->prepare("UPDATE x_settings set so_value_tx = '0' WHERE so_name_vc = 'daemon_lastrun'");
            $sql->execute();
            $sql = $zdbh->prepare("UPDATE x_settings set so_value_tx = '0' WHERE so_name_vc = 'daemon_dayrun'");
            $sql->execute();
            $sql = $zdbh->prepare("UPDATE x_settings set so_value_tx = '0' WHERE so_name_vc = 'daemon_weekrun'");
            $sql->execute();
            $sql = $zdbh->prepare("UPDATE x_settings set so_value_tx = '0' WHERE so_name_vc = 'daemon_monthrun'");
            $sql->execute();
        }
        self::$ok = true;
    }

    /**
     * The message box for the last action (template <@ Result @>).
     *
     * @return string|null The HTML, or null when there is nothing to show.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your settings have been saved successfully!"));
        }
        return;
    }

}