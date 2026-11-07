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
 * Sub-domains module: list, create and delete the account's subdomains and choose their PHP version.
 */
require_once dirname(__FILE__) . '/../../domains/code/domainsui.php';

class module_controller extends ctrl_module
{

    /** @var bool|null Not used. */
    static $complete;
    /** @var bool|null True when the subdomain started with 'www.' (shown by getResult()). */
    static $error;
    /** @var bool|null True when the vhost file could not be written (shown by getResult(); never set here). */
    static $writeerror;
    /** @var bool|null Not used. */
    static $nosub;
    /** @var bool|null True when the domain already exists on this server (shown by getResult()). */
    static $alreadyexists;
    /** @var bool|null True when the domain name is not valid (shown by getResult()). */
    static $badname;
    /** @var bool|null True when no domain name was entered (shown by getResult()). */
    static $blank;
    /** @var bool|null True when the parent domain is not one of the account's own (shown by getResult()). */
    static $notowner;

    /** @var bool|null True when the last change was saved (shown by getResult()). */
    static $ok;

    // The 'worker' methods.

    /**
     * List an account's live subdomains, by name.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return array<int, array<string, mixed>>|false Rows with 'subname', 'subdirectory', 'subactive', 'subphp', 'subid'; false when there are none.
     */
    static function ListSubDomains($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL AND vh_type_in=2 ORDER BY vh_name_vc ASC";
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
                array_push($res, array('subname' => $rowdomains['vh_name_vc'],
                    'subdirectory' => $rowdomains['vh_directory_vc'],
                    'subactive' => $rowdomains['vh_active_in'],
                    'subphp' => isset($rowdomains['vh_php_version_vc']) ? (string) $rowdomains['vh_php_version_vc'] : '',
                    'subid' => $rowdomains['vh_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List an account's live domains (type 1), by name.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return array<int, array<string, mixed>>|false Rows with 'name', 'directory', 'active', 'id'; false when there are none.
     */
    static function ListDomains($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL AND vh_type_in=1 ORDER BY vh_name_vc ASC";
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
                    'id' => $rowdomains['vh_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the folders in an account's public_html (except _errorpages).
     *
     * @global runtime_controller $controller The request controller (not used).
     * @param int|string $uid The account ID.
     * @return array<int, array<string, string>> Rows with 'domains' (the folder name); empty when the folder can not be opened.
     */
    static function ListDomainDirs($uid)
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $res = array();
        $handle = @opendir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/public_html");
        $chkdir = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/public_html/";
        if (!$handle) {
            // Log an error as the folder cannot be opened...
        } else {
            while ($file = @readdir($handle)) {
                if ($file != "." && $file != ".." && $file != "_errorpages") {
                    if (is_dir($chkdir . $file)) {
                        array_push($res, array('domains' => $file));
                    }
                }
            }
            closedir($handle);
        }
        return $res;
    }

    /**
     * Mark a subdomain as deleted and flag the Apache configuration for rewriting.
     *
     * Runs the OnBeforeDeleteSubDomain and OnAfterDeleteSubDomain hooks. Does not
     * check who owns the subdomain.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $id The x_vhosts ID.
     * @return bool Always true.
     */
    static function ExecuteDeleteSubDomain($id)
    {
        global $zdbh;
		// Delete Domain
        runtime_hook::Execute('OnBeforeDeleteSubDomain');
        $sql = $zdbh->prepare("UPDATE x_vhosts
							   SET vh_deleted_ts=:time
							   WHERE vh_id_pk=:id");
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':id', $id);
        $sql->execute();
        self::SetWriteApacheConfigTrue();
        $retval = TRUE;
        runtime_hook::Execute('OnAfterDeleteSubDomain');
        return $retval;
    }

    /**
     * Create a subdomain: its folder, error pages, welcome page and x_vhosts record.
     *
     * Runs the OnBeforeAddSubDomain and OnAfterAddSubDomain hooks and flags the
     * Apache configuration for rewriting.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @param string $domain The full subdomain name (spaces removed, lower-cased).
     * @param string $destination An existing folder in public_html (ignored with $autohome).
     * @param int|string $autohome 1 to make a new folder named after the subdomain.
     * @param string $phpversion Optional. PHP version ('' for the default). Default ''.
     * @return bool|null True when created; false when it is not under one of the account's own domains or the folder is not valid; null when CheckCreateForErrors() failed.
     */
    static function ExecuteAddSubDomain($uid, $domain, $destination, $autohome, $phpversion = '')
    {
        global $zdbh;
        $retval = FALSE;
        runtime_hook::Execute('OnBeforeAddSubDomain');
        $currentuser = ctrl_users::GetUserDetail($uid);
        $domain = strtolower(str_replace(' ', '', $domain));
        // Only under one of the account's own domains (the form and the API both come here)
        $under = false;
        foreach (self::ListDomains($uid) ?: array() as $d) {
            $parent = strtolower((string) $d['name']);
            if (strlen($domain) > strlen($parent) + 1 && substr($domain, -strlen($parent) - 1) === '.' . $parent) {
                $under = true;
            }
        }
        if (!$under) {
            self::$notowner = true;
            return false;
        }
        if (!fs_director::CheckForEmptyValue(self::CheckCreateForErrors($domain))) {
            //** New Home Directory **//
            if ($autohome == 1) {
                $destination = "/" . str_replace(".", "_", $domain);
                $vhost_path = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/public_html/" . $destination . "/";
                fs_director::CreateDirectory($vhost_path);
                //** Existing Home Directory **//
            } else {
                // An existing folder inside the account's public_html only
                $destination = ctrl_ownership::SafeSubFolder($destination);
                if ($destination === false || $destination === '') {
                    return false;
                }
                $destination = "/" . $destination;
                $vhost_path = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/public_html/" . $destination . "/";
            }
            // Error documents:- Error pages are added automatically if they are found in the _errorpages directory
            // and if they are a valid error code, and saved in the proper format, i.e. <error_number>.html
            fs_director::CreateDirectory($vhost_path . "/_errorpages/");
            $errorpages = ctrl_options::GetSystemOption('static_dir') . "/errorpages/";
            if (is_dir($errorpages)) {
                if ($handle = @opendir($errorpages)) {
                    while (($file = @readdir($handle)) !== false) {
                        if ($file != "." && $file != "..") {
                            $page = explode(".", $file);
                            if (!fs_director::CheckForEmptyValue(self::CheckErrorDocument($page[0]))) {
                                fs_filehandler::CopyFile($errorpages . $file, $vhost_path . '/_errorpages/' . $file);
                            }
                        }
                    }
                    closedir($handle);
                }
            }
            // Lets copy the default welcome page across...
            if ((!file_exists($vhost_path . "/index.html")) && (!file_exists($vhost_path . "/index.php")) && (!file_exists($vhost_path . "/index.htm"))) {
                fs_filehandler::CopyFileSafe(ctrl_options::GetSystemOption('static_dir') . "pages/welcome.html", $vhost_path . "/index.html");
            }
            // If all has gone well we need to now create the domain in the database...
            $sql = $zdbh->prepare("INSERT INTO x_vhosts (vh_acc_fk,
														 vh_name_vc,
														 vh_directory_vc,
														 vh_type_in,
														 vh_php_version_vc,
														 vh_created_ts) VALUES (
														 :userid,
														 :domain,
														 :destination,
														 2,
														 :phpversion,
														 :time)"); //CLEANER FUNCTION ON $domain and $homedirectory_to_use (Think I got it?)
            $sql->bindParam(':userid', $currentuser['userid']);
            $sql->bindParam(':domain', $domain);
            $sql->bindParam(':destination', $destination);
            // '' = the default PHP; anything else must be an enabled extra version
            $phpversion = sys_phpversions::Clean($phpversion);
            $phpversion = ($phpversion === '') ? null : $phpversion;
            $sql->bindParam(':phpversion', $phpversion);
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->execute();
            // Only run if the Server platform is Windows.
            if (sys_versions::ShowOSPlatformVersion() == 'Windows') {
                if (ctrl_options::GetSystemOption('disable_hostsen') == 'false') {
                    // Lets add the hostname to the HOSTS file so that the server can view the domain immediately...
                    @exec("C:/Sentora/bin/zpss/setroute.exe " . $domain . "");
                }
            }
            self::SetWriteApacheConfigTrue();
            $retval = TRUE;
            runtime_hook::Execute('OnAfterAddSubDomain');
            return $retval;
        }
    }

    /**
     * Check a new subdomain name; sets $blank, $badname, $error or $alreadyexists on failure.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $domain The full subdomain name.
     * @return bool True when it can be created.
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
        return TRUE;
    }

    /**
     * Check whether a number is an HTTP status code that may have an error page.
     *
     * @param int|string $error The status code (from the file name).
     * @return bool True when it is in the list.
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
     * Check that a domain name has at least two valid labels.
     *
     * @param string $a The domain name.
     * @return bool True when valid.
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
     * Check that an email address looks valid.
     *
     * @param string $email The email address.
     * @return bool True when valid.
     */
    static function IsValidEmail($email)
    {
        return preg_match('/^[a-z0-9]+([_\\.-][a-z0-9]+)*@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i', $email) == 1;
    }

    /**
     * Flag the Apache configuration for rewriting by the daemon (apache_changed = true).
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
     * List the current user's subdomains for the page (<% loop SubDomainList %>).
     *
     * @return array<int, array<string, mixed>>|false Rows with 'subname', 'subdirectory', 'subactive', 'substatus' (HTML), 'subphp' (HTML label), 'subid'; false when there are none.
     */
    static function getSubDomainList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $res = array();
        $subdomains = self::ListSubDomains($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($subdomains)) {
            foreach ($subdomains as $row) {
                $status = self::getSubDomainStatusHTML($row['subactive'], $row['subid']);
                $res[] = array('subname' => $row['subname'],
                    'subdirectory' => $row['subdirectory'],
                    'subactive' => $row['subactive'],
                    'substatus' => $status,
                    'subphp' => sys_phpversions::ListLabel($row['subphp']),
                    'subid' => $row['subid']);
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the current user's domains, to pick the parent domain (<% loop DomainList %>).
     *
     * @return array<int, array<string, mixed>>|false Rows with 'name', 'directory', 'active', 'id'; false when there are none.
     */
    static function getDomainList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $domains = self::ListDomains($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($domains)) {
            return $domains;
        } else {
            return false;
        }
    }

    /**
     * Check whether the current user may add another subdomain (<% if CreateSubDomain %>).
     *
     * @return bool True when the quota is unlimited (-1) or not used up.
     */
    static function getCreateSubDomain()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['subdomainquota'] < 0) or //-1 = unlimited
                ($currentuser['subdomainquota'] > ctrl_users::GetQuotaUsages('subdomains', $currentuser['userid']));
    }

    /**
     * List the folders in the current user's public_html (<% loop SubDomainDirsList %>).
     *
     * @global db_driver $zdbh The panel's database connection (not used).
     * @global runtime_controller $controller The request controller (not used).
     * @return array<int, array<string, string>>|false Rows with 'domains'; false when there are none.
     */
    static function getSubDomainDirsList()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $domaindirectories = self::ListDomainDirs($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($domaindirectories)) {
            return $domaindirectories;
        } else {
            return false;
        }
    }

    /**
     * Create the subdomain posted on the form for the current user.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when created.
     */
    static function doCreateSubDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        // The package's limit on sub-domains, checked here as well: the page only hides the
        // form at the limit, so a posted form could create more than the package allows
        if (!self::getCreateSubDomain()) {
            return false;
        }
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // The parent must be one of this account's own domains: it came from the form, so any
        // name was accepted, and a sub-domain of another account's domain took over its traffic
        $parent = strtolower(trim(isset($formvars['inDomain']) ? (string) $formvars['inDomain'] : ''));
        $own = false;
        foreach (self::ListDomains($currentuser['userid']) ?: array() as $d) {
            if (strtolower($d['name']) === $parent) {
                $own = true;
            }
        }
        if (!$own) {
            self::$notowner = true;
            return false;
        }
        $sub = strtolower(trim(isset($formvars['inSub']) ? (string) $formvars['inSub'] : ''));
        if ($sub === '') {
            self::$blank = true;
            return false;
        }
        if (self::ExecuteAddSubDomain($currentuser['userid'], $sub . "." . $parent, isset($formvars['inDestination']) ? $formvars['inDestination'] : '', isset($formvars['inAutoHome']) ? $formvars['inAutoHome'] : 1, isset($formvars['inPhpVersion']) ? $formvars['inPhpVersion'] : '')) {
            self::$ok = TRUE;
            return true;
        } else {
            return false;
        }
        return;
    }

    /**
     * Delete the subdomain confirmed on the form, when it belongs to the current user.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when deleted.
     */
    static function doDeleteSubDomain()
    {
        global $controller;
        runtime_csfr::Protect();
//PP      $currentuser = ctrl_users::GetUserDetail();  assignment never used
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inDelete']) || !ctrl_ownership::Owns('x_vhosts', 'vh_id_pk', 'vh_acc_fk', $formvars['inDelete'], ' AND vh_type_in = 2 AND vh_deleted_ts IS NULL')) {
            return false;
        }
        if (isset($formvars['inDelete'])) {
            if (self::ExecuteDeleteSubDomain($formvars['inDelete'])) {
                self::$ok = TRUE;
                return true;
            }
        }
        return false;
    }

    /**
     * Handle a delete button: redirect to the confirmation page for that subdomain and exit.
     *
     * @global runtime_controller $controller The request controller.
     * @return false When no delete button of the user's subdomains was pressed; otherwise it redirects and exits.
     */
    static function doConfirmDeleteSubDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListSubDomains($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['subid'] . ''])) {
                header('location: ./?module=' . $controller->GetCurrentModule() . '&show=Delete&id=' . $row['subid'] . '&domain=' . $row['subname']);
                exit;
            }
        }
        return false;
    }
	
    /**
     * Template flag <% if isDeleteDomain %>: show the delete confirmation?
     *
     * @param int|string|null $uid Optional. Not used (always the logged-in account). Default null.
     * @return bool True for ?show=Delete with the ?id= of one of the account's sub-domains.
     */
    static function getisDeleteDomain($uid = null)
    {
        return domains_ui::Confirming(2) !== false;
    }

    /**
     * Return the subdomain ID from the URL ('id') (<@ CurrentID @>).
     *
     * @return mixed The URL value, or '' when none.
     */
    static function getCurrentID()
    {
        $row = domains_ui::Confirming(2);
        return $row ? (int) $row['vh_id_pk'] : '';
    }

    /**
     * Return the subdomain name from the URL ('domain') (<@ CurrentDomain @>).
     *
     * @return mixed The URL value, or '' when none.
     */
    static function getCurrentDomain()
    {
        $row = domains_ui::Confirming(2);
        return $row ? domains_ui::h($row['vh_name_vc']) : '';
    }

    /**
     * The figures at the top (<@ Tiles @>): sub-domains against the package's limit, live, and waiting.
     *
     * @return string The HTML.
     */
    static function getTiles()
    {
        $me = ctrl_users::GetUserDetail();
        $rows = array();
        foreach (self::ListSubDomains($me['userid']) ?: array() as $r) {
            $rows[] = array('active' => $r['subactive']);
        }
        return domains_ui::Tiles($rows, $me['subdomainquota'], 'Sub-domains', 'sub');
    }

    /**
     * One card per sub-domain (<@ Cards @>), with its folder, state, PHP version, Visit and Delete.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML.
     */
    static function getCards()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_acc_fk = :u AND vh_type_in = 2 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc");
        $q->execute(array(':u' => (int) ctrl_users::GetUserDetail()['userid']));
        $rows = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = array('id' => $r['vh_id_pk'], 'name' => $r['vh_name_vc'], 'active' => $r['vh_active_in'], 'dir' => $r['vh_directory_vc'],
                'php' => isset($r['vh_php_version_vc']) ? (string) $r['vh_php_version_vc'] : '', 'created' => $r['vh_created_ts']);
        }
        return domains_ui::Cards($rows, array('module' => 'sub_domains', 'phpAction' => 'SetPhpVersion', 'phpField' => 'inSubDomainId', 'sub' => true));
    }

    /**
     * The folders of public_html as choices for "a folder that is already there" (<@ FolderOptions @>).
     *
     * @return string The <option> HTML.
     */
    static function getFolderOptions()
    {
        return domains_ui::FolderOptions(self::ListDomainDirs(ctrl_users::GetUserDetail()['userid']));
    }

    /**
     * What deleting the sub-domain in the confirmation does (<@ DeleteDetails @>).
     *
     * @return string The HTML ('' when there is no confirmation).
     */
    static function getDeleteDetails()
    {
        $row = domains_ui::Confirming(2);
        return $row ? domains_ui::DeleteDetails($row) : '';
    }

    /**
     * The name typed when creating failed, so it is not lost (<@ PostedSub @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The escaped name.
     */
    static function getPostedSub()
    {
        global $controller;
        return self::$ok ? '' : domains_ui::h((string) $controller->GetControllerRequest('FORM', 'inSub'));
    }

    /**
     * The account's domains as choices for the parent, the one picked before selected (<@ ParentOptions @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The <option> HTML.
     */
    static function getParentOptions()
    {
        global $controller;
        $picked = (string) $controller->GetControllerRequest('FORM', 'inDomain');
        $out = '';
        foreach (self::ListDomains(ctrl_users::GetUserDetail()['userid']) ?: array() as $d) {
            $out .= '<option value="' . domains_ui::h($d['name']) . '"' . ($d['name'] === $picked ? ' selected' : '') . '>' . domains_ui::h($d['name']) . '</option>';
        }
        return $out;
    }

    /**
     * Build the status cells of a subdomain: Live, or Pending with a help icon.
     *
     * @global runtime_controller $controller The request controller.
     * @param int|string $int The vh_active_in value (1 = live).
     * @param int|string $id The subdomain ID.
     * @return string HTML: two <td> cells.
     */
    static function getSubDomainStatusHTML($int, $id)
    {
        global $controller;
        if ($int == 1) {
            return '<td><span class="label label-success">' . ui_language::translate('Live') . '</span></td>'
                    . '<td></td>';
        } else {
            return '<td><span class="label label-warning" title="' . htmlspecialchars(ui_language::translate('It goes live at the next update of the web server, within an hour.'), ENT_QUOTES, 'UTF-8') . '">' . ui_language::translate('Pending') . '</span></td><td></td>';
        }
    }

    /**
     * Build the PHP version <option> list for the create form (<@ PhpVersionOptions @>).
     *
     * @return string HTML <option> elements.
     */
    static function getPhpVersionOptions()
    {
        return sys_phpversions::SelectOptions('');
    }

    /**
     * Check whether any extra PHP version is enabled (<% if HasPhpVersions %>).
     *
     * @return bool True when there is a choice.
     */
    static function getHasPhpVersions()
    {
        return count(sys_phpversions::Enabled()) > 0;
    }

    /**
     * Change the PHP version of one of the current user's subdomains.
     *
     * Flags the Apache configuration for rewriting.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return bool False when no subdomain was posted, else true.
     */
    static function doSetPhpVersion()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (!isset($formvars['inSubDomainId'])) {
            return false;
        }
        $version = sys_phpversions::Clean(isset($formvars['inPhpVersion']) ? $formvars['inPhpVersion'] : '');
        $version = ($version === '') ? null : $version;
        // Only subdomains (type 2) that belong to this user
        $sql = $zdbh->prepare("UPDATE x_vhosts SET vh_php_version_vc = :php WHERE vh_id_pk = :id AND vh_acc_fk = :uid AND vh_type_in = 2 AND vh_deleted_ts IS NULL");
        $sql->bindParam(':php', $version);
        $sql->bindParam(':id', $formvars['inSubDomainId']);
        $sql->bindParam(':uid', $currentuser['userid']);
        $sql->execute();
        self::SetWriteApacheConfigTrue();
        self::$ok = true;
        return true;
    }

    /**
     * Return the message for the last action (<@ Result @>).
     *
     * @return string|null HTML message, or null when there is none.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$notowner)) {
            return ui_sysmessage::shout(ui_language::translate("Choose one of your own domains for the sub-domain."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return ui_sysmessage::shout(ui_language::translate("Your Domain can not be empty. Please enter a valid Domain Name and try again."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return ui_sysmessage::shout(ui_language::translate("Your Domain name is not valid. Please enter a valid Domain Name: i.e. 'domain.com'"), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("The domain already appears to exist on this server."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$error)) {
            return ui_sysmessage::shout(ui_language::translate("Please remove 'www'. The 'www' will automatically work with all Domains / Subdomains."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$writeerror)) {
            return ui_sysmessage::shout(ui_language::translate("There was a problem writing to the virtual host container file. Please contact your administrator and report this error. Your domain will not function until this error is corrected."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your domain web hosting has been saved successfully."), "zannounceok");
        }
        return;
    }

    // Webinterface sudo methods.
}
