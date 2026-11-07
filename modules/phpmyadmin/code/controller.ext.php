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
 * phpMyAdmin module: the page that opens phpMyAdmin (etc/apps/phpmyadmin), with the
 * account's MySQL users to log in with and what is good to know before using it.
 * phpMyAdmin itself is its own application with its own login (a MySQL user).
 */
class module_controller extends ctrl_module
{

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
     * The phpMyAdmin version from its own source (<@ Version @>).
     *
     * @return string For example '5.2.3', or '' when it can not be read.
     */
    static function getVersion()
    {
        $src = @file_get_contents('etc/apps/phpmyadmin/libraries/classes/Version.php');
        return ($src !== false && preg_match("/VERSION = '([0-9.]+)'/", $src, $m)) ? self::h($m[1]) : '';
    }

    /**
     * Whether a module is installed and switched on, for the links to it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $folder The module's folder name.
     * @return bool
     */
    static function ModuleOn($folder)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_modules WHERE mo_folder_vc = :f AND mo_enabled_en = 'true'");
        $q->execute(array(':f' => $folder));
        return (int) $q->fetchColumn() > 0;
    }

    /**
     * The account's MySQL users with their databases.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array{id: int, name: string, access: string, dbs: array<int, string>}> One entry per user, by name.
     */
    static function Users()
    {
        global $zdbh;
        $uid = (int) ctrl_users::GetUserDetail()['userid'];
        $q = $zdbh->prepare("SELECT mu_id_pk, mu_name_vc, mu_access_vc FROM x_mysql_users WHERE mu_acc_fk = :a AND mu_deleted_ts IS NULL ORDER BY mu_name_vc");
        $q->execute(array(':a' => $uid));
        $d = $zdbh->prepare("SELECT d.my_name_vc FROM x_mysql_dbmap m JOIN x_mysql_databases d ON d.my_id_pk = m.mm_database_fk
            WHERE m.mm_user_fk = :u AND m.mm_acc_fk = :a AND d.my_deleted_ts IS NULL ORDER BY d.my_name_vc");
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d->execute(array(':u' => (int) $r['mu_id_pk'], ':a' => $uid));
            $out[] = array('id' => (int) $r['mu_id_pk'], 'name' => (string) $r['mu_name_vc'], 'access' => (string) $r['mu_access_vc'], 'dbs' => $d->fetchAll(PDO::FETCH_COLUMN));
        }
        return $out;
    }

    /**
     * Template flag <% if HasUsers %>: the account has a MySQL user to log in with.
     *
     * @return bool
     */
    static function getHasUsers()
    {
        return count(self::Users()) > 0;
    }

    /**
     * Template flag <% if NoUsers %>: the account has no MySQL user yet.
     *
     * @return bool
     */
    static function getNoUsers()
    {
        return !self::getHasUsers();
    }

    /**
     * One row per MySQL user (<@ UserRows @>): its name with Copy, its databases, and Show password
     * (which asks MySQL Users, where the password is kept).
     *
     * @return string The HTML.
     */
    static function getUserRows()
    {
        $prefix = ctrl_users::GetUserDetail()['username'] . '_';
        $show = self::ModuleOn('mysql_users');
        $out = '';
        foreach (self::Users() as $u) {
            $name = $u['name'];
            $nameHtml = strpos($name, $prefix) === 0 ? '<span class="pm-prefix">' . self::h($prefix) . '</span><b>' . self::h(substr($name, strlen($prefix))) . '</b>' : '<b>' . self::h($name) . '</b>';
            $dbs = '';
            foreach ($u['dbs'] as $db) {
                $dbs .= '<span class="pm-db">' . self::h($db) . '</span>';
            }
            $remote = $u['access'] !== '%' && strtolower($u['access']) !== 'localhost';
            $out .= '<div class="pm-user"><div class="pm-user-main">'
                . '<div class="pm-name"><code data-copy="' . self::h($name) . '">' . $nameHtml . '</code>'
                . '<button type="button" class="btn btn-default btn-xs pm-copy" data-done="' . self::t('Copied') . '">' . self::t('Copy') . '</button></div>'
                . '<div class="pm-dbs">' . ($dbs !== '' ? $dbs : '<span class="pm-none">' . self::t('no database yet') . '</span>') . '</div>'
                . ($remote ? '<p class="pm-warn">' . str_replace('%s', self::h($u['access']), self::t('This user may only log in from %s: phpMyAdmin runs on this server, so it can not log in here.')) . '</p>' : '')
                . '</div>'
                . ($show ? '<form method="post" action="./?module=mysql_users&amp;action=EditUser" class="pm-show">' . runtime_csfr::Token()
                    . '<button class="btn btn-default btn-sm" type="submit" name="inShow_' . $u['id'] . '" value="' . $u['id'] . '"><svg class="pm-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>' . self::t('Show password') . '</button></form>' : '')
                . '</div>';
        }
        return $out;
    }

    /**
     * Template value <@ UploadLimit @>: the largest file phpMyAdmin can import here (the smaller of PHP's upload and post limits).
     *
     * @return string For example '2 MB'.
     */
    static function getUploadLimit()
    {
        $bytes = function ($v) {
            $v = trim((string) $v);
            $n = (float) $v;
            switch (strtolower(substr($v, -1))) {
                case 'g':
                    $n *= 1024;
                    // no break
                case 'm':
                    $n *= 1024;
                    // no break
                case 'k':
                    $n *= 1024;
            }
            return $n;
        };
        $limits = array_filter(array($bytes(ini_get('upload_max_filesize')), $bytes(ini_get('post_max_size'))), function ($n) { return $n > 0; });
        return $limits ? self::h(fs_director::ShowHumanFileSize((int) min($limits))) : self::t('no limit');
    }

    /**
     * Template flags for the links to the other database modules (<% if DatabasesOn %>, <% if UsersOn %>).
     *
     * @return bool
     */
    static function getDatabasesOn()
    {
        return self::ModuleOn('mysql_databases');
    }

    /**
     * Template flag <% if UsersOn %>: MySQL Users is switched on.
     *
     * @return bool
     */
    static function getUsersOn()
    {
        return self::ModuleOn('mysql_users');
    }

}
