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
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
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
require_once dirname(__FILE__) . '/infoparse.php';

/**
 * PHP Info controller: the PHP the panel runs on, from phpinfo(), as a page in the panel's style.
 */
class module_controller extends ctrl_module
{

    /**
     * Escape text for HTML.
     *
     * @param mixed $s The text.
     * @return string
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translated and HTML-escaped text.
     *
     * @param string $s The English text.
     * @return string
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * An inline SVG icon (stroked, in the text colour).
     *
     * @param string $name The icon: php, server, memory, upload, clock, bolt, puzzle, search, sliders, list, box.
     * @return string SVG markup, or '' for an unknown name.
     */
    static function Icon($name)
    {
        $p = array(
            'php' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="m10 12-2 2 2 2M14 12l2 2-2 2"/>',
            'server' => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
            'memory' => '<rect x="5" y="5" width="14" height="14" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>',
            'upload' => '<path d="M20 15v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/><path d="m16 8-4-4-4 4"/><path d="M12 4v11"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'bolt' => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
            'puzzle' => '<path d="M10 4a2 2 0 1 1 4 0v2h4a1 1 0 0 1 1 1v4h-2a2 2 0 1 0 0 4h2v4a1 1 0 0 1-1 1h-4v-2a2 2 0 1 0-4 0v2H6a1 1 0 0 1-1-1v-4h2a2 2 0 1 0 0-4H5V7a1 1 0 0 1 1-1h4z"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
            'sliders' => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
            'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
            'box' => '<path d="m21 8-9-5-9 5 9 5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        );
        if (!isset($p[$name])) {
            return '';
        }
        return '<svg class="pi-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$name] . '</svg>';
    }

    /**
     * Whether the logged-in account is an administrator.
     *
     * @return bool
     */
    static function IsAdmin()
    {
        $user = ctrl_users::GetUserDetail();
        return ((int) $user['usergroupid'] === 1);
    }

    /**
     * phpinfo() of the PHP the panel runs on, parsed. The environment and the request
     * variables (server paths, the panel's own settings) only for administrators.
     *
     * @return array<string, mixed> See phpi_parse().
     */
    static function Info()
    {
        $what = INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES;
        if (self::IsAdmin()) {
            $what |= INFO_ENVIRONMENT | INFO_VARIABLES;
        }
        ob_start();
        phpinfo($what);
        $html = (string) ob_get_clean();
        return phpi_parse($html);
    }

    /**
     * A value for the page: empty and "no value" shown as a muted dash.
     *
     * @param string $v The value.
     * @return string HTML.
     */
    static function Val($v)
    {
        $v = (string) $v;
        if ($v === '' || $v === 'no value') {
            return '<span class="pi-none">' . self::t('no value') . '</span>';
        }
        if ($v === 'On' || $v === 'enabled' || $v === 'Enabled' || $v === 'Up and Running') {
            return '<span class="pi-on">' . self::h($v) . '</span>';
        }
        if ($v === 'Off' || $v === 'disabled' || $v === 'Disabled') {
            return '<span class="pi-off">' . self::h($v) . '</span>';
        }
        return self::h($v);
    }

    /**
     * A list value (comma separated) as chips.
     *
     * @param string $v The value.
     * @return string HTML.
     */
    static function Chips($v)
    {
        $items = array_filter(array_map('trim', preg_split('/,\s*/', (string) $v)), 'strlen');
        if (!$items) {
            return '<span class="pi-none">' . self::t('none') . '</span>';
        }
        return '<div class="pi-chips">' . implode('', array_map(function ($i) { return '<span>' . self::h($i) . '</span>'; }, $items)) . '</div>';
    }

    /**
     * A table of directives: name, local value, master value; a row where they differ is marked.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $rows The directives.
     * @return string HTML.
     */
    static function DirectiveTable($rows)
    {
        $out = '<table class="pi-table"><thead><tr><th>' . self::t('Directive') . '</th><th>' . self::t('Value') . '</th><th>' . self::t('Master value') . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $diff = ($r[1] !== $r[2]);
            $out .= '<tr class="pi-row' . ($diff ? ' pi-diff' : '') . '" data-s="' . self::h(strtolower($r[0] . ' ' . $r[1])) . '"><td class="pi-key">' . self::h($r[0])
                . ($diff ? ' <span class="pi-tag" title="' . self::t('Set differently for the panel than in php.ini') . '">' . self::t('changed') . '</span>' : '') . '</td>'
                . '<td class="pi-val">' . self::Val($r[1]) . '</td><td class="pi-val pi-master">' . self::Val($r[2]) . '</td></tr>';
        }
        return $out . '</tbody></table>';
    }

    /**
     * A table of name and value rows.
     *
     * @param array<int, array{0: string, 1: string}> $rows The rows.
     * @return string HTML.
     */
    static function InfoTable($rows)
    {
        $out = '<table class="pi-table pi-kv"><tbody>';
        foreach ($rows as $r) {
            $out .= '<tr class="pi-row" data-s="' . self::h(strtolower($r[0] . ' ' . $r[1])) . '"><td class="pi-key">' . self::h($r[0]) . '</td><td class="pi-val">' . self::Val($r[1]) . '</td></tr>';
        }
        return $out . '</tbody></table>';
    }

    /**
     * A search box that filters the rows (or cards) of its tab.
     *
     * @param string $placeholder The placeholder text (English).
     * @return string HTML.
     */
    static function Search($placeholder)
    {
        return '<label class="pi-search">' . self::Icon('search') . '<input type="search" class="form-control" placeholder="' . self::t($placeholder) . '" aria-label="' . self::t($placeholder) . '"></label>';
    }

    /**
     * Template HTML: the whole PHP Info page (template: <@ PHPInfo @>).
     *
     * @return string HTML.
     */
    static function getPHPInfo()
    {
        $d = self::Info();
        $general = array();
        foreach ($d['general'] as $r) {
            if ($r[1] !== '') {   // the engine's copyright line has no value
                $general[$r[0]] = $r[1];
            }
        }
        // every directive by name (local and master value)
        $dir = array();
        foreach ($d['modules'] as $m) {
            foreach ($m['directives'] as $r) {
                $dir[$r[0]] = $r;
            }
        }
        $ini = function ($k) use ($dir) { return isset($dir[$k]) ? $dir[$k][1] : ''; };
        $opcache = isset($d['modules']['Zend OPcache']) ? $d['modules']['Zend OPcache']['info'] : array();
        $opState = '';
        foreach ($opcache as $r) {
            if ($r[0] === 'Opcode Caching') {
                $opState = $r[1];
            }
        }
        $admin = self::IsAdmin();
        $g = function ($k) use ($general) { return isset($general[$k]) ? $general[$k] : ''; };

        // cards
        $card = function ($icon, $label, $value, $detail, $color) {
            return '<div class="pi-card" style="--c:' . $color . '">' . self::Icon($icon) . '<div><div class="pi-card-label">' . self::t($label) . '</div><div class="pi-card-value">' . self::h($value)
                . '</div><div class="pi-card-detail">' . $detail . '</div></div></div>';
        };
        $out = '<div class="pi" id="pi">';
        $out .= '<div class="pi-head"><div class="pi-logo">php</div><div><div class="pi-title">PHP ' . self::h($d['version']) . '</div>'
            . '<div class="pi-sub">' . self::h($g('Server API')) . ($g('Build Date') !== '' ? ' · ' . self::t('built') . ' ' . self::h($g('Build Date')) : '') . '</div>'
            . '<div class="pi-sub">Zend Engine ' . self::h(zend_version()) . '</div></div></div>';
        $out .= '<div class="pi-cards">'
            . $card('memory', 'Memory limit', $ini('memory_limit') === '-1' ? ui_language::translate('No limit') : $ini('memory_limit'), self::t('per script'), '#447fb3')
            . $card('upload', 'Largest upload', $ini('upload_max_filesize'), self::t('form post up to') . ' ' . self::h($ini('post_max_size')), '#1fa2b5')
            . $card('clock', 'Time limit', $ini('max_execution_time') === '0' ? ui_language::translate('none') : $ini('max_execution_time') . ' s', self::t('input') . ' ' . self::h($ini('max_input_time')) . ' s · ' . self::h($ini('max_input_vars')) . ' ' . self::t('fields'), '#8e6bd8')
            . $card('bolt', 'OPcache', $opState !== '' ? $opState : ui_language::translate('Not loaded'), self::t('compiled scripts kept in memory'), $opState === 'Up and Running' ? '#2db87d' : '#f5a623')
            . $card('puzzle', 'Extensions', (string) count($d['modules']), self::t('loaded'), '#ff6854')
            . '</div>';

        // tabs
        $tabs = array('summary' => array('list', 'Summary', ''), 'extensions' => array('puzzle', 'Extensions', (string) count($d['modules'])),
            'settings' => array('sliders', 'Core settings', isset($d['modules']['Core']) ? (string) count($d['modules']['Core']['directives']) : '0'));
        if ($admin) {
            $tabs['environment'] = array('server', 'Environment', (string) count($d['environment']));
            $tabs['variables'] = array('box', 'Variables', (string) count($d['variables']));
        }
        $out .= '<nav class="pi-tabs" role="tablist">';
        $first = true;
        foreach ($tabs as $k => $t) {
            $out .= '<button type="button" role="tab" class="pi-tab' . ($first ? ' on' : '') . '" data-tab="' . $k . '">' . self::Icon($t[0]) . '<span>' . self::t($t[1]) . '</span>'
                . ($t[2] !== '' ? ' <span class="pi-badge">' . self::h($t[2]) . '</span>' : '') . '</button>';
            $first = false;
        }
        $out .= '</nav>';

        // summary
        $key = array(
            'Resources' => array('memory_limit', 'max_execution_time', 'max_input_time', 'max_input_vars', 'upload_max_filesize', 'post_max_size', 'max_file_uploads', 'file_uploads'),
            'Errors' => array('display_errors', 'display_startup_errors', 'log_errors', 'error_log', 'error_reporting'),
            'Security' => array('open_basedir', 'allow_url_fopen', 'allow_url_include', 'expose_php', 'session.cookie_httponly', 'session.cookie_secure', 'session.use_strict_mode'),
            'Other' => array('date.timezone', 'default_charset', 'session.gc_maxlifetime', 'session.save_handler', 'opcache.enable', 'opcache.memory_consumption', 'short_open_tag'),
        );
        $out .= '<section class="pi-pane on" data-pane="summary"><div class="pi-grid">';
        $out .= '<div class="pi-panel"><h4>' . self::t('Build') . '</h4><dl class="pi-dl">';
        foreach (array('System', 'Build Date', 'Build System', 'Build Provider', 'Server API', 'Configuration File (php.ini) Path', 'Loaded Configuration File', 'Scan this dir for additional .ini files',
            'PHP API', 'PHP Extension', 'Zend Extension', 'Debug Build', 'Thread Safety', 'Zend Signal Handling', 'Zend Memory Manager', 'Zend Multibyte Support', 'IPv6 Support', 'DTrace Support', 'Virtual Directory Support') as $k) {
            if (isset($general[$k])) {
                $out .= '<dt>' . self::h($k) . '</dt><dd>' . self::Val($general[$k]) . '</dd>';
            }
        }
        $out .= '</dl></div>';
        $out .= '<div class="pi-panel"><h4>' . self::t('Key settings') . '</h4><p class="pi-note">' . self::t('Marked "changed" where the panel sets a value other than php.ini.') . '</p>';
        foreach ($key as $group => $names) {
            $rows = array();
            foreach ($names as $n) {
                if (isset($dir[$n])) {
                    $rows[] = $dir[$n];
                }
            }
            if ($rows) {
                $out .= '<div class="pi-group">' . self::t($group) . '</div>' . str_replace('<thead><tr><th>' . self::t('Directive') . '</th><th>' . self::t('Value') . '</th><th>' . self::t('Master value') . '</th></tr></thead>', '', self::DirectiveTable($rows));
            }
        }
        $out .= '</div>';
        $out .= '<div class="pi-panel pi-wide"><h4>' . self::t('Additional .ini files') . '</h4>' . self::Chips(preg_replace('#[^,]*/#', '', $g('Additional .ini files parsed'))) . '</div>';
        if (isset($dir['disable_functions'])) {
            $out .= '<div class="pi-panel pi-wide"><h4>' . self::t('Disabled functions') . '</h4>' . self::Chips($dir['disable_functions'][1] === 'no value' ? '' : $dir['disable_functions'][1]) . '</div>';
        }
        foreach (array('Registered PHP Streams' => 'Streams', 'Registered Stream Socket Transports' => 'Socket transports', 'Registered Stream Filters' => 'Stream filters') as $k => $label) {
            if (isset($general[$k])) {
                $out .= '<div class="pi-panel"><h4>' . self::t($label) . '</h4>' . self::Chips($general[$k]) . '</div>';
            }
        }
        $out .= '</div></section>';

        // extensions
        $out .= '<section class="pi-pane" data-pane="extensions"><div class="pi-toolbar">' . self::Search('Find an extension or setting') . '<span class="pi-count"></span></div><div class="pi-exts">';
        $names = array_keys($d['modules']);
        natcasesort($names);
        foreach ($names as $name) {
            $m = $d['modules'][$name];
            $ver = phpversion($name === 'Zend OPcache' ? 'Zend OPcache' : $name);
            $search = strtolower($name);
            foreach ($m['directives'] as $r) {
                $search .= ' ' . strtolower($r[0]);
            }
            $out .= '<details class="pi-ext" data-s="' . self::h($search) . '"><summary><span class="pi-ext-name">' . self::h($name) . '</span>'
                . ($ver ? '<span class="pi-ext-ver">' . self::h($ver) . '</span>' : '')
                . ($m['directives'] ? '<span class="pi-ext-n">' . count($m['directives']) . ' ' . self::t(count($m['directives']) === 1 ? 'setting' : 'settings') . '</span>' : '') . '</summary><div class="pi-ext-body">'
                . ($m['info'] ? self::InfoTable($m['info']) : '') . ($m['directives'] ? self::DirectiveTable($m['directives']) : '')
                . (!$m['info'] && !$m['directives'] ? '<p class="pi-note">' . self::t('Nothing more to show for this extension.') . '</p>' : '') . '</div></details>';
        }
        $out .= '</div></section>';

        // core settings
        $out .= '<section class="pi-pane" data-pane="settings"><div class="pi-toolbar">' . self::Search('Find a setting') . '<span class="pi-count"></span></div><div class="pi-panel pi-flat">'
            . (isset($d['modules']['Core']) ? self::DirectiveTable($d['modules']['Core']['directives']) : '') . '</div></section>';

        if ($admin) {
            $out .= '<section class="pi-pane" data-pane="environment"><div class="pi-toolbar">' . self::Search('Find a variable') . '<span class="pi-count"></span></div>'
                . '<p class="pi-note">' . self::t('The environment of the panel\'s PHP. Shown to administrators only.') . '</p><div class="pi-panel pi-flat">' . self::InfoTable($d['environment']) . '</div></section>';
            $out .= '<section class="pi-pane" data-pane="variables"><div class="pi-toolbar">' . self::Search('Find a variable') . '<span class="pi-count"></span></div>'
                . '<p class="pi-note">' . self::t('What PHP received with this page request. Shown to administrators only.') . '</p><div class="pi-panel pi-flat">' . self::InfoTable($d['variables']) . '</div></section>';
        }
        $out .= '</div>';
        return $out;
    }

}
