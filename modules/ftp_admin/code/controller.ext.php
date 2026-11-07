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
 * FTP Admin module (administrators): how the FTP server is doing, every FTP account on the
 * server with its owner, how clients connect, and the module's settings (x_settings).
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True after doUpdateConfig() saved the settings (shown by getResult()). */
    static $ok;

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
     * Whether the FTP server answers on port 21 (here, at most one second).
     *
     * @return bool
     */
    static function ServerUp()
    {
        $c = @fsockopen('127.0.0.1', 21, $no, $str, 1);
        if ($c) {
            fclose($c);
            return true;
        }
        return false;
    }

    /**
     * Every FTP account on the server, with its owner.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>> By name: 'user', 'owner', 'enabled' (0: the client is switched off), 'dir', 'access', 'created'.
     */
    static function Accounts()
    {
        global $zdbh;
        static $rows = null;
        if ($rows === null) {
            $rows = $zdbh->query("SELECT f.ft_user_vc AS user, a.ac_user_vc AS owner, a.ac_enabled_in AS enabled, f.ft_directory_vc AS dir, f.ft_access_vc AS access, f.ft_created_ts AS created
                FROM x_ftpaccounts f LEFT JOIN x_accounts a ON a.ac_id_pk = f.ft_acc_fk WHERE f.ft_deleted_ts IS NULL ORDER BY a.ac_user_vc, f.ft_user_vc")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }

    /**
     * The figures at the top (<@ Tiles @>): the server, the accounts, and how many are read or upload only.
     *
     * @return string The HTML.
     */
    static function getTiles()
    {
        $up = self::ServerUp();
        $by = array('RO' => 0, 'WO' => 0);
        $owners = array();
        foreach (self::Accounts() as $a) {
            if (isset($by[$a['access']])) {
                $by[$a['access']]++;
            }
            $owners[$a['owner']] = true;
        }
        $ico = function ($d) { return '<svg class="fa-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>'; };
        return '<div class="fa-tile ' . ($up ? 'ok' : 'bad') . '">' . $ico($up ? '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>' : '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>')
            . '<div><b>' . self::t($up ? 'Running' : 'Not answering') . '</b><span>' . self::t('FTP server, port 21') . '</span></div></div>'
            . '<div class="fa-tile">' . $ico('<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>') . '<div><b>' . count(self::Accounts()) . '</b><span>'
            . (count($owners) === 1 ? self::t('FTP accounts, of 1 client') : str_replace('%d', (string) count($owners), self::t('FTP accounts, of %d clients'))) . '</span></div></div>'
            . '<div class="fa-tile">' . $ico('<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>') . '<div><b>' . ($by['RO'] + $by['WO']) . '</b><span>'
            . self::t('Read or upload only') . '</span></div></div>';
    }

    /**
     * Every FTP account (<@ AccountRows @>), as table rows.
     *
     * @return string The HTML.
     */
    static function getAccountRows()
    {
        $names = array('RW' => 'Full access', 'RO' => 'Read only', 'WO' => 'Upload only');
        $out = '';
        foreach (self::Accounts() as $a) {
            $off = $a['enabled'] !== null && (int) $a['enabled'] === 0;
            $out .= '<tr' . ($off ? ' class="fa-row-off"' : '') . ' data-s="' . self::h(strtolower($a['user'] . ' ' . $a['owner'] . ' ' . $a['dir'])) . '"><td><code>' . self::h($a['user']) . '</code></td><td>' . self::h($a['owner'])
                . ($off ? '<span class="fa-off" title="' . self::t('Its FTP accounts can not log in until it is switched on again in Manage Clients.') . '">' . self::t('Switched off') . '</span>' : '') . '</td>'
                . '<td><code>' . self::h($a['dir'] === '' ? '/' : $a['dir']) . '</code></td><td><span class="fa-acc fa-acc-' . self::h(strtolower($a['access'])) . '">'
                . self::t(isset($names[$a['access']]) ? $names[$a['access']] : $a['access']) . '</span></td>'
                . '<td>' . ($a['created'] ? self::h(ui_language::Date('j M Y', (int) $a['created'])) : '') . '</td></tr>';
        }
        return $out;
    }

    /**
     * Template flag <% if HasAccounts %>.
     *
     * @return bool
     */
    static function getHasAccounts()
    {
        return count(self::Accounts()) > 0;
    }

    /**
     * Template flag <% if NoAccounts %>.
     *
     * @return bool
     */
    static function getNoAccounts()
    {
        return !self::getHasAccounts();
    }

    /**
     * The panel's own name, which FTP clients connect to (<@ Server @>).
     *
     * @return string The escaped host name.
     */
    static function getServer()
    {
        return self::h(ctrl_options::GetSystemOption('sentora_domain'));
    }

    /**
     * Returns the module's user-editable settings, as fields (<@ SettingFields @>).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML.
     */
    static function getSettingFields()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_settings WHERE so_module_vc = :m AND so_usereditable_en = 'true' ORDER BY so_cleanname_vc");
        $q->execute(array(':m' => ui_module::GetModuleName()));
        $out = '';
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = self::h($r['so_name_vc']);
            if (ctrl_options::CheckForPredefinedOptions($r['so_defvalues_tx'])) {
                $field = '<select class="form-control" name="' . $name . '" id="' . $name . '">';
                foreach (explode('|', (string) $r['so_defvalues_tx']) as $v) {
                    $field .= '<option' . ($v === $r['so_value_tx'] ? ' selected' : '') . '>' . self::h($v) . '</option>';
                }
                $field .= '</select>';
            } else {
                $field = '<input class="form-control" type="text" name="' . $name . '" id="' . $name . '" value="' . self::h($r['so_value_tx']) . '" required>';
            }
            $out .= '<label class="fa-field" for="' . $name . '"><span>' . self::t($r['so_cleanname_vc']) . '</span>' . $field . '<small>' . self::h(rtrim(ui_language::translate((string) $r['so_desc_tx']), '. ') . '.')
                . ($r['so_name_vc'] === 'ftp_db' ? ' ' . self::t('Change it only when ProFTPD\'s database was moved: the panel writes the FTP logins there.') : '') . '</small></label>';
        }
        return $out;
    }

    /**
     * Returns the module's user-editable settings for the old form (<% loop Config %>).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>>|false One row per setting: 'cleanname', 'name', 'description', 'value', 'fieldhtml'; false when there are none.
     */
    static function getConfig()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_settings WHERE so_module_vc = :m AND so_usereditable_en = 'true' ORDER BY so_cleanname_vc");
        $q->execute(array(':m' => ui_module::GetModuleName()));
        $res = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $res[] = array('cleanname' => ui_language::translate($r['so_cleanname_vc']), 'name' => $r['so_name_vc'], 'description' => ui_language::translate($r['so_desc_tx']), 'value' => $r['so_value_tx'],
                'fieldhtml' => ctrl_options::CheckForPredefinedOptions($r['so_defvalues_tx']) ? ctrl_options::OuputSettingMenuField($r['so_name_vc'], $r['so_defvalues_tx'], $r['so_value_tx'])
                    : ctrl_options::OutputSettingTextArea($r['so_name_vc'], $r['so_value_tx']));
        }
        return $res ? $res : false;
    }

    /**
     * Saves the settings form (POST action=UpdateConfig): each user-editable setting of the module,
     * a database name only when it is a valid one (letters, digits, underscores).
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
        $q = $zdbh->prepare("SELECT so_name_vc, so_defvalues_tx FROM x_settings WHERE so_module_vc = :m AND so_usereditable_en = 'true'");
        $q->execute(array(':m' => ui_module::GetModuleName()));
        $set = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = :n");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $v = trim((string) $controller->GetControllerRequest('FORM', $r['so_name_vc']));
            if ($v === '' || ($r['so_name_vc'] === 'ftp_db' && !preg_match('/^[A-Za-z0-9_]{1,64}$/', $v))) {
                continue;
            }
            if (ctrl_options::CheckForPredefinedOptions($r['so_defvalues_tx']) && !in_array($v, explode('|', (string) $r['so_defvalues_tx']), true)) {
                continue;
            }
            $set->execute(array(':v' => $v, ':n' => $r['so_name_vc']));
        }
        self::$ok = true;
    }

    /**
     * Returns the "settings saved" message after a save (<@ Result @>).
     *
     * @return string|null The alert HTML, or null when nothing was saved.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate('The settings are saved.'), 'zannounceok');
        }
        return;
    }

}
