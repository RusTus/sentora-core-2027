<?php

/**
 *
 * Sentora - A Cross-Platform Open-Source Web Hosting Control panel.
 *
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (Sentora) is free software: you can redistribute it and/or modify
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
 * Sentora Config module (Admin): the panel's general settings and the daemon run times.
 */
class module_controller extends ctrl_module
{

    /** @var string|null What the last action did: 'saved', 'nochange', 'daemon' (shown by getResult()). */
    static $done;

    /** @var array<int, string> Settings that were not saved, with the reason (shown by getResult()). */
    static $errors = array();

    /**
     * The sections of the page and the settings in each, in order. A setting in the database that is
     * not listed here is shown under "Other", so a new one never goes missing.
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, string>}> Key => [title, intro, setting names].
     */
    static function Sections()
    {
        return array(
            'panel' => array('Panel', 'How the panel is reached and shown.',
                array('sentora_port', 'server_ip', 'panel_fallback', 'panel_ip_access', 'Sentora_df', 'root_drive', 'servicechk_to', 'panel_ssl_tx')),
            'security' => array('Security', 'Logins and passwords.',
                array('twofactor_enabled', 'password_minlength', 'login_csfr')),
            'email' => array('Email', 'How the panel sends email: notices, password resets and the welcome message.',
                array('mailer_type', 'email_from_name', 'email_from_address', 'email_smtp', 'smtp_server', 'smtp_port', 'smtp_secure', 'smtp_auth', 'smtp_username', 'smtp_password', 'welcome_message')),
            'logging' => array('Logging', 'Where errors go, and whether PHP shows them.',
                array('debug_mode', 'logmode', 'logfile')),
            'updates' => array('Updates', 'Whether the panel and its add-on modules update themselves. Both are off unless you turn them on: then you are trusting each new release to work on this server. Every Sentora release is installed and checked on Ubuntu, Debian, AlmaLinux, Rocky Linux and Fedora before it is published, and a release is only installed here once it has been out for a day, but your server can still differ from the test machines. The panel is backed up before every update and checked afterwards: if the daemon or the login page fails, it is put back by itself. Each update, and anything that went wrong, is shown as a notice and emailed to the administrator. Leave these off if you would rather read the change log and press Update yourself (Panel Update, Repo Browser).',
                array('panel_autoupdate', 'addons_autoupdate', 'panel_autoupdate_hour')),
            'feedback' => array('Feedback', 'A short daily report about this server for the Sentora-2027 project (nothing private: what is sent is in /var/lib/sentora/feedback-last.json), and the feedback server that also counts the add-on downloads Repo Browser shows. Without a feedback server, nothing is sent or counted: everything else works the same.',
                array('customer_feedback', 'feedback_url')),
        );
    }

    /**
     * How each setting is shown: a label, help text, the field type and names for its choices.
     * Anything not listed falls back to the database's own name and description.
     *
     * @return array<string, array<string, mixed>> Setting name => 'label', 'help', 'type' (text, number, email, area, code, switch, select, password), 'min', 'max', 'choices', 'clear' (may be emptied), 'apache' (changing it rewrites the vhosts).
     */
    static function Fields()
    {
        return array(
            'sentora_port' => array('label' => 'Panel port', 'help' => 'The port Apache serves the panel on (80, or 443 with SSL). Takes effect on the next daemon run.', 'type' => 'number', 'min' => 1, 'max' => 65535, 'apache' => true),
            'server_ip' => array('label' => 'Server IP address', 'help' => "This server's public address, used in DNS records and the panel's links. Empty: found automatically.", 'type' => 'text', 'clear' => true, 'ip' => true, 'apache' => true),
            'panel_fallback' => array('label' => 'Site not found falls back to panel login', 'help' => "On: an address this server has no site for (a typo, a domain not set up yet, the server's IP) opens the panel login, so new users always find the panel. Off: those get a plain 404 (Not Found), so IP scanners and bots do not learn a Sentora panel is here, and a domain whose SSL is not set up shows Not Found rather than your panel. Off is the more private choice once you know the panel's address.", 'type' => 'switch', 'apache' => true),
            'panel_ip_access' => array('label' => 'Serve the panel on the server IP', 'help' => "Also show the panel at the server's own IP address (https://ip), not only at its host name. Matters only when \"Site not found falls back to panel login\" is off (on, the IP opens the panel anyway).", 'type' => 'switch', 'apache' => true),
            'Sentora_df' => array('label' => 'Date format', 'help' => 'How dates are shown in the panel, as a PHP date() format.', 'type' => 'text', 'date' => true),
            'root_drive' => array('label' => 'Root drive', 'help' => 'The drive Sentora is installed on, for the disk figures.', 'type' => 'text'),
            'servicechk_to' => array('label' => 'Service check timeout', 'help' => 'Seconds to wait for a service before it is shown as down.', 'type' => 'number', 'min' => 1, 'max' => 120),
            'panel_ssl_tx' => array('label' => 'Panel SSL settings', 'help' => "Apache SSL lines for the panel's own site. Sencrypt fills this in when you secure the panel; empty: the panel is served without SSL.", 'type' => 'code', 'clear' => true, 'apache' => true),
            'twofactor_enabled' => array('label' => 'Two-factor login', 'help' => 'A 6-digit code from an authenticator app as well as the password.', 'type' => 'select',
                'choices' => array('disabled' => 'Off', 'enabled' => 'Each account can turn it on', 'enabled-forced' => 'Required for every account')),
            'password_minlength' => array('label' => 'Shortest password', 'help' => 'The fewest characters a new password may have.', 'type' => 'number', 'min' => 6, 'max' => 128),
            'login_csfr' => array('label' => 'Allow remote login forms', 'help' => "Accept logins posted from other sites' forms. This turns off the login form's protection against forged requests: leave it off unless you need it.", 'type' => 'switch'),
            'mailer_type' => array('label' => 'Send email with', 'help' => "PHP's mail() uses this server's mail system; SMTP sends through the server below.", 'type' => 'select',
                'choices' => array('mail' => 'PHP mail()', 'smtp' => 'SMTP', 'sendmail' => 'Sendmail')),
            'email_from_name' => array('label' => 'From name', 'help' => 'The sender name on the email the panel sends.', 'type' => 'text'),
            'email_from_address' => array('label' => 'From address', 'help' => 'The sender address on the email the panel sends.', 'type' => 'email'),
            'email_smtp' => array('label' => 'Use an SMTP server', 'help' => 'Send through the SMTP server below.', 'type' => 'switch'),
            'smtp_server' => array('label' => 'SMTP server', 'help' => 'Host name or address.', 'type' => 'text', 'clear' => true, 'smtp' => true),
            'smtp_port' => array('label' => 'SMTP port', 'help' => '25, 465 (SSL) or 587 (TLS).', 'type' => 'number', 'min' => 1, 'max' => 65535, 'smtp' => true),
            'smtp_secure' => array('label' => 'Encryption', 'help' => 'How the connection to the SMTP server is secured.', 'type' => 'select',
                'choices' => array('false' => 'None', 'ssl' => 'SSL', 'tls' => 'TLS (STARTTLS)'), 'smtp' => true),
            'smtp_auth' => array('label' => 'Log in to the SMTP server', 'help' => 'The server needs a user name and password.', 'type' => 'switch', 'smtp' => true),
            'smtp_username' => array('label' => 'SMTP user', 'help' => '', 'type' => 'text', 'clear' => true, 'smtp' => true, 'auth' => true),
            'smtp_password' => array('label' => 'SMTP password', 'help' => 'Leave empty to keep the saved one.', 'type' => 'password', 'smtp' => true, 'auth' => true),
            'welcome_message' => array('label' => 'Welcome email', 'help' => 'Sent to new clients when their account is made. {{fullname}}, {{username}}, {{password}} and {{controlpanelurl}} are filled in.', 'type' => 'area', 'wide' => true),
            'debug_mode' => array('label' => 'PHP errors', 'help' => 'Development shows PHP errors, warnings and notices on the page; keep it on Production for a live server.', 'type' => 'select',
                'choices' => array('prod' => 'Production: hide them', 'dev' => 'Development: show them')),
            'logmode' => array('label' => 'Log errors to', 'help' => '', 'type' => 'select',
                'choices' => array('db' => 'The database', 'file' => 'A file (below)', 'email' => 'Email')),
            'logfile' => array('label' => 'Log file', 'help' => 'Used when errors are logged to a file.', 'type' => 'text'),
            'panel_autoupdate' => array('label' => 'Keep the panel up to date', 'help' => 'Install each new Sentora release by itself, at the hour below, once it has been out for a day. The panel is backed up first and checked afterwards; if it does not work, it is put back and you get a notice and an email. A release that had to be put back is not tried again by itself.', 'type' => 'switch'),
            'addons_autoupdate' => array('label' => 'Keep add-on modules up to date', 'help' => 'Install new versions of the add-on modules from your repositories by themselves, at the same hour, once each has been out for a day (as Update all in Repo Browser). A notice says which were updated, and which failed.', 'type' => 'switch'),
            'panel_autoupdate_hour' => array('label' => 'Update hour', 'help' => 'When the automatic updates are installed, in this server\'s time. Choose a quiet hour: the panel is unavailable for a minute or two while it updates.', 'type' => 'select',
                'choices' => array('0' => '00:00', '1' => '01:00', '2' => '02:00', '3' => '03:00', '4' => '04:00', '5' => '05:00', '6' => '06:00', '7' => '07:00', '8' => '08:00', '9' => '09:00', '10' => '10:00', '11' => '11:00', '12' => '12:00', '13' => '13:00', '14' => '14:00', '15' => '15:00', '16' => '16:00', '17' => '17:00', '18' => '18:00', '19' => '19:00', '20' => '20:00', '21' => '21:00', '22' => '22:00', '23' => '23:00')),
            'customer_feedback' => array('label' => 'Send customer feedback', 'help' => 'Once a day: the panel version, operating system, hardware, software versions, public and internal IP address, how busy the server is and counts (accounts, domains, mailboxes). Never a domain, user name, email address, password or file. Off: nothing is sent, and add-on downloads are not counted.', 'type' => 'switch'),
            'feedback_url' => array('label' => 'Feedback server', 'help' => 'Its address, such as https://stats.example.com (reports go to its api.php; it counts add-on downloads at /repo). Empty: the one the Sentora-2027 project publishes, if any.', 'type' => 'text', 'clear' => true, 'https' => true),
        );
    }

    /**
     * The module's user-editable settings from x_settings, keyed by name.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<string, array<string, mixed>> Setting name => its x_settings row.
     */
    static function Settings()
    {
        global $zdbh;
        $sql = $zdbh->prepare("SELECT * FROM x_settings WHERE so_module_vc = :module AND so_usereditable_en = 'true' ORDER BY so_cleanname_vc");
        $module = ui_module::GetModuleName();
        $sql->bindParam(':module', $module);
        $sql->execute();
        $res = array();
        while ($row = $sql->fetch()) {
            $res[$row['so_name_vc']] = $row;
        }
        return $res;
    }

    /**
     * Whether a list of choices is just true and false (shown as a switch).
     *
     * @param string|null $defvalues The so_defvalues_tx value ("a|b|c").
     * @return bool True for "true|false" in either order.
     */
    static function IsBoolean($defvalues)
    {
        $v = explode('|', strtolower((string) $defvalues));
        sort($v);
        return $v === array('false', 'true');
    }

    /**
     * The settings form: one pane per section, each a grid of fields (template <@ SettingsPanes @>).
     *
     * @return string The HTML.
     */
    static function getSettingsPanes()
    {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $t = function ($s) { return ui_language::translate($s); };
        $settings = self::Settings();
        $fields = self::Fields();
        $sections = self::Sections();
        $listed = array();
        foreach ($sections as $sec) {
            $listed = array_merge($listed, array_map('strtolower', $sec[2]));
        }
        $other = array();
        foreach ($settings as $name => $row) {
            if (!in_array(strtolower($name), $listed, true)) {
                $other[] = $name;
            }
        }
        if ($other) {
            $sections['other'] = array('Other', 'Settings this page has no section for.', $other);
        }
        // the settings by lower-case name: Sentora_df is stored with a capital
        $byname = array();
        foreach ($settings as $name => $row) {
            $byname[strtolower($name)] = $row;
        }
        $out = '';
        foreach ($sections as $key => $sec) {
            $rows = '';
            foreach ($sec[2] as $name) {
                if (!isset($byname[strtolower($name)])) {
                    continue;
                }
                $row = $byname[strtolower($name)];
                $name = $row['so_name_vc'];
                $f = isset($fields[$name]) ? $fields[$name] : array();
                $label = isset($f['label']) ? $f['label'] : $row['so_cleanname_vc'];
                $help = isset($f['help']) ? $f['help'] : $row['so_desc_tx'];
                $value = (string) $row['so_value_tx'];
                $type = isset($f['type']) ? $f['type'] : (self::IsBoolean($row['so_defvalues_tx']) ? 'switch' : (ctrl_options::CheckForPredefinedOptions($row['so_defvalues_tx']) ? 'select' : 'text'));
                $id = 'sc_' . preg_replace('/[^A-Za-z0-9_]/', '', $name);
                $attr = ' name="' . $h($name) . '" id="' . $id . '"';
                $cls = 'sc-field' . (!empty($f['wide']) || $type === 'code' ? ' sc-wide' : '') . (!empty($f['smtp']) ? ' sc-smtp' : '') . (!empty($f['auth']) ? ' sc-auth' : '');
                $extra = '';
                switch ($type) {
                    case 'switch':
                        $on = strtolower($value) === 'true';
                        $rows .= '<div class="' . $cls . ' sc-switchrow"><div><label for="' . $id . '">' . $h($t($label)) . '</label>'
                            . ($help !== '' ? '<small>' . $h($t($help)) . '</small>' : '') . '</div>'
                            . '<label class="sc-switch"><input type="hidden" name="' . $h($name) . '" value="false"><input type="checkbox"' . $attr . ' value="true"' . ($on ? ' checked' : '') . '><span class="sc-toggle"></span></label></div>';
                        continue 2;
                    case 'select':
                        $choices = isset($f['choices']) ? $f['choices'] : array();
                        $field = '<select class="form-control"' . $attr . '>';
                        foreach (explode('|', (string) $row['so_defvalues_tx']) as $opt) {
                            $field .= '<option value="' . $h($opt) . '"' . ($opt === $value ? ' selected' : '') . '>' . $h(isset($choices[$opt]) ? $t($choices[$opt]) : $opt) . '</option>';
                        }
                        $field .= '</select>';
                        break;
                    case 'number':
                        $field = '<input class="form-control" type="number"' . $attr . ' value="' . $h($value) . '"'
                            . (isset($f['min']) ? ' min="' . (int) $f['min'] . '"' : '') . (isset($f['max']) ? ' max="' . (int) $f['max'] . '"' : '') . ' required>';
                        break;
                    case 'email':
                        $field = '<input class="form-control" type="text" inputmode="email"' . $attr . ' value="' . $h($value) . '" autocomplete="off" required>';
                        break;
                    case 'password':
                        // the stored password is never sent to the browser: empty keeps it
                        $field = '<input class="form-control" type="password"' . $attr . ' value="" autocomplete="new-password" placeholder="'
                            . $h($t($value !== '' ? 'Saved (leave empty to keep it)' : 'Not set')) . '">';
                        break;
                    case 'area':
                        $field = '<textarea class="form-control" rows="8"' . $attr . '>' . $h($value) . '</textarea>';
                        break;
                    case 'code':
                        $field = '<textarea class="form-control sc-code" rows="5" spellcheck="false"' . $attr . '>' . $h($value) . '</textarea>';
                        break;
                    default:
                        $field = '<input class="form-control" type="text"' . $attr . ' value="' . $h($value) . '" autocomplete="off"' . (empty($f['clear']) ? ' required' : '') . '>';
                }
                if (!empty($f['date'])) {
                    $extra = '<small class="sc-now">' . $h($t('Now:')) . ' <b>' . $h(@date($value)) . '</b></small>';
                }
                $rows .= '<div class="' . $cls . '"><label for="' . $id . '">' . $h($t($label)) . '</label>' . $field
                    . ($help !== '' ? '<small>' . $h($t($help)) . '</small>' : '') . $extra . '</div>';
            }
            if ($rows === '') {
                continue;
            }
            $out .= '<section class="sc-pane" id="sc-' . $key . '" data-title="' . $h($t($sec[0])) . '" role="tabpanel">'
                . '<p class="sc-intro">' . $h($t($sec[1])) . '</p><div class="sc-fields">' . $rows . '</div></section>';
        }
        return $out;
    }

    /**
     * A date in the panel's format (sentora_df), with how long ago or from now as a tooltip-free note.
     *
     * @param int|string $time A Unix time; 0 or empty means never.
     * @return string|false The formatted date, or false for never.
     */
    static function When($time)
    {
        if ((int) $time <= 0) {
            return false;
        }
        return date(ctrl_options::GetSystemOption('sentora_df'), (int) $time);
    }

    /**
     * How far a time is from now, in words ("in 4 min", "2 h ago").
     *
     * @param int $time A Unix time.
     * @return string The words.
     */
    static function Ago($time)
    {
        $d = (int) $time - time();
        $a = abs($d);
        if ($a < 60) {
            $w = ui_language::translate($d >= 0 ? 'under a minute' : 'just now');
            return $d >= 0 ? ui_language::translate('in') . ' ' . $w : $w;
        }
        if ($a < 3600) {
            $w = floor($a / 60) . ' min';
        } elseif ($a < 172800) {
            $w = floor($a / 3600) . ' h';
        } else {
            $w = floor($a / 86400) . ' ' . ui_language::translate('days');
        }
        return $d >= 0 ? ui_language::translate('in') . ' ' . $w : $w . ' ' . ui_language::translate('ago');
    }

    /**
     * The daemon's run times as rows: next run, last run, and the last daily, weekly and monthly jobs (template <@ DaemonRows @>).
     *
     * @return string The HTML.
     */
    static function getDaemonRows()
    {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $last = (int) ctrl_options::GetSystemOption('daemon_lastrun');
        $next = $last > 0 ? $last + (int) ctrl_options::GetSystemOption('daemon_run_interval') : (int) (ceil(time() / 300) * 300);
        if ($next < time()) {
            // overdue: cron runs it on the next 5-minute mark
            $next = (int) (ceil(time() / 300) * 300);
        }
        $rows = array(
            array('Next run', $next, 'sc-next'),
            array('Last run', $last, ''),
            array('Daily jobs', ctrl_options::GetSystemOption('daemon_dayrun'), ''),
            array('Weekly jobs', ctrl_options::GetSystemOption('daemon_weekrun'), ''),
            array('Monthly jobs', ctrl_options::GetSystemOption('daemon_monthrun'), ''),
        );
        $out = '';
        foreach ($rows as $r) {
            $when = self::When($r[1]);
            $out .= '<div class="sc-run ' . $r[2] . '"><span>' . $h(ui_language::translate($r[0])) . '</span>'
                . ($when === false ? '<b class="sc-never">' . $h(ui_language::translate('Not since the last reset')) . '</b>'
                    : '<b>' . $h($when) . '</b><small>' . $h(self::Ago($r[1])) . '</small>') . '</div>';
        }
        return $out;
    }

    /**
     * The figures at the top of the page: panel port, mail method, two-factor and debug mode (template <@ Summary @>).
     *
     * @return string The HTML.
     */
    static function getSummary()
    {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $t = function ($s) { return ui_language::translate($s); };
        $f = self::Fields();
        $pick = function ($name) use ($f, $t) {
            $v = (string) ctrl_options::GetSystemOption($name);
            return isset($f[$name]['choices'][$v]) ? $t($f[$name]['choices'][$v]) : $v;
        };
        $port = (string) ctrl_options::GetSystemOption('sentora_port');
        $ssl = (string) ctrl_options::GetSystemOption('panel_ssl_tx') !== '';
        $mail = ctrl_options::GetSystemOption('email_smtp') === 'true' ? 'SMTP ' . ctrl_options::GetSystemOption('smtp_server') : $pick('mailer_type');
        $debug = ctrl_options::GetSystemOption('debug_mode') === 'dev';
        $tiles = array(
            array('Panel', ($ssl ? 'HTTPS' : 'HTTP') . ' · ' . $t('port') . ' ' . $port, $ssl ? 'ok' : 'warn'),
            array('Email', $mail, ''),
            array('Two-factor login', $pick('twofactor_enabled'), ctrl_options::GetSystemOption('twofactor_enabled') === 'disabled' ? 'warn' : 'ok'),
            array('PHP errors', $debug ? $t('Shown (development)') : $t('Hidden'), $debug ? 'warn' : 'ok'),
        );
        $out = '';
        foreach ($tiles as $x) {
            $out .= '<div class="sc-tile ' . $x[2] . '"><span>' . $h($t($x[0])) . '</span><b>' . $h($x[1]) . '</b></div>';
        }
        return $out;
    }

    /**
     * The panel's address, and the console command that moves it to another name (<@ PanelAddress @>).
     *
     * @return string HTML.
     */
    static function getPanelAddress()
    {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $domain = (string) ctrl_options::GetSystemOption('sentora_domain');
        return sprintf($h(ui_language::translate('The panel\'s address: %s. To move it to another name, run this on the server as root: %s')),
            '<b>https://' . $h($domain) . '</b>', '<code>sentora-change-domain panel.example.com</code>');
    }

    /**
     * Save the posted settings that changed, after checking them; the vhosts are rewritten only when a setting
     * that shapes them changed. Empty values are skipped, except for settings that may be cleared.
     *
     * Checks the CSRF token; sets self::$done and self::$errors.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doUpdateConfig()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $fields = self::Fields();
        $changed = 0;
        $apache = false;
        foreach (self::Settings() as $name => $row) {
            $value = $controller->GetControllerRequest('FORM', $name);
            if ($value === false || $value === null || is_array($value)) {
                continue;
            }
            $f = isset($fields[$name]) ? $fields[$name] : array();
            $label = ui_language::translate(isset($f['label']) ? $f['label'] : $row['so_cleanname_vc']);
            $value = (isset($f['type']) && in_array($f['type'], array('area', 'code'), true)) ? str_replace("\r\n", "\n", (string) $value) : trim((string) $value);
            if ($value === '' && empty($f['clear'])) {
                continue; // an empty box keeps the saved value (and the SMTP password)
            }
            $err = self::Check($row, $f, $value);
            if ($err !== '') {
                // the limits are put in after translating, so the text is one row in x_translations
                self::$errors[] = $label . ': ' . str_replace(array('%min%', '%max%'), array((string) (int) ($f['min'] ?? 0), (string) (int) ($f['max'] ?? 0)), ui_language::translate($err));
                continue;
            }
            if ($value === (string) $row['so_value_tx']) {
                continue;
            }
            $update = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
            $update->bindParam(':value', $value);
            $update->bindParam(':name', $row['so_name_vc']);
            $update->execute();
            $changed++;
            if (!empty($f['apache'])) {
                $apache = true;
            }
        }
        if ($apache) {
            self::SetWriteApacheConfigTrue();
        }
        self::$done = $changed ? 'saved' : 'nochange';
    }

    /**
     * Check a posted value of a setting.
     *
     * @param array<string, mixed> $row The setting's x_settings row.
     * @param array<string, mixed> $f The setting's entry in Fields().
     * @param string $value The posted value.
     * @return string Empty when it is fine, else why not (untranslated; %min% and %max% stand for the field's limits).
     */
    static function Check($row, $f, $value)
    {
        if (ctrl_options::CheckForPredefinedOptions($row['so_defvalues_tx']) && !in_array($value, explode('|', (string) $row['so_defvalues_tx']), true)) {
            return 'not one of the choices.';
        }
        $type = isset($f['type']) ? $f['type'] : '';
        if ($type === 'number' && (!ctype_digit($value) || (isset($f['min']) && (int) $value < $f['min']) || (isset($f['max']) && (int) $value > $f['max']))) {
            return 'must be a whole number from %min% to %max%.';
        }
        if ($type === 'email' && !preg_match('/^[^@\s<>"]+@[^@\s<>"]+$/', $value)) {
            return 'not an email address.';
        }
        if (!empty($f['ip']) && $value !== '' && filter_var($value, FILTER_VALIDATE_IP) === false) {
            return 'not an IP address.';
        }
        if (in_array($type, array('text', 'email', 'number', 'password'), true) && preg_match('/[\r\n]/', $value)) {
            return 'must be on one line.';
        }
        if (!empty($f['https']) && $value !== '' && !preg_match('#^https://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/%-]*)?$#', $value)) {
            return 'must be an https:// address.';
        }
        if (!empty($f['date']) && trim($value) === '') {
            return 'can not be empty.';
        }
        return '';
    }

    /**
     * Set apache_changed so the daemon rewrites the Apache vhosts on its next run.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function SetWriteApacheConfigTrue()
    {
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx='true' WHERE so_name_vc='apache_changed'");
        $sql->execute();
    }

    /**
     * Handle "force full daemon run": reset the last run times so every daily, weekly and monthly job runs next time.
     *
     * Checks the CSRF token and sets self::$done.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doForceDaemon()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inForceFull'])) {
            $sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = '0' WHERE so_name_vc IN ('daemon_lastrun', 'daemon_dayrun', 'daemon_weekrun', 'daemon_monthrun')");
            $sql->execute();
            self::$done = 'daemon';
        }
    }

    /**
     * Handle "Run the daemon now": start it at once through zsudo daemon-run (the same command cron runs
     * every 5 minutes, as root). Administrators only; sets self::$done.
     *
     * @return void
     */
    static function doRunDaemon()
    {
        runtime_csfr::Protect();
        $u = ctrl_users::GetUserDetail();
        if (!$u || (int) $u['usergroupid'] !== 1) {
            return;
        }
        $zsudo = (string) ctrl_options::GetSystemOption('zsudo');
        $rc = 1;
        if ($zsudo !== '' && is_executable($zsudo) && function_exists('exec')) {
            $out = array();
            exec(escapeshellcmd($zsudo) . ' daemon-run 2>&1', $out, $rc);
        }
        self::$done = (int) $rc === 0 ? 'daemonnow' : ((int) $rc === 2 ? 'daemonnow-old' : 'daemonnow-fail');
    }

    /**
     * The question asked before queueing a full daemon run (template data attribute <@ ResetConfirm @>).
     *
     * @return string The translated text, escaped for an HTML attribute.
     */
    static function getResetConfirm()
    {
        return htmlspecialchars(ui_language::translate('Queue a full daemon run? Every daily, weekly and monthly job runs again within 5 minutes.'), ENT_QUOTES, 'UTF-8');
    }

    /**
     * The message box for the last action (template <@ Result @>).
     *
     * @return string|null The HTML, or null when there is nothing to show.
     */
    static function getResult()
    {
        $out = '';
        if (self::$errors) {
            $list = '';
            foreach (self::$errors as $e) {
                $list .= '<br>&bull; ' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8');
            }
            $out .= ui_sysmessage::shout(ui_language::translate('These settings were not saved:') . $list, 'zannounceerror');
        }
        if (self::$done === 'saved') {
            $out .= ui_sysmessage::shout(ui_language::translate(self::$errors ? 'The other changes have been saved.' : 'Your settings have been saved.'), 'zannounceok');
        } elseif (self::$done === 'nochange' && !self::$errors) {
            $out .= ui_sysmessage::shout(ui_language::translate('Nothing had changed.'), 'zannounceinfo');
        } elseif (self::$done === 'daemon') {
            $out .= ui_sysmessage::shout(ui_language::translate('A full daemon run is queued: every daily, weekly and monthly job runs within 5 minutes.'), 'zannounceok');
        } elseif (self::$done === 'daemonnow') {
            $out .= ui_sysmessage::shout(ui_language::translate('The daemon is starting now. It usually takes under a minute (longer while another run is busy); reload this page to see the new run times. Its report: /var/sentora/logs/daemon-panel-run.log'), 'zannounceok');
        } elseif (self::$done === 'daemonnow-old') {
            $out .= ui_sysmessage::shout(ui_language::translate('This server\'s zsudo does not know this action yet. Run sentora-update once on the server\'s console, as root: it builds the new zsudo, and then this works from here.'), 'zannounceerror');
        } elseif (self::$done === 'daemonnow-fail') {
            $out .= ui_sysmessage::shout(ui_language::translate('The daemon could not be started from here (check that the queue of root actions runs: systemctl status sentora-zsudo.path). It still runs within 5 minutes.'), 'zannounceerror');
        }
        return $out === '' ? null : $out;
    }

}
