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
 * Template tag <# ui_tpl_progbarbandwidth #>: the bandwidth usage bar.
 */
class ui_tpl_progbarbandwidth {

    /**
     * Builds a progress bar showing this month's bandwidth use against the quota.
     *
     * The bar turns red at 75%; the percentage is written in it from 10%.
     *
     * @return string The Bootstrap progress bar HTML (empty bar when the quota is unlimited).
     */
    public static function Template() {
        $currentuser = ctrl_users::GetUserDetail();
        $bandwidthquota = $currentuser['bandwidthquota'];
        $bandwidth = ctrl_users::GetQuotaUsages('bandwidth', $currentuser['userid']);

        if ($bandwidthquota == 0) {
            return '<div class="progress progress-striped"><div class="progress-bar progress-bar-success" style="width: 0%"></div></div>';
        } else {
            if (fs_director::CheckForEmptyValue($bandwidth)){
                $bandwidth = 0;
            }
            $percent = round(($bandwidth / $bandwidthquota) * 100, 0);
            if($percent >= 75){
                $bar = 'danger';
            }else{
                $bar = 'success';
            }
            if($percent >= 10){
                $showpercent = $percent.'%';
            }else{
                $showpercent = '';
            }
            return '<div class="progress progress-striped"><div class="progress-bar progress-bar-'.$bar.'" style="width: ' . $percent . '%">' . $showpercent . '</div></div>';
        }
    }

}

?>
