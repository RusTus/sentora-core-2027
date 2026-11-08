<?php

/**
 * Follow a change of the server's own address (daemon, every run).
 *
 * The websites' Apache settings name their domains (*:80, *:443), so they keep working when the server's IP
 * changes; the panel's own "answer on the server's IP" alias (SentoraPanelAliases) and the panel's IP
 * setting (server_ip, used for DNS records and shown in the panel) do not follow by themselves: after an IP
 * change the panel answered "Not Found" on the new address until someone ran setso. Now:
 *
 * - when the server's own addresses differ from the last run, Apache's settings are written again (the
 *   alias follows within 5 minutes);
 * - when server_ip is a local address the server no longer has and it now has exactly one local IPv4
 *   address, server_ip becomes that one, and the DNS records that pointed to the old one follow (as AutoIP
 *   does for a public address). The change is logged and emailed to the alert address.
 *
 * A public server_ip (the address the server is reached on through a router) is left alone: AutoIP
 * follows that one. With no or several local addresses it does not guess, and says so once.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-08
 */
class sys_serverip
{
    /** The setting that keeps the addresses seen at the last run. */
    const SEEN = 'server_ips_seen';

    /**
     * The server's own IPv4 addresses (not loopback).
     *
     * @return string[]
     */
    static function Own()
    {
        $out = array();
        foreach (preg_split('/\s+/', trim((string) @shell_exec('hostname -I 2>/dev/null'))) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($ip, '127.') !== 0) {
                $out[] = $ip;
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /**
     * Is it a local (private or link-local) IPv4 address?
     *
     * @param string $ip
     * @return bool
     */
    static function IsLocal($ip)
    {
        return (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|169\.254\.)/', (string) $ip);
    }

    /**
     * Daemon: notice a change of the server's addresses and follow it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string[]|null $own Optional. The server's addresses (for testing; default: Own()).
     * @return string What happened ('' when nothing changed).
     */
    static function Follow($own = null)
    {
        global $zdbh;
        $own = $own === null ? self::Own() : array_values($own);
        if (!$own) {
            return '';   // (no address at all: the network is not up; nothing to decide on)
        }
        $now = implode(' ', $own);
        $seen = ctrl_options::GetSystemOption(self::SEEN);
        if ($seen === false) {
            $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
                VALUES (:n, 'Server addresses seen', :v, NULL, 'The server\'s own addresses at the daemon\'s last run (to notice a change).', 'Apache Config', 'false')")
                ->execute(array(':n' => self::SEEN, ':v' => $now));
            $seen = $now;
        }
        $said = array();
        if ((string) $seen !== $now) {
            ctrl_options::SetSystemOption(self::SEEN, $now);
            ctrl_options::SetSystemOption('apache_changed', 'true');
            $said[] = "the server's addresses changed (" . ($seen !== '' ? $seen : 'none') . " -> $now): the panel's settings are written for them";
        }
        $sip = trim((string) ctrl_options::GetSystemOption('server_ip'));
        if ($sip !== '' && self::IsLocal($sip) && !in_array($sip, $own, true)) {
            $local = array_values(array_filter($own, array('sys_serverip', 'IsLocal')));
            if (count($local) === 1) {
                $new = $local[0];
                ctrl_options::SetSystemOption('server_ip', $new);
                $q = $zdbh->prepare("UPDATE x_dns SET dn_target_vc = :new WHERE dn_target_vc = :old AND dn_deleted_ts IS NULL");
                $q->execute(array(':new' => $new, ':old' => $sip));
                $n = $q->rowCount();
                if ($n > 0) {
                    // every zone is written again (as AutoIP does)
                    $list = (string) ctrl_options::GetSystemOption('dns_hasupdates');
                    if (!in_array('0', explode(',', $list), true)) {
                        ctrl_options::SetSystemOption('dns_hasupdates', trim($list . ',0', ','));
                    }
                }
                ctrl_options::SetSystemOption('apache_changed', 'true');
                $said[] = "the panel's IP was $sip, which this server no longer has: it is now $new" . ($n ? " ($n DNS record(s) changed with it)" : '');
                self::Tell("The server's address changed from $sip to $new.\n\nThe panel's IP (Admin > Sentora Config) is now $new"
                    . ($n ? ", and $n DNS record(s) that pointed to $sip point to $new" : '') . ". The panel answers on the new address within 5 minutes.\n\n"
                    . "If the panel should use another address (a public one it is reached on through a router): setso --set server_ip <address>");
            } elseif ((string) ctrl_options::GetSystemOption('server_ip_unsure') !== $sip . '|' . $now) {
                // say it once for this situation, not every 5 minutes
                self::Remember('server_ip_unsure', $sip . '|' . $now);
                $said[] = "the panel's IP is $sip, which this server no longer has, and it has " . (count($local) ? count($local) . ' local addresses (' . implode(', ', $local) . ')' : 'no local address')
                    . ": not changed. Set it: setso --set server_ip <address>";
                self::Tell("The panel's IP (Admin > Sentora Config) is $sip, which this server no longer has (it has: $now).\n\n"
                    . "It was not changed, because it is not clear which address is meant. Set it as root:\n    setso --set server_ip <address>\n"
                    . "The panel does not answer on its IP address until then (its name still works).");
            }
        }
        foreach ($said as $s) {
            echo "Server address: $s" . fs_filehandler::NewLine();
        }
        return implode('; ', $said);
    }

    /**
     * Keep a setting (made when missing).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name
     * @param string $value
     * @return void
     */
    static function Remember($name, $value)
    {
        global $zdbh;
        if (ctrl_options::GetSystemOption($name) === false) {
            $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
                VALUES (:n, :n2, :v, NULL, 'Set by the daemon.', 'Apache Config', 'false')")->execute(array(':n' => $name, ':n2' => $name, ':v' => $value));
        } else {
            ctrl_options::SetSystemOption($name, $value);
        }
    }

    /**
     * Log it in alerts.log and email the alert address (Sentora Config; zadmin's when empty).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $text
     * @return void
     */
    static function Tell($text)
    {
        global $zdbh;
        $log = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/') . '/alerts.log';
        @file_put_contents($log, date('Y-m-d H:i:s') . "\tserver address\t" . str_replace("\n", ' ', $text) . "\n", FILE_APPEND);
        $to = trim((string) ctrl_options::GetSystemOption('alert_email'));
        if ($to === '') {
            $to = (string) $zdbh->query("SELECT ac_email_vc FROM x_accounts WHERE ac_user_vc = 'zadmin'")->fetchColumn();
        }
        if ($to === '' || !class_exists('sys_email')) {
            return;
        }
        try {
            $m = new sys_email();
            $m->Subject = 'Sentora: the server\'s address changed (' . (string) ctrl_options::GetSystemOption('sentora_domain') . ')';
            $m->Body = $text;
            foreach (array_filter(array_map('trim', explode(',', $to))) as $a) {
                $m->AddAddress($a);
            }
            $m->SendEmail();
        } catch (Exception $e) {
        }
    }
}
