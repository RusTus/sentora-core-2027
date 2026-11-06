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
 * Distribution Lists module: controller.
 *
 * Lists, creates and deletes mail distribution lists and their members; the mail
 * server specific file (e.g. code/postfix.php) is included to update the mail database.
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True when the last change was saved (shown by getResult()). */
    static $ok;
    /** @var bool|null Not used. */
    static $edit;
    /** @var bool|null True when the address is already used by a mailbox, alias, forwarder or list. */
    static $alreadyexists;
    /** @var bool|null True when the address is not a valid email address. */
    static $validemail;
    /** @var bool|null True when no address was given. */
    static $noaddress;
    /** @var bool|null True when the domain is not one of the user's domains. */
    static $validdomain;
    /** @var bool|null Tells the mail server file to delete a list. */
    static $delete;
    /** @var bool|null Tells the mail server file to create a list. */
    static $create;
    /** @var bool|null Tells the mail server file to remove a list member. */
    static $deleteuser;
    /** @var bool|null Tells the mail server file to add a list member. */
    static $createuser;

    /**
     * List the distribution lists of an account (start of the 'worker' methods).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return array<int, array<string, mixed>>|false One row per list: 'address', 'totalmb' (member count), 'id'; false when there are none.
     */
    static function ListDist($uid)
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $sql = "SELECT * FROM x_distlists WHERE dl_acc_fk=:userid AND dl_deleted_ts IS NULL ORDER BY dl_address_vc ASC";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->bindParam(':userid', $currentuser['userid']);
            $sql->execute();
            while ($rowdistlist = $sql->fetch()) {
                $numrows2 = $zdbh->prepare("SELECT COUNT(*) FROM x_distlistusers WHERE du_distlist_fk=:dl_id_pk AND du_deleted_ts IS NULL");
                $numrows2->bindParam(':dl_id_pk', $rowdistlist['dl_id_pk']);
                $numrows2->execute();
                $numrowmb = $numrows2->fetch();
                $res[] = array('address' => $rowdistlist['dl_address_vc'],
                    'totalmb' => $numrowmb[0],
                    'id' => $rowdistlist['dl_id_pk']);
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Get one distribution list of the logged-in account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $id The list ID (dl_id_pk), from the URL or form.
     * @return array<int, array<string, mixed>>|false A single row: 'address', 'id'; false when not found or not owned.
     */
    static function ListCurrentDist($id)
    {
        // Only this account's own records (the id comes from ?other= or the form)
        if (!ctrl_ownership::Owns('x_distlists', 'dl_id_pk', 'dl_acc_fk', $id)) {
            return false;
        }
        global $zdbh;
        $sql = "SELECT * FROM x_distlists WHERE dl_id_pk=:id AND dl_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':id', $id);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->bindParam(':id', $id);
            $sql->execute();
            while ($rowdistlist = $sql->fetch()) {
                $res[] = array('address' => $rowdistlist['dl_address_vc'],
                    'id' => $rowdistlist['dl_id_pk']);
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the members of a distribution list of the logged-in account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|false $id The list ID (dl_id_pk), from the URL or form.
     * @return array<int, array<string, mixed>>|false One row per member: 'address', 'distlist' (the list address), 'id'; false when there are none or the list is not owned.
     */
    static function ListDistUsers($id)
    {
        // Only this account's own records (the id comes from ?other= or the form)
        if (!ctrl_ownership::Owns('x_distlists', 'dl_id_pk', 'dl_acc_fk', $id)) {
            return false;
        }
        global $zdbh;
        $numrows = $zdbh->prepare("SELECT * FROM x_distlists WHERE dl_id_pk=:id AND dl_deleted_ts IS NULL");
        $numrows->bindParam(':id', $id);
        $numrows->execute();
        $result = $numrows->fetch();
        if ($result) {
            $numrows = $zdbh->prepare("SELECT * FROM x_distlistusers WHERE du_distlist_fk=:dl_id_pk AND du_deleted_ts IS NULL");
            $numrows->bindParam(':dl_id_pk', $result['dl_id_pk']);
            $numrows->execute();
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_distlistusers WHERE du_distlist_fk=:dl_id_pk AND du_deleted_ts IS NULL");
                $res = array();
                $sql->bindParam(':dl_id_pk', $result['dl_id_pk']);
                $sql->execute();
                while ($rowdistlist = $sql->fetch()) {
                    $res[] = array('address' => $rowdistlist['du_address_vc'],
                        'distlist' => $result['dl_address_vc'],
                        'id' => $rowdistlist['du_id_pk']);
                }
                return $res;
            } else {
                return false;
            }
        }
        return false;
    }

    /**
     * List the mailboxes of an account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return array<int, array<string, mixed>>|false One row per mailbox: 'address', 'id'; false when there are none.
     */
    static function ListMailbox($uid)
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $sql = "SELECT * FROM x_mailboxes WHERE mb_acc_fk=:userid AND mb_deleted_ts IS NULL ORDER BY mb_address_vc ASC";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->bindParam(':userid', $currentuser['userid']);
            $sql->execute();
            while ($rowmailboxes = $sql->fetch()) {
                $res[] = array('address' => $rowmailboxes['mb_address_vc'],
                    'id' => $rowmailboxes['mb_id_pk']);
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Produces a list of domain names only.
     *
     * Template loop <% loop DomainList %>.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|null $uid Optional. The account ID. Default null (the logged-in user).
     * @return array<int, array<string, string>>|false One row per enabled domain: 'domain'; false when there are none.
     */
    static function getDomainList($uid = null)
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail($uid);
        
        $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_enabled_in=1 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc ASC";
        $binds = array(':userid' => $currentuser['userid']);
        $prepared = $zdbh->bindQuery($sql, $binds);
        
        $rows = $prepared->fetchAll(PDO::FETCH_ASSOC);
        $return = array();
        
        if (count($rows) > 0) {
            foreach ($rows as $row) {
                $return[] = array('domain' => $row['vh_name_vc']);
            }
            return $return;
        } else {
            return false;
        }
    }

    /**
     * Create a distribution list.
     *
     * Validates the address, includes the mail server file to add the alias and inserts
     * the x_distlists row. Sets self::$ok on success.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $uid The account ID.
     * @param string $inAddress The local part of the address.
     * @param string $inDomain The domain (must be one of the user's domains).
     * @return bool True when created, false when validation failed.
     */
    static function ExecuteAddDistList($uid, $inAddress, $inDomain)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $fulladdress = strtolower(str_replace(' ', '', $inAddress . '@' . $inDomain));
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrors($inAddress, $inDomain))) {
            return false;
        }
        runtime_hook::Execute('OnBeforeAddDistList');
        self::$create = true;
        // Include mail server specific file here.
        $MailServerFile = 'modules/' . $controller->GetControllerRequest('URL', 'module') . '/code/' . ctrl_options::GetSystemOption('mailserver_php');
        if (file_exists($MailServerFile))
            include($MailServerFile);

        $sqlStatment = "INSERT INTO x_distlists (dl_acc_fk,
										  dl_address_vc,
										  dl_created_ts) VALUES (
										  :userid,
										  :fulladdress,
										  :time)";
        $sql = $zdbh->prepare($sqlStatment);
        $sql->bindParam(':userid', $currentuser['userid']);
        $sql->bindParam(':fulladdress', $fulladdress);
        $sql->bindParam(':time', time());
        $sql->execute();
        runtime_hook::Execute('OnAfterAddDistList');
        self::$ok = true;
        return true;
    }

    /**
     * Delete a distribution list and its members.
     *
     * Includes the mail server file to remove the alias, then marks the list and its
     * members deleted. Sets self::$ok.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $dl_id_pk The list ID.
     * @return void
     */
    static function ExecuteDeleteDistList($dl_id_pk)
    {
        global $zdbh;
        global $controller;
        runtime_hook::Execute('OnBeforeDeleteDistList');
        self::$delete = true;
        $numrows = $zdbh->prepare("SELECT * FROM x_distlists WHERE dl_id_pk=:dl_id_pk AND dl_deleted_ts IS NULL");
        $numrows->bindParam(':dl_id_pk', $dl_id_pk);
        $numrows->execute();
        $rowdl = $numrows->fetch();

        // Include mail server specific file here.
        $MailServerFile = 'modules/' . $controller->GetControllerRequest('URL', 'module') . '/code/' . ctrl_options::GetSystemOption('mailserver_php');
        if (file_exists($MailServerFile))
            include($MailServerFile);

        $sql = "UPDATE x_distlistusers SET du_deleted_ts=:time WHERE du_distlist_fk=:dl_id_pk";
        $sql = $zdbh->prepare($sql);
        $sql->bindParam(':dl_id_pk', $dl_id_pk);
        $sql->bindParam(':time', time());
        $sql->execute();
        $sql = "UPDATE x_distlists SET dl_deleted_ts=:time WHERE dl_id_pk=:dl_id_pk";
        $sql = $zdbh->prepare($sql);
        $sql->bindParam(':dl_id_pk', $dl_id_pk);
        $sql->bindParam(':time', time());
        $sql->execute();
        runtime_hook::Execute('OnAfterDeleteDistList');
        self::$ok = true;
    }

    /**
     * Add a member address to a distribution list.
     *
     * Validates the posted address (see CheckCreateForErrorsDistListUser()), includes the
     * mail server file to update the alias and inserts the x_distlistusers row.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $du_distlist_fk The list ID.
     * @param string $address The member address local part.
     * @param string $domain The member address domain.
     * @param string $dladdress The list address (not used; the mail server file reads $rowdl).
     * @return bool True when added, false when validation failed.
     */
    static function ExecuteAddDistListUser($du_distlist_fk, $address, $domain, $dladdress)
    {
        global $zdbh;
        global $controller;
        $fulladdress = strtolower(str_replace(' ', '', $address . '@' . $domain));
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrorsDistListUser())) {
            return false;
        }

        $numrows = $zdbh->prepare("SELECT * FROM x_distlists WHERE dl_id_pk=:du_distlist_fk AND dl_deleted_ts IS NULL");
        $numrows->bindParam(':du_distlist_fk', $du_distlist_fk);
        $numrows->execute();
        $rowdl = $numrows->fetch(); //WARNING : $rowdl is used in mail server specific file

        runtime_hook::Execute('OnBeforeAddDistListUser');
        self::$createuser = true;
        // Include mail server specific file here.
        $MailServerFile = 'modules/' . $controller->GetControllerRequest('URL', 'module') . '/code/' . ctrl_options::GetSystemOption('mailserver_php');
        if (file_exists($MailServerFile))
            include($MailServerFile);

        $sql = "INSERT INTO x_distlistusers (
										du_distlist_fk,
										du_address_vc,
										du_created_ts) VALUES (
										:du_distlist_fk,
										:fulladdress,
										:time)";
        $sql = $zdbh->prepare($sql);
        $sql->bindParam(':du_distlist_fk', $du_distlist_fk);
        $sql->bindParam(':fulladdress', $fulladdress);
        $sql->bindParam(':time', time());
        $sql->execute();
        runtime_hook::Execute('OnAfterAddDistListUser');
        self::$ok = true;
        return true;
    }

    /**
     * Remove a member from a distribution list.
     *
     * Includes the mail server file to update the alias, then marks the member deleted.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $du_id_pk The member ID.
     * @return true Always true.
     */
    static function ExecuteDeleteDistListUser($du_id_pk)
    {
        global $zdbh;
        global $controller;
        $numrows = $zdbh->prepare("SELECT * FROM x_distlistusers WHERE du_id_pk=:du_id_pk AND du_deleted_ts IS NULL");
        $numrows->bindParam(':du_id_pk', $du_id_pk);
        $numrows->execute();
        $rowdlu = $numrows->fetch(); //WARNING : $rowdlu is used in mail server specific file

        $numrows = $zdbh->prepare("SELECT * FROM x_distlists WHERE dl_id_pk=:du_distlist_fk AND dl_deleted_ts IS NULL");
        $numrows->bindParam(':du_distlist_fk', $rowdlu['du_distlist_fk']);
        $numrows->execute();
        $rowdl = $numrows->fetch();
        $dladdress = $rowdl['dl_address_vc']; //WARNING : $dladdress is used in mail server specific file

        runtime_hook::Execute('OnBeforeDeleteDistListUser');
        self::$deleteuser = true;
        // Include mail server specific file here.
        $MailServerFile = 'modules/' . $controller->GetControllerRequest('URL', 'module') . '/code/' . ctrl_options::GetSystemOption('mailserver_php');
        if (file_exists($MailServerFile))
            include($MailServerFile);

        $sql = "UPDATE x_distlistusers SET du_deleted_ts=:time WHERE du_id_pk=:du_id_pk";
        $sql = $zdbh->prepare($sql);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':du_id_pk', $du_id_pk);
        $sql->execute();
        runtime_hook::Execute('OnAfterDeleteDistListUser');
        self::$ok = true;
        return true;
    }

    /**
     * Validate a new distribution list address.
     *
     * Sets self::$noaddress, self::$validemail, self::$validdomain or self::$alreadyexists on failure.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $inAddress The local part of the address.
     * @param string $inDomain The domain.
     * @return bool True when the address can be used.
     */
    static function CheckCreateForErrors($inAddress, $inDomain)
    {
        global $zdbh;
        $fulladdress = strtolower(str_replace(' ', '', $inAddress . '@' . $inDomain));
        if (fs_director::CheckForEmptyValue($inAddress)) {
            self::$noaddress = true;
            return false;
        }
        if (!self::IsValidEmail($fulladdress)) {
            self::$validemail = true;
            return false;
        }
        if(!self::IsValidDomain($inDomain)){
            self::$validdomain = true;
            return false;        
        }
        $sql = "SELECT * FROM x_mailboxes WHERE mb_address_vc=:fulladdress AND mb_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':fulladdress', $fulladdress);
        $numrows->execute();
        $result = $numrows->fetch();
        if ($numrows->fetchColumn() <> 0) {
            self::$alreadyexists = true;
            return false;
        }

        $sql = "SELECT * FROM x_forwarders WHERE fw_address_vc=:fulladdress AND fw_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':fulladdress', $fulladdress);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            self::$alreadyexists = true;
            return false;
        }

        $sql = "SELECT * FROM x_distlists WHERE dl_address_vc=:fulladdress AND dl_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':fulladdress', $fulladdress);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            self::$alreadyexists = true;
            return false;
        }

        $sql = "SELECT * FROM x_aliases WHERE al_address_vc=:fulladdress AND al_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':fulladdress', $fulladdress);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            self::$alreadyexists = true;
            return false;
        }
        return true;
    }

    /**
     * Validate the posted new list member (inAddAddress, inAddDomain, inDLID).
     *
     * Sets self::$noaddress or self::$validemail on failure.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return bool True when the member can be added.
     */
    static function CheckCreateForErrorsDistListUser()
    {
        global $zdbh;
        global $controller;
        $address = $controller->GetControllerRequest('FORM', 'inAddAddress');
        $domain = $controller->GetControllerRequest('FORM', 'inAddDomain');
        $dlid = $controller->GetControllerRequest('FORM', 'inDLID');
        $fulladdress = strtolower(str_replace(' ', '', $address . '@' . $domain));
        if (fs_director::CheckForEmptyValue($address)) {
            self::$noaddress = true;
            return false;
        }
        if (!self::IsValidEmail($fulladdress)) {
            self::$validemail = true;
            return false;
        }

        $sql = "SELECT * FROM x_distlistusers WHERE du_distlist_fk=:dlid  AND du_address_vc=:fulladdress AND du_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':dlid', $dlid);
        $numrows->bindParam(':fulladdress', $fulladdress);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            self::$alreadyexistsdu = true;
            return false;
        }
        return true;
    }

    /**
     * Check an email address against a simple pattern.
     *
     * @param string $email The full address.
     * @return bool True when it looks like a valid address.
     */
    static function IsValidEmail($email)
    {
        return preg_match('/^[a-z0-9]+([_\\.-][a-z0-9]+)*@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i', $email) == 1;
    }
    
    /**
     * Check that a domain is one of the logged-in user's domains.
     *
     * @param string $domain The domain name.
     * @return bool True when the user owns the domain.
     */
    static function IsValidDomain($domain)
    {
         foreach(self::getDomainList() as $key => $checkDomain){
            if(array_key_exists('domain', $checkDomain) && $checkDomain['domain'] == $domain){
                return true;
            }
        }
        return false;
    }

    // End 'worker' methods.
    // Webinterface sudo methods.

    /**
     * Handle the list page's Edit/Delete buttons (start of the web interface methods).
     *
     * Redirects to the edit or delete view of the chosen list and exits.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doEditDistList()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListDist($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['id'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['id']);
                exit;
            }
            if (isset($formvars['inEdit_' . $row['id'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $row['id']);
                exit;
            }
        }
        return;
    }

    /**
     * Handle the delete confirmation form.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when the list is missing or not owned, otherwise null.
     */
    static function doConfirmDeleteDistList()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inDelete']) || !ctrl_ownership::Owns('x_distlists', 'dl_id_pk', 'dl_acc_fk', $formvars['inDelete'], ' AND dl_deleted_ts IS NULL')) {
            return false;
        }
        return self::ExecuteDeleteDistList($formvars['inDelete']);
    }

    /**
     * Handle the edit form: add a member or remove one.
     *
     * Redirects back to the edit view with status=ok and exits after a change.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when the list is not owned, otherwise null.
     */
    static function doUpdateDistList()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inDLID']) || !ctrl_ownership::Owns('x_distlists', 'dl_id_pk', 'dl_acc_fk', $formvars['inDLID'], ' AND dl_deleted_ts IS NULL')) {
            return false;
        }
        if (isset($formvars['inAdd'])) {
            if (self::ExecuteAddDistListUser($formvars['inDLID'], $formvars['inAddAddress'], $formvars['inAddDomain'], $formvars['inDLAD'])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $formvars['inDLID'] . "&status=ok");
                exit;
            }
        }
        foreach ((array) self::ListDistUsers($formvars['inDLID']) as $row) {
            if (isset($formvars['inDeleteUser_' . $row['id'] . ''])) {
                if (self::ExecuteDeleteDistListUser($formvars['inDeleteUser_' . $row['id'] . ''])) {
                    header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $formvars['inDLID'] . "&status=ok");
                    exit;
                }
            }
        }
        return;
    }

    /**
     * Handle the create form.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when the list was created.
     */
    static function doAddDistList()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        return self::ExecuteAddDistList($currentuser['userid'], $formvars['inAddress'], $formvars['inDomain']);
    }

    /**
     * Template loop <% loop DistList %>: the logged-in user's lists.
     *
     * @return array<int, array<string, mixed>>|false See ListDist().
     */
    static function getDistList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListDist($currentuser['userid']);
    }

    /**
     * Template loop <% loop DistListUsers %>: members of the list in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return array<int, array<string, mixed>>|false See ListDistUsers().
     */
    static function getDistListUsers()
    {
        global $controller;
        return self::ListDistUsers($controller->GetControllerRequest('URL', 'other'));
    }

    /**
     * Template value <@ CurrentDistListID @>: the ID of the list in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The list ID ('' when there is no ?other=; null when the list is not owned).
     */
    static function getCurrentDistListID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentDist($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['id'];
        } else {
            return '';
        }
    }

    /**
     * Template value <@ CurrentDistList @>: the address of the list in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The list address ('' when there is no ?other=; null when the list is not owned).
     */
    static function getCurrentDistList()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentDist($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['address'];
        } else {
            return '';
        }
    }

    /**
     * Template flag <% if isEditDistList %>: show the edit view?
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|null $uid Optional. The account ID. Default null (the logged-in user).
     * @return bool|null True when ?show=Edit and the list in ?other= is the user's; null when it is not.
     */
    static function getisEditDistList($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars     = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Edit Mail distlists.
        // This shall avoid exposing mail distlists based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

    	$sql = "SELECT * FROM x_distlists WHERE dl_acc_fk=:userid AND dl_id_pk=:editedUsrID AND dl_deleted_ts IS NULL";
    	$numrows = $zdbh->prepare($sql);
    	$numrows->bindParam(':userid', $currentuser['userid']);
		$numrows->bindParam(':editedUsrID', $urlvars['other']);
    	$numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }
		
        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Edit");
    }

    /**
     * Template flag <% if isDeleteDistList %>: show the delete view?
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|null $uid Optional. The account ID. Default null (the logged-in user).
     * @return bool|null True when ?show=Delete and the list in ?other= is the user's; null when it is not.
     */
    static function getisDeleteDistList($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete Mail distlists.
        // This shall avoid exposing mail distlists based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

    	$sql = "SELECT * FROM x_distlists WHERE dl_acc_fk=:userid AND dl_id_pk=:editedUsrID AND dl_deleted_ts IS NULL";
    	$numrows = $zdbh->prepare($sql);
    	$numrows->bindParam(':userid', $currentuser['userid']);
		$numrows->bindParam(':editedUsrID', $urlvars['other']);
    	$numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }

        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Delete");
    }

    /**
     * Template flag <% if isCreateDistList %>: show the list and create form?
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when there is no ?show=.
     */
    static function getisCreateDistList()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (!isset($urlvars['show']))
            return true;
        return false;
    }

    /**
     * Template flag <% if QuotaLimit %>: may the user create another list?
     *
     * @return bool True when the quota is unlimited (-1) or not used up.
     */
    static function getQuotaLimit()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['distlistsquota'] < 0) or //-1 = unlimited
                ($currentuser['distlistsquota'] > ctrl_users::GetQuotaUsages('distlists', $currentuser['userid']));
    }

    /**
     * Template value <@ DistListUsagepChart @>: the quota usage pie chart.
     *
     * @global runtime_controller $controller The request controller.
     * @return string An <img> tag (the "unlimited" image when the quota is -1).
     */
    static function getDistListUsagepChart()
    {
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$maximum = $currentuser['distlistsquota'];
		if ($maximum < 0) { //-1 = unlimited
            if (file_exists(ui_template::AssetPath('img/misc/unlimited.png'))) {
				return '<img src="' . ui_template::AssetPath('img/misc/unlimited.png') . '" alt="' . ui_language::translate('Unlimited') . '"/>';
			} else {
				return '<img src="modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/unlimited.png" alt="' . ui_language::translate('Unlimited') . '"/>';
			}
        } else {
            $used = ctrl_users::GetQuotaUsages('distlists', $currentuser['userid']);
            $free = max($maximum - $used, 0);
            return '<img src="etc/lib/pChart2/sentora/z3DPie.php?score=' . $free . '::' . $used
                    . '&labels=Free: ' . $free . '::Used: ' . $used
                    . '&legendfont=verdana&legendfontsize=8&imagesize=240::190&chartsize=120::90&radius=100&legendsize=150::160"'
                    . ' alt="' . ui_language::translate('Pie chart') . '"/>';
        }
    }

    /**
     * Template value <@ ResultURL @>: success message after a redirect with status=ok.
     *
     * @global runtime_controller $controller The request controller.
     * @return string|null The message HTML, or null.
     */
    static function getResultURL()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (isset($urlvars['status']) && $urlvars['status'] == 'ok') {
            return ui_sysmessage::shout(ui_language::translate("Changes to your distribution lists have been saved successfully!"), "zannounceok");
        }
    }

    /**
     * Template value <@ Result @>: the outcome of the last action.
     *
     * @return string|null The error or success message HTML, or null when there is none.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("A mailbox, alias, forwarder or distribution list already exists with that name."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$validemail)) {
            return ui_sysmessage::shout(ui_language::translate("Your email address is not valid."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$noaddress)) {
            return ui_sysmessage::shout(ui_language::translate("Your email address cannot be blank."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$validdomain)) {
            return ui_sysmessage::shout(ui_language::translate("The selected domain was not valid."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your distrubution lists have been saved successfully!"), "zannounceok");
        } else {
            return NULL;
        }
        return;
    }

}
