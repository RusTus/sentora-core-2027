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
 * Updated by RusTus, 2026-11-13
 */
/**
 * Backup Config module (Admin): backup settings, off-server backup and panel restore.
 *
 * The settings are rows of x_settings for this module; the off-server backup and
 * the restore requests are carried out by the daemon (hooks/OnDaemonRun.hook.php).
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True after doUpdateConfig() saved the settings (read by getResult()). */
    static $ok;

    /**
     * The module's user-editable settings (x_settings rows of this module).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>> The rows, by clean name.
     */
    static function ConfigRows()
    {
        global $zdbh;
        $moduleName = ui_module::GetModuleName();
        $sql = $zdbh->prepare("SELECT * FROM x_settings WHERE so_module_vc=:module AND so_usereditable_en = 'true' ORDER BY so_cleanname_vc");
        $sql->bindParam(':module', $moduleName);
        $sql->execute();
        return $sql->fetchAll();
    }

    /**
     * Template flag: the module has settings to show (template: <% if Config %>).
     *
     * @return bool
     */
    static function getConfig()
    {
        return count(self::ConfigRows()) > 0;
    }

    /**
     * Template HTML: the settings as form fields: a switch for true/false, a list for other
     * fixed choices, a text box otherwise. The field names are the setting names, as
     * doUpdateConfig() reads them; a switch also sends "false" when it is off.
     *
     * @return string HTML.
     */
    static function getConfigFields()
    {
        $out = '';
        foreach (self::ConfigRows() as $r) {
            $name = (string) $r['so_name_vc'];
            $val = (string) $r['so_value_tx'];
            $label = self::t($r['so_cleanname_vc']);
            $help = self::h(preg_replace('/\s*\((true\/false)\)\s*$/i', '', ui_language::translate((string) $r['so_desc_tx'])));
            if ((string) $r['so_defvalues_tx'] === 'true|false') {
                $out .= '<label class="bk-switch bk-wide"><input type="hidden" name="' . self::h($name) . '" value="false"><input type="checkbox" name="' . self::h($name) . '" value="true"' . ($val === 'true' ? ' checked' : '') . '>'
                    . '<span class="bk-toggle" aria-hidden="true"></span><span><b>' . $label . '</b><small>' . $help . '</small></span></label>';
            } elseif (ctrl_options::CheckForPredefinedOptions($r['so_defvalues_tx'])) {
                $opts = '';
                foreach (explode('|', (string) $r['so_defvalues_tx']) as $o) {
                    $opts .= '<option value="' . self::h($o) . '"' . ($o === $val ? ' selected' : '') . '>' . self::h($o) . '</option>';
                }
                $out .= '<label class="bk-field"><span>' . $label . '</span><select class="form-control" name="' . self::h($name) . '">' . $opts . '</select><small>' . $help . '</small></label>';
            } else {
                $out .= '<label class="bk-field"><span>' . $label . '</span><input class="form-control" type="text" name="' . self::h($name) . '" value="' . self::h($val) . '"><small>' . $help . '</small></label>';
            }
        }
        return $out;
    }

    /**
     * Save the posted values of the module's settings (empty values are skipped).
     *
     * Checks the CSRF token and sets self::$ok.
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
        $moduleName = ui_module::GetModuleName();
        $sql = "SELECT * FROM x_settings WHERE so_module_vc=:module AND so_usereditable_en = 'true'";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':module', $moduleName);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':module', $moduleName);
            $sql->execute();
            while ($row = $sql->fetch()) {
                if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', $row['so_name_vc']))) {
                    $value = $controller->GetControllerRequest('FORM', $row['so_name_vc']);
                    $name = $row['so_name_vc'];
                    $updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
                    $updatesql->bindParam(':value', $value);
                    $updatesql->bindParam(':name', $name);
                    $updatesql->execute();
                }
            }
        }
        self::$ok = true;
    }

    // --- page helpers -------------------------------------------------------------------------

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
     * @param string $name The icon: users, trash, cloud, restore, clock, check, x, plug, play, archive, sliders, alert, refresh.
     * @return string SVG markup, or '' for an unknown name.
     */
    static function Icon($name)
    {
        $p = array(
            'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/>',
            'trash' => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="m6 7 1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
            'cloud' => '<path d="M7 18a5 5 0 1 1 1.6-9.7A6 6 0 0 1 20 11a4 4 0 0 1-1 7.9z"/>',
            'restore' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 8v4l3 2"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
            'x' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
            'plug' => '<path d="M9 2v6M15 2v6"/><path d="M6 8h12v4a6 6 0 0 1-12 0z"/><path d="M12 18v4"/>',
            'play' => '<circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4z"/>',
            'archive' => '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9"/><path d="M10 13h4"/>',
            'sliders' => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
            'alert' => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
            'refresh' => '<path d="M20 12a8 8 0 1 1-2.3-5.7L20 8.5"/><path d="M20 3.5v5h-5"/>',
        );
        return isset($p[$name]) ? '<svg class="bk-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$name] . '</svg>' : '';
    }

    /**
     * "3 hours ago" and the like.
     *
     * @param int $ts A Unix time.
     * @return string Translated text.
     */
    static function Ago($ts)
    {
        $s = max(0, time() - (int) $ts);
        if ($s < 60) {
            return ui_language::translate('just now');
        }
        foreach (array(array(86400, '1 day ago', '%d days ago'), array(3600, '1 hour ago', '%d hours ago'), array(60, '1 minute ago', '%d minutes ago')) as $u) {
            if ($s >= $u[0]) {
                $n = (int) floor($s / $u[0]);
                return str_replace('%d', (string) $n, ui_language::translate($n === 1 ? $u[1] : $u[2]));
            }
        }
        return '';
    }

    /**
     * Translate a text with %s in it, then put the values in, each in place of the next %s
     * (the values are not translated and never stored in x_translations).
     *
     * @param string $text The English text, for example 'Restore of %s from %s queued.'.
     * @param string[] $values The values, in order.
     * @return string The translated text with the values (not escaped).
     */
    static function Fill($text, array $values)
    {
        $i = 0;
        return (string) preg_replace_callback('/%s/', function () use (&$i, $values) {
            return isset($values[$i]) ? (string) $values[$i++] : '';
        }, ui_language::translate($text));
    }

    /**
     * A date and how long ago, or "never".
     *
     * @param mixed $ts A Unix time, or 0.
     * @return string HTML.
     */
    static function When($ts)
    {
        return (int) $ts > 0 ? self::h(date('Y-m-d H:i', (int) $ts)) . ' · ' . self::h(self::Ago((int) $ts)) : self::t('never');
    }

    /**
     * The tab the page opens on: the one the last action belongs to, else '' (the page script then picks).
     *
     * @global runtime_controller $controller The request controller.
     * @return string 'settings', 'offsite', 'restore' or ''.
     */
    static function Tab()
    {
        global $controller;
        if ((string) $controller->GetControllerRequest('URL', 'snap') !== '' || self::$restoreMsg || self::$flash !== '' || isset($_SESSION['bk_restore_msg'])) {
            return 'restore';
        }
        if (self::$offsiteErrors || self::$offsiteSaved || self::$offsiteQueued !== '') {
            return 'offsite';
        }
        return self::$ok ? 'settings' : '';
    }

    /**
     * Template value: the tab to open (data-tab of the page).
     *
     * @return string
     */
    static function getOpenTab()
    {
        return self::Tab();
    }

    /**
     * Template HTML: four cards: client backups, how long they are kept, the off-server backup, the last restore.
     *
     * @return string HTML.
     */
    static function getSummary()
    {
        $opt = function ($k) { return strtolower((string) ctrl_options::GetSystemOption($k)) === 'true'; };
        $card = function ($icon, $color, $label, $value, $detail) {
            return '<div class="bk-card" style="--c:' . $color . '">' . self::Icon($icon) . '<div><div class="bk-card-label">' . self::t($label) . '</div>'
                . '<div class="bk-card-value">' . $value . '</div><div class="bk-card-detail">' . $detail . '</div></div></div>';
        };
        $out = $card('users', '#447fb3', 'Client backups', $opt('disk_bu') ? self::t('On') : self::t('Off'),
            $opt('schedule_bu') ? self::t('plus a daily backup of every account') : self::t('made by clients on their Backup page'));
        $out .= $card('trash', '#f5a623', 'Kept for', $opt('purge_bu') ? str_replace('%s', self::h(ctrl_options::GetSystemOption('purge_date')), self::t('%s days')) : self::t('Until deleted'),
            $opt('purge_bu') ? self::t('older client backups are deleted') : self::t('client backups are not deleted automatically'));
        $o = self::Offsite();
        $st = json_decode((string) @file_get_contents('/var/lib/sentora/offsite-backup.json'), true);
        $st = is_array($st) ? $st : array();
        $failed = isset($st['result']) && $st['result'] !== 'ok';
        $out .= $card('cloud', $o['offsite_enabled'] === 'true' ? ($failed ? '#ff6854' : '#1fa2b5') : '#9aa5b1', 'Off-server backup',
            $o['offsite_enabled'] === 'true' ? sprintf(self::t('Nightly at %s'), self::h($o['offsite_time'])) : self::t('Off'),
            $failed ? '<span class="bk-bad">' . self::t('last run failed') . '</span>' : (!empty($st['last_ok']) ? self::t('last good copy') . ' ' . self::h(self::Ago($st['last_ok'])) : self::t('no copy yet')));
        $rs = self::RestoreJson(self::RESTORE_STATE);
        if (is_file(self::RESTORE_REQ) || !empty($rs['running']) || !empty($rs['listing'])) {
            $out .= $card('restore', '#8e6bd8', 'Restore', self::t('Working'), self::t('see the Restore tab'));
        } elseif (!empty($rs['result'])) {
            $out .= $card('restore', $rs['result'] === 'ok' ? '#2db87d' : '#ff6854', 'Last restore', $rs['result'] === 'ok' ? self::t('Done') : ($rs['result'] === 'partial' ? self::t('With problems') : self::t('Failed')),
                self::h(self::Ago((int) ($rs['finished'] ?? 0))));
        } else {
            $cat = self::RestoreJson(self::RESTORE_CATALOG);
            $n = count((array) ($cat['snapshots'] ?? array()));
            $out .= $card('restore', '#8e6bd8', 'Restore', $n ? str_replace('%d', (string) $n, self::t($n === 1 ? '1 snapshot' : '%d snapshots')) : self::t('Ready'), $n ? self::t('on the backup machine') : self::t('read the backups to list them'));
        }
        return $out;
    }

    // --- off-server backup (its own section: proper fields, an encrypted password) ------------

    /** @var string[] Validation errors of the last off-server backup form post (shown by getResult()). */
    static $offsiteErrors = array();
    /** @var bool True after doSaveOffsite() saved the off-server backup settings. */
    static $offsiteSaved = false;
    /** @var string The job just queued for the daemon: 'check', 'now', or '' for none. */
    static $offsiteQueued = '';

    /**
     * The off-server backup settings, with their defaults.
     *
     * @return array<string, string> Setting name (offsite_*) => default value.
     */
    static function OffsiteDefaults()
    {
        return array('offsite_enabled' => 'false', 'offsite_server' => '', 'offsite_port' => '22', 'offsite_user' => 'sentora-backup',
            'offsite_password' => '', 'offsite_path' => '/srv/sentora-backups', 'offsite_time' => '02:30', 'offsite_keep' => '14',
            'offsite_mail' => 'true', 'offsite_request' => '', 'offsite_hostkey' => '', 'offsite_pubkey' => '');
    }

    /**
     * Read the current off-server backup settings, using the defaults for missing ones.
     *
     * @return array<string, string> Setting name (offsite_*) => value.
     */
    static function Offsite()
    {
        $v = array();
        foreach (self::OffsiteDefaults() as $k => $d) {
            $s = ctrl_options::GetSystemOption($k);
            $v[$k] = ($s === false || $s === null) ? $d : (string) $s;
        }
        return $v;
    }

    /**
     * Store one off-server backup setting, creating the x_settings row when it is missing.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name The setting name (offsite_*).
     * @param string|int $value The value (stored as a string).
     * @return void
     */
    static function SetOffsite($name, $value)
    {
        global $zdbh;
        $q = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = :n");
        $q->execute(array(':v' => (string) $value, ':n' => $name));
        if ($q->rowCount() === 0 && ctrl_options::GetSystemOption($name) === false) {
            $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
                VALUES (:n, :n2, :v, NULL, 'Off-server backup (set in Admin > Backup Config)', 'Backup Config', 'false')")
                ->execute(array(':n' => $name, ':n2' => $name, ':v' => (string) $value));
        }
    }

    /**
     * Validate and save the posted off-server backup form.
     *
     * Checks the CSRF token. On errors fills self::$offsiteErrors and saves nothing;
     * otherwise stores the settings (the password encrypted) and sets self::$offsiteSaved.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doSaveOffsite()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $get = function ($k) use ($f) { return isset($f[$k]) ? trim((string) $f[$k]) : ''; };
        $enabled = $get('inOffsiteEnabled') === '1' ? 'true' : 'false';
        $server = $get('inOffsiteServer');
        $port = $get('inOffsitePort') === '' ? '22' : $get('inOffsitePort');
        $user = $get('inOffsiteUser');
        $path = rtrim($get('inOffsitePath'), '/');
        // ("/" alone: a Sentora backup server, where this panel's folder is the top)
        $path = ($path === '' && $get('inOffsitePath') !== '') ? '/' : $path;
        $hostkey = preg_replace('/\s+/', '', $get('inOffsiteHostkey'));
        $time = $get('inOffsiteTime');
        $keep = $get('inOffsiteKeep');
        $mail = $get('inOffsiteMail') === '1' ? 'true' : 'false';
        $e = array();
        if ($server !== '' && !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,252})$/', $server)) {
            $e[] = 'The server is a host name or address (letters, digits, dots and dashes).';
        }
        if (!preg_match('/^[0-9]{1,5}$/', $port) || (int) $port < 1 || (int) $port > 65535) {
            $e[] = 'The port is a number from 1 to 65535.';
        }
        if ($server !== '' && !preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/i', $user)) {
            $e[] = 'The username has letters, digits, dots, dashes or underscores.';
        }
        if (!preg_match('#^/[A-Za-z0-9._/-]{0,250}$#', $path) || strpos($path, '..') !== false) {
            $e[] = 'The folder is a full path like /srv/sentora-backups (letters, digits, dots, dashes, underscores).';
        }
        if ($hostkey !== '' && !preg_match('#^SHA256:[A-Za-z0-9+/]{43}$#', $hostkey)) {
            $e[] = 'The backup server\'s key is its fingerprint, like SHA256:followed by 43 letters and digits (the backup server shows it when installed).';
        }
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            $e[] = 'The time is HH:MM, for example 02:30.';
        }
        if (!preg_match('/^[0-9]{1,3}$/', $keep) || (int) $keep < 1 || (int) $keep > 365) {
            $e[] = 'Nights kept is a number from 1 to 365.';
        }
        if ($enabled === 'true' && $server === '' && $path === '') {
            $e[] = 'Set a server (or a local folder) before switching it on.';
        }
        if ($e) {
            self::$offsiteErrors = $e;
            return;
        }
        foreach (array('offsite_enabled' => $enabled, 'offsite_server' => $server, 'offsite_port' => $port, 'offsite_user' => $user,
            'offsite_path' => $path, 'offsite_time' => $time, 'offsite_keep' => (string) (int) $keep, 'offsite_mail' => $mail, 'offsite_hostkey' => $hostkey) as $k => $v) {
            self::SetOffsite($k, $v);
        }
        // the password: changed only when a new one is typed, cleared only on request
        $pw = isset($f['inOffsitePassword']) ? (string) $f['inOffsitePassword'] : '';
        if ($get('inOffsiteClearPassword') === '1') {
            self::SetOffsite('offsite_password', '');
        } elseif ($pw !== '') {
            if (strlen($pw) > 256 || preg_match('/[\x00-\x1f]/', $pw)) {
                self::$offsiteErrors = array('The password may not contain control characters (256 characters at most).');
                return;
            }
            $enc = ctrl_secret::Encrypt($pw);
            if ($enc === '') {
                self::$offsiteErrors = array('The password could not be stored encrypted (this server has no secret in cnf/security.php).');
                return;
            }
            self::SetOffsite('offsite_password', $enc);
        }
        self::$offsiteSaved = true;
    }

    /**
     * Test connection / Back up now: queue a job for the daemon (root), run within 5 minutes.
     *
     * Checks the CSRF token; sets self::$offsiteQueued, or self::$offsiteErrors when no
     * server or folder is set.
     *
     * @param string $what The job: 'check' (test connection) or 'now' (back up now).
     * @return void
     */
    static function QueueOffsite($what)
    {
        runtime_csfr::Protect();
        $o = self::Offsite();
        if ($o['offsite_server'] === '' && $o['offsite_path'] === '') {
            self::$offsiteErrors = array('Save a server or a folder first.');
            return;
        }
        self::SetOffsite('offsite_request', $what . ' ' . time());
        self::$offsiteQueued = $what;
    }

    /**
     * Handle the "Test connection" button: queue a connection test.
     *
     * @return void
     */
    static function doOffsiteCheck()
    {
        self::QueueOffsite('check');
    }

    /**
     * Handle the "Back up now" button: queue an off-server backup.
     *
     * @return void
     */
    static function doOffsiteNow()
    {
        self::QueueOffsite('now');
    }

    /**
     * Build the off-server backup settings form and its two action buttons (template <@ OffsiteForm @>).
     *
     * @return string The HTML.
     */
    static function getOffsiteForm()
    {
        $o = self::Offsite();
        $field = function ($label, $input, $help) {
            return '<label class="bk-field"><span>' . self::t($label) . '</span>' . $input . ($help !== '' ? '<small>' . self::t($help) . '</small>' : '') . '</label>';
        };
        $hasPw = ($o['offsite_password'] !== '');
        return '<form class="bk-ftp" action="./?module=backup_admin&amp;action=SaveOffsite" method="post">' . runtime_csfr::Token()
            . '<label class="bk-switch bk-wide"><input type="checkbox" name="inOffsiteEnabled" value="1"' . ($o['offsite_enabled'] === 'true' ? ' checked' : '') . '><span class="bk-toggle" aria-hidden="true"></span>'
            . '<span><b>' . self::t('Copy the backups every night') . '</b><small>' . self::t('Off: nothing is copied (the settings are kept).') . '</small></span></label>'
            . $field('Server', '<input class="form-control" type="text" name="inOffsiteServer" value="' . self::h($o['offsite_server']) . '" placeholder="backup.example.com" maxlength="253">', 'The backup machine, reached over SSH. Empty: the folder below is on this server (for example an NFS mount).')
            . $field('Port', '<input class="form-control" type="text" name="inOffsitePort" value="' . self::h($o['offsite_port']) . '" inputmode="numeric" maxlength="5">', 'SSH port, usually 22.')
            . $field('Username', '<input class="form-control" type="text" name="inOffsiteUser" value="' . self::h($o['offsite_user']) . '" maxlength="32" autocomplete="off">', 'The user on the backup machine.')
            . '<label class="bk-field"><span>' . self::t('Password') . '</span><input class="form-control" type="password" name="inOffsitePassword" value="" maxlength="256" autocomplete="new-password" placeholder="' . ($hasPw ? self::t('stored: leave empty to keep it') : self::t('none: the SSH key is used')) . '">'
                . ($hasPw ? '<span class="bk-check"><input type="checkbox" name="inOffsiteClearPassword" value="1"> ' . self::t('Remove the password and use the SSH key') . '</span>' : '')
                . '<small>' . self::t('Stored encrypted, never shown again. Without a password the SSH key /root/.ssh/sentora_offsite is used (more secure; sentora-offsite-backup --setup makes it).') . '</small></label>'
            . $field('Folder', '<input class="form-control" type="text" name="inOffsitePath" value="' . self::h($o['offsite_path']) . '" maxlength="251">', 'Where the copies go; each server gets its own folder in it, with one folder per night. On a Sentora backup server: / (this panel\'s own folder there).')
            . $field('Backup server\'s key', '<input class="form-control" type="text" name="inOffsiteHostkey" value="' . self::h($o['offsite_hostkey']) . '" placeholder="SHA256:..." maxlength="60" autocomplete="off" spellcheck="false">', 'Its SSH key\'s fingerprint (a Sentora backup server shows it when installed): every copy then checks it is talking to that server and no other. Empty: the first key it sees is trusted.')
            . '<label class="bk-field"><span>' . self::t('Time') . '</span><input class="form-control" type="time" name="inOffsiteTime" value="' . self::h($o['offsite_time']) . '"><small>' . sprintf(self::t('Every day at this time (server time, %s).'), self::h(date('T'))) . '</small></label>'
            . $field('Nights kept', '<input class="form-control" type="text" name="inOffsiteKeep" value="' . self::h($o['offsite_keep']) . '" inputmode="numeric" maxlength="3">', 'Older nights are removed from the backup machine.')
            . '<label class="bk-switch bk-wide"><input type="checkbox" name="inOffsiteMail" value="1"' . ($o['offsite_mail'] === 'true' ? ' checked' : '') . '><span class="bk-toggle" aria-hidden="true"></span>'
            . '<span><b>' . self::t('Include the mailboxes') . '</b><small>' . self::t('Copied incrementally: only new mail is sent each night.') . '</small></span></label>'
            . '<div class="bk-form-foot bk-wide"><button class="btn btn-primary" type="submit">' . self::t('Save settings') . '</button></div></form>';
    }

    /**
     * How to make another server this panel's backup server (template <@ OffsiteServer @>): the
     * one-line install command, carrying this panel's backup key (its public half).
     *
     * @return string The HTML.
     */
    static function getOffsiteServer()
    {
        $o = self::Offsite();
        $pub = (string) $o['offsite_pubkey'];
        if (!preg_match('/^ssh-ed25519 [A-Za-z0-9+\/]{40,100}={0,3}( [A-Za-z0-9@._-]{1,100})?$/', $pub)) {
            return '<p class="bk-muted">' . self::t('This panel\'s backup key is made by the daemon within 5 minutes; the command to install a Sentora backup server then shows here.') . '</p>';
        }
        $cmd = 'curl -fsSL https://raw.githubusercontent.com/RusTus/sentora-installers-2027/master2027/backup/sentora2027-backup-server -o sentora2027-backup-server && sudo bash sentora2027-backup-server --key ' . escapeshellarg($pub);
        return '<ol class="bk-steps">'
            . '<li>' . self::t('On a server of its own (Ubuntu 26.04, Debian 13, AlmaLinux, Rocky Linux or Red Hat Enterprise Linux 10, or Fedora 45; not this panel\'s server), log in, become root and run:') . '</li>'
            . '</ol><pre class="bk-cmd" tabindex="0">' . self::h($cmd) . '</pre>'
            . '<ol class="bk-steps" start="2"><li>' . self::t('It ends with what to enter above: its address as Server, sentora-backup as Username (no password), / as Folder, and its key\'s fingerprint as Backup server\'s key. Save, then press Test connection.') . '</li></ol>'
            . '<p class="bk-muted">' . self::t('This panel\'s key may only copy into its own folder there and read it back: no shell, nothing else. Several panels can share one backup server, each in its own folder.') . '</p>';
    }

    /**
     * Build the off-server backup status: queued job, last connection test, last good copy and a failed run, with the Test connection and Back up now buttons (template <@ OffsiteStatus @>).
     *
     * Reads the queued request and /var/lib/sentora/offsite-backup.json written by the backup script.
     *
     * @return string The HTML.
     */
    static function getOffsiteStatus()
    {
        $o = self::Offsite();
        $st = json_decode((string) @file_get_contents('/var/lib/sentora/offsite-backup.json'), true);
        $st = is_array($st) ? $st : array();
        $line = function ($icon, $class, $label, $when, $message) {
            return '<div class="bk-state ' . $class . '">' . self::Icon($icon) . '<div><b>' . self::t($label) . '</b><span>' . $when . '</span>' . ($message !== '' ? '<span class="bk-sub">' . self::h($message) . '</span>' : '') . '</div></div>';
        };
        $out = '';
        if ($o['offsite_request'] !== '') {
            $out .= $line('clock', 'info', strtok($o['offsite_request'], ' ') === 'check' ? 'Connection test queued' : 'Backup queued', self::t('the daemon runs it within 5 minutes'), '');
        }
        if (isset($st['check_time'])) {
            $ok = !empty($st['check_ok']);
            $out .= $line($ok ? 'check' : 'x', $ok ? 'ok' : 'bad', $ok ? 'Connection test passed' : 'Connection test failed', self::When($st['check_time']), isset($st['check_message']) ? (string) $st['check_message'] : '');
        }
        if (isset($st['result']) && $st['result'] !== 'ok') {
            $out .= $line('x', 'bad', 'Last run failed', self::When($st['last_run'] ?? 0), (string) ($st['message'] ?? ''));
        }
        $out .= $line('archive', '', 'Last good copy', self::When(isset($st['last_ok']) ? $st['last_ok'] : 0), '');
        return $out . '<div class="bk-ftp-actions">'
            . '<form class="bk-inline" method="post" action="./?module=backup_admin&amp;action=OffsiteCheck">' . runtime_csfr::Token() . '<button class="btn btn-default btn-sm bk-btn" type="submit">' . self::Icon('plug') . '<span>' . self::t('Test connection') . '</span></button></form>'
            . '<form class="bk-inline" method="post" action="./?module=backup_admin&amp;action=OffsiteNow">' . runtime_csfr::Token() . '<button class="btn btn-default btn-sm bk-btn" type="submit">' . self::Icon('play') . '<span>' . self::t('Back up now') . '</span></button></form></div>';
    }

    // --- Restore from the full panel backup (sentora-panel-restore, run by the daemon) ----------

    const RESTORE_REQ = '/var/lib/sentora/tmp/panel-restore-request.json';
    const RESTORE_STATE = '/var/lib/sentora/panel-restore.json';
    const RESTORE_CATALOG = '/var/lib/sentora/restore-catalog.json';
    /** @var array<int, array{0: string, 1: string}> Messages for getResult(): array(kind 'ok'|'error', text). */
    static $restoreMsg = array();
    /** @var string The message of a restore request accepted just before the redirect (from the session; kept for this page). */
    static $flash = '';

    /**
     * Read a JSON file of the restore tool.
     *
     * @param string $file Path of the JSON file.
     * @return array<string, mixed> The decoded data, or an empty array when the file is missing or not valid JSON.
     */
    static function RestoreJson($file)
    {
        $d = @json_decode((string) @file_get_contents($file), true);
        return is_array($d) ? $d : array();
    }

    /**
     * A snapshot of the catalog, by server and date (format 2 only), or null.
     *
     * @param string $server The server name of the snapshot.
     * @param string $date The date (night) of the snapshot.
     * @return array<string, mixed>|null The snapshot entry ('server', 'date', 'format', 'accounts', 'databases', ...), or null when not found.
     */
    static function RestoreSnapshot($server, $date)
    {
        foreach ((array) (self::RestoreJson(self::RESTORE_CATALOG)['snapshots'] ?? array()) as $s) {
            if ($s['server'] === (string) $server && $s['date'] === (string) $date) {
                return $s;
            }
        }
        return null;
    }

    /**
     * After a request is accepted: its message in the session and a plain address, so a
     * reload of the page does not send the request again.
     *
     * Sends a Location header and exits.
     *
     * @param string $message The message to show after the redirect (already translated).
     * @param string $snap Optional. The snapshot ('server/date') to open again. Default ''.
     * @return void Never returns (exit).
     */
    static function RestoreDone($message, $snap = '')
    {
        $_SESSION['bk_restore_msg'] = $message;
        header('Location: ./?module=backup_admin' . ($snap !== '' ? '&snap=' . rawurlencode($snap) : '') . '#restore');
        exit;
    }

    /**
     * Write a restore request for the daemon, unless one is already queued or running.
     *
     * @param array<string,mixed> $req The request: 'action' ('list', 'account' or 'full') and its details.
     * @return bool True when queued; false (with a message in self::$restoreMsg) when busy.
     */
    static function RestoreQueue(array $req)
    {
        $st = self::RestoreJson(self::RESTORE_STATE);
        if (!empty($st['running']) || is_file(self::RESTORE_REQ)) {
            self::$restoreMsg[] = array('error', 'A restore is already queued or running: wait for it to finish.');
            return false;
        }
        $req['by'] = ctrl_users::GetUserDetail()['username'];
        $req['time'] = time();
        file_put_contents(self::RESTORE_REQ, json_encode($req));
        return true;
    }

    /**
     * Handle "Read the backups": queue a listing of the snapshots, then redirect.
     *
     * Checks the CSRF token; exits through RestoreDone() when queued.
     *
     * @return void
     */
    static function doRestoreList()
    {
        runtime_csfr::Protect();
        if (self::RestoreQueue(array('action' => 'list'))) {
            self::RestoreDone(ui_language::translate('Reading the backups: the list appears here within 5 minutes.'));
        }
    }

    /**
     * Handle "Restore this account": validate the form and queue the restore of one account.
     *
     * Checks the CSRF token; exits through RestoreDone() when queued, otherwise adds
     * an error to self::$restoreMsg.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doRestoreAccount()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $snap = self::RestoreSnapshot($f['inServer'] ?? '', $f['inDate'] ?? '');
        $user = (string) ($f['inAccount'] ?? '');
        $found = false;
        foreach ((array) ($snap['accounts'] ?? array()) as $a) {
            $found = $found || $a['username'] === $user;
        }
        if (!$snap || (int) $snap['format'] < 2 || !$found) {
            self::$restoreMsg[] = array('error', 'Choose a snapshot and an account in it.');
            return;
        }
        $parts = array();
        foreach (array('files', 'databases', 'mail') as $p) {
            if (!empty($f['inPart_' . $p])) {
                $parts[] = $p;
            }
        }
        if (!$parts) {
            self::$restoreMsg[] = array('error', 'Choose what to restore: websites, databases and/or mail.');
            return;
        }
        if (!empty($f['inExact']) && in_array('files', $parts, true)) {
            $parts[] = 'exact';
        }
        if (self::RestoreQueue(array('action' => 'account', 'server' => $snap['server'], 'date' => $snap['date'], 'username' => $user, 'parts' => $parts))) {
            self::RestoreDone(self::Fill('Restore of %s from %s queued: it starts within 5 minutes. Its progress shows here.', array($user, $snap['date'])), $snap['server'] . '/' . $snap['date']);
        }
    }

    /**
     * Handle "Restore the whole panel": validate the form and queue a full restore.
     *
     * Checks the CSRF token and the typed RESTORE confirmation; exits through
     * RestoreDone() when queued, otherwise adds an error to self::$restoreMsg.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doRestoreFull()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $snap = self::RestoreSnapshot($f['inServer'] ?? '', $f['inDate'] ?? '');
        if (!$snap || (int) $snap['format'] < 2) {
            self::$restoreMsg[] = array('error', 'Choose a snapshot to restore.');
            return;
        }
        if (trim((string) ($f['inConfirm'] ?? '')) !== 'RESTORE') {
            self::$restoreMsg[] = array('error', 'Type RESTORE (in capitals) to confirm: a full restore replaces every account, database and setting on this server.');
            return;
        }
        if (self::RestoreQueue(array('action' => 'full', 'server' => $snap['server'], 'date' => $snap['date']))) {
            self::RestoreDone(self::Fill('Full restore from %s %s queued: it starts within 5 minutes. Its progress shows here.', array($snap['server'], $snap['date'])), $snap['server'] . '/' . $snap['date']);
        }
    }

    /**
     * Build the restore section: job status, the snapshot list and the restore forms (template <@ Restore @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getRestore()
    {
        global $controller;
        $out = '';
        // what is running or ran last
        $st = self::RestoreJson(self::RESTORE_STATE);
        $req = self::RestoreJson(self::RESTORE_REQ);
        if ($req) {
            $out .= '<div class="bk-job"><span class="bk-spinner" aria-hidden="true"></span><div><b>' . self::t($req['action'] === 'list' ? 'Reading the backups' : 'Restore') . ' ' . self::t('queued') . '</b><span class="bk-sub">' . self::t('It starts within 5 minutes. Reload the page to follow it.') . '</span></div></div>';
        } elseif (!empty($st['running']) || !empty($st['listing'])) {
            $out .= '<div class="bk-job"><span class="bk-spinner" aria-hidden="true"></span><div><b>' . self::h(!empty($st['listing']) ? ui_language::translate('Reading the backups') : ui_language::translate('Restoring') . ' ' . ($st['snapshot'] ?? '')) . '…</b>'
                . '<span>' . self::h($st['step'] ?? '') . '</span><span class="bk-sub">' . self::t('Reload the page to follow it. The log: /var/sentora/logs/panel-restore.log') . '</span></div></div>';
        } elseif (!empty($st['result'])) {
            $ok = $st['result'] === 'ok';
            $out .= '<div class="bk-state ' . ($ok ? 'ok' : 'bad') . ' bk-last">' . self::Icon($ok ? 'check' : ($st['result'] === 'partial' ? 'alert' : 'x')) . '<div><b>'
                . self::t($ok ? 'Last restore finished' : ($st['result'] === 'partial' ? 'Last restore finished with problems' : 'Last restore failed')) . '</b><span>' . self::When($st['finished'] ?? 0) . '</span>'
                . '<span class="bk-sub">' . self::h($st['message'] ?? '') . '</span></div></div>';
        }
        $cat = self::RestoreJson(self::RESTORE_CATALOG);
        $out .= '<div class="bk-panel-head"><div><h4>' . self::t('Snapshots on the backup machine') . '</h4><p>'
            . (!empty($cat['time']) ? self::t('Last read') . ' ' . self::When($cat['time']) . (isset($cat['target']) ? ' · ' . self::t('from') . ' ' . self::h($cat['target']) : '')
                : self::t('Lists the snapshots on the backup machine (Off-server backup tab), from any server.')) . '</p></div>'
            . '<form class="bk-inline" method="post" action="./?module=backup_admin&amp;action=RestoreList#restore">' . runtime_csfr::Token()
            . '<button class="btn btn-default btn-sm bk-btn" type="submit">' . self::Icon('refresh') . '<span>' . self::t('Read the backups') . '</span></button></form></div>';
        if (!empty($cat['error'])) {
            return $out . '<div class="bk-state bad">' . self::Icon('x') . '<div><b>' . self::t('The backups could not be read') . '</b><span class="bk-sub">' . self::h($cat['error']) . '</span></div></div>';
        }
        $snaps = (array) ($cat['snapshots'] ?? array());
        if (!$snaps) {
            return $out . '<p class="bk-empty">' . (empty($cat['time']) ? self::t('Read the backups to see which nights can be restored.') : self::t('No snapshots yet.')) . '</p>';
        }
        // the snapshots, newest first; one chosen
        $chosen = (string) $controller->GetControllerRequest('URL', 'snap');
        $rows = '';
        foreach ($snaps as $s) {
            $key = $s['server'] . '/' . $s['date'];
            $rows .= '<tr' . ($key === $chosen ? ' class="on"' : '') . '><td><b>' . self::h($s['date']) . '</b></td><td>' . self::h($s['server'])
                . ($s['server'] === ($cat['this_server'] ?? '') ? ' <span class="bk-tag bk-tag-info">' . self::t('this server') . '</span>' : '') . '</td>'
                . ($s['format'] >= 2
                    ? '<td class="bk-num">' . count($s['accounts']) . '</td><td class="bk-num">' . count($s['databases']) . '</td><td>' . self::h($s['sentora'] ?? '') . '</td>'
                        . '<td class="bk-act"><a class="btn btn-' . ($key === $chosen ? 'primary' : 'default') . ' btn-sm" href="./?module=backup_admin&amp;snap=' . rawurlencode($key) . '#restore">' . self::t('Open') . '</a></td>'
                    : '<td colspan="4" class="bk-sub">' . self::t('made by an older version: restore it by hand') . '</td>') . '</tr>';
        }
        $out .= '<div class="bk-scroll bk-snaps"><table class="bk-table"><thead><tr><th>' . self::t('Night') . '</th><th>' . self::t('Server') . '</th><th class="bk-num">' . self::t('Accounts') . '</th><th class="bk-num">'
            . self::t('Databases') . '</th><th>Sentora</th><th class="bk-act"></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        if ($chosen === '' || strpos($chosen, '/') === false) {
            return $out . '<p class="bk-empty">' . self::t('Open a snapshot to see what it holds and restore from it.') . '</p>';
        }
        list($srv, $date) = explode('/', $chosen, 2);
        $snap = self::RestoreSnapshot($srv, $date);
        if (!$snap || (int) $snap['format'] < 2) {
            return $out;
        }
        $hidden = '<input type="hidden" name="inServer" value="' . self::h($snap['server']) . '"><input type="hidden" name="inDate" value="' . self::h($snap['date']) . '">' . runtime_csfr::Token();
        $acc = '';
        $opts = '';
        foreach ($snap['accounts'] as $a) {
            $acc .= '<tr><td><b>' . self::h($a['username']) . '</b></td><td>' . self::h(implode(', ', array_slice($a['domains'], 0, 4)) . (count($a['domains']) > 4 ? ' +' . (count($a['domains']) - 4) : '')) . '</td><td>'
                . self::h(implode(', ', $a['databases']) ?: '–') . '</td><td class="bk-num">' . (int) $a['mailboxes'] . '</td><td class="bk-num">' . (int) $a['size_mb'] . ' MB</td></tr>';
            $opts .= '<option value="' . self::h($a['username']) . '">' . self::h($a['username']) . '</option>';
        }
        $out .= '<div class="bk-snap"><div class="bk-snap-head">' . self::Icon('archive') . '<div><h4>' . self::h(ui_language::translate('Snapshot') . ' ' . $snap['date'] . ' · ' . $snap['server']) . '</h4>'
            . '<p>' . self::h('Sentora-' . ($snap['sentora'] ?? '') . ' · ' . ui_language::translate('panel') . ' ' . ($snap['panel'] ?? '') . ' · ' . count($snap['databases']) . ' ' . ui_language::translate('databases') . ' · ' . ($snap['mail'] ? ui_language::translate('with the mailboxes') : ui_language::translate('without the mailboxes'))) . '</p></div></div>'
            . '<div class="bk-scroll bk-accounts"><table class="bk-table"><thead><tr><th>' . self::t('Account') . '</th><th>' . self::t('Domains') . '</th><th>' . self::t('Databases') . '</th><th class="bk-num">' . self::t('Mailboxes') . '</th><th class="bk-num">' . self::t('Size') . '</th></tr></thead><tbody>' . $acc . '</tbody></table></div>'
            . '<div class="bk-restore-grid">'
            // one account
            . '<form method="post" action="./?module=backup_admin&amp;action=RestoreAccount&amp;snap=' . rawurlencode($chosen) . '#restore" class="bk-restore" data-confirm="' . self::t('Restore this account? What it has now is saved first.') . '">'
            . '<h4>' . self::Icon('users') . self::t('Restore one account') . '</h4>'
            . '<label class="bk-field"><span>' . self::t('Account') . '</span><select class="form-control" name="inAccount">' . $opts . '</select></label>'
            . '<div class="bk-parts"><span class="bk-check"><input type="checkbox" name="inPart_files" value="1" checked> ' . self::t('Websites (copied as the account\'s own user)') . '</span>'
            . '<span class="bk-check bk-indent"><input type="checkbox" name="inExact" value="1"> ' . self::t('Also remove files the backup does not have') . '</span>'
            . '<span class="bk-check"><input type="checkbox" name="inPart_databases" value="1" checked> ' . self::t('Databases and MySQL users') . '</span>'
            . '<span class="bk-check"><input type="checkbox" name="inPart_mail" value="1"' . ($snap['mail'] ? ' checked' : ' disabled') . '> ' . self::t('Mailboxes') . '</span></div>'
            . '<p class="bk-sub">' . self::t('The account must exist on this server with the same username (make it in Manage Clients first). What it has now is saved before anything is changed.') . '</p>'
            . $hidden . '<button class="btn btn-primary" type="submit">' . self::t('Restore this account') . '</button></form>'
            // the whole panel
            . '<form method="post" action="./?module=backup_admin&amp;action=RestoreFull&amp;snap=' . rawurlencode($chosen) . '#restore" class="bk-restore bk-danger">'
            . '<h4>' . self::Icon('alert') . self::t('Restore the whole panel') . '</h4><p class="bk-sub">' . self::t('For a new or rebuilt server: every database (the panel\'s own included), the hosting accounts\' MySQL users, the panel\'s encryption key, the certificates, every account\'s websites and the mailboxes. The daemon then rebuilds the websites, PHP and DNS. This server keeps its own name, IP address, service passwords and off-server backup settings; what it has now is saved first.') . '</p>'
            . '<label class="bk-field"><span>' . self::t('Type RESTORE to confirm') . '</span><input class="form-control bk-confirm-word" type="text" name="inConfirm" autocomplete="off" placeholder="RESTORE"></label>'
            . $hidden . '<button class="btn btn-danger" type="submit">' . self::t('Restore the whole panel') . '</button></form></div></div>';
        return $out;
    }

    /**
     * The message box for the last action (template <@ Result @>).
     *
     * @return string|null The HTML of the message, or null when there is nothing to show.
     */
    static function getResult()
    {
        foreach (self::$offsiteErrors as $e) {
            return ui_sysmessage::shout(htmlspecialchars(ui_language::translate($e), ENT_QUOTES, 'UTF-8'), 'zannounceerror');
        }
        if (self::$offsiteSaved) {
            return ui_sysmessage::shout(ui_language::translate('The off-server backup settings have been saved. A change to the time or the switch takes effect within 5 minutes.'), 'zannounceok');
        }
        if (self::$offsiteQueued !== '') {
            return ui_sysmessage::shout(ui_language::translate(self::$offsiteQueued === 'check' ? 'Connection test queued: the result appears below within 5 minutes.' : 'Backup queued: it starts within 5 minutes; the result appears below when it is done.'), 'zannounceok');
        }
        // the message of a request accepted just before the redirect (kept for this page:
        // the template calls this twice)
        if (self::$flash === '' && isset($_SESSION['bk_restore_msg'])) {
            self::$flash = (string) $_SESSION['bk_restore_msg'];
            unset($_SESSION['bk_restore_msg']);
        }
        if (self::$flash !== '') {
            // translated when it was made (RestoreDone())
            return ui_sysmessage::shout(htmlspecialchars(self::$flash, ENT_QUOTES, 'UTF-8'), 'zannounceok');
        }
        foreach (self::$restoreMsg as $m) {
            return ui_sysmessage::shout(htmlspecialchars(ui_language::translate($m[1]), ENT_QUOTES, 'UTF-8'), $m[0] === 'ok' ? 'zannounceok' : 'zannounceerror');
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your settings have been saved successfully!"), "zannounceok");
        }
        return;
    }

}
