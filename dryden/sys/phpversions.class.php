<?php

/**
 * Core class: extra PHP-FPM versions for hosted domains.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-08
 */

/**
 * Extra PHP versions for hosted domains.
 *
 * The panel and every domain without a choice run on the system PHP (8.5,
 * Apache's mod_php). Extra versions are PHP-FPM builds in
 * /opt/sentora-php/<version>/, each with its own systemd service and socket.
 * The administrator enables or disables them in the PHP Versions module; the
 * list of enabled versions is the 'php_versions_enabled' setting.
 */
class sys_phpversions
{

    const BASE_DIR = '/opt/sentora-php/';
    const SOCKET_DIR = '/run/sentora-php/';

    /** @var array<string, string> Last day of security fixes from the PHP project for each version: version => 'Y-m-d'. */
    static $supportEnds = array(
        '7.1' => '2019-12-01',
        '7.4' => '2022-11-28',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    );

    /**
     * Versions that are only there to move old websites to this server (7.1, from Sentora 2.0.2) or to
     * move their apps on to a supported PHP step by step (7.4, 8.1): no security fixes, installed switched
     * off, and marked as such wherever they can be chosen.
     *
     * @var string[]
     */
    static $migrationOnly = array('7.1', '7.4', '8.1');

    /**
     * Whether a version is only for moving old websites (see $migrationOnly).
     *
     * @param string $version PHP version, e.g. '7.1'.
     * @return bool
     */
    static function IsMigrationOnly($version)
    {
        return in_array((string) $version, self::$migrationOnly, true);
    }

    /**
     * Return the system PHP version, used by the panel and by domains without a choice.
     *
     * @return string Version as 'major.minor', e.g. '8.5'.
     */
    static function DefaultVersion()
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * List the extra versions installed on this server, e.g. array('8.1', '8.2').
     *
     * The system version is never in the list.
     *
     * @return string[] Versions, oldest first.
     */
    static function Installed()
    {
        $versions = array();
        foreach ((array) glob(self::BASE_DIR . '*/sbin/php-fpm') as $binary) {
            $version = basename(dirname(dirname($binary)));
            if (preg_match('/^\d+\.\d+$/', $version) && $version !== self::DefaultVersion()) {
                $versions[] = $version;
            }
        }
        usort($versions, 'version_compare');
        return $versions;
    }

    /**
     * List the installed versions the administrator has enabled.
     *
     * @return string[] Versions, oldest first.
     */
    static function Enabled()
    {
        $setting = (string) ctrl_options::GetSystemOption('php_versions_enabled');
        $chosen = array_filter(array_map('trim', explode(',', $setting)));
        return array_values(array_intersect(self::Installed(), $chosen));
    }

    /**
     * Check whether a version is installed and enabled.
     *
     * @param string $version PHP version, e.g. '8.3'.
     * @return bool True when the version is enabled.
     */
    static function IsEnabled($version)
    {
        return in_array((string) $version, self::Enabled(), true);
    }

    /**
     * Normalise a version a user picked: '' (the default) or an enabled version.
     *
     * @param string $version The submitted version.
     * @return string An enabled version, or '' for the default.
     */
    static function Clean($version)
    {
        $version = trim((string) $version);
        return self::IsEnabled($version) ? $version : '';
    }

    /**
     * Check whether a version's PHP-FPM service is running.
     *
     * Accounts have their own pools now, so the old shared socket is gone; every
     * running version keeps its small idle pool's socket (php<version>-idle.sock),
     * made when the service starts.
     *
     * @param string $version PHP version, e.g. '8.3'.
     * @return bool True when the idle pool's socket exists.
     */
    static function IsRunning($version)
    {
        $v = trim((string) $version);
        return preg_match('/^[0-9]+\.[0-9]+$/', $v) === 1 && file_exists(self::SOCKET_DIR . 'php' . $v . '-idle.sock');
    }

    /**
     * Return the old shared PHP-FPM socket of a version.
     *
     * @param string $version PHP version, e.g. '8.3'.
     * @return string Full socket path.
     */
    static function Socket($version)
    {
        return self::SOCKET_DIR . 'php' . $version . '-fpm.sock';
    }

    /**
     * Return the systemd service name of an extra version.
     *
     * @param string $version PHP version, e.g. '8.3'.
     * @return string Service name, e.g. 'sentora-php8.3-fpm'.
     */
    static function Service($version)
    {
        return 'sentora-php' . $version . '-fpm';
    }

    /**
     * Return the version a domain actually runs.
     *
     * Its choice if that version is still enabled, otherwise the default.
     *
     * @param string $version The domain's chosen version ('' for the default).
     * @return string PHP version, e.g. '8.3'.
     */
    static function Effective($version)
    {
        return self::IsEnabled($version) ? (string) $version : self::DefaultVersion();
    }

    /**
     * Return a translated note on how long a version gets security fixes.
     *
     * @param string $version PHP version, e.g. '8.3'.
     * @return string E.g. 'security fixes until 2027-12-31', or '' when unknown.
     */
    static function SupportNote($version)
    {
        if (!isset(self::$supportEnds[$version])) {
            return '';
        }
        $ends = self::$supportEnds[$version];
        if (strtotime($ends . ' 23:59:59') < time()) {
            return ui_language::translate('no security fixes since') . ' ' . $ends;
        }
        return ui_language::translate('security fixes until') . ' ' . $ends;
    }

    /**
     * Build the <option> list for the domain forms: the default first, then enabled versions.
     *
     * @param string $selected Optional. The version to preselect ('' for the default). Default ''.
     * @return string HTML <option> elements.
     */
    static function SelectOptions($selected = '')
    {
        $selected = (string) $selected;
        $html = '<option value=""' . ($selected === '' ? ' selected="selected"' : '') . '>'
            . ui_language::translate('Default') . ' (PHP ' . self::DefaultVersion() . ')</option>';
        foreach (self::Enabled() as $version) {
            $html .= '<option value="' . $version . '"' . ($selected === $version ? ' selected="selected"' : '') . '>PHP '
                . $version . ' (' . self::SupportNote($version) . ')'
                . (self::IsMigrationOnly($version) ? ' - ' . ui_language::translate('for moving old sites only') : '') . '</option>';
        }
        return $html;
    }

    /**
     * Return the label for lists: "8.5 (default)" or "8.3", noting a disabled choice.
     *
     * @param string $version The domain's chosen version ('' for the default).
     * @return string The label (not HTML-escaped).
     */
    static function Label($version)
    {
        $version = (string) $version;
        if ($version === '') {
            return self::DefaultVersion() . ' (' . ui_language::translate('default') . ')';
        }
        if (!self::IsEnabled($version)) {
            return self::DefaultVersion() . ' (' . $version . ' ' . ui_language::translate('is disabled') . ')';
        }
        return $version;
    }

    /**
     * Return the days until a version's security support ends.
     *
     * @param string $version PHP version, e.g. '8.3'.
     * @return int|null Days left (negative: ended), or null if unknown.
     */
    static function DaysLeft($version)
    {
        if (!isset(self::$supportEnds[$version])) {
            return null;
        }
        return (int) floor((strtotime(self::$supportEnds[$version] . ' 23:59:59') - time()) / 86400);
    }

    /**
     * Return the label for the domain lists.
     *
     * Label() plus a warning when the version the domain runs gets no security fixes
     * any more (red) or stops getting them within 90 days.
     *
     * @param string $version The domain's chosen version ('' for the default).
     * @return string HTML.
     */
    static function ListLabel($version)
    {
        $label = htmlspecialchars(self::Label($version), ENT_QUOTES, 'UTF-8');
        $run = self::Effective($version);
        $days = self::DaysLeft($run);
        if ($days === null) {
            return $label;
        }
        if ($days < 0) {
            return $label . ' <span class="label label-danger" title="' . htmlspecialchars(ui_language::translate('Choose a newer PHP version for this domain.'), ENT_QUOTES, 'UTF-8') . '">'
                . ui_language::translate('no security fixes since') . ' ' . self::$supportEnds[$run] . '</span>';
        }
        if ($days <= 90) {
            return $label . ' <span class="label label-warning">' . ui_language::translate('security fixes until') . ' ' . self::$supportEnds[$run] . '</span>';
        }
        return $label;
    }

    /**
     * Build the Apache lines that hand a domain's PHP files to its PHP-FPM version.
     *
     * Returns '' for the default (mod_php) so the normal php_handler is used.
     * The open_basedir, upload and session settings that mod_php gets from
     * php_admin_value are passed to PHP-FPM as PHP_ADMIN_VALUE instead.
     *
     * @param string $version PHP version, e.g. '8.3'.
     * @param string $openBasedir The open_basedir value, or '' for none.
     * @param string $tmpDir Folder for uploads and sessions.
     * @return string Apache configuration lines, or '' when the version is not enabled.
     */
    static function ApacheHandler($version, $openBasedir, $tmpDir)
    {
        if (!self::IsEnabled($version)) {
            return '';
        }
        $admin = array();
        if ($openBasedir !== '') {
            $admin[] = 'open_basedir=' . $openBasedir;
        }
        $admin[] = 'upload_tmp_dir=' . $tmpDir;
        $admin[] = 'session.save_path=' . $tmpDir;
        $nl = fs_filehandler::NewLine();
        $line = '# PHP ' . $version . ' (PHP-FPM)' . $nl;
        $line .= '<FilesMatch "\.(php|phtml|php3)$">' . $nl;
        $line .= '    SetHandler "proxy:unix:' . self::Socket($version) . '|fcgi://localhost/"' . $nl;
        $line .= '</FilesMatch>' . $nl;
        $line .= 'ProxyFCGISetEnvIf "true" PHP_ADMIN_VALUE "' . implode('\n', $admin) . '"' . $nl;
        return $line;
    }

    /**
     * Start enabled versions and stop disabled ones. Run by the daemon (root).
     *
     * Prints a line for each service it starts or stops.
     *
     * @return void
     */
    static function SyncServices()
    {
        $enabled = self::Enabled();
        foreach (self::Installed() as $version) {
            $service = escapeshellarg(self::Service($version));
            if (in_array($version, $enabled, true)) {
                exec('systemctl is-active --quiet ' . $service, $out, $active);
                if ($active !== 0) {
                    exec('systemctl enable --now ' . $service . ' 2>&1', $out, $rc);
                    echo "PHP " . $version . " (PHP-FPM): " . ($rc === 0 ? 'started' : 'start FAILED') . fs_filehandler::NewLine();
                }
            } else {
                exec('systemctl is-enabled --quiet ' . $service, $out, $isEnabled);
                if ($isEnabled === 0) {
                    exec('systemctl disable --now ' . $service . ' 2>&1', $out, $rc);
                    echo "PHP " . $version . " (PHP-FPM): disabled and stopped" . fs_filehandler::NewLine();
                }
            }
        }
    }

    /**
     * Count the live domains and subdomains that chose each version.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<string, int> Version => number of domains.
     */
    static function UsageCounts()
    {
        global $zdbh;
        $counts = array();
        $sql = $zdbh->prepare("SELECT vh_php_version_vc AS v, COUNT(*) AS n FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_php_version_vc IS NOT NULL AND vh_php_version_vc <> '' GROUP BY vh_php_version_vc");
        $sql->execute();
        while ($row = $sql->fetch()) {
            $counts[$row['v']] = (int) $row['n'];
        }
        return $counts;
    }


    /** Which PHP socket Apache was given for each domain, as the daemon last wrote it (and Apache ran with it). */
    const LIVE_FILE = '/var/lib/sentora/php-live.json';

    /**
     * Daemon: note, from the vhost configuration Apache now runs with, which PHP socket each domain was
     * given ({"domains": {"example.com": "/run/sentora-php/web1-7.1.sock", ...}, "time": ...}), for
     * LiveState(). Readable by the panel; it holds no secrets.
     *
     * @param string $config The vhost configuration text.
     * @return void
     */
    static function RecordLive($config)
    {
        $map = array();
        foreach (preg_split('/<virtualhost\b/i', (string) $config) as $block) {
            if (preg_match('/^\s*ServerName\s+(\S+)/mi', $block, $n) && preg_match('#SetHandler\s+"proxy:unix:([^|"]+)\|#i', $block, $h)) {
                $map[strtolower($n[1])] = $h[1];
            }
        }
        $dir = dirname(self::LIVE_FILE);
        if (!is_dir($dir)) {
            return;
        }
        $tmp = self::LIVE_FILE . '.new';
        if (@file_put_contents($tmp, json_encode(array('domains' => $map, 'time' => time()))) !== false) {
            @chmod($tmp, 0644);
            @rename($tmp, self::LIVE_FILE);
        }
    }

    /**
     * The exact PHP version of a version that is installed here, e.g. '7.1.33' for '7.1'.
     *
     * @param string $version 'major.minor'.
     * @return string The full version, or the same 'major.minor' when it can not be read.
     */
    static function ExactVersion($version)
    {
        if ($version === self::DefaultVersion()) {
            return PHP_VERSION;
        }
        $info = @file_get_contents(self::BASE_DIR . $version . '/BUILD-INFO', false, null, 0, 200);
        return ($info !== false && preg_match('/PHP (' . preg_quote($version, '/') . '\.\d+)/', $info, $m)) ? $m[1] : $version;
    }

    /**
     * Which PHP a domain really runs on, for the badge next to its PHP choice: the version Apache was
     * given for it (LIVE_FILE), whether that matches the choice, and whether that PHP's socket is there.
     *
     * @param string $domain The domain name.
     * @param string $chosen The domain's choice ('' = the default).
     * @return array{state: string, text: string} state: 'ok', 'pending', 'down' or 'unknown'.
     */
    static function LiveState($domain, $chosen)
    {
        static $live = null;
        if ($live === null) {
            $j = @json_decode((string) @file_get_contents(self::LIVE_FILE), true);
            $live = is_array($j) && isset($j['domains']) && is_array($j['domains']) ? $j['domains'] : array();
        }
        $want = self::Effective((string) $chosen);
        $sock = isset($live[strtolower($domain)]) ? (string) $live[strtolower($domain)] : '';
        if ($sock === '') {
            return array('state' => 'unknown', 'text' => '');
        }
        if (preg_match('#^/run/php/web\d+\.sock$#', $sock)) {
            $runs = self::DefaultVersion();
        } elseif (preg_match('#^' . preg_quote(self::SOCKET_DIR, '#') . 'web\d+-(\d+\.\d+)\.sock$#', $sock, $m)) {
            $runs = $m[1];
        } else {
            return array('state' => 'unknown', 'text' => '');
        }
        if ($runs !== $want) {
            return array('state' => 'pending', 'text' => str_replace('%s', $runs, ui_language::translate('Changing within 5 minutes (PHP %s until then)')));
        }
        if (!file_exists($sock)) {
            return array('state' => 'down', 'text' => str_replace('%s', $runs, ui_language::translate('PHP %s is not running')));
        }
        return array('state' => 'ok', 'text' => str_replace('%s', self::ExactVersion($runs), ui_language::translate('Running PHP %s')));
    }

}

?>
