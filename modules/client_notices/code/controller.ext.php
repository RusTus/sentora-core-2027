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
 * Client Notice Manager: the admin and resellers send notices to their clients, shown at the top
 * of the panel. Each notice has a kind (information, warning, maintenance, outage), an audience
 * (all clients, packages, groups or one client; the admin also everyone), an optional start and
 * end, and whether clients may close it. The notices are kept by ctrl_notices; the admin also
 * sees and manages the ones the panel's own code sent.
 */
class module_controller extends ctrl_module
{

    /**
     * Result of the last action: 'saved', 'removed' or 'error' ('' for none).
     *
     * @var string
     */
    static $result = '';

    /**
     * Why a save was refused, in English.
     *
     * @var array<int, string>
     */
    static $errors = array();

    /**
     * The posted form of a refused save, so nothing is lost.
     *
     * @var array<string, mixed>|null
     */
    static $posted = null;

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
     * The logged-in account's ID.
     *
     * @return int
     */
    static function Me()
    {
        return (int) ctrl_users::GetUserDetail()['userid'];
    }

    /**
     * Whether the logged-in account is an administrator: it may send to everyone and to any account, package or group.
     *
     * @return bool
     */
    static function IsAdmin()
    {
        return (int) ctrl_users::GetUserDetail()['usergroupid'] === 1;
    }

    /**
     * The accounts a personal notice may go to: the account's own clients (an admin: every account but itself).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array{id: int, user: string, name: string, email: string, package: string}> By user name.
     */
    static function Clients()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT a.ac_id_pk, a.ac_user_vc, a.ac_email_vc, p.ud_fullname_vc, k.pk_name_vc FROM x_accounts a
            LEFT JOIN x_profiles p ON p.ud_user_fk = a.ac_id_pk LEFT JOIN x_packages k ON k.pk_id_pk = a.ac_package_fk
            WHERE a.ac_deleted_ts IS NULL AND a.ac_id_pk <> :me" . (self::IsAdmin() ? '' : ' AND a.ac_reseller_fk = :r') . " ORDER BY a.ac_user_vc");
        $q->execute(array(':me' => self::Me()) + (self::IsAdmin() ? array() : array(':r' => self::Me())));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
            $out[] = array('id' => (int) $r[0], 'user' => (string) $r[1], 'email' => (string) $r[2], 'name' => (string) $r[3], 'package' => (string) $r[4]);
        }
        return $out;
    }

    /**
     * The packages a notice may go to, with how many of the account's clients are on each.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array{id: int, name: string, count: int}>
     */
    static function Packages()
    {
        global $zdbh;
        $admin = self::IsAdmin();
        $q = $zdbh->prepare("SELECT k.pk_id_pk, k.pk_name_vc, (SELECT COUNT(*) FROM x_accounts a WHERE a.ac_package_fk = k.pk_id_pk AND a.ac_deleted_ts IS NULL AND a.ac_id_pk <> :me"
            . ($admin ? '' : ' AND a.ac_reseller_fk = :r') . ") FROM x_packages k WHERE k.pk_deleted_ts IS NULL" . ($admin ? '' : ' AND k.pk_reseller_fk = :r2') . " ORDER BY k.pk_name_vc");
        $q->execute(array(':me' => self::Me()) + ($admin ? array() : array(':r' => self::Me(), ':r2' => self::Me())));
        return array_map(function ($r) { return array('id' => (int) $r[0], 'name' => (string) $r[1], 'count' => (int) $r[2]); }, $q->fetchAll(PDO::FETCH_NUM));
    }

    /**
     * The groups a notice may go to (a reseller: its own groups and the ones its clients are in), with how many of the account's clients are in each.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array{id: int, name: string, count: int}>
     */
    static function Groups()
    {
        global $zdbh;
        $admin = self::IsAdmin();
        $p = array(':me' => self::Me());
        $mine = '';
        if (!$admin) {
            $mine = ' AND (g.ug_reseller_fk = :r2 OR g.ug_id_pk IN (SELECT ac_group_fk FROM x_accounts WHERE ac_reseller_fk = :r3 AND ac_deleted_ts IS NULL))';
            $p += array(':r' => self::Me(), ':r2' => self::Me(), ':r3' => self::Me());
        }
        $q = $zdbh->prepare("SELECT g.ug_id_pk, g.ug_name_vc, (SELECT COUNT(*) FROM x_accounts a WHERE a.ac_group_fk = g.ug_id_pk AND a.ac_deleted_ts IS NULL AND a.ac_id_pk <> :me"
            . ($admin ? '' : ' AND a.ac_reseller_fk = :r') . ") FROM x_groups g WHERE 1 = 1" . $mine . " ORDER BY g.ug_name_vc");
        $q->execute($p);
        return array_map(function ($r) { return array('id' => (int) $r[0], 'name' => (string) $r[1], 'count' => (int) $r[2]); }, $q->fetchAll(PDO::FETCH_NUM));
    }

    /**
     * The notices the account manages: its own, and for an admin also the ones the panel sent.
     *
     * @return array<int, array<string, mixed>> The notices (see ctrl_notices::Row()).
     */
    static function Notices()
    {
        $out = ctrl_notices::From(self::Me());
        if (self::IsAdmin()) {
            $out = array_merge($out, ctrl_notices::From(0));
        }
        return $out;
    }

    /**
     * A notice the account may change (its own; for an admin also one the panel sent).
     *
     * @param int|string $id The notice ID.
     * @return array<string, mixed>|false The notice, or false.
     */
    static function Mine($id)
    {
        $n = ctrl_notices::Get((int) $id);
        if (!$n || ($n['from'] !== self::Me() && !($n['from'] === 0 && self::IsAdmin()))) {
            return false;
        }
        return $n;
    }

    // Actions

    /**
     * Save the posted notice (new, or the one in inId), after checking its audience is the account's own.
     *
     * Redirects to the list when saved; when refused, the form stays filled in with the reasons.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|void False when refused; otherwise ends with a redirect header and exit.
     */
    static function doSave()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $id = isset($f['inId']) ? (int) $f['inId'] : 0;
        $old = $id ? self::Mine($id) : false;
        if ($id && !$old) {
            return false;
        }
        $scope = isset($f['inScope']) ? (string) $f['inScope'] : 'clients';
        $targets = array();
        $e = array();
        $ids = function ($v) { return array_values(array_unique(array_map('intval', is_array($v) ? $v : array()))); };
        $col = function ($rows) { return array_map(function ($r) { return $r['id']; }, $rows); };
        if ($scope === 'everyone' && !self::IsAdmin()) {
            $scope = 'clients';
        }
        if ($scope === 'packages') {
            $targets = array_values(array_intersect($ids(isset($f['inPackages']) ? $f['inPackages'] : null), $col(self::Packages())));
            if (!$targets) {
                $e[] = 'Choose at least one package.';
            }
        } elseif ($scope === 'groups') {
            $targets = array_values(array_intersect($ids(isset($f['inGroups']) ? $f['inGroups'] : null), $col(self::Groups())));
            if (!$targets) {
                $e[] = 'Choose at least one group.';
            }
        } elseif ($scope === 'user') {
            $u = isset($f['inUser']) ? (int) $f['inUser'] : 0;
            if (!in_array($u, $col(self::Clients()), true)) {
                $e[] = 'Choose the client the notice is for.';
            } else {
                $targets = array($u);
            }
        } elseif ($scope === 'clients' && $old && $old['from'] === 0) {
            $targets = $old['targets'];
        } elseif (!in_array($scope, array('everyone', 'clients'), true)) {
            $scope = 'clients';
        }
        $tz = new DateTimeZone(date_default_timezone_get());
        $when = function ($v, $name) use (&$e, $tz) {
            $v = trim((string) $v);
            if ($v === '') {
                return null;
            }
            $d = DateTime::createFromFormat('Y-m-d\TH:i', $v, $tz);
            if (!$d) {
                $e[] = 'The ' . $name . ' time is not a date and time.';
                return null;
            }
            return $d->getTimestamp();
        };
        $start = $when(isset($f['inStart']) ? $f['inStart'] : '', 'start');
        $end = $when(isset($f['inEnd']) ? $f['inEnd'] : '', 'end');
        if ($end !== null && $end <= time()) {
            $e[] = 'The end time has already passed.';
        }
        $n = array(
            'id' => $id, 'from' => $old ? $old['from'] : self::Me(), 'type' => isset($f['inType']) ? (string) $f['inType'] : 'info',
            'title' => isset($f['inTitle']) ? (string) $f['inTitle'] : '', 'body' => isset($f['inBody']) ? (string) $f['inBody'] : '',
            'scope' => $scope, 'targets' => $targets, 'closable' => !empty($f['inClosable']), 'start' => $start, 'end' => $end,
            'key' => $old ? $old['key'] : null, 'source' => $old ? $old['source'] : 'client_notices',
        );
        if (!$e && ctrl_notices::Send($n) === false) {
            $e[] = ctrl_notices::$error;
        }
        if ($e) {
            self::$result = 'error';
            self::$errors = $e;
            self::$posted = $f;
            return false;
        }
        header('location: ./?module=' . $controller->GetCurrentModule() . '&saved=true');
        exit;
    }

    /**
     * Take away a notice (inRemove), when it is the account's to change.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|void False when it is not the account's; otherwise ends with a redirect header and exit.
     */
    static function doRemove()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        if (!isset($f['inRemove']) || !self::Mine($f['inRemove'])) {
            return false;
        }
        ctrl_notices::Remove((int) $f['inRemove']);
        header('location: ./?module=' . $controller->GetCurrentModule() . '&removed=true');
        exit;
    }

    // Template values

    /**
     * Template flag <% if IsEdit %>: the form for a new notice or changing one.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getIsEdit()
    {
        global $controller;
        if (self::$posted !== null) {
            return true;
        }
        $show = $controller->GetControllerRequest('URL', 'show');
        return $show === 'New' || ($show === 'Edit' && self::Mine($controller->GetControllerRequest('URL', 'id')) !== false);
    }

    /**
     * Template flag <% if IsList %>: the list of notices.
     *
     * @return bool
     */
    static function getIsList()
    {
        return !self::getIsEdit();
    }

    /**
     * The message after an action (<@ Result @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML ('' for none).
     */
    static function getResult()
    {
        global $controller;
        if (self::$result === 'error') {
            $li = '';
            foreach (self::$errors as $e) {
                $li .= '<li>' . self::t($e) . '</li>';
            }
            return ui_sysmessage::shout('<b>' . self::t('The notice was not saved:') . '</b><ul class="cn-errs">' . $li . '</ul>', 'zannounceerror');
        }
        if ($controller->GetControllerRequest('URL', 'saved')) {
            return ui_sysmessage::shout(ui_language::translate('The notice is saved. It shows on the next page its clients open.'), 'zannounceok');
        }
        if ($controller->GetControllerRequest('URL', 'removed')) {
            return ui_sysmessage::shout(ui_language::translate('The notice is removed.'), 'zannounceok');
        }
        return '';
    }

    /**
     * The figures at the top (<@ Tiles @>): showing now, waiting to start, and ended.
     *
     * @return string The HTML.
     */
    static function getTiles()
    {
        $c = array('live' => 0, 'scheduled' => 0, 'ended' => 0);
        foreach (self::Notices() as $n) {
            $c[ctrl_notices::State($n)]++;
        }
        $tile = function ($cls, $num, $text, $path) {
            return '<div class="cn-tile ' . $cls . '"><svg class="cn-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                . $path . '</svg><div><b>' . $num . '</b><span>' . self::t($text) . '</span></div></div>';
        };
        return $tile('live', $c['live'], 'Showing now', '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>')
            . $tile('sched', $c['scheduled'], 'Waiting to start', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>')
            . $tile('ended', $c['ended'], 'Ended', '<path d="M20 6 9 17l-5-5"/>');
    }

    /**
     * Who a notice is for, in words, with how many accounts that is.
     *
     * @param array<string, mixed> $n The notice.
     * @return string The HTML.
     */
    static function Audience($n)
    {
        $names = function ($rows, $ids) {
            $out = array();
            foreach ($rows as $r) {
                if (in_array($r['id'], $ids, true)) {
                    $out[] = $r['name'];
                }
            }
            return implode(', ', $out);
        };
        $reach = ctrl_notices::Reach($n);
        switch ($n['scope']) {
            case 'everyone':
                $what = self::t('Everyone on the server');
                break;
            case 'packages':
                $what = self::t('Packages:') . ' ' . self::h($names(self::Packages(), $n['targets']));
                break;
            case 'groups':
                $what = self::t('Groups:') . ' ' . self::h($names(self::Groups(), $n['targets']));
                break;
            case 'user':
                $who = '#' . (int) $n['targets'][0];
                foreach (self::Clients() as $c) {
                    if ($c['id'] === (int) $n['targets'][0]) {
                        $who = $c['user'] . ($c['name'] !== '' ? ' (' . $c['name'] . ')' : '');
                    }
                }
                return '<span class="cn-aud"><b>' . self::t('Personal:') . '</b> ' . self::h($who) . '</span>';
            default:
                $what = $n['from'] === 0 && $n['targets'] ? self::t('Clients of a reseller') : self::t('All your clients');
        }
        return '<span class="cn-aud"><b>' . $what . '</b> &middot; ' . $reach . ' ' . self::t($reach === 1 ? 'account' : 'accounts') . '</span>';
    }

    /**
     * When a notice shows, in words.
     *
     * @param array<string, mixed> $n The notice.
     * @return string The HTML.
     */
    static function When($n)
    {
        $d = function ($t) { return self::h(date('j M Y, H:i', $t)); };
        switch (ctrl_notices::State($n)) {
            case 'ended':
                return '<span class="cn-state ended">' . self::t('Ended') . '</span> ' . $d($n['end']);
            case 'scheduled':
                return '<span class="cn-state sched">' . self::t('Starts') . '</span> ' . $d($n['start']) . ($n['end'] !== null ? ' &middot; ' . self::t('until') . ' ' . $d($n['end']) : '');
        }
        return '<span class="cn-state live">' . self::t('Showing') . '</span> ' . ($n['end'] !== null ? self::t('until') . ' ' . $d($n['end']) : self::t('until you remove it'));
    }

    /**
     * The notices (<@ NoticeList @>): a card each, showing ones first, with Change and Remove.
     *
     * @return string The HTML.
     */
    static function getNoticeList()
    {
        $rows = self::Notices();
        if (!$rows) {
            return '<div class="cn-empty">' . ui_tpl_notice::Icon('info', 'cn-ico-xl') . '<b>' . self::t('No notices') . '</b><span>'
                . self::t('Tell your clients about planned maintenance, an outage or something new. A notice shows at the top of every page of their panel.') . '</span></div>';
        }
        $order = array('live' => 0, 'scheduled' => 1, 'ended' => 2);
        usort($rows, function ($a, $b) use ($order) {
            $d = $order[ctrl_notices::State($a)] - $order[ctrl_notices::State($b)];
            return $d !== 0 ? $d : $b['id'] - $a['id'];
        });
        $out = '';
        foreach ($rows as $n) {
            $state = ctrl_notices::State($n);
            $out .= '<div class="cn-item cn-t-' . self::h($n['type']) . ($state === 'ended' ? ' is-ended' : '') . '">'
                . ui_tpl_notice::Icon($n['type'], 'cn-item-ico')
                . '<div class="cn-item-main"><div class="cn-item-head"><b>' . self::h($n['title'] !== '' ? $n['title'] : ui_tpl_notice::TypeName($n['type'])) . '</b>'
                . ($n['title'] !== '' ? '<span class="cn-kind">' . self::h(ui_tpl_notice::TypeName($n['type'])) . '</span>' : '')
                . ($n['from'] === 0 ? '<span class="cn-kind cn-panel" title="' . self::h($n['source']) . '">' . self::t('Sent by the panel') . '</span>' : '')
                . (!$n['closable'] ? '<span class="cn-kind">' . self::t('Can not be closed') . '</span>' : '') . '</div>'
                . '<div class="cn-item-body' . (mb_strlen($n['body'], 'UTF-8') > 280 || substr_count($n['body'], "\n") > 4 ? ' is-long' : '') . '">' . ui_tpl_notice::Format($n['body']) . '</div>'
                . '<div class="cn-item-meta">' . self::Audience($n) . '<span>' . self::When($n) . '</span></div></div>'
                . '<div class="cn-item-act"><a class="btn btn-default btn-sm" href="./?module=client_notices&amp;show=Edit&amp;id=' . $n['id'] . '">' . self::t('Change') . '</a>'
                . '<button class="btn btn-default btn-sm cn-remove" type="submit" form="cn-remove" name="inRemove" value="' . $n['id'] . '" data-confirm="' . self::t('Remove this notice? Your clients no longer see it.') . '" aria-label="' . self::t('Remove') . '" title="' . self::t('Remove') . '">'
                . '<svg class="cn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></button></div>'
                . '</div>';
        }
        return $out;
    }

    /**
     * The notice in the form: the posted one after a refused save, the one being changed, or a new one
     * (?user= starts a personal notice to that client).
     *
     * @global runtime_controller $controller The request controller.
     * @return array<string, mixed> 'id', 'type', 'title', 'body', 'scope', 'targets', 'closable', 'start', 'end' (form values), 'panel' (sent by the panel).
     */
    static function FormValues()
    {
        global $controller;
        $dt = function ($t) { return $t === null ? '' : date('Y-m-d\TH:i', $t); };
        if (self::$posted !== null) {
            $f = self::$posted;
            $scope = isset($f['inScope']) ? (string) $f['inScope'] : 'clients';
            $t = $scope === 'packages' ? (isset($f['inPackages']) ? (array) $f['inPackages'] : array()) : ($scope === 'groups' ? (isset($f['inGroups']) ? (array) $f['inGroups'] : array()) : (isset($f['inUser']) ? array($f['inUser']) : array()));
            $old = !empty($f['inId']) ? self::Mine($f['inId']) : false;
            return array('id' => (int) (isset($f['inId']) ? $f['inId'] : 0), 'type' => isset($f['inType']) ? (string) $f['inType'] : 'info', 'title' => isset($f['inTitle']) ? (string) $f['inTitle'] : '',
                'body' => isset($f['inBody']) ? (string) $f['inBody'] : '', 'scope' => $scope, 'targets' => array_map('intval', $t), 'closable' => !empty($f['inClosable']),
                'start' => isset($f['inStart']) ? (string) $f['inStart'] : '', 'end' => isset($f['inEnd']) ? (string) $f['inEnd'] : '', 'panel' => $old && $old['from'] === 0);
        }
        if ($controller->GetControllerRequest('URL', 'show') === 'Edit' && ($n = self::Mine($controller->GetControllerRequest('URL', 'id')))) {
            return array('id' => $n['id'], 'type' => $n['type'], 'title' => $n['title'], 'body' => $n['body'], 'scope' => $n['scope'], 'targets' => $n['targets'],
                'closable' => $n['closable'], 'start' => $dt($n['start']), 'end' => $dt($n['end']), 'panel' => $n['from'] === 0);
        }
        $user = (int) $controller->GetControllerRequest('URL', 'user');
        return array('id' => 0, 'type' => 'info', 'title' => '', 'body' => '', 'scope' => $user ? 'user' : 'clients', 'targets' => $user ? array($user) : array(),
            'closable' => true, 'start' => '', 'end' => '', 'panel' => false);
    }

    /**
     * The form's heading (<@ FormTitle @>).
     *
     * @return string The escaped text.
     */
    static function getFormTitle()
    {
        return self::t(self::FormValues()['id'] ? 'Change the notice' : 'New notice');
    }

    /**
     * The notice ID in the form (<@ FormId @>), 0 for a new one.
     *
     * @return int
     */
    static function getFormId()
    {
        return (int) self::FormValues()['id'];
    }

    /**
     * The kinds of notice as radio buttons (<@ TypeChoices @>).
     *
     * @return string The HTML.
     */
    static function getTypeChoices()
    {
        $v = self::FormValues();
        $help = array('info' => 'News, a new feature', 'warning' => 'Something to act on', 'maintenance' => 'Planned work', 'outage' => 'Something is down');
        $out = '';
        foreach (array('info', 'warning', 'maintenance', 'outage') as $t) {
            $out .= '<label class="cn-type cn-t-' . $t . '"><input type="radio" name="inType" value="' . $t . '"' . ($v['type'] === $t ? ' checked' : '') . '>'
                . ui_tpl_notice::Icon($t, 'cn-ico') . '<span><b>' . self::h(ui_tpl_notice::TypeName($t)) . '</b><small>' . self::t($help[$t]) . '</small></span></label>';
        }
        return $out;
    }

    /**
     * The title in the form (<@ FormTitleValue @>), escaped.
     *
     * @return string
     */
    static function getFormTitleValue()
    {
        return self::h(self::FormValues()['title']);
    }

    /**
     * The text in the form (<@ FormBody @>), escaped.
     *
     * @return string
     */
    static function getFormBody()
    {
        return self::h(self::FormValues()['body']);
    }

    /**
     * The longest text and title (<@ MaxBody @>, <@ MaxTitle @>).
     *
     * @return int
     */
    static function getMaxBody()
    {
        return ctrl_notices::MAX_BODY;
    }

    /**
     * The longest title (<@ MaxTitle @>).
     *
     * @return int
     */
    static function getMaxTitle()
    {
        return ctrl_notices::MAX_TITLE;
    }

    /**
     * The audience choice (<@ AudienceChoices @>): the kinds as tabs, with the packages, groups and client picker under them.
     *
     * @return string The HTML.
     */
    static function getAudienceChoices()
    {
        $v = self::FormValues();
        $clients = self::Clients();
        $all = count($clients);
        $scopes = array();
        if (self::IsAdmin()) {
            $scopes['everyone'] = array('Everyone', 'Every account on the server.');
        }
        if ($v['panel'] && $v['scope'] === 'clients') {
            $scopes['clients'] = array('Clients of a reseller', 'As the panel sent it.');
        } else {
            $scopes['clients'] = array(self::IsAdmin() ? 'Your clients' : 'All your clients', self::IsAdmin() ? 'The accounts you made yourself.' : 'Every client you have.');
        }
        $scopes['packages'] = array('Packages', 'Clients on the packages you tick.');
        $scopes['groups'] = array('Groups', 'Clients in the groups you tick.');
        $scopes['user'] = array('One client', 'A personal notice that only they see.');
        $tabs = '';
        foreach ($scopes as $k => $s) {
            $tabs .= '<label class="cn-scope"><input type="radio" name="inScope" value="' . $k . '"' . ($v['scope'] === $k ? ' checked' : '') . '><span><b>' . self::t($s[0]) . '</b><small>' . self::t($s[1]) . '</small></span></label>';
        }
        $boxes = function ($rows, $name) use ($v) {
            if (!$rows) {
                return '<p class="cn-none">' . self::t('You have none yet.') . '</p>';
            }
            $out = '';
            foreach ($rows as $r) {
                $out .= '<label class="cn-check"><input type="checkbox" name="' . $name . '[]" value="' . $r['id'] . '" data-count="' . $r['count'] . '"'
                    . (in_array($r['id'], $v['targets'], true) ? ' checked' : '') . '><span>' . self::h($r['name']) . '</span><small>' . $r['count'] . '</small></label>';
            }
            return '<div class="cn-checks">' . $out . '</div>';
        };
        $opts = '';
        foreach ($clients as $c) {
            $label = $c['user'] . ($c['name'] !== '' ? ' — ' . $c['name'] : '') . ($c['email'] !== '' ? ' — ' . $c['email'] : '');
            $opts .= '<option value="' . $c['id'] . '"' . ($v['scope'] === 'user' && in_array($c['id'], $v['targets'], true) ? ' selected' : '')
                . ' data-s="' . self::h(strtolower($label . ' ' . $c['package'])) . '">' . self::h($label) . '</option>';
        }
        // how many "everyone" and "your clients" reach (an admin's clients list is every account)
        $reach = $all;
        $direct = $all;
        if (self::IsAdmin()) {
            $direct = ctrl_notices::Reach(array('from' => self::Me(), 'scope' => 'clients', 'targets' => array()));
        }
        return '<div class="cn-scopes" data-everyone="' . $reach . '" data-clients="' . $direct . '">' . $tabs . '</div>'
            . '<div class="cn-pane" data-for="packages">' . $boxes(self::Packages(), 'inPackages') . '</div>'
            . '<div class="cn-pane" data-for="groups">' . $boxes(self::Groups(), 'inGroups') . '</div>'
            . '<div class="cn-pane" data-for="user">' . ($clients ? '<input type="search" class="form-control cn-find" placeholder="' . self::t('Search by user name, name, email or package') . '" aria-label="' . self::t('Search clients') . '">'
                . '<select class="form-control cn-pick" name="inUser" size="7" aria-label="' . self::t('Client') . '">' . $opts . '</select><p class="cn-nomatch" hidden>' . self::t('No client matches.') . '</p>'
                : '<p class="cn-none">' . self::t('You have no clients yet.') . '</p>') . '</div>'
            . '<p class="cn-reach" id="cn-reach" data-one="' . self::t('Reaches 1 account') . '" data-many="' . self::t('Reaches %d accounts') . '"></p>';
    }

    /**
     * The start and end in the form (<@ FormStart @>, <@ FormEnd @>), as datetime-local values.
     *
     * @return string
     */
    static function getFormStart()
    {
        return self::h(self::FormValues()['start']);
    }

    /**
     * The end in the form (<@ FormEnd @>).
     *
     * @return string
     */
    static function getFormEnd()
    {
        return self::h(self::FormValues()['end']);
    }

    /**
     * Whether the notice may be closed (<@ FormClosable @>): ' checked' or ''.
     *
     * @return string
     */
    static function getFormClosable()
    {
        return self::FormValues()['closable'] ? ' checked' : '';
    }

    /**
     * The server's time zone and time (<@ ServerTime @>), as the start and end are in it.
     *
     * @return string The escaped text.
     */
    static function getServerTime()
    {
        return self::h(date_default_timezone_get() . ', ' . ui_language::translate('now') . ' ' . date('j M Y, H:i'));
    }

    /**
     * The preview's starting HTML (<@ FormPreview @>), so it shows without JavaScript too.
     *
     * @return string The HTML.
     */
    static function getFormPreview()
    {
        $v = self::FormValues();
        return ui_tpl_notice::Box(array('id' => 0, 'from' => $v['panel'] ? 0 : self::Me(), 'scope' => $v['scope'], 'type' => $v['type'], 'title' => $v['title'],
            'body' => $v['body'] !== '' ? $v['body'] : ui_language::translate('Your text shows here.'), 'closable' => $v['closable'], 'updated' => 0), 0, true);
    }

    /**
     * The texts the preview needs (<@ PreviewTexts @>), as data attributes.
     *
     * @return string The attributes.
     */
    static function getPreviewTexts()
    {
        $a = array('info' => ui_tpl_notice::TypeName('info'), 'warning' => ui_tpl_notice::TypeName('warning'), 'maintenance' => ui_tpl_notice::TypeName('maintenance'),
            'outage' => ui_tpl_notice::TypeName('outage'), 'host' => ui_language::translate(self::FormValues()['panel'] ? 'From the panel' : 'From your host'),
            'personal' => ui_language::translate('Just for you'), 'everyone' => ui_language::translate('For everyone'), 'close' => ui_language::translate('Close'), 'empty' => ui_language::translate('Your text shows here.'));
        $out = '';
        foreach ($a as $k => $t) {
            $out .= ' data-' . $k . '="' . self::h($t) . '"';
        }
        $icons = '';
        foreach (array('info', 'warning', 'maintenance', 'outage') as $t) {
            $icons .= ' data-ico-' . $t . '="' . self::h(ui_tpl_notice::Icon($t)) . '"';
        }
        return $out . $icons;
    }
}
