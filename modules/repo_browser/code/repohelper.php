<?php

/**
 * Repo Browser module: helper class (repo_browser_helper).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-06
 */

/**
 * Repo Browser helpers shared by the controller and ajax.php.
 * Everything that reaches the shell, the database or an outgoing HTTP request
 * is checked here first.
 */
class repo_browser_helper
{

    /**
     * Check a repository address: [https://]host[:port][/path] as stored in etc/zppy-cache/repo.list.
     *
     * @param mixed $repo The address (anything that is not a string fails).
     * @return bool
     */
    static function ValidRepo($repo)
    {
        return is_string($repo) && strlen($repo) <= 200
            && preg_match('#^(https://)?[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:[0-9]{1,5})?(/[A-Za-z0-9._~-]+)*/?$#', $repo) === 1;
    }

    /**
     * The address of a repository: "https://host/path" as written (GitHub serves only
     * HTTPS), or http:// in front of "host[:port][/path]" (the old form, kept working).
     *
     * @param string $repo The repository as stored.
     * @return string The base URL, without a trailing slash.
     */
    static function Base($repo)
    {
        $repo = rtrim((string) $repo, '/');
        return (stripos($repo, 'https://') === 0) ? $repo : 'http://' . $repo;
    }

    /**
     * What the admin typed: https:// kept, http:// dropped (it is the default).
     *
     * @param string $repo The typed address.
     * @return string The address as stored in repo.list.
     */
    static function Normalize($repo)
    {
        return preg_replace('#^http://#i', '', trim((string) $repo));
    }

    /**
     * Check a module folder name (letters, digits, '_' and '-', at most 64).
     *
     * @param mixed $module The name (anything that is not a string fails).
     * @return bool
     */
    static function ValidModule($module)
    {
        return is_string($module) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $module) === 1;
    }

    /**
     * Check a module version number such as 101 or 1.2.0.
     *
     * @param mixed $version The version (anything that is not a string fails).
     * @return bool
     */
    static function ValidVersion($version)
    {
        return is_string($version) && preg_match('/^[0-9]{1,6}(\.[0-9]{1,6}){0,3}$/', $version) === 1;
    }

    /**
     * The repositories currently in repo.list (comments, blank and invalid lines skipped).
     *
     * @return string[]
     */
    static function Repos()
    {
        $file = ctrl_options::GetSystemOption('sentora_root') . "etc/zppy-cache/repo.list";
        $repos = array();
        foreach ((array) @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#' && self::ValidRepo($line)) {
                $repos[] = $line;
            }
        }
        return $repos;
    }

    /**
     * Only repositories the admin added may be contacted (no requests to arbitrary hosts).
     *
     * @param mixed $repo The repository address.
     * @return bool True when it is valid and in repo.list.
     */
    static function KnownRepo($repo)
    {
        return self::ValidRepo($repo) && in_array($repo, self::Repos(), true);
    }

    /**
     * Split "repo:::module" into its repository and module.
     *
     * @param string $full The combined value.
     * @return array{0: string, 1: string}|false array(repo, module), or false when either part is not valid.
     */
    static function SplitFull($full)
    {
        $parts = explode(':::', (string) $full);
        if (count($parts) < 2 || !self::ValidRepo($parts[0]) || !self::ValidModule($parts[1])) {
            return false;
        }
        return array($parts[0], $parts[1]);
    }

    /**
     * Modules shipped with this panel: Repo Browser must never replace or remove them.
     *
     * @param string $module The module folder name (compared case-insensitively).
     * @return bool
     */
    static function IsCoreModule($module)
    {
        static $core = array(
            'aliases', 'apache_admin', 'autoip', 'backup_admin', 'backupmgr', 'client_notices', 'cron',
            'distlists', 'dns_admin', 'dns_manager', 'domains', 'faqs', 'forwarders', 'ftp_admin',
            'ftp_management', 'mail_admin', 'mailboxes', 'manage_clients', 'manage_groups', 'moduleadmin',
            'my_account', 'mysql_databases', 'mysql_users', 'packages', 'parked_domains', 'password_assistant',
            'php_versions', 'phpinfo', 'phpmyadmin', 'protected_directories', 'repo_browser',
            'sencrypt', 'sentoraconfig', 'services', 'shadowing', 'sub_domains', 'theme_manager',
            'usage_viewer', 'user_logviewer', 'webmail', 'zpx_core_module',
            // ZPanel names of the same modules
            'zpanelconfig', 'news', 'updates', 'htpasswd',
        );
        return in_array(strtolower((string) $module), $core, true);
    }

    /**
     * HTTP status check used to see whether a repository or package exists (HEAD request, no redirects).
     *
     * @param string $url The URL.
     * @return bool True when the server answers 200.
     */
    static function UrlExists($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return $code == 200;
    }

    /**
     * A small file (at most 256 KB, usually XML) from a known repository, or ''.
     *
     * @param string $url The URL.
     * @return string The content, or '' when it could not be read.
     */
    static function Fetch($url)
    {
        $ctx = stream_context_create(array('http' => array('timeout' => 10, 'follow_location' => 0)));
        $data = @file_get_contents($url, false, $ctx, 0, 262144);
        return ($data === false) ? '' : $data;
    }

    /**
     * Escape text for HTML.
     *
     * @param mixed $text The text (used as a string).
     * @return string
     */
    static function h($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }


    /**
     * Allowed zppy command lines (also checked again by the root job):
     *   update | checkperms | --version | --help
     *   install|upgrade <module>   remove <module> [-y]   repo add|remove <[https://]host[/path]>
     *   install-upload <module>    (a .zpp uploaded in Module Admin)
     * Modules shipped with the panel can not be installed over, upgraded or removed.
     *
     * @param string[] $args The zppy arguments.
     * @return bool
     */
    static function ValidZPPYArguments($args)
    {
        $n = count($args);
        if ($n == 0) {
            return false;
        }
        switch ($args[0]) {
            case 'update':
            case 'checkperms':
            case '--version':
            case '--help':
                return $n == 1;
            case 'install':
            case 'upgrade':
            case 'install-upload':
                return $n == 2 && self::ValidModule($args[1]) && !self::IsCoreModule($args[1]);
            case 'remove':
                return ($n == 2 || ($n == 3 && $args[2] === '-y')) && self::ValidModule($args[1]) && !self::IsCoreModule($args[1]);
            case 'repo':
                return $n == 3 && in_array($args[1], array('add', 'remove'), true) && self::ValidRepo($args[2]);
        }
        return false;
    }

    /**
     * Module changes are made by root, not by the panel: the panel queues the
     * command and the daemon runs it (within 5 minutes). The modules folder
     * belongs to root, so a break-in to the panel can not plant code that the
     * root daemon would run.
     *
     * Creates the x_zppy_queue table when it does not exist.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function QueueEnsure()
    {
        global $zdbh;
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_zppy_queue (
            zq_id_pk INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            zq_acc_fk INT NOT NULL, zq_args_tx TEXT NOT NULL, zq_status_vc VARCHAR(10) NOT NULL DEFAULT 'queued',
            zq_output_tx MEDIUMTEXT NULL, zq_created_in INT NOT NULL, zq_done_in INT NULL) ENGINE=InnoDB");
    }

    /**
     * The install, removal or upgrade of $module waiting for the daemon (queued or
     * running), as its command name, or false.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $module The module folder name.
     * @return string|false 'install', 'install-upload', 'remove' or 'upgrade'; false when nothing waits.
     */
    static function Pending($module)
    {
        global $zdbh;
        self::QueueEnsure();
        $sql = $zdbh->prepare("SELECT zq_args_tx FROM x_zppy_queue WHERE zq_status_vc IN ('queued', 'running') ORDER BY zq_id_pk");
        $sql->execute();
        while ($row = $sql->fetch()) {
            $args = json_decode((string) $row['zq_args_tx'], true);
            if (is_array($args) && isset($args[0], $args[1]) && $args[1] === (string) $module
                && in_array($args[0], array('install', 'install-upload', 'remove', 'upgrade'), true)) {
                return $args[0];
            }
        }
        return false;
    }

    /**
     * Each repository's latest add or remove job, if it is still waiting or it failed:
     * repo => array('state' => 'queued' or 'failed', 'action' => 'add' or 'remove', 'id' => job id).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<string, array{state: string, action: string, id: int}>
     */
    static function RepoJobs()
    {
        global $zdbh;
        self::QueueEnsure();
        $jobs = array();
        $sql = $zdbh->prepare("SELECT zq_id_pk, zq_args_tx, zq_status_vc FROM x_zppy_queue ORDER BY zq_id_pk");
        $sql->execute();
        while ($row = $sql->fetch()) {
            $args = json_decode((string) $row['zq_args_tx'], true);
            if (!is_array($args) || !isset($args[0], $args[1], $args[2]) || $args[0] !== 'repo') {
                continue;
            }
            $state = in_array($row['zq_status_vc'], array('queued', 'running'), true) ? 'queued'
                : ($row['zq_status_vc'] === 'failed' ? 'failed' : 'done');
            $jobs[(string) $args[2]] = array('state' => $state, 'action' => $args[1], 'id' => (int) $row['zq_id_pk']);
        }
        return array_filter($jobs, function ($j) { return $j['state'] !== 'done'; });
    }

    /**
     * Queue a zppy command for the daemon (root) to run.
     *
     * Refused when the arguments are not allowed, or when a job for the same module or
     * repository is already waiting.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string[] $args The zppy arguments.
     * @return bool True when queued.
     */
    static function Enqueue($args)
    {
        global $zdbh;
        if (!self::ValidZPPYArguments($args)) {
            return false;
        }
        // One job at a time per module: another install, removal or upgrade waits until
        // the daemon has run the one already queued
        $args = array_values($args);
        if (isset($args[0], $args[1]) && in_array($args[0], array('install', 'install-upload', 'remove', 'upgrade'), true)
            && self::Pending($args[1]) !== false) {
            return false;
        }
        // ... and one job at a time per repository
        if (isset($args[0], $args[2]) && $args[0] === 'repo') {
            $jobs = self::RepoJobs();
            if (isset($jobs[$args[2]]) && $jobs[$args[2]]['state'] === 'queued') {
                return false;
            }
        }
        self::QueueEnsure();
        $user = ctrl_users::GetUserDetail();
        $sql = $zdbh->prepare("INSERT INTO x_zppy_queue (zq_acc_fk, zq_args_tx, zq_created_in) VALUES (:a, :c, :t)");
        $json = json_encode(array_values($args));
        $now = time();
        $sql->bindParam(':a', $user['userid']);
        $sql->bindParam(':c', $json);
        $sql->bindParam(':t', $now);
        $sql->execute();
        return true;
    }

    /**
     * The latest queued zppy jobs, newest first.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $limit Optional. How many jobs. Default 10.
     * @return array<int, array<int|string, mixed>> The x_zppy_queue rows.
     */
    static function Recent($limit = 10)
    {
        global $zdbh;
        self::QueueEnsure();
        $sql = $zdbh->prepare("SELECT * FROM x_zppy_queue ORDER BY zq_id_pk DESC LIMIT " . (int) $limit);
        $sql->execute();
        return $sql->fetchAll();
    }

    /** where Module Admin keeps an uploaded .zpp until the root job installs it */
    const UPLOAD_DIR = '/var/lib/sentora/module-uploads/';
}

?>
