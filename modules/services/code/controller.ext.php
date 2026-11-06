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
 * @changes P.Peyremorte
 * - added display of ports checked (80 may be not used!)
 * - refactored (replacement of duplicate string constructions by a fucntion)
 */
/**
 * Services module: controller.
 *
 * Shows whether the hosting services answer on their ports, the server uptime and
 * the daemon's last and next run.
 */
class module_controller extends ctrl_module
{
    /**
     * Build the status icon and text for one local port.
     *
     * @param int $PortNum The TCP port on 127.0.0.1.
     * @param string $iconpath Not used (the icons come from ui_status).
     * @return string HTML: the open/closed icon and "(port N is open|closed)".
     */
    static private function status_port($PortNum, $iconpath)
    {
        $status = sys_monitoring::LocalPortStatus($PortNum);
        return ($status ? ui_status::Ok('Port ' . $PortNum . ' is open') : ui_status::Bad('Port ' . $PortNum . ' is closed')) . ' (port ' . $PortNum .' is ' . ($status ? 'open' : 'closed') . ')';
    }

    /**
     * Template value <@ Services @>: the service status table and server uptime.
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return string The HTML.
     */
    static public function getServices()
    {
        global $controller;
        $iconpath = ''; // the status icons are ui_status (no image files)
        $line = "<h2>" . ui_language::translate("Checking status of services...") . "</h2>";
        $line .= "<table>";
        

        $line .= '<tr><th>HTTP</th><td>'  . module_controller::status_port(80, $iconpath) . '</td></tr>';
		$line .= '<tr><th>HTTPS</th><td>'  . module_controller::status_port(443, $iconpath) . '</td></tr>';
        $line .= '<tr><th>FTP</th><td>'   . module_controller::status_port(21, $iconpath) . '</td></tr>';
        $line .= '<tr><th>DNS</th><td>'   . module_controller::status_port(53, $iconpath)  . '</td></tr>';
        $line .= '<tr><th>SMTP</th><td>'  . module_controller::status_port(25, $iconpath) . '</td></tr>';
        $line .= '<tr><th>POP3</th><td>'  . module_controller::status_port(110, $iconpath) . '</td></tr>';
        $line .= '<tr><th>IMAP</th><td>'  . module_controller::status_port(143, $iconpath) . '</td></tr>';
        $line .= '<tr><th>IMAPS</th><td>' . module_controller::status_port(993, $iconpath) . '</td></tr>';
        $line .= '<tr><th>POP3S</th><td>' . module_controller::status_port(995, $iconpath) . '</td></tr>';
        $line .= '<tr><th>SMTP Submission</th><td>' . module_controller::status_port(587, $iconpath) . '</td></tr>';
        $line .= '<tr><th>MySQL</th><td>' . module_controller::status_port(3306, $iconpath) . '</td></tr>';
        $line .= '</table>';
        $line .= '<br><h2>' . ui_language::translate('Server Uptime') . '</h2>';
        $line .= ui_language::translate('Uptime') . ": " . sys_monitoring::ServerUptime();
        return $line;
    }

    /**
     * Whether the web server answers on port 80.
     *
     * @return bool
     */
    static function getIsHTTPServerUp()
    {
        return sys_monitoring::PortStatus(80);
    }

    /**
     * Whether the web server answers on port 443.
     *
     * @return bool
     */
    static function getIsHTTPSServerUp()
    {
        return sys_monitoring::PortStatus(443);
    }

    /**
     * Whether MySQL answers on port 3306.
     *
     * @return bool
     */
    static function getIsMySQLUp()
    {
        return sys_monitoring::PortStatus(3306);
    }

    /**
     * Whether the FTP server answers on port 21.
     *
     * @return bool
     */
    static function getIsFTPUp()
    {
        return sys_monitoring::PortStatus(21);
    }

    /**
     * Whether the SMTP server answers on port 25.
     *
     * @return bool
     */
    static function getIsSMTPUp()
    {
        return sys_monitoring::PortStatus(25);
    }

    /**
     * Whether the POP3 server answers on port 110.
     *
     * @return bool
     */
    static function getIsPOP3Up()
    {
        return sys_monitoring::PortStatus(110);
    }

    /**
     * Whether the IMAP server answers on port 143.
     *
     * @return bool
     */
    static function getIsIMAPUp()
    {
        return sys_monitoring::PortStatus(143);
    }

    /**
     * The server uptime.
     *
     * @return string Human readable uptime.
     */
    static function getUptime()
    {
        return sys_monitoring::ServerUptime();
    }

    /**
     * Template value <@ LastRunTime @>: when the daemon last ran.
     *
     * @return string The time in the panel's date format (option 'sentora_df').
     */
    static function getLastRunTime()
    {
        return date(ctrl_options::GetSystemOption('sentora_df'), ctrl_options::GetSystemOption('daemon_lastrun'));
    }

    /**
     * Template value <@ NextRunTime @>: when the daemon runs next.
     *
     * @return string The time in the panel's date format (last run + run interval).
     */
    static function getNextRunTime()
    {
        $new_time = ctrl_options::GetSystemOption('daemon_lastrun') + ctrl_options::GetSystemOption('daemon_run_interval');
        return date(ctrl_options::GetSystemOption('sentora_df'), $new_time);
    }

}
