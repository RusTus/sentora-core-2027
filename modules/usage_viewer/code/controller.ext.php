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
 * @change P.Peyremorte added unlimited, factorization in functions
 *
 * Updated by RusTus, 2026-11-06
 */
/**
 * Usage Viewer: the account's use of its package (disk, bandwidth, domains, mail ...),
 * drawn in the page in the theme's colours (it used pChart images before).
 */
class module_controller extends ctrl_module
{

    /**
     * Escape text for HTML.
     *
     * @param mixed $s The text.
     * @return string
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translated and HTML-escaped text.
     *
     * @param string $s The English text.
     * @return string
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * An inline SVG icon (stroked, in the text colour).
     *
     * @param string $name The icon: disk, wave, globe, sub, park, ftp, db, mail, forward, list.
     * @return string SVG markup.
     */
    static function Icon($name)
    {
        $p = array(
            'disk' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
            'wave' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
            'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18"/>',
            'sub' => '<path d="M6 3v12a3 3 0 0 0 3 3h9"/><path d="m15 15 3 3-3 3"/>',
            'park' => '<path d="M5 21V4h8a5 5 0 0 1 0 10H5"/>',
            'ftp' => '<path d="M20 15v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/><path d="m16 8-4-4-4 4"/><path d="M12 4v11"/>',
            'db' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
            'forward' => '<path d="m15 6 6 6-6 6"/><path d="M21 12H9a6 6 0 0 0-6 6"/>',
            'list' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/>',
        );
        return '<svg class="uv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$name] . '</svg>';
    }

    /**
     * Whether the account may open a module (to link to it).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array<string, mixed> $user The logged-in account.
     * @param string $folder The module's folder.
     * @return bool
     */
    static function CanOpen($user, $folder)
    {
        global $zdbh;
        static $ids = null;
        if ($ids === null) {
            $ids = array();
            foreach ($zdbh->query("SELECT mo_id_pk, mo_folder_vc FROM x_modules WHERE mo_enabled_en = 'true'")->fetchAll() as $r) {
                $ids[$r['mo_folder_vc']] = (int) $r['mo_id_pk'];
            }
        }
        return isset($ids[$folder]) && ctrl_groups::CheckGroupModulePermissions($user['usergroupid'], $ids[$folder]);
    }

    /**
     * Treat an empty value as 0.
     *
     * @param mixed $value The value.
     * @return mixed 0 when empty, else the value.
     */
    static function empty_as_0($value)
    {
        return (empty($value)) ? 0 : $value;
    }

    /**
     * The account's use of each resource and its limit.
     *
     * Disk and bandwidth: 0 in the package means no limit (here -1). The others: -1 means no limit.
     *
     * @return array<string, array{used: int|float, max: int|float}> Keyed by resource.
     */
    static function Figures()
    {
        static $f = null;
        if ($f !== null) {
            return $f;
        }
        $u = ctrl_users::GetUserDetail();
        $id = $u['userid'];
        $q = function ($k) use ($id) { return (float) self::empty_as_0(ctrl_users::GetQuotaUsages($k, $id)); };
        $f = array(
            'disk' => array('used' => $q('diskspace'), 'max' => (float) $u['diskquota'] == 0 ? -1 : (float) $u['diskquota']),
            'bandwidth' => array('used' => $q('bandwidth'), 'max' => (float) self::empty_as_0($u['bandwidthquota']) == 0 ? -1 : (float) $u['bandwidthquota']),
            'domains' => array('used' => $q('domains'), 'max' => (int) self::empty_as_0($u['domainquota'])),
            'subdomains' => array('used' => $q('subdomains'), 'max' => (int) self::empty_as_0($u['subdomainquota'])),
            'parkeddomains' => array('used' => $q('parkeddomains'), 'max' => (int) self::empty_as_0($u['parkeddomainquota'])),
            'ftpaccounts' => array('used' => $q('ftpaccounts'), 'max' => (int) self::empty_as_0($u['ftpaccountsquota'])),
            'mysql' => array('used' => $q('mysql'), 'max' => (int) self::empty_as_0($u['mysqlquota'])),
            'mailboxes' => array('used' => $q('mailboxes'), 'max' => (int) self::empty_as_0($u['mailboxquota'])),
            'forwarders' => array('used' => $q('forwarders'), 'max' => (int) self::empty_as_0($u['forwardersquota'])),
            'distlists' => array('used' => $q('distlists'), 'max' => (int) self::empty_as_0($u['distlistsquota'])),
        );
        return $f;
    }

    /**
     * How full, as a percentage, and its colour level.
     *
     * @param int|float $used Used.
     * @param int|float $max The limit (negative: none).
     * @return array{0: float, 1: string} Percentage (0 when there is no limit) and 'ok', 'mid', 'high' or 'none'.
     */
    static function Level($used, $max)
    {
        if ($max < 0) {
            return array(0, 'none');
        }
        $pct = $max == 0 ? 100 : min(100, $used / $max * 100);
        return array($pct, $pct >= 90 ? 'high' : ($pct >= 75 ? 'mid' : 'ok'));
    }

    /**
     * A ring chart (SVG) filled to a percentage.
     *
     * @param float $pct 0 to 100.
     * @param string $level 'ok', 'mid', 'high' or 'none'.
     * @param string $centre HTML in the middle.
     * @return string HTML.
     */
    static function Ring($pct, $level, $centre)
    {
        $r = 52;
        $c = 2 * M_PI * $r;
        $dash = $level === 'none' ? 0 : round($c * $pct / 100, 1);
        return '<div class="uv-ring lv-' . $level . '"><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="uv-track" cx="60" cy="60" r="' . $r . '"/>'
            . ($dash > 0 ? '<circle class="uv-fill" cx="60" cy="60" r="' . $r . '" stroke-dasharray="' . $dash . ' ' . round($c, 1) . '" transform="rotate(-90 60 60)"/>' : '')   // nothing to fill: no dot
            . '</svg><div class="uv-centre">' . $centre . '</div></div>';
    }

    /**
     * Template HTML: disk and bandwidth, each with a ring chart.
     *
     * When the disk has no limit, its ring shows the account's share of the server's disk.
     *
     * @return string HTML.
     */
    static function getStorage()
    {
        $f = self::Figures();
        $out = '';
        foreach (array('disk' => array('disk', 'Disk space', '#447fb3'), 'bandwidth' => array('wave', 'Bandwidth this month', '#1fa2b5')) as $k => $m) {
            $used = $f[$k]['used'];
            $max = $f[$k]['max'];
            list($pct, $lv) = self::Level($used, $max);
            if ($max < 0) {
                $centre = '<b>' . self::h(fs_director::ShowHumanFileSize($used)) . '</b><span>' . self::t('used') . '</span>';
                if ($k === 'disk') {
                    $free = (float) @disk_free_space(rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') ?: '/');
                    $detail = self::t('No limit in your package.') . ($free > 0 ? ' ' . sprintf(self::t('%s free on the server.'), self::h(fs_director::ShowHumanFileSize($free))) : '');
                } else {
                    $detail = self::t('No limit in your package.');
                }
            } else {
                $centre = '<b>' . round($pct) . '%</b><span>' . self::t('used') . '</span>';
                $detail = sprintf(self::t('%1$s of %2$s used, %3$s left.'), '<b>' . self::h(fs_director::ShowHumanFileSize($used)) . '</b>', '<b>' . self::h(fs_director::ShowHumanFileSize($max)) . '</b>',
                    self::h(fs_director::ShowHumanFileSize(max(0, $max - $used))));
            }
            $out .= '<div class="uv-card uv-big" style="--c:' . $m[2] . '">' . self::Ring($pct, $lv, $centre) . '<div><h4>' . self::Icon($m[0]) . self::t($m[1]) . '</h4><p>' . $detail . '</p>'
                . ($k === 'bandwidth' ? '<p class="uv-sub">' . self::t('Counted from the first of the month; it starts again then.') . '</p>' : '')
                . ($lv === 'high' ? '<p class="uv-warn">' . self::t('Nearly full: free some space, or ask about a larger package.') . '</p>' : '') . '</div></div>';
        }
        return $out;
    }

    /**
     * Template HTML: one card per countable resource (domains, mailboxes ...), with a bar and a link to manage it.
     *
     * @return string HTML.
     */
    static function getResources()
    {
        $u = ctrl_users::GetUserDetail();
        $f = self::Figures();
        $list = array(
            'domains' => array('globe', 'Domains', 'domains'), 'subdomains' => array('sub', 'Subdomains', 'sub_domains'), 'parkeddomains' => array('park', 'Parked domains', 'parked_domains'),
            'mailboxes' => array('mail', 'Mailboxes', 'mailboxes'), 'forwarders' => array('forward', 'Mail forwarders', 'forwarders'), 'distlists' => array('list', 'Distribution lists', 'distlists'),
            'mysql' => array('db', 'MySQL databases', 'mysql_databases'), 'ftpaccounts' => array('ftp', 'FTP accounts', 'ftp_management'),
        );
        $out = '';
        foreach ($list as $k => $m) {
            $used = (int) $f[$k]['used'];
            $max = (int) $f[$k]['max'];
            list($pct, $lv) = self::Level($used, $max);
            if ($max < 0) {
                $count = $used . ' <span>' . self::t('of no limit') . '</span>';
                $foot = self::t('No limit');
            } elseif ($max === 0) {
                $count = $used . ' <span>' . self::t('of 0') . '</span>';
                $foot = self::t('Not in your package');
                $lv = 'off';
            } else {
                $count = $used . ' <span>' . sprintf(self::t('of %d'), $max) . '</span>';
                $foot = $used >= $max ? self::t('All used') : sprintf(self::t('%d left'), $max - $used);
            }
            $link = self::CanOpen($u, $m[2]) ? '<a class="uv-manage" href="./?module=' . $m[2] . '">' . self::t('Manage') . ' &rarr;</a>' : '';
            $out .= '<div class="uv-card uv-res lv-' . $lv . '"><div class="uv-res-head">' . self::Icon($m[0]) . '<h4>' . self::t($m[1]) . '</h4></div>'
                . '<div class="uv-count">' . $count . '</div>'
                . '<div class="uv-bar"><span style="width: ' . ($lv === 'none' || $lv === 'off' ? 0 : round($pct, 1)) . '%"></span></div>'
                . '<div class="uv-res-foot"><span>' . $foot . '</span>' . $link . '</div></div>';
        }
        return $out;
    }

    /**
     * Template value: the package name.
     *
     * @return string HTML-escaped.
     */
    static function getPackageName()
    {
        $u = ctrl_users::GetUserDetail();
        return self::h($u['packagename']);
    }
}
