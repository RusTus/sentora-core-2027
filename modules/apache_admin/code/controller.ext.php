<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *

 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 *
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * Reworked by Anthony D. - Duke City Solutions, LLC
 * @copyright (c) 2008-2019 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
/**
 * Apache Admin module (administrators): the web server's settings in x_settings, as tabs with checked
 * values, and per-domain overrides (switched off, open_basedir, port, IP, SSL, extra directives). Any
 * change sets apache_changed, so the daemon writes the Apache configuration within 5 minutes (and
 * keeps the old one when Apache does not accept the new one).
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True after a domain's overrides were saved. */
    static $ok;

    /** @var bool|null The old page's "show the override form" flag (kept for the old buttons). */
    static $showvhost;

    /** @var string|null The message after a save. */
    static $result = null;

    /** @var array<string, array<string, mixed>>|null The settings after a refused save, with what was typed. */
    static $fields = null;

    /** @var array<string, string> Why a domain's override was refused, by field. */
    static $errors = array();

    /**
     * Escape a value for HTML.
     *
     * @param mixed $s The value (cast to string).
     * @return string The escaped text.
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translate and escape a text.
     *
     * @param string $s The English text.
     * @return string The escaped translation.
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * The settings, their tabs, controls and checks.
     *
     * @return array<string, array<string, mixed>> See ui_settingsform.
     */
    static function Spec()
    {
        $w = 'Web server';
        $p = 'PHP';
        $d = 'Domains and backups';
        $c = 'Extra directives';
        $path = array('pattern' => '#^/[A-Za-z0-9._/-]*/$#', 'pattern_help' => 'A folder path starting and ending with /.');
        // FollowSymLinks is switched on per domain only (its page), never for every website
        $noSymlinks = function ($v) {
            return sys_symlinks::Offending($v) ? 'A line here would switch FollowSymLinks on (Options FollowSymLinks or All, AllowOverride All or Options): not allowed. It can be switched on for one domain only, on that domain\'s page.' : '';
        };
        return array(
            'apache_port' => array('section' => $w, 'label' => 'Port', 'help' => 'The port Apache serves websites on.', 'type' => 'number', 'min' => 1, 'max' => 65535),
            'dir_index' => array('section' => $w, 'label' => 'Start pages', 'help' => 'The files Apache looks for when a folder is asked for, in order.', 'type' => 'text',
                'pattern' => '/^DirectoryIndex( [A-Za-z0-9._-]+)+$/', 'pattern_help' => 'DirectoryIndex, then the file names, such as: DirectoryIndex index.html index.php'),
            'access_log_format' => array('section' => $w, 'label' => 'Access log format', 'help' => 'Combined also logs where visitors came from and their browser.', 'type' => 'select', 'options' => array('combined' => 'Combined', 'common' => 'Common')),
            'bandwidth_log_format' => array('section' => $w, 'label' => 'Bandwidth log format', 'help' => 'The log the panel counts bandwidth from.', 'type' => 'select', 'options' => array('common' => 'Common', 'combined' => 'Combined')),
            'apache_allow_disabled' => array('section' => $w, 'label' => 'Keep switched-off clients\' websites online', 'help' => 'Off: a client that is switched off also has its websites switched off.', 'type' => 'switch'),
            'disable_hostsen' => array('section' => $w, 'label' => 'Leave /etc/hosts alone', 'help' => 'On: domains are not added to the server\'s HOSTS file.', 'type' => 'switch'),
            'use_openbase' => array('section' => $p, 'label' => 'Keep PHP in each account\'s folder', 'help' => 'open_basedir: a website\'s PHP can only open files in its own account. Strongly recommended.', 'type' => 'switch'),
            'openbase_temp' => array('section' => $p, 'label' => 'Temporary folder', 'help' => 'Also open to PHP under open_basedir.', 'type' => 'text') + $path,
            'upload_temp_dir' => array('section' => $p, 'label' => 'Upload folder', 'help' => 'Where uploads wait while they arrive.', 'type' => 'text') + $path,
            'php_pool_children' => array('section' => $p, 'label' => 'PHP processes per account', 'help' => 'The most PHP requests one account can run at once. Each account has its own pool.', 'type' => 'number', 'min' => 1, 'max' => 200),
            'shared_domains' => array('section' => $d, 'label' => 'Shared domains', 'help' => 'Domains several accounts may use sub-domains of (dynamic DNS services), separated by commas.', 'type' => 'text', 'empty' => true,
                'pattern' => '/^[a-z0-9.-]+( *, *[a-z0-9.-]+)*$/i', 'pattern_help' => 'Names separated by commas, such as: no-ip,dyndns'),
            'apache_backup' => array('section' => $d, 'label' => 'Back up the configuration', 'help' => 'Keep a copy of the previous vhost configuration before a new one is written.', 'type' => 'switch'),
            'apache_purgebu' => array('section' => $d, 'label' => 'Delete old copies', 'help' => '', 'type' => 'switch'),
            'apache_purge_date' => array('section' => $d, 'label' => 'Keep copies for', 'help' => '', 'type' => 'number', 'min' => 1, 'max' => 3650, 'unit' => 'days'),
            'global_vhcustom' => array('section' => $c, 'label' => 'For every website', 'help' => 'Apache directives added to every website\'s configuration.', 'type' => 'code', 'check' => $noSymlinks),
            'global_zpcustom' => array('section' => $c, 'label' => 'For the panel', 'help' => 'Apache directives added to the panel\'s own site.', 'type' => 'code', 'check' => $noSymlinks),
        );
    }

    /**
     * Set the apache_changed setting to 'true' so the daemon rewrites the Apache config.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function SetWriteApacheConfigTrue()
    {
        global $zdbh;
        $zdbh->prepare("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc = 'apache_changed'")->execute();
    }

    /**
     * The domains (and sub-domains and parked domains), with their owner and what they override.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>> The x_vhosts rows plus 'owner' and 'overrides' (each an English label, with %s, and the value for it).
     */
    static function Vhosts()
    {
        global $zdbh;
        static $rows = null;
        if ($rows === null) {
            sys_symlinks::EnsureColumn();
            $rows = $zdbh->query("SELECT v.*, a.ac_user_vc AS owner FROM x_vhosts v LEFT JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk WHERE v.vh_deleted_ts IS NULL ORDER BY v.vh_name_vc")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $o = array();
                if ((int) $r['vh_enabled_in'] === 0) {
                    $o[] = array('Switched off', '');
                }
                if ((int) $r['vh_obasedir_in'] === 0) {
                    $o[] = array('open_basedir off', '');
                }
                if (sys_symlinks::Allowed($r)) {
                    $o[] = array('FollowSymLinks on', '');
                }
                if ((string) $r['vh_custom_port_in'] !== '' && $r['vh_custom_port_in'] !== null) {
                    $o[] = array('Port %s', (string) (int) $r['vh_custom_port_in']);
                }
                if ((int) $r['vh_portforward_in'] === 1) {
                    $o[] = array('Port 80 forwarded', '');
                }
                if ((string) $r['vh_custom_ip_vc'] !== '') {
                    $o[] = array('IP %s', (string) $r['vh_custom_ip_vc']);
                }
                if ((string) $r['vh_ssl_tx'] !== '' || (string) $r['vh_ssl_port_in'] !== '') {
                    $o[] = array('SSL', '');
                }
                if (trim((string) $r['vh_custom_tx']) !== '') {
                    $o[] = array('Extra directives', '');
                }
                $r['overrides'] = $o;
            }
            unset($r);
        }
        return $rows;
    }

    /**
     * One domain by ID.
     *
     * @param mixed $id The x_vhosts ID.
     * @return array<string, mixed>|false See Vhosts().
     */
    static function Vhost($id)
    {
        foreach (self::Vhosts() as $r) {
            if ((int) $r['vh_id_pk'] === (int) $id) {
                return $r;
            }
        }
        return false;
    }

    /**
     * Template flag <% if IsVhost %>: a domain's override page (?show=Vhost&id=).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getIsVhost()
    {
        global $controller;
        return $controller->GetControllerRequest('URL', 'show') === 'Vhost' && self::Vhost($controller->GetControllerRequest('URL', 'id')) !== false;
    }

    /**
     * Template flag <% if IsMain %>: the settings and the list of domains with overrides.
     *
     * @return bool
     */
    static function getIsMain()
    {
        return !self::getIsVhost();
    }

    /**
     * Whether a service answers on a local port (at most half a second).
     *
     * @param int $port The port.
     * @return bool
     */
    static function Answers($port)
    {
        $c = @fsockopen('127.0.0.1', (int) $port, $no, $str, 0.5);
        if ($c) {
            fclose($c);
            return true;
        }
        return false;
    }

    /**
     * The figures at the top (<@ Tiles @>): Apache, the domains, and when the configuration was last written.
     *
     * @return string The HTML.
     */
    static function getTiles()
    {
        $port = (int) ctrl_options::GetSystemOption('apache_port');
        $up = self::Answers($port > 0 ? $port : 80);
        $n = count(self::Vhosts());
        $over = count(array_filter(self::Vhosts(), function ($r) { return (bool) $r['overrides']; }));
        $changed = (string) ctrl_options::GetSystemOption('apache_changed');
        $ico = function ($d) { return '<svg class="aa-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>'; };
        $when = strtolower($changed) === 'true' ? ui_language::translate('Being written: within 5 minutes')
            : (ctype_digit($changed) && (int) $changed > 0 ? ui_language::translate('Written') . ' ' . ui_language::Date('j M, H:i', (int) $changed) : ui_language::translate('Up to date'));
        return '<div class="aa-tile ' . ($up ? 'ok' : 'bad') . '">' . $ico($up ? '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>' : '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>')
            . '<div><b>' . self::t($up ? 'Running' : 'Not answering') . '</b><span>' . str_replace('%d', (string) ($port > 0 ? $port : 80), self::t('Apache, port %d')) . '</span></div></div>'
            . '<div class="aa-tile">' . $ico('<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>') . '<div><b>' . $n . '</b><span>' . str_replace('%d', (string) $over, self::t('domains, %d with their own settings')) . '</span></div></div>'
            . '<div class="aa-tile">' . $ico('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>') . '<div><b>' . self::h($when) . '</b><span>' . self::t('Apache configuration') . '</span></div></div>';
    }

    /**
     * The domains with their own settings (<@ OverrideRows @>), and a picker for any domain.
     *
     * @return string The HTML.
     */
    static function getOverrideRows()
    {
        $rows = '';
        $opts = '';
        foreach (self::Vhosts() as $r) {
            $opts .= '<option value="' . (int) $r['vh_id_pk'] . '">' . self::h($r['vh_name_vc']) . ' (' . self::h($r['owner']) . ')</option>';
            if (!$r['overrides']) {
                continue;
            }
            $chips = '';
            foreach ($r['overrides'] as $o) {
                $chips .= '<span class="aa-chip' . (in_array($o[0], array('Switched off', 'open_basedir off', 'FollowSymLinks on'), true) ? ' warn' : '') . '">' . str_replace('%s', self::h($o[1]), self::t($o[0])) . '</span>';
            }
            $rows .= '<div class="aa-row"><div class="aa-main"><b>' . self::h($r['vh_name_vc']) . '</b><span>' . self::t('of') . ' ' . self::h($r['owner']) . '</span><div class="aa-chips">' . $chips . '</div></div>'
                . '<a class="btn btn-default btn-sm" href="./?module=apache_admin&amp;show=Vhost&amp;id=' . (int) $r['vh_id_pk'] . '">' . self::t('Edit') . '</a></div>';
        }
        // the picker first: it stays in the same place however many domains have settings of their own
        return ($opts !== '' ? '<form class="aa-pick" method="get" action="./"><input type="hidden" name="module" value="apache_admin"><input type="hidden" name="show" value="Vhost">'
                . '<label for="aa-id">' . self::t('Change the settings of a domain:') . '</label><div><select class="form-control" name="id" id="aa-id">' . $opts . '</select>'
                . '<button class="btn btn-default" type="submit">' . self::t('Open') . '</button></div></form>' : '')
            . ($rows !== '' ? '<div class="aa-rows">' . $rows . '</div>' : '<p class="aa-sub">' . self::t('No domain has settings of its own.') . '</p>');
    }

    /**
     * The settings form (<@ SettingsForm @>).
     *
     * @return string The HTML.
     */
    static function getSettingsForm()
    {
        $fields = self::$fields !== null ? self::$fields : ui_settingsform::Fields(ui_module::GetModuleName(), self::Spec());
        return ui_settingsform::Render($fields, './?module=apache_admin&action=UpdateApacheConfig', 'Save settings',
            '<label class="aa-force"><input type="checkbox" name="inForce" value="1"> ' . self::t('Also write the configuration again without a change') . '</label>');
    }

    /**
     * Handle the settings form: save each allowed value that changed, and let the daemon write Apache's configuration.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doUpdateApacheConfig()
    {
        global $controller;
        runtime_csfr::Protect();
        $form = $controller->GetAllControllerRequests('FORM');
        $fields = ui_settingsform::Fields(ui_module::GetModuleName(), self::Spec());
        $changes = ui_settingsform::Read($fields, $form);
        $saved = ui_settingsform::Write($changes);
        if ($saved > 0 || !empty($form['inForce'])) {
            self::SetWriteApacheConfigTrue();
        }
        self::$fields = $fields;
        $bad = count(array_filter($fields, function ($x) { return !empty($x['error']); }));
        self::$result = (!empty($form['inForce']) && $saved === 0 && $bad === 0)
            ? ui_sysmessage::shout(self::t('The web server\'s configuration is written again within 5 minutes.'), 'zannounceok')
            : ui_settingsform::Result($fields, $saved, 'The daemon writes the web server\'s configuration within 5 minutes.');
    }

    /**
     * The domain on its override page (<@ VhostTitle @>, <@ VhostId @>, <@ VhostInfo @>, <@ VhostFields @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return array<string, mixed>|false
     */
    static function Current()
    {
        global $controller;
        return self::Vhost($controller->GetControllerRequest('URL', 'id'));
    }

    /**
     * <@ VhostTitle @>: the domain's name, escaped.
     *
     * @return string
     */
    static function getVhostTitle()
    {
        $r = self::Current();
        return $r ? self::h($r['vh_name_vc']) : '';
    }

    /**
     * <@ VhostId @>: the domain's ID.
     *
     * @return int
     */
    static function getVhostId()
    {
        $r = self::Current();
        return $r ? (int) $r['vh_id_pk'] : 0;
    }

    /**
     * <@ VhostInfo @>: who owns the domain, what it is and its folder.
     *
     * @return string The HTML.
     */
    static function getVhostInfo()
    {
        $r = self::Current();
        if (!$r) {
            return '';
        }
        $types = array(1 => 'Domain', 2 => 'Sub-domain', 3 => 'Parked domain');
        return '<dl class="aa-dl"><dt>' . self::t('Client') . '</dt><dd>' . self::h($r['owner']) . '</dd><dt>' . self::t('Kind') . '</dt><dd>' . self::t(isset($types[(int) $r['vh_type_in']]) ? $types[(int) $r['vh_type_in']] : 'Domain') . '</dd>'
            . '<dt>' . self::t('Folder') . '</dt><dd><code>public_html/' . self::h($r['vh_directory_vc']) . '</code></dd>'
            . ($r['vh_created_ts'] ? '<dt>' . self::t('Added') . '</dt><dd>' . self::h(ui_language::Date('j M Y', (int) $r['vh_created_ts'])) . '</dd>' : '') . '</dl>';
    }

    /**
     * <@ VhostFields @>: the domain's override fields, with what was typed after a refused save.
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getVhostFields()
    {
        global $controller;
        $r = self::Current();
        if (!$r) {
            return '';
        }
        $f = self::$errors ? $controller->GetAllControllerRequests('FORM') : array();
        $val = function ($k) use ($r, $f) { return array_key_exists($k, $f) ? (string) $f[$k] : (string) $r[$k]; };
        $chk = function ($k) use ($r, $f) { return ($f ? !empty($f[$k]) : (int) ($r[$k] ?? 0) === 1) ? ' checked' : ''; };
        $err = function ($k) { return isset(self::$errors[$k]) ? '<em>' . self::t(self::$errors[$k]) . '</em>' : ''; };
        $row = function ($k, $label, $help, $ctl, $wide = false) use ($err) {
            return '<div class="sf-row' . (isset(self::$errors[$k]) ? ' is-bad' : '') . ($wide ? ' is-wide' : '') . '"><div class="sf-what"><label for="' . $k . '">' . self::t($label) . '</label>'
                . ($help !== '' ? '<small>' . self::t($help) . '</small>' : '') . $err($k) . '</div><div class="sf-ctl">' . $ctl . '</div></div>';
        };
        $sw = function ($k) use ($chk) { return '<label class="sf-switch"><input type="checkbox" name="' . $k . '" id="' . $k . '" value="1"' . $chk($k) . '><span class="sf-toggle"></span></label>'; };
        $num = function ($k) use ($val) { return '<input class="form-control" type="number" name="' . $k . '" id="' . $k . '" min="1" max="65535" value="' . self::h($val($k)) . '" style="max-width:160px">'; };
        $code = function ($k) use ($val) { return '<textarea class="form-control sf-code" name="' . $k . '" id="' . $k . '" rows="7" spellcheck="false">' . self::h($val($k)) . '</textarea>'; };
        return '<section class="sf-pane">'
            . $row('vh_enabled_in', 'Website answers', 'Off: Apache does not serve this domain.', $sw('vh_enabled_in'))
            . $row('vh_obasedir_in', 'Keep PHP in the account\'s folder', 'open_basedir. Turn it off only for a program that needs files elsewhere.', $sw('vh_obasedir_in'))
            . $row('vh_symlinks_in', 'Allow FollowSymLinks (for moving an old site)', 'Off (recommended): Apache follows a symbolic link only to a file of the same owner, and an .htaccess with "Options +FollowSymLinks" gives error 500. On: this site\'s .htaccess may use it, and every link is followed, also to other accounts\' files. Switch it off again once the site uses "Options +SymLinksIfOwnerMatch".', $sw('vh_symlinks_in'))
            . $row('vh_custom_port_in', 'Port', 'Empty: the server\'s port.', $num('vh_custom_port_in'))
            . $row('vh_portforward_in', 'Send port 80 to that port', 'Needs Apache\'s mod_rewrite.', $sw('vh_portforward_in'))
            . $row('vh_custom_ip_vc', 'IP address', 'Empty: every address of the server.', '<input class="form-control" type="text" name="vh_custom_ip_vc" id="vh_custom_ip_vc" value="' . self::h($val('vh_custom_ip_vc')) . '" spellcheck="false">')
            . $row('vh_ssl_port_in', 'SSL port', 'Empty: 443.', $num('vh_ssl_port_in'))
            . $row('vh_ssl_tx', 'SSL settings', 'The certificate lines Sencrypt writes; change them only if you know why.', $code('vh_ssl_tx'), true)
            . $row('vh_custom_tx', 'Extra directives', 'Apache directives added to this domain\'s configuration.', $code('vh_custom_tx'), true)
            . '</section>';
    }

    /**
     * <@ SymlinksHelp @>: why a moved site can give error 500, and the steps to move it with the
     * FollowSymLinks switch, for an administrator who has not met it before.
     *
     * @return string The HTML.
     */
    static function getSymlinksHelp()
    {
        $steps = array(
            'Switch on "Allow FollowSymLinks" for this domain and save: the site works again within 5 minutes.',
            'Finish moving the site and check that it works.',
            'In the site\'s .htaccess files (File Manager), change "Options +FollowSymLinks" to "Options +SymLinksIfOwnerMatch". It works the same for the site.',
            'Switch "Allow FollowSymLinks" off again and check the site once more.',
        );
        $li = '';
        foreach ($steps as $s) {
            $li .= '<li>' . self::t($s) . '</li>';
        }
        return '<h4>' . self::t('A moved site gives error 500?') . '</h4>'
            . '<p>' . self::t('Old sites often have the line "Options +FollowSymLinks" in their .htaccess file. This server does not allow it, because it would let one website read another website\'s files, so Apache answers the site with error 500 (Internal Server Error). The site\'s error log then says "Option FollowSymLinks not allowed here".') . '</p>'
            . '<p><b>' . self::t('To move such a site:') . '</b></p><ol>' . $li . '</ol>'
            . '<p class="aa-sub">' . self::t('The site\'s owner can also do step 3 alone, without the switch. Clients see the same explanation in View Site Logs when their site has this error.') . '</p>';
    }

    /**
     * The old chooser's form (inVhost, a domain name): open that domain's page.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doDisplayVhost()
    {
        global $controller;
        runtime_csfr::Protect();
        foreach (self::Vhosts() as $r) {
            if ($r['vh_name_vc'] === (string) $controller->GetControllerRequest('FORM', 'inVhost')) {
                header('location: ./?module=apache_admin&show=Vhost&id=' . (int) $r['vh_id_pk']);
                exit;
            }
        }
    }

    /**
     * Handle a domain's override form: checked ports and IP address, then the daemon writes the configuration.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return bool|void False when refused (the form shows again); otherwise ends with a redirect and exit.
     */
    static function doSaveVhost()
    {
        global $zdbh, $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $r = self::Vhost($f['vh_id_pk'] ?? 0);
        if (!$r) {
            return false;
        }
        $port = function ($k) use ($f) {
            $v = trim((string) ($f[$k] ?? ''));
            if ($v === '') {
                return null;
            }
            if (!preg_match('/^\d{1,5}$/', $v) || (int) $v < 1 || (int) $v > 65535) {
                self::$errors[$k] = 'Enter a port from 1 to 65535, or leave it empty.';
                return null;
            }
            return (int) $v;
        };
        $cport = $port('vh_custom_port_in');
        $sport = $port('vh_ssl_port_in');
        $ip = trim((string) ($f['vh_custom_ip_vc'] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            self::$errors['vh_custom_ip_vc'] = 'Enter an IP address, or leave it empty.';
        }
        // FollowSymLinks: only through the switch, not around it in the extra directives
        $symlinks = !empty($f['vh_symlinks_in']) ? 1 : 0;
        if (!$symlinks && sys_symlinks::Offending((string) ($f['vh_custom_tx'] ?? ''))) {
            self::$errors['vh_custom_tx'] = 'A line here would switch FollowSymLinks on: switch on "Allow FollowSymLinks" above for this domain, or remove the line.';
        }
        if (self::$errors) {
            return false;
        }
        $text = function ($k) use ($f) { $v = rtrim(str_replace("\r\n", "\n", (string) ($f[$k] ?? ''))); return $v === '' ? null : $v; };
        $zdbh->prepare("UPDATE x_vhosts SET vh_enabled_in = ?, vh_obasedir_in = ?, vh_ssl_tx = ?, vh_ssl_port_in = ?, vh_custom_port_in = ?, vh_portforward_in = ?, vh_custom_ip_vc = ?, vh_custom_tx = ?
            WHERE vh_id_pk = ? AND vh_deleted_ts IS NULL")->execute(array(
            !empty($f['vh_enabled_in']) ? 1 : 0, !empty($f['vh_obasedir_in']) ? 1 : 0, $text('vh_ssl_tx'), $sport, $cport, !empty($f['vh_portforward_in']) ? 1 : null,
            $ip === '' ? null : $ip, $text('vh_custom_tx'), (int) $r['vh_id_pk']));
        if (sys_symlinks::EnsureColumn()) {
            $zdbh->prepare("UPDATE x_vhosts SET " . sys_symlinks::COLUMN . " = ? WHERE vh_id_pk = ? AND vh_deleted_ts IS NULL")->execute(array($symlinks, (int) $r['vh_id_pk']));
        }
        self::SetWriteApacheConfigTrue();
        header('location: ./?module=apache_admin&saved=' . rawurlencode($r['vh_name_vc']));
        exit;
    }

    /**
     * Return the message for the last action (template: <@ Result @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string|null
     */
    static function getResult()
    {
        global $controller;
        if (self::$errors) {
            return ui_sysmessage::shout(self::t('Not saved: a value is not allowed (shown below).'), 'zannounceerror');
        }
        if (self::$result !== null) {
            return self::$result;
        }
        $who = $controller->GetControllerRequest('URL', 'saved');
        if ($who) {
            return ui_sysmessage::shout(str_replace('%s', '<strong>' . self::h($who) . '</strong>', self::t('The settings of %s are saved. The daemon writes them to Apache within 5 minutes.')), 'zannounceok');
        }
        return null;
    }
}
