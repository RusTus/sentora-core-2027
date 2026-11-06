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
 */
/**
 * Usage Viewer module: controller.
 *
 * Shows the logged-in user's disk, bandwidth and package usage against the quotas.
 * DisplayUsagepChart() (template <@ Usage @>) fills the static usage figures that the
 * other charts then use.
 */
class module_controller extends ctrl_module
{

    /** @var int|string The disk quota in bytes (0: no quota). Set by DisplayUsagepChart(). */
    static $diskquota;
    /** @var int|string|null Disk space used in bytes. Set by DisplayUsagepChart(). */
    static $diskspace;
    /** @var int|string|null Bandwidth used this month in bytes. Set by DisplayUsagepChart(). */
    static $bandwidth;
    /** @var int|string The bandwidth quota in bytes (0: no quota). Set by DisplayUsagepChart(). */
    static $bandwidthquota;
    /** @var int|string|null Number of domains in use. Set by DisplayUsagepChart(). */
    static $domains;
    /** @var int|string The domains quota (-1: unlimited). Set by DisplayUsagepChart(). */
    static $domainsquota;
    /** @var int|string|null Number of sub-domains in use. Set by DisplayUsagepChart(). */
    static $subdomains;
    /** @var int|string The sub-domains quota (-1: unlimited). Set by DisplayUsagepChart(). */
    static $subdomainsquota;
    /** @var int|string|null Number of parked domains in use. Set by DisplayUsagepChart(). */
    static $parkeddomains;
    /** @var int|string The parked domains quota (-1: unlimited). Set by DisplayUsagepChart(). */
    static $parkeddomainsquota;
    /** @var int|string|null Number of MySQL databases in use. Set by DisplayUsagepChart(). */
    static $mysql;
    /** @var int|string The MySQL databases quota (-1: unlimited). Set by DisplayUsagepChart(). */
    static $mysqlquota;
    /** @var int|string|null Number of FTP accounts in use. Set by DisplayUsagepChart(). */
    static $ftpaccounts;
    /** @var int|string The FTP accounts quota (-1: unlimited). Set by DisplayUsagepChart(). */
    static $ftpaccountsquota;
    /** @var int|string|null Number of mailboxes in use. Set by DisplayUsagepChart(). */
    static $mailboxes;
    /** @var int|string The mailboxes quota (-1: unlimited). Set by DisplayUsagepChart(). */
    static $mailboxquota;
    /** @var int|string|null Number of mail forwarders in use. Set by DisplayUsagepChart(). */
    static $forwarders;
    /** @var int|string The forwarders quota (-1: unlimited). Set by DisplayUsagepChart(). */
    static $forwardersquota;
    /** @var int|string Number of distribution lists in use (0 when none). Set by DisplayUsagepChart(). */
    static $distlists;
    /** @var int|string The distribution lists quota (-1: unlimited). Set by DisplayUsagepChart(). */
    static $distlistsquota;

    /**
     * Return the chart HTML only when the pChart library is installed.
     *
     * @param string $display The chart HTML.
     * @return string The HTML, or "pChart Library Not Found.".
     */
    static private function check_pChart($display)
    {
        return (file_exists('etc/lib/pChart2/pChart/pData.php')) ? $display : 'pChart Library Not Found.';
    }

    /**
     * Template value <@ Usage @>: the disk pie chart and the package usage table.
     *
     * Must run before the other charts: it fills the static usage figures.
     *
     * @return string The chart HTML, or "pChart Library Not Found.".
     */
    static function getUsage()
    {
        return self::check_pChart(self::DisplayUsagepChart());
    }

    /**
     * Template value <@ DomainsUsage @>: the domains usage chart.
     *
     * @return string The chart HTML, or "pChart Library Not Found.".
     */
    static function getDomainsUsage()
    {
        return self::check_pChart(self::DisplayDomainsUsagepChart());
    }

    /**
     * Template value <@ SubDomainsUsage @>: the sub-domains usage chart.
     *
     * @return string The chart HTML, or "pChart Library Not Found.".
     */
    static function getSubDomainsUsage()
    {
        return self::check_pChart(self::DisplaySubDomainsUsagepChart());
    }

    /**
     * Template value <@ ParkedDomainsUsage @>: the parked domains usage chart.
     *
     * @return string The chart HTML, or "pChart Library Not Found.".
     */
    static function getParkedDomainsUsage()
    {
        return self::check_pChart(self::DisplayParkedDomainsUsagepChart());
    }

    /**
     * Template value <@ MysqlUsage @>: the MySQL databases usage chart.
     *
     * @return string The chart HTML, or "pChart Library Not Found.".
     */
    static function getMysqlUsage()
    {
        return self::check_pChart(self::DisplayMysqlUsagepChart());
    }

    /**
     * Template value <@ FTPUsage @>: the FTP accounts usage chart.
     *
     * @return string The chart HTML, or "pChart Library Not Found.".
     */
    static function getFTPUsage()
    {
        return self::check_pChart(self::DisplayFTPUsagepChart());
    }

    /**
     * Template value <@ MailboxUsage @>: the mailboxes usage chart.
     *
     * @return string The chart HTML, or "pChart Library Not Found.".
     */
    static function getMailboxUsage()
    {
        return self::check_pChart(self::DisplayMailboxUsagepChart());
    }

    /**
     * Template value <@ ForwardersUsage @>: the forwarders usage chart.
     *
     * @return string The chart HTML, or "pChart Library Not Found.".
     */
    static function getForwardersUsage()
    {
        return self::check_pChart(self::DisplayForwardersUsagepChart());
    }

    /**
     * Template value <@ DistListUsage @>: the distribution lists usage chart.
     *
     * @return string The chart HTML, or "pChart Library Not Found.".
     */
    static function getDistListUsage()
    {
        return self::check_pChart(self::DisplayDistListUsagepChart());
    }

    //Begin Display Methods

    /**
     * Turn an empty value into 0.
     *
     * @param mixed $value A quota or usage value.
     * @return mixed The value, or 0 when it is empty.
     */
    static function empty_as_0($value)
    {
        return (empty($value)) ? 0 : $value;
    }

    /**
     * Build one row of the package usage table.
     *
     * @param string $name The resource label (translated).
     * @param int|string|float|null $used The amount used.
     * @param int|string $quota The quota (below 0: unlimited).
     * @param bool $human Optional. Show the amounts as file sizes. Default false.
     * @return string The <tr> HTML with a progress bar image.
     */
    static function build_row_usage($name, $used, $quota, $human = false)
    {
        $res = '<tr><th nowrap="nowrap">' . ui_language::translate($name) . ':</th><td nowrap="nowrap">' . (($human) ? fs_director::ShowHumanFileSize($used) : $used);
        if ($quota < 0) {
            $res .= '</td><td style="text-align:center">&#8734; ' . ui_language::translate('Unlimited') . ' &#8734;</td>';
        } else {
            $res .= ' / ' . (($human) ? fs_director::ShowHumanFileSize($quota) : $quota) . '</td><td><img src="etc/lib/pChart2/sentora/zProgress.php?percent=' . (($quota == 0 or $used == $quota) ? 100 : round($used / $quota * 100, 0)) . '"/>';
        }
        return $res . '</td></tr>';
    }

    /**
     * Build the disk usage pie chart and package usage table.
     *
     * Reads the user's quotas and usage into the static properties used by the other charts.
     *
     * @global db_driver $zdbh The panel's database connection (not used).
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function DisplayUsagepChart()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();

        self::$diskquota = $currentuser['diskquota'];
        self::$diskspace = ctrl_users::GetQuotaUsages('diskspace', $currentuser['userid']);

        self::$bandwidthquota = module_controller::empty_as_0($currentuser['bandwidthquota']);
        self::$bandwidth = ctrl_users::GetQuotaUsages('bandwidth', $currentuser['userid']);

        self::$domainsquota = module_controller::empty_as_0($currentuser['domainquota']);
        self::$domains = ctrl_users::GetQuotaUsages('domains', $currentuser['userid']);

        self::$subdomainsquota = module_controller::empty_as_0($currentuser['subdomainquota']);
        self::$subdomains = ctrl_users::GetQuotaUsages('subdomains', $currentuser['userid']);

        self::$parkeddomainsquota = module_controller::empty_as_0($currentuser['parkeddomainquota']);
        self::$parkeddomains = ctrl_users::GetQuotaUsages('parkeddomains', $currentuser['userid']);

        self::$mysqlquota = module_controller::empty_as_0($currentuser['mysqlquota']);
        self::$mysql = ctrl_users::GetQuotaUsages('mysql', $currentuser['userid']);

        self::$ftpaccountsquota = module_controller::empty_as_0($currentuser['ftpaccountsquota']);
        self::$ftpaccounts = ctrl_users::GetQuotaUsages('ftpaccounts', $currentuser['userid']);

        self::$mailboxquota = module_controller::empty_as_0($currentuser['mailboxquota']);
        self::$mailboxes = ctrl_users::GetQuotaUsages('mailboxes', $currentuser['userid']);

        self::$forwardersquota = module_controller::empty_as_0($currentuser['forwardersquota']);
        self::$forwarders = ctrl_users::GetQuotaUsages('forwarders', $currentuser['userid']);

        self::$distlistsquota = $currentuser['distlistsquota'];
        self::$distlists = module_controller::empty_as_0(ctrl_users::GetQuotaUsages('distlists', $currentuser['userid']));

        $maximum = self::$diskquota;
        $used = self::$diskspace;
        if ($maximum == 0) {
            if (sys_versions::ShowOSPlatformVersion() != 'Windows') {
                // We'll specify the full path to the hsoted directory to ensure that NFS mounts etc are taken into account.
                $free = disk_free_space(ctrl_options::GetOption('hosted_dir'));
            } else {
                // On Windows we'll check the disk (partition) that is configured for the 'hostdata' directory.
                $free = disk_free_space(substr(ctrl_options::GetOption('hosted_dir'), 0, 2));
            }
            $freeLabel = fs_director::ShowHumanFileSize($free) . ' (' . ui_language::translate('Server disk') . ')';
        } else {
            $free = max($maximum - $used, 0);
            $freeLabel = fs_director::ShowHumanFileSize($free);
        }
        $usedLabel = fs_director::ShowHumanFileSize($used);


        $line = '<table class="none" cellpadding="0" cellspacing="0">' .
                '<tr>' .
                '<td align="left" valign="top" width="350px">' .
                '<h2>' . ui_language::translate('Disk Usage Total') . '</h2>' .
                '<img src="etc/lib/pChart2/sentora/z3DPie.php?score=' . $free . '::' . $used .
                '&amp;imagesize=350::250&amp;chartsize=150::120&amp;radius=150' .
                '&amp;labels=Free_Space: ' . $freeLabel . '::Used_Space: ' . $usedLabel .
                '&amp;legendfont=verdana&amp;legendfontsize=8&amp;legendsize=10::220"/>' .
                '</td>' .
                '<td align="left" valign="top">' .
                '<h2>' . ui_language::translate('Package Usage Total') . '</h2>' .
                '<table class="table table-striped" border="0" cellspacing="0" cellpadding="0">' .
                module_controller::build_row_usage('Disk space', self::$diskspace, (self::$diskquota == 0) ? -1 : self::$diskquota, true) .
                module_controller::build_row_usage('Bandwidth', self::$bandwidth, (self::$bandwidthquota == 0) ? -1 : self::$bandwidthquota, true) .
                module_controller::build_row_usage('Domains', self::$domains, self::$domainsquota) .
                module_controller::build_row_usage('Sub-domains', self::$subdomains, self::$subdomainsquota) .
                module_controller::build_row_usage('Parked domains', self::$parkeddomains, self::$parkeddomainsquota) .
                module_controller::build_row_usage('FTP accounts', self::$ftpaccounts, self::$ftpaccountsquota) .
                module_controller::build_row_usage('MySQL&reg databases', self::$mysql, self::$mysqlquota) .
                module_controller::build_row_usage('Mailboxes', self::$mailboxes, self::$mailboxquota) .
                module_controller::build_row_usage('Mail forwarders', self::$forwarders, self::$forwardersquota) .
                module_controller::build_row_usage('Distribution lists', self::$distlists, self::$distlistsquota) .
                '</table>' .
                '</td>' .
                '</tr>' .
                '</table>';
        return $line;
    }

    /**
     * Build one usage pie chart with its heading.
     *
     * @global runtime_controller $controller The request controller.
     * @param string $name The heading (translated).
     * @param int|string|null $used The amount used.
     * @param int|string $maximum The quota (below 0: unlimited, shows the unlimited image).
     * @return string The HTML.
     */
    static private function DisplayChart($name, $used, $maximum)
    {
		global $controller;
        if ($maximum < 0) { //-1 = unlimited
            $res = '<img src="modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/unlimited.png" alt="' . ui_language::translate('Unlimited') . '"/>';
        } else {
            $free = max($maximum - $used, 0);
            $res = '<img src="etc/lib/pChart2/sentora/z3DPie.php?score=' . $free . '::' . $used
                    . '&amp;imagesize=240::190&amp;chartsize=120::90&amp;radius=100'
                    . '&amp;labels=Free: ' . $free . '::Used: ' . $used
                    . '&amp;legendfont=verdana&amp;legendfontsize=8&amp;legendsize=10::160"'
                    . ' alt="' . ui_language::translate('Pie chart') . '"/>';
        }
        return '<h2>' . ui_language::translate($name) . '</h2>' . $res;
    }

    /**
     * The domains usage chart.
     *
     * @return string The HTML.
     */
    static function DisplayDomainsUsagepChart()
    {
        return self::DisplayChart('Domain Usage', self::$domains, self::$domainsquota);
    }

    /**
     * The sub-domains usage chart.
     *
     * @return string The HTML.
     */
    static function DisplaySubDomainsUsagepChart()
    {
        return self::DisplayChart('Sub-Domain Usage', self::$subdomains, self::$subdomainsquota);
    }

    /**
     * The parked domains usage chart.
     *
     * @return string The HTML.
     */
    static function DisplayParkedDomainsUsagepChart()
    {
        return self::DisplayChart('Parked-Domain Usage', self::$parkeddomains, self::$parkeddomainsquota);
    }

    /**
     * The MySQL databases usage chart.
     *
     * @return string The HTML.
     */
    static function DisplayMysqlUsagepChart()
    {
        return self::DisplayChart('MySQL&reg Database Usage', self::$mysql, self::$mysqlquota);
    }

    /**
     * The mailboxes usage chart.
     *
     * @return string The HTML.
     */
    static function DisplayMailboxUsagepChart()
    {
        return self::DisplayChart('Mailbox Usage', self::$mailboxes, self::$mailboxquota);
    }

    /**
     * The FTP accounts usage chart.
     *
     * @return string The HTML.
     */
    static function DisplayFTPUsagepChart()
    {
        return self::DisplayChart('FTP Usage', self::$ftpaccounts, self::$ftpaccountsquota);
    }

    /**
     * The forwarders usage chart.
     *
     * @return string The HTML.
     */
    static function DisplayForwardersUsagepChart()
    {
        return self::DisplayChart('Forwarders Usage', self::$forwarders, self::$forwardersquota);
    }

    /**
     * The distribution lists usage chart.
     *
     * @return string The HTML.
     */
    static function DisplayDistListUsagepChart()
    {
        return self::DisplayChart('Distribution List Usage', self::$distlists, self::$distlistsquota);
    }

    /**
     * Build a progress bar image for one resource of the logged-in user.
     *
     * Not called anywhere in the panel.
     *
     * @param string $total The resource name for ctrl_users::GetQuotaUsages() (e.g. 'domains').
     * @param string $quota The quota key in the user details (e.g. 'domainquota').
     * @return string The <img> HTML, or '' when the quota is 0.
     */
    static function DisplaypBar($total, $quota)
    {
        $currentuser = ctrl_users::GetUserDetail();
        $typequota = $currentuser[$quota];
        $type = ctrl_users::GetQuotaUsages($total, $currentuser['userid']);
        if ($typequota == 0)
            return ''; //Quota are disabled
        if (fs_director::CheckForEmptyValue($type))
            return '<img src="etc/lib/pChart2/sentora/zProgress.php?percent=0"/>';
        if ($type == $typequota)
            return '<img src="etc/lib/pChart2/sentora/zProgress.php?percent=100"/>';
        return '<img src="etc/lib/pChart2/sentora/zProgress.php?percent=' . round($type / $typequota * 100, 0) . '"/>';
    }

}
