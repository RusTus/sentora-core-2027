<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/)
 * Sentora is a GPL fork of the ZPanel Project.
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * Updated by RusTus, 2026-11-07
 */
/**
 * Webmail module: the page that opens Roundcube (etc/apps/webmail) and, when it is
 * installed, the SOGo groupware, with the account's mailboxes to log in with and the
 * settings for mail programs and phones. Roundcube and SOGo keep their own look.
 */
class module_controller extends ctrl_module
{

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
     * The text of the Roundcube button (<@ LaunchWebMail @>), kept for themes that use it.
     *
     * @return string The translated text.
     */
    static function getLaunchWebMail()
    {
        return ui_language::translate("Launch Webmail");
    }

    /**
     * Whether SOGo is installed (<% if HasSOGo %>).
     *
     * @return bool
     */
    static function getHasSOGo()
    {
        return is_file(sys_platform::Get('sogo_bin'));
    }

    /**
     * The server name for mail programs and phones (<@ SOGoServer @>): the panel's own name, which its certificate is for.
     *
     * @return string The escaped host name.
     */
    static function getSOGoServer()
    {
        return self::h(ctrl_options::GetSystemOption('sentora_domain'));
    }

    /**
     * The Roundcube version from its own source (<@ RoundcubeVersion @>).
     *
     * @return string For example '1.7.5', or ''.
     */
    static function getRoundcubeVersion()
    {
        $src = @file_get_contents('etc/apps/webmail/program/include/iniset.php');
        return ($src !== false && preg_match("/RCMAIL_VERSION', '([0-9.]+)'/", $src, $m)) ? self::h($m[1]) : '';
    }

    /**
     * The account's mailboxes.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array{0: string, 1: bool}> Address and whether it is switched on.
     */
    static function Boxes()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT mb_address_vc, mb_enabled_in FROM x_mailboxes WHERE mb_acc_fk = :u AND mb_deleted_ts IS NULL ORDER BY mb_address_vc");
        $q->execute(array(':u' => (int) ctrl_users::GetUserDetail()['userid']));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
            $out[] = array((string) $r[0], (int) $r[1] === 1);
        }
        return $out;
    }

    /**
     * Template flag <% if HasBoxes %>: the account has a mailbox to log in with.
     *
     * @return bool
     */
    static function getHasBoxes()
    {
        return count(self::Boxes()) > 0;
    }

    /**
     * Template flag <% if NoBoxes %>: no mailbox yet.
     *
     * @return bool
     */
    static function getNoBoxes()
    {
        return !self::getHasBoxes();
    }

    /**
     * Template flag <% if MailboxesOn %>: Mailboxes is switched on, for its links.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return bool
     */
    static function getMailboxesOn()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_modules WHERE mo_folder_vc = 'mailboxes' AND mo_enabled_en = 'true'");
        $q->execute();
        return (int) $q->fetchColumn() > 0;
    }

    /**
     * One row per mailbox (<@ BoxRows @>): the address with Copy, and a note when it is switched off.
     *
     * @return string The HTML.
     */
    static function getBoxRows()
    {
        $out = '';
        foreach (self::Boxes() as $b) {
            $at = strrpos($b[0], '@');
            $out .= '<div class="wm-box' . ($b[1] ? '' : ' is-off') . '"><span class="wm-avatar" aria-hidden="true">' . self::h(strtoupper(substr($b[0], 0, 1))) . '</span>'
                . '<span class="wm-addr" data-copy="' . self::h($b[0]) . '"><b>' . self::h($at === false ? $b[0] : substr($b[0], 0, $at)) . '</b><wbr><span>' . self::h($at === false ? '' : substr($b[0], $at)) . '</span></span>'
                . ($b[1] ? '' : '<span class="wm-off">' . self::t('switched off: can not log in') . '</span>')
                . '<button type="button" class="btn btn-default btn-xs wm-copy" data-done="' . self::t('Copied') . '">' . self::t('Copy') . '</button></div>';
        }
        return $out;
    }

    /**
     * The settings for mail programs (<@ ClientSettings @>): the server and the ports, each with Copy.
     *
     * @return string The HTML.
     */
    static function getClientSettings()
    {
        $host = self::h(ctrl_options::GetSystemOption('sentora_domain'));
        $copy = '<button type="button" class="btn btn-default btn-xs wm-copy" data-done="' . self::t('Copied') . '">' . self::t('Copy') . '</button>';
        $rows = array(
            array('Incoming (IMAP)', '993', 'SSL/TLS', '143 ' . ui_language::translate('with STARTTLS')),
            array('Incoming (POP3)', '995', 'SSL/TLS', '110 ' . ui_language::translate('with STARTTLS')),
            array('Outgoing (SMTP)', '587', 'STARTTLS', '465 ' . ui_language::translate('with SSL/TLS')),
        );
        $out = '<div class="wm-server"><span>' . self::t('Server for all of them') . '</span><code data-copy="' . $host . '">' . $host . '</code>' . $copy . '</div>'
            . '<table class="wm-table"><thead><tr><th></th><th>' . self::t('Port') . '</th><th>' . self::t('Security') . '</th><th>' . self::t('Or') . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $out .= '<tr><th>' . self::t($r[0]) . '</th><td><b>' . $r[1] . '</b></td><td>' . self::h($r[2]) . '</td><td class="wm-alt">' . self::h($r[3]) . '</td></tr>';
        }
        return $out . '</tbody></table>';
    }

}
