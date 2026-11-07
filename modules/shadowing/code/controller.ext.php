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
 * Shadowing module: lets an administrator or reseller log in as one of their client accounts, to
 * see the panel as the client does. The original account ID is kept in the session ('ruid'),
 * so ./?returnsession switches back (to the page shadowing started from, 'rreturn'); shadowing
 * again from a shadowed account keeps the first one, so the way back is never lost. Each start
 * is logged (Log()), and the page shows the recent ones.
 */
class module_controller extends ctrl_module
{
    /** @var mixed Unused. */
    static $shout;

    /**
     * Where shadowing is logged (as ctrl_twofactor logs its resets).
     */
    const LOG = '/var/lib/sentora/shadowing.log';

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
     * Whether the logged-in account is an administrator (it may shadow every account). This
     * used to check the user name "zadmin", so another administrator only saw its own clients.
     *
     * @return bool
     */
    static function IsAdmin()
    {
        return (int) ctrl_users::GetUserDetail()['usergroupid'] === 1;
    }

    /**
     * The accounts the logged-in account may shadow: an administrator every other account, a reseller its own clients.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>> By user name: 'id', 'user', 'name', 'email', 'package', 'group', 'reseller', 'enabled', 'lastlogon', 'disk', 'bw'.
     */
    static function Accounts()
    {
        global $zdbh;
        static $rows = null;
        if ($rows !== null) {
            return $rows;
        }
        $me = (int) ctrl_users::GetUserDetail()['userid'];
        $admin = self::IsAdmin();
        $q = $zdbh->prepare("SELECT a.ac_id_pk, a.ac_user_vc, a.ac_email_vc, a.ac_enabled_in, a.ac_lastlogon_ts, p.ud_fullname_vc, k.pk_name_vc, g.ug_name_vc, r.ac_user_vc AS reseller
            FROM x_accounts a LEFT JOIN x_profiles p ON p.ud_user_fk = a.ac_id_pk LEFT JOIN x_packages k ON k.pk_id_pk = a.ac_package_fk
            LEFT JOIN x_groups g ON g.ug_id_pk = a.ac_group_fk LEFT JOIN x_accounts r ON r.ac_id_pk = a.ac_reseller_fk
            WHERE a.ac_deleted_ts IS NULL AND a.ac_id_pk <> :me" . ($admin ? '' : ' AND a.ac_reseller_fk = :me2') . " ORDER BY a.ac_user_vc");
        $q->execute(array(':me' => $me) + ($admin ? array() : array(':me2' => $me)));
        $rows = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['ac_id_pk'];
            $rows[] = array('id' => $id, 'user' => (string) $r['ac_user_vc'], 'name' => (string) $r['ud_fullname_vc'], 'email' => (string) $r['ac_email_vc'],
                'package' => (string) $r['pk_name_vc'], 'group' => (string) $r['ug_name_vc'], 'reseller' => (string) $r['reseller'], 'enabled' => (int) $r['ac_enabled_in'] === 1,
                'lastlogon' => (int) $r['ac_lastlogon_ts'], 'disk' => (float) ctrl_users::GetQuotaUsages('diskspace', $id), 'bw' => (float) ctrl_users::GetQuotaUsages('bandwidth', $id));
        }
        return $rows;
    }

    /**
     * The accounts the logged-in user may shadow, in the old loop's form (kept for themes that use <% loop ShadowAccounts %>).
     *
     * @return array<int, array<string, mixed>>|false One row per account: 'clientusername', 'clientid', 'packagename', 'usergroup', 'currentdisk', 'currentbandwidth', 'resellerid', 'resellername'; false when there are none.
     */
    static function getShadowAccounts()
    {
        $res = array();
        foreach (self::Accounts() as $a) {
            $res[] = array('clientusername' => self::h($a['user']), 'clientid' => $a['id'], 'packagename' => self::h($a['package']), 'usergroup' => self::h($a['group']),
                'currentdisk' => fs_director::ShowHumanFileSize($a['disk']), 'currentbandwidth' => fs_director::ShowHumanFileSize($a['bw']), 'resellerid' => '', 'resellername' => self::h($a['reseller']));
        }
        return $res ? $res : false;
    }

    /**
     * Append a line to the shadowing log.
     *
     * @param string $text What happened.
     * @return void
     */
    static function Log($text)
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        @file_put_contents(self::LOG, date('Y-m-d H:i:s') . "\t" . $text . "\t" . $ip . "\n", FILE_APPEND | LOCK_EX);
        @chmod(self::LOG, 0640);
    }

    /**
     * Handle Log in as (inShadow_<id>): become the chosen account, then open the panel and exit.
     *
     * Only accounts listed by Accounts() can be chosen; nothing happens for any other ID. When the
     * session is already a shadow one, the first account stays the one to return to. The page to
     * return to (inReturn, a module name) is kept for ./?returnsession.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doShadowUser()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        foreach (self::Accounts() as $a) {
            if (fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inShadow_' . $a['id']))) {
                continue;
            }
            $return = (string) $controller->GetControllerRequest('FORM', 'inReturn');
            $from = isset($_SESSION['ruid']) && $_SESSION['ruid'] ? (int) $_SESSION['ruid'] : (int) $currentuser['userid'];
            self::Log('by=' . ($from === (int) $currentuser['userid'] ? $currentuser['username'] : ctrl_users::GetUserDetail($from)['username'] . ' (via ' . $currentuser['username'] . ')') . "\tof=" . $a['user']);
            $keep = runtime_sessionsecurity::getSessionSecurityEnabled();
            ctrl_auth::KillCookies();
            ctrl_auth::SetUserSession($a['id'], $keep);
            // set after the session switch (it makes a new session id; the values stay)
            ctrl_auth::SetSession('ruid', $from);
            if (!isset($_SESSION['rreturn']) || $from === (int) $currentuser['userid']) {
                ctrl_auth::SetSession('rreturn', preg_match('/^[a-z0-9_]{1,40}$/', $return) ? $return : 'shadowing');
            }
            header('location: ./');
            exit;
        }
    }

    /**
     * The accounts as rows (<@ AccountRows @>), each with Log in as.
     *
     * @return string The HTML.
     */
    static function getAccountRows()
    {
        $admin = self::IsAdmin();
        $out = '';
        foreach (self::Accounts() as $a) {
            $s = strtolower($a['user'] . ' ' . $a['name'] . ' ' . $a['email'] . ' ' . $a['package'] . ' ' . $a['reseller']);
            $out .= '<div class="sh-row' . ($a['enabled'] ? '' : ' is-off') . '" data-s="' . self::h($s) . '" data-r="' . self::h($a['reseller']) . '">'
                . '<span class="sh-avatar" aria-hidden="true">' . self::h(strtoupper(substr($a['user'], 0, 1))) . '</span>'
                . '<div class="sh-main"><div class="sh-name"><b>' . self::h($a['user']) . '</b>' . ($a['name'] !== '' ? '<span>' . self::h($a['name']) . '</span>' : '')
                . (!$a['enabled'] ? '<em class="sh-badge off">' . self::t('Switched off') . '</em>' : '') . '</div>'
                . '<div class="sh-sub"><span class="sh-chip">' . self::h($a['package']) . '</span><span>' . self::h(ui_language::translate($a['group'])) . '</span>'
                . ($admin ? '<span>' . self::t('Client of') . ' <b>' . self::h($a['reseller']) . '</b></span>' : '')
                . '<span>' . ($a['lastlogon'] ? self::t('Last login') . ' ' . self::h(date('j M Y', $a['lastlogon'])) : self::t('Never logged in')) . '</span>'
                . '<span>' . self::h(fs_director::ShowHumanFileSize($a['disk'])) . ' ' . self::t('disk') . '</span></div></div>'
                . '<button class="btn btn-primary btn-sm sh-go" type="submit" name="inShadow_' . $a['id'] . '" value="inShadow_' . $a['id'] . '">'
                . '<svg class="sh-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/></svg>'
                . self::t('Log in as') . '</button></div>';
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
     * The resellers to filter by, for an administrator (<@ ResellerOptions @>); '' for a reseller.
     *
     * @return string The <option>s.
     */
    static function getResellerOptions()
    {
        if (!self::IsAdmin()) {
            return '';
        }
        $names = array_unique(array_map(function ($a) { return $a['reseller']; }, self::Accounts()));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $out = '';
        foreach ($names as $n) {
            $out .= '<option value="' . self::h($n) . '">' . self::t('Clients of') . ' ' . self::h($n) . '</option>';
        }
        return count($names) > 1 ? $out : '';
    }

    /**
     * Template flag <% if NoAdmin %>: a reseller.
     *
     * @return bool
     */
    static function getNoAdmin()
    {
        return !self::IsAdmin();
    }

    /**
     * Template flag <% if IsAdmin %>.
     *
     * @return bool
     */
    static function getIsAdmin()
    {
        return self::IsAdmin();
    }

    /**
     * The recent shadowing (<@ Recent @>): the last 12 starts, an administrator's of everyone, a reseller's own.
     *
     * @return string The HTML ('' when there are none).
     */
    static function getRecent()
    {
        $lines = @file(self::LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return '';
        }
        $me = ctrl_users::GetUserDetail()['username'];
        $admin = self::IsAdmin();
        $out = '';
        $n = 0;
        foreach (array_reverse($lines) as $l) {
            $f = explode("\t", $l);
            if (count($f) < 3 || strpos($f[1], 'by=') !== 0 || strpos($f[2], 'of=') !== 0) {
                continue;
            }
            $by = substr($f[1], 3);
            if (!$admin && strtok($by, ' ') !== $me) {
                continue;
            }
            $out .= '<li><span class="sh-when">' . self::h(date('j M, H:i', (int) strtotime($f[0]))) . '</span><span><b>' . self::h($by) . '</b> ' . self::t('as') . ' <b>' . self::h(substr($f[2], 3)) . '</b></span></li>';
            if (++$n >= 12) {
                break;
            }
        }
        return $out !== '' ? '<ul class="sh-recent">' . $out . '</ul>' : '';
    }
}
