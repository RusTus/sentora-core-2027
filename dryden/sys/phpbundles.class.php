<?php

/**
 * Core class: updates of the extra PHP versions (Sentora's own builds in /opt/sentora-php).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-13
 */

/**
 * The extra PHP versions are not updated by the system's packages: a new PHP release needs a new
 * build (GitHub: sentora-installers-2027, Actions > PHP bundles), which goes on the "current" release
 * with php-bundles.json, the list of every build there (system, version, SHA-256).
 *
 * Once a day the daemon compares each installed extra version with that list and with php.net:
 *  - ready:   the release has a newer build: the administrators get a notice, and PHP Versions an
 *             Update button; the daemon then installs it (bin/sentora-php-install: checked against its
 *             SHA-256, tested with this server's settings, the old files back if it does not start)
 *  - waiting: php.net has a newer release, the release has no build of it yet: the notice says so
 *  - current: nothing newer
 * Nothing is ever compiled on the server. With the hidden setting php_bundle_maintainer set (setso
 * --set php_bundle_maintainer 1), the notice also links to the workflow that builds them.
 */
class sys_phpbundles
{

    /** The repository and release the bundles come from (as bin/sentora-php-install). */
    const REPO = 'RusTus/sentora-installers-2027';
    const TAG = 'current';

    /** Settings (hidden): the last check's result (JSON), the Update asked for, and its outcome. */
    const STATE = 'php_bundle_updates';
    const REQUEST = 'php_bundle_update_request';
    const RESULT = 'php_bundle_update_result';

    /** The notice's key. */
    const NOTICE = 'php-bundle-updates';

    /**
     * The system part of the bundles' names (ubuntu26.04, debian13, alma10).
     *
     * @return string
     */
    static function Tag()
    {
        $tag = sys_platform::Get('php_bundle_tag');
        if ($tag !== '') {
            return $tag;
        }
        // a server installed before the setting: worked out as bin/sentora-php-install does
        $os = @parse_ini_file('/etc/os-release') ?: array();
        $id = (string) ($os['ID'] ?? '');
        $ver = (string) ($os['VERSION_ID'] ?? '');
        return $id === 'debian' ? 'debian' . $ver : 'ubuntu' . ($ver !== '' ? $ver : '26.04');
    }

    /**
     * Read a hidden setting of this class ('' when it is not there).
     *
     * @param string $name One of the constants above.
     * @return string
     */
    static function Setting($name)
    {
        $v = ctrl_options::GetSystemOption($name);
        return $v === false ? '' : (string) $v;
    }

    /**
     * Write a hidden setting of this class (made when it is not there).
     *
     * @param string $name One of the constants above.
     * @param string $value The value.
     * @return void
     */
    static function Save($name, $value)
    {
        global $zdbh;
        if ((int) $zdbh->query("SELECT COUNT(*) FROM x_settings WHERE so_name_vc = " . $zdbh->quote($name))->fetchColumn() === 0) {
            $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
                VALUES (:n, :c, '', NULL, 'Extra PHP versions: updates (written by the panel and the daemon).', 'PHP Versions', 'false')")
                ->execute(array(':n' => $name, ':c' => ucfirst(str_replace('_', ' ', $name))));
        }
        $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = :n")->execute(array(':v' => $value, ':n' => $name));
    }

    /**
     * The last check: array('checked' => time, 'versions' => array(mm => array('installed', 'built',
     * 'latest', 'state')), 'error' => text).
     *
     * @return array<string, mixed>
     */
    static function State()
    {
        $s = json_decode(self::Setting(self::STATE), true);
        return is_array($s) ? $s : array('checked' => 0, 'versions' => array(), 'error' => '');
    }

    /**
     * The exact version of an installed extra PHP, e.g. '8.4.27' ('' when it does not run).
     *
     * @param string $mm For example '8.4'.
     * @return string
     */
    static function Installed($mm)
    {
        $cfg = sys_phpversions::BASE_DIR . $mm . '/bin/php-config';
        if (!is_file($cfg)) {
            return '';
        }
        $v = trim((string) @shell_exec(escapeshellarg($cfg) . ' --version 2>/dev/null'));
        return preg_match('/^\d+\.\d+\.\d+$/', $v) ? $v : '';
    }

    /**
     * Fetch a small text over HTTPS (at most 1 MB, 20 seconds).
     *
     * @param string $url The address.
     * @return string|false The text, or false.
     */
    static function Fetch($url)
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        $c = curl_init($url);
        curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Sentora-2027 PHP update check', CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $total, $got) { return $got > 1048576 ? 1 : 0; }));
        $body = curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        return ($body !== false && $code === 200) ? (string) $body : false;
    }

    /**
     * Check every installed extra version, save the result, and send or take away the notice.
     *
     * @return string What happened, for the daemon's log.
     */
    static function Check()
    {
        $installed = sys_phpversions::Installed();
        $state = array('checked' => time(), 'versions' => array(), 'error' => '');
        if (!$installed) {
            self::Save(self::STATE, json_encode($state));
            ctrl_notices::Withdraw(self::NOTICE, 0);
            return 'No extra PHP versions installed.';
        }
        $tag = self::Tag();
        $list = json_decode((string) self::Fetch('https://github.com/' . self::REPO . '/releases/download/' . self::TAG . '/php-bundles.json'), true);
        $built = (is_array($list) && isset($list['bundles'][$tag]) && is_array($list['bundles'][$tag])) ? $list['bundles'][$tag] : null;
        if ($built === null) {
            $state['error'] = 'The list of builds (php-bundles.json) could not be read from GitHub.';
        }
        foreach ($installed as $mm) {
            $have = self::Installed($mm);
            $b = ($built !== null && isset($built[$mm]['version']) && preg_match('/^\d+\.\d+\.\d+$/', (string) $built[$mm]['version'])) ? (string) $built[$mm]['version'] : '';
            // php.net's newest release of the branch (none for versions it no longer lists)
            $latest = '';
            $rel = json_decode((string) self::Fetch('https://www.php.net/releases/index.php?json&max=1&version=' . rawurlencode($mm)), true);
            if (is_array($rel)) {
                foreach (array_keys($rel) as $k) {
                    if (preg_match('/^' . preg_quote($mm, '/') . '\.\d+$/', (string) $k) && ($latest === '' || version_compare($k, $latest, '>'))) {
                        $latest = (string) $k;
                    }
                }
            }
            // the same version rebuilt (an extension added, such as Snuffleupagus): another build than the
            // one installed (sentora-php-install notes its SHA-256), or, for a build installed before that
            // note existed, a release build with Snuffleupagus where this one has none
            $rebuilt = false;
            if ($have !== '' && $b !== '' && $b === $have && $built !== null) {
                $sum = isset($built[$mm]['sha256']) ? (string) $built[$mm]['sha256'] : '';
                $mine = trim((string) @file_get_contents(sys_phpversions::BASE_DIR . $mm . '/BUNDLE-SHA256'));
                if (preg_match('/^[0-9a-f]{64}$/', $sum) && preg_match('/^[0-9a-f]{64}$/', $mine)) {
                    $rebuilt = $sum !== $mine;
                } elseif (!empty($built[$mm]['snuffleupagus'])) {
                    $rebuilt = !glob(sys_phpversions::BASE_DIR . $mm . '/lib/php/extensions/*/snuffleupagus.so');
                }
            }
            if ($have !== '' && $b !== '' && (version_compare($b, $have, '>') || $rebuilt)) {
                $st = 'ready';
            } elseif ($have !== '' && $latest !== '' && version_compare($latest, $have, '>') && ($b === '' || version_compare($latest, $b, '>'))) {
                $st = 'waiting';
            } else {
                $st = 'current';
            }
            $state['versions'][$mm] = array('installed' => $have, 'built' => $b, 'latest' => $latest, 'state' => $st, 'rebuilt' => $rebuilt);
        }
        self::Save(self::STATE, json_encode($state));
        return self::Notice($state);
    }

    /**
     * Send the administrators' notice for the last check, or take it away when nothing is pending.
     *
     * @param array<string, mixed> $state The check (see State()).
     * @return string What happened, for the daemon's log.
     */
    static function Notice(array $state)
    {
        $ready = array();
        $waiting = array();
        foreach ((array) $state['versions'] as $mm => $v) {
            if ($v['state'] === 'ready') {
                $ready[] = !empty($v['rebuilt']) && $v['built'] === $v['installed']
                    ? '- A new build of PHP **' . $v['built'] . '** is ready (the same version, built again: for example with Snuffleupagus for Web Hardening)'
                    : '- PHP **' . $v['built'] . '** is ready (this server runs ' . $v['installed'] . ')';
            } elseif ($v['state'] === 'waiting') {
                $waiting[] = '- PHP **' . $v['latest'] . '** is out (this server runs ' . $v['installed'] . '); its Sentora build is not ready yet';
            }
        }
        if (!$ready && !$waiting) {
            $n = ctrl_notices::Withdraw(self::NOTICE, 0);
            return 'Extra PHP versions: all current' . ($n ? '; took the notice away.' : '.');
        }
        $body = 'New PHP releases usually carry security fixes.' . "\n" . implode("\n", array_merge($ready, $waiting));
        if ($ready) {
            $body .= "\n" . '[Update in PHP Versions](./?module=php_versions): the sites on that version pause for a few seconds.';
        }
        if ($waiting) {
            $body .= "\n" . 'A version that is not ready yet is offered here as soon as its build is published.';
            if (self::Setting('php_bundle_maintainer') !== '') {
                $body .= ' [Build it on GitHub](https://github.com/' . self::REPO . '/actions/workflows/php-bundles.yml) (system ' . self::Tag() . ').';
            }
        }
        $id = ctrl_notices::Send(array('scope' => 'groups', 'targets' => array(1), 'type' => 'info',
            'title' => $ready ? 'PHP updates are ready' : 'New PHP releases', 'body' => $body, 'closable' => 'login',
            'key' => self::NOTICE, 'source' => 'php_versions'));
        return 'Extra PHP versions: ' . count($ready) . ' update(s) ready, ' . count($waiting) . ' not built yet'
            . ($id ? '; the administrators have a notice.' : '; the notice was refused: ' . ctrl_notices::$error);
    }

    /**
     * Ask the daemon to install the ready build of a version (from PHP Versions).
     *
     * @param string $mm For example '8.4'.
     * @return bool False when that version has no ready update.
     */
    static function Request($mm)
    {
        $state = self::State();
        if (!preg_match('/^\d+\.\d+$/', (string) $mm) || ($state['versions'][$mm]['state'] ?? '') !== 'ready') {
            return false;
        }
        self::Save(self::REQUEST, $mm);
        return true;
    }

    /**
     * Daemon (root): install an update asked for in PHP Versions, and check again once a day.
     *
     * @return string What happened, for the daemon's log ('' when nothing).
     */
    static function DaemonRun()
    {
        $out = '';
        $mm = self::Setting(self::REQUEST);
        if ($mm !== '') {
            self::Save(self::REQUEST, '');
            if (preg_match('/^\d+\.\d+$/', $mm) && in_array($mm, sys_phpversions::Installed(), true) && function_exists('posix_geteuid') && posix_geteuid() === 0) {
                $bin = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/bin/sentora-php-install';
                $lines = array();
                exec('/bin/bash ' . escapeshellarg($bin) . ' ' . escapeshellarg($mm) . ' --yes 2>&1', $lines, $rc);
                $last = trim((string) end($lines));
                self::Save(self::RESULT, json_encode(array('version' => $mm, 'ok' => $rc === 0, 'text' => mb_substr($last, 0, 300), 'time' => time())));
                $out .= 'PHP ' . $mm . ' update: ' . ($rc === 0 ? 'done' : 'FAILED') . ' (' . $last . ')' . fs_filehandler::NewLine();
                // the notice and the page show the new state at once
                $out .= self::Check();
                return $out;
            }
            $out .= 'PHP update request for ' . $mm . ' ignored (not an installed version).' . fs_filehandler::NewLine();
        }
        $state = self::State();
        if ((int) $state['checked'] < time() - 86400) {
            $out .= self::Check();
        }
        return $out;
    }

}
