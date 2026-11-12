<?php

/**
 * Core class: the PHP extensions of each PHP version (PHP Versions > Extensions).
 *
 * The daemon (root) lists what each PHP has (Inventory: built in, add-ons it carries switched on or
 * off, and for the system PHP the extensions its package manager can add) into a file the panel
 * reads. An administrator switches an add-on on or off and gives it settings of its own
 * ("apcu.shm_size = 64M"); the daemon writes them into that PHP's own .ini folder, tries PHP with
 * them (php-fpm -t and the extension really loads), reloads it, and puts the old files back when
 * PHP refuses them. Nothing is compiled on a server (owner's rule): an add-on a build does not carry
 * comes with a later build, and the system PHP's come from the system's own packages.
 *
 * A change applies to the whole version: every site that runs on it (and the panel, for the system
 * PHP). Some extensions are never offered because they would undo the fences between accounts
 * (Catalog(): 'never').
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-12
 */
class sys_phpext
{
    /** What each PHP has, written by the daemon for the panel. */
    const INVENTORY = '/var/lib/sentora/php-extensions.json';

    /** The administrator's choices (x_settings). */
    const SETTING = 'php_extensions_wanted';

    /** The prefix of Sentora's own .ini file for an extension in a PHP's scan folder. */
    const PREFIX = '40-sentora-';

    /**
     * What each known extension is for: (what it does, when to switch it on, careful), and 'never'
     * for those that are not offered (with why).
     *
     * @return array<string, array<int|string, string>>
     */
    static function Catalog()
    {
        return array(
            'apcu' => array('A fast store in memory for a site\'s own data (object cache).', 'WordPress, Nextcloud and many frameworks use it as a cache when it is there.', 'Each PHP process of an account shares it with that account only; give it memory with apc.shm_size (for example 64M). (Its settings start with apc., not apcu.)'),
            'redis' => array('Talks to a Redis server (a cache or a session store).', 'Caching plugins (Redis Object Cache), Nextcloud, Laravel queues.', 'Needs a Redis server to talk to; on its own it does nothing. Some builds of it need igbinary (and msgpack) switched on too: if it is refused, switch those on with it.'),
            'memcached' => array('Talks to a Memcached server (a cache).', 'Older caching plugins and frameworks.', 'Needs a Memcached server to talk to. Some builds need igbinary or msgpack switched on with it.'),
            'imagick' => array('Image work with ImageMagick: resize, crop, PDFs, many formats.', 'WordPress uses it for better thumbnails; galleries and shops.', 'Large images use a lot of memory; keep memory_limit in mind.'),
            'opcache' => array('Keeps compiled PHP scripts in memory, so pages run much faster.', 'Always on.', 'Sentora sets its size and keeps one account\'s cached scripts from another (validate_permission).'),
            'intl' => array('Languages, dates, numbers and money in each country\'s format.', 'Shops, multilingual sites, most frameworks.', 'Harmless.'),
            'soap' => array('Talks to older web services (SOAP).', 'Shipping, payment and accounting connectors.', 'Harmless.'),
            'sodium' => array('Modern encryption.', 'Used by WordPress and most frameworks for passwords and signatures.', 'Harmless.'),
            'gd' => array('Basic image work (thumbnails, captchas).', 'Almost every CMS.', 'Harmless.'),
            'zip' => array('Reads and writes ZIP files.', 'Plugin and theme installs, backups, exports.', 'Harmless.'),
            'curl' => array('Lets PHP fetch web addresses (APIs, updates).', 'Almost every site.', 'Harmless.'),
            'mbstring' => array('Text in every language (multibyte characters).', 'Almost every site.', 'Harmless.'),
            'mysqli' => array('MySQL databases.', 'WordPress and most PHP apps.', 'Harmless.'),
            'pdo_mysql' => array('MySQL databases (PDO).', 'Laravel, Symfony and most frameworks.', 'Harmless.'),
            'pdo_sqlite' => array('SQLite databases in a file (PDO).', 'Small apps and tests.', 'Harmless.'),
            'sqlite3' => array('SQLite databases in a file.', 'Small apps.', 'Harmless.'),
            'bcmath' => array('Exact arithmetic with long numbers.', 'Shops and accounting (money).', 'Harmless.'),
            'gmp' => array('Arithmetic with very long numbers.', 'Cryptography and some payment libraries.', 'Harmless.'),
            'exif' => array('Reads the details stored in photos.', 'Galleries and WordPress (photo rotation).', 'Harmless.'),
            'ldap' => array('Logs people in against Active Directory or another directory.', 'Snipe-IT, Nextcloud and intranets.', 'Harmless.'),
            'imap' => array('Reads mailboxes over IMAP.', 'Help desks (osTicket) that read a mailbox.', 'Left PHP in 8.4; only older versions carry it.'),
            'xsl' => array('Turns XML into other documents (XSLT).', 'Some publishing and accounting tools.', 'Harmless.'),
            'ftp' => array('Lets PHP use FTP servers.', 'Backup and deploy plugins.', 'Harmless.'),
            'sockets' => array('Low-level network connections.', 'Some chat, queue and monitoring tools.', 'Harmless; the firewall still decides what may connect.'),
            'calendar' => array('Calendar conversions.', 'Some booking and calendar apps.', 'Harmless.'),
            'gettext' => array('Translations of a site\'s texts.', 'Multilingual apps.', 'Harmless.'),
            'igbinary' => array('A smaller, faster way to store PHP data.', 'With redis or memcached, when a plugin asks for it.', 'Harmless.'),
            'msgpack' => array('A compact data format.', 'When a plugin asks for it.', 'Harmless.'),
            'tidy' => array('Cleans up HTML.', 'Some editors and importers.', 'Harmless.'),
            'mongodb' => array('Talks to a MongoDB database.', 'Apps built on MongoDB.', 'Needs a MongoDB server.'),
            'yaml' => array('Reads and writes YAML files.', 'Some frameworks and tools.', 'Harmless.'),
            'mailparse' => array('Reads e-mail messages.', 'Help desks and mailing tools.', 'Harmless.'),
            'pgsql' => array('PostgreSQL databases.', 'Apps that use PostgreSQL.', 'Needs a PostgreSQL server.'),
            'pdo_pgsql' => array('PostgreSQL databases (PDO).', 'Frameworks with PostgreSQL.', 'Needs a PostgreSQL server.'),
            'bz2' => array('Reads and writes .bz2 files.', 'Backup and import tools.', 'Harmless.'),
            'shmop' => array('Shared memory between processes.', 'Rarely needed.', 'Only shared within one account\'s PHP processes.'),
            'sysvsem' => array('Locks between processes.', 'Some queue and cache libraries.', 'Harmless.'),
            'sysvshm' => array('Shared memory between processes.', 'Rarely needed.', 'Harmless.'),
            'sysvmsg' => array('Messages between processes.', 'Rarely needed.', 'Harmless.'),
            'snuffleupagus' => array('PHP\'s own guard (Web Hardening).', 'Switched on and off in Web Hardening, not here.', ''),
            'ffi' => array('never' => 'Lets PHP call any program library directly: a site could step around open_basedir and every other fence between accounts. Not offered on a shared server.'),
            'xdebug' => array('never' => 'A debugger: it slows every site on this PHP a lot and can show a site\'s inner details to visitors. Use it on a development server, not here.'),
            'pcntl' => array('never' => 'Lets a web page start processes of its own outside PHP-FPM\'s limits. Only for command-line PHP; not offered for websites.'),
            'dl_test' => array('never' => 'A test extension of PHP itself; not useful on a server.'),
            'zend_test' => array('never' => 'A test extension of PHP itself; not useful on a server.'),
        );
    }

    /**
     * Extensions worth adding to the system PHP from its packages, when it does not have them.
     *
     * @return string[]
     */
    static function Addable()
    {
        return array('apcu', 'redis', 'memcached', 'imagick', 'igbinary', 'msgpack', 'intl', 'soap', 'gmp', 'bcmath', 'ldap', 'xsl', 'tidy', 'mongodb', 'yaml', 'mailparse', 'pgsql');
    }

    /**
     * Is an extension never offered?
     *
     * @param string $ext
     * @return string Why ('' when it may be offered).
     */
    static function Never($ext)
    {
        $c = self::Catalog();
        return isset($c[$ext]['never']) ? $c[$ext]['never'] : '';
    }

    // ----- the administrator's choices -----

    /**
     * The choices: version => extension => array('on' => bool, 'ini' => settings text).
     *
     * @global db_driver $zdbh
     * @return array<string, array<string, array{on: bool, ini: string}>>
     */
    static function Wanted()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT so_value_tx FROM x_settings WHERE so_name_vc = :n");
        $q->execute(array(':n' => self::SETTING));
        $w = json_decode((string) $q->fetchColumn(), true);
        return is_array($w) ? $w : array();
    }

    /**
     * Store the choices (the row is made on a server installed before this setting).
     *
     * @global db_driver $zdbh
     * @param array $w See Wanted().
     * @return void
     */
    static function SaveWanted(array $w)
    {
        global $zdbh;
        $json = json_encode($w, JSON_UNESCAPED_SLASHES);
        $q = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = :n");
        $q->execute(array(':v' => $json, ':n' => self::SETTING));
        if ($q->rowCount() === 0 && $zdbh->query("SELECT COUNT(*) FROM x_settings WHERE so_name_vc = '" . self::SETTING . "'")->fetchColumn() == 0) {
            $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en) VALUES (:n, 'PHP extensions', :v, '', 'The PHP extensions switched on in PHP Versions, and their settings', 'PHP Versions', 'false')")
                ->execute(array(':n' => self::SETTING, ':v' => $json));
        }
    }

    /**
     * The name prefixes of an extension's settings (most use their own name; APCu uses apc.).
     *
     * @param string $ext
     * @return string[]
     */
    static function Prefixes($ext)
    {
        $more = array('apcu' => array('apc.'));
        return array_merge(array($ext . '.'), isset($more[$ext]) ? $more[$ext] : array());
    }

    /**
     * Read an extension's settings: "name = value" lines whose names start with "<extension>.".
     * Paths and anything that names a file are refused (a setting must never point PHP at a file
     * to run or load, or at a folder shared between accounts).
     *
     * @param string $ext
     * @param string $text
     * @return array{0: array<string, string>, 1: string[]} (name => value, what is wrong)
     */
    static function ParseSettings($ext, $text)
    {
        $out = array();
        $bad = array();
        foreach (preg_split('/\r?\n/', (string) $text) as $n => $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            if (!preg_match('/^([a-z0-9_.]+)\s*=\s*(.*)$/i', $line, $m)) {
                $bad[] = sprintf('Line %d: write it as name = value.', $n + 1);
                continue;
            }
            $name = strtolower($m[1]);
            $value = trim($m[2], " \t\"'");
            $mine = false;
            foreach (self::Prefixes($ext) as $pre) {
                $mine = $mine || strpos($name, $pre) === 0;
            }
            if (!$mine) {
                $bad[] = sprintf('Line %d: only settings of %s (starting with %s) go here.', $n + 1, $ext, implode(' or ', self::Prefixes($ext)));
                continue;
            }
            if (preg_match('/(preload|file_cache|restrict_api|lockfile|error_log|log_file|_path$|_dir$|_file$|mmap_file_mask|socket)/', $name)) {
                $bad[] = sprintf('Line %d: %s names a file or folder; it can not be set here.', $n + 1, $name);
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9 _.:,@+-]{0,120}$/', $value)) {
                $bad[] = sprintf('Line %d: %s does not take that value (letters, digits and . : , - + only).', $n + 1, $name);
                continue;
            }
            $out[$name] = $value;
        }
        if (count($out) > 30) {
            $bad[] = 'At most 30 settings for one extension.';
        }
        return array($out, $bad);
    }

    // ----- what each PHP has (the daemon writes it, the panel reads it) -----

    /**
     * The inventory the daemon wrote.
     *
     * @return array<string, array<string, mixed>>
     */
    static function Inventory()
    {
        $i = json_decode((string) @file_get_contents(self::INVENTORY), true);
        return is_array($i) ? $i : array();
    }

    /**
     * Run a command (as root, by the daemon) and give back its exit code and output.
     *
     * @param string $cmd
     * @return array{0: int, 1: string}
     */
    static function Run($cmd)
    {
        $out = array();
        exec(ctrl_system::OwnFiles($cmd . ' 2>&1'), $out, $rc);
        return array($rc, implode("\n", $out));
    }

    /**
     * What one PHP has: its scan folder, extension folder, loaded modules and the add-ons in it.
     *
     * @param string $v
     * @param string $fpm
     * @return array<string, mixed>
     */
    static function Look($v, $fpm)
    {
        list(, $info) = self::Run(escapeshellarg($fpm) . ' -i');
        list(, $mods) = self::Run(escapeshellarg($fpm) . ' -m');
        $r = array('scan' => '', 'extdir' => '', 'loaded' => array(), 'files' => array(), 'by' => array());
        if (preg_match('/^Scan this dir for additional \.ini files => (.+)$/m', $info, $m) && trim($m[1]) !== '(none)') {
            $r['scan'] = rtrim(trim($m[1]), '/');
        }
        if (preg_match('/^extension_dir => (\S+)/m', $info, $m)) {
            $r['extdir'] = rtrim(trim($m[1]), '/');
        }
        foreach (preg_split('/\r?\n/', $mods) as $l) {
            $l = strtolower(trim($l));
            if ($l !== '' && $l[0] !== '[') {
                $r['loaded'][$l === 'zend opcache' ? 'opcache' : $l] = true;
            }
        }
        $r['loaded'] = array_keys($r['loaded']);
        foreach ((array) glob($r['extdir'] . '/*.so') as $so) {
            $r['files'][] = basename($so, '.so');
        }
        // which .ini file loads each add-on (Sentora's own, or the system's package)
        foreach ((array) glob($r['scan'] . '/*.ini') as $ini) {
            foreach (preg_split('/\r?\n/', (string) @file_get_contents($ini)) as $l) {
                if (preg_match('/^\s*(?:zend_)?extension\s*=\s*"?([^"\s;]+?)(?:\.so)?"?\s*$/', $l, $m)) {
                    $r['by'][basename($m[1])] = basename($ini);
                }
            }
        }
        return $r;
    }

    /**
     * The file Sentora writes for an extension in a PHP's scan folder.
     *
     * @param string $scan
     * @param string $ext
     * @param string $v
     * @return string
     */
    static function OwnFile($scan, $ext, $v)
    {
        // (the extra versions' own builds switch their add-ons on in 30-<name>.ini: that file is theirs,
        // and Sentora's, so it is the one changed)
        if ($v !== sys_phpversions::DefaultVersion() && is_file($scan . '/30-' . $ext . '.ini')) {
            return $scan . '/30-' . $ext . '.ini';
        }
        return $scan . '/' . self::PREFIX . $ext . '.ini';
    }

    /**
     * Is this .ini file one Sentora may change for the extension?
     *
     * @param string $file
     * @param string $ext
     * @param string $v
     * @return bool
     */
    static function IsOwn($file, $ext, $v)
    {
        return $file === self::PREFIX . $ext . '.ini' || ($v !== sys_phpversions::DefaultVersion() && $file === '30-' . $ext . '.ini');
    }

    /**
     * The daemon's part: list what every PHP has, apply the administrator's choices, write the
     * inventory for the panel.
     *
     * @return string[] Lines for the daemon's report.
     */
    static function DaemonRun()
    {
        $report = array();
        $wanted = self::Wanted();
        $old = self::Inventory();
        // nothing asked since the last look, and that look is less than an hour old: nothing to do
        $sig = sha1(json_encode($wanted) . '|' . implode(',', sys_phpversions::Installed()));
        $first = $old ? reset($old) : array();
        if (isset($first['sig'], $first['checked']) && $first['sig'] === $sig && (int) $first['checked'] > time() - 3600) {
            return array();
        }
        $inv = array();
        $default = sys_phpversions::DefaultVersion();
        foreach (array_unique(array_merge(array($default), sys_phpversions::Installed())) as $v) {
            $fpm = sys_domainphp::FpmBinary($v);
            if ($fpm === '') {
                continue;
            }
            $look = self::Look($v, $fpm);
            if ($look['scan'] === '' || !is_dir($look['scan'])) {
                continue;
            }
            $results = isset($old[$v]['results']) && is_array($old[$v]['results']) ? $old[$v]['results'] : array();
            $changed = false;
            // (helpers other add-ons are built with come first: redis and memcached may need igbinary or msgpack)
            $list = isset($wanted[$v]) && is_array($wanted[$v]) ? $wanted[$v] : array();
            uksort($list, function ($a, $b) {
                $first = array('igbinary' => 0, 'msgpack' => 1, 'apcu' => 2);
                return (isset($first[$a]) ? $first[$a] : 9) - (isset($first[$b]) ? $first[$b] : 9) ?: strcmp($a, $b);
            });
            foreach ($list as $ext => $want) {
                if (!preg_match('/^[a-z0-9_]{1,40}$/', (string) $ext) || self::Never($ext) !== '' || $ext === 'snuffleupagus') {
                    continue;
                }
                $on = !empty($want['on']);
                list($set) = self::ParseSettings($ext, isset($want['ini']) ? (string) $want['ini'] : '');
                $own = self::OwnFile($look['scan'], $ext, $v);
                $by = isset($look['by'][$ext]) ? $look['by'][$ext] : '';
                $has = in_array($ext, $look['files'], true);
                // the system PHP: an extension it does not have comes from its package, once
                if ($on && !$has && !in_array($ext, $look['loaded'], true)) {
                    if ($v === $default && in_array($ext, self::Addable(), true)
                        && (empty($results[$ext]['tried']) || (int) ($want['asked'] ?? 0) > (int) ($results[$ext]['time'] ?? 0))) {
                        $results[$ext] = self::InstallPackage($v, $ext);
                        $report[] = 'PHP ' . $v . ': ' . $ext . ' ' . $results[$ext]['text'];
                        $look = self::Look($v, $fpm);
                        $has = in_array($ext, $look['files'], true);
                        $by = isset($look['by'][$ext]) ? $look['by'][$ext] : '';
                        $changed = $changed || $results[$ext]['ok'];
                    }
                    if (!$has) {
                        continue;
                    }
                }
                // loaded by the system's own package file: Sentora adds only the settings
                $loadsIt = !($by !== '' && !self::IsOwn($by, $ext, $v));
                $text = '; Written by Sentora (PHP Versions > Extensions): changes here are overwritten.' . "\n";
                if ($on) {
                    if ($loadsIt) {
                        $text .= ($ext === 'opcache' ? 'zend_extension' : 'extension') . '=' . $ext . '.so' . "\n";
                    }
                    foreach ($set as $k => $val) {
                        $text .= $k . ' = ' . $val . "\n";
                    }
                }
                $current = is_file($own) ? (string) file_get_contents($own) : null;
                $wantFile = ($on && ($loadsIt || $set)) ? $text : null;
                // off: Sentora's file goes (a system package's own file stays: its package decides)
                if ($wantFile === $current || (!$on && $current === null)) {
                    continue;
                }
                if ($wantFile === null) {
                    @unlink($own);
                } else {
                    file_put_contents($own, $wantFile);
                    @chmod($own, 0644);
                }
                // PHP must accept it and, when on, really load it; otherwise the old file comes back
                list($rc, $out) = self::Run(escapeshellarg($fpm) . ' -t');
                list(, $mods) = self::Run(escapeshellarg($fpm) . ' -m');
                $loaded = in_array($ext, array_map(function ($l) { $l = strtolower(trim($l)); return $l === 'zend opcache' ? 'opcache' : $l; }, preg_split('/\r?\n/', $mods)), true);
                if ($rc !== 0 || ($on && !$loaded) || (!$on && $loaded && $loadsIt)) {
                    if ($current === null) {
                        @unlink($own);
                    } else {
                        file_put_contents($own, $current);
                    }
                    $why = trim((string) preg_replace('/\s+/', ' ', $out));
                    $results[$ext] = array('ok' => false, 'time' => time(), 'text' => 'not changed: PHP did not accept it' . ($why !== '' ? ' (' . substr($why, 0, 200) . ')' : ''));
                    $report[] = 'PHP ' . $v . ': ' . $ext . ' ' . $results[$ext]['text'];
                    continue;
                }
                $results[$ext] = array('ok' => true, 'time' => time(), 'text' => $on ? ($loadsIt ? 'switched on' : 'settings written') : 'switched off');
                $report[] = 'PHP ' . $v . ': ' . $ext . ' ' . $results[$ext]['text'];
                $changed = true;
            }
            if ($changed) {
                $service = sys_accountphp::ServiceName($v);
                list($rc) = self::Run('systemctl reload ' . escapeshellarg($service));
                if ($rc !== 0) {
                    self::Run('systemctl restart ' . escapeshellarg($service));
                }
                $look = self::Look($v, $fpm);
            }
            // what the page shows
            $addable = array();
            if ($v === $default) {
                foreach (self::Addable() as $e) {
                    if (!in_array($e, $look['files'], true) && !in_array($e, $look['loaded'], true)) {
                        $addable[] = $e;
                    }
                }
            }
            $inv[$v] = array(
                'checked' => time(),
                'loaded' => $look['loaded'],
                'files' => $look['files'],
                'by' => $look['by'],
                'addable' => $addable,
                'results' => $results,
                'system' => $v === $default,
                'sig' => $sig,
            );
        }
        @mkdir(dirname(self::INVENTORY), 0755, true);
        $tmp = self::INVENTORY . '.new';
        if (file_put_contents($tmp, json_encode($inv, JSON_UNESCAPED_SLASHES)) !== false) {
            @chmod($tmp, 0644);
            rename($tmp, self::INVENTORY);
        }
        return $report;
    }

    /**
     * Add an extension to the system PHP from the system's package (settings package_install and
     * php_pecl_package / php_ext_package, set by each installer).
     *
     * @param string $v
     * @param string $ext
     * @return array{ok: bool, tried: bool, time: int, text: string}
     */
    static function InstallPackage($v, $ext)
    {
        $install = trim((string) sys_platform::Get('package_install'));
        $names = array();
        foreach (array('php_pecl_package', 'php_ext_package') as $s) {
            $t = trim((string) sys_platform::Get($s));
            if ($t !== '') {
                $names[] = str_replace(array('{v}', '{e}'), array($v, $ext), $t);
            }
        }
        $names = array_values(array_unique(array_filter($names, function ($n) { return preg_match('/^[A-Za-z0-9._+-]{1,80}$/', $n); })));
        if ($install === '' || !$names) {
            return array('ok' => false, 'tried' => true, 'time' => time(), 'text' => 'could not be added: this system\'s package names are not known');
        }
        foreach ($names as $pkg) {
            list($rc) = self::Run($install . ' ' . escapeshellarg($pkg));
            if ($rc === 0) {
                return array('ok' => true, 'tried' => true, 'time' => time(), 'text' => 'added from the system\'s package ' . $pkg);
            }
        }
        return array('ok' => false, 'tried' => true, 'time' => time(), 'text' => 'could not be added: the system has no package for it (' . implode(', ', $names) . ')');
    }
}
