<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Generic template place holder class.
 * @package zpanelx
 * @subpackage dryden -> ui -> tpl
 * @version 1.1.0
 * @author Jason Davis (jason.davis.fl@gmail.com)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
/**
 * Template tag <# ui_tpl_clientdomains #>: the user's domains overview.
 */
class ui_tpl_clientdomains {

    /**
     * Builds the dashboard overview of the user's first domains, sub-domains and parked domains.
     *
     * Shows up to 4 of each kind with their status.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML stats rows.
     */
    public static function Template() {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail();
        $user = $currentuser;
        $domain_limit = 4;

        /* Domains */
        $line = self::getDomains('domain', $domain_limit, $currentuser, $zdbh);

        /* Sub Domains */
        $line .= self::getDomains('subdomain', $domain_limit, $currentuser, $zdbh);

        /* Parked Domains */
        $line .= self::getDomains('parkeddomain', $domain_limit, $currentuser, $zdbh);

        return $line;
    }


    /**
     * Builds the overview rows for one kind of domain a user owns.
     *
     * Lists up to $domain_limit domains with their status (Active / Pending / Disabled),
     * or a "No ... Found" row with an "Add New" link when there are none.
     *
     * @param  string  $type         'domain', 'subdomain' or 'parkeddomain'.
     * @param  int     $domain_limit Number of domains to list (Template() passes 4).
     * @param  array<string,mixed> $user The user's details from ctrl_users::GetUserDetail() (uses 'userid').
     * @param  db_driver $db         The panel's database connection.
     * @return string                The HTML stats rows.
     */
    public static function getDomains($type, $domain_limit, $user, $db){

        $zdbh = $db;

        switch ($type) {
            case 'domain':
                $domain_type = 1;
                $name = 'Domain';
                $domain_url = 'domains';
                break;
            case 'subdomain':
                $domain_type = 2;
                $name = 'Sub-Domain';
                $domain_url = 'sub_domains';
                break;
            case 'parkeddomain':
                $domain_type = 3;
                $name = 'Parked Domain';
                $domain_url = 'parked_domains';
                break;
        }

        /* Domains */
        $sql = 'SELECT * FROM x_vhosts WHERE vh_acc_fk= :userid AND vh_type_in=' .$domain_type. ' AND vh_deleted_ts IS NULL ORDER BY vh_id_pk LIMIT '.$domain_limit;

        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $user['userid']);
        $numrows->execute();
        $line = '';
        
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $user['userid']);
            $sql->execute();
            $limit = 0;

            if($domain_type == 1){
                $line .= '<div class="stats-row s-top">';
            }else{
                $line .= '<div class="stats-row">';
            }
            $line .= '    <div class="stats-column"><strong>'.$name.'s</strong></div>';
            $line .= '    <div class="stats-column"><strong>Status</strong></div>';
            $line .= '    <div class="stats-column"><a href="?module='.$domain_url.'">(Add New '.$name.')</a></div>';
            $line .= '</div><!--end stats-row-->';
            while ($rowdomains = $sql->fetch()) {
                if ($rowdomains['vh_type_in'] == $domain_type) {

                    $line .= '<div class="stats-row">';
                    $line .= '  <div class="stats-column"><a href="http://'.$rowdomains['vh_name_vc'] . '" target="_blank">' . $rowdomains['vh_name_vc'] . '</a></div>';


                    if ($rowdomains['vh_active_in'] == 1 && $rowdomains['vh_enabled_in'] == 1) {
                        $line .= '<div class="stats-column"><span class="label label-success">Active</span></div>';
                    } elseif ($rowdomains['vh_active_in'] == 0 && $rowdomains['vh_enabled_in'] == 1) {
                        $line .= '<div class="stats-column"><span class="label label-warning">Pending</span></div>';
                    } else {
                        $line .= '';
                    }
                    if ($rowdomains['vh_enabled_in'] == 0) {
                        $line .= '<div class="stats-column"><span class="label label-danger">Disabled</span></div>';
                    }

                    $line .= '</div><!--end stats-row-->';
                }
                $limit++;
            }
            if ($limit >= $domain_limit) {

                $line .= '<div class="stats-row">';
                $line .= '    <div class="stats-column"><a href="?module='.$domain_url.'">(View all '.$name.'s)</a></div>';
                $line .= '</div><!--end stats-row-->';

            }
        } else {

            if($domain_type == 1){
                $line .= '<div class="stats-row s-top">';
            }else{
                $line .= '<div class="stats-row">';
            }
            $line .= '    <div class="stats-column"><strong>'.$name.'s</strong></div>';
            $line .= '</div><!--end stats-row-->';
            $line .= '<div class="stats-row">';
            $line .= '    <div class="stats-column"><span class="Side_Info_None">No '.$name.'s Found</span></div>';
            $line .= '    <div class="stats-column"><a href="?module='.$domain_url.'">Add New '.$name.'</a></div>';
            $line .= '</div><!--end stats-row-->';
        }

        return $line;
    }
}

?>