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
class module_controller extends ctrl_module
{

    /** @var bool|null Not used in this module. */
    static $complete;
    /** @var bool|null True when the domain starts with www. */
    static $error;
    /** @var bool|null True when the vhost file could not be written (never set in this module). */
    static $writeerror;
    /** @var bool|null True when the domain looks like a subdomain of an existing domain. */
    static $nosub;
    /** @var bool|null True when the domain already exists on the server. */
    static $alreadyexists;
    /** @var bool|null True when the domain name is not valid. */
    static $badname;
    /** @var bool|null True when the domain was left blank. */
    static $blank;
    /** @var bool|null True after a parked domain change was saved (shown by getResult()). */
    static $ok;

    // The 'worker' methods.
    /**
     * List the account's parked domains.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return array<int, array<string, mixed>>|false One row per domain: 'name', 'directory', 'active', 'created' (timestamp), 'id'; false when there are none.
     */
    static function ListParkedDomains($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL AND vh_type_in=3 ORDER BY vh_name_vc ASC";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            while ($rowdomains = $sql->fetch()) {
                array_push($res, array('name' => $rowdomains['vh_name_vc'],
                    'directory' => $rowdomains['vh_directory_vc'],
                    'active' => $rowdomains['vh_active_in'],
                    'created' => $rowdomains['vh_created_ts'],
                    'id' => $rowdomains['vh_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List all of the account's vhosts (domains, subdomains and parked domains).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return array<int, array<string, mixed>>|false One row per vhost: 'name', 'directory', 'active', 'created', 'id'; false when there are none.
     */
    static function ListCurrentDomain($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            while ($rowdomains = $sql->fetch()) {
                array_push($res, array('name' => $rowdomains['vh_name_vc'],
                    'directory' => $rowdomains['vh_directory_vc'],
                    'active' => $rowdomains['vh_active_in'],
                    'created' => $rowdomains['vh_created_ts'],
                    'id' => $rowdomains['vh_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Mark a parked domain deleted and flag the Apache config for rewriting.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $id The vhost ID.
     * @return true Always true.
     */
    static function ExecuteDeleteParkedDomain($id)
    {
        global $zdbh;
		// Delete Domain
        runtime_hook::Execute('OnBeforeDeleteParkedDomain');
        $sql = $zdbh->prepare("UPDATE x_vhosts
							   SET vh_deleted_ts=:time
							   WHERE vh_id_pk=:id");
        $sql->bindParam(':id', $id);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        self::SetWriteApacheConfigTrue();
        $retval = TRUE;
        runtime_hook::Execute('OnAfterDeleteParkedDomain');
        return $retval;
    }

    /**
     * Add a parked domain after checking it.
     *
     * Inserts the x_vhosts row (type 3) and flags the Apache config for rewriting.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param string $domain The domain name (spaces are removed, lowercased).
     * @return true|null True when added, null when a check failed.
     */
    static function ExecuteAddParkedDomain($uid, $domain)
    {
        global $zdbh;
        $retval = FALSE;
        runtime_hook::Execute('OnBeforeAddParkedDomain');
        $currentuser = ctrl_users::GetUserDetail($uid);
        $domain = strtolower(str_replace(' ', '', $domain));
        if (!fs_director::CheckForEmptyValue(self::CheckCreateForErrors($domain))) {
            // If all has gone well we need to now create the domain in the database...
            $sql = $zdbh->prepare("INSERT INTO x_vhosts (vh_acc_fk,
														 vh_name_vc,
														 vh_directory_vc,
														 vh_type_in,
														 vh_created_ts) VALUES (
														 :userid,
														 :domain,
														 '',
														 3,
														 :time)");
            $sql->bindParam(':userid', $currentuser['userid']);
            $sql->bindParam(':domain', $domain);
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->execute();
            // Only run if the Server platform is Windows.
            if (sys_versions::ShowOSPlatformVersion() == 'Windows') {
                if (ctrl_options::GetSystemOption('disable_hostsen') == 'false') {
                    // Lets add the hostname to the HOSTS file so that the server can view the domain immediately...
                    @exec("C:/Sentora/bin/zpss/setroute.exe " . $domain . "");
                    @exec("C:/Sentora/bin/zpss/setroute.exe www." . $domain . "");
                }
            }
            self::SetWriteApacheConfigTrue();
            $retval = TRUE;
            runtime_hook::Execute('OnAfterAddParkedDomain');
            return $retval;
        }
    }

    /**
     * Check a new parked domain; sets the matching error flag on the first problem.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $domain The domain name.
     * @return bool True when the domain can be added.
     */
    static function CheckCreateForErrors($domain)
    {
        global $zdbh;
        // Check for spaces and remove if found...
        $domain = strtolower(str_replace(' ', '', $domain));
        // Check to make sure the domain is not blank before we go any further...
        if ($domain == '') {
            self::$blank = TRUE;
            return FALSE;
        }
        // Check for invalid characters in the domain...
        if (!self::IsValidDomainName($domain)) {
            self::$badname = TRUE;
            return FALSE;
        }
        // Check to make sure the domain is in the correct format before we go any further...
        if (strpos($domain, 'www.') === 0) {
            self::$error = TRUE;
            return FALSE;
        }
        // Check to see if the domain already exists in Sentora somewhere and redirect if it does....
        $sql = "SELECT COUNT(*) FROM x_vhosts WHERE vh_name_vc=:domain AND vh_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':domain', $domain);

        if ($numrows->execute()) {
            if ($numrows->fetchColumn() > 0) {
                self::$alreadyexists = TRUE;
                return FALSE;
            }
        }
        // Check to make sure user not adding a subdomain and blocks stealing of subdomains....
        // Get shared domain list
        $SharedDomains = array();
        $a = ctrl_options::GetSystemOption('shared_domains');
        $a = explode(',', $a);
        foreach ($a as $b) {
            $SharedDomains[] = $b;
        }
        if (substr_count($domain, ".") > 1) {
            $part = explode('.', $domain);
            foreach ($part as $check) {
                if (!in_array($check, $SharedDomains)) {
                    if (strlen($check) > 3) {
                        $sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc LIKE :Checked AND vh_type_in !=2 AND vh_deleted_ts IS NULL");
                        $Checked = '%' . $check . '%';
                        $sql->bindParam(':Checked', $Checked);
                        $sql->execute();
                        while ($rowcheckdomains = $sql->fetch()) {
                            $subpart = explode('.', $rowcheckdomains['vh_name_vc']);
                            foreach ($subpart as $subcheck) {
                                if (strlen($subcheck) > 3) {
                                    if ($subcheck == $check) {
                                        if (substr($domain, -7) == substr($rowcheckdomains['vh_name_vc'], -7)) {
                                            self::$nosub = TRUE;
                                            return FALSE;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return TRUE;
    }

    /**
     * Check whether a number is a known HTTP status code.
     *
     * @param int|string $error The status code.
     * @return bool
     */
    static function CheckErrorDocument($error)
    {
        $errordocs = array(100, 101, 102, 200, 201, 202, 203, 204, 205, 206, 207,
            300, 301, 302, 303, 304, 305, 306, 307, 400, 401, 402,
            403, 404, 405, 406, 407, 408, 409, 410, 411, 412, 413,
            414, 415, 416, 417, 418, 419, 420, 421, 422, 423, 424,
            425, 426, 500, 501, 502, 503, 504, 505, 506, 507, 508,
            509, 510);
        return in_array($error, $errordocs);
    }

    /**
     * Check that a string is a valid domain name (at least one dot, valid labels).
     *
     * @param string $a The domain name.
     * @return bool
     */
    static function IsValidDomainName($a)
    {
        if (stristr($a, '.')) {
            $part = explode(".", $a);
            foreach ($part as $check) {
                if (!preg_match('/^[a-z\d][a-z\d-]{0,62}$/i', $check) || preg_match('/-$/', $check)) {
                    return false;
                }
            }
        } else {
            return false;
        }
        return true;
    }

    /**
     * Check that a string is a valid email address.
     *
     * @param string $email The email address.
     * @return bool
     */
    static function IsValidEmail($email)
    {
        return preg_match('/^[a-z0-9]+([_\\.-][a-z0-9]+)*@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i', $email) == 1;
    }

    /**
     * Flag the Apache config for rewriting by the daemon (apache_changed option).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function SetWriteApacheConfigTrue()
    {
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_settings
								SET so_value_tx='true'
								WHERE so_name_vc='apache_changed'");
        $sql->execute();
    }

    // End 'worker' methods.

    // Webinterface sudo methods.
    /**
     * Template loop: the logged-in account's parked domains.
     *
     * @return array<int, array<string, mixed>>|false One row per domain: 'name', 'directory', 'active', 'status' (HTML), 'created' (formatted date), 'id'; false when there are none.
     */
    static function getParkedDomainList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $res = array();
        $parkeddomains = self::ListParkedDomains($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($parkeddomains)) {
            foreach ($parkeddomains as $row) {
                $status = self::getParkedDomainStatusHTML($row['active'], $row['id']);
                $created = date(ctrl_Options::GetSystemOption('sentora_df'), $row['created']);
                $res[] = array('name' => $row['name'],
                    'directory' => $row['directory'],
                    'active' => $row['active'],
                    'status' => $status,
                    'created' => $created,
                    'id' => $row['id']);
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Template flag: the account may add another parked domain (unlimited or under quota).
     *
     * @return bool
     */
    static function getCreateParkedDomain()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['parkeddomainquota'] < 0) or //-1 = unlimited
                ($currentuser['parkeddomainquota'] > ctrl_users::GetQuotaUsages('parkeddomains', $currentuser['userid']));
    }

    /**
     * Handle the add parked domain form POST.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when the domain was added.
     */
    static function doCreateParkedDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteAddParkedDomain($currentuser['userid'], $formvars['inDomain'])) {
            self::$ok = TRUE;
            return true;
        } else {
            return false;
        }
        return;
    }

    /**
     * Handle the delete confirmation POST for one of the account's parked domains.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when the domain was deleted.
     */
    static function doDeleteParkedDomain()
    {
        global $controller;
        runtime_csfr::Protect();
//        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inDelete']) || !ctrl_ownership::Owns('x_vhosts', 'vh_id_pk', 'vh_acc_fk', $formvars['inDelete'], ' AND vh_type_in = 3 AND vh_deleted_ts IS NULL')) {
            return false;
        }
        if (isset($formvars['inDelete'])) {
            if (self::ExecuteDeleteParkedDomain($formvars['inDelete'])) {
                self::$ok = TRUE;
                return true;
            }
        }
        return false;
    }

    /**
     * Handle the list POST: redirect to the delete confirmation and exit.
     *
     * @global runtime_controller $controller The request controller.
     * @return false False when no delete button was sent (otherwise it exits).
     */
    static function doConfirmDeleteParkedDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListParkedDomains($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['id'] . ''])) {
                header('location: ./?module=' . $controller->GetCurrentModule() . '&show=Delete&id=' . $row['id'] . '&domain=' . $row['name']);
                exit;
            }
        }
        return false;
    }

    /**
     * Template flag: show the delete confirmation for an own vhost (?show=Delete&id=<id>).
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|null $uid Optional. The account ID; null for the logged-in account. Default null.
     * @return bool|null Null when the vhost is not the account's own.
     */
    static function getisDeleteDomain($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete Parked Domains.
        // This shall avoid exposing Parked Domains based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

    	$sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_id_pk=:editedUsrID AND vh_deleted_ts IS NULL";
    	$numrows = $zdbh->prepare($sql);
    	$numrows->bindParam(':userid', $currentuser['userid']);
		$numrows->bindParam(':editedUsrID', $urlvars['id']);
    	$numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }

        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Delete");
    }

    /**
     * Template value: the ?id= of the URL.
     *
     * @global runtime_controller $controller The request controller.
     * @return string The ID, or ''.
     */
    static function getCurrentID()
    {
        global $controller;
        $id = $controller->GetControllerRequest('URL', 'id');
        return ($id) ? $id : '';
    }

    /**
     * Template value: the ?domain= of the URL.
     *
     * @global runtime_controller $controller The request controller.
     * @return string The domain, or ''.
     */
    static function getCurrentDomain()
    {
        global $controller;
        $domain = $controller->GetControllerRequest('URL', 'domain');
        return ($domain) ? $domain : '';
    }

    /**
     * Template HTML: the parked domain quota pie chart, or the "unlimited" image.
     *
     * @return string HTML img tag.
     */
    static function getParkedDomainUsagepChart()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $maximum = $currentuser['parkeddomainquota'];
        if ($maximum < 0) { //-1 = unlimited
            return '<img src="' . ui_template::AssetPath('img/misc/unlimited.png') . '" alt="' . ui_language::translate('Unlimited') . '"/>';
        } else {
            $used = ctrl_users::GetQuotaUsages('parkeddomains', $currentuser['userid']);
            $free = max($maximum - $used, 0);
            return '<img src="etc/lib/pChart2/sentora/z3DPie.php?score=' . $free . '::' . $used
                    . '&labels=Free: ' . $free . '::Used: ' . $used
                    . '&legendfont=verdana&legendfontsize=8&imagesize=240::190&chartsize=120::90&radius=100&legendsize=150::160"'
                    . ' alt="' . ui_language::translate('Pie chart') . '"/>';
        }
    }

    /**
     * Build the two status table cells (Live or Pending with a help icon) of a parked domain.
     *
     * @global runtime_controller $controller The request controller.
     * @param int|string $int The vh_active_in value (1 = live).
     * @param int|string $id The vhost ID.
     * @return string HTML (two td cells).
     */
    static function getParkedDomainStatusHTML($int, $id)
    {
        global $controller;
        if ($int == 1) {
            return '<td><font color="green">' . ui_language::translate('Live') . '</font></td>'
                    . '<td></td>';
        } else {
            return '<td><font color="orange">' . ui_language::translate("Pending") . '</font></td>'
                    . '<td><a href="#" class="help_small" id="help_small_' . $id . '_a"'
                    . 'title="' . ui_language::translate('Your domain will become active at the next scheduled update.  This can take up to one hour.') . '">'
                    . '<img src="/modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/help_small.png" border="0" /></a></td>';
        }
    }

    /**
     * Template HTML: the error or success message of the last action.
     *
     * @return string|null HTML message, or null when there is nothing to show.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return ui_sysmessage::shout(ui_language::translate("Your Domain can not be empty. Please enter a valid Domain Name and try again."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return ui_sysmessage::shout(ui_language::translate("Your Domain name is not valid. Please enter a valid Domain Name: i.e. 'domain.com'"), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("The domain already appears to exsist on this server."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$error)) {
            return ui_sysmessage::shout(ui_language::translate("Please remove 'www'. The 'www' will automatically work with all Domains / Subdomains."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$writeerror)) {
            return ui_sysmessage::shout(ui_language::translate("There was a problem writting to the virtual host container file. Please contact your administrator and report this error. Your domain will not function until this error is corrected."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your domain web hosting has been saved successfully."), "zannounceok");
        }
        return;
    }

    // Webinterface sudo methods.
}
