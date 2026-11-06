<?php

/**
 * GoAccess module: shared helper for the panel page, the report viewer and the hourly job.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * GoAccess for Sentora (Server Admin): shared code for the panel page, the report
 * viewer and the hourly job.
 *
 * Reports: one for all domains together, one for the panel itself and one per
 * domain or subdomain, built every hour from the access logs into
 * /var/sentora/stats/goaccess/<report>/ (with GoAccess's own history, so a report
 * keeps its past when the log is rotated). GoAccess runs as its own user,
 * sentora-stats, and is given only the new log lines (see the hourly job).
 */
class goaccess_helper
{

    const BIN = '/opt/sentora-goaccess/bin/goaccess';
    const DIR = '/var/sentora/stats/goaccess/';
    // GoAccess runs as this user, which can read nothing else on the server
    const USER = ctrl_statsdomains::STATS_USER;

    /**
     * Check whether GoAccess is installed.
     *
     * @return bool True when the binary exists and is executable.
     */
    static function Installed()
    {
        return is_file(self::BIN) && is_executable(self::BIN);
    }

    /**
     * Check that a report key is safe: 'all', 'panel' or a domain name.
     *
     * @param mixed $key The key (cast to string).
     * @return bool True when valid.
     */
    static function ValidKey($key)
    {
        $key = (string) $key;
        return $key === 'all' || $key === 'panel' || (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $key) && strpos($key, '..') === false);
    }

    /**
     * Return the HTML file of a report.
     *
     * @param string $key The report key.
     * @return string|false Full path, or false for an invalid key.
     */
    static function ReportFile($key)
    {
        return self::ValidKey($key) ? self::DIR . $key . '/report.html' : false;
    }

    /**
     * List every report.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<string, array{0: string, 1: string[], 2: int}> Report key => array(title, log files, owning account ID (0: administrators only)); 'all' and 'panel' first.
     */
    static function Reports()
    {
        global $zdbh;
        $logDir = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/') . '/';
        $reports = array();
        $all = array();
        $sql = $zdbh->prepare("SELECT v.vh_name_vc, a.ac_id_pk, a.ac_user_vc FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk
            WHERE v.vh_deleted_ts IS NULL AND v.vh_type_in IN (1, 2) AND a.ac_deleted_ts IS NULL ORDER BY v.vh_name_vc");
        $sql->execute();
        while ($row = $sql->fetch()) {
            $domain = strtolower((string) $row['vh_name_vc']);
            if (!self::ValidKey($domain) || in_array($domain, array('all', 'panel'), true) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $row['ac_user_vc'])) {
                continue;
            }
            $log = $logDir . 'domains/' . $row['ac_user_vc'] . '/' . $domain . '-access.log';
            $reports[$domain] = array($domain . ' (' . $row['ac_user_vc'] . ')', array($log), (int) $row['ac_id_pk']);
            $all[] = $log;
        }
        $panelLog = $logDir . 'sentora-access.log';
        $panelName = (string) ctrl_options::GetSystemOption('sentora_domain');
        $all[] = $panelLog;
        return array_merge(
            array('all' => array('All domains and the panel', $all, 0),
                'panel' => array('Control panel (' . $panelName . ')', array($panelLog), 0)),
            $reports);
    }

    /**
     * List the reports an account may see.
     *
     * Administrators all of them (including the panel and all-domains reports, which
     * cover every account); everyone else the reports of their own domains only.
     *
     * @param array<string,string>|false|null $user User details from ctrl_users::GetUserDetail().
     * @return array<string, array{0: string, 1: string[], 2: int}> Report key => array(title, log files, owning account ID).
     */
    static function ReportsFor($user)
    {
        $all = self::Reports();
        if (!$user) {
            return array();
        }
        if ((int) $user['usergroupid'] === 1) {
            return $all;
        }
        $mine = array();
        foreach ($all as $key => $report) {
            if ((int) $report[2] > 0 && (int) $report[2] === (int) $user['userid']) {
                $mine[$key] = array($key, $report[1], $report[2]); // their own domain: no account name needed
            }
        }
        return $mine;
    }

}

?>
