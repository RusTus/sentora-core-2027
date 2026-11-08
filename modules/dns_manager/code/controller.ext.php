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
 * DNS Manager module: lets an account view and edit the DNS records of its own domains.
 *
 * Records are kept in x_dns; changed domains are listed in the dns_hasupdates
 * setting and the daemon (hooks/OnDaemonRun.hook.php) writes their zone files.
 */
class module_controller extends ctrl_module
{

    /** @var int|string|bool|null The domain (x_vhosts) ID being edited, set by doDisplayRecords()/doCreateDefaultRecords(). */
    static $editdomain;
    /** @var mixed Unused. */
    static $showform;
    /** @var string|null Success message shown by getResult(). */
    static $ResultOk;
    /** @var string|null First error message of the save, shown by getResult(). */
    static $ResultErr;

    /**
     * Remember an error message for getResult(), keeping only the first one.
     *
     * @param string $ErrorText The message (translated when shown).
     * @return void
     */
    private static function SetError($ErrorText)
    {
        if (empty(self::$ResultErr))
            self::$ResultErr = $ErrorText;
    }

    /**
     * The module's CSS and JavaScript tags (template <@ Init @>).
     *
     * @return string The HTML <link> and <script> tags.
     */
    static function getInit()
    {
        return '<link rel="stylesheet" type="text/css" href="modules/dns_manager/assets/dns.css">'
            . '<script src="modules/dns_manager/assets/dns.js" defer></script>';
    }

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
     * @param string $name The icon: globe, back, save, undo, plus, trash, check, alert, x, info, edit, list.
     * @return string SVG markup, or '' for an unknown name.
     */
    static function Icon($name)
    {
        $p = array(
            'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18"/>',
            'back' => '<path d="m15 18-6-6 6-6"/>',
            'save' => '<path d="M5 3h11l3 3v13a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2z"/><path d="M8 3v5h7V3"/><path d="M8 21v-7h8v7"/>',
            'undo' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'trash' => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="m6 7 1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
            'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
            'alert' => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
            'x' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
            'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-5M12 8h.01"/>',
            'edit' => '<path d="M12 20h8"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
            'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        );
        if (!isset($p[$name])) {
            return '';
        }
        return '<svg class="dn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$name] . '</svg>';
    }

    /**
     * The record types the page shows, in order, with their help text.
     *
     * @return array<string, string> Type => description (English).
     */
    static function RecordTypes()
    {
        return array(
            'A' => "An IPv4 address for a host name, e.g. '192.0.2.10'. Use @ for the domain itself.",
            'AAAA' => "An IPv6 address for a host name, e.g. '2001:db8::10'. Use @ for the domain itself.",
            'CNAME' => "Makes a host name an alias of another name, e.g. 'www' pointing to '@' or to 'host.example.com'. A host name with a CNAME can have no other record.",
            'MX' => "The servers that receive this domain's email, tried in order of priority (lowest first). The host name is usually left as @; the target is a host name such as 'mail.example.com'.",
            'TXT' => "Text for other services to read: SPF ('v=spf1 ...'), DKIM and DMARC keys, and ownership checks for Google, Microsoft and others.",
            'SRV' => "Where a service runs: priority, weight, port and target host. The host name names the service and protocol, e.g. '_sip._tcp'.",
            'SPF' => "The old SPF record type. Mail servers no longer read it: keep SPF in a TXT record instead.",
            'NS' => "The name servers that answer for this domain. They should match the name servers set at the domain's registrar.",
        );
    }

    /**
     * Determine which DNS page to show: the domain list or a domain's records (template <@ RecordAction @>).
     *
     * Shows the "create default records" page when the chosen domain has no records yet.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return string The HTML of the page.
     */
    static function getRecordAction()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'domainID'))) {
            return self::DisplayRecords();
        }
        if (fs_director::CheckForEmptyValue(self::$editdomain)) {
            return self::DisplayDomains();
        }
        $domainID = self::$editdomain;
        $numrows = $zdbh->prepare("SELECT COUNT(*) FROM x_dns WHERE dn_acc_fk=:userid AND dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL");
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->bindParam(':domainID', $domainID);
        $numrows->execute();
        return ((int) $numrows->fetchColumn() === 0) ? self::DisplayDefaultRecords() : self::DisplayRecords();
    }

    /**
     * One of the account's domains (not subdomains), by ID.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param mixed $domainID The domain (x_vhosts) ID.
     * @return array<string, mixed>|false The x_vhosts row, or false when it is not the account's.
     */
    static function OwnDomain($domainID)
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail();
        $sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_id_pk=:domainID AND vh_acc_fk=:userid AND vh_type_in != 2 AND vh_deleted_ts IS NULL");
        $sql->bindParam(':domainID', $domainID);
        $sql->bindParam(':userid', $currentuser['userid']);
        $sql->execute();
        return $sql->fetch();
    }

    /**
     * A form button that opens a domain's records (posts to DisplayRecords with the session token).
     *
     * @param int|string $domainID The domain ID.
     * @param string $label The button text (English).
     * @param string $icon The icon name.
     * @param string $class Extra button classes.
     * @return string HTML.
     */
    static function OpenButton($domainID, $label, $icon, $class)
    {
        return '<form class="dn-inline" action="./?module=dns_manager&amp;action=DisplayRecords" method="post">'
            . '<input type="hidden" name="inDomain" value="' . self::h($domainID) . '">' . self::getCSFR_Tag()
            . '<button type="submit" class="btn ' . $class . ' dn-btn">' . self::Icon($icon) . '<span>' . self::t($label) . '</span></button></form>';
    }

    /**
     * A record's target as the form shows it: TXT and SPF values are stored with slashes added (CleanRecord()).
     *
     * @param mixed $target The stored target.
     * @param string $type The record type.
     * @return string
     */
    static function ShownTarget($target, $type)
    {
        return ($type === 'TXT' || $type === 'SPF') ? stripslashes((string) $target) : (string) $target;
    }

    /**
     * Allow user to Create Initial Domain DNS records for the First time.
     *
     * Shows the records that would be made (the account's own list in x_dns_create, else the default one).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML.
     */
    static function DisplayDefaultRecords()
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail();
        $domain = self::OwnDomain(self::$editdomain);
        if (!$domain) {
            return self::DisplayDomains();
        }
        $ip = ctrl_options::GetSystemOption('server_ip');
        if (fs_director::CheckForEmptyValue($ip)) {
            $ip = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
        }
        $count = $zdbh->prepare('SELECT COUNT(*) FROM x_dns_create WHERE dc_acc_fk=:userId');
        $count->bindParam(':userId', $currentuser['userid']);
        $count->execute();
        $owner = ((int) $count->fetchColumn() > 0) ? (int) $currentuser['userid'] : 0;
        $list = $zdbh->prepare('SELECT * FROM x_dns_create WHERE dc_acc_fk=:owner ORDER BY dc_id_pk');
        $list->bindParam(':owner', $owner);
        $list->execute();
        $rows = '';
        while ($r = $list->fetch()) {
            $target = str_replace(array(':IP:', ':DOMAIN:'), array($ip, $domain['vh_name_vc']), (string) $r['dc_target_vc']);
            $prio = in_array($r['dc_type_vc'], array('MX', 'SRV'), true) && $r['dc_priority_in'] !== null ? '<span class="dn-prio">' . (int) $r['dc_priority_in'] . '</span> ' : '';
            $rows .= '<tr><td><span class="dn-type t-' . self::h(strtolower($r['dc_type_vc'])) . '">' . self::h($r['dc_type_vc']) . '</span></td><td class="dn-host">' . self::h($r['dc_host_vc'])
                . '</td><td class="dn-target">' . $prio . self::h($target) . '</td><td class="dn-num">' . self::h(self::TtlText($r['dc_ttl_in'])) . '</td></tr>';
        }

        $line = '<div class="dn" id="dn">';
        $line .= '<div class="dn-bar"><div class="dn-bar-title">' . self::Icon('globe') . '<div><h3>' . self::h($domain['vh_name_vc']) . '</h3><span class="dn-sub">' . self::t('No DNS records yet') . '</span></div></div>'
            . '<div class="dn-bar-actions"><a class="btn btn-default dn-btn" href="./?module=dns_manager">' . self::Icon('back') . '<span>' . self::t('All domains') . '</span></a></div></div>';
        $line .= '<div class="dn-card"><div class="dn-card-head"><h4>' . self::t('Set up DNS for this domain') . '</h4><p>'
            . self::t('These records point the domain, its www and mail at this server and name its name servers. You can change or add to them afterwards.') . '</p></div>';
        if ($rows !== '') {
            $line .= '<div class="dn-scroll"><table class="dn-table dn-preview"><thead><tr><th>' . self::t('Type') . '</th><th>' . self::t('Host') . '</th><th>' . self::t('Target') . '</th><th class="dn-num">TTL</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        }
        $line .= '<form class="dn-foot" action="./?module=dns_manager&amp;action=CreateDefaultRecords" method="post">'
            . '<input type="hidden" name="inDomain" value="' . self::h($domain['vh_id_pk']) . '">'
            . '<input type="hidden" name="inUserID" value="' . self::h($currentuser['userid']) . '">' . self::getCSFR_Tag()
            . '<button type="submit" class="btn btn-primary dn-btn">' . self::Icon('plus') . '<span>' . self::t('Create these records') . '</span></button></form>';
        $line .= '</div></div>';
        return $line;
    }

    /**
     * A TTL in words, e.g. 3600 => "1 hour".
     *
     * @param mixed $s The TTL in seconds.
     * @return string
     */
    static function TtlText($s)
    {
        $s = (int) $s;
        foreach (array(array(604800, '1 week', '%d weeks'), array(86400, '1 day', '%d days'), array(3600, '1 hour', '%d hours'), array(60, '1 minute', '%d minutes')) as $u) {
            if ($s >= $u[0] && $s % $u[0] === 0) {
                $n = $s / $u[0];
                return str_replace('%d', (string) $n, ui_language::translate($n === 1 ? $u[1] : $u[2]));
            }
        }
        return $s . ' s';
    }

    /**
     * Build the tab with one record type's records of a domain, and the template row for new records.
     *
     * The field names (ttl[id], target[id], delete[id], hostName[new_n] ...) are what SaveDNS() and CheckForErrors() read.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $type The record type ('A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'SPF' or 'NS').
     * @param array<int, array<string, mixed>> $records The domain's records of this type (x_dns rows).
     * @param string $domainName The domain name.
     * @param bool $on Whether this tab is the open one.
     * @return string The HTML.
     */
    static function DnsRecordField($type, $records, $domainName, $on)
    {
        $lockIP = ($type === 'A' && ctrl_options::GetSystemOption('custom_ip') == 'false');
        $serverIP = ctrl_options::GetSystemOption('server_ip');
        if (fs_director::CheckForEmptyValue($serverIP)) {
            $serverIP = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
        }
        $prio = ($type === 'MX' || $type === 'SRV');
        $srv = ($type === 'SRV');
        $types = self::RecordTypes();
        $num = function ($name, $id, $value, $label) {
            return '<td class="dn-num"><input class="form-control input-sm" name="' . $name . '[' . $id . ']" value="' . self::h($value) . '" inputmode="numeric" aria-label="' . self::t($label) . '">'
                . '<input type="hidden" name="original_' . $name . '[' . $id . ']" value="' . self::h($value) . '"></td>';
        };

        $line = '<section class="dn-pane' . ($on ? ' on' : '') . '" data-pane="' . $type . '" role="tabpanel">';
        $line .= '<p class="dn-help">' . self::Icon('info') . '<span>' . self::t($types[$type]) . '</span></p>';
        $line .= '<div class="dn-scroll"><table class="dn-table dn-records"><thead><tr>';
        $line .= '<th>' . self::t('Host name') . '</th><th class="dn-num">TTL</th>';
        if ($prio) {
            $line .= '<th class="dn-num">' . self::t('Priority') . '</th>';
        }
        if ($srv) {
            $line .= '<th class="dn-num">' . self::t('Weight') . '</th><th class="dn-num">' . self::t('Port') . '</th>';
        }
        $line .= '<th>' . self::t('Target') . '</th><th class="dn-act"></th></tr></thead><tbody>';

        foreach ($records as $r) {
            $id = (int) $r['dn_id_pk'];
            $host = (string) $r['dn_host_vc'];
            $full = ($host === '@' || $host === '') ? $domainName : $host . '.' . $domainName;
            $line .= '<tr class="dn-row">';
            $line .= '<td><b class="dn-host">' . self::h($host) . '</b><div class="dn-sub">' . self::h($full) . '</div></td>';
            $line .= $num('ttl', $id, $r['dn_ttl_in'], 'TTL');
            if ($prio) {
                $line .= $num('priority', $id, $r['dn_priority_in'], 'Priority');
            }
            if ($srv) {
                $line .= $num('weight', $id, $r['dn_weight_in'], 'Weight') . $num('port', $id, $r['dn_port_in'], 'Port');
            }
            $line .= '<td class="dn-target"><input class="form-control input-sm" name="target[' . $id . ']" value="' . self::h(self::ShownTarget($r['dn_target_vc'], $type)) . '"' . ($lockIP ? ' readonly title="' . self::t('Only the server IP address can be used.') . '"' : '') . ' aria-label="' . self::t('Target') . '">'
                . '<input type="hidden" name="original_target[' . $id . ']" value="' . self::h(self::ShownTarget($r['dn_target_vc'], $type)) . '"></td>';
            $line .= '<td class="dn-act"><input type="hidden" name="type[' . $id . ']" value="' . $type . '"><input type="hidden" class="dn-del" name="delete[' . $id . ']" value="false">'
                . '<button type="button" class="dn-icon-btn dn-undo" title="' . self::t('Undo') . '">' . self::Icon('undo') . '</button>'
                . '<button type="button" class="dn-icon-btn dn-delete" title="' . self::t('Delete') . '">' . self::Icon('trash') . '</button></td>';
            $line .= '</tr>';
        }
        $line .= '<tr class="dn-empty"' . ($records ? ' hidden' : '') . '><td colspan="' . (4 + ($prio ? 1 : 0) + ($srv ? 2 : 0)) . '">' . sprintf(self::t('No %s records.'), $type) . '</td></tr>';
        $line .= '</tbody></table></div>';

        // New record template (copied by dns.js; "proto_" becomes "<name>[new_n]")
        $line .= '<template class="dn-proto"><tr class="dn-row dn-new">';
        $line .= '<td><input class="form-control input-sm" name="proto_hostName" placeholder="' . ($type === 'MX' || $type === 'NS' ? '@' : self::t('e.g. www or @')) . '" aria-label="' . self::t('Host name') . '"><div class="dn-sub dn-full"></div><div class="dn-warn" hidden></div></td>';
        $line .= '<td class="dn-num"><input class="form-control input-sm" name="proto_ttl" value="' . ($type === 'NS' ? 172800 : 3600) . '" inputmode="numeric" aria-label="TTL"></td>';
        if ($prio) {
            $line .= '<td class="dn-num"><input class="form-control input-sm" name="proto_priority" value="10" inputmode="numeric" aria-label="' . self::t('Priority') . '"></td>';
        }
        if ($srv) {
            $line .= '<td class="dn-num"><input class="form-control input-sm" name="proto_weight" value="0" inputmode="numeric" aria-label="' . self::t('Weight') . '"></td>'
                . '<td class="dn-num"><input class="form-control input-sm" name="proto_port" inputmode="numeric" aria-label="' . self::t('Port') . '"></td>';
        }
        $placeholder = array('A' => '192.0.2.10', 'AAAA' => '2001:db8::10', 'CNAME' => '@', 'MX' => 'mail.' . $domainName, 'TXT' => 'v=spf1 a mx ~all', 'SRV' => 'sip.' . $domainName, 'SPF' => 'v=spf1 a mx ~all', 'NS' => 'ns1.' . $domainName);
        $line .= '<td class="dn-target"><input class="form-control input-sm" name="proto_target"' . ($lockIP ? ' value="' . self::h($serverIP) . '" readonly' : ' placeholder="' . self::h($placeholder[$type]) . '"') . ' aria-label="' . self::t('Target') . '"></td>';
        $line .= '<td class="dn-act"><input type="hidden" name="proto_type" value="' . $type . '"><input type="hidden" class="dn-del" name="proto_delete" value="false">'
            . '<button type="button" class="dn-icon-btn dn-remove" title="' . self::t('Remove') . '">' . self::Icon('trash') . '</button></td>';
        $line .= '</tr></template>';

        $line .= '<div class="dn-pane-foot"><button type="button" class="btn btn-default dn-btn dn-add">' . self::Icon('plus') . '<span>' . sprintf(self::t('Add %s record'), $type) . '</span></button></div>';
        $line .= '</section>';
        return $line;
    }

    /**
     * Build and show DNS Record HTML output: one tab per record type and the zone check result.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function DisplayRecords()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $domainID = fs_director::CheckForEmptyValue(self::$editdomain) ? $controller->GetControllerRequest('FORM', 'domainID') : self::$editdomain;
        // Only this account's own domains (the ID can come from the posted form)
        $domain = self::OwnDomain($domainID);
        if (!$domain) {
            return self::DisplayDomains();
        }
        $domainID = $domain['vh_id_pk'];
        $domainName = (string) $domain['vh_name_vc'];

        // the records, by type
        $byType = array();
        $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_acc_fk=:userid AND dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL ORDER BY dn_host_vc ASC, dn_priority_in ASC");
        $sql->bindParam(':userid', $currentuser['userid']);
        $sql->bindParam(':domainID', $domainID);
        $sql->execute();
        while ($r = $sql->fetch()) {
            $byType[(string) $r['dn_type_vc']][] = $r;
        }
        // types to show: the allowed ones (SPF only when the domain still has some), and any
        // type the domain has records of, so every record is posted back with the form
        $shown = array();
        foreach (array_keys(self::RecordTypes()) as $type) {
            if ((self::IsTypeAllowed($type) && ($type !== 'SPF' || !empty($byType['SPF']))) || !empty($byType[$type])) {
                $shown[] = $type;
            }
        }

        // DNS zone check
        $zone_message = trim(str_replace((string) self::$zonecheckFile, '', (string) self::CheckZoneRecord($domainID)));
        if (strstr(strtoupper($zone_message), "OK")) {
            if (substr_count($zone_message, ":") >= 2) {
                $zone = array('warn', 'alert', 'Loaded with warnings', 'Your DNS zone has been loaded, but with errors. Some features may not work until corrected.');
            } else {
                $zone = array('ok', 'check', 'Zone OK', 'Your DNS zone has been loaded without errors.');
            }
        } else {
            $zone = array('bad', 'x', 'Zone has errors', 'Errors detected have prevented your DNS zone from being loaded. Please correct the error(s) listed below. Until these errors are fixed, your DNS will not work.');
        }
        $pending = in_array((string) $domainID, explode(',', (string) ctrl_options::GetSystemOption('dns_hasupdates')), true);

        $line = '<div class="dn" id="dn" data-domain="' . self::h($domainName) . '" data-leave="' . self::t('There are unsaved changes. Leave without saving them?') . '"'
            . ' data-mx-warn="' . self::t('An MX host name is usually left as @. With a name here, the address becomes user@name.') . '"'
            . ' data-dup-warn="' . self::t('This already ends with the domain name: the record would be for') . '"'
            . ' data-cname-warn="' . self::t('A CNAME on @ breaks the domain\'s other records (mail, name servers).') . '">';
        $line .= '<form action="./?module=dns_manager&amp;action=SaveDNS" method="post" class="dn-form">';
        $line .= '<input id="domainName" name="domainName" value="' . self::h($domainName) . '" type="hidden">';
        $line .= '<input id="domainID" name="domainID" value="' . self::h($domainID) . '" type="hidden">';
        $line .= '<input name="newRecords" value="0" type="hidden" class="dn-count">';

        // title bar
        $line .= '<div class="dn-bar"><div class="dn-bar-title">' . self::Icon('globe') . '<div><h3><a href="http://' . self::h($domainName) . '" target="_blank" rel="noopener noreferrer">' . self::h($domainName) . '</a></h3>'
            . '<span class="dn-pill ' . $zone[0] . '">' . self::Icon($zone[1]) . self::t($zone[2]) . '</span>'
            . ($pending ? ' <span class="dn-pill info">' . self::t('Publishing within 5 minutes') . '</span>' : '') . '</div></div>';
        $line .= '<div class="dn-bar-actions"><span class="dn-changes" hidden>' . self::t('Unsaved changes') . '</span>'
            . '<a class="btn btn-default dn-btn" href="./?module=dns_manager">' . self::Icon('back') . '<span>' . self::t('All domains') . '</span></a>'
            . '<button type="button" class="btn btn-default dn-btn dn-undo-all" disabled>' . self::Icon('undo') . '<span>' . self::t('Undo changes') . '</span></button>'
            . '<button type="submit" class="btn btn-primary dn-btn dn-save" disabled>' . self::Icon('save') . '<span>' . self::t('Save changes') . '</span></button></div></div>';

        // tabs and records
        $line .= '<div class="dn-card dn-card-records"><nav class="dn-tabs" role="tablist">';
        foreach ($shown as $i => $type) {
            $n = isset($byType[$type]) ? count($byType[$type]) : 0;
            $line .= '<button type="button" role="tab" class="dn-tab' . ($i === 0 ? ' on' : '') . '" data-tab="' . $type . '">' . $type . ' <span class="dn-badge">' . $n . '</span></button>';
        }
        $line .= '</nav>';
        foreach ($shown as $i => $type) {
            $line .= self::DnsRecordField($type, isset($byType[$type]) ? $byType[$type] : array(), $domainName, $i === 0);
        }
        $line .= '</div>';
        $line .= self::getCSFR_Tag();
        $line .= '</form>';

        // zone check
        $line .= '<div class="dn-card"><div class="dn-card-head"><h4>' . self::t('DNS status') . '</h4></div>';
        $line .= '<div class="dn-zone ' . $zone[0] . '">' . self::Icon($zone[1]) . '<div><b>' . self::t($zone[3]) . '</b><span>'
            . self::t("Saved changes are published within 5 minutes. Other servers may keep the old records for up to their TTL (often an hour, at most 24 hours).") . '</span></div></div>';
        if ($zone_message !== '') {
            $line .= '<details class="dn-details"' . ($zone[0] === 'ok' ? '' : ' open') . '><summary>' . self::t('Output of the DNS zone checker') . '</summary><pre class="dn-raw">' . self::h($zone_message) . '</pre></details>';
        }
        $line .= '</div></div>';
        return $line;
    }

    /**
     * Show the account's domains, each with its record count, whether changes are waiting to be published and an edit button.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML.
     */
    static function DisplayDomains()
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail();
        $sql = $zdbh->prepare("SELECT v.vh_id_pk, v.vh_name_vc, v.vh_type_in,
                (SELECT COUNT(*) FROM x_dns d WHERE d.dn_vhost_fk = v.vh_id_pk AND d.dn_acc_fk = v.vh_acc_fk AND d.dn_deleted_ts IS NULL) AS records
            FROM x_vhosts v WHERE v.vh_acc_fk=:userid AND v.vh_type_in != 2 AND v.vh_deleted_ts IS NULL ORDER BY v.vh_name_vc ASC");
        $sql->bindParam(':userid', $currentuser['userid']);
        $sql->execute();
        $domains = $sql->fetchAll();
        $pending = explode(',', (string) ctrl_options::GetSystemOption('dns_hasupdates'));

        $line = '<div class="dn" id="dn">';
        $line .= '<div class="dn-card"><div class="dn-card-head dn-head-row"><div><h4>' . self::t('Your domains') . '</h4><p>'
            . self::t('Choose a domain to see and change its DNS records: where the website and mail are, name servers, and text records such as SPF and DKIM.') . '</p></div>';
        if (count($domains) > 8) {
            $line .= '<input type="search" class="form-control dn-filter" placeholder="' . self::t('Find a domain') . '" aria-label="' . self::t('Find a domain') . '">';
        }
        $line .= '</div>';
        if (!$domains) {
            $line .= '<p class="dn-none">' . self::t('You have no domains yet. Add one in Domains, and its DNS records can be set up here.') . '</p></div></div>';
            return $line;
        }
        $line .= '<div class="dn-scroll"><table class="dn-table dn-domains"><thead><tr><th>' . self::t('Domain') . '</th><th class="dn-num">' . self::t('Records') . '</th><th>' . self::t('Status') . '</th><th class="dn-act"></th></tr></thead><tbody>';
        foreach ($domains as $d) {
            $n = (int) $d['records'];
            if ($n === 0) {
                $status = '<span class="dn-pill warn">' . self::t('Not set up') . '</span>';
            } elseif (in_array((string) $d['vh_id_pk'], $pending, true)) {
                $status = '<span class="dn-pill info">' . self::t('Publishing within 5 minutes') . '</span>';
            } else {
                $status = '<span class="dn-pill ok">' . self::t('Published') . '</span>';
            }
            $kind = ((int) $d['vh_type_in'] === 3) ? ' <span class="dn-tag">' . self::t('parked') . '</span>' : '';
            $line .= '<tr data-name="' . self::h($d['vh_name_vc']) . '"><td><div class="dn-domain">' . self::Icon('globe') . '<b>' . self::h($d['vh_name_vc']) . '</b>' . $kind . '</div></td>'
                . '<td class="dn-num">' . $n . '</td><td>' . $status . '</td><td class="dn-act">'
                . ($n === 0 ? self::OpenButton($d['vh_id_pk'], 'Set up DNS', 'plus', 'btn-primary btn-sm') : self::OpenButton($d['vh_id_pk'], 'Edit records', 'edit', 'btn-default btn-sm'))
                . '</td></tr>';
        }
        $line .= '</tbody></table></div></div></div>';
        return $line;
    }

    /**
     * Handle the domain chosen in the list: show its records if the account owns it.
     *
     * Checks the CSRF token and sets self::$editdomain.
     *
     * @global db_driver $zdbh The panel's database connection (unused).
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doDisplayRecords()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        // Only this account's own domains
        if (!ctrl_ownership::Owns('x_vhosts', 'vh_id_pk', 'vh_acc_fk', $controller->GetControllerRequest('FORM', 'inDomain'), ' AND vh_type_in != 2 AND vh_deleted_ts IS NULL')) {
            return;
        }
        self::$editdomain = $controller->GetControllerRequest('FORM', 'inDomain');
        return;
    }

    /**
     * Handle the records form: check the posted records and save them when they are valid.
     *
     * Checks the CSRF token and that the account owns the domain; sets self::$ResultOk
     * on success (errors are set by CheckForErrors()).
     *
     * @global db_driver $zdbh The panel's database connection (unused).
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doSaveDNS()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        // Records can only be added to or changed on this account's own domains
        if (!ctrl_ownership::Owns('x_vhosts', 'vh_id_pk', 'vh_acc_fk', $controller->GetControllerRequest('FORM', 'domainID'), ' AND vh_type_in != 2 AND vh_deleted_ts IS NULL')) {
            return;
        }
        if (!fs_director::CheckForEmptyValue(self::CheckForErrors())) {
            self::SaveDNS();
            //self::WriteRecord();
            self::$ResultOk = 'Changes to your DNS have been saved successfully!';
            return;
        }
    }

    /**
     * Creates a new DNS record from an array of key value pairs.
     *
     * Cleans the type, host name and target, inserts the record and marks the domain
     * for a zone update. Also used by the web service API.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array<string,mixed> $rec Array of record properties: 'uid', 'domainName', 'domainID', 'type', 'hostName', 'ttl', 'target'; optional 'priority', 'weight', 'port', 'time'.
     * @return void
     */
    static function createDNSRecord(array $rec)
    {
        global $zdbh;
        // the same cleaning as records added in the page (this is also used by the web
        // service API): no line breaks or control characters anywhere in a record
        $rec['type'] = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $rec['type']));
        $rec['hostName'] = preg_replace('/[\x00-\x1F\x7F\s]/', '', (string) $rec['hostName']);
        $rec['target'] = self::CleanRecord($rec['target'], $rec['type']);
        $sql = $zdbh->prepare('INSERT INTO x_dns (dn_acc_fk,
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
                           :userid,
                           :domainName,
                           :domainID,
                           :type_new,
                           :hostName_new,
                           :ttl_new,
                           :target_new,
                           :priority_new,
                           :weight_new,
                           :port_new,
                           :time)'
        );

        $priority_new = array_key_exists('priority', $rec) ? $rec['priority'] : 0;
        $weight_new = array_key_exists('weight', $rec) ? $rec['weight'] : 0;
        $port_new = array_key_exists('port', $rec) ? $rec['port'] : 0;
        $time = array_key_exists('time', $rec) ? $rec['time'] : time();

        $sql->bindParam(':userid', $rec['uid']);
        $sql->bindParam(':domainName', $rec['domainName']);
        $sql->bindParam(':domainID', $rec['domainID']);
        $sql->bindParam(':type_new', $rec['type']);
        $sql->bindParam(':hostName_new', $rec['hostName']);
        $sql->bindParam(':ttl_new', $rec['ttl']);
        $sql->bindParam(':target_new', $rec['target']);
        $sql->bindParam(':priority_new', $priority_new);
        $sql->bindParam(':weight_new', $weight_new);
        $sql->bindParam(':port_new', $port_new);
        $sql->bindParam(':time', $time);
        $sql->execute();

        self::TriggerDNSUpdate($rec['domainID']);
    }

    /**
     * Handle "Create Records": create a domain's records from x_dns_create (the account's own list, else the default one).
     *
     * Checks the CSRF token, that the account owns the domain and that inUserID is the
     * logged-in account; sets self::$editdomain so the records are shown next.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doCreateDefaultRecords()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();

        $domainID = $controller->GetControllerRequest('FORM', 'inDomain');
        // Only this account's own domains, for this account
        $currentuser = ctrl_users::GetUserDetail();
        if (!ctrl_ownership::Owns('x_vhosts', 'vh_id_pk', 'vh_acc_fk', $domainID, ' AND vh_type_in != 2 AND vh_deleted_ts IS NULL') || (string) $controller->GetControllerRequest('FORM', 'inUserID') !== (string) $currentuser['userid']) {
            return;
        }
        $numrows = $zdbh->prepare('SELECT * FROM x_vhosts WHERE vh_id_pk=:domainID AND vh_type_in !=2 AND vh_deleted_ts IS NULL');
        $numrows->bindParam(':domainID', $domainID);
        $numrows->execute();
        $domainName = $numrows->fetch();
        $domainName = $domainName['vh_name_vc'];

        $userID = $controller->GetControllerRequest('FORM', 'inUserID');
        if (!fs_director::CheckForEmptyValue(ctrl_options::GetSystemOption('server_ip'))) {
            $targetIP = ctrl_options::GetSystemOption('server_ip');
        } else {
            $targetIP = $_SERVER["SERVER_ADDR"]; //This needs checking on windows 7 we may need to use LOCAL_ADDR :- Sam Mottley
        }
        //Get list of DNS rows to create
        $RowCount = $zdbh->prepare('SELECT count(*) FROM x_dns_create WHERE dc_acc_fk=:userId');
        $RowCount->bindparam(':userId', $userID);
        $RowCount->execute();
        if ($RowCount->fetchColumn() > 0) {
            //The current user have specifics entries, use them only
            $CreateList = $zdbh->prepare('SELECT * FROM x_dns_create WHERE dc_acc_fk=:userId');
            $CreateList->bindparam(':userId', $userID);
            $CreateList->execute();
        } else {
            //no entry specific to this user is present, use default entries (user number = 0)
            $CreateList = $zdbh->query('SELECT * FROM x_dns_create WHERE dc_acc_fk=0');
        }
        while ($CreateItem = $CreateList->fetch()) {
            $Target = str_replace(':IP:', $targetIP, $CreateItem['dc_target_vc']);
            $Target = str_replace(':DOMAIN:', $domainName, $Target);

            $Row = array(
                'uid' => $userID,
                'domainName' => $domainName,
                'domainID' => $domainID,
                'type' => $CreateItem['dc_type_vc'],
                'hostName' => $CreateItem['dc_host_vc'],
                'ttl' => $CreateItem['dc_ttl_in'],
                'target' => $Target);

            if (!empty($CreateItem['dc_priority_in']))
                $Row['priority'] = $CreateItem['dc_priority_in'];

            if (!empty($CreateItem['dc_weight_in']))
                $Row['weight'] = $CreateItem['dc_weight_in'];

            if (!empty($CreateItem['dc_port_in']))
                $Row['port'] = $CreateItem['dc_port_in'];

            self::createDNSRecord($Row);
        }

        self::$editdomain = $domainID;
        return;
    }

    /**
     * Save the posted record changes: updates, deletions and new records, then mark the domain for a zone update.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function SaveDNS()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $dnsrecords = array();
        //Grab form inputs in array and assign them to variables
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'domainName'))) {
            $domainName = $controller->GetControllerRequest('FORM', 'domainName');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'domainID'))) {
            $domainID = $controller->GetControllerRequest('FORM', 'domainID');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'ttl'))) {
            $ttl = $controller->GetControllerRequest('FORM', 'ttl');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_ttl'))) {
            $original_ttl = $controller->GetControllerRequest('FORM', 'original_ttl');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'target'))) {
            $target = $controller->GetControllerRequest('FORM', 'target');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_target'))) {
            $original_target = $controller->GetControllerRequest('FORM', 'original_target');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'type'))) {
            $type = $controller->GetControllerRequest('FORM', 'type');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'delete'))) {
            $delete = $controller->GetControllerRequest('FORM', 'delete');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'hostName'))) {
            $hostName = $controller->GetControllerRequest('FORM', 'hostName');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'priority'))) {
            $priority = $controller->GetControllerRequest('FORM', 'priority');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_priority'))) {
            $original_priority = $controller->GetControllerRequest('FORM', 'original_priority');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'weight'))) {
            $weight = $controller->GetControllerRequest('FORM', 'weight');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_weight'))) {
            $original_weight = $controller->GetControllerRequest('FORM', 'original_weight');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'port'))) {
            $port = $controller->GetControllerRequest('FORM', 'port');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_port'))) {
            $original_port = $controller->GetControllerRequest('FORM', 'original_port');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'newRecords'))) {
            $newRecords = $controller->GetControllerRequest('FORM', 'newRecords');
        }
        //Get all existing records for domain and add the id's to an array
        $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_acc_fk=:userid AND dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->bindParam(':domainID', $domainID);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_acc_fk=:userid AND dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL");
                $sql->bindParam(':userid', $currentuser['userid']);
                $sql->bindParam(':domainID', $domainID);
                $sql->execute();
                while ($rowdns = $sql->fetch()) {
                    $dnsrecords[] = $rowdns['dn_id_pk'];
                }
            }
        }
        //Existing Records
        //Sort through the dns record array by id and update as needed
        foreach ($dnsrecords as $id) {
            if ($delete[$id] == "true") {
                //The record has been marked for deletion, so lets delete it!
                $sql = $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=:time WHERE dn_id_pk =:id AND dn_deleted_ts IS NULL");
                $sql->bindParam(':id', $id);
                $time = time();
                $sql->bindParam(':time', $time);
                $sql->execute();
                self::TriggerDNSUpdate($domainID);
            } else {
                //The record needs updating instead.
                //TTL
                if (isset($ttl[$id]) && !fs_director::CheckForEmptyValue($ttl[$id]) && $ttl[$id] != $original_ttl[$id] && is_numeric($ttl[$id])) {
                    $sql = $zdbh->prepare("UPDATE x_dns SET dn_ttl_in=:cleanRecord WHERE dn_id_pk = :id AND dn_deleted_ts IS NULL");
                    $sql->bindParam(':id', $id);
                    $cleanRecord = self::CleanRecord($ttl[$id], $type[$id]);
                    $sql->bindParam(':cleanRecord', $cleanRecord);
                    $sql->execute();
                }
                //TARGET
                if (isset($target[$id]) && !fs_director::CheckForEmptyValue($target[$id]) && $target[$id] != $original_target[$id]) {
                    $sql = $zdbh->prepare("UPDATE x_dns SET dn_target_vc=:cleanRecord WHERE dn_id_pk = :id AND dn_deleted_ts IS NULL");
                    $sql->bindParam(':id', $id);
                    $cleanRecord = self::CleanRecord($target[$id], $type[$id]);
                    $sql->bindParam(':cleanRecord', $cleanRecord);
                    $sql->execute();
                }
                //PRIORITY
                if (isset($priority[$id]) && !fs_director::CheckForEmptyValue($priority[$id]) && $priority[$id] != $original_priority[$id]) {
                    $sql = $zdbh->prepare("UPDATE x_dns SET dn_priority_in=:cleanRecord WHERE dn_id_pk = :id AND dn_deleted_ts IS NULL");
                    $sql->bindParam(':id', $id);
                    $cleanRecord = self::CleanRecord($priority[$id], $type[$id]);
                    $sql->bindParam(':cleanRecord', $cleanRecord);
                    $sql->execute();
                }
                //WEIGHT
                if (isset($weight[$id]) && !fs_director::CheckForEmptyValue($weight[$id]) && $weight[$id] != $original_weight[$id]) {
                    $sql = $zdbh->prepare("UPDATE x_dns SET dn_weight_in=:cleanRecord WHERE dn_id_pk = :id AND dn_deleted_ts IS NULL");
                    $sql->bindParam(':id', $id);
                    $cleanRecord = self::CleanRecord($weight[$id], $type[$id]);
                    $sql->bindParam(':cleanRecord', $cleanRecord);
                    $sql->execute();
                }
                //PORT
                if (isset($port[$id]) && !fs_director::CheckForEmptyValue($port[$id]) && $port[$id] != $original_port[$id]) {
                    $sql = $zdbh->prepare("UPDATE x_dns SET dn_port_in=:cleanRecord WHERE dn_id_pk = :id AND dn_deleted_ts IS NULL");
                    $sql->bindParam(':id', $id);
                    $cleanRecord = self::CleanRecord($port[$id], $type[$id]);
                    $sql->bindParam(':cleanRecord', $cleanRecord);
                    $sql->execute();
                }
                //Flag the record for needing updating on next daemon run...
                self::TriggerDNSUpdate($domainID);
            }
        }
        //NEW Records
        //Find all new records in post array
        if (isset($newRecords) && !fs_director::CheckForEmptyValue($newRecords)) {
            $numnew = $newRecords;
            $id = 1;
            while ($numnew >= $id) {
                if (isset($type['new_' . $id]) && !fs_director::CheckForEmptyValue($target['new_' . $id])) {
                    if ($delete['new_' . $id] != "true" && !fs_director::CheckForEmptyValue($type['new_' . $id])) {
                        if (isset($hostName['new_' . $id])) {
                            $hostName_new = self::CleanRecord($hostName['new_' . $id], $type['new_' . $id]);
                        } else {
                            $hostName_new = "NULL";
                        }
                        if (isset($type['new_' . $id]) && !fs_director::CheckForEmptyValue($type['new_' . $id])) {
                            $type_new = $type['new_' . $id];
                        } else {
                            $type_new = "NULL";
                        }
                        if (isset($ttl['new_' . $id]) && !fs_director::CheckForEmptyValue($ttl['new_' . $id])) {
                            $ttl_new = self::CleanRecord($ttl['new_' . $id], $type['new_' . $id]);
                        } else {
                            $ttl_new = "0";
                        }
                        if (isset($target['new_' . $id]) && !fs_director::CheckForEmptyValue($target['new_' . $id])) {
                            //If Custom IP addresses are not allowed.
                            if ($type['new_' . $id] == 'A') {
                                if (ctrl_options::GetSystemOption('custom_ip') == 'false') {
                                    if (!fs_director::CheckForEmptyValue(ctrl_options::GetSystemOption('server_ip'))) {
                                        $target['new_' . $id] = ctrl_options::GetSystemOption('server_ip');
                                    } else {
                                        $target['new_' . $id] = $_SERVER["SERVER_ADDR"];
                                    }
                                }
                            }
                            $target_new = self::CleanRecord($target['new_' . $id], $type['new_' . $id]);
                        } else {
                            $target_new = "NULL";
                        }
                        if (isset($priority['new_' . $id]) && !fs_director::CheckForEmptyValue($priority['new_' . $id])) {
                            $priority_new = self::CleanRecord($priority['new_' . $id], $type['new_' . $id]);
                        } else {
                            $priority_new = "0";
                        }
                        if (isset($weight['new_' . $id]) && !fs_director::CheckForEmptyValue($weight['new_' . $id])) {
                            $weight_new = self::CleanRecord($weight['new_' . $id], $type['new_' . $id]);
                        } else {
                            $weight_new = "0";
                        }
                        if (isset($port['new_' . $id]) && !fs_director::CheckForEmptyValue($port['new_' . $id])) {
                            $port_new = self::CleanRecord($port['new_' . $id], $type['new_' . $id]);
                        } else {
                            $port_new = "0";
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
                           :userid,
                           :domainName,
                           :domainID,
                           :type_new,
                           :hostName_new,
                           :ttl_new,
                           :target_new,
                           :priority_new,
                           :weight_new,
                           :port_new,
                           :time)"
                        );
                        $sql->bindParam(':userid', $currentuser['userid']);
                        $sql->bindParam(':domainName', $domainName);
                        $sql->bindParam(':domainID', $domainID);
                        $sql->bindParam(':type_new', $type_new);
                        $sql->bindParam(':hostName_new', $hostName_new);
                        $sql->bindParam(':ttl_new', $ttl_new);
                        $sql->bindParam(':target_new', $target_new);
                        $sql->bindParam(':priority_new', $priority_new);
                        $sql->bindParam(':weight_new', $weight_new);
                        $sql->bindParam(':port_new', $port_new);
                        $time = time();
                        $sql->bindParam(':time', $time);
                        $sql->execute();
                        //Flag the record for needing updating on next daemon run...
                        self::TriggerDNSUpdate($domainID);
                    }
                }
                $id++;
            }
        }
        return;
    }

    //Use the same method as above and check for input errors doSaveDNS() uses before continuing.
    /**
     * Validate the posted records (host names, targets, TTLs, priorities, weights, ports, IP addresses).
     *
     * Sets the first error through SetError().
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return bool True when every record is valid.
     */
    static function CheckForErrors()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $dnsrecords = array();

        //Grab form inputs in array and assign them to variables
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'domainName'))) {
            $domainName = $controller->GetControllerRequest('FORM', 'domainName');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'domainID'))) {
            $domainID = $controller->GetControllerRequest('FORM', 'domainID');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'ttl'))) {
            $ttl = $controller->GetControllerRequest('FORM', 'ttl');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_ttl'))) {
            $original_ttl = $controller->GetControllerRequest('FORM', 'original_ttl');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'target'))) {
            $target = $controller->GetControllerRequest('FORM', 'target');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_target'))) {
            $original_target = $controller->GetControllerRequest('FORM', 'original_target');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'type'))) {
            $type = $controller->GetControllerRequest('FORM', 'type');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'delete'))) {
            $delete = $controller->GetControllerRequest('FORM', 'delete');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'hostName'))) {
            $hostName = $controller->GetControllerRequest('FORM', 'hostName');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'priority'))) {
            $priority = $controller->GetControllerRequest('FORM', 'priority');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_priority'))) {
            $original_priority = $controller->GetControllerRequest('FORM', 'original_priority');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'weight'))) {
            $weight = $controller->GetControllerRequest('FORM', 'weight');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_weight'))) {
            $original_weight = $controller->GetControllerRequest('FORM', 'original_weight');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'port'))) {
            $port = $controller->GetControllerRequest('FORM', 'port');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_port'))) {
            $original_port = $controller->GetControllerRequest('FORM', 'original_port');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'newRecords'))) {
            $newRecords = $controller->GetControllerRequest('FORM', 'newRecords');
        }
        //Get all existing records for domain and add the id's to an array
        $numrows = $zdbh->prepare('SELECT COUNT(*) FROM x_dns WHERE dn_acc_fk=:userid AND dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL');
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->bindParam(':domainID', $domainID);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare('SELECT dn_id_pk FROM x_dns WHERE dn_acc_fk=:userid AND dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL');
                $sql->bindParam(':userid', $currentuser['userid']);
                $sql->bindParam(':domainID', $domainID);
                $sql->execute();
                while ($rowdns = $sql->fetch()) {
                    $dnsrecords[] = $rowdns['dn_id_pk'];
                }
            }
        }

        //Existing Records
        //Sort through the dns record array by id and update as needed
        foreach ($dnsrecords as $id) {
            if ($delete[$id] == "false") {
                //TTL
                if (isset($ttl[$id]) && !fs_director::CheckForEmptyValue($ttl[$id]) && $ttl[$id] != $original_ttl[$id]) {
                    if (!is_numeric($ttl[$id])) {
                        self::SetError('TTL must be a numeric value.');
                        return FALSE;
                    }
                }

                //TARGET
                if (isset($target[$id]) && !fs_director::CheckForEmptyValue($target[$id]) && $target[$id] != $original_target[$id]) {
                    if ($type[$id] == "A") {
                        if (!self::IsValidIPv4($target[$id])) {
                            self::SetError('IP Address is not a valid IPV4 address.');
                            return FALSE;
                        }
                    } elseif ($type[$id] == "AAAA") {
                        if (!self::IsValidIPv6($target[$id])) {
                            self::SetError('IP Address is not a valid IPV6 address');
                            return FALSE;
                        }
                    } elseif ($type[$id] == "TXT") {

                    } elseif ($type[$id] == "SPF") {

                    } else {
                        if (!self::IsValidIP($target[$id])) {
                            if (!self::IsValidDomainName($target[$id])) {
                                self::SetError('An invalid domain name character was entered. Domain names are limited to alphanumeric characters and hyphens.');
                                return FALSE;
                            }
                        }
                        if (!self::IsValidDomainName($target[$id])) {
                            if (!self::IsValidIP($target[$id])) {
                                self::SetError('Target is not a valid IP address');
                                return FALSE;
                            }
                        }
                    }
                }

                //PRIORITY
                if (isset($priority[$id]) && !fs_director::CheckForEmptyValue($priority[$id]) && $priority[$id] != $original_priority[$id]) {
                    if (!is_numeric($priority[$id])) {
                        self::SetError('Priority must be a numeric value.');
                        return FALSE;
                    }
                    if ($priority[$id] < 0 || $priority[$id] > 65535) {
                        self::SetError('The priority of a dns record must be a numeric value between 0 and 65535');
                        return FALSE;
                    }
                }

                //WEIGHT
                if (isset($weight[$id]) && !fs_director::CheckForEmptyValue($weight[$id]) && $weight[$id] != $original_weight[$id]) {
                    if (!is_numeric($weight[$id])) {
                        self::SetError('Weight must be a numeric value.');
                        return FALSE;
                    }
                    if ($weight[$id] < 0 || $weight[$id] > 65535) {
                        self::SetError('The weight of a dns record must be a numeric value between 0 and 65535');
                        return FALSE;
                    }
                }

                //PORT
                if (isset($port[$id]) && !fs_director::CheckForEmptyValue($port[$id]) && $port[$id] != $original_port[$id]) {
                    if (!is_numeric($port[$id])) {
                        self::SetError('PORT must be a numeric value.');
                        return FALSE;
                    }
                    if ($port[$id] < 0 || $port[$id] > 65535) {
                        self::SetError('The port of a dns record must be a numeric value between 0 and 65535');
                        return FALSE;
                    }
                }
            }
        }

        //NEW Records
        //Find all new records in post array
        if (isset($newRecords) && !fs_director::CheckForEmptyValue($newRecords)) {
            $numnew = $newRecords;
            for ($id = 1; $id <= $numnew; $id++) {
                $NewId = 'new_' . $id;
                if (isset($type[$NewId])) {
                    if ($delete[$NewId] == "false" && !fs_director::CheckForEmptyValue($type[$NewId])) {
                        // a new row without a target was dropped without a word (the grey text in
                        // the field is only an example): say so instead
                        if ((!isset($target[$NewId]) || trim((string) $target[$NewId]) === '') && $type[$NewId] != 'A'
                            && isset($hostName[$NewId]) && trim((string) $hostName[$NewId]) !== '') {
                            self::SetError('The new ' . $type[$NewId] . ' record "' . trim((string) $hostName[$NewId]) . '" has no target: type one (the grey text in the field is only an example).');
                            return FALSE;
                        }
                        //HOSTNAME
                        if (isset($hostName[$NewId]) && !fs_director::CheckForEmptyValue($hostName[$NewId]) && $hostName[$NewId] != "@") {
                            // A host name with a CNAME can have no other record, and a record can
                            // not be added twice; other types may share a name (A and AAAA for www,
                            // several TXT records). Records being deleted in this save do not count.
                            $numrows = $zdbh->prepare('SELECT dn_id_pk, dn_type_vc, dn_target_vc FROM x_dns WHERE dn_host_vc=:hostName2 AND dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL');
                            $hostName2 = strtolower(trim((string) $hostName[$NewId]));
                            $numrows->bindParam(':hostName2', $hostName2);
                            $numrows->bindParam(':domainID', $domainID);
                            $numrows->execute();
                            while ($same = $numrows->fetch()) {
                                if (isset($delete[$same['dn_id_pk']]) && $delete[$same['dn_id_pk']] == "true") {
                                    continue;
                                }
                                if ($type[$NewId] == 'CNAME' || $same['dn_type_vc'] == 'CNAME') {
                                    self::SetError('A host name with a CNAME record can have no other record.');
                                    return FALSE;
                                }
                                if ($same['dn_type_vc'] == $type[$NewId] && (string) $same['dn_target_vc'] === self::CleanRecord(isset($target[$NewId]) ? $target[$NewId] : '', $type[$NewId])) {
                                    self::SetError('That record already exists.');
                                    return FALSE;
                                }
                            }

                            if ($type[$NewId] != "SRV") {
                                if (!($hostName[$NewId] == '*' or self::IsValidTargetName($hostName[$NewId]) )) {
                                    self::SetError('Hostname invalid.');
                                    return FALSE;
                                }
                            }
                        }
                        //TTL
                        if (isset($ttl[$NewId]) && !fs_director::CheckForEmptyValue($ttl[$NewId])) {
                            if (!is_numeric($ttl[$NewId])) {
                                self::SetError('TTL must be a numeric value.');
                                return FALSE;
                            }
                        }
                        //TARGET
                        if (isset($target[$NewId]) && !fs_director::CheckForEmptyValue($target[$NewId])) {
                            if ($type[$NewId] == "A") {
                                if (!self::IsValidIPv4($target[$NewId])) {
                                    self::SetError('IP Address is not a valid IPV4 address.');
                                    return FALSE;
                                }
                            } elseif ($type[$NewId] == "AAAA") {
                                if (!self::IsValidIPv6($target[$NewId])) {
                                    self::SetError('IP Address is not a valid IPV6 address');
                                    return FALSE;
                                }
                            } elseif ($type[$NewId] == "TXT") {

                            } elseif ($type[$NewId] == "SPF") {

                            } elseif ($type[$NewId] == "NS") {

                            } else {
                                if (!self::IsValidIP($target[$NewId])) {
                                    if (!self::IsValidDomainName($target[$NewId])) {
                                        self::SetError('An invalid domain name character was entered. Domain names are limited to alphanumeric characters and hyphens.');
                                        return FALSE;
                                    }
                                }
                                if (!self::IsValidDomainName($target[$NewId])) {
                                    if (!self::IsValidIP($target[$NewId])) {
                                        self::SetError('Target is not a valid IP address');
                                        return FALSE;
                                    }
                                }
                            }
                        }
                        //PRIORITY
                        if (isset($priority[$NewId]) && !fs_director::CheckForEmptyValue($priority[$NewId])) {
                            if (!is_numeric($priority[$NewId])) {
                                self::SetError('Priority must be a numeric value.');
                                return FALSE;
                            }
                            if ($priority[$NewId] < 0 || $priority[$NewId] > 65535) {
                                self::SetError('The priority of a dns record must be a numeric value between 0 and 65535');
                                return FALSE;
                            }
                        }
                        //WEIGHT
                        if (isset($weight[$NewId]) && !fs_director::CheckForEmptyValue($weight[$NewId])) {
                            if (!is_numeric($weight[$NewId])) {
                                self::SetError('Weight must be a numeric value.');
                                return FALSE;
                            }
                            if ($weight[$NewId] < 0 || $weight[$NewId] > 65535) {
                                self::SetError('The weight of a dns record must be a numeric value between 0 and 65535');
                                return FALSE;
                            }
                        }
                        //PORT
                        if (isset($port[$NewId]) && !fs_director::CheckForEmptyValue($port[$NewId])) {
                            if (!is_numeric($port[$NewId])) {
                                self::SetError('PORT must be a numeric value.');
                                return FALSE;
                            }
                            if ($port[$NewId] < 0 || $port[$NewId] > 65535) {
                                self::SetError('The port of a dns record must be a numeric value between 0 and 65535');
                                return FALSE;
                            }
                        }
                    }
                }
            }
        }
        return true;
    }

    /**
     * Check a host name: '@', or dot-separated labels of letters, digits and dashes (no dash at the end).
     *
     * @param string $a The host name.
     * @return bool
     */
    static function IsValidDomainName($a)
    {
        if ($a != "@") {
            $part = explode(".", $a);
            foreach ($part as $check) {
                if (!preg_match('/^[a-z\d][a-z\d\-]{0,62}$/i', $check) || preg_match('/-$/', $check)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Check a target name: like IsValidDomainName() but labels may also contain underscores.
     *
     * @param string $a The target name.
     * @return bool
     */
    static function IsValidTargetName($a)
    {
        if ($a != "@") {
            $part = explode(".", $a);
            foreach ($part as $check) {
                if (!preg_match('/^[a-z\d_][a-z\d\-_]{0,62}$/i', $check) || preg_match('/-$/', $check)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Clean a record value for the zone file.
     *
     * Removes control characters and surrounding space; for SPF/TXT removes quotes and
     * adds slashes, for other types removes spaces and lowercases; an empty NS/MX value becomes '@'.
     *
     * @param mixed $data The value (used as a string).
     * @param string $type The record type.
     * @return string The cleaned value.
     */
    static function CleanRecord($data, $type)
    {
        // No line breaks or other control characters: a record is one line of the zone
        // file (a line break could add any line, such as a $INCLUDE directive)
        $data = preg_replace('/[\x00-\x1F\x7F]/', '', (string) $data);
        $data = trim($data);
        if ($type == 'SPF' || $type == 'TXT') {
            $data = str_replace('"', '', $data);
            $data = str_replace('\'', '', $data);
            $data = addslashes($data);
        } else {
            $data = str_replace(' ', '', $data);
        }
        //Add '@' if hostname is blank on NS and MX records.
        if ($type == 'NS' || $type == 'MX') {
            if ($data == '') {
                $data = "@";
            }
        }

        // We'll leave the content for SPF and TXT records and won't try to make them look better by strtolower'ing them.
        if (!($type == 'SPF' || $type == 'TXT')) {
            $data = strtolower($data);
        }

        return $data;
    }

    /**
     * Check that a record type is in the allowed_types setting.
     *
     * @global db_driver $zdbh The panel's database connection (unused).
     * @param string $type The record type.
     * @return bool
     */
    static function IsTypeAllowed($type)
    {
        global $zdbh;
        $record_types = ctrl_options::GetSystemOption('allowed_types');
        $record_types = explode(' ', $record_types);
        if (in_array($type, $record_types)) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    /**
     * Check for a public IPv4 or IPv6 address (no private or reserved ranges).
     *
     * @param string $ip The address.
     * @return bool
     */
    static function IsValidIP($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    /**
     * Check for a public IPv4 address (no private or reserved ranges).
     *
     * @param string $ip The address.
     * @return bool
     */
    static function IsValidIPv4($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    /**
     * Check for a public IPv6 address (no private or reserved ranges).
     *
     * @param string $ip The address.
     * @return bool
     */
    static function IsValidIPv6($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    /**
     * The message box for the last save (template <@ Result @>).
     *
     * @return string|null The HTML of the message, or null when there is nothing to show.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$ResultOk)) {
            return ui_sysmessage::shout(self::t(self::$ResultOk), 'zannouncesuccess', self::t('SUCCESS DNS SAVED'));
        } elseif (!fs_director::CheckForEmptyValue(self::$ResultErr)) {
            return ui_sysmessage::shout(self::t(self::$ResultErr), 'zannounceerror', self::t('ERROR DNS NOT SAVED'));
        }
        return;
    }

    /**
     * Add a domain ID to the dns_hasupdates list so the daemon rewrites its zone.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (unused).
     * @param int|string $id The domain (x_vhosts) ID.
     * @return true|null True when the ID was added, null when it was already in the list.
     */
    static function TriggerDNSUpdate($id)
    {
        global $zdbh;
        global $controller;
        $records_list = ctrl_options::GetSystemOption('dns_hasupdates');
        $record_array = explode(',', $records_list);
        if (!in_array($id, $record_array)) {
            if (empty($records_list)) {
                $records_list .= $id;
            } else {
                $records_list .= ',' . $id;
            }
            $sql = "UPDATE x_settings SET so_value_tx=:newlist WHERE so_name_vc='dns_hasupdates'";
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':newlist', $records_list);
            $sql->execute();
            return true;
        }
    }

    /** @var string|false|null The zone test file of this request (its name is removed from named-checkzone's message). */
    static $zonecheckFile = null;

    /**
     * A value for the zone test file: no line breaks or control characters (as the daemon writes zones).
     *
     * @param mixed $v The value (used as a string).
     * @return string The value without control characters.
     */
    static function ZoneValue($v)
    {
        return preg_replace('/[\x00-\x1F\x7F]/', '', (string) $v);
    }

    /**
     * Make a new, private (0600) zone test file in the panel's own etc/tmp folder.
     *
     * Stores its path in self::$zonecheckFile.
     *
     * @return string|false The file path, or false when it could not be made there.
     */
    static function ZoneCheckFile()
    {
        $dir = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/etc/tmp';
        $file = @tempnam($dir, 'zonecheck-');
        if ($file === false || strpos($file, $dir . '/') !== 0) {
            return false;
        }
        @chmod($file, 0600);
        self::$zonecheckFile = $file;
        return $file;
    }

    /**
     * Write a domain's records to a test zone file and run named-checkzone on it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $domainID The domain (x_vhosts) ID.
     * @return string|null The output of named-checkzone; '' when no test file could be made; null when the domain has no records.
     */
    static function CheckZoneRecord($domainID)
    {
        global $zdbh;
        $hasrecords = false;
        $sql = 'SELECT COUNT(*) FROM x_dns WHERE dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL';
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':domainID', $domainID);

        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                $hasrecords = true;
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL ORDER BY dn_type_vc");
                $sql->bindParam(':domainID', $domainID);
                $sql->execute();
                $numrows = $zdbh->prepare("SELECT dn_name_vc FROM x_dns WHERE dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL");
                $numrows->bindParam(':domainID', $domainID);
                $numrows->execute();
                $domain = $numrows->fetch();

                // The zone test file: a new file with a random name in the panel's own temp
                // folder (closed to the sites). It was <temp_dir>/<domain>.txt in the shared
                // temp folder every site can write to: a link put there under that name
                // would have made the panel write the zone wherever it pointed.
                $zonecheck_file = self::ZoneCheckFile();
                if ($zonecheck_file === false) {
                    return '';
                }
                $checkline = "$" . "TTL 10800" . fs_filehandler::NewLine();
                $checkline .= "@ IN SOA " . $domain['dn_name_vc'] . ". ";
                $checkline .= "postmaster." . $domain['dn_name_vc'] . ". (" . fs_filehandler::NewLine();
                $checkline .= " " . date("Ymdt") . " ;serial" . fs_filehandler::NewLine();
                $checkline .= " " . ctrl_options::GetSystemOption('refresh_ttl') . " ;refresh after 6 hours" . fs_filehandler::NewLine();
                $checkline .= " " . ctrl_options::GetSystemOption('retry_ttl') . " ;retry after 1 hour" . fs_filehandler::NewLine();
                $checkline .= " " . ctrl_options::GetSystemOption('expire_ttl') . " ;expire after 1 week" . fs_filehandler::NewLine();
                $checkline .= " " . ctrl_options::GetSystemOption('minimum_ttl') . " ) ;minimum TTL of 1 day" . fs_filehandler::NewLine();
                while ($rowdns = $sql->fetch()) {
                    if (self::ZoneValue($rowdns['dn_type_vc']) == "A") {
                        $checkline .= self::ZoneValue($rowdns['dn_host_vc']) . " " . self::ZoneValue($rowdns['dn_ttl_in']) . " IN A " . self::ZoneValue($rowdns['dn_target_vc']) . fs_filehandler::NewLine();
                    }
                    if (self::ZoneValue($rowdns['dn_type_vc']) == "AAAA") {
                        $checkline .= self::ZoneValue($rowdns['dn_host_vc']) . " " . self::ZoneValue($rowdns['dn_ttl_in']) . " IN AAAA " . self::ZoneValue($rowdns['dn_target_vc']) . fs_filehandler::NewLine();
                    }
                    if (self::ZoneValue($rowdns['dn_type_vc']) == "CNAME") {
                        $checkline .= self::ZoneValue($rowdns['dn_host_vc']) . " " . self::ZoneValue($rowdns['dn_ttl_in']) . " IN CNAME " . self::ZoneValue($rowdns['dn_target_vc']) . fs_filehandler::NewLine();
                    }
                    if (self::ZoneValue($rowdns['dn_type_vc']) == "MX") {
                        $checkline .= self::ZoneValue($rowdns['dn_host_vc']) . " " . self::ZoneValue($rowdns['dn_ttl_in']) . " IN MX " . self::ZoneValue($rowdns['dn_priority_in']) . " " . self::ZoneValue($rowdns['dn_target_vc']) . "." . fs_filehandler::NewLine();
                    }
                    if (self::ZoneValue($rowdns['dn_type_vc']) == "TXT") {
                        $checkline .= self::ZoneValue($rowdns['dn_host_vc']) . " " . self::ZoneValue($rowdns['dn_ttl_in']) . " IN TXT \"" . stripslashes(self::ZoneValue($rowdns['dn_target_vc'])) . "\"" . fs_filehandler::NewLine();
                    }
                    if (self::ZoneValue($rowdns['dn_type_vc']) == "SRV") {
                        $checkline .= self::ZoneValue($rowdns['dn_host_vc']) . " " . self::ZoneValue($rowdns['dn_ttl_in']) . " IN SRV " . self::ZoneValue($rowdns['dn_priority_in']) . " " . self::ZoneValue($rowdns['dn_weight_in']) . " " . self::ZoneValue($rowdns['dn_port_in']) . " " . self::ZoneValue($rowdns['dn_target_vc']) . "." . fs_filehandler::NewLine();
                    }
                    if (self::ZoneValue($rowdns['dn_type_vc']) == "SPF") {
                        $checkline .= self::ZoneValue($rowdns['dn_host_vc']) . " " . self::ZoneValue($rowdns['dn_ttl_in']) . " IN SPF \"" . stripslashes(self::ZoneValue($rowdns['dn_target_vc'])) . "\"" . fs_filehandler::NewLine();
                    }
                    if (self::ZoneValue($rowdns['dn_type_vc']) == "NS") {
                        $checkline .= self::ZoneValue($rowdns['dn_host_vc']) . " " . self::ZoneValue($rowdns['dn_ttl_in']) . " IN NS " . self::ZoneValue($rowdns['dn_target_vc']) . "." . fs_filehandler::NewLine();
                    }
                }
                file_put_contents($zonecheck_file, $checkline);
            }
        }
        if ($hasrecords == true) {
            //Check the temp zone record for errors
            if (file_exists($zonecheck_file)) {
                ob_start();

                $command = ctrl_options::GetSystemOption('named_checkzone');
                $args = array(
                    $domain['dn_name_vc'],
                    $zonecheck_file
                );
                $retval = ctrl_system::systemCommand($command, $args);

                $content_grabbed = ob_get_contents();
                ob_end_clean();
                unlink($zonecheck_file);
                if ($retval == 0) {
                    //Syntax check passed.
                    return $content_grabbed;
                } else {
                    //Syntax ERROR.
                    return $content_grabbed;
                }
            }
        }
    }

}
