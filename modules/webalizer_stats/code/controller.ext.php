<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - Visitor Stats zpanel plugin, written by RusTus: www.zpanelcp.com.
 *
 */
class module_controller extends ctrl_module
{

    static function ListDomains($uid)
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $currentuser['userid']);
            $res = array();
            $sql->execute();
            while ($rowclients = $sql->fetch()) {
                array_push($res, array('vh_id_pk' => $rowclients['vh_id_pk'],
                    'vh_name_vc' => $rowclients['vh_name_vc']));
            }
            return $res;
        } else {
            return false;
        }
    }

    //
    static function getIsValidDomain($domain)
    {
        foreach ( (array) self::getDomains() as $key => $value ) {
            if( (string) $domain === (string) $value['vh_name_vc'] ){
                return true;
            }
        }
        return false;
    }

    static function getDomains()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $clientlist = self::ListDomains($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($clientlist)) {
            return $clientlist;
        } else {
            return false;
        }
    }

    static function getCurrentDomain()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['domain'])) && ($urlvars['domain'] != ""))
            return htmlspecialchars($urlvars['domain'], ENT_QUOTES, 'UTF-8');
        return false;
    }

    static function getReportToShow()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (isset($urlvars['domain']) && $urlvars['domain'] != "") {
            if (self::getIsValidDomain($urlvars['domain']) == true) {
                $currentuser = ctrl_users::GetUserDetail();
                // Served by code/report.php (owner only); the files are outside the web root
                if (!is_file('/var/sentora/stats/webalizer/' . $currentuser['username'] . '/' . $urlvars['domain'] . '/index.html')) {
                    return false;
                }
                return 'modules/webalizer_stats/code/report.php/' . rawurlencode($urlvars['domain']) . '/index.html';
            } else {
                header("location: ./?module=" . $controller->GetCurrentModule());
                exit;
            }
        }
        return false;
    }

    static function doShowStats()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if ( isset($formvars['inDomain']) && self::getIsValidDomain($formvars['inDomain']) == true) {
            header("location: ./?module=" . $controller->GetCurrentModule() . "&show=true&domain=" . $formvars['inDomain'] . "");
            exit;
        } else {
            return false;
        }
    }

    static function getIsShowStats()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "true"))
            return true;
        return false;
    }

    static function getInit()
    {
        
    }

}
