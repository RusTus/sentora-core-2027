<?php

/**
 * Core class: today's defaults in the PHP settings of the hosted sites (the system PHP-FPM and the
 * extra PHP versions), on new and on existing servers.
 *
 * A setting changes only while it is still at PHP's old default, and only once: what an
 * administrator set in php.ini (before or after) is never changed. The line is written where PHP
 * reads it (the .ini file that sets it, or php.ini), as an ordinary line an administrator can
 * change, never as php_admin_value, so a site's .user.ini and a domain's own PHP settings (Apache
 * Admin) still win. PHP-FPM is tried with the change first and the files put back if it refuses.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-12
 */
class sys_phpdefaults
{
    /** What was done, per php.ini (so a setting is changed only once). */
    const STATE = '/var/lib/sentora/php-defaults.json';

    /**
     * The defaults: name => array(today's value, PHP's old values as ini_get() gives them).
     *
     * @return array<string, array{0: string, 1: string[]}>
     */
    static function Wanted()
    {
        return array(
            // uploads of today's photos, themes and plugins (PHP's 2M / 8M are from 2000s sites)
            'upload_max_filesize' => array('64M', array('2M')),
            'post_max_size' => array('72M', array('8M')),
            // large menus and shop products send more fields than 1000 (the rest is lost unseen)
            'max_input_vars' => array('3000', array('1000')),
            // a session id the site did not make itself is refused; the cookie is out of reach of
            // JavaScript; other sites' forms do not send it (a site can change each of these)
            'session.use_strict_mode' => array('1', array('0', '')),
            'session.cookie_httponly' => array('1', array('0', '')),
            'session.cookie_samesite' => array('Lax', array('')),
            // room for the scripts of several sites; one account's cached scripts are only
            // served to a user that may read the file (many accounts share one PHP-FPM)
            'opcache.memory_consumption' => array('256', array('128')),
            'opcache.interned_strings_buffer' => array('16', array('8')),
            'opcache.max_accelerated_files' => array('20000', array('10000', '4000')),
            'opcache.validate_permission' => array('1', array('0', '')),
        );
    }

    /**
     * Bring every PHP the sites use to today's defaults (run by the daemon as root).
     *
     * @return string[] Lines for the daemon's report.
     */
    static function Apply()
    {
        $report = array();
        $state = json_decode((string) @file_get_contents(self::STATE), true);
        $state = is_array($state) ? $state : array();
        $versions = array_unique(array_merge(array(sys_phpversions::DefaultVersion()), sys_phpversions::Installed()));
        foreach ($versions as $v) {
            $fpm = sys_domainphp::FpmBinary($v);
            if ($fpm === '') {
                continue;
            }
            $info = self::Info($fpm);
            if ($info['ini'] === '' || !is_file($info['ini'])) {
                continue;
            }
            $files = array_merge(array($info['ini']), $info['inis']);
            // nothing new since the last look: no need to ask PHP again
            $sig = self::Signature($files);
            $key = $info['ini'];
            if (isset($state[$key]['sig']) && $state[$key]['sig'] === $sig) {
                continue;
            }
            $done = isset($state[$key]['done']) && is_array($state[$key]['done']) ? $state[$key]['done'] : array();
            $backup = array();
            $changed = array();
            foreach (self::Wanted() as $name => $w) {
                if (isset($done[$name]) || !array_key_exists($name, $info['values'])) {
                    continue;
                }
                $now = $info['values'][$name];
                if (!in_array($now, $w[1], true)) {
                    // set to something else already (an administrator's choice): left alone for good
                    $done[$name] = 'kept ' . $now;
                    continue;
                }
                $file = self::Writer($files, $name, $info['ini']);
                if (!isset($backup[$file])) {
                    $backup[$file] = (string) file_get_contents($file);
                }
                if (self::Set($file, $name, $w[0])) {
                    $changed[$name] = $w[0];
                }
            }
            if ($changed) {
                if (!sys_domainphp::FpmTest($v)) {
                    foreach ($backup as $f => $text) {
                        file_put_contents($f, $text);
                    }
                    $report[] = 'PHP ' . $v . ': its FPM refused today\'s defaults, the files are as before';
                    // (tried again once one of its files changes, not every run)
                    $state[$key] = array('sig' => self::Signature($files), 'done' => isset($state[$key]['done']) ? $state[$key]['done'] : array());
                    continue;
                }
                foreach ($changed as $name => $val) {
                    $done[$name] = 'set ' . $val;
                }
                $service = sys_accountphp::ServiceName($v);
                exec(ctrl_system::OwnFiles('systemctl reload ' . escapeshellarg($service) . ' > /dev/null 2>&1'), $o, $rc);
                if ($rc !== 0) {
                    exec(ctrl_system::OwnFiles('systemctl restart ' . escapeshellarg($service) . ' > /dev/null 2>&1'));
                }
                $report[] = 'PHP ' . $v . ': today\'s defaults set (' . implode(', ', array_map(function ($k, $x) { return $k . ' ' . $x; }, array_keys($changed), $changed)) . ')';
            }
            $state[$key] = array('sig' => self::Signature($files), 'done' => $done);
        }
        @mkdir(dirname(self::STATE), 0755, true);
        @file_put_contents(self::STATE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $report;
    }

    /**
     * What a PHP-FPM reads: its php.ini, the other .ini files, and its current values.
     *
     * @param string $fpm The FPM program.
     * @return array{ini: string, inis: string[], values: array<string, string>}
     */
    static function Info($fpm)
    {
        $out = array();
        exec(ctrl_system::OwnFiles(escapeshellarg($fpm) . ' -i 2> /dev/null'), $out);
        $text = implode("\n", $out);
        $info = array('ini' => '', 'inis' => array(), 'values' => array());
        if (preg_match('/^Loaded Configuration File => (\S+)$/m', $text, $m)) {
            $info['ini'] = $m[1];
        }
        if (preg_match('/^Additional \.ini files parsed => (.+?)(?=^\S[^=\n]*=> |\z)/ms', $text, $m) && trim($m[1]) !== '(none)') {
            $info['inis'] = array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $m[1])), 'is_file'));
        }
        foreach (array_keys(self::Wanted()) as $name) {
            // "name => local value => master value" (no value: PHP writes "no value")
            if (preg_match('/^' . preg_quote($name, '/') . ' => (.*?) => (.*)$/m', $text, $m)) {
                $v = trim($m[2]);
                $info['values'][$name] = self::Plain($v === 'no value' ? '' : $v);
            }
        }
        return $info;
    }

    /**
     * A value as ini_get() gives it (phpinfo writes On/Off for switches).
     *
     * @param string $v
     * @return string
     */
    static function Plain($v)
    {
        $l = strtolower($v);
        return $l === 'on' ? '1' : ($l === 'off' ? '0' : $v);
    }

    /**
     * The file whose line for a setting PHP uses (the last one read that sets it), else php.ini.
     *
     * @param string[] $files php.ini first, then the others in PHP's order.
     * @param string $name
     * @param string $ini
     * @return string
     */
    static function Writer(array $files, $name, $ini)
    {
        $found = $ini;
        foreach ($files as $f) {
            if (preg_match('/^[ \t]*' . preg_quote($name, '/') . '[ \t]*=/m', (string) @file_get_contents($f))) {
                $found = $f;
            }
        }
        return $found;
    }

    /**
     * Write "name = value" into an .ini file: its own line changed, else the commented example
     * line (";name = ...") made a real one, else a new line under [PHP] (php.ini's first section,
     * so it is never inside a [HOST=] or [PATH=] section).
     *
     * @param string $file
     * @param string $name
     * @param string $value
     * @return bool True when the file changed.
     */
    static function Set($file, $name, $value)
    {
        $text = (string) file_get_contents($file);
        $q = preg_quote($name, '/');
        $line = $name . ' = ' . $value;
        if (preg_match('/^[ \t]*' . $q . '[ \t]*=.*$/m', $text)) {
            // the last one wins in PHP: change that one
            $new = preg_replace_callback('/^[ \t]*' . $q . '[ \t]*=.*$/m', function () use ($line) { return $line; }, $text);
        } elseif (preg_match('/^[ \t]*;[ \t]*' . $q . '[ \t]*=.*$/m', $text)) {
            $new = preg_replace('/^[ \t]*;[ \t]*' . $q . '[ \t]*=.*$/m', $line, $text, 1);
        } elseif (preg_match('/^\[PHP\][ \t]*$/mi', $text)) {
            $new = preg_replace('/^(\[PHP\][ \t]*)$/mi', "$1\n" . $line, $text, 1);
        } else {
            $new = $line . "\n" . $text;
        }
        if ($new === null || $new === $text) {
            return false;
        }
        return file_put_contents($file, $new) !== false;
    }

    /**
     * A short mark of the files' sizes and times (changes when any of them changes).
     *
     * @param string[] $files
     * @return string
     */
    static function Signature(array $files)
    {
        $s = '';
        foreach ($files as $f) {
            clearstatcache(true, $f);
            $s .= $f . ':' . @filesize($f) . ':' . @filemtime($f) . ';';
        }
        return sha1($s . json_encode(self::Wanted()));
    }
}
