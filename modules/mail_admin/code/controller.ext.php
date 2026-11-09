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
 * Updated by RusTus, 2026-11-07
 */
/**
 * Mail Admin module (administrators): the mail settings in x_settings (mailbox size, what happens to
 * the mail of deleted domains, and the SOGo groupware), as tabs with checked values, and whether the
 * mail services answer. The daemon applies the SOGo settings within 5 minutes.
 */
class module_controller extends ctrl_module
{

    /** @var string|null The message after a save. */
    static $result = null;

    /** @var array<string, array<string, mixed>>|null The settings after a refused save, with what was typed. */
    static $fields = null;

    /**
     * The settings, their tabs, controls and ranges (the ranges the daemon applies).
     *
     * @return array<string, array<string, mixed>> See ui_settingsform.
     */
    static function Spec()
    {
        $langs = array();
        foreach (array('English', 'French', 'German', 'SpanishSpain', 'SpanishArgentina', 'Italian', 'Dutch', 'BrazilianPortuguese', 'Portuguese', 'Polish', 'Swedish', 'Danish', 'NorwegianBokmal',
            'Finnish', 'Czech', 'Russian', 'Ukrainian', 'Japanese', 'ChineseChina', 'Hungarian', 'TurkishTurkey', 'Catalan') as $l) {
            $langs[$l] = preg_replace(array('/^(Spanish|Chinese|Turkish)(\w+)$/', '/^BrazilianPortuguese$/', '/^NorwegianBokmal$/'), array('$1 ($2)', 'Portuguese (Brazil)', 'Norwegian (Bokmål)'), $l);
        }
        $refresh = array('manually' => 'Only when asked', 'every_minute' => 'Every minute', 'every_2_minutes' => 'Every 2 minutes', 'every_5_minutes' => 'Every 5 minutes',
            'every_10_minutes' => 'Every 10 minutes', 'every_20_minutes' => 'Every 20 minutes', 'every_30_minutes' => 'Every 30 minutes', 'once_per_hour' => 'Every hour');
        $m = 'Mailboxes';
        $g = 'SOGo: use';
        $f = 'SOGo: features';
        $s = 'SOGo: security';
        $p = 'SOGo: performance';
        return array(
            'max_mail_size' => array('section' => $m, 'label' => 'Largest mailbox', 'help' => 'The most mail one mailbox may hold.', 'type' => 'number', 'min' => 1, 'max' => 1048576, 'unit' => 'MB'),
            'remove_orphan' => array('section' => $m, 'label' => 'Delete the mailboxes of deleted domains', 'help' => 'When a domain is deleted, its mailboxes and their mail go too, at the next daemon run.', 'type' => 'switch'),
            'sogo_language' => array('section' => $g, 'label' => 'Language', 'help' => 'For users who have not chosen one.', 'type' => 'select', 'options' => $langs),
            'sogo_timezone' => array('section' => $g, 'label' => 'Time zone', 'help' => 'For calendars, for users who have not chosen one.', 'type' => 'timezone'),
            'sogo_first_day' => array('section' => $g, 'label' => 'First day of the week', 'help' => '', 'type' => 'select', 'options' => array('1' => 'Monday', '0' => 'Sunday', '6' => 'Saturday')),
            'sogo_refresh' => array('section' => $g, 'label' => 'Check for new mail', 'help' => 'How often the mail list looks for new mail, unless a user chose otherwise.', 'type' => 'select', 'options' => $refresh),
            'sogo_max_message_kb' => array('section' => $g, 'label' => 'Largest message to send', 'help' => 'With its attachments. 0: no limit of SOGo\'s own (the mail server\'s limit still applies).', 'type' => 'number', 'min' => 0, 'max' => 1048576, 'unit' => 'KB'),
            'sogo_activesync' => array('section' => $f, 'label' => 'ActiveSync', 'help' => 'Phones, tablets and Outlook sync mail, calendars and contacts.', 'type' => 'switch'),
            'sogo_vacation' => array('section' => $f, 'label' => 'Out-of-office replies', 'help' => 'Users can set automatic replies.', 'type' => 'switch'),
            'sogo_forward' => array('section' => $f, 'label' => 'Mail forwarding', 'help' => 'Users can forward their mail to another address.', 'type' => 'switch'),
            'sogo_filters' => array('section' => $f, 'label' => 'Mail filters', 'help' => 'Users can sort incoming mail with rules (Sieve).', 'type' => 'switch'),
            'sogo_invitations' => array('section' => $f, 'label' => 'Invitation emails', 'help' => 'Guests of a calendar event get an email about it and its changes.', 'type' => 'switch'),
            'sogo_email_alarms' => array('section' => $f, 'label' => 'Reminders by email', 'help' => 'Calendar reminders can also come by email (checked every minute).', 'type' => 'switch'),
            'sogo_extra_accounts' => array('section' => $f, 'label' => 'Other mail accounts', 'help' => 'Users can add mail accounts on other servers to SOGo.', 'type' => 'switch'),
            'sogo_custom_from' => array('section' => $f, 'label' => 'Change the sender address', 'help' => 'Users can send with another From address. Off is safer: mail can then not be sent in someone else\'s name.', 'type' => 'switch'),
            'sogo_failed_logins' => array('section' => $s, 'label' => 'Wrong passwords before a block', 'help' => 'Within 10 minutes, for one user. 0: never blocked.', 'type' => 'number', 'min' => 0, 'max' => 1000),
            'sogo_block_minutes' => array('section' => $s, 'label' => 'Block for', 'help' => 'How long a user is blocked after too many wrong passwords.', 'type' => 'number', 'min' => 1, 'max' => 1440, 'unit' => 'minutes'),
            'sogo_session_minutes' => array('section' => $s, 'label' => 'Log out after', 'help' => 'Without activity. 0: never.', 'type' => 'number', 'min' => 0, 'max' => 10080, 'unit' => 'minutes'),
            'sogo_workers' => array('section' => $p, 'label' => 'Worker processes', 'help' => 'How many requests SOGo handles at once. Each uses memory, and every syncing phone holds one while it waits.', 'type' => 'number', 'min' => 1, 'max' => 50),
            'sogo_memory_mb' => array('section' => $p, 'label' => 'Memory per worker', 'help' => 'A worker is restarted when it uses more.', 'type' => 'number', 'min' => 128, 'max' => 4096, 'unit' => 'MB'),
            'sogo_as_ping_seconds' => array('section' => $p, 'label' => 'ActiveSync wait', 'help' => 'How long a phone waits for new mail on one connection. Longer saves battery.', 'type' => 'number', 'min' => 60, 'max' => 3540, 'unit' => 'seconds'),
            'sogo_as_window' => array('section' => $p, 'label' => 'ActiveSync items per sync', 'help' => 'The most items sent to a phone at once.', 'type' => 'number', 'min' => 25, 'max' => 500),
            'sogo_backup' => array('section' => $p, 'label' => 'Daily backup', 'help' => 'All calendars, contacts and preferences, each night to /var/backups/sogo (kept 31 days).', 'type' => 'switch'),
        );
    }

    /**
     * The settings form (<@ SettingsForm @>).
     *
     * @return string The HTML.
     */
    static function getSettingsForm()
    {
        $fields = self::$fields !== null ? self::$fields : ui_settingsform::Fields(ui_module::GetModuleName(), self::Spec());
        return ui_settingsform::Render($fields, './?module=mail_admin&action=UpdateConfig', 'Save settings');
    }

    /**
     * Saves the settings form: each allowed value that changed (a refused one is shown with why).
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doUpdateConfig()
    {
        global $controller;
        runtime_csfr::Protect();
        $fields = ui_settingsform::Fields(ui_module::GetModuleName(), self::Spec());
        $changes = ui_settingsform::Read($fields, $controller->GetAllControllerRequests('FORM'));
        $saved = ui_settingsform::Write($changes);
        self::$fields = $fields;
        self::$result = ui_settingsform::Result($fields, $saved, 'The daemon applies SOGo settings within 5 minutes.');
    }

    /**
     * The message after a save (<@ Result @>).
     *
     * @return string|null
     */
    static function getResult()
    {
        return self::$result;
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
     * The mail services and whether they answer (<@ Services @>), and the mailbox count.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML.
     */
    static function getServices()
    {
        global $zdbh;
        $list = array(array('Incoming mail (SMTP)', 25), array('Sending (submission)', 587), array('IMAP', 993), array('POP3', 995));
        if (is_file(sys_platform::Get('sogo_bin'))) {
            $list[] = array('SOGo groupware', 20000);
        }
        $out = '';
        foreach ($list as $s) {
            $up = self::Answers($s[1]);
            $out .= '<li class="' . ($up ? 'ok' : 'bad') . '"><i></i><span>' . htmlspecialchars(ui_language::translate($s[0]), ENT_QUOTES, 'UTF-8') . '</span><code>' . $s[1] . '</code><b>'
                . htmlspecialchars(ui_language::translate($up ? 'answers' : 'not answering'), ENT_QUOTES, 'UTF-8') . '</b></li>';
        }
        $boxes = (int) $zdbh->query("SELECT COUNT(*) FROM x_mailboxes WHERE mb_deleted_ts IS NULL")->fetchColumn();
        return '<ul class="ma-svc">' . $out . '</ul><p class="ma-sub">' . ($boxes === 1 ? htmlspecialchars(ui_language::translate('1 mailbox on the server.'), ENT_QUOTES, 'UTF-8')
                : str_replace('%d', (string) $boxes, htmlspecialchars(ui_language::translate('%d mailboxes on the server.'), ENT_QUOTES, 'UTF-8')))
            . (is_file(sys_platform::Get('sogo_bin')) ? '' : ' ' . htmlspecialchars(ui_language::translate('SOGo is not installed: its settings apply when it is.'), ENT_QUOTES, 'UTF-8')) . '</p>';
    }

}
