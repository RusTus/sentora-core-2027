<?php

/**
 * Administrators' own Apache settings (Apache Admin): a domain's "Extra directives" and SSL
 * settings, and those for every website or for the panel. They go into Apache's configuration as
 * written, so one bad line must not take Apache, and every website with it, down:
 *
 * - Filter(): lines that would let them run a program as root, load outside code, use mod_php or
 *   switch FollowSymLinks on (sys_symlinks) are kept but commented out; so is SSLEngine outside the
 *   SSL settings (Apache refuses to start on it without a certificate, and its config test does
 *   not notice).
 * - Test(): Apache's own syntax check of an entry in a scratch configuration, when it is saved.
 * - CheckSsl(): what Apache's config test does not catch but makes it refuse to start: SSL switched
 *   on without a certificate, a certificate or key that is not there, a key that does not fit it.
 * - Block(), Culprit(), SwitchOff(): the daemon marks each entry in the file it writes; when Apache
 *   still refuses the file, the line Apache names leads to the entry, which is switched off (kept
 *   in the database) so everything else goes live. Problems() lists them for Apache Admin.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
class sys_apachecustom
{
    /** The setting holding the entries switched off, and the last error (JSON). */
    const SETTING = 'apache_custom_problems';

    /** The marker lines around each entry in the vhosts file. */
    const BEGIN = '# BEGIN SENTORA CUSTOM ';
    const END = '# END SENTORA CUSTOM ';

    /**
     * An entry as it goes into Apache's configuration.
     *
     * @param string $text The entry.
     * @param bool $symlinks True for a domain with "Allow FollowSymLinks" on.
     * @param bool $ssl True for a domain's SSL settings (SSLEngine belongs there).
     * @return string
     */
    static function Filter($text, $symlinks = false, $ssl = false)
    {
        // mod_php lines would stop Apache now that PHP runs in PHP-FPM (a .user.ini takes PHP settings)
        $text = preg_replace('/^(\s*)(php_(?:admin_)?(?:value|flag)\b.*)$/mi', '$1# Not used with PHP-FPM (use .user.ini): $2', (string) $text);
        // what would run as root (Apache's main process starts piped loggers and rewrite-map
        // programs as root) or load outside code
        $danger = '/^(\s*)((?:Include|IncludeOptional|LoadModule|LoadFile|RewriteMap|PidFile|ServerRoot|CoreDumpDirectory|ScriptSock|Mutex|Define|UnDefine|User|Group|Listen|SSLPassPhraseDialog|SSLCryptoDevice|GlobalLog|ScoreBoardFile|DefaultRuntimeDir)\b.*'
            . '|(?:CustomLog|ErrorLog|TransferLog|ForensicLog|CookieLog|RewriteLog|ScriptLog)\s+["\']?\s*(?:\||syslog:|exec:).*'
            . '|(?:CustomLog|TransferLog)\s+.*["\']?\s*\|.*)$/mi';
        $text = preg_replace($danger, '$1# Not allowed in custom entries (would run as root): $2', $text);
        if (!$ssl) {
            $text = preg_replace('/^(\s*)(SSLEngine\b.*)$/mi', '$1# Not allowed here (SSL is set in the domain\'s SSL settings): $2', $text);
        }
        if (!$symlinks) {
            $text = sys_symlinks::Filter($text);
        }
        return $text;
    }

    /**
     * The entry for the daemon's vhosts file, between markers (so the line an Apache error
     * names leads back to it), or a note when it is switched off.
     *
     * @param string $key 'vh:<id>:custom', 'vh:<id>:ssl', 'global_vhcustom' or 'global_zpcustom'.
     * @param string $text The entry.
     * @param bool $symlinks See Filter().
     * @param bool $ssl See Filter().
     * @return string
     */
    static function Block($key, $text, $symlinks = false, $ssl = false)
    {
        if (trim((string) $text) === '') {
            return '';
        }
        if (self::IsOff($key)) {
            return '# Sentora: these settings are switched off: Apache refused them (Apache Admin shows why)' . "\n";
        }
        return self::BEGIN . $key . "\n" . self::Filter($text, $symlinks, $ssl) . "\n" . self::END . $key . "\n";
    }

    /**
     * Apache's own syntax check of an entry (Apache Admin, when it is saved): the entry in a
     * scratch configuration with this server's modules, checked with "apache2 -t" as the panel's
     * own user. SSL settings go in a port-443 site.
     *
     * @param string $text The entry.
     * @param bool $ssl True for SSL settings.
     * @param bool $symlinks True for a domain with "Allow FollowSymLinks" on.
     * @return string '' when Apache takes it (or the check can not run here), else Apache's message.
     */
    static function Test($text, $ssl = false, $symlinks = false)
    {
        if (trim((string) $text) === '' || !is_executable('/usr/sbin/apache2') || !is_dir('/etc/apache2/mods-enabled')) {
            return '';
        }
        $dir = sys_get_temp_dir() . '/apachecheck-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700)) {
            return '';
        }
        $conf = "ServerRoot /etc/apache2\nInclude /etc/apache2/mods-enabled/*.load\nInclude /etc/apache2/mods-enabled/*.conf\n"
            . "PidFile $dir/pid\nErrorLog $dir/error.log\nServerName sentora-check.invalid\n"
            . '<VirtualHost *:' . ($ssl ? '443' : '80') . ">\nServerName sentora-check.invalid\nDocumentRoot $dir\n"
            . "#--- the entry\n" . self::Filter($text, $symlinks, $ssl) . "\n#--- end\n</VirtualHost>\n";
        file_put_contents("$dir/check.conf", $conf);
        // (the modules' settings use Apache's environment: /etc/apache2/envvars)
        exec('/bin/sh -c ' . escapeshellarg('. /etc/apache2/envvars 2> /dev/null; exec /usr/sbin/apache2 -t -f ' . $dir . '/check.conf') . ' 2>&1', $out, $rc);
        @unlink("$dir/check.conf");
        @unlink("$dir/error.log");
        @rmdir($dir);
        if ($rc === 0) {
            return $ssl ? self::CheckSsl($text, false) : '';
        }
        $msg = trim(preg_replace('/\s+/', ' ', implode(' ', $out)));
        // the line in the entry, not in the scratch file
        if (preg_match('/on line (\d+) of [^:]*check\.conf/', $msg, $m)) {
            $first = substr_count(substr($conf, 0, strpos($conf, "#--- the entry\n")), "\n") + 2;
            $n = (int) $m[1] - $first + 1;
            $msg = preg_replace('/Syntax error on line \d+ of \S*check\.conf:\s*/', $n >= 1 ? 'Line ' . $n . ': ' : '', $msg);
        }
        return $msg !== '' ? $msg : 'Apache refused it.';
    }

    /**
     * What Apache's config test does not catch in SSL settings, but makes it refuse to start (and
     * a reload stops the running Apache): SSL switched on without a certificate, a certificate or
     * key file that is not there, and a key that does not belong to the certificate.
     *
     * @param string $text The SSL settings.
     * @param bool $readKeys True where the key can be read (the daemon, as root): checks the pair.
     * @return string '' when fine, else the reason (English).
     */
    static function CheckSsl($text, $readKeys = true)
    {
        $live = array();
        foreach (preg_split('/\r?\n/', (string) $text) as $l) {
            $l = trim($l);
            if ($l !== '' && $l[0] !== '#') {
                $live[] = $l;
            }
        }
        $get = function ($name) use ($live) {
            foreach ($live as $l) {
                if (preg_match('/^' . $name . '\s+"?([^"\s]+)"?/i', $l, $m)) {
                    return $m[1];
                }
            }
            return '';
        };
        $on = false;
        foreach ($live as $l) {
            if (preg_match('/^SSLEngine\s+on\b/i', $l)) {
                $on = true;
            }
        }
        if (!$on) {
            return '';
        }
        $cert = $get('SSLCertificateFile');
        $key = $get('SSLCertificateKeyFile');
        if ($cert === '') {
            return 'SSL is switched on (SSLEngine on) without a certificate (SSLCertificateFile).';
        }
        if (!is_file($cert)) {
            return 'The certificate file is not there: ' . $cert;
        }
        if ($key !== '' && !is_file($key)) {
            return 'The key file is not there: ' . $key;
        }
        if ($readKeys && function_exists('openssl_x509_check_private_key')) {
            $c = @file_get_contents($cert);
            $k = @file_get_contents($key !== '' ? $key : $cert);
            // a key behind a passphrase: Apache would ask for it on the console, and with nobody
            // there it does not start
            if ($k !== false && preg_match('/-----BEGIN (ENCRYPTED PRIVATE KEY|[A-Z ]*PRIVATE KEY)-----\s*(Proc-Type: 4,ENCRYPTED)?/', $k, $mm)
                && ($mm[1] === 'ENCRYPTED PRIVATE KEY' || !empty($mm[2]))) {
                return 'The key is protected by a passphrase, which Apache can not ask for (' . ($key !== '' ? $key : $cert) . ').';
            }
            if ($c !== false && $k !== false && @openssl_x509_read($c) && @openssl_pkey_get_private($k) && !@openssl_x509_check_private_key($c, $k)) {
                return 'The key does not belong to the certificate (' . ($key !== '' ? $key : $cert) . ').';
            }
        }
        return '';
    }

    /**
     * The entry an Apache config test failure comes from: the line Apache names, in the marked
     * blocks of the file it checked. A line just after a block (an unclosed section shows up
     * there) counts for the last block of the same site.
     *
     * @param string $config The vhosts file that failed.
     * @param string $output Apache's output.
     * @param string $file The vhosts file's path. Apache names every file of the include chain
     *                     ("line 228 of apache2.conf: ... line 42 of httpd-vhosts.conf"): the line in this one counts.
     * @return string|null The entry's key, or null when it is not in an entry.
     */
    static function Culprit($config, $output, $file = '')
    {
        if (!preg_match_all('/on line (\d+) of ([^:\s]+)/', $output, $all, PREG_SET_ORDER)) {
            return null;
        }
        $n = 0;
        foreach ($all as $m) {
            if ($file === '' || $m[2] === $file || basename($m[2]) === basename($file)) {
                $n = (int) $m[1];
            }
        }
        if ($n === 0) {
            return null;   // the error is in another file than the vhosts file
        }
        $open = null;
        $lastClosed = null;
        foreach (preg_split('/\r?\n/', $config) as $i => $l) {
            if ($i + 1 > $n) {
                break;
            }
            if (strpos($l, self::BEGIN) === 0) {
                $open = trim(substr($l, strlen(self::BEGIN)));
            } elseif (strpos($l, self::END) === 0) {
                $lastClosed = $open;
                $open = null;
            } elseif (strpos($l, '# DOMAIN: ') === 0 || strpos($l, '# END PANEL DOMAIN') === 0) {
                $lastClosed = null;
            }
        }
        return $open !== null ? $open : $lastClosed;
    }

    /**
     * The entries switched off, and the last problem Apache had ('apache').
     *
     * @return array<string, array{name:string, error:string, time:int}>
     */
    static function Problems()
    {
        $v = json_decode((string) ctrl_options::GetSystemOption(self::SETTING), true);
        return is_array($v) ? $v : array();
    }

    /**
     * @param string $key
     * @return bool True when that entry is switched off.
     */
    static function IsOff($key)
    {
        return $key !== 'apache' && isset(self::Problems()[$key]);
    }

    /**
     * Record a problem: an entry switched off, or ('apache') what Apache said.
     *
     * @param string $key The entry, or 'apache'.
     * @param string $name What it is (a domain name, or a description).
     * @param string $error Apache's message.
     * @return void
     */
    static function SwitchOff($key, $name, $error)
    {
        $p = self::Problems();
        $p[$key] = array('name' => (string) $name, 'error' => substr(trim((string) $error), 0, 600), 'time' => time());
        self::Save($p);
    }

    /**
     * Forget a problem (the entry was saved again, or Apache took the configuration).
     *
     * @param string $key
     * @return void
     */
    static function Clear($key)
    {
        $p = self::Problems();
        if (isset($p[$key])) {
            unset($p[$key]);
            self::Save($p);
        }
    }

    /**
     * @param array<string, array> $p
     * @return void
     */
    private static function Save(array $p)
    {
        global $zdbh;
        if ((int) $zdbh->query("SELECT COUNT(*) FROM x_settings WHERE so_name_vc = '" . self::SETTING . "'")->fetchColumn() === 0) {
            $zdbh->exec("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_desc_tx, so_module_vc, so_usereditable_en)
                VALUES ('" . self::SETTING . "', 'Apache settings switched off', '', 'Written by the daemon, shown in Apache Admin.', 'Apache Config', 'false')");
        }
        $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = :n")->execute(array(':v' => $p ? json_encode($p) : '', ':n' => self::SETTING));
    }

    /**
     * What an entry is, for messages: the domain and which field.
     *
     * @param string $key
     * @return string E.g. "example.com: Extra directives".
     */
    static function Describe($key)
    {
        global $zdbh;
        if ($key === 'global_vhcustom') {
            return 'Extra directives for every website';
        }
        if ($key === 'global_zpcustom') {
            return 'Extra directives for the panel';
        }
        if (preg_match('/^vh:(\d+):(custom|ssl)$/', $key, $m)) {
            $q = $zdbh->prepare("SELECT vh_name_vc FROM x_vhosts WHERE vh_id_pk = :i");
            $q->execute(array(':i' => (int) $m[1]));
            return (string) $q->fetchColumn() . ': ' . ($m[2] === 'ssl' ? 'SSL settings' : 'Extra directives');
        }
        return $key;
    }
}
