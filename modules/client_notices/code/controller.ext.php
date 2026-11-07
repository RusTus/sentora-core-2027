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

/**
 * Client Notices module: lets a reseller (or the admin) set the notice shown at the top of the
 * panel to its clients. A notice is plain text (see ui_tpl_notice::Format()); the page shows a
 * live preview of how it looks.
 */
class module_controller extends ctrl_module
{

    /**
     * The longest notice, in characters.
     */
    const MAX_LENGTH = 2000;

    /**
     * Result of the last action: 'saved', 'cleared' or 'toolong' ('' for none).
     *
     * @var string
     */
    static $result = '';

    /**
     * The text posted with a refused save, so it is not lost.
     *
     * @var string|null
     */
    static $posted = null;

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
     * Save an account's notice text.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param string $notice The notice text ('' for none).
     * @return bool Always true.
     */
    static function ExectuteUpdateNotice($uid, $notice)
    {
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_accounts SET ac_notice_tx = :notice WHERE ac_id_pk = :uid");
        $sql->execute(array(':notice' => (string) $notice, ':uid' => (int) $uid));
        return true;
    }

    /**
     * An account's notice as plain text (a notice from the old HTML editor is read as text).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $rid The account ID.
     * @return string|false The notice, or false when the account is not found.
     */
    static function ExecuteShowNotice($rid)
    {
        global $zdbh;
        $sql = $zdbh->prepare("SELECT ac_notice_tx FROM x_accounts WHERE ac_id_pk = :rid");
        $sql->execute(array(':rid' => (int) $rid));
        $result = $sql->fetch();
        return $result ? ui_tpl_notice::Text($result['ac_notice_tx']) : false;
    }

    // End 'worker' methods.

    /**
     * The logged-in account's own notice: the one its clients see.
     *
     * @return string The text ('' for none).
     */
    static function Own()
    {
        $n = self::ExecuteShowNotice(ctrl_users::GetUserDetail()['userid']);
        return $n === false ? '' : $n;
    }

    /**
     * The notice in the editor (<@ CurrentNoticeText @>), escaped: the account's own, or the text of a refused save.
     *
     * Before, this showed the notice of the account's own reseller, while saving changed the
     * account's own, so a reseller never saw the notice it had set.
     *
     * @return string The escaped text.
     */
    static function getCurrentNoticeText()
    {
        return self::h(self::$posted !== null ? self::$posted : self::Own());
    }

    /**
     * The notice as clients see it (<@ Preview @>).
     *
     * @return string The HTML ('' for none).
     */
    static function getPreview()
    {
        return ui_tpl_notice::Format(self::$posted !== null ? self::$posted : self::Own());
    }

    /**
     * Template flag <% if HasNotice %>: a notice is set.
     *
     * @return bool
     */
    static function getHasNotice()
    {
        return self::Own() !== '';
    }

    /**
     * The longest notice (<@ MaxLength @>).
     *
     * @return int
     */
    static function getMaxLength()
    {
        return self::MAX_LENGTH;
    }

    /**
     * Who sees the notice (<@ Audience @>): the number of the account's clients, and the admin itself.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML.
     */
    static function getAudience()
    {
        global $zdbh;
        $me = ctrl_users::GetUserDetail();
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_reseller_fk = :u AND ac_id_pk <> :u2 AND ac_deleted_ts IS NULL");
        $q->execute(array(':u' => (int) $me['userid'], ':u2' => (int) $me['userid']));
        $n = (int) $q->fetchColumn();
        $self = (int) $me['resellerid'] === (int) $me['userid'];
        $who = $n === 1 ? self::t('your 1 client') : ($n === 0 ? self::t('none of your clients yet: you have none') : self::t('your') . ' ' . $n . ' ' . self::t('clients'));
        return '<b>' . $n . '</b><span>' . self::t('Shown to') . ' ' . $who . ($self ? ' ' . self::t('and to you') : '') . '</span>';
    }

    /**
     * The message after an action (<@ Result @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML ('' for none).
     */
    static function getResult()
    {
        global $controller;
        $r = self::$result;
        if ($r === '') {
            $r = $controller->GetControllerRequest('URL', 'saved') ? 'saved' : ($controller->GetControllerRequest('URL', 'cleared') ? 'cleared' : '');
        }
        switch ($r) {
            case 'saved':
                return ui_sysmessage::shout(ui_language::translate('The notice is saved. Your clients see it on their next page.'), 'zannounceok');
            case 'cleared':
                return ui_sysmessage::shout(ui_language::translate('The notice is removed. Your clients no longer see it.'), 'zannounceok');
            case 'toolong':
                return ui_sysmessage::shout(ui_language::translate('The notice is too long: it may have at most') . ' ' . self::MAX_LENGTH . ' ' . ui_language::translate('characters.'), 'zannounceerror');
        }
        return '';
    }

    /**
     * Save the posted notice for the current user (or remove it with inClear), then redirect back and exit.
     *
     * A notice longer than MAX_LENGTH is refused and stays in the editor.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|void False when the notice is too long; otherwise ends with a redirect header and exit.
     */
    static function doUpdateMessage()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        $clear = !empty($formvars['inClear']);
        $notice = $clear ? '' : trim(str_replace(array("\r\n", "\r"), "\n", (string) (isset($formvars['inNotice']) ? $formvars['inNotice'] : '')));
        if (mb_strlen($notice, 'UTF-8') > self::MAX_LENGTH) {
            self::$result = 'toolong';
            self::$posted = $notice;
            return false;
        }
        self::ExectuteUpdateNotice($currentuser['userid'], $notice);
        header("location: ./?module=" . $controller->GetCurrentModule() . ($notice === '' ? '&cleared=true' : '&saved=true'));
        exit;
    }

    // Webinterface sudo methods.
}
