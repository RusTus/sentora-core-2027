<?php

/**
 * Core class: PHP settings for one domain, set by an administrator in Apache Admin.
 *
 * A domain with its own PHP settings gets its own PHP-FPM pool (web<account>-d<domain>),
 * run by the same Linux user as the account's pool and fenced in the same way; the settings
 * are php_value lines in that pool. Only a list of everyday settings may be set (sizes, times,
 * error display, time zone, session cookies), never the safety fences (open_basedir, temp
 * folders, disable_functions, extensions). A php_value is like a line in php.ini: the site's
 * own .user.ini or ini_set() can still change what PHP lets a site change.
 *
 * Why a pool of its own: PHP-FPM keeps a setting sent with a request in the worker that
 * served it, so settings sent per request would reach the account's other domains.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-12
 */
class sys_domainphp
{
    /** The x_vhosts column that holds the settings (name = value lines). */
    const COLUMN = 'vh_php_ini_tx';

    /** At most this many settings for one domain. */
    const MAX = 40;

    /**
     * The settings that may be set, with the kind of value each takes.
     *
     * @return array<string, string> name => size, int, bool, errlevel, word, zone, samesite
     */
    static function Allowed()
    {
        return array(
            'memory_limit' => 'size',
            'upload_max_filesize' => 'size',
            'post_max_size' => 'size',
            'max_execution_time' => 'int',
            'max_input_time' => 'int',
            'max_input_vars' => 'int',
            'max_input_nesting_level' => 'int',
            'max_file_uploads' => 'int',
            'default_socket_timeout' => 'int',
            'display_errors' => 'bool',
            'display_startup_errors' => 'bool',
            'log_errors' => 'bool',
            'html_errors' => 'bool',
            'error_reporting' => 'errlevel',
            'output_buffering' => 'boolint',
            'short_open_tag' => 'bool',
            'allow_url_fopen' => 'bool',
            'zlib.output_compression' => 'bool',
            'default_charset' => 'word',
            'date.timezone' => 'zone',
            'pcre.backtrack_limit' => 'int',
            'pcre.recursion_limit' => 'int',
            'session.gc_maxlifetime' => 'int',
            'session.cookie_lifetime' => 'int',
            'session.cookie_secure' => 'bool',
            'session.cookie_httponly' => 'bool',
            'session.cookie_samesite' => 'samesite',
            'session.use_strict_mode' => 'bool',
            'session.name' => 'word',
        );
    }

    /**
     * Add the column to x_vhosts on a server installed before it (no settings for any domain).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return bool True when the column is there.
     */
    static function EnsureColumn()
    {
        global $zdbh;
        static $done = null;
        if ($done !== null) {
            return $done;
        }
        try {
            $has = (int) $zdbh->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_vhosts' AND COLUMN_NAME = '" . self::COLUMN . "'")->fetchColumn();
            if (!$has) {
                $zdbh->exec("ALTER TABLE x_vhosts ADD COLUMN " . self::COLUMN . " TEXT NULL");
            }
            $done = true;
        } catch (Exception $e) {
            $done = false;
        }
        return $done;
    }

    /**
     * Read the settings an administrator typed: one "name = value" a line; empty lines and
     * lines starting with ; or # are left out.
     *
     * @param string $text
     * @return array{0: array<string, string>, 1: string[]} The settings (name => value) and what is wrong, one line each.
     */
    static function Parse($text)
    {
        $kinds = self::Allowed();
        $out = array();
        $bad = array();
        foreach (preg_split('/\r?\n/', (string) $text) as $n => $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            $line = preg_replace('/^php_(?:admin_)?(?:value|flag)\s*\[?\s*/i', '', $line);
            if (!preg_match('/^([A-Za-z0-9_.]+)\s*\]?\s*[=\s]\s*(.*)$/', $line, $m)) {
                $bad[] = sprintf('Line %d: write it as name = value.', $n + 1);
                continue;
            }
            $name = strtolower($m[1]);
            $value = trim($m[2]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            if (!isset($kinds[$name])) {
                $bad[] = sprintf('Line %d: %s can not be set for one domain.', $n + 1, $name);
                continue;
            }
            $value = self::Clean($kinds[$name], $value);
            if ($value === null) {
                $bad[] = sprintf('Line %d: %s does not take that value.', $n + 1, $name);
                continue;
            }
            $out[$name] = $value;
        }
        if (count($out) > self::MAX) {
            $bad[] = sprintf('At most %d settings for one domain.', self::MAX);
        }
        return array($out, $bad);
    }

    /**
     * Check a value for its kind and write it the one way PHP-FPM reads it.
     *
     * @param string $kind
     * @param string $v
     * @return string|null The value, or null when it is not allowed.
     */
    static function Clean($kind, $v)
    {
        $v = trim((string) $v);
        $bools = array('on' => 'On', '1' => 'On', 'true' => 'On', 'yes' => 'On', 'off' => 'Off', '0' => 'Off', 'false' => 'Off', 'no' => 'Off', '' => 'Off');
        switch ($kind) {
            case 'size':
                return preg_match('/^(-1|[0-9]{1,6}[KMG]?)$/i', $v) ? strtoupper($v) : null;
            case 'int':
                return preg_match('/^-?[0-9]{1,9}$/', $v) ? (string) (int) $v : null;
            case 'bool':
                return isset($bools[strtolower($v)]) ? $bools[strtolower($v)] : (strtolower($v) === 'stderr' ? 'stderr' : null);
            case 'boolint':
                return isset($bools[strtolower($v)]) ? $bools[strtolower($v)] : (preg_match('/^[0-9]{1,7}$/', $v) ? $v : null);
            case 'errlevel':
                return preg_match('/^[A-Z_0-9 &~|^()-]{1,120}$/', $v) ? preg_replace('/\s+/', ' ', $v) : null;
            case 'word':
                return preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $v) ? $v : null;
            case 'zone':
                return in_array($v, timezone_identifiers_list(), true) ? $v : null;
            case 'samesite':
                $s = array('lax' => 'Lax', 'strict' => 'Strict', 'none' => 'None');
                return isset($s[strtolower($v)]) ? $s[strtolower($v)] : null;
        }
        return null;
    }

    /**
     * The settings of a domain (only the allowed ones; nothing when none or the column is missing).
     *
     * @param array<string, mixed> $vhost An x_vhosts row.
     * @return array<string, string>
     */
    static function Settings($vhost)
    {
        if (!isset($vhost[self::COLUMN]) || trim((string) $vhost[self::COLUMN]) === '') {
            return array();
        }
        list($set) = self::Parse((string) $vhost[self::COLUMN]);
        return array_slice($set, 0, self::MAX, true);
    }

    /**
     * The pool lines for the settings.
     *
     * @param array<string, string> $settings
     * @return string
     */
    static function PoolLines(array $settings)
    {
        $out = '';
        foreach ($settings as $k => $v) {
            $out .= 'php_value[' . $k . '] = ' . $v . "\n";
        }
        return $out;
    }

    /**
     * The domains with settings of their own, by account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<int, array{version: string, settings: array<string, string>}>> account id => domain id => its PHP version and settings
     */
    static function Domains()
    {
        global $zdbh;
        if (!self::EnsureColumn()) {
            return array();
        }
        $out = array();
        $sql = $zdbh->prepare("SELECT vh_id_pk, vh_acc_fk, vh_php_version_vc, " . self::COLUMN . " FROM x_vhosts WHERE vh_deleted_ts IS NULL AND " . self::COLUMN . " IS NOT NULL AND " . self::COLUMN . " <> ''");
        $sql->execute();
        while ($row = $sql->fetch()) {
            $set = self::Settings($row);
            if ($set) {
                $out[(int) $row['vh_acc_fk']][(int) $row['vh_id_pk']] = array(
                    'version' => sys_phpversions::Effective((string) $row['vh_php_version_vc']),
                    'settings' => $set,
                );
            }
        }
        return $out;
    }

    /**
     * Where a PHP version's FPM program is ('' when not found).
     *
     * @param string $version
     * @return string
     */
    static function FpmBinary($version)
    {
        if ($version !== sys_phpversions::DefaultVersion()) {
            $f = sys_phpversions::BASE_DIR . $version . '/sbin/php-fpm';
            return is_file($f) ? $f : '';
        }
        $name = basename((string) sys_platform::Get('php_fpm_process', array('v' => $version)));
        if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            return '';
        }
        foreach (array('/usr/sbin', '/usr/bin', '/sbin', '/usr/local/sbin') as $d) {
            if (is_file($d . '/' . $name) && is_executable($d . '/' . $name)) {
                return $d . '/' . $name;
            }
        }
        return '';
    }

    /**
     * Does a PHP version's FPM accept its whole configuration (every pool)?
     *
     * @param string $version
     * @return bool True when it does, or when it can not be asked (no FPM program found).
     */
    static function FpmTest($version)
    {
        $fpm = self::FpmBinary($version);
        if ($fpm === '') {
            return true;
        }
        $out = array();
        exec(ctrl_system::OwnFiles(escapeshellarg($fpm) . ' -t 2>&1'), $out, $rc);
        return $rc === 0;
    }
}
