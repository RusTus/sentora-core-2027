<?php

/**
 * Websites on Windows (the panel's side of the Windows stack, sentora-windows-2027): the daemon (SYSTEM) writes one
 * Apache vhost per domain, subdomain and parked domain into its own file (the 'apache_vhost' setting; httpd.conf
 * includes it after the administrator's httpd-vhosts.conf, so the panel stays the default site), makes each domain's
 * folder with its error pages and welcome page (Apache's account may not write in hostdata), tests Apache's settings and
 * asks SentoraZsudo for a gentle restart; a refused file is put back as it was. Each site's PHP is its account's own
 * (phpaccount.ps1: every folder in hostdata\NAME runs NAME's PHP), so a vhost here names no PHP at all.
 * HTTPS: a domain with a certificate from Sencrypt (its vh_ssl_tx names the files) gets an HTTPS site on the port of
 * configs/apache/ssl.conf, and its plain-HTTP site sends visitors there; the files are checked (in the account's own
 * certificate folder, readable, the key belongs to the certificate) and only their paths are written, never the stored
 * text. Certificates live in the panel's state folder (CertHome(): Apache's account writes them, the accounts' own PHP
 * can not read them), Let's Encrypt's check files in one folder for every site (AcmeDir()).
 * Not built yet on Windows: quotas' pages, custom vhost entries, port forwarding (Linux: apache_admin).
 * Nothing here runs on Linux.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-25
 */
class sys_winvhosts
{
    /**
     * The daemon's part: when the panel asked (apache_changed) or the file is missing, write, test and restart.
     *
     * @return string[] Lines for the daemon's log.
     */
    static function Run()
    {
        global $zdbh;
        $out = array();
        if (!sys_versions::IsWindows()) {
            return $out;
        }
        $file = (string) ctrl_options::GetSystemOption('apache_vhost');
        if ($file === '' || basename($file) === 'httpd-vhosts.conf') {
            // (the administrator's own file is never written; an older install named it here)
            return array('Websites: the apache_vhost setting names no file of the panel\'s own');
        }
        if (strtolower((string) ctrl_options::GetSystemOption('apache_changed')) !== 'true' && is_file($file) && is_file(dirname($file) . '/sentora-fallback.conf')) {
            return $out;
        }
        // (the request is taken now, before the domains are read: a domain saved while this run works asks again, and the
        // next run brings it; cleared at the end, it was lost)
        self::Done();
        $made = self::Folders();
        if ($made) {
            $out[] = 'Websites: ' . $made . ' folder(s) made';
        }
        // "Site not found falls back to panel login" (panel_fallback): its own file, read before the other sites
        $fbFile = dirname($file) . '/sentora-fallback.conf';
        $fbText = self::Fallback();
        $fbOld = is_file($fbFile) ? (string) @file_get_contents($fbFile) : null;
        if ($fbOld !== $fbText) {
            @file_put_contents($fbFile, $fbText);
            $test = self::Test();
            if ($test !== '') {
                if ($fbOld === null) {
                    @unlink($fbFile);
                } else {
                    @file_put_contents($fbFile, $fbOld);
                }
                $out[] = 'Websites: Apache refused the "site not found" setting, the old one is kept: ' . $test;
            } else {
                $out[] = 'Websites: names no site claims now ' . (self::FallbackOn() ? 'open the panel' : 'get Not Found') . ', Apache restarts: ' . self::Restart();
            }
        }
        $text = self::Build();
        $old = is_file($file) ? (string) @file_get_contents($file) : null;
        if ($old !== $text) {
            if (@file_put_contents($file . '.new', $text) === false || !@rename($file . '.new', $file)) {
                $out[] = 'Websites: ' . $file . ' could not be written';
                self::Done('true');
                return $out;
            }
            $test = self::Test();
            // an administrator's entry Apache refuses is switched off (kept in the database, Apache Config says why), and
            // the rest goes live; at most a few times
            for ($try = 0; $test !== '' && $try < 5; $try++) {
                $culprit = sys_apachecustom::Culprit($text, $test, $file);
                if ($culprit === null || sys_apachecustom::IsOff($culprit)) {
                    break;
                }
                sys_apachecustom::SwitchOff($culprit, isset(self::$names[$culprit]) ? self::$names[$culprit] : $culprit, $test);
                $out[] = 'Websites: Apache refused ' . (isset(self::$names[$culprit]) ? self::$names[$culprit] : $culprit) . '\'s extra directives: switched off (' . $test . ')';
                $text = self::Build();
                @file_put_contents($file, $text);
                $test = self::Test();
            }
            if ($test !== '') {
                // (Apache would not start with it: the file as it was, and the panel asks again at the next change)
                if ($old === null) {
                    @unlink($file);
                } else {
                    @file_put_contents($file, $old);
                }
                sys_apachecustom::SwitchOff('apache', 'Apache', $test);
                $out[] = 'Websites: Apache refused the new sites, the old ones are kept: ' . $test;
                return $out;
            }
            sys_apachecustom::Clear('apache');
            $out[] = 'Websites: ' . substr_count($text, '<VirtualHost ') . ' site(s) written, Apache restarts: ' . self::Restart();
            sys_phpversions::RecordLive($text);
        }
        return $out;
    }

    /**
     * Whether a name no site claims opens the panel (panel_fallback: on unless set to an off value, as on Linux).
     *
     * @return bool
     */
    static function FallbackOn()
    {
        $v = strtolower(trim((string) ctrl_options::GetSystemOption('panel_fallback')));
        return !in_array($v, array('false', '0', 'no', 'off'), true);
    }

    /**
     * The file read before every other site (configs/apache/sentora-fallback.conf): empty while names no site claims
     * open the panel (the first site in httpd-vhosts.conf); else the first site on each port, which answers those names
     * with Not Found, but still opens the panel on this server's own addresses and localhost while panel_ip_access is on.
     *
     * @return string
     */
    static function Fallback()
    {
        $nl = "\r\n";
        $t = '# Made by the Sentora panel (sys_winvhosts) from "Site not found falls back to panel login" (Sentora Config).' . $nl;
        if (self::FallbackOn()) {
            return $t . '# On: names no site claims open the panel (the first site in httpd-vhosts.conf).' . $nl;
        }
        $v = strtolower(trim((string) ctrl_options::GetSystemOption('panel_ip_access')));
        $ipOk = !in_array($v, array('false', '0', 'no', 'off'), true);
        $panel = rtrim(self::Fwd(ctrl_options::GetSystemOption('sentora_root')), '/');
        $port = (int) ctrl_options::GetSystemOption('apache_port');
        $port = $port > 0 ? $port : 80;
        $body = '    ServerName sentora-not-found.invalid' . $nl . '    DocumentRoot "' . $panel . '"' . $nl . '    RewriteEngine On' . $nl
            . ($ipOk ? '    RewriteCond %{HTTP_HOST} !^(\[[0-9a-fA-F:]+\]|[0-9.]+|localhost)(:[0-9]+)?$ [NC]' . $nl : '')
            . '    RewriteRule ^ - [R=404,L]' . $nl;
        $t .= '# Off: names no site claims get Not Found' . ($ipOk ? ' (this server\'s addresses and localhost still open the panel)' : '') . '.' . $nl
            . '<VirtualHost *:' . $port . '>' . $nl . $body . '</VirtualHost>' . $nl;
        $ssl = dirname(dirname((string) ctrl_options::GetSystemOption('apache_vhost'))) . '/ssl/';
        if (is_file($ssl . 'server.crt') && is_file($ssl . 'server.key')) {
            $t .= '<VirtualHost *:' . self::HttpsPort() . '>' . $nl . $body . '    SSLEngine on' . $nl
                . '    SSLCertificateFile "' . str_replace('\\', '/', $ssl) . 'server.crt"' . $nl . '    SSLCertificateKeyFile "' . str_replace('\\', '/', $ssl) . 'server.key"' . $nl
                . '</VirtualHost>' . $nl;
        }
        return $t;
    }

    /**
     * The vhosts file.
     *
     * @return string
     */
    static function Build()
    {
        global $zdbh;
        $nl = "\r\n";
        $hosted = self::Fwd(ctrl_options::GetSystemOption('hosted_dir'));
        $static = self::Fwd(ctrl_options::GetSystemOption('static_dir'));
        $parking = self::Fwd(ctrl_options::GetSystemOption('parking_path'));
        $logs = self::Fwd(ctrl_options::GetSystemOption('log_dir'));
        $port = (int) ctrl_options::GetSystemOption('apache_port');
        $port = $port > 0 ? $port : 80;
        $https = self::HttpsPort();
        $acme = self::AcmeDir();
        $allowDisabled = strtolower((string) ctrl_options::GetSystemOption('apache_allow_disabled')) === 'true';
        // Apache Config's settings: start pages, log format, and the administrator's lines for every website
        $index = trim((string) ctrl_options::GetSystemOption('dir_index'));
        $index = preg_match('/^DirectoryIndex( [A-Za-z0-9._-]+)+$/', $index) ? '    ' . $index . $nl : '';
        $format = (string) ctrl_options::GetSystemOption('access_log_format') === 'common' ? 'common' : 'combined';
        $every = self::Custom('global_vhcustom', (string) ctrl_options::GetSystemOption('global_vhcustom'), $nl);
        self::$names = array('global_vhcustom' => 'For every website');
        $t = '# Made by the Sentora panel (sys_winvhosts) from its domains: changes here are written over at the next change.' . $nl
            . '# Your own sites go in httpd-vhosts.conf.' . $nl . $nl
            . '# Let\'s Encrypt\'s check files, for every site (written by Sencrypt; plain files only)' . $nl
            . 'Alias /.well-known/acme-challenge/ "' . $acme . '.well-known/acme-challenge/"' . $nl
            . '<Directory "' . $acme . '.well-known/acme-challenge">' . $nl
            . '    Options None' . $nl . '    AllowOverride None' . $nl . '    Require all granted' . $nl . '    ForceType text/plain' . $nl
            . '    <IfModule php_module>' . $nl . '        php_admin_flag engine Off' . $nl . '    </IfModule>' . $nl
            . '</Directory>' . $nl . $nl;
        $phpOn = sys_phpversions::Enabled();
        $phpConf = dirname((string) ctrl_options::GetSystemOption('apache_vhost')) . '/php-accounts/';
        $q = $zdbh->query("SELECT v.vh_id_pk, v.vh_name_vc, v.vh_directory_vc, v.vh_type_in, v.vh_enabled_in, v.vh_ssl_tx, v.vh_custom_tx, v.vh_php_version_vc, a.ac_id_pk, a.ac_user_vc
            FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk
            WHERE v.vh_deleted_ts IS NULL AND a.ac_deleted_ts IS NULL ORDER BY v.vh_name_vc");
        $active = $zdbh->prepare("UPDATE x_vhosts SET vh_active_in = 1 WHERE vh_id_pk = :id");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $name = strtolower((string) $v['vh_name_vc']);
            $user = sys_winphp::Name($v['ac_user_vc']);
            if (!self::IsDomain($name) || $user === '') {
                continue;
            }
            $dir = self::Folder($v['vh_directory_vc']);
            if ($dir === null) {
                continue;
            }
            $enabled = (int) $v['vh_enabled_in'] === 1 && ($allowDisabled || ctrl_users::CheckUserEnabled($v['ac_id_pk']));
            if (!$enabled) {
                $root = $static . 'disabled';
            } elseif ((int) $v['vh_type_in'] === 3) {
                $root = rtrim($parking, '/');
            } else {
                $root = $hosted . $user . '/public_html' . $dir;
            }
            $names = '    ServerName ' . $name . $nl . ((int) $v['vh_type_in'] === 2 ? '' : '    ServerAlias www.' . $name . $nl);
            $key = 'vh:' . (int) $v['vh_id_pk'] . ':custom';
            self::$names[$key] = $name;
            // (another PHP version chosen on the Domains page: the account's pool for it, once phpaccount.ps1 has made it)
            $pv = (string) $v['vh_php_version_vc'];
            $pool = 'php' . str_replace('.', '', $pv) . '-' . $user;
            $phpLine = '';
            if ($enabled && (int) $v['vh_type_in'] !== 3 && in_array($pv, $phpOn, true) && strpos((string) @file_get_contents($phpConf . $user . '.conf'), 'balancer://' . $pool . '"') !== false) {
                $phpLine = '    <Directory "' . rtrim($root, '/') . '">' . $nl . '        <FilesMatch "\.php$">' . $nl . '            SetHandler "proxy:balancer://' . $pool . '/"' . $nl
                    . '        </FilesMatch>' . $nl . '    </Directory>' . $nl;
            }
            $body = '    DocumentRoot "' . rtrim($root, '/') . '"' . $nl . $index . $phpLine
                . '    ErrorLog "' . $logs . 'domains/' . $user . '/' . $name . '-error.log"' . $nl
                . '    CustomLog "' . $logs . 'domains/' . $user . '/' . $name . '-access.log" ' . $format . $nl
                . self::ErrorPages($hosted . $user . '/public_html' . $dir, $enabled && (int) $v['vh_type_in'] !== 3)
                . ($enabled ? $every . self::Custom($key, (string) $v['vh_custom_tx'], $nl) : '');
            $cert = self::Cert($v['vh_ssl_tx'], $user);
            if ($cert === null) {
                $t .= '# ' . $name . ' (' . $user . ')' . $nl . '<VirtualHost *:' . $port . '>' . $nl . $names . $body . '</VirtualHost>' . $nl . $nl;
            } else {
                // (plain HTTP sends visitors to HTTPS, except Let's Encrypt's check files; the fingerprint line makes a
                // renewed certificate a change, so Apache restarts and takes it)
                $t .= '# ' . $name . ' (' . $user . '), HTTPS: ' . $cert['sum'] . $nl
                    . '<VirtualHost *:' . $port . '>' . $nl . $names
                    . '    RewriteEngine On' . $nl
                    . '    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/' . $nl
                    . '    RewriteRule ^/?(.*) https://%{SERVER_NAME}' . ($https === 443 ? '' : ':' . $https) . '/$1 [R=301,L]' . $nl
                    . '    ErrorLog "' . $logs . 'domains/' . $user . '/' . $name . '-error.log"' . $nl
                    . '    CustomLog "' . $logs . 'domains/' . $user . '/' . $name . '-access.log" ' . $format . $nl
                    . '</VirtualHost>' . $nl
                    . '<VirtualHost *:' . $https . '>' . $nl . $names . $body
                    . '    SSLEngine on' . $nl
                    . '    SSLCertificateFile "' . $cert['cert'] . '"' . $nl
                    . '    SSLCertificateKeyFile "' . $cert['key'] . '"' . $nl
                    . ($cert['chain'] !== '' ? '    SSLCertificateChainFile "' . $cert['chain'] . '"' . $nl : '')
                    . '</VirtualHost>' . $nl . $nl;
            }
            $active->execute(array(':id' => (int) $v['vh_id_pk']));
        }
        return $t . self::Branding($port, $https, $logs, $format, $nl);
    }

    /**
     * Resellers' own panel addresses (Branding: panel.theirhosting.com), as on Linux: plain HTTP answers Let's Encrypt's
     * check (the folder every site serves) and sends the rest to HTTPS, or says "being set up" until the address has
     * its certificate; with it, HTTPS runs the panel as its main address does (its folder and PHP). Between
     * "# BRANDING: host" lines, which Branding's daemon part reads.
     *
     * @param int $port Apache's plain-HTTP port.
     * @param int $https Its HTTPS port.
     * @param string $logs The log folder (forward slashes, with a slash at the end).
     * @param string $format The access log format.
     * @param string $nl The line end.
     * @return string
     */
    static function Branding($port, $https, $logs, $format, $nl)
    {
        if (!class_exists('ctrl_branding')) {
            return '';
        }
        try {
            $addresses = ctrl_branding::Addresses();
        } catch (Exception $e) {
            return '';
        }
        if (!$addresses) {
            return '';
        }
        $panel = rtrim(self::Fwd(ctrl_options::GetSystemOption('sentora_root')), '/');
        $t = '# Panel addresses of resellers (Branding)' . $nl;
        foreach ($addresses as $a) {
            $host = strtolower((string) $a['host']);
            if (!self::IsDomain($host)) {
                continue;
            }
            $cert = str_replace('\\', '/', (string) $a['cert']);
            $key = str_replace('\\', '/', (string) $a['key']);
            $t .= '# BRANDING: ' . $host . $nl
                . '<VirtualHost *:' . $port . '>' . $nl
                . '    ServerName ' . $host . $nl
                . '    DocumentRoot "' . $panel . '"' . $nl
                . '    ErrorLog "' . $logs . 'apache/panel-error.log"' . $nl
                . '    RewriteEngine On' . $nl
                . '    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/[A-Za-z0-9_-]+$' . $nl
                . ($cert !== '' ? '    RewriteRule ^/?(.*) https://' . $host . ($https === 443 ? '' : ':' . $https) . '/$1 [R=301,L]' . $nl
                    : '    RewriteRule ^ - [R=503,L]' . $nl . '    ErrorDocument 503 "This panel address is being set up: it works within a few minutes, once its certificate is ready."' . $nl)
                . '</VirtualHost>' . $nl;
            if ($cert !== '') {
                $t .= '<VirtualHost *:' . $https . '>' . $nl
                    . '    ServerName ' . $host . $nl
                    . '    DocumentRoot "' . $panel . '"' . $nl
                    . '    ErrorLog "' . $logs . 'apache/panel-error.log"' . $nl
                    . '    CustomLog "' . $logs . 'apache/panel-access.log" ' . $format . $nl
                    . '    SSLEngine on' . $nl
                    . '    SSLCertificateFile "' . $cert . '"' . $nl
                    . '    SSLCertificateKeyFile "' . $key . '"' . $nl
                    . '</VirtualHost>' . $nl;
            }
            $t .= '# END BRANDING: ' . $host . $nl . $nl;
        }
        return $t;
    }

    /** @var array<string, string> What each custom entry in the last Build() is (for a switched-off entry's note). */
    private static $names = array();

    /**
     * An administrator's entry (Apache Config) for the file, between sys_apachecustom's markers so a line Apache refuses
     * leads back to it; FollowSymLinks never (a junction would lead into another account's folder).
     *
     * @param string $key 'global_vhcustom' or 'vh:<id>:custom'.
     * @param string $text The entry.
     * @param string $nl The line end.
     * @return string
     */
    static function Custom($key, $text, $nl)
    {
        $b = sys_apachecustom::Block($key, $text, false, false);
        return $b === '' ? '' : str_replace("\n", $nl, str_replace("\r\n", "\n", $b));
    }

    /**
     * Each live domain's folder, its error pages and its welcome page, and the folder for its logs (as SYSTEM: the
     * new folders take hostdata's rights, the account's own PHP gets its own when phpaccount.ps1 makes the account).
     *
     * @return int How many folders were made.
     */
    static function Folders()
    {
        global $zdbh;
        $hosted = self::Fwd(ctrl_options::GetSystemOption('hosted_dir'));
        $static = self::Fwd(ctrl_options::GetSystemOption('static_dir'));
        $logs = self::Fwd(ctrl_options::GetSystemOption('log_dir'));
        $made = 0;
        // Let's Encrypt's check files, and each account's certificate folders (in the folder the installer protected:
        // nothing is made when it is not there, so a key never lands in a folder others may read)
        $acme = self::AcmeDir() . '.well-known/acme-challenge';
        if (!is_dir($acme)) {
            @mkdir($acme, 0755, true);
        }
        $certs = self::CertBase();
        if (is_dir($certs)) {
            foreach ($zdbh->query("SELECT ac_user_vc FROM x_accounts WHERE ac_deleted_ts IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $u) {
                $n = sys_winphp::Name($u);
                if ($n !== '') {
                    foreach (array('letsencrypt', 'third_party') as $kind) {
                        if (!is_dir($certs . $n . '/ssl/sencrypt/' . $kind)) {
                            @mkdir($certs . $n . '/ssl/sencrypt/' . $kind, 0700, true);
                        }
                    }
                }
            }
        }
        $q = $zdbh->query("SELECT v.vh_directory_vc, v.vh_type_in, a.ac_user_vc FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk
            WHERE v.vh_deleted_ts IS NULL AND a.ac_deleted_ts IS NULL");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $user = sys_winphp::Name($v['ac_user_vc']);
            $dir = self::Folder($v['vh_directory_vc']);
            if ($user === '' || $dir === null) {
                continue;
            }
            if (!is_dir($logs . 'domains/' . $user)) {
                @mkdir($logs . 'domains/' . $user, 0755, true);
            }
            if ((int) $v['vh_type_in'] === 3) {
                continue;
            }
            $path = $hosted . $user . '/public_html' . $dir;
            if (is_dir($path)) {
                continue;
            }
            if (!@mkdir($path, 0755, true)) {
                continue;
            }
            $made++;
            @mkdir($path . '/_errorpages', 0755, true);
            foreach ((array) @glob($static . 'errorpages/*.html') as $page) {
                if (preg_match('/^[0-9]{3}\.html$/', basename($page))) {
                    @copy($page, $path . '/_errorpages/' . basename($page));
                }
            }
            if (!file_exists($path . '/index.html') && !file_exists($path . '/index.php') && is_file($static . 'pages/welcome.html')) {
                @copy($static . 'pages/welcome.html', $path . '/index.html');
            }
        }
        return $made;
    }

    /**
     * ErrorDocument lines for the pages in a site's _errorpages folder.
     *
     * @param string $path The site's folder.
     * @param bool $use False for no lines.
     * @return string
     */
    static function ErrorPages($path, $use)
    {
        $t = '';
        if (!$use) {
            return $t;
        }
        foreach ((array) @glob($path . '/_errorpages/*.html') as $page) {
            $code = basename($page, '.html');
            if (preg_match('/^(400|401|403|404|500|502|503)$/', $code)) {
                $t .= '    ErrorDocument ' . $code . ' /_errorpages/' . $code . '.html' . "\r\n";
            }
        }
        return $t;
    }

    /**
     * Test Apache's settings with the new file: '' when Apache takes them, else what it said.
     *
     * @return string
     */
    static function Test()
    {
        $httpd = (string) ctrl_options::GetSystemOption('httpd_exe');
        $conf = dirname((string) ctrl_options::GetSystemOption('apache_vhost')) . '/httpd.conf';
        if ($httpd === '' || !is_file($httpd) || !is_file($conf)) {
            return 'httpd_exe or httpd.conf is not there';
        }
        $o = array();
        exec('"' . str_replace('/', '\\', $httpd) . '" -t -f "' . str_replace('/', '\\', $conf) . '" 2>&1', $o, $rc);
        return (int) $rc === 0 ? '' : substr(implode(' ', $o), 0, 400);
    }

    /**
     * Ask SentoraZsudo for Apache's gentle restart.
     *
     * @return string What zsudo said.
     */
    static function Restart()
    {
        $zsudo = (string) ctrl_options::GetSystemOption('zsudo');
        $svc = (string) ctrl_options::GetSystemOption('apache_sn');
        if ($zsudo === '' || !sys_versions::CanRun($zsudo) || !preg_match('/^[A-Za-z0-9-]{1,40}$/', $svc)) {
            return 'no zsudo to ask';
        }
        $o = array();
        exec(escapeshellcmd($zsudo) . ' service ' . $svc . ' graceful 2>&1', $o, $rc);
        return trim(implode(' ', $o));
    }

    /**
     * Where an account's certificates are, as Sencrypt names them (HOME . '/ssl/sencrypt/<kind>/<domain>/'): on Linux
     * the account's own folder; on Windows its folder in the panel's state folder (Apache's account writes it, the
     * accounts' own PHP can not read it).
     *
     * @param string $user The account's user name.
     * @return string HOME, with no slash at the end.
     */
    static function CertHome($user)
    {
        if (!sys_versions::IsWindows()) {
            return ctrl_options::GetSystemOption('hosted_dir') . $user;
        }
        return self::CertBase() . $user;
    }

    /**
     * The folder of every account's certificates on Windows ('C:/zpanel/data/sentora/certs/').
     *
     * @return string
     */
    static function CertBase()
    {
        return self::Fwd(dirname(rtrim(self::Fwd(ctrl_options::GetSystemOption('hosted_dir')), '/'))) . 'data/sentora/certs/';
    }

    /**
     * The folder Let's Encrypt's check files go in on Windows (its .well-known/acme-challenge, for every site).
     *
     * @return string With a slash at the end.
     */
    static function AcmeDir()
    {
        return self::Fwd(dirname(rtrim(self::Fwd(ctrl_options::GetSystemOption('hosted_dir')), '/'))) . 'data/sentora/acme/';
    }

    /**
     * Apache's HTTPS port (the Listen line of configs/apache/ssl.conf, which the installer writes), else 443.
     *
     * @return int
     */
    static function HttpsPort()
    {
        $f = dirname((string) ctrl_options::GetSystemOption('apache_vhost')) . '/ssl.conf';
        $s = is_file($f) ? (string) @file_get_contents($f) : '';
        return preg_match('/^\s*Listen\s+(?:[0-9.]+:)?([0-9]{1,5})\s*$/mi', $s, $m) && (int) $m[1] > 0 && (int) $m[1] < 65536 ? (int) $m[1] : 443;
    }

    /**
     * The options openssl_pkey_new(), openssl_csr_new() and openssl_csr_sign() need: on Windows PHP finds no
     * openssl.cnf of its own, so the one next to PHP is named (extras/ssl/openssl.cnf).
     *
     * @param array<string, mixed> $more Options of the caller's own.
     * @return array<string, mixed>
     */
    static function OpensslArgs($more = array())
    {
        if (sys_versions::IsWindows() && !isset($more['config'])) {
            $cnf = dirname(str_replace('\\', '/', (string) ctrl_options::GetSystemOption('php_exer'))) . '/extras/ssl/openssl.cnf';
            if (is_file($cnf)) {
                $more['config'] = $cnf;
            }
        }
        return $more;
    }

    /**
     * Two paths name the same place (Windows: either slash, any case).
     *
     * @param string|false $a
     * @param string|false $b
     * @return bool
     */
    static function SamePath($a, $b)
    {
        if ($a === false || $b === false) {
            return false;
        }
        $a = rtrim(str_replace('\\', '/', (string) $a), '/');
        $b = rtrim(str_replace('\\', '/', (string) $b), '/');
        return sys_versions::IsWindows() ? strtolower($a) === strtolower($b) : $a === $b;
    }

    /**
     * The certificate files a domain's SSL settings name, when they may be used: in the account's own certificate
     * folder, readable, and the key belongs to the certificate (Apache would not start otherwise). The full chain is
     * used when Let's Encrypt left one next to the certificate.
     *
     * @param string|null $tx The domain's vh_ssl_tx.
     * @param string $user The account's user name.
     * @return array{cert: string, key: string, chain: string, sum: string}|null
     */
    static function Cert($tx, $user)
    {
        $tx = (string) $tx;
        if (trim($tx) === '') {
            return null;
        }
        $f = array();
        foreach (preg_split('/\r\n|\r|\n/', $tx) as $line) {
            if (preg_match('/^\s*(SSLCertificateFile|SSLCertificateKeyFile|SSLCACertificateFile|SSLCertificateChainFile)\s+"?([^"]+?)"?\s*$/i', $line, $m)) {
                $f[strtolower($m[1])] = str_replace('\\', '/', $m[2]);
            }
        }
        $home = str_replace('\\', '/', self::CertHome($user)) . '/ssl/sencrypt/';
        $ok = function ($p) use ($home) {
            return is_string($p) && $p !== '' && strpos($p, '..') === false && preg_match('#^[A-Za-z0-9:/._-]+$#', $p)
                && strncasecmp($p, $home, strlen($home)) === 0 && is_file($p) && is_readable($p);
        };
        $cert = isset($f['sslcertificatefile']) ? $f['sslcertificatefile'] : '';
        $key = isset($f['sslcertificatekeyfile']) ? $f['sslcertificatekeyfile'] : '';
        if (!$ok($cert) || !$ok($key)) {
            return null;
        }
        $cp = (string) @file_get_contents($cert);
        $kp = (string) @file_get_contents($key);
        if ($cp === '' || $kp === '' || !@openssl_x509_check_private_key($cp, $kp)) {
            return null;
        }
        $chain = '';
        $full = dirname($cert) . '/fullchain.pem';
        if (basename($cert) === 'cert.pem' && $ok($full) && @openssl_x509_check_private_key((string) @file_get_contents($full), $kp)) {
            $cert = $full;
        } else {
            foreach (array('sslcertificatechainfile', 'sslcacertificatefile') as $k) {
                if (isset($f[$k]) && $ok($f[$k]) && filesize($f[$k]) > 0 && @openssl_x509_read((string) @file_get_contents($f[$k]))) {
                    $chain = $f[$k];
                    break;
                }
            }
        }
        $sum = substr(hash('sha256', (string) @file_get_contents($cert) . $kp . ($chain !== '' ? (string) @file_get_contents($chain) : '')), 0, 16);
        return array('cert' => $cert, 'key' => $key, 'chain' => $chain, 'sum' => $sum);
    }

    /**
     * A domain name as Sentora takes them.
     *
     * @param string $n
     * @return bool
     */
    static function IsDomain($n)
    {
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/', $n);
    }

    /**
     * A vhost's folder inside public_html ('/shop_example_com'), or null when it is not one.
     *
     * @param string $d
     * @return string|null
     */
    static function Folder($d)
    {
        $d = trim(str_replace('\\', '/', (string) $d), '/');
        if ($d === '') {
            return '';
        }
        foreach (explode('/', $d) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $part)) {
                return null;
            }
        }
        return '/' . $d;
    }

    /**
     * A folder setting with forward slashes and one at the end.
     *
     * @param string $p
     * @return string
     */
    static function Fwd($p)
    {
        return rtrim(str_replace('\\', '/', (string) $p), '/') . '/';
    }

    /**
     * The panel's request is taken ('false'), or asked again ('true': the file could not be written).
     *
     * @param string $value
     * @return void
     */
    static function Done($value = 'false')
    {
        global $zdbh;
        $zdbh->exec("UPDATE x_settings SET so_value_tx = '" . ($value === 'true' ? 'true' : 'false') . "' WHERE so_name_vc = 'apache_changed'");
    }
}
