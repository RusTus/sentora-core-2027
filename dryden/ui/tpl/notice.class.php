<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Generic template place holder class.
 * @package zpanelx
 * @subpackage dryden -> ui -> tpl
 * @version 1.1.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
/**
 * Template tag <# ui_tpl_notice #>: the reseller's notice to their clients.
 */
class ui_tpl_notice {

    /**
     * Returns the notice the user's reseller set (Client Notice Manager) as an alert box.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string|false The alert HTML from ui_sysmessage::shout(), or false when the reseller has no notice.
     */
    public static function Template() {
        $user_array = ctrl_users::GetUserDetail();
        global $zdbh;
        $result = $zdbh->query("SELECT ac_notice_tx FROM x_accounts WHERE ac_id_pk = " . $user_array['resellerid'] . "")->Fetch();
        if ($result) {
            if ($result['ac_notice_tx'] <> "")
                return ui_sysmessage::shout(
                    runtime_xss::xssClean($result['ac_notice_tx']),
                    'notice',
                    'Notice:',
                    true
                );
            return false;
        } else {
            return false;
        }
    }

}

?>
