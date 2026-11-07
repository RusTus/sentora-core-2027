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

    /** @var bool|null True when the address is on the list already (shown by getResult()); it was set without being declared, which stopped the page. */
    static $alreadyexistsdu;

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
        $sql->bindValue(':time', time(), PDO::PARAM_INT); // bindParam refuses time() in PHP 8
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
        $sql->bindValue(':time', time(), PDO::PARAM_INT); // bindParam refuses time() in PHP 8
        $sql->execute();
        $sql = "UPDATE x_distlists SET dl_deleted_ts=:time WHERE dl_id_pk=:dl_id_pk";
        $sql = $zdbh->prepare($sql);
        $sql->bindParam(':dl_id_pk', $dl_id_pk);
        $sql->bindValue(':time', time(), PDO::PARAM_INT); // bindParam refuses time() in PHP 8
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
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrorsDistListUser($du_distlist_fk, $address, $domain))) {
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
        $sql->bindValue(':time', time(), PDO::PARAM_INT); // bindParam refuses time() in PHP 8
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
        // COUNT: fetch() and then fetchColumn() read past the only row, so a mailbox's address was never seen
        $sql = "SELECT COUNT(*) FROM x_mailboxes WHERE mb_address_vc=:fulladdress AND mb_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':fulladdress', $fulladdress);
        $numrows->execute();
        if ((int) $numrows->fetchColumn() <> 0) {
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
     * Validate a new list member: the values given, else the posted inAddAddress, inAddDomain and inDLID.
     *
     * Sets self::$noaddress, self::$validemail or self::$alreadyexistsdu on failure.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string|null $dlid Optional. The list ID. Default null (the form's).
     * @param string|null $address Optional. The part before @. Default null (the form's).
     * @param string|null $domain Optional. The part after @. Default null (the form's).
     * @return bool True when the member can be added.
     */
    static function CheckCreateForErrorsDistListUser($dlid = null, $address = null, $domain = null)
    {
        global $zdbh;
        global $controller;
        // the values given, else the form's (as before)
        $address = $address !== null ? $address : $controller->GetControllerRequest('FORM', 'inAddAddress');
        $domain = $domain !== null ? $domain : $controller->GetControllerRequest('FORM', 'inAddDomain');
        $dlid = $dlid !== null ? $dlid : $controller->GetControllerRequest('FORM', 'inDLID');
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
            // one field for the whole address now; split at the last @ (the two old fields still work)
            $addr = strtolower(trim(isset($formvars['inAddAddress']) ? (string) $formvars['inAddAddress'] : ''));
            $dom = strtolower(trim(isset($formvars['inAddDomain']) ? (string) $formvars['inAddDomain'] : ''));
            if ($dom === '' && strpos($addr, '@') !== false) {
                $dom = substr($addr, strrpos($addr, '@') + 1);
                $addr = substr($addr, 0, strrpos($addr, '@'));
            }
            if (self::ExecuteAddDistListUser($formvars['inDLID'], $addr, $dom, '')) {
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
        // The package's limit on distribution lists, checked here as well: the page only hides the
        // form at the limit, so a posted form could create more than the package allows
        if (!self::getQuotaLimit()) {
            return false;
        }
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
     * Escape a value for HTML.
     *
     * @param mixed $s The value (cast to string).
     * @return string The escaped text.
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translate and escape a text.
     *
     * @param string $s The English text.
     * @return string The escaped translation.
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * The figures at the top (<@ Tiles @>): lists against the package's limit, members, and lists with no members.
     *
     * @return string The HTML.
     */
    static function getTiles()
    {
        $me = ctrl_users::GetUserDetail();
        $lists = self::ListDist($me['userid']) ?: array();
        $members = array_sum(array_map(function ($l) { return (int) $l['totalmb']; }, $lists));
        $empty = count(array_filter($lists, function ($l) { return (int) $l['totalmb'] === 0; }));
        $max = (int) $me['distlistsquota'];
        $used = count($lists);
        $pct = $max > 0 ? min(100, round($used / $max * 100)) : 0;
        $ico = function ($d) { return '<svg class="dl-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>'; };
        return '<div class="dl-tile' . ($max >= 0 && $used >= $max ? ' full' : '') . '">' . $ico('<path d="M4 4h16v16H4z"/><path d="m4 7 8 6 8-6"/>')
            . '<div><b>' . $used . ($max < 0 ? '' : ' <small>/ ' . $max . '</small>') . '</b><span>' . self::t('Distribution lists') . ($max < 0 ? ' &middot; ' . self::t('no limit') : '') . '</span>'
            . ($max > 0 ? '<div class="dl-bar"><i style="width:' . $pct . '%"></i></div>' : '') . '</div></div>'
            . '<div class="dl-tile">' . $ico('<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6"/>') . '<div><b>' . $members . '</b><span>' . self::t('Members in all') . '</span></div></div>'
            . '<div class="dl-tile' . ($empty ? ' warn' : '') . '">' . $ico('<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>') . '<div><b>' . $empty . '</b><span>' . self::t('Lists with no members') . '</span></div></div>';
    }

    /**
     * One card per list (<@ ListCards @>): its address, how many members and the first of them, Members and Delete
     * (buttons of the EditDistList form).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML ('' when there are none).
     */
    static function getListCards()
    {
        global $zdbh;
        $lists = self::ListDist(ctrl_users::GetUserDetail()['userid']);
        if (!$lists) {
            return '';
        }
        $q = $zdbh->prepare("SELECT du_address_vc FROM x_distlistusers WHERE du_distlist_fk = :id AND du_deleted_ts IS NULL ORDER BY du_address_vc");
        $out = '';
        foreach ($lists as $l) {
            $id = (int) $l['id'];
            $q->execute(array(':id' => $id));
            $members = $q->fetchAll(PDO::FETCH_COLUMN);
            $n = count($members);
            $pills = '';
            foreach (array_slice($members, 0, 5) as $m) {
                $pills .= '<span class="dl-pill">' . self::h($m) . '</span>';
            }
            $addr = (string) $l['address'];
            $at = strrpos($addr, '@');
            $out .= '<div class="dl-card' . ($n ? '' : ' is-empty') . '" data-s="' . self::h(strtolower($addr . ' ' . implode(' ', $members))) . '">'
                . '<div class="dl-main"><div class="dl-addr"><svg class="dl-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6"/></svg>'
                . '<span class="dl-full"><b>' . self::h($at === false ? $addr : substr($addr, 0, $at)) . '</b><span class="dl-dom">' . self::h($at === false ? '' : substr($addr, $at)) . '</span></span>'
                . '<span class="dl-count">' . ($n === 1 ? self::t('1 member') : str_replace('%d', (string) $n, self::t('%d members'))) . '</span></div>'
                . '<div class="dl-members">' . ($n ? $pills . ($n > 5 ? '<span class="dl-more">+' . ($n - 5) . '</span>' : '') : '<span class="dl-none">' . self::t('No members yet: mail to this address goes nowhere') . '</span>') . '</div></div>'
                . '<div class="dl-act"><button class="btn btn-primary btn-sm" type="submit" name="inEdit_' . $id . '" value="inEdit_' . $id . '">'
                . '<svg class="dl-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M19 8v6M16 11h6"/></svg>' . self::t('Members') . '</button>'
                . '<button class="btn btn-default btn-sm dl-del" type="submit" name="inDelete_' . $id . '" value="inDelete_' . $id . '" title="' . self::t('Delete') . '" aria-label="' . self::t('Delete') . ' ' . self::h($addr) . '">'
                . '<svg class="dl-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></button></div>'
                . '</div>';
        }
        return $out;
    }

    /**
     * The members of the list being edited, each with Remove (buttons of the UpdateDistList form) (<@ MemberRows @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getMemberRows()
    {
        global $controller;
        $list = self::ListDistUsers($controller->GetControllerRequest('URL', 'other'));
        if (!$list) {
            return '<p class="dl-empty">' . self::t('No members yet: add the first below.') . '</p>';
        }
        $own = array_map('strtolower', array_column(self::ListMailbox(ctrl_users::GetUserDetail()['userid']) ?: array(), 'address'));
        $out = '';
        foreach ($list as $m) {
            $here = in_array(strtolower($m['address']), $own, true);
            $out .= '<div class="dl-mrow"><span class="dl-avatar" aria-hidden="true">' . self::h(strtoupper(substr($m['address'], 0, 1))) . '</span>'
                . '<span class="dl-maddr">' . self::h($m['address']) . '</span>'
                . '<span class="dl-where ' . ($here ? 'here' : 'out') . '">' . self::t($here ? 'your mailbox' : 'outside address') . '</span>'
                . '<button class="btn btn-default btn-sm dl-del" type="submit" name="inDeleteUser_' . (int) $m['id'] . '" value="' . (int) $m['id'] . '">' . self::t('Remove') . '</button></div>';
        }
        return $out;
    }

    /**
     * How many members the list being edited has (<@ MemberCount @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int
     */
    static function getMemberCount()
    {
        global $controller;
        $list = self::ListDistUsers($controller->GetControllerRequest('URL', 'other'));
        return $list ? count($list) : 0;
    }

    /**
     * The account's mailboxes as suggestions for a new member (<@ MailboxSuggestions @>).
     *
     * @return string The <option> HTML for a datalist.
     */
    static function getMailboxSuggestions()
    {
        $out = '';
        foreach (self::ListMailbox(ctrl_users::GetUserDetail()['userid']) ?: array() as $m) {
            $out .= '<option value="' . self::h($m['address']) . '"></option>';
        }
        return $out;
    }

    /**
     * The domain choices for a new list (<@ DomainOptions @>), the one picked before selected.
     *
     * @global runtime_controller $controller The request controller.
     * @return string The <option> HTML.
     */
    static function getDomainOptions()
    {
        global $controller;
        $picked = (string) $controller->GetControllerRequest('FORM', 'inDomain');
        $out = '';
        foreach (self::getDomainList() ?: array() as $d) {
            $out .= '<option value="' . self::h($d['domain']) . '"' . ($d['domain'] === $picked ? ' selected' : '') . '>@' . self::h($d['domain']) . '</option>';
        }
        return $out;
    }

    /**
     * The name typed when making a list failed, so it is not lost (<@ PostedAddress @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The escaped text.
     */
    static function getPostedAddress()
    {
        global $controller;
        return self::$ok ? '' : self::h((string) $controller->GetControllerRequest('FORM', 'inAddress'));
    }

    /**
     * What deleting the list does (<@ DeleteDetails @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getDeleteDetails()
    {
        global $controller;
        $list = self::ListDistUsers($controller->GetControllerRequest('URL', 'other'));
        $n = $list ? count($list) : 0;
        if ($n === 0) {
            $what = self::t('Mail sent to the list is no longer passed on.');
        } elseif ($n === 1) {
            $what = self::t('Mail sent to the list is no longer passed on to its one member.');
        } else {
            $what = str_replace('%d', (string) $n, self::t('Mail sent to the list is no longer passed on to its %d members.'));
        }
        return '<ul class="dl-lose"><li>' . $what . '</li>'
            . '<li>' . self::t('The members\' own mailboxes and their mail stay.') . '</li></ul>';
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
        if (!fs_director::CheckForEmptyValue(self::$alreadyexistsdu)) {
            return ui_sysmessage::shout(ui_language::translate("That address is on the list already."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("A mailbox, alias, forwarder or distribution list already has that address."), "zannounceerror");
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
            return ui_sysmessage::shout(ui_language::translate("Changes to your distribution lists have been saved successfully!"), "zannounceok");
        } else {
            return NULL;
        }
        return;
    }

}
