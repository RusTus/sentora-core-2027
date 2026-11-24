<?php

/**
 * Websites on Windows (the panel's side of the Windows stack, sentora-windows-2027): the daemon (SYSTEM) writes one
 * Apache vhost per domain, subdomain and parked domain into its own file (the 'apache_vhost' setting; httpd.conf
 * includes it after the administrator's httpd-vhosts.conf, so the panel stays the default site), makes each domain's
 * folder with its error pages and welcome page (Apache's account may not write in hostdata), tests Apache's settings and
 * asks SentoraZsudo for a gentle restart; a refused file is put back as it was. Each site's PHP is its account's own
 * (phpaccount.ps1: every folder in hostdata\NAME runs NAME's PHP), so a vhost here names no PHP at all.
 * Not built yet on Windows: HTTPS per domain, quotas' pages, custom vhost entries, port forwarding (Linux: apache_admin).
 * Nothing here runs on Linux.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
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
        if (strtolower((string) ctrl_options::GetSystemOption('apache_changed')) !== 'true' && is_file($file)) {
            return $out;
        }
        $made = self::Folders();
        if ($made) {
            $out[] = 'Websites: ' . $made . ' folder(s) made';
        }
        $text = self::Build();
        $old = is_file($file) ? (string) @file_get_contents($file) : null;
        if ($old !== $text) {
            if (@file_put_contents($file . '.new', $text) === false || !@rename($file . '.new', $file)) {
                $out[] = 'Websites: ' . $file . ' could not be written';
                return $out;
            }
            $test = self::Test();
            if ($test !== '') {
                // (Apache would not start with it: the file as it was, and the panel asks again at the next change)
                if ($old === null) {
                    @unlink($file);
                } else {
                    @file_put_contents($file, $old);
                }
                $out[] = 'Websites: Apache refused the new sites, the old ones are kept: ' . $test;
                self::Done();
                return $out;
            }
            $out[] = 'Websites: ' . substr_count($text, '<VirtualHost ') . ' site(s) written, Apache restarts: ' . self::Restart();
        }
        self::Done();
        return $out;
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
        $allowDisabled = strtolower((string) ctrl_options::GetSystemOption('apache_allow_disabled')) === 'true';
        $t = '# Made by the Sentora panel (sys_winvhosts) from its domains: changes here are written over at the next change.' . $nl
            . '# Your own sites go in httpd-vhosts.conf.' . $nl . $nl;
        $q = $zdbh->query("SELECT v.vh_id_pk, v.vh_name_vc, v.vh_directory_vc, v.vh_type_in, v.vh_enabled_in, a.ac_id_pk, a.ac_user_vc
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
            $t .= '# ' . $name . ' (' . $user . ')' . $nl
                . '<VirtualHost *:' . $port . '>' . $nl
                . '    ServerName ' . $name . $nl
                . ((int) $v['vh_type_in'] === 2 ? '' : '    ServerAlias www.' . $name . $nl)
                . '    DocumentRoot "' . rtrim($root, '/') . '"' . $nl
                . '    ErrorLog "' . $logs . 'domains/' . $user . '/' . $name . '-error.log"' . $nl
                . '    CustomLog "' . $logs . 'domains/' . $user . '/' . $name . '-access.log" combined' . $nl
                . self::ErrorPages($hosted . $user . '/public_html' . $dir, $enabled && (int) $v['vh_type_in'] !== 3)
                . '</VirtualHost>' . $nl . $nl;
            $active->execute(array(':id' => (int) $v['vh_id_pk']));
        }
        return $t;
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
     * The panel's request is done.
     *
     * @return void
     */
    static function Done()
    {
        global $zdbh;
        $zdbh->exec("UPDATE x_settings SET so_value_tx = 'false' WHERE so_name_vc = 'apache_changed'");
    }
}
