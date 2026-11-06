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
 * Template tag <# ui_tpl_progbardisk #>: the disk space usage bar.
 */
class ui_tpl_progbardisk {

    /**
     * Builds a progress bar showing the disk space used against the quota.
     *
     * The bar turns red at 75%; the percentage is written in it from 10%.
     *
     * @return string The Bootstrap progress bar HTML (empty bar when the quota is unlimited).
     */
    public static function Template() {
        $currentuser = ctrl_users::GetUserDetail();
        $diskquota = $currentuser['diskquota'];
        $diskspace = ctrl_users::GetQuotaUsages('diskspace', $currentuser['userid']);

        if ($diskquota == 0) {
            return '<div class="progress progress-striped"><div class="progress-bar progress-bar-success" style="width: 0%"></div></div>';
        } else {
            if (fs_director::CheckForEmptyValue($diskspace)){
                $diskspace = 0;
            }
            $percent = round(($diskspace / $diskquota) * 100, 0);
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
