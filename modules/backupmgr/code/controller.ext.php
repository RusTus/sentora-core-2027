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
class module_controller extends ctrl_module
{

    /** @var bool|null True after a backup was deleted (shown by getResult()). */
    static $deleteok;
    /** @var bool|null True after doBackup() ran (shown by getResult()). */
    static $backupok;
    /** @var bool|null Set when a backup could not be saved (never set in this module; read by getResult()). */
    static $filenotexist;

    /**
     * List the account's backup archives.
     *
     * @param int $userid The account ID.
     * @return array<int, array<string, string>> One row per backup: 'backupfile' (name without .zip), 'created', 'filesize'.
     */
    static function ListBackUps($userid)
    {
        // real backup files only (sys_backup::ListFiles: no links, backup names only)
        $currentuser = ctrl_users::GetUserDetail($userid);
        $res = array();
        foreach (sys_backup::ListFiles($currentuser['username']) as $file => $info) {
            $res[] = array('backupfile' => substr($file, 0, -4),
                'created' => date("F d Y H:i:s", $info['mtime']),
                'filesize' => fs_director::ShowHumanFileSize($info['size']));
        }
        return $res;
    }

    /**
     * Sort a list of rows in place by one column.
     *
     * @param array<array-key,array<string,mixed>> &$arr The rows to sort (changed in place).
     * @param string $col The column key to sort by.
     * @param int $dir Optional. SORT_ASC or SORT_DESC. Default SORT_ASC.
     * @return void
     */
    static function array_sort_by_column(&$arr, $col, $dir = SORT_ASC)
    {
        $sort_col = array();
        foreach ($arr as $key => $row) {
            $sort_col[$key] = $row[$col];
        }
        array_multisort($sort_col, $dir, $arr);
    }

    /**
     * Check whether the account's public_html folder has any files in it.
     *
     * @param int $userid The account ID.
     * @return bool True when public_html is not empty.
     */
    static function CheckHasData($userid)
    {
        $currentuser = ctrl_users::GetUserDetail($userid);
        $datafolder = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/public_html/";
        $dirFiles = array();
        if (is_dir($datafolder) && ($handle = opendir($datafolder))) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != "..") {
                    $dirFiles[] = $file;
                }
            }
            closedir($handle);
        }
        
        if (!fs_director::CheckForEmptyValue($dirFiles)) {
            return true;
        }
        return false;
    }

    /**
     * Run the before/after backup hooks.
     *
     * The backup itself is made in the background by doBackupNow(); this only fires
     * the OnBeforeCreateBackup and OnAfterCreateBackup hooks.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int $userid The account ID.
     * @param mixed $download Optional. The form's inDownLoad value (unused). Default 0.
     * @return void
     */
    static function ExecuteBackup($userid, $download = 0)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($userid);
        runtime_hook::Execute('OnBeforeCreateBackup');

        runtime_hook::Execute('OnAfterCreateBackup');
    }

    /**
     * Print a file to the output in 1 MB chunks.
     *
     * @param string $filename The full path of the file to send.
     * @return bool False when the file cannot be opened, else the result of fclose().
     */
    static function readfile_chunked($filename)
    {
        $chunksize = 1 * (1024 * 1024);
        $buffer = '';
        $handle = fopen($filename, 'rb');
        if ($handle === false) {
            return false;
        }
        while (!feof($handle)) {
            $buffer = fread($handle, $chunksize);
            print $buffer;
        }
        return fclose($handle);
    }

    /**
     * Delete one of the account's backups and run the delete hooks.
     *
     * @param string $username The account's username.
     * @param string $file The backup name without the .zip extension.
     * @return void
     */
    static function ExecuteDeleteBackup($username, $file)
    {
        runtime_hook::Execute('OnBeforeDeleteBackup');
        // one of the account's own backups by name, in its real backups folder only
        sys_backup::DeleteFile($username, $file . '.zip');
        runtime_hook::Execute('OnAfterDeleteBackup');
    }

    /**
     * Create the account's backups folder if it does not exist.
     *
     * @param string $username The account's username.
     * @return void
     */
    static function ExecuteCreateBackupDirectory($username)
    {
        $backupdir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/backups/";
        if (!is_dir($backupdir)) {
            fs_director::CreateDirectory($backupdir);
        }
    }

    /**
     * Get the number of days after which backups are purged.
     *
     * @return string|false The purge_date option when purging is on, else false.
     */
    static function CheckPurgeDate()
    {
        if (strtolower(ctrl_options::GetSystemOption('purge_bu')) == "true") {
            return ctrl_options::GetSystemOption('purge_date');
        } else {
            return false;
        }
    }

    /**
     * Handle the backup form POST for the logged-in account.
     *
     * Checks the CSRF token, runs ExecuteBackup() and sets $backupok.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doBackup()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        // always the logged-in account (the form's own id is not trusted)
        $currentuser = ctrl_users::GetUserDetail();
        $download = $controller->GetControllerRequest('FORM', 'inDownLoad');
        self::ExecuteBackup($currentuser['userid'], $download);
        self::$backupok = true;
    }

    /**
     * Handle the delete form POST: delete each backup whose inDelete_ button was sent.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doDeleteBackup()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $userid = $currentuser['userid'];
        $username = $currentuser['username'];
        $files = self::ListBackUps($userid);
        //print_r($_POST);
        foreach ($files as $file) {
            if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDelete_' . $file['backupfile'] . '')) ||
                    !fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDelete_' . $file['backupfile'] . '_x')) ||
                    !fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDelete_' . $file['backupfile'] . '_y'))) {
                self::ExecuteDeleteBackup($username, $file['backupfile']);
                self::$deleteok = true;
            }
        }
    }

    /**
     * Template flag: the logged-in account has files in public_html.
     *
     * @global runtime_controller $controller The request controller (unused).
     * @return bool
     */
    static function GetHasData()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::CheckHasData($currentuser['userid']);
    }

    /**
     * Template loop: the logged-in account's backups.
     *
     * @global runtime_controller $controller The request controller (unused).
     * @return array<int, array<string, string>> One row per backup: 'backupfile', 'created', 'filesize'.
     */
    static function GetBackUpList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListBackUps($currentuser['userid']);
    }

    /**
     * Get the backups folder of the logged-in account, relative to the hosted dir.
     *
     * @global runtime_controller $controller The request controller (unused).
     * @return string For example "username/backups/".
     */
    static function GetFileLocation()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $filelocation = $currentuser['username'] . "/backups/";
        return $filelocation;
    }

    /**
     * Get the logged-in account's ID (used in the download link).
     *
     * @global runtime_controller $controller The request controller (unused).
     * @return int|string The account ID as read from the database.
     */
    static function getUserID()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $userid = $currentuser['userid'];
        return $userid;
    }

    /**
     * Template flag: backups may be kept on the server's disk (disk_bu option).
     *
     * @global runtime_controller $controller The request controller (unused).
     * @return bool
     */
    static function GetDiskAllowed()
    {
        global $controller;
        if (strtolower(ctrl_options::GetSystemOption('disk_bu')) == "true")
            return true;
        return false;
    }

    /**
     * Template value: days after which backups are purged.
     *
     * @return string|false The purge_date option, or false when purging is off.
     */
    static function GetPurgeDate()
    {
        return self::CheckPurgeDate();
    }

    /**
     * Create the logged-in account's backups folder if it is missing.
     *
     * ExecuteCreateBackupDirectory() returns nothing, so this always returns false.
     *
     * @return bool Always false in practice (the template echoes nothing).
     */
    static function getCreateBackupDirectory()
    {
        $currentuser = ctrl_users::GetUserDetail();
        if (self::ExecuteCreateBackupDirectory($currentuser['username']))
            return true;
        return false;
    }

    /**
     * Read a value from x_backup_settings.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name The setting name (bus_name_vc).
     * @return string|false The setting value, or false when not found.
     */
    static function GetBUOption($name)
    {
        global $zdbh;
        // $result = $zdbh->query("SELECT bus_value_tx FROM x_backup_settings WHERE bus_name_vc = '$name'")->Fetch();
        $sql = $zdbh->prepare("SELECT bus_value_tx FROM x_backup_settings WHERE bus_name_vc = :name");
        $sql->bindParam(':name', $name);
        $sql->execute();
        $result = $sql->fetch();
        if ($result) {
            return $result['bus_value_tx'];
        } else {
            return false;
        }
    }

    // --- Back up now: in the background, with its progress on this page ------------------------

    /** @var string Result of sys_backup::StartBackground(): '', 'started', 'running', 'recent' or 'failed'. */
    static $startResult = '';

    /**
     * Handle the "Back up now" POST: start a background backup for the logged-in account.
     *
     * @return void
     */
    static function doBackupNow()
    {
        runtime_csfr::Protect();
        $me = ctrl_users::GetUserDetail();
        self::$startResult = sys_backup::StartBackground($me['userid']);
    }

    /**
     * Template HTML: the progress box while a backup runs, else the "Back up now" form.
     *
     * @return string HTML.
     */
    static function getBackupNow()
    {
        $me = ctrl_users::GetUserDetail();
        $p = sys_backup::Progress($me['userid']);
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $t = function ($s) { return ui_language::translate($s); };
        if ($p['state'] === 'running') {
            return '<div class="bk-progress" id="bk-progress" data-status="modules/backupmgr/code/status.php" data-started="' . (int) $p['started'] . '">'
                . '<span class="bk-spinner" aria-hidden="true"></span>'
                . '<div><strong>' . $t('Making your backup') . '</strong> &mdash; ' . $t('started at') . ' ' . $h(date('H:i', $p['started']))
                . ', <span id="bk-elapsed">' . (int) $p['elapsed'] . 's</span> ' . $t('so far') . '.<br>'
                . '<span class="text-muted">' . $t('Your websites and databases are being packed. Large sites can take several minutes. You can leave this page: the backup appears in the list below when it is ready.') . '</span></div></div>';
        }
        return '<form action="./?module=backupmgr&amp;action=BackupNow" method="post"><table class="table table-striped"><tr>'
            . '<th nowrap="nowrap" style="width: 160px;"><button class="btn btn-primary" type="submit">' . $t('Back up now') . '</button></th>'
            . '<td>' . $t('Makes a backup of your websites and databases on this page. When it is ready it appears in the list below, ready to download.') . '</td>'
            . '</tr></table>' . runtime_csfr::Token() . '</form>';
    }

    /**
     * Template HTML: a message about starting or finishing the background backup.
     *
     * @global runtime_controller $controller The request controller.
     * @return string HTML message, or '' when there is nothing to say.
     */
    static function getProgressMessage()
    {
        global $controller;
        $me = ctrl_users::GetUserDetail();
        $p = sys_backup::Progress($me['userid']);
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        if (self::$startResult === 'recent') {
            return ui_sysmessage::shout(ui_language::translate('A backup was made less than 2 minutes ago: it is in the list below.'), 'zannounceinfo');
        }
        if (self::$startResult === 'failed') {
            return ui_sysmessage::shout(ui_language::translate('The backup could not be started. Ask your administrator.'), 'zannounceerror');
        }
        // the page reloads with done=1 when the backup is ready
        if ((string) $controller->GetControllerRequest('URL', 'done') === '1') {
            if ($p['state'] === 'done') {
                return ui_sysmessage::shout(ui_language::translate('Your backup is ready') . ': <strong>' . $h($p['file']) . '</strong> (' . $h($p['size']) . '). '
                    . ui_language::translate('Download it from the list below.'), 'zannounceok');
            }
            if ($p['state'] === 'failed') {
                return ui_sysmessage::shout($h(ui_language::translate($p['message'] ?? 'The backup could not be made.')), 'zannounceerror');
            }
        }
        if ($p['state'] === 'failed' && isset($p['finished']) && time() - (int) $p['finished'] < 600) {
            return ui_sysmessage::shout($h(ui_language::translate($p['message'] ?? 'The backup could not be made.')), 'zannounceerror');
        }
        return '';
    }

    // --- copy to the client's own FTP server (sys_clientftp; uploads run by the daemon) ----------

    /** @var string[] Errors from the FTP settings form (untranslated). */
    static $ftpErrors = array();
    /** @var bool True after the FTP settings were saved. */
    static $ftpSaved = false;
    /** @var string The FTP job queued for the daemon: '', 'check' or 'now'. */
    static $ftpQueued = '';

    /**
     * Template flag: copying backups to the client's own FTP server is allowed.
     *
     * @return bool
     */
    static function getFtpAllowed()
    {
        return sys_clientftp::Allowed();
    }

    /**
     * Handle the FTP settings form POST: validate and save the settings.
     *
     * Sets $ftpErrors on a problem or $ftpSaved on success. The password is stored
     * encrypted and only replaced when a new one was entered.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doFtpSave()
    {
        global $controller;
        runtime_csfr::Protect();
        if (!sys_clientftp::Allowed()) {
            return;
        }
        $me = ctrl_users::GetUserDetail();
        $f = $controller->GetAllControllerRequests('FORM');
        $g = function ($k) use ($f) { return isset($f[$k]) ? trim((string) $f[$k]) : ''; };
        $cfg = sys_clientftp::Get($me['userid']);
        $v = array('bf_enabled_in' => $g('inFtpEnabled') === '1' ? 1 : 0, 'bf_host_vc' => strtolower($g('inFtpHost')),
            'bf_port_in' => $g('inFtpPort') === '' ? 21 : $g('inFtpPort'), 'bf_user_vc' => $g('inFtpUser'), 'bf_pass_tx' => (string) $cfg['bf_pass_tx'],
            'bf_path_vc' => $g('inFtpPath') === '' ? '/' : '/' . trim($g('inFtpPath'), '/'), 'bf_tls_vc' => $g('inFtpTls'),
            'bf_insecure_in' => $g('inFtpInsecure') === '1' ? 1 : 0, 'bf_hour_in' => $g('inFtpHour'), 'bf_keep_in' => $g('inFtpKeep'));
        if ($v['bf_path_vc'] !== '/') {
            $v['bf_path_vc'] = rtrim($v['bf_path_vc'], '/');
        }
        $e = array();
        if ($v['bf_host_vc'] !== '' && !sys_clientftp::ValidHost($v['bf_host_vc'])) { $e[] = 'The server is a host name or an address.'; }
        if (!ctype_digit((string) $v['bf_port_in']) || (int) $v['bf_port_in'] < 1 || (int) $v['bf_port_in'] > 65535) { $e[] = 'The port is a number from 1 to 65535.'; }
        if ($v['bf_host_vc'] !== '' && !sys_clientftp::ValidUser($v['bf_user_vc'])) { $e[] = 'The username may not have spaces, colons, slashes or @.'; }
        if (!sys_clientftp::ValidPath($v['bf_path_vc'])) { $e[] = 'The folder is a path like /backups (letters, digits, spaces, dots, dashes, underscores).'; }
        if (!in_array($v['bf_tls_vc'], array('explicit', 'implicit', 'none'), true)) { $e[] = 'Choose how to connect.'; }
        if (!ctype_digit((string) $v['bf_hour_in']) || (int) $v['bf_hour_in'] > 23) { $e[] = 'Choose an hour.'; }
        if (!ctype_digit((string) $v['bf_keep_in']) || (int) $v['bf_keep_in'] < 1 || (int) $v['bf_keep_in'] > 60) { $e[] = 'Copies kept is a number from 1 to 60.'; }
        if ($v['bf_enabled_in'] === 1 && $v['bf_host_vc'] === '') { $e[] = 'Enter your FTP server before switching it on.'; }
        $pw = isset($f['inFtpPassword']) ? (string) $f['inFtpPassword'] : '';
        if ($pw !== '' && (strlen($pw) > 256 || preg_match('/[\x00-\x1f\x7f]/', $pw))) { $e[] = 'The password may not contain control characters (256 characters at most).'; }
        if ($v['bf_host_vc'] !== '' && $e === array()) {
            list($ip, $why) = sys_clientftp::ResolveTarget($v['bf_host_vc'], $v['bf_port_in']);
            if ($ip === false) { $e[] = 'That server can not be used: ' . $why . '.'; }
        }
        if ($e) {
            self::$ftpErrors = $e;
            return;
        }
        if ($pw !== '') {
            $enc = ctrl_secret::Encrypt($pw);
            if ($enc === '') {
                self::$ftpErrors = array('The password could not be stored encrypted on this server.');
                return;
            }
            $v['bf_pass_tx'] = $enc;
        }
        sys_clientftp::Save($me['userid'], $v);
        self::$ftpSaved = true;
    }

    /**
     * Queue an FTP job for the daemon for the logged-in account.
     *
     * Checks the CSRF token; sets $ftpQueued, or $ftpErrors when no FTP server is saved.
     *
     * @param string $what The job: 'check' (test connection) or 'now' (upload now).
     * @return void
     */
    static function FtpQueue($what)
    {
        runtime_csfr::Protect();
        $me = ctrl_users::GetUserDetail();
        $cfg = sys_clientftp::Get($me['userid']);
        if (!sys_clientftp::Allowed() || $cfg['bf_host_vc'] === '') {
            self::$ftpErrors = array('Save your FTP server first.');
            return;
        }
        sys_clientftp::SetRequest($me['userid'], $what);
        self::$ftpQueued = $what;
    }

    /**
     * Handle the "Test connection" POST.
     *
     * @return void
     */
    static function doFtpCheck()
    {
        self::FtpQueue('check');
    }

    /**
     * Handle the "Upload now" POST.
     *
     * @return void
     */
    static function doFtpNow()
    {
        self::FtpQueue('now');
    }

    /**
     * Template HTML: the FTP settings form and the test/upload buttons.
     *
     * @return string HTML.
     */
    static function getFtpForm()
    {
        $me = ctrl_users::GetUserDetail();
        $c = sys_clientftp::Get($me['userid']);
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $t = function ($s) { return ui_language::translate($s); };
        $row = function ($label, $field, $help) use ($t) {
            return '<tr><th nowrap="nowrap" style="width: 200px;">' . $t($label) . '</th><td style="width: 340px;">' . $field . '</td><td class="text-muted">' . $t($help) . '</td></tr>';
        };
        $opt = function ($value, $label, $cur) use ($h, $t) { return '<option value="' . $h($value) . '"' . ((string) $cur === (string) $value ? ' selected' : '') . '>' . $h($t($label)) . '</option>'; };
        $hours = '';
        for ($i = 0; $i < 24; $i++) {
            $hours .= $opt($i, sprintf('%02d:00', $i), $c['bf_hour_in']);
        }
        $hasPw = ((string) $c['bf_pass_tx'] !== '');
        return '<form action="./?module=backupmgr&amp;action=FtpSave" method="post"><table class="table table-striped">'
            . $row('Enabled', '<label><input type="checkbox" name="inFtpEnabled" value="1"' . ((int) $c['bf_enabled_in'] === 1 ? ' checked' : '') . '> ' . $t('Copy a backup to my FTP server every day') . '</label>', 'Your websites and databases (not your mail).')
            . $row('Server', '<input class="form-control" name="inFtpHost" value="' . $h($c['bf_host_vc']) . '" placeholder="ftp.example.com" maxlength="253">', 'Your FTP server.')
            . $row('Port', '<input class="form-control" name="inFtpPort" value="' . $h($c['bf_port_in']) . '" inputmode="numeric" maxlength="5" style="max-width: 110px;">', '21 for FTP and FTPS with TLS, 990 for implicit FTPS.')
            . $row('Username', '<input class="form-control" name="inFtpUser" value="' . $h($c['bf_user_vc']) . '" maxlength="128" autocomplete="off">', '')
            . $row('Password', '<input class="form-control" type="password" name="inFtpPassword" value="" maxlength="256" autocomplete="new-password" placeholder="' . $h($hasPw ? $t('stored: leave empty to keep it') : '') . '">', 'Stored encrypted, never shown again.')
            . $row('Folder', '<input class="form-control" name="inFtpPath" value="' . $h($c['bf_path_vc']) . '" maxlength="200">', 'Made if it does not exist.')
            . $row('Connection', '<select class="form-control" name="inFtpTls">' . $opt('explicit', 'FTPS: TLS on port 21 (recommended)', $c['bf_tls_vc']) . $opt('implicit', 'FTPS: implicit TLS (port 990)', $c['bf_tls_vc']) . $opt('none', 'Plain FTP: NOT encrypted', $c['bf_tls_vc']) . '</select>'
                . '<label style="font-weight: normal; margin-top: 4px;"><input type="checkbox" name="inFtpInsecure" value="1"' . ((int) $c['bf_insecure_in'] === 1 ? ' checked' : '') . '> ' . $t('Accept a self-signed certificate') . '</label>',
                'Plain FTP sends your password and your backups unencrypted. Accept a self-signed certificate only for a server you run yourself.')
            . $row('Time', '<select class="form-control" name="inFtpHour" style="max-width: 120px;">' . $hours . '</select>', 'Every day at this hour (server time, ' . $h(date('T')) . ').')
            . $row('Copies kept', '<input class="form-control" name="inFtpKeep" value="' . $h($c['bf_keep_in']) . '" inputmode="numeric" maxlength="2" style="max-width: 110px;">', 'Older copies of your backups are removed from your FTP server.')
            . '<tr><th colspan="3"><button class="btn btn-primary" type="submit">' . $t('Save') . '</button></th></tr></table>' . runtime_csfr::Token() . '</form>'
            . '<form method="post" action="./?module=backupmgr&amp;action=FtpCheck" style="display:inline">' . runtime_csfr::Token() . '<button class="btn btn-default" type="submit">' . $t('Test connection') . '</button></form> '
            . '<form method="post" action="./?module=backupmgr&amp;action=FtpNow" style="display:inline">' . runtime_csfr::Token() . '<button class="btn btn-default" type="submit">' . $t('Upload now') . '</button></form>';
    }

    /**
     * Template HTML: queued job and the last test, upload and good copy times.
     *
     * @return string HTML table.
     */
    static function getFtpStatus()
    {
        $me = ctrl_users::GetUserDetail();
        $c = sys_clientftp::Get($me['userid']);
        $s = sys_clientftp::Status($me['userid']);
        $h = function ($x) { return htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8'); };
        $when = function ($ts) { return ((int) $ts > 0) ? date('Y-m-d H:i', (int) $ts) : '-'; };
        $lab = function ($ok) { return $ok ? '<span class="label label-success">OK</span>' : '<span class="label label-danger">FAILED</span>'; };
        $out = '<table class="table table-condensed" style="max-width: 900px; margin-top: 14px;">';
        if ($c['bf_request_vc'] !== '') {
            $out .= '<tr><th style="width: 200px;">' . ui_language::translate('Queued') . '</th><td><span class="label label-warning">' . $h($c['bf_request_vc'] === 'check' ? 'Test connection' : 'Upload now') . '</span> ' . ui_language::translate('starts within 5 minutes') . '</td></tr>';
        }
        if (isset($s['check_time'])) {
            $out .= '<tr><th style="width: 200px;">' . ui_language::translate('Last connection test') . '</th><td>' . $h($when($s['check_time'])) . ' ' . $lab(!empty($s['check_ok'])) . ' ' . $h($s['check_message']) . '</td></tr>';
        }
        if (isset($s['run_time'])) {
            $out .= '<tr><th style="width: 200px;">' . ui_language::translate('Last upload') . '</th><td>' . $h($when($s['run_time'])) . ' ' . $lab(!empty($s['run_ok'])) . ' ' . $h($s['run_message']) . '</td></tr>';
        }
        $out .= '<tr><th style="width: 200px;">' . ui_language::translate('Last good copy') . '</th><td>' . $h($when(isset($s['last_ok']) ? $s['last_ok'] : 0)) . '</td></tr>';
        return $out . '</table>';
    }

    /**
     * Template HTML: the result message of the last form action.
     *
     * @return string|null HTML message, or null when there is nothing to show.
     */
    static function getResult()
    {
        foreach (self::$ftpErrors as $e) {
            return ui_sysmessage::shout(htmlspecialchars(ui_language::translate($e), ENT_QUOTES, 'UTF-8'), 'zannounceerror');
        }
        if (self::$ftpSaved) {
            return ui_sysmessage::shout(ui_language::translate('Your FTP backup settings have been saved.'), 'zannounceok');
        }
        if (self::$ftpQueued !== '') {
            return ui_sysmessage::shout(ui_language::translate(self::$ftpQueued === 'check' ? 'Connection test queued: the result appears below within 5 minutes.' : 'Upload queued: it starts within 5 minutes; the result appears below when it is done.'), 'zannounceok');
        }
        if (!fs_director::CheckForEmptyValue(self::$filenotexist)) {
            return ui_sysmessage::shout("There was an error saving your backup!", "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$deleteok)) {
            return ui_sysmessage::shout("Backup deleted successfully!", "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$backupok)) {
            return ui_sysmessage::shout("Backup completed successfully!", "zannounceok");
        }
        return;
    }

}
