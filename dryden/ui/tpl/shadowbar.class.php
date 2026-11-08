<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Generic template place holder class.
 * @package zpanelx
 * @subpackage dryden -> ui -> tpl
 * @version 1.0.1
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @Added shadowed account username - TGates 2024-01-20
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-07
 */
/**
 * Template tag <# ui_tpl_shadowbar #>: the bar shown while shadowing, with the way back.
 */
class ui_tpl_shadowbar {

    /**
     * Returns the bar with the button to end shadowing, when an admin or reseller is shadowing a user.
     *
     * @return string|false The bar HTML, or false when the session is not a shadow session.
     */
    public static function Template() {
        if (empty($_SESSION['ruid'])) {
            return false;
        }
        $h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        $as = ctrl_users::GetUserDetail();
        $me = ctrl_users::GetUserDetail((int) $_SESSION['ruid']);
        return '<div class="zshadowbar" id="zshadowbar" role="status"><svg class="zshadowbar-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>'
            . '<span>' . $h(ui_language::translate('You are logged in as')) . ' <b>' . $h($as['username']) . '</b>: ' . $h(ui_language::translate('what you change here, you change for this client.')) . '</span>'
            . '<a class="btn btn-sm zshadowbar-back" href="./?returnsession=true">' . $h(ui_language::translate('Back to')) . ' ' . $h($me['username']) . '</a></div>';
    }

}

?>