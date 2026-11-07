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
 * Domains module: controller.
 *
 * Lists, creates and deletes the account's domains (x_vhosts type 1), sets their PHP
 * version, and flags the Apache config for rewriting by the daemon.
 */
require_once dirname(__FILE__) . '/domainsui.php';

class module_controller extends ctrl_module
{

    /** @var bool|null Not used. */
    static $complete;
    /** @var bool|null True when the domain started with 'www.'. */
    static $error;
    /** @var bool|null True when the vhost file could not be written (never set at present). */
    static $writeerror;
    /** @var bool|null True when the name looks like a subdomain of an existing domain. */
    static $nosub;
    /** @var bool|null True when the domain already exists on the server. */
    static $alreadyexists;
    /** @var bool|null True when the domain name is not valid. */
    static $badname;
    /** @var bool|null True when the domain name was blank. */
    static $blank;
    /** @var bool|null True when the last change was saved (shown by getResult()). */
    static $ok;

    // The 'worker' methods.
    /**
     * List domains (type 1) of one account, or of every account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid Optional. The account ID, or 0 for all accounts. Default 0.
     * @return array<int, array<string, mixed>>|false One row per domain: 'uid', 'name', 'directory', 'active', 'id', 'php' (version or ''); false when there are none.
     */
    static function ListDomains($uid = 0)
    {
        global $zdbh;
        if ($uid == 0) {
            $sql = "SELECT * FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_type_in=1 ORDER BY vh_name_vc ASC";
            $numrows = $zdbh->prepare($sql);
        } else {
            $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL AND vh_type_in=1 ORDER BY vh_name_vc ASC";
            $numrows = $zdbh->prepare($sql);
            $numrows->bindParam(':uid', $uid);
        }
        //$numrows = $zdbh->query($sql);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            if ($uid == 0) {
                $sql = $zdbh->prepare($sql);
            } else {
                $sql = $zdbh->prepare($sql);
                $sql->bindParam(':uid', $uid);
            }
            $res = array();
            $sql->execute();
            while ($rowdomains = $sql->fetch()) {
                array_push($res, array(
                    'uid' => $rowdomains['vh_acc_fk'],
                    'name' => $rowdomains['vh_name_vc'],
                    'directory' => $rowdomains['vh_directory_vc'],
                    'active' => $rowdomains['vh_active_in'],
                    'id' => $rowdomains['vh_id_pk'],
                    'php' => isset($rowdomains['vh_php_version_vc']) ? (string) $rowdomains['vh_php_version_vc'] : '',
                ));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the folders in the account's public_html (except _errorpages).
     *
     * @global runtime_controller $controller The request controller. Declared but not used.
     * @param int|string $uid The account ID.
     * @return array<int, array<string, string>> One row per folder: 'domains' (the folder name); empty when public_html can not be opened.
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
     * Delete a domain (sets vh_deleted_ts) and flag the Apache config for rewriting.
     *
     * Runs the OnBeforeDeleteDomain/OnAfterDeleteDomain hooks. The caller must have
     * checked ownership.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $id The domain ID (vh_id_pk).
     * @return bool Always true.
     */
    static function ExecuteDeleteDomain($id)
    {
        global $zdbh;
		
		// Delete Domain
        runtime_hook::Execute('OnBeforeDeleteDomain');
        $sql = $zdbh->prepare("UPDATE x_vhosts
							   SET vh_deleted_ts=:time
							   WHERE vh_id_pk=:id");
        $sql->bindParam(':id', $id);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        self::SetWriteApacheConfigTrue();
        $retval = TRUE;
        runtime_hook::Execute('OnAfterDeleteDomain');
        return $retval; 
    }
	
	
	
    /**
     * Create a domain for an account after checking it (CheckCreateForErrors()).
     *
     * Creates (or uses an existing) folder in public_html, copies the error pages and the
     * welcome page, inserts the x_vhosts row and flags the Apache config for rewriting.
     * Runs the OnBeforeAddDomain/OnAfterAddDomain hooks.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @param string $domain The domain name (spaces removed, lowercased).
     * @param string $destination An existing folder in public_html (used when $autohome is not 1).
     * @param int|string $autohome 1 to create a new folder named after the domain.
     * @param string $phpversion Optional. PHP version for the domain ('' = the default). Default ''.
     * @return bool|null True when created; false when the existing folder is not allowed; null when the name failed the checks (see the static flags).
     */
    static function ExecuteAddDomain($uid, $domain, $destination, $autohome, $phpversion = '')
    {
        global $zdbh;
        $retval = FALSE;
        runtime_hook::Execute('OnBeforeAddDomain');
        $currentuser = ctrl_users::GetUserDetail($uid);
        $domain = strtolower(str_replace(' ', '', $domain));
        if (!fs_director::CheckForEmptyValue(self::CheckCreateForErrors($domain))) {
            //** New Home Directory **//
            if ($autohome == 1) {
                $destination = "/" . str_replace(".", "_", $domain);
                $vhost_path = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/public_html/" . $destination . "/";
                fs_director::CreateDirectory($vhost_path);
                fs_director::SetFileSystemPermissions($vhost_path, 0777);
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
														 1,
														 :phpversion,
														 :time)"); //CLEANER FUNCTION ON $domain and $homedirectory_to_use (Think I got it?)
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->bindParam(':userid', $currentuser['userid']);
            $sql->bindParam(':domain', $domain);
            $sql->bindParam(':destination', $destination);
            // '' = the default PHP; anything else must be an enabled extra version
            $phpversion = sys_phpversions::Clean($phpversion);
            $phpversion = ($phpversion === '') ? null : $phpversion;
            $sql->bindParam(':phpversion', $phpversion);
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
            runtime_hook::Execute('OnAfterAddDomain');
            return $retval;
        }
    }

    /**
     * Check a new domain name and set the matching error flag.
     *
     * Refuses a blank or invalid name, a name starting with www., an existing domain,
     * and what looks like a subdomain of another account's domain.
     * @global db_driver $zdbh The panel's database connection.
     * @param string $domain The domain name.
     * @return bool True when there are no errors.
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
        $a = explode(',', ctrl_options::GetSystemOption('shared_domains'));
        foreach ($a as $b) {
            $SharedDomains[] = $b;
        }
        if (substr_count($domain, ".") > 1) {
            $part = explode('.', $domain);
            foreach ($part as $check) {
                if (!in_array($check, $SharedDomains)) {
                    if (strlen($check) > 3) {
                        $sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc LIKE :check AND vh_type_in !=2 AND vh_deleted_ts IS NULL");
                        $checkSql = '%' . $check . '%';
                        $sql->bindParam(':check', $checkSql);
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
     * Is $error an HTTP status code an error page can be set for?
     *
     * @param int|string $error The status code (from an error page file name).
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
     * Is $a a valid domain name (at least one dot, valid labels)?
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
     * Is $email a valid email address?
     *
     * @param string $email The address.
     * @return bool
     */
    static function IsValidEmail($email)
    {
        return preg_match('/^[a-z0-9]+([_\\.-][a-z0-9]+)*@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i', $email) == 1;
    }

    /**
     * Set the apache_changed setting to 'true' so the daemon rewrites the Apache config.
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

    /**
     * Does $ip look like an IPv4 address?
     *
     * The pattern uses ^ as its delimiter, so it is not anchored and also matches
     * longer text containing an address.
     * @param string $ip The address.
     * @return bool
     */
    static function IsvalidIP($ip)
    {
        return preg_match("^([1-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(\.([0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3}^", $ip) == 1;
    }

    // End 'worker' methods.

    // Webinterface sudo methods.
    /**
     * List the logged-in account's domains (template: <% loop DomainList %>).
     *
     * @return array<int, array<string, mixed>>|false One row per domain: 'name', 'directory', 'active', 'status' (HTML), 'php' (HTML label), 'id'; false when there are none.
     */
    static function getDomainList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $res = array();
        $domains = self::ListDomains($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($domains)) {
            foreach ($domains as $row) {
                $status = self::getDomainStatusHTML($row['active'], $row['id']);
                $res[] = array('name' => $row['name'],
                    'directory' => $row['directory'],
                    'active' => $row['active'],
                    'status' => $status,
                    'php' => sys_phpversions::ListLabel($row['php']),
                    'id' => $row['id']);
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * May the account create another domain (template: <% if CreateDomain %>)?
     *
     * @return bool True when the quota is unlimited (-1) or not yet reached.
     */
    static function getCreateDomain()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['domainquota'] < 0) or //-1 = unlimited
                ($currentuser['domainquota'] > ctrl_users::GetQuotaUsages('domains', $currentuser['userid']));
    }

    /**
     * List the folders in the account's public_html (template: <% loop DomainDirsList %>).
     *
     * @return array<int, array<string, string>>|false See ListDomainDirs(); false when there are none.
     */
    static function getDomainDirsList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $domaindirectories = self::ListDomainDirs($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($domaindirectories)) {
            return $domaindirectories;
        } else {
            return false;
        }
    }

    /**
     * Handle the create domain form (POST).
     *
     * Checks the CSFR token (dies when it is wrong) and creates the domain from inDomain,
     * inDestination, inAutoHome and inPhpVersion.
     * @global runtime_controller $controller The request controller.
     * @return bool True when created.
     */
    static function doCreateDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        // The package's limit on domains, checked here as well: the page only hides the
        // form at the limit, so a posted form could create more than the package allows
        if (!self::getCreateDomain()) {
            return false;
        }
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteAddDomain($currentuser['userid'], $formvars['inDomain'], $formvars['inDestination'], $formvars['inAutoHome'], isset($formvars['inPhpVersion']) ? $formvars['inPhpVersion'] : '')) {
            self::$ok = TRUE;
            return true;
        } else {
            return false;
        }
        return;
    }

    /**
     * Handle the confirm delete form (POST): delete the domain in inDelete.
     *
     * Checks the CSFR token and that the domain belongs to the logged-in account.
     * @global runtime_controller $controller The request controller.
     * @return bool True when deleted.
     */
    static function doDeleteDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inDelete']) || !ctrl_ownership::Owns('x_vhosts', 'vh_id_pk', 'vh_acc_fk', $formvars['inDelete'], ' AND vh_type_in = 1 AND vh_deleted_ts IS NULL')) {
            return false;
        }
        if (isset($formvars['inDelete'])) {
            if (self::ExecuteDeleteDomain($formvars['inDelete'])) {
                self::$ok = TRUE;
                return true;
            }
        }
        return false;
    }

    /**
     * Handle a Delete button in the domain list (POST): redirect to the confirmation view and exit.
     *
     * Checks the CSFR token (dies when it is wrong).
     * @global runtime_controller $controller The request controller.
     * @return false False when no Delete button of the account's domains was pressed (otherwise the request exits).
     */
    static function doConfirmDeleteDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListDomains($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['id'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&id=" . $row['id'] . "&domain=" . $row['name'] . "");
                exit;
            }
        }
        return false;
    }

    /**
     * Is the page showing the delete confirmation for a domain of this account (template: <% if isDeleteDomain %>)?
     *
     * @param int|string|null $uid Optional. Not used (always the logged-in account). Default null.
     * @return bool True for ?show=Delete with the ?id= of one of the account's domains.
     */
    static function getisDeleteDomain($uid = null)
    {
        return domains_ui::Confirming(1) !== false;
    }

    /**
     * Return the domain ID in ?id= when it is one of the account's (template: <@ CurrentID @>).
     *
     * @return string|int The ID, or ''.
     */
    static function getCurrentID()
    {
        $row = domains_ui::Confirming(1);
        return $row ? (int) $row['vh_id_pk'] : '';
    }

    /**
     * Return the domain name in ?domain= (template: <@ CurrentDomain @>).
     *
     * The name of the account's own domain with that ?id= (not ?domain=), HTML-escaped.
     * @return string The domain, or ''.
     */
    static function getCurrentDomain()
    {
        $row = domains_ui::Confirming(1);
        return $row ? domains_ui::h($row['vh_name_vc']) : '';
    }

    /**
     * The figures at the top (<@ Tiles @>): domains against the package's limit, live, and waiting.
     *
     * @return string The HTML.
     */
    static function getTiles()
    {
        $me = ctrl_users::GetUserDetail();
        $rows = self::ListDomains($me['userid']);
        return domains_ui::Tiles($rows ? $rows : array(), $me['domainquota'], 'Domains', 'globe');
    }

    /**
     * One card per domain (<@ Cards @>), with its folder, state, PHP version, Visit and Delete.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML.
     */
    static function getCards()
    {
        global $zdbh;
        $me = ctrl_users::GetUserDetail();
        $q = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_acc_fk = :u AND vh_type_in = 1 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc");
        $q->execute(array(':u' => (int) $me['userid']));
        $rows = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = array('id' => $r['vh_id_pk'], 'name' => $r['vh_name_vc'], 'active' => $r['vh_active_in'], 'dir' => $r['vh_directory_vc'],
                'php' => isset($r['vh_php_version_vc']) ? (string) $r['vh_php_version_vc'] : '', 'created' => $r['vh_created_ts']);
        }
        return domains_ui::Cards($rows, array('module' => 'domains', 'phpAction' => 'SetPhpVersion', 'phpField' => 'inDomainId'));
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
     * What deleting the domain in the confirmation does (<@ DeleteDetails @>).
     *
     * @return string The HTML ('' when there is no confirmation).
     */
    static function getDeleteDetails()
    {
        $row = domains_ui::Confirming(1);
        return $row ? domains_ui::DeleteDetails($row) : '';
    }

    /**
     * The domain name typed when creating failed, so it is not lost (<@ PostedDomain @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The escaped name.
     */
    static function getPostedDomain()
    {
        global $controller;
        return self::$ok ? '' : domains_ui::h((string) $controller->GetControllerRequest('FORM', 'inDomain'));
    }

    /**
     * Return the status cells for a domain row: Live, or Pending with a help icon.
     *
     * @global runtime_controller $controller The request controller.
     * @param int|string $int The domain's vh_active_in value (1 = live).
     * @param int|string $id The domain ID (for the help icon's id).
     * @return string Two <td> cells of HTML.
     */
    static function getDomainStatusHTML($int, $id)
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
     * PHP version choices (default first, then enabled extra versions).
     *
     * Template: <@ PhpVersionOptions @>.
     * @return string HTML <option> elements.
     */
    static function getPhpVersionOptions()
    {
        return sys_phpversions::SelectOptions('');
    }

    /**
     * Show the PHP version controls only when extra versions are enabled.
     *
     * Template: <% if HasPhpVersions %>.
     * @return bool
     */
    static function getHasPhpVersions()
    {
        return count(sys_phpversions::Enabled()) > 0;
    }

    /**
     * Change the PHP version of one of the current user's domains.
     *
     * Handles the PHP version form (POST: inDomainId, inPhpVersion). Checks the CSFR token
     * (dies when it is wrong); only the user's own domains (type 1) are changed. Flags the
     * Apache config for rewriting.
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return bool False when inDomainId is missing, else true.
     */
    static function doSetPhpVersion()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (!isset($formvars['inDomainId'])) {
            return false;
        }
        $version = sys_phpversions::Clean(isset($formvars['inPhpVersion']) ? $formvars['inPhpVersion'] : '');
        $version = ($version === '') ? null : $version;
        // Only domains (type 1) that belong to this user
        $sql = $zdbh->prepare("UPDATE x_vhosts SET vh_php_version_vc = :php WHERE vh_id_pk = :id AND vh_acc_fk = :uid AND vh_type_in = 1 AND vh_deleted_ts IS NULL");
        $sql->bindParam(':php', $version);
        $sql->bindParam(':id', $formvars['inDomainId']);
        $sql->bindParam(':uid', $currentuser['userid']);
        $sql->execute();
        self::SetWriteApacheConfigTrue();
        self::$ok = true;
        return true;
    }

    /**
     * Return the message for the last action (template: <@ Result @>).
     *
     * @return string|null The HTML message, or null when there is none.
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
            return ui_sysmessage::shout(ui_language::translate("The domain already appears to exist on this server."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$nosub)) {
            return ui_sysmessage::shout(ui_language::translate("You cannot add a Sub-Domain here. Please use the Subdomain manager to add Sub-Domains."), "zannounceerror");
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

