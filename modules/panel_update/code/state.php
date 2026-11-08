<?php

/**
 * Panel Update: what the page shows, read from the files sentora-update (root) writes.
 *
 * sentora-update keeps its view of a run in /var/lib/sentora-update (only root may write there):
 * "status" (one tab-separated line per event of the last update or rollback), "available" (the
 * last "sentora-update --status" answer and when) and "backups" (its backups, whose own folder is
 * root's only). The panel starts runs through zsudo (panel-check, panel-update, panel-rollback)
 * and reads these files; it never runs anything as root itself.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-07
 */
class panel_update_state
{

    /** Where sentora-update writes its files. */
    const DIR = '/var/lib/sentora-update/';

    /** The release this server runs, as sentora-update and the installer record it. */
    const INSTALLED = '/var/lib/sentora/last-update';

    /** Who asked for what, written by the panel. */
    const REQUESTS = '/var/lib/sentora/panel-update.log';

    /** Where the backups are (on the server; the panel can not read the folder). */
    const BACKUPS = '/var/sentora/backups/';

    /**
     * Allow only panel administrators (for the JSON endpoint); ends the request otherwise.
     *
     * @return array<string, mixed> The administrator's details.
     */
    static function RequireAdmin()
    {
        session_start();
        ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
        $uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
        $user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
        $asked = isset($_SESSION['pu_asked']) ? (int) $_SESSION['pu_asked'] : 0;
        // the panel's texts in the account's language (no request controller here)
        ui_language::ForAccount($uid);
        session_write_close();
        if (!$user || (int) $user['usergroupid'] !== 1) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit(ui_language::translate('Panel Update is only available to panel administrators.'));
        }
        $user['pu_asked'] = $asked;
        return $user;
    }

    /**
     * The release installed: "current, 2026-11-07 03:26" (or '' when not recorded).
     *
     * @return string The text after the zip's SHA-256.
     */
    static function Installed()
    {
        $line = trim((string) @file_get_contents(self::INSTALLED));
        $parts = explode(' ', $line, 2);
        return isset($parts[1]) ? $parts[1] : '';
    }

    /**
     * When the panel's code was last put in place, from a release line as sentora-update and the installer
     * write it ("current, 2026-11-06 23:11" or "current, 2026-11-06 22:30 by the installer").
     *
     * @param string $text The release line (without the SHA-256).
     * @return array{when: int, installer: bool} 'when': a Unix time (0 when it can not be read); 'installer': made by the installer.
     */
    static function ParseInstalled($text)
    {
        $when = preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2})/', (string) $text, $m) ? (int) strtotime($m[1]) : 0;
        return array('when' => $when, 'installer' => strpos((string) $text, 'by the installer') !== false);
    }

    /**
     * The Sentora version of the panel's code (its VERSION file).
     *
     * @return string For example '2027' ('' when unknown).
     */
    static function Version()
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '', (string) @file_get_contents('VERSION'));
    }

    /**
     * The last answer to "is a newer panel published?".
     *
     * @return array<string, mixed> 'state' ('available', 'current', 'unknown' or 'never'), 'checked' (Unix time or 0),
     *         'tag', 'date' (Y-m-d or ''), 'repo'.
     */
    static function Available()
    {
        $r = array('state' => 'never', 'checked' => 0, 'tag' => '', 'date' => '', 'repo' => '');
        $line = trim((string) @file_get_contents(self::DIR . 'available'));
        if (!preg_match('/^(\d+) (available|current|unknown)(?: (\S+) (\S+) (\S+))?$/', $line, $m)) {
            return $r;
        }
        $r['checked'] = (int) $m[1];
        $r['state'] = $m[2];
        if ($m[2] === 'available') {
            $r['tag'] = $m[3];
            $r['date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $m[4]) ? $m[4] : '';
            $r['repo'] = preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $m[5]) ? $m[5] : '';
        }
        return $r;
    }

    /**
     * The backups sentora-update made, newest first.
     *
     * @return array<int, array<string, mixed>> 'name', 'size' (bytes), 'time' (Unix time).
     */
    static function Backups()
    {
        $out = array();
        foreach (preg_split('/\n/', (string) @file_get_contents(self::DIR . 'backups'), -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $f = explode("\t", $line);
            if (count($f) === 3 && self::IsBackupName($f[0])) {
                $out[] = array('name' => $f[0], 'size' => (int) $f[1], 'time' => (int) $f[2]);
            }
        }
        return $out;
    }

    /**
     * Whether a name is one of sentora-update's backup names (what zsudo panel-rollback accepts).
     *
     * @param string $name The file name.
     * @return bool
     */
    static function IsBackupName($name)
    {
        return (bool) preg_match('/^panel-before-update-\d{8}-\d{6}\.tar\.gz$/', (string) $name);
    }

    /**
     * The last update or rollback, from sentora-update's status file.
     *
     * @return array<string, mixed>|null 'mode' ('update' or 'rollback'), 'started', 'from' ('console' or 'panel'),
     *         'steps' (each 'n', 'total', 'title', 'state' ('running', 'ok', 'warn', 'fail'), 'took', 'lines' ([kind, text])),
     *         'total', 'backup', 'result' ('running', 'ok', 'warn', 'same', 'fail', 'stalled'), 'message', 'release', 'updated';
     *         null when there was none.
     */
    static function Run()
    {
        $file = self::DIR . 'status';
        $text = (string) @file_get_contents($file);
        if ($text === '') {
            return null;
        }
        $run = array('mode' => '', 'started' => 0, 'from' => '', 'steps' => array(), 'total' => 0, 'backup' => '', 'result' => 'running',
            'message' => '', 'release' => '', 'updated' => (int) @filemtime($file));
        $step = -1;
        foreach (preg_split('/\n/', $text, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $f = explode("\t", $line);
            switch ($f[0]) {
                case 'start':
                    $run['mode'] = isset($f[1]) && $f[1] === 'rollback' ? 'rollback' : 'update';
                    $run['started'] = isset($f[2]) ? (int) $f[2] : 0;
                    $run['from'] = isset($f[3]) && $f[3] === 'panel' ? 'panel' : 'console';
                    break;
                case 'step':
                    $run['steps'][] = array('n' => (int) $f[1], 'total' => (int) $f[2], 'title' => (string) $f[3], 'state' => 'running', 'took' => 0, 'lines' => array());
                    $step = count($run['steps']) - 1;
                    $run['total'] = (int) $f[2];
                    break;
                case 'info':
                case 'warn':
                    if ($step >= 0 && isset($f[1])) {
                        $run['steps'][$step]['lines'][] = array($f[0], (string) $f[1]);
                    }
                    break;
                case 'end':
                    if ($step >= 0) {
                        $run['steps'][$step]['state'] = in_array($f[1], array('ok', 'warn', 'fail'), true) ? $f[1] : 'ok';
                        $run['steps'][$step]['took'] = isset($f[2]) ? (int) $f[2] : 0;
                    }
                    break;
                case 'backup':
                    $name = basename((string) $f[1]);
                    $run['backup'] = self::IsBackupName($name) ? $name : '';
                    break;
                case 'done':
                    $run['result'] = in_array($f[1], array('ok', 'warn', 'same'), true) ? $f[1] : 'ok';
                    $run['message'] = isset($f[2]) ? (string) $f[2] : '';
                    $run['release'] = isset($f[3]) ? (string) $f[3] : '';
                    break;
                case 'fail':
                    $run['result'] = 'fail';
                    $run['message'] = isset($f[1]) ? (string) $f[1] : '';
                    break;
            }
        }
        // no word for 20 minutes and not finished: it was stopped (the server restarted, or it was killed)
        if ($run['result'] === 'running' && time() - $run['updated'] > 1200) {
            $run['result'] = 'stalled';
        }
        return $run;
    }

    /**
     * The console command that goes back to the version before a run (or lists the backups).
     *
     * @param string $backup Optional. A backup name. Default '' (list them).
     * @return string The command.
     */
    static function RollbackCommand($backup = '')
    {
        return 'sentora-update --rollback' . (self::IsBackupName($backup) ? ' ' . self::BACKUPS . $backup : '');
    }

    /**
     * Ask root through zsudo: panel-check, panel-update or panel-rollback <backup>.
     *
     * @param string $action 'panel-check', 'panel-update' or 'panel-rollback'.
     * @param string $backup Optional. The backup name for panel-rollback. Default ''.
     * @return int 0 when the job was handed to root, 2 when this server's zsudo does not have the action yet, else another code.
     */
    static function Ask($action, $backup = '')
    {
        $zsudo = (string) ctrl_options::GetSystemOption('zsudo');
        if (!in_array($action, array('panel-check', 'panel-update', 'panel-rollback'), true) || $zsudo === '' || !is_executable($zsudo) || !function_exists('exec')) {
            return 1;
        }
        if ($action === 'panel-rollback' && !self::IsBackupName($backup)) {
            return 1;
        }
        $out = array();
        exec(escapeshellcmd($zsudo) . ' ' . $action . ($action === 'panel-rollback' ? ' ' . escapeshellarg($backup) : '') . ' 2>&1', $out, $rc);
        return (int) $rc;
    }

    /**
     * Note who asked for what, in /var/lib/sentora/panel-update.log (System Log Viewer, Panel security).
     *
     * @param array<string, mixed> $user The administrator (ctrl_users::GetUserDetail()).
     * @param string $what What was asked, in English.
     * @return void
     */
    static function Log($user, $what)
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '-';
        @file_put_contents(self::REQUESTS, date('Y-m-d H:i:s') . ' ' . $user['username'] . ' (' . $ip . '): ' . $what . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Check the panel password of the logged-in administrator (the same check the login form makes).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param mixed $password The password typed.
     * @return bool
     */
    static function PasswordOk($uid, $password)
    {
        global $zdbh;
        if (!is_string($password) || $password === '') {
            return false;
        }
        $q = $zdbh->prepare("SELECT ac_pass_vc, ac_passsalt_vc FROM x_accounts WHERE ac_id_pk = :id AND ac_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $uid));
        $row = $q->fetch();
        if (!$row) {
            return false;
        }
        $crypto = new runtime_hash;
        $crypto->SetPassword($password);
        $crypto->SetSalt($row['ac_passsalt_vc']);
        return hash_equals((string) $row['ac_pass_vc'], (string) $crypto->CryptParts($crypto->Crypt())->Hash);
    }
}
