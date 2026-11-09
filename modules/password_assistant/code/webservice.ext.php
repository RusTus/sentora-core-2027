<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * @package zpanelx
 * @subpackage modules
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-06
 */

/**
 * Password Assistant web service: password reset for other programs (XMWS API).
 */
class webservice extends ws_xmws {

    /**
     * Resets a user's Sentora account password. Requires <uid> and <newpassword> tags.
     *
     * @return array<string, string> The response data object: 'response' and 'content' (<uid> and <reset> true or false).
     */
    function ResetUserPassword() {
        $contenttags = $this->XMLDataToArray($this->wsdata);
        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        // never an administrator's password (a leaked key must not take the panel over), and never
        // a password shorter than the panel itself allows
        $uid = ws_xmws::Account($contenttags['xmws']['content']['uid'] ?? '', true);
        $pass = $contenttags['xmws']['content']['newpassword'] ?? '';
        $min = max(8, (int) ctrl_options::GetSystemOption('password_minlength'));
        if ($uid === 0) {
            return ws_xmws::Error('1107', 'No such account, or an administrator (the API does not set administrators\' passwords)');
        }
        if (!is_string($pass) || strlen($pass) < $min) {
            return ws_xmws::Error('1107', 'The new password needs at least ' . $min . ' characters');
        }
        if (module_controller::UpdatePassword($uid, $pass)) {
            $dataobject->addItemValue('content', ws_xmws::NewXMLTag('uid', $contenttags['xmws']['content']['uid']) . ws_xmws::NewXMLTag('reset', 'true'));
        } else {
            $dataobject->addItemValue('content', ws_xmws::NewXMLTag('uid', $contenttags['xmws']['content']['uid']) . ws_xmws::NewXMLTag('reset', 'false'));
        }
        return $dataobject->getDataObject();
    }

}

?>
