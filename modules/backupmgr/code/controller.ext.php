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
 * Updated by RusTus, 2026-11-07
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

    // --- page helpers ------------------------------------------------------------------------------

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
     * @param string $name The icon: archive, clock, trash, cloud, download, play, check, x, server, plug, upload.
     * @return string SVG markup, or '' for an unknown name.
     */
    static function Icon($name)
    {
        $p = array(
            'archive' => '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9"/><path d="M10 13h4"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'trash' => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="m6 7 1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
            'cloud' => '<path d="M7 18a5 5 0 1 1 1.6-9.7A6 6 0 0 1 20 11a4 4 0 0 1-1 7.9z"/>',
            'download' => '<path d="M20 15v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/><path d="m8 11 4 4 4-4"/><path d="M12 15V4"/>',
            'play' => '<circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4z"/>',
            'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
            'x' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
            'server' => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
            'plug' => '<path d="M9 2v6M15 2v6"/><path d="M6 8h12v4a6 6 0 0 1-12 0z"/><path d="M12 18v4"/>',
            'upload' => '<path d="M20 15v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/><path d="m16 8-4-4-4 4"/><path d="M12 4v11"/>',
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
     * Template HTML: four cards (backups kept, the latest, automatic deletion, the FTP copy).
     *
     * @return string HTML.
     */
    static function getSummary()
    {
        $me = ctrl_users::GetUserDetail();
        $files = sys_backup::ListFiles($me['username']);
        $total = 0;
        foreach ($files as $f) {
            $total += (int) $f['size'];
        }
        $latest = $files ? reset($files) : null;
        $purge = self::CheckPurgeDate();
        $card = function ($icon, $color, $label, $value, $detail) {
            return '<div class="bk-card" style="--c:' . $color . '">' . self::Icon($icon) . '<div><div class="bk-card-label">' . self::t($label) . '</div>'
                . '<div class="bk-card-value">' . $value . '</div><div class="bk-card-detail">' . $detail . '</div></div></div>';
        };
        $out = $card('archive', '#447fb3', 'Backups kept', (string) count($files), count($files) ? str_replace('%s', self::h(fs_director::ShowHumanFileSize($total)), self::t('%s in all')) : self::t('none yet'));
        $out .= $card('clock', '#2db87d', 'Latest backup', $latest ? self::h(self::Ago($latest['mtime'])) : '–', $latest ? self::h(date('Y-m-d H:i', $latest['mtime'])) : self::t('make one below'));
        $out .= $card('trash', '#f5a623', 'Kept for', $purge ? str_replace('%s', self::h($purge), self::t('%s days')) : self::t('Until deleted'), $purge ? self::t('older backups are deleted automatically') : self::t('backups are not deleted automatically'));
        if (sys_clientftp::Allowed()) {
            $c = sys_clientftp::Get($me['userid']);
            $st = sys_clientftp::Status($me['userid']);
            $on = (int) $c['bf_enabled_in'] === 1 && $c['bf_host_vc'] !== '';
            $out .= $card('cloud', $on ? '#1fa2b5' : '#9aa5b1', 'Copy to my FTP', $on ? sprintf(self::t('Daily at %s'), sprintf('%02d:00', (int) $c['bf_hour_in'])) : self::t('Off'),
                !empty($st['last_ok']) ? self::t('last good copy') . ' ' . self::h(self::Ago($st['last_ok'])) : ($on ? self::t('no copy yet') : self::t('set it up below')));
        }
        return $out;
    }

    /**
     * Template HTML: the account's backups as table rows, each with Download and Delete.
     *
     * The delete button is named inDelete_<backup name>, as doDeleteBackup() reads it.
     *
     * @return string HTML table rows.
     */
    static function getBackupRows()
    {
        $me = ctrl_users::GetUserDetail();
        $out = '';
        $first = true;
        foreach (sys_backup::ListFiles($me['username']) as $file => $info) {
            $name = substr($file, 0, -4);
            $out .= '<tr><td><div class="bk-file">' . self::Icon('archive') . '<div><b>' . self::h($file) . '</b>' . ($first ? ' <span class="bk-tag">' . self::t('latest') . '</span>' : '')
                . '<span class="bk-sub">' . self::h($me['username']) . '/backups/</span></div></div></td>'
                . '<td>' . self::h(date('Y-m-d H:i', $info['mtime'])) . '<span class="bk-sub">' . self::h(self::Ago($info['mtime'])) . '</span></td>'
                . '<td class="bk-num">' . self::h(fs_director::ShowHumanFileSize($info['size'])) . '</td>'
                . '<td class="bk-act"><a class="btn btn-default btn-sm bk-btn" href="modules/backupmgr/code/downloadbackup.php?id=' . (int) $me['userid'] . '&amp;file=' . rawurlencode($file) . '">' . self::Icon('download') . '<span>' . self::t('Download') . '</span></a> '
                . '<form class="bk-inline bk-confirm" action="./?module=backupmgr&amp;action=DeleteBackup" method="post" data-confirm="' . self::h(sprintf(ui_language::translate('Delete the backup %s? This can not be undone.'), $file)) . '">'
                . runtime_csfr::Token() . '<button type="submit" class="btn btn-default btn-sm bk-btn bk-del" name="inDelete_' . self::h($name) . '" value="' . self::h($name) . '" title="' . self::t('Delete') . '">' . self::Icon('trash') . '</button></form></td></tr>';
            $first = false;
        }
        return $out;
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
        if ($p['state'] === 'running') {
            return '<div class="bk-progress" id="bk-progress" data-status="modules/backupmgr/code/status.php" data-started="' . (int) $p['started'] . '">'
                . '<span class="bk-spinner" aria-hidden="true"></span>'
                . '<div><b>' . self::t('Making your backup') . '</b><span>' . self::t('Started at') . ' ' . self::h(date('H:i', $p['started'])) . ' · <span id="bk-elapsed">' . (int) $p['elapsed'] . 's</span></span>'
                . '<span class="bk-sub">' . self::t('Your websites and databases are being packed. Large sites can take several minutes. You can leave this page: the backup appears in the list below when it is ready.') . '</span></div></div>';
        }
        return '<form class="bk-now" action="./?module=backupmgr&amp;action=BackupNow" method="post">' . runtime_csfr::Token()
            . '<div><b>' . self::t('Back up your websites and databases') . '</b><span class="bk-sub">' . self::t('Everything in your public_html folder and all your MySQL databases, in one zip. It is made in the background and appears in the list below, ready to download. Your mail is not included.') . '</span></div>'
            . '<button class="btn btn-primary bk-btn" type="submit">' . self::Icon('play') . '<span>' . self::t('Back up now') . '</span></button></form>';
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

    /** @var array<int, string|array{0: string, 1: string}> Errors from the FTP settings form: English text, or
     *       array(English text with %s, the translated value for %s). Translated when shown. */
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
            if ($ip === false) {
                // the reason translated too; an address in it goes in after translating
                $why = preg_match('/^that address \((.*)\) is not allowed$/', (string) $why, $m)
                    ? str_replace('%s', $m[1], ui_language::translate('that address (%s) is not allowed')) : ui_language::translate((string) $why);
                $e[] = array('That server can not be used: %s.', $why);
            }
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
        $field = function ($label, $input, $help, $wide = false) {
            return '<label class="bk-field' . ($wide ? ' bk-wide' : '') . '"><span>' . self::t($label) . '</span>' . $input . ($help !== '' ? '<small>' . $help . '</small>' : '') . '</label>';
        };
        $opt = function ($value, $label, $cur) { return '<option value="' . self::h($value) . '"' . ((string) $cur === (string) $value ? ' selected' : '') . '>' . self::t($label) . '</option>'; };
        $hours = '';
        for ($i = 0; $i < 24; $i++) {
            $hours .= '<option value="' . $i . '"' . ((int) $c['bf_hour_in'] === $i ? ' selected' : '') . '>' . sprintf('%02d:00', $i) . '</option>';
        }
        $hasPw = ((string) $c['bf_pass_tx'] !== '');
        return '<form class="bk-ftp" action="./?module=backupmgr&amp;action=FtpSave" method="post">' . runtime_csfr::Token()
            . '<label class="bk-switch bk-wide"><input type="checkbox" name="inFtpEnabled" value="1"' . ((int) $c['bf_enabled_in'] === 1 ? ' checked' : '') . '><span class="bk-toggle" aria-hidden="true"></span>'
            . '<span><b>' . self::t('Copy a backup to my FTP server every day') . '</b><small>' . self::t('Your websites and databases (not your mail).') . '</small></span></label>'
            . $field('Server', '<input class="form-control" type="text" name="inFtpHost" value="' . self::h($c['bf_host_vc']) . '" placeholder="ftp.example.com" maxlength="253">', '')
            . $field('Port', '<input class="form-control" type="text" name="inFtpPort" value="' . self::h($c['bf_port_in']) . '" inputmode="numeric" maxlength="5">', self::t('21 for FTP and FTPS with TLS, 990 for implicit FTPS.'))
            . $field('Username', '<input class="form-control" type="text" name="inFtpUser" value="' . self::h($c['bf_user_vc']) . '" maxlength="128" autocomplete="off">', '')
            . $field('Password', '<input class="form-control" type="password" name="inFtpPassword" value="" maxlength="256" autocomplete="new-password" placeholder="' . ($hasPw ? self::t('stored: leave empty to keep it') : '') . '">', self::t('Stored encrypted, never shown again.'))
            . $field('Folder', '<input class="form-control" type="text" name="inFtpPath" value="' . self::h($c['bf_path_vc']) . '" maxlength="200">', self::t('Made if it does not exist.'))
            . $field('Connection', '<select class="form-control" name="inFtpTls">' . $opt('explicit', 'FTPS: TLS on port 21 (recommended)', $c['bf_tls_vc']) . $opt('implicit', 'FTPS: implicit TLS (port 990)', $c['bf_tls_vc']) . $opt('none', 'Plain FTP: NOT encrypted', $c['bf_tls_vc']) . '</select>'
                . '<span class="bk-check"><input type="checkbox" name="inFtpInsecure" value="1"' . ((int) $c['bf_insecure_in'] === 1 ? ' checked' : '') . '> ' . self::t('Accept a self-signed certificate') . '</span>',
                self::t('Plain FTP sends your password and your backups unencrypted. Accept a self-signed certificate only for a server you run yourself.'))
            . $field('Time', '<select class="form-control" name="inFtpHour">' . $hours . '</select>', sprintf(self::t('Every day at this hour (server time, %s).'), self::h(date('T'))))
            . $field('Copies kept', '<input class="form-control" type="text" name="inFtpKeep" value="' . self::h($c['bf_keep_in']) . '" inputmode="numeric" maxlength="2">', self::t('Older copies are removed from your FTP server.'))
            . '<div class="bk-form-foot bk-wide"><button class="btn btn-primary" type="submit">' . self::t('Save settings') . '</button></div></form>';
    }

    /**
     * Template HTML: the FTP copy's state (queued job, last test, last upload, last good copy) and the Test connection and Upload now buttons.
     *
     * @return string HTML.
     */
    static function getFtpStatus()
    {
        $me = ctrl_users::GetUserDetail();
        $c = sys_clientftp::Get($me['userid']);
        $s = sys_clientftp::Status($me['userid']);
        $line = function ($icon, $class, $label, $when, $message) {
            return '<div class="bk-state ' . $class . '">' . self::Icon($icon) . '<div><b>' . self::t($label) . '</b><span>' . $when . '</span>' . ($message !== '' ? '<span class="bk-sub">' . self::h($message) . '</span>' : '') . '</div></div>';
        };
        $when = function ($ts) { return (int) $ts > 0 ? self::h(date('Y-m-d H:i', (int) $ts)) . ' · ' . self::h(self::Ago((int) $ts)) : self::t('never'); };
        $out = '';
        if ($c['bf_request_vc'] !== '') {
            $out .= $line('clock', 'info', $c['bf_request_vc'] === 'check' ? 'Connection test queued' : 'Upload queued', self::t('starts within 5 minutes'), '');
        }
        if (isset($s['check_time'])) {
            $ok = !empty($s['check_ok']);
            $out .= $line($ok ? 'check' : 'x', $ok ? 'ok' : 'bad', $ok ? 'Connection test passed' : 'Connection test failed', $when($s['check_time']), (string) $s['check_message']);
        }
        if (isset($s['run_time'])) {
            $ok = !empty($s['run_ok']);
            $out .= $line($ok ? 'check' : 'x', $ok ? 'ok' : 'bad', $ok ? 'Last upload' : 'Last upload failed', $when($s['run_time']), (string) $s['run_message']);
        }
        $out .= $line('archive', '', 'Last good copy', $when(isset($s['last_ok']) ? $s['last_ok'] : 0), '');
        $disabled = $c['bf_host_vc'] === '' ? ' disabled title="' . self::t('Save your FTP server first.') . '"' : '';
        return $out . '<div class="bk-ftp-actions">'
            . '<form class="bk-inline" method="post" action="./?module=backupmgr&amp;action=FtpCheck">' . runtime_csfr::Token() . '<button class="btn btn-default btn-sm bk-btn" type="submit"' . $disabled . '>' . self::Icon('plug') . '<span>' . self::t('Test connection') . '</span></button></form>'
            . '<form class="bk-inline" method="post" action="./?module=backupmgr&amp;action=FtpNow">' . runtime_csfr::Token() . '<button class="btn btn-default btn-sm bk-btn" type="submit"' . $disabled . '>' . self::Icon('upload') . '<span>' . self::t('Upload now') . '</span></button></form></div>';
    }

    /**
     * Template HTML: the result message of the last form action.
     *
     * @return string|null HTML message, or null when there is nothing to show.
     */
    static function getResult()
    {
        foreach (self::$ftpErrors as $e) {
            $text = is_array($e) ? str_replace('%s', $e[1], ui_language::translate($e[0])) : ui_language::translate($e);
            return ui_sysmessage::shout(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), 'zannounceerror');
        }
        if (self::$ftpSaved) {
            return ui_sysmessage::shout(ui_language::translate('Your FTP backup settings have been saved.'), 'zannounceok');
        }
        if (self::$ftpQueued !== '') {
            return ui_sysmessage::shout(ui_language::translate(self::$ftpQueued === 'check' ? 'Connection test queued: the result appears below within 5 minutes.' : 'Upload queued: it starts within 5 minutes; the result appears below when it is done.'), 'zannounceok');
        }
        if (!fs_director::CheckForEmptyValue(self::$filenotexist)) {
            return ui_sysmessage::shout(ui_language::translate("There was an error saving your backup!"), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$deleteok)) {
            return ui_sysmessage::shout(ui_language::translate("Backup deleted successfully!"), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$backupok)) {
            return ui_sysmessage::shout(ui_language::translate("Backup completed successfully!"), "zannounceok");
        }
        return;
    }

}
