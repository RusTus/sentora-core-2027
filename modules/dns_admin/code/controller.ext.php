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
 * Updated by RusTus, 2026-11-12
 */
/**
 * DNS Config module (Admin): DNS settings, BIND service control, record tools and BIND logs.
 *
 * Changes to zones are written by the daemon (dns_manager hooks): most actions
 * here only update x_dns / x_settings and mark the zones for an update.
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True after a domain's records were reset (read by getResult()). */
    static $ok;
    /** @var mixed Unused. */
    static $service;
    /** @var int|null Number of deleted records purged from the database by Purge(). */
    static $purged;
    /** @var int|null Number of records marked as deleted by DeleteAll() or DeleteDomainRecords(). */
    static $deleted;
    /** @var int|null Number of records of one type marked as deleted by DeleteType(). */
    static $deletedtype;
    /** @var string|null The record type DeleteType() deleted (from the inType form field). */
    static $type;
    /** @var int|null Number of domains whose records ResetAll() reset to the defaults. */
    static $reset;
    /** @var int|null Number of domains AddMissing() created default records for. */
    static $addmissing;
    /** @var bool|null True when the "View logs" button was pressed (set, not read, here). */
    static $showlog;
    /** @var bool|null True when the BIND log could not be written (shown by getResult()). */
    static $notwritable;
    /** @var bool|null True after "force update" marked all zones for the next daemon run. */
    static $forceupdate;

    /**
     * The whole DNS Config page body (template <@ DNSConfig @>).
     *
     * @return string The HTML (see DisplayDNSConfig()).
     */
    static function getDNSConfig()
    {
        $display = self::DisplayDNSConfig();
        return $display;
    }

    /**
     * Build the DNS Config page: tabs for settings, tools, the BIND service, logs and the usage charts.
     *
     * Counts the BIND log's error and warning lines (LogCounts()).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function DisplayDNSConfig()
    {
        global $zdbh;
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $t = function ($s) use ($h) { return $h(ui_language::translate($s)); };
        $ico = function ($d) { return '<svg class="dn-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>'; };

        // what there is: BIND, the records by type, the domains, the log
        $up = !fs_director::CheckForEmptyValue(sys_monitoring::PortStatus(53));
        $types = array('A' => 0, 'AAAA' => 0, 'CNAME' => 0, 'MX' => 0, 'TXT' => 0, 'SRV' => 0, 'SPF' => 0, 'NS' => 0);
        $active = 0;
        foreach ($zdbh->query("SELECT dn_type_vc, COUNT(*) AS n FROM x_dns WHERE dn_deleted_ts IS NULL GROUP BY dn_type_vc")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = strtoupper((string) $r['dn_type_vc']);
            $types[$k] = (isset($types[$k]) ? $types[$k] : 0) + (int) $r['n'];
            $active += (int) $r['n'];
        }
        $deleted = (int) $zdbh->query("SELECT COUNT(*) FROM x_dns WHERE dn_deleted_ts IS NOT NULL")->fetchColumn();
        $domains = (int) $zdbh->query("SELECT COUNT(*) FROM x_vhosts WHERE vh_deleted_ts IS NULL")->fetchColumn();
        $without = (int) $zdbh->query("SELECT COUNT(*) FROM x_vhosts v WHERE v.vh_deleted_ts IS NULL AND NOT EXISTS (SELECT 1 FROM x_dns d WHERE d.dn_vhost_fk = v.vh_id_pk AND d.dn_deleted_ts IS NULL)")->fetchColumn();
        list($nErr, $nWarn, $nLog) = self::LogCounts();

        $line = '<link rel="stylesheet" href="modules/dns_admin/assets/dns.css">';
        $line .= '<div class="dn">';
        // the tiles
        $line .= '<div class="dn-tiles">'
            . '<div class="dn-tile ' . ($up ? 'ok' : 'bad') . '">' . $ico($up ? '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>' : '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>')
            . '<div><b>' . $t($up ? 'Running' : 'Not answering') . '</b><span>' . $t('DNS (BIND), port 53') . '</span></div></div>'
            . '<div class="dn-tile">' . $ico('<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>')
            . '<div><b>' . number_format($active) . ' ' . $t('records') . '</b><span>' . str_replace(array('%1$d', '%2$d'), array($domains, $without), $t('%1$d domains, %2$d without records')) . '</span></div></div>'
            . '<div class="dn-tile' . ($nErr ? ' bad' : ($nWarn ? ' warn' : ' ok')) . '">' . $ico('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>')
            . '<div><b>' . str_replace('%d', (string) $nErr, $t($nErr === 1 ? '%d error' : '%d errors')) . ', ' . str_replace('%d', (string) $nWarn, $t($nWarn === 1 ? '%d warning' : '%d warnings')) . '</b><span>' . str_replace('%d', (string) $nLog, $t('BIND\'s log: %d lines')) . '</span></div></div>'
            . '</div>';

        $line .= '<div class="dn-cols"><div class="sf dn-main">';
        $line .= '<div class="sf-tabs" role="tablist">'
            . '<button type="button" class="sf-tab" data-pane="general">' . $t('Settings') . '</button>'
            . '<button type="button" class="sf-tab" data-pane="tools">' . $t('Record tools') . '</button>'
            . '<button type="button" class="sf-tab" data-pane="services">' . $t('Service') . '</button>'
            . '<button type="button" class="sf-tab" data-pane="logs">' . $t('Log') . '</button></div>';

        // Settings: the module's settings, each with its explanation
        $line .= '<div class="sf-pane" id="general"><form action="./?module=dns_admin&amp;action=UpdateDNSConfig" method="post">';
        $sql = $zdbh->prepare("SELECT * FROM x_settings WHERE so_module_vc = :m AND so_usereditable_en = 'true' ORDER BY so_cleanname_vc");
        $sql->execute(array(':m' => ui_module::GetModuleName()));
        $n = 0;
        while ($row = $sql->fetch()) {
            $n++;
            $field = ctrl_options::CheckForPredefinedOptions($row['so_defvalues_tx'])
                ? ctrl_options::OuputSettingMenuField($row['so_name_vc'], $row['so_defvalues_tx'], $row['so_value_tx'])
                : ctrl_options::OutputSettingTextArea($row['so_name_vc'], $row['so_value_tx']);
            $line .= '<div class="sf-row"><div class="sf-what"><label>' . $t($row['so_cleanname_vc']) . '</label><small>' . $t($row['so_desc_tx']) . '</small></div><div class="sf-ctl">' . $field . '</div></div>';
        }
        $line .= ($n ? '<div class="dn-save"><button class="btn btn-primary" type="submit" name="inSaveSystem">' . $t('Save settings') . '</button><span class="sf-hint">' . $t('The zones are written again within 5 minutes.') . '</span></div>' : '<p class="sf-hint">' . $t('No settings.') . '</p>')
            . '</form></div>';

        // Record tools: the button first, then what it does
        $opts = '<option value="">' . $t('Choose a domain') . '</option>';
        foreach ($zdbh->query("SELECT vh_id_pk, vh_name_vc FROM x_vhosts WHERE vh_deleted_ts IS NULL ORDER BY vh_name_vc")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $opts .= '<option value="' . (int) $row['vh_id_pk'] . '">' . $h($row['vh_name_vc']) . '</option>';
        }
        $typeOpts = '';
        foreach (array_keys($types) as $ty) {
            $typeOpts .= '<option value="' . $ty . '">' . $ty . ' (' . (int) $types[$ty] . ')</option>';
        }
        $tool = function ($name, $btn, $title, $what, $ctl = '', $danger = false, $confirm = '') use ($t) {
            return '<div class="dn-act' . ($danger ? ' is-danger' : '') . '"><button class="btn ' . ($danger ? 'btn-danger' : 'btn-primary') . ' btn-sm" type="submit" name="' . $name . '" value="1"'
                . ($confirm !== '' ? ' data-confirm="' . $t($confirm) . '"' : '') . '>' . $t($btn) . '</button>'
                . '<div class="dn-act-what"><b>' . $t($title) . '</b><small>' . $t($what) . '</small>' . ($ctl !== '' ? '<div class="dn-act-ctl">' . $ctl . '</div>' : '') . '</div></div>';
        };
        $line .= '<div class="sf-pane" id="tools" hidden><form action="./?module=dns_admin&amp;action=UpdateTools" method="post"><div class="dn-acts">'
            . $tool('inAddMissing', 'Add', 'Add default records to domains without any', 'Each domain that has no DNS records gets Sentora\'s default set (A, MX, SPF, NS and the like). Domains with records are not touched.')
            . $tool('inResetDomain', 'Reset', 'Reset one domain to the default records', 'Its records are replaced by the default set: records added by hand to that domain are lost.', '<select class="form-control input-sm" name="inResetDomainID">' . $opts . '</select>', false, 'Replace this domain\'s records with the default set? Records added by hand are lost.')
            . $tool('inForceUpdate', 'Write', 'Write every zone again', 'The daemon writes all zone files again and reloads BIND within 5 minutes. Nothing in the records changes.')
            . $tool('inPurge', 'Purge', 'Remove deleted records from the database', str_replace('%d', (string) $deleted, ui_language::translate('%d deleted record(s) wait here; they are already gone from the zones.')))
            . '<p class="dn-danger-head">' . $t('Careful: these change every domain') . '</p>'
            . $tool('inResetAll', 'Reset all', 'Reset every domain to the default records', 'Every domain\'s records are replaced by the default set: every record added by hand is lost.', '', true, 'Replace the records of EVERY domain with the default set? Every record added by hand is lost.')
            . $tool('inDeleteType', 'Delete', 'Delete one record type from every domain', 'Every record of the chosen type is deleted, on every domain.', '<select class="form-control input-sm" name="inType" id="inType">' . $typeOpts . '</select>', true, 'Delete every record of this type, on every domain?')
            . $tool('inDeleteAll', 'Delete all', 'Delete every record of every domain', 'Every zone is emptied: no domain answers in DNS until records are added again.', '', true, 'Delete EVERY DNS record of EVERY domain? No domain will answer in DNS until records are added again.')
            . '</div></form></div>';

        // Service
        $line .= '<div class="sf-pane" id="services" hidden><form action="./?module=dns_admin&amp;action=UpdateService" method="post">'
            . '<div class="dn-svc"><span class="dn-pill ' . ($up ? 'ok' : 'bad') . '"><i></i>' . $t($up ? 'BIND is running: port 53 answers' : 'BIND is not answering on port 53') . '</span></div>'
            . '<div class="dn-acts">'
            . $tool('inReloadService', 'Reload', 'Reload BIND', 'BIND reads its zone files again, without stopping. Use it after a change that did not show.')
            . $tool('inStartService', 'Start', 'Start BIND', 'Starts the DNS server when it is stopped.')
            . $tool('inStopService', 'Stop', 'Stop BIND', 'Stops the DNS server: no domain on this server answers in DNS until it is started again.', '', true, 'Stop the DNS server? No domain on this server answers in DNS until it is started again.')
            . '</div></form></div>';

        // Log: view (a window of its own) and clear, side by side for each kind
        $line .= '<div class="sf-pane" id="logs" hidden>'
            . '<form id="dnLogView" name="launchbindlog" action="modules/dns_admin/code/getbindlog.php" target="bindlogwindow" method="post" onsubmit="window.open(\'\', \'bindlogwindow\', \'scrollbars=yes,menubar=no,height=525,width=825,resizable=yes,toolbar=no,location=no,status=no\')"></form>'
            . '<form id="dnLogClear" action="./?module=dns_admin&amp;action=Updatelogs" method="post"></form>'
            . '<div class="dn-acts">';
        foreach (array(array('Errors', 'inViewErrors', 'inClearErrors', $nErr, 'The lines where BIND could not do something (a zone it could not load, for example).'),
                       array('Warnings', 'inViewWarnings', 'inClearWarnings', $nWarn, 'Lines BIND warns about; often harmless.'),
                       array('The whole log', 'inViewLogs', 'inClearLogs', $nLog, 'Everything BIND wrote.')) as $l) {
            $line .= '<div class="dn-act"><span class="dn-btns"><button class="btn btn-primary btn-sm" type="submit" form="dnLogView" name="' . $l[1] . '" value="1">' . $t('View') . '</button>'
                . '<button class="btn btn-default btn-sm" type="submit" form="dnLogClear" name="' . $l[2] . '" value="1" data-confirm="' . $t('Clear these lines from BIND\'s log?') . '">' . $t('Clear') . '</button></span>'
                . '<div class="dn-act-what"><b>' . $t($l[0]) . ' <span class="dn-count' . ($l[3] && $l[1] === 'inViewErrors' ? ' bad' : '') . '">' . (int) $l[3] . '</span></b><small>' . $t($l[4]) . '</small></div></div>';
        }
        $line .= '</div></div>';
        $line .= '</div>';

        // the side: the records as a ring, by type
        $colors = array('A' => '#447fb3', 'AAAA' => '#7c5cc4', 'CNAME' => '#1fa2b5', 'MX' => '#e08a1e', 'TXT' => '#2bb673', 'SRV' => '#d6457a', 'SPF' => '#8a9a5b', 'NS' => '#6b7785');
        $total = max(1, $active);
        $r = 52;
        $c = 2 * M_PI * $r;
        $off = 0.0;
        $arcs = '';
        $legend = '';
        foreach ($types as $ty => $cnt) {
            if ($cnt > 0) {
                $len = $c * $cnt / $total;
                $arcs .= '<circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="' . (isset($colors[$ty]) ? $colors[$ty] : '#999') . '" stroke-width="16" stroke-dasharray="' . round(max(0, $len - 2), 2) . ' ' . round($c, 2) . '" stroke-dashoffset="' . round(-$off, 2) . '" transform="rotate(-90 70 70)"><title>' . $ty . ': ' . (int) $cnt . '</title></circle>';
                $off += $len;
            }
            $legend .= '<li' . ($cnt ? '' : ' class="none"') . '><i style="background:' . (isset($colors[$ty]) ? $colors[$ty] : '#999') . '"></i>' . $ty . '<b>' . (int) $cnt . '</b></li>';
        }
        $line .= '<aside class="dn-box"><h4>' . $t('Records') . '</h4>'
            . '<div class="dn-ring"><svg viewBox="0 0 140 140" role="img" aria-label="' . $t('Records by type') . '"><circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="var(--sn-line, #e6e9ee)" stroke-width="16"/>' . $arcs . '</svg>'
            . '<div class="dn-ring-mid"><b>' . number_format($active) . '</b><span>' . $t('records') . '</span></div></div>'
            . '<ul class="dn-legend">' . $legend . '</ul>'
            . ($deleted ? '<p class="dn-note">' . str_replace('%d', (string) $deleted, $t('%d deleted record(s) still in the database: Record tools > Purge removes them.')) . '</p>' : '')
            . '</aside>';
        $line .= '</div></div>';
        return $line;
    }

    /**
     * Save the posted DNS settings (empty values are skipped) and mark all zones for an update.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doUpdateDNSConfig()
    {
        global $zdbh;
        global $controller;
        $sql = "SELECT COUNT(*) FROM x_settings WHERE so_module_vc=:moduleName AND so_usereditable_en = 'true'";
        $numrows = $zdbh->prepare($sql);
        $GetModuleName = ui_module::GetModuleName();
        $numrows->bindParam(':moduleName', $GetModuleName);

        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_settings WHERE so_module_vc=:moduleName AND so_usereditable_en = 'true'");
                $GetModuleName = ui_module::GetModuleName();
                $sql->bindParam(':moduleName', $GetModuleName);
                $sql->execute();
                while ($row = $sql->fetch()) {
                    if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', $row['so_name_vc']))) {
                        $name = $controller->GetControllerRequest('FORM', $row['so_name_vc']);
                        $name2 = $row['so_name_vc'];
                        $updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :name WHERE so_name_vc = :name2");
                        $updatesql->bindParam(':name', $name);
                        $updatesql->bindParam(':name2', $name2);
                        $updatesql->execute();
                        self::TriggerDNSUpdate("0");
                    }
                }
            }
        }
    }

    /**
     * Start, stop or reload BIND, depending on which button was posted.
     *
     * @global db_driver $zdbh The panel's database connection (unused).
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doUpdateService()
    {
        global $zdbh;
        global $controller;
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inStartService'))) {
            self::StartBind();
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inStopService'))) {
            self::StopBind();
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inReloadService'))) {
            self::ReloadBind();
        }
    }

    /**
     * Run the record tool whose button was posted (reset, add missing, delete by type, purge, delete all, force update).
     *
     * Each tool (except purge) also marks all zones for an update by the daemon.
     *
     * @global db_driver $zdbh The panel's database connection (unused).
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doUpdateTools()
    {
        global $zdbh;
        global $controller;
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inResetAll'))) {
            self::ResetAll();
            self::TriggerDNSUpdate("0");
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inResetDomain')) && !fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inResetDomainID'))) {
            self::ResetDomain($controller->GetControllerRequest('FORM', 'inResetDomainID'));
            self::TriggerDNSUpdate("0");
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inAddMissing'))) {
            self::AddMissing();
            self::TriggerDNSUpdate("0");
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDeleteType'))) {
            self::DeleteType();
            self::TriggerDNSUpdate("0");
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inPurge'))) {
            self::Purge();
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDeleteAll'))) {
            self::DeleteAll();
            self::TriggerDNSUpdate("0");
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inForceUpdate'))) {
            self::$forceupdate = true;
            self::TriggerDNSUpdate("0");
        }
    }

    /**
     * Clear the errors, the warnings or the whole BIND log, or show the log, depending on the button posted.
     *
     * @global db_driver $zdbh The panel's database connection (unused).
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doUpdateLogs()
    {
        global $zdbh;
        global $controller;
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inClearErrors'))) {
            self::ClearErrors();
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inClearWarnings'))) {
            self::ClearWarnings();
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inClearLogs'))) {
            self::ClearLog();
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inViewLogs'))) {
            //self::ViewLogs();
            self::$showlog = TRUE;
        }
    }

    /**
     * Start the BIND service (through zsudo on Linux; waits 2 seconds).
     *
     * @return void
     */
    static function StartBind()
    {
        if (sys_versions::ShowOSPlatformVersion() == "Windows") {
            /** @todo this needs changing to use the system command */
            exec('net start ' . ctrl_options::GetSystemOption('bind_service') . '', $out);
        } else {
            $out = ctrl_system::systemCommand(ctrl_options::GetSystemOption('zsudo'), array('service', ctrl_options::GetSystemOption('bind_service'), 'start'));
            sleep(2);
        }
    }

    /**
     * Stop the BIND service (through zsudo on Linux; waits 2 seconds).
     *
     * @return void
     */
    static function StopBind()
    {
        if (sys_versions::ShowOSPlatformVersion() == "Windows") {
            /** @todo this needs changing to use the system command */
            exec('net stop ' . ctrl_options::GetSystemOption('bind_service') . '', $out);
        } else {
            $out = ctrl_system::systemCommand(ctrl_options::GetSystemOption('zsudo'), array('service', ctrl_options::GetSystemOption('bind_service'), 'stop'));
            sleep(2);
        }
    }

    /**
     * Reload the BIND service (rndc on Windows, the service command through zsudo elsewhere).
     *
     * @return void
     */
    static function ReloadBind()
    {
        if (sys_versions::ShowOSPlatformVersion() == "Windows") {
            /** @todo this needs changing to use the system command */
            $reload_bind = ctrl_options::GetSystemOption('bind_dir') . 'rndc.exe reload';
            pclose(popen($reload_bind, 'r'));
        } else {
            $out = ctrl_system::systemCommand(ctrl_options::GetSystemOption('zsudo'), array('service', ctrl_options::GetSystemOption('bind_service'), 'reload'));
        }
    }

    /**
     * Replace the records of every domain that has records with the default records.
     *
     * Sets self::$reset to the number of domains reset.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (unused).
     * @return void
     */
    static function ResetAll()
    {
        global $zdbh;
        global $controller;
        $vhosts = array();
        $numrecords = 0;
        //Get a list of current domains with records
        $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_deleted_ts IS NULL";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_deleted_ts IS NULL GROUP BY dn_vhost_fk");
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $vhosts[] = $row['dn_vhost_fk'];
                    $numrecords++;
                }
            }
        }
        self::$reset = $numrecords;
        //Delete current records
        self::DeleteAll();
        //Create Default Records
        foreach ($vhosts as $vhost) {
            self::CreateDefaultRecords($vhost);
        }
    }

    /**
     * Replace the records of one domain with the default records.
     *
     * @global db_driver $zdbh The panel's database connection (unused).
     * @param int|string $dn_vhost_fk The domain (x_vhosts) ID, from the inResetDomainID form field.
     * @return void
     */
    static function ResetDomain($dn_vhost_fk)
    {
        global $zdbh;
        //Delete current records
        self::DeleteDomainRecords($dn_vhost_fk);
        //Create Default Records
        self::CreateDefaultRecords($dn_vhost_fk);
        self::$ok = true;
    }

    /**
     * Create the default records for every domain that has none.
     *
     * Sets self::$addmissing to the number of domains done.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (unused).
     * @return void
     */
    static function AddMissing()
    {
        global $zdbh;
        global $controller;
        $vhosts = array();
        $numrecords = 0;
        $sql = "SELECT COUNT(*) FROM x_vhosts WHERE vh_deleted_ts IS NULL";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_deleted_ts IS NULL");
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $vhosts[] = $row['vh_id_pk'];
                }
            }
        }
        if (!fs_director::CheckForEmptyValue($vhosts)) {
            foreach ($vhosts as $vhost) {
                $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_vhost_fk = :vhost AND dn_deleted_ts IS NULL";
                $numrows = $zdbh->prepare($sql);
                $numrows->bindParam(':vhost', $vhost);
                if ($numrows->execute()) {
                    if ($numrows->fetchColumn() == 0) {
                        self::CreateDefaultRecords($vhost);
                        $numrecords++;
                    }
                }
            }
            self::$addmissing = $numrecords;
        }
    }

    /**
     * Mark every record of the posted type (inType) as deleted.
     *
     * Sets self::$deletedtype and self::$type.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function DeleteType()
    {
        global $zdbh;
        global $controller;
        $numrecords = 0;
        $type = $controller->GetControllerRequest('FORM', 'inType');
        $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_type_vc = :type AND dn_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':type', $type);


        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                $type = $controller->GetControllerRequest('FORM', 'inType');
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_type_vc = :type AND dn_deleted_ts IS NULL");
                $sql->bindParam(':type', $type);
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $time = time();
                    $type = $controller->GetControllerRequest('FORM', 'inType');
                    $delete_record = $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=:time WHERE dn_id_pk = :dn_id_pk AND dn_type_vc = :type");
                    $delete_record->bindParam(':time', $time);
                    $delete_record->bindParam(':dn_id_pk', $row['dn_id_pk']);
                    $delete_record->bindParam(':type', $type);
                    $delete_record->execute();
                    $numrecords++;
                }
                self::$deletedtype = $numrecords;
                self::$type = $controller->GetControllerRequest('FORM', 'inType');
            }
        }
    }

    /**
     * Remove the records marked as deleted from the database for good.
     *
     * Sets self::$purged to the number removed.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (unused).
     * @return void
     */
    static function Purge()
    {
        global $zdbh;
        global $controller;
        $numrecords = 0;
        $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_deleted_ts IS NOT NULL";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_deleted_ts IS NOT NULL");
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $delete_record = $zdbh->prepare("DELETE FROM x_dns WHERE dn_id_pk = :dn_id_pk");
                    $delete_record->bindParam(':dn_id_pk', $row['dn_id_pk']);
                    $delete_record->execute();

                    $numrecords++;
                }
                self::$purged = $numrecords;
            }
        }
    }

    /**
     * Mark every record of every domain as deleted.
     *
     * Sets self::$deleted to the number marked.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (unused).
     * @return void
     */
    static function DeleteAll()
    {
        global $zdbh;
        global $controller;
        $numrecords = 0;
        $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_deleted_ts IS NULL";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_deleted_ts IS NULL");
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $time = time();
                    $delete_record = $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=:time WHERE dn_id_pk = :dn_id_pk");
                    $delete_record->bindParam(':time', $time);
                    $delete_record->bindParam(':dn_id_pk', $row['dn_id_pk']);
                    $delete_record->execute();
                    $numrecords++;
                }
                self::$deleted = $numrecords;
            }
        }
    }

    /**
     * Mark every record of one domain as deleted.
     *
     * Sets self::$deleted to the number marked.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (unused).
     * @param int|string $domainid The domain (x_vhosts) ID.
     * @return void
     */
    static function DeleteDomainRecords($domainid)
    {
        global $zdbh;
        global $controller;
        $numrecords = 0;
        $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_vhost_fk=:domainid AND dn_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':domainid', $domainid);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_vhost_fk=:domainid AND dn_deleted_ts IS NULL");
                $sql->bindParam(':domainid', $domainid);
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $time = time();
                    $delete_record = $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=:time WHERE dn_id_pk = :dn_id_pk");
                    $delete_record->bindParam(':time', $time);
                    $delete_record->bindParam(':dn_id_pk', $row['dn_id_pk']);
                    $delete_record->execute();
                    $numrecords++;
                }
                self::$deleted = $numrecords;
            }
        }
    }

    /**
     * BIND's log (the setting bind_log), only when it is a plain file inside Sentora's logs folder
     * (log_dir): never a link (it could lead to another file), so nothing here reads or writes anywhere else.
     *
     * @return string The path, or '' when there is no such plain file.
     */
    static function LogPath()
    {
        $f = (string) ctrl_options::GetSystemOption('bind_log');
        if ($f === '' || $f[0] !== '/' || strpos($f, '..') !== false || !is_file($f) || is_link($f)) {
            return '';
        }
        // and only inside Sentora's logs folder (log_dir): the setting can be changed in Sentora Config,
        // and Clear must never empty some other file
        $dir = realpath((string) ctrl_options::GetSystemOption('log_dir'));
        $real = realpath($f);
        return ($dir !== false && $real !== false && strpos($real, rtrim($dir, '/') . '/') === 0) ? $f : '';
    }

    /**
     * How many lines of BIND's log are errors, warnings, and in all (read line by line, not kept).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    static function LogCounts()
    {
        $n = array(0, 0, 0);
        $f = self::LogPath();
        if ($f !== '' && ($fh = @fopen($f, 'rb'))) {
            while (($l = fgets($fh, 8192)) !== false) {
                $n[2]++;
                if (self::IsLine($l, 'error')) {
                    $n[0]++;
                }
                if (self::IsLine($l, 'warning')) {
                    $n[1]++;
                }
            }
            fclose($fh);
        }
        return $n;
    }

    /**
     * True when a log line is of that kind ("error:" or "error ", "warning:" or "warning ").
     *
     * @param string $l
     * @param string $kind 'error' or 'warning'
     * @return bool
     */
    static function IsLine($l, $kind)
    {
        return strpos($l, $kind . ':') !== false || strpos($l, $kind . ' ') !== false;
    }

    /**
     * Remove lines from BIND's log: those of a kind ('error', 'warning'), or all of them (''). The file
     * stays the same file (BIND keeps writing to it), locked while it is rewritten, and it must still
     * be the plain file that was checked (not exchanged for a link meanwhile). Sets self::$notwritable
     * when it can not be done.
     *
     * @param string $kind
     * @return void
     */
    static function ClearLines($kind)
    {
        $f = self::LogPath();
        $st = $f !== '' ? @lstat($f) : false;
        $fh = ($st && is_writable($f)) ? @fopen($f, 'r+b') : false;
        if (!$fh) {
            self::$notwritable = true;
            return;
        }
        $now = fstat($fh);
        if (!$now || $now['ino'] !== $st['ino'] || $now['dev'] !== $st['dev'] || !flock($fh, LOCK_EX)) {
            fclose($fh);
            self::$notwritable = true;
            return;
        }
        $keep = '';
        if ($kind !== '') {
            while (($l = fgets($fh, 8192)) !== false) {
                if (!self::IsLine($l, $kind)) {
                    $keep .= $l;
                }
            }
        }
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, $keep);
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /**
     * Remove the error lines from the BIND log (sets self::$notwritable when it cannot be written).
     *
     * @return void
     */
    static function ClearErrors()
    {
        self::ClearLines('error');
    }

    /**
     * Remove the warning lines from the BIND log (sets self::$notwritable when it cannot be written).
     *
     * @return void
     */
    static function ClearWarnings()
    {
        self::ClearLines('warning');
    }

    /**
     * Empty the BIND log (sets self::$notwritable when it cannot be written).
     *
     * @return void
     */
    static function ClearLog()
    {
        self::ClearLines('');
    }

    /**
     * Create the default records for one domain: A @, CNAME www and ftp, A mail, MX, A ns1/ns2 and two NS.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (unused).
     * @param int|string $vh_acc_fk The domain (x_vhosts) ID (despite the name; cast to int).
     * @return void
     */
    static function CreateDefaultRecords($vh_acc_fk)
    {
        global $zdbh;
        global $controller;
        // Prepared: the id can come from the inResetDomainID form field (was SQL injection)
        $domainID = (int) $vh_acc_fk;
        $domainQuery = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_id_pk = :id AND vh_deleted_ts IS NULL");
        $domainQuery->bindParam(':id', $domainID);
        $domainQuery->execute();
        $domainName = $domainQuery->fetch();
        $userID = $domainName['vh_acc_fk'];
        if (!fs_director::CheckForEmptyValue(ctrl_options::GetSystemOption('server_ip'))) {
            $target = ctrl_options::GetSystemOption('server_ip');
        } else {
            $target = $_SERVER["SERVER_ADDR"];
        }
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'A',
                                                            '@',
                                                            3600,
                                                            :target,
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->bindParam(':domainID', $domainID);
        $sql->bindParam(':target', $target);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'CNAME',
                                                            'www',
                                                            3600,
                                                            '@',
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->bindParam(':domainID', $domainID);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'CNAME',
                                                            'ftp',
                                                            3600,
                                                            '@',
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->bindParam(':domainID', $domainID);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'A',
                                                            'mail',
                                                            86400,
                                                            :target,
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->bindParam(':domainID', $domainID);
        $sql->bindParam(':target', $target);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'MX',
                                                            '@',
                                                            86400,
                                                            :vh_name_vc,
                                                            10,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $Domain = 'mail.' . $domainName['vh_name_vc'];
        $sql->bindParam(':vh_name_vc', $Domain);
        $sql->bindParam(':domainID', $domainID);
        $sql->bindParam(':target', $target);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'A',
                                                            'ns1',
                                                            172800,
                                                            :target,
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->bindParam(':domainID', $domainID);
        $sql->bindParam(':target', $target);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'A',
                                                            'ns2',
                                                            172800,
                                                            :target,
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->bindParam(':domainID', $domainID);
        $sql->bindParam(':target', $target);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'NS',
                                                            '@',
                                                            172800,
                                                            :vh_name_vc2,
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $Domain = 'ns1.' . $domainName['vh_name_vc'];
        $sql->bindParam(':vh_name_vc', $Domain);
        $sql->bindParam(':domainID', $domainID);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':vh_name_vc2', $domainName['vh_name_vc']);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'NS',
                                                            '@',
                                                            172800,
                                                            :ns2,
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $Domain = 'ns2.' . $domainName['vh_name_vc'];
        $sql->bindParam(':ns2', $Domain);
        $sql->bindParam(':domainID', $domainID);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->execute();
        return;
    }

    /**
     * The message box for the last action (template <@ Result @>).
     *
     * @return string|null The HTML of the message, or null when there is nothing to show.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your settings have been saved successfully!"), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$notwritable)) {
            return ui_sysmessage::shout(ui_language::translate("No permission to write to log file."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$forceupdate)) {
            return ui_sysmessage::shout(ui_language::translate("All zone records will be updated on next daemon run."), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$reset)) {
            return ui_sysmessage::shout(str_replace('%s', number_format(self::$reset), ui_language::translate("Records of %s domain(s) were reset to the defaults.")), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$addmissing)) {
            return ui_sysmessage::shout(str_replace('%s', number_format(self::$addmissing), ui_language::translate("Default records were created for %s domain(s).")), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$deletedtype)) {
            return ui_sysmessage::shout(sprintf(ui_language::translate('%1$s %2$s record(s) were marked as deleted.'), number_format(self::$deletedtype), htmlspecialchars((string) self::$type, ENT_QUOTES, 'UTF-8')), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$deleted)) {
            return ui_sysmessage::shout(str_replace('%s', number_format(self::$deleted), ui_language::translate("%s record(s) were marked as deleted.")), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$purged)) {
            return ui_sysmessage::shout(str_replace('%s', number_format(self::$purged), ui_language::translate("%s record(s) were purged from the database.")), "zannounceok");
        }
        return;
    }

    /**
     * Add a domain ID to the dns_hasupdates list so the daemon rewrites its zone.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (unused).
     * @param int|string $id The domain ID; "0" here (all zones).
     * @return true|null True when the ID was added, null when it was already in the list.
     */
    static function TriggerDNSUpdate($id)
    {
        global $zdbh;
        global $controller;
        $GetRecords = ctrl_options::GetSystemOption('dns_hasupdates');
        $records = explode(",", $GetRecords);
        foreach ($records as $record) {
            $RecordArray[] = $record;
        }
        if (!in_array($id, $RecordArray)) {
            $newlist = $GetRecords . "," . $id;
            $newlist = str_replace(",,", ",", $newlist);
            $sql = "UPDATE x_settings SET so_value_tx=:newlist WHERE so_name_vc='dns_hasupdates'";
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':newlist', $newlist);
            $sql->execute();
            return true;
        }
    }

    /**
     * Say whether the BIND log can be read.
     *
     * @param string $filename Path of the log file.
     * @return string HTML: a green or red message.
     */
    static function CheckLogReadable($filename)
    {
        if (is_readable($filename)) {
            $retval = "<font color=\"green\">" . ui_language::translate("Connected to log file") . "</font>";
        } else {
            $retval = "<font color=\"red\">" . ui_language::translate("Log file is not Readable") . "</font>";
        }
        return $retval;
    }

    /**
     * Say whether the BIND log can be written.
     *
     * @param string $filename Path of the log file.
     * @return string|null HTML: a green "(writable)" or red "(readonly)", or null when the file cannot be read.
     */
    static function CheckLogWritable($filename)
    {
        if (is_readable($filename)) {
            if (is_writable($filename)) {
                $retval = "<font color=\"green\">" . ui_language::translate("(writable)") . "</font>";
            } else {
                $retval = "<font color=\"red\">" . ui_language::translate("(readonly)") . "</font>";
            }
        } else {
            $retval = NULL;
        }
        return $retval;
    }

}
