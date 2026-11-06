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
     * List the module's user-editable settings for the settings form.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, string>>|false One row per setting: 'cleanname', 'name', 'description', 'value', 'fieldhtml'; false when there are none.
     */
    static function getConfig()
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail();
        $moduleName = ui_module::GetModuleName();

        $sql = "SELECT * FROM x_settings WHERE so_module_vc=:module AND so_usereditable_en = 'true' ORDER BY so_cleanname_vc";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':module', $moduleName);
        $numrows->execute();

        //$numrows = $zdbh->query($sql);
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->bindParam(':module', $moduleName);
            $sql->execute();
            while ($rowmailsettings = $sql->fetch()) {
                if (ctrl_options::CheckForPredefinedOptions($rowmailsettings['so_defvalues_tx'])) {
                    $fieldhtml = ctrl_options::OuputSettingMenuField($rowmailsettings['so_name_vc'], $rowmailsettings['so_defvalues_tx'], $rowmailsettings['so_value_tx']);
                } else {
                    $fieldhtml = ctrl_options::OutputSettingTextArea($rowmailsettings['so_name_vc'], $rowmailsettings['so_value_tx']);
                }
                array_push($res, array('cleanname' => ui_language::translate($rowmailsettings['so_cleanname_vc']),
                    'name' => $rowmailsettings['so_name_vc'],
                    'description' => ui_language::translate($rowmailsettings['so_desc_tx']),
                    'value' => $rowmailsettings['so_value_tx'],
                    'fieldhtml' => $fieldhtml));
            }
            return $res;
        } else {
            return false;
        }
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
            'offsite_mail' => 'true', 'offsite_request' => '');
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
            'offsite_path' => $path, 'offsite_time' => $time, 'offsite_keep' => (string) (int) $keep, 'offsite_mail' => $mail) as $k => $v) {
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
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $t = function ($s) { return ui_language::translate($s); };
        $row = function ($label, $field, $help) use ($t) {
            return '<tr><th nowrap="nowrap" style="width: 220px;">' . $t($label) . '</th><td style="width: 360px;">' . $field . '</td><td class="text-muted">' . $t($help) . '</td></tr>';
        };
        $hasPw = ($o['offsite_password'] !== '');
        $html = '<form action="./?module=backup_admin&amp;action=SaveOffsite" method="post"><table class="table table-striped">'
            . $row('Enabled', '<label><input type="checkbox" name="inOffsiteEnabled" value="1"' . ($o['offsite_enabled'] === 'true' ? ' checked' : '') . '> ' . $t('Copy the backups every night') . '</label>', 'Off: nothing is copied (the settings are kept).')
            . $row('Server', '<input class="form-control" name="inOffsiteServer" value="' . $h($o['offsite_server']) . '" placeholder="backup.example.com" maxlength="253">', 'The backup machine, reached over SSH. Empty: the folder below is on this server (for example an NFS mount).')
            . $row('Port', '<input class="form-control" name="inOffsitePort" value="' . $h($o['offsite_port']) . '" inputmode="numeric" maxlength="5" style="max-width: 110px;">', 'SSH port, usually 22.')
            . $row('Username', '<input class="form-control" name="inOffsiteUser" value="' . $h($o['offsite_user']) . '" maxlength="32" autocomplete="off">', 'The user on the backup machine.')
            . $row('Password', '<input class="form-control" type="password" name="inOffsitePassword" value="" maxlength="256" autocomplete="new-password" placeholder="' . $h($hasPw ? $t('stored: leave empty to keep it') : $t('none: the SSH key is used')) . '">'
                . ($hasPw ? '<label style="font-weight: normal; margin-top: 4px;"><input type="checkbox" name="inOffsiteClearPassword" value="1"> ' . $t('Remove the password and use the SSH key') . '</label>' : ''),
                'Stored encrypted, never shown again. Without a password the SSH key /root/.ssh/sentora_offsite is used (more secure; sentora-offsite-backup --setup makes it).')
            . $row('Folder', '<input class="form-control" name="inOffsitePath" value="' . $h($o['offsite_path']) . '" maxlength="251">', 'Where the copies go; each server gets its own folder in it, with one folder per night.')
            . $row('Time', '<input class="form-control" type="time" name="inOffsiteTime" value="' . $h($o['offsite_time']) . '" style="max-width: 140px;">', 'Every day at this time (server time, ' . $h(date('T')) . ').')
            . $row('Nights kept', '<input class="form-control" name="inOffsiteKeep" value="' . $h($o['offsite_keep']) . '" inputmode="numeric" maxlength="3" style="max-width: 110px;">', 'Older nights are removed from the backup machine.')
            . $row('Mailboxes', '<label><input type="checkbox" name="inOffsiteMail" value="1"' . ($o['offsite_mail'] === 'true' ? ' checked' : '') . '> ' . $t('Include the mailboxes') . '</label>', 'Copied incrementally: only new mail is sent each night.')
            . '<tr><th colspan="3"><button class="btn btn-primary" type="submit">' . $t('Save') . '</button></th></tr></table>' . runtime_csfr::Token() . '</form>'
            . '<form method="post" action="./?module=backup_admin&amp;action=OffsiteCheck" style="display:inline">' . runtime_csfr::Token() . '<button class="btn btn-default" type="submit">' . $t('Test connection') . '</button></form> '
            . '<form method="post" action="./?module=backup_admin&amp;action=OffsiteNow" style="display:inline">' . runtime_csfr::Token() . '<button class="btn btn-default" type="submit">' . $t('Back up now') . '</button></form>';
        return $html;
    }

    /**
     * Build the off-server backup status table (template <@ OffsiteStatus @>).
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
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $when = function ($ts) { return ((int) $ts > 0) ? date('Y-m-d H:i', (int) $ts) : '-'; };
        $out = '<table class="table table-condensed" style="max-width: 900px; margin-top: 14px;">';
        if ($o['offsite_request'] !== '') {
            $out .= '<tr><th style="width: 220px;">' . ui_language::translate('Queued') . '</th><td><span class="label label-warning">' . $h(strtok($o['offsite_request'], ' ') === 'check' ? 'Test connection' : 'Back up now') . '</span> ' . ui_language::translate('the daemon runs it within 5 minutes') . '</td></tr>';
        }
        if (isset($st['check_time'])) {
            $out .= '<tr><th style="width: 220px;">' . ui_language::translate('Last connection test') . '</th><td>' . $h($when($st['check_time'])) . ' '
                . (!empty($st['check_ok']) ? '<span class="label label-success">OK</span>' : '<span class="label label-danger">FAILED</span>') . ' ' . $h(isset($st['check_message']) ? $st['check_message'] : '') . '</td></tr>';
        }
        $out .= '<tr><th style="width: 220px;">' . ui_language::translate('Last good copy') . '</th><td>' . $h($when(isset($st['last_ok']) ? $st['last_ok'] : 0)) . '</td></tr>';
        if (isset($st['result']) && $st['result'] !== 'ok') {
            $out .= '<tr><th>' . ui_language::translate('Last run') . '</th><td>' . $h($when($st['last_run'])) . ' <span class="label label-danger">FAILED</span> ' . $h($st['message']) . '</td></tr>';
        }
        return $out . '</table>';
    }

    // --- Restore from the full panel backup (sentora-panel-restore, run by the daemon) ----------

    const RESTORE_REQ = '/var/lib/sentora/tmp/panel-restore-request.json';
    const RESTORE_STATE = '/var/lib/sentora/panel-restore.json';
    const RESTORE_CATALOG = '/var/lib/sentora/restore-catalog.json';
    /** @var array<int, array{0: string, 1: string}> Messages for getResult(): array(kind 'ok'|'error', text). */
    static $restoreMsg = array();

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
     * @param string $message The message to show after the redirect.
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
            self::RestoreDone('Reading the backups: the list appears here within 5 minutes.');
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
            self::RestoreDone('Restore of ' . $user . ' from ' . $snap['date'] . ' queued: it starts within 5 minutes. Its progress shows here.', $snap['server'] . '/' . $snap['date']);
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
            self::RestoreDone('Full restore from ' . $snap['server'] . ' ' . $snap['date'] . ' queued: it starts within 5 minutes. Its progress shows here.', $snap['server'] . '/' . $snap['date']);
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
        $h = function ($x) { return htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8'); };
        $t = function ($x) { return ui_language::translate($x); };
        $out = '';
        // what is running or ran last
        $st = self::RestoreJson(self::RESTORE_STATE);
        $req = self::RestoreJson(self::RESTORE_REQ);
        if ($req) {
            $out .= '<p class="bk-job"><span class="bk-spin"></span> ' . $h($t($req['action'] === 'list' ? 'Reading the backups' : 'Restore')) . ' ' . $t('queued: it starts within 5 minutes') . '.</p>';
        } elseif (!empty($st['running']) || !empty($st['listing'])) {
            $out .= '<p class="bk-job"><span class="bk-spin"></span> <strong>' . $h(!empty($st['listing']) ? $t('Reading the backups') : $t('Restoring') . ' ' . ($st['snapshot'] ?? '')) . '</strong>&hellip; '
                . '<span class="text-muted">' . $h($st['step'] ?? '') . '</span><br><span class="text-muted">' . $t('Reload the page to follow it. The log: /var/sentora/logs/panel-restore.log') . '</span></p>';
        } elseif (!empty($st['result'])) {
            $icon = $st['result'] === 'ok' ? ui_status::Ok('Done') : ($st['result'] === 'partial' ? ui_status::Warn('With problems') : ui_status::Bad('Failed'));
            $out .= '<p>' . $icon . ' ' . $h($t('Last restore') . ', ' . date('Y-m-d H:i', (int) ($st['finished'] ?? 0)) . ': ' . ($st['message'] ?? '')) . '</p>';
        }
        $cat = self::RestoreJson(self::RESTORE_CATALOG);
        $out .= '<form method="post" action="./?module=backup_admin&amp;action=RestoreList#restore" style="margin-bottom: 12px;">' . runtime_csfr::Token()
            . '<button class="btn btn-default" type="submit">' . $t('Read the backups') . '</button> <span class="text-muted">'
            . $h(!empty($cat['time']) ? $t('Last read') . ' ' . date('Y-m-d H:i', (int) $cat['time']) . (isset($cat['target']) ? ' ' . $t('from') . ' ' . $cat['target'] : '') : $t('Lists the snapshots on the backup machine (Off-server backup above), from any server.')) . '</span></form>';
        if (!empty($cat['error'])) {
            return $out . '<p class="text-danger">' . $h($t('The backups could not be read') . ': ' . $cat['error']) . '</p>';
        }
        $snaps = (array) ($cat['snapshots'] ?? array());
        if (!$snaps) {
            return $out . (empty($cat['time']) ? '' : '<p class="text-muted">' . $t('No snapshots yet.') . '</p>');
        }
        // the snapshots, newest first; one chosen
        $chosen = (string) $controller->GetControllerRequest('URL', 'snap');
        $rows = '';
        foreach ($snaps as $s) {
            $key = $s['server'] . '/' . $s['date'];
            $sel = ($key === $chosen);
            $rows .= '<tr' . ($sel ? ' class="info"' : '') . '><td>' . $h($s['date']) . '</td><td>' . $h($s['server']) . ($s['server'] === ($cat['this_server'] ?? '') ? ' <span class="label label-default">' . $t('this server') . '</span>' : '') . '</td>'
                . ($s['format'] >= 2
                    ? '<td>' . count($s['accounts']) . '</td><td>' . count($s['databases']) . '</td><td>' . $h($s['sentora'] ?? '') . '</td><td><a class="btn btn-default btn-xs" href="./?module=backup_admin&amp;snap=' . rawurlencode($key) . '#restore">' . $t('Open') . '</a></td>'
                    : '<td colspan="4" class="text-muted">' . $t('made by an older version: restore it by hand') . '</td>') . '</tr>';
        }
        $out .= '<div style="max-height: 300px; overflow-y: auto; border: 1px solid var(--sn-line, #ddd); border-radius: 4px;"><table class="table table-condensed" style="margin: 0;"><thead><tr><th>' . $t('Night') . '</th><th>' . $t('Server') . '</th><th>' . $t('Accounts') . '</th><th>'
            . $t('Databases') . '</th><th>Sentora</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        if ($chosen === '' || strpos($chosen, '/') === false) {
            return $out . '<p class="text-muted" style="margin-top: 8px;">' . $t('Open a snapshot to see what it holds and restore from it.') . '</p>';
        }
        list($srv, $date) = explode('/', $chosen, 2);
        $snap = self::RestoreSnapshot($srv, $date);
        if (!$snap || (int) $snap['format'] < 2) {
            return $out;
        }
        $hidden = '<input type="hidden" name="inServer" value="' . $h($snap['server']) . '"><input type="hidden" name="inDate" value="' . $h($snap['date']) . '">' . runtime_csfr::Token();
        $acc = '';
        $opts = '';
        foreach ($snap['accounts'] as $a) {
            $acc .= '<tr><td><strong>' . $h($a['username']) . '</strong></td><td>' . $h(implode(', ', array_slice($a['domains'], 0, 4)) . (count($a['domains']) > 4 ? ' +' . (count($a['domains']) - 4) : '')) . '</td><td>'
                . $h(implode(', ', $a['databases']) ?: '-') . '</td><td>' . (int) $a['mailboxes'] . '</td><td>' . (int) $a['size_mb'] . ' MB</td></tr>';
            $opts .= '<option value="' . $h($a['username']) . '">' . $h($a['username']) . '</option>';
        }
        $out .= '<h3 style="margin-top: 18px;">' . $h($t('Snapshot') . ' ' . $snap['date'] . ' ' . $t('of') . ' ' . $snap['server']) . '</h3>'
            . '<p class="text-muted">' . $h('Sentora-' . ($snap['sentora'] ?? '') . ', ' . $t('panel') . ' ' . ($snap['panel'] ?? '') . ', ' . count($snap['databases']) . ' ' . $t('databases') . ($snap['mail'] ? ', ' . $t('with the mailboxes') : ', ' . $t('without the mailboxes'))) . '</p>'
            . '<div style="max-height: 260px; overflow-y: auto; border: 1px solid var(--sn-line, #ddd); border-radius: 4px;"><table class="table table-condensed table-striped" style="margin: 0;"><thead><tr><th>' . $t('Account') . '</th><th>' . $t('Domains') . '</th><th>' . $t('Databases') . '</th><th>' . $t('Mailboxes') . '</th><th>' . $t('Size') . '</th></tr></thead><tbody>' . $acc . '</tbody></table></div>'
            // one account
            . '<form method="post" action="./?module=backup_admin&amp;action=RestoreAccount&amp;snap=' . rawurlencode($chosen) . '#restore" class="bk-restore" onsubmit="return confirm(\'' . $h($t('Restore this account? What it has now is saved first.')) . '\');">'
            . '<h4>' . $t('Restore one account') . '</h4><p>' . $t('Account') . ' <select class="form-control" name="inAccount" style="display: inline-block; width: auto;">' . $opts . '</select></p>'
            . '<p><label><input type="checkbox" name="inPart_files" value="1" checked> ' . $t('Websites (copied as the account\'s own user)') . '</label><br>'
            . '<label style="margin-left: 22px;"><input type="checkbox" name="inExact" value="1"> ' . $t('Also remove files the backup does not have') . '</label><br>'
            . '<label><input type="checkbox" name="inPart_databases" value="1" checked> ' . $t('Databases and MySQL users') . '</label><br>'
            . '<label><input type="checkbox" name="inPart_mail" value="1"' . ($snap['mail'] ? ' checked' : ' disabled') . '> ' . $t('Mailboxes') . '</label></p>'
            . '<p class="text-muted">' . $t('The account must exist on this server with the same username (make it in Manage Clients first). What it has now is saved before anything is changed.') . '</p>'
            . $hidden . '<button class="btn btn-primary" type="submit">' . $t('Restore this account') . '</button></form>'
            // the whole panel
            . '<form method="post" action="./?module=backup_admin&amp;action=RestoreFull&amp;snap=' . rawurlencode($chosen) . '#restore" class="bk-restore bk-danger">'
            . '<h4>' . $t('Restore the whole panel') . '</h4><p>' . $t('For a new or rebuilt server: every database (the panel\'s own included), the hosting accounts\' MySQL users, the panel\'s encryption key, the certificates, every account\'s websites and the mailboxes. The daemon then rebuilds the websites, PHP and DNS. This server keeps its own name, IP address, service passwords and off-server backup settings; what it has now is saved first.') . '</p>'
            . '<p>' . $t('Type RESTORE to confirm') . ' <input class="form-control" name="inConfirm" autocomplete="off" style="display: inline-block; width: 160px;"></p>'
            . $hidden . '<button class="btn btn-danger" type="submit">' . $t('Restore the whole panel') . '</button></form>';
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
        static $flash = null;
        if ($flash === null) {
            $flash = isset($_SESSION['bk_restore_msg']) ? (string) $_SESSION['bk_restore_msg'] : '';
            unset($_SESSION['bk_restore_msg']);
        }
        if ($flash !== '') {
            return ui_sysmessage::shout(htmlspecialchars(ui_language::translate($flash), ENT_QUOTES, 'UTF-8'), 'zannounceok');
        }
        foreach (self::$restoreMsg as $m) {
            return ui_sysmessage::shout(htmlspecialchars(ui_language::translate($m[1]), ENT_QUOTES, 'UTF-8'), $m[0] === 'ok' ? 'zannounceok' : 'zannounceerror');
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your settings have been saved successfully!"));
        }
        return;
    }

}
