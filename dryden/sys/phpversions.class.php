<?php

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

    /**
     * Last day of security fixes from the PHP project for each version.
     */
    static $supportEnds = array(
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    );

    /**
     * The system PHP version, used by the panel and by domains without a choice.
     */
    static function DefaultVersion()
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * Extra versions installed on this server, e.g. array('8.1', '8.2').
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
     * Installed versions the administrator has enabled.
     */
    static function Enabled()
    {
        $setting = (string) ctrl_options::GetSystemOption('php_versions_enabled');
        $chosen = array_filter(array_map('trim', explode(',', $setting)));
        return array_values(array_intersect(self::Installed(), $chosen));
    }

    static function IsEnabled($version)
    {
        return in_array((string) $version, self::Enabled(), true);
    }

    /**
     * Normalise a version a user picked: '' (the default) or an enabled version.
     */
    static function Clean($version)
    {
        $version = trim((string) $version);
        return self::IsEnabled($version) ? $version : '';
    }

    static function Socket($version)
    {
        return self::SOCKET_DIR . 'php' . $version . '-fpm.sock';
    }

    static function Service($version)
    {
        return 'sentora-php' . $version . '-fpm';
    }

    /**
     * Version a domain actually runs: its choice if that version is still
     * enabled, otherwise the default.
     */
    static function Effective($version)
    {
        return self::IsEnabled($version) ? (string) $version : self::DefaultVersion();
    }

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
     * <option> list for the domain forms: the default first, then enabled versions.
     */
    static function SelectOptions($selected = '')
    {
        $selected = (string) $selected;
        $html = '<option value=""' . ($selected === '' ? ' selected="selected"' : '') . '>'
            . ui_language::translate('Default') . ' (PHP ' . self::DefaultVersion() . ')</option>';
        foreach (self::Enabled() as $version) {
            $html .= '<option value="' . $version . '"' . ($selected === $version ? ' selected="selected"' : '') . '>PHP '
                . $version . ' (' . self::SupportNote($version) . ')</option>';
        }
        return $html;
    }

    /**
     * Label for lists: "8.5 (default)" or "8.3", noting a disabled choice.
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
     * Apache lines that hand a domain's PHP files to its PHP-FPM version.
     * Returns '' for the default (mod_php) so the normal php_handler is used.
     * The open_basedir, upload and session settings that mod_php gets from
     * php_admin_value are passed to PHP-FPM as PHP_ADMIN_VALUE instead.
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
     * Number of live domains and subdomains that chose each version.
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

}

?>
