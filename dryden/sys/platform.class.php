<?php

/**
 * Core class: the commands, paths, service names, users and groups of the Linux system the panel
 * runs on.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */

/**
 * Sentora's code names no Linux distribution: whatever differs between them (a path, a command,
 * a service, a user) is a setting in x_settings, which the installer of each distribution sets
 * (sentora2027-ub26.04, sentora2027-deb13, sentora2027-alma10). The values here are the defaults
 * (Ubuntu's and Debian's, which are the same), used until a setting is in the database; every
 * daemon run adds the missing ones, hidden from Sentora Config (so_usereditable_en = 'false':
 * set so_usereditable_en = 'true' to show one there). Commands that root runs stay hidden:
 * change them with setso over SSH.
 *
 * In a value, {v} is a PHP version (8.4), {m} an Apache module name (lua) and {dir} a folder.
 * The shell tools read the same values with: setso --get <name>
 */
class sys_platform
{

    /** @var array<string, array{0: string, 1: string}> name => (default, what it is) */
    static $settings = array(
        // the web server's user and group (Apache, the sites' files, FTP)
        'web_user' => array('www-data', 'The web server\'s Linux user'),
        'web_group' => array('www-data', 'The web server\'s Linux group'),
        'nobody_group' => array('nogroup', 'The group of the user nobody'),
        // Apache
        'apache_ctl' => array('apache2ctl', 'Apache\'s control command (configtest)'),
        'apache_modules_cmd' => array('apache2ctl -M', 'Command that lists Apache\'s loaded modules'),
        'apache_bin' => array('/usr/sbin/apache2', 'Apache\'s program'),
        'apache_server_root' => array('/etc/apache2', 'Apache\'s ServerRoot'),
        'apache_envvars' => array('/etc/apache2/envvars', 'A file Apache\'s environment is read from before it is tested (empty: none)'),
        'apache_module_includes' => array("Include /etc/apache2/mods-enabled/*.load\nInclude /etc/apache2/mods-enabled/*.conf", 'The lines that load Apache\'s modules, for testing a configuration on its own'),
        'apache_enable_module' => array('a2enmod -q {m}', 'Command that switches an Apache module on (empty: the modules are on once installed)'),
        'apache_log_dir' => array('/var/log/apache2', 'Apache\'s own log folder'),
        // the system PHP (the panel's, and the sites' default) and its PHP-FPM
        'php_fpm_pool_dir' => array('/etc/php/{v}/fpm/pool.d/', 'The system PHP-FPM\'s pool folder'),
        'php_fpm_service' => array('php{v}-fpm', 'The system PHP-FPM\'s service'),
        'php_fpm_socket_dir' => array('/run/php/', 'The folder of the system PHP-FPM\'s sockets'),
        'php_fpm_process' => array('php-fpm{v}', 'The system PHP-FPM\'s process name'),
        // the database server
        'db_service' => array('mysql', 'The database server\'s service'),
        'db_conf_file' => array('/etc/mysql/mysql.conf.d/mysqld.cnf', 'The database server\'s settings file (its [mysqld] section)'),
        'db_socket' => array('/var/run/mysqld/mysqld.sock', 'The database server\'s socket'),
        // BIND
        'bind_group' => array('bind', 'BIND\'s Linux group'),
        // mail: Dovecot
        'dovecot_conf' => array('/etc/dovecot/dovecot.conf', 'Dovecot\'s settings file'),
        'dovecot_service' => array('dovecot', 'Dovecot\'s service'),
        'dovecot_spamav_block' => array("sieve_script spamav {\n  type = before\n  driver = file\n  path = {dir}%{user | lower}.sieve\n}", 'Dovecot setting that runs each mailbox\'s spam filter before the user\'s own ({dir}: their folder)'),
        // the programs whose presence says a service is installed
        'spamd_bin' => array('/usr/sbin/spamd', 'SpamAssassin\'s program'),
        'clamd_bin' => array('/usr/sbin/clamd', 'ClamAV\'s program'),
        'sogo_bin' => array('/usr/sbin/sogod', 'SOGo\'s program'),
        // mail: ClamAV
        'clamav_user' => array('clamav', 'ClamAV\'s Linux user'),
        'clamd_conf' => array('/etc/clamav/clamd.conf', 'ClamAV\'s settings file'),
        'clamd_socket' => array('/run/clamav/clamd.ctl', 'ClamAV\'s socket'),
        'clamd_service' => array('clamav-daemon', 'ClamAV\'s service'),
        'clamav_milter_conf' => array('/etc/clamav/clamav-milter.conf', 'ClamAV mail connection\'s settings file'),
        'clamav_milter_pid' => array('/run/clamav/clamav-milter.pid', 'ClamAV mail connection\'s PID file'),
        'clamav_milter_service' => array('clamav-milter', 'ClamAV mail connection\'s service'),
        'freshclam_conf' => array('/etc/clamav/freshclam.conf', 'The virus signature updater\'s settings file'),
        'freshclam_service' => array('clamav-freshclam', 'The virus signature updater\'s service'),
        'freshclam_user' => array('clamav', 'The virus signature updater\'s Linux user (owns the signatures)'),
        'clamav_milter_user' => array('clamav', 'ClamAV mail connection\'s Linux user'),
        // mail: SpamAssassin
        'spamd_user' => array('debian-spamd', 'SpamAssassin\'s Linux user'),
        'spamassassin_dir' => array('/etc/spamassassin', 'SpamAssassin\'s settings folder'),
        'spamd_defaults_file' => array('/etc/default/spamd', 'SpamAssassin\'s start options file'),
        'spamd_defaults_template' => array("OPTIONS=\"{options}\"\nPIDFILE=\"/run/spamd.pid\"", 'That file\'s contents ({options}: the options Sentora sets)'),
        'spamd_service' => array('spamd', 'SpamAssassin\'s service'),
        'spamd_maintenance_timer' => array('spamassassin-maintenance.timer', 'SpamAssassin\'s daily rule update (empty: none)'),
        'spamass_milter_user' => array('spamass-milter', 'SpamAssassin mail connection\'s Linux user'),
        'spamass_milter_defaults_file' => array('/etc/default/spamass-milter', 'SpamAssassin mail connection\'s start options file'),
        'spamass_milter_service' => array('spamass-milter', 'SpamAssassin mail connection\'s service'),
        'spamass_milter_skip_auth_flag' => array('-I', 'Its option that skips mail from logged-in senders (Debian\'s -I, upstream -a)'),
        'spamass_milter_defaults_template' => array("OPTIONS=\"{options}\"\nSOCKET=\"{socket}\"\nSOCKETOWNER=\"{user}:postfix\"\nSOCKETMODE=\"0660\"", 'That file\'s contents ({options}, {socket}, {user})'),
        // SOGo
        'sogo_conf' => array('/etc/sogo/sogo.conf', 'SOGo\'s settings file'),
        'sogo_service' => array('sogo', 'SOGo\'s service'),
        // cron: the folder of users' crontabs
        'crontab_dir' => array('/var/spool/cron/crontabs', 'The folder of the users\' crontabs'),
        'cron_service' => array('cron', 'The cron service (and its process name)'),
        // the package tool, named in messages
        'package_install' => array('apt-get -qq -y install', 'The command that installs a package (the tools run it as root)'),
        'php_ext_package' => array('php{v}-{e}', 'The package of a PHP extension ({v}: PHP version, {e}: the extension)'),
        // the extra PHP versions (Sentora's builds): which bundles fit this system, and their libraries
        // (empty: worked out as before, for servers installed before these settings)
        'php_bundle_tag' => array('', 'The system part of the PHP bundles\' names (sentora-php-<version>-<this>-amd64.tar.gz)'),
        'php_bundle_libs' => array('', 'The packages of the libraries the PHP bundles use'),
    );

    /** @var array<string, string>|null values read from the database once per run */
    private static $cache = null;

    /**
     * Return a setting: its value in x_settings, or its default while it is not there yet.
     *
     * @param string $name A name from self::$settings.
     * @param array<string, string> $fill Placeholders to fill, e.g. array('v' => '8.4').
     * @return string The value.
     */
    static function Get($name, $fill = array())
    {
        if (self::$cache === null) {
            self::$cache = array();
            global $zdbh;
            try {
                if ($zdbh) {
                    $q = $zdbh->prepare("SELECT so_name_vc, so_value_tx FROM x_settings WHERE so_name_vc IN ('" . implode("','", array_keys(self::$settings)) . "')");
                    $q->execute();
                    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        self::$cache[$r['so_name_vc']] = (string) $r['so_value_tx'];
                    }
                }
            } catch (Exception $e) {
                // (no database: the defaults)
            }
        }
        $value = array_key_exists($name, self::$cache) ? self::$cache[$name] : (isset(self::$settings[$name]) ? self::$settings[$name][0] : '');
        foreach ($fill as $k => $v) {
            $value = str_replace('{' . $k . '}', (string) $v, $value);
        }
        return $value;
    }

    /**
     * Add the settings that are not in x_settings yet, with their defaults, hidden from Sentora
     * Config. Run by the daemon (and setso, before it sets one).
     *
     * @return int How many were added.
     */
    static function Ensure()
    {
        global $zdbh;
        $have = array();
        foreach ($zdbh->query("SELECT so_name_vc FROM x_settings WHERE so_name_vc IN ('" . implode("','", array_keys(self::$settings)) . "')")->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $have[$n] = true;
        }
        $add = $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
            VALUES (:n, :c, :v, NULL, :d, 'Sentora Config', 'false')");
        $added = 0;
        foreach (self::$settings as $name => $s) {
            if (!isset($have[$name])) {
                $add->execute(array(':n' => $name, ':c' => ucfirst(str_replace('_', ' ', $name)), ':v' => $s[0], ':d' => $s[1] . ' (set for this Linux distribution by its installer)'));
                $added++;
            }
        }
        self::$cache = null;
        return $added;
    }

}
