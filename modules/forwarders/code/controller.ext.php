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
 * Forwarders module: controller.
 *
 * Lists, creates and deletes mail forwarders; the mail server specific file (e.g.
 * code/postfix.php) is included to update the mail database.
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True when the last change was saved (shown by getResult()). */
    static $ok;
    /** @var bool|null True when the address already exists (never set in this module). */
    static $alreadyexists;
    /** @var bool|null True when the mailbox would be forwarded to itself. */
    static $alreadyexistssame;
    /** @var bool|null True when the destination is not a valid email address. */
    static $validemail;
    /** @var bool|null True when the source is not one of the user's mailboxes. */
    static $validmailbox;
    /** @var bool|null True when no source address was given. */
    static $noaddress;
    /** @var bool|null Tells the mail server file to delete a forwarder. */
    static $delete;
    /** @var bool|null Tells the mail server file to create a forwarder. */
    static $create;

    /**
     * List the forwarders of an account (start of the 'worker' methods).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (not used).
     * @param int|string $uid The account ID.
     * @return array<int, array<string, mixed>>|false One row per forwarder: 'address', 'destination', 'status' (keep-copy icon HTML), 'id'; false when there are none.
     */
    static function ListForwarders($uid)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $sql = "SELECT * FROM x_forwarders WHERE fw_acc_fk=:userid AND fw_deleted_ts IS NULL ORDER BY fw_address_vc ASC";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $currentuser['userid']);
            $res = array();
            $sql->execute();
            while ($rowforwarders = $sql->fetch()) {
                if ($rowforwarders['fw_keepmessage_in'] == 1) {
                    $status = ui_status::Ok('A copy of the original message will be left in the source mailbox address when it is fowarded to the destination address');
                } else {
                    $status = ui_status::Bad('The original message will only be available in the destination address');
                }
                $res[] = array('address' => $rowforwarders['fw_address_vc'],
                    'destination' => $rowforwarders['fw_destination_vc'],
                    'status' => $status,
                    'id' => $rowforwarders['fw_id_pk']);
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Get one forwarder of the logged-in account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $fid The forwarder ID (fw_id_pk), from the URL or form.
     * @return array<int, array<string, mixed>>|false A single row: 'address', 'destination', 'id'; false when not found or not owned.
     */
    static function ListCurrentForwarder($fid)
    {
        // Only this account's own records (the id comes from ?other= or the form)
        if (!ctrl_ownership::Owns('x_forwarders', 'fw_id_pk', 'fw_acc_fk', $fid)) {
            return false;
        }
        global $zdbh;
        $sql = "SELECT * FROM x_forwarders WHERE fw_id_pk=:fid AND fw_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':fid', $fid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':fid', $fid);
            $res = array();
            $sql->execute();
            while ($rowforwarders = $sql->fetch()) {
                $res[] = array('address' => $rowforwarders['fw_address_vc'],
                    'destination' => $rowforwarders['fw_destination_vc'],
                    'id' => $rowforwarders['fw_id_pk']);
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Produces a list of mailboxes only.
     *
     * Template loop <% loop MailboxList %>.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|null $uid Optional. The account ID. Default null (the logged-in user).
     * @return array<int, array<string, mixed>>|false One row per mailbox: 'address', 'id'; false when there are none.
     */
    static function getMailboxList($uid = null)
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail($uid);
        
        $sql = "SELECT * FROM x_mailboxes WHERE mb_acc_fk=:userid AND mb_deleted_ts IS NULL ORDER BY mb_address_vc ASC";
        $binds = array(':userid' => $currentuser['userid']);
        $prepared = $zdbh->bindQuery($sql, $binds);
        
        $rows = $prepared->fetchAll(PDO::FETCH_ASSOC);
        $return = array();
        
        if (count($rows) > 0) {
            foreach ($rows as $row) {
                $return[] = array('address' => $row['mb_address_vc'], 'id' => $row['mb_id_pk']);
            }
            return $return;
        } else {
            return false;
        }
    }

    /**
     * Create a forwarder from one of the user's mailboxes.
     *
     * Validates the input, includes the mail server file to update the alias and inserts
     * the x_forwarders row. Sets self::$ok on success.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $uid The account ID.
     * @param string $address The source mailbox address.
     * @param string $dname The destination local part.
     * @param string $ddomain The destination domain.
     * @param int $keepmessage 1 to keep a copy in the source mailbox, else 0.
     * @return bool True when created, false when validation failed.
     */
    static function ExecuteCreateForwarder($uid, $address, $dname, $ddomain, $keepmessage)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrors($address, $dname, $ddomain, $keepmessage))) {
            return false;
        }
        $destination = strtolower(str_replace(' ', '', $dname . '@' . $ddomain));
        runtime_hook::Execute('OnBeforeCreateForwarder');
        self::$create = true;
        // Include mail server specific file here.
        $MailServerFile = 'modules/' . $controller->GetControllerRequest('URL', 'module') . '/code/' . ctrl_options::GetSystemOption('mailserver_php');
        if (file_exists($MailServerFile))
            include($MailServerFile);
        $sql = "INSERT INTO x_forwarders (fw_acc_fk,
											  fw_address_vc,
											  fw_destination_vc,
											  fw_keepmessage_in,
											  fw_created_ts) VALUES (
											  :userid,
											  :address,
											  :destination,
											  :keepmessage,
											  :time)";
        $sql = $zdbh->prepare($sql);
        $sql->bindParam(':userid', $currentuser['userid']);
        $sql->bindParam(':address', $address);
        $sql->bindParam(':destination', $destination);
        $sql->bindParam(':keepmessage', $keepmessage);
        $sql->bindValue(':time', time(), PDO::PARAM_INT); // bindParam refuses time() in PHP 8
        $sql->execute();
        runtime_hook::Execute('OnAfterCreateForwarder');
        self::$ok = true;
        return true;
    }

    /**
     * Delete a forwarder.
     *
     * Includes the mail server file to reset the alias, then marks the forwarder deleted.
     * Sets self::$ok.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $fw_id_pk The forwarder ID.
     * @return void
     */
    static function ExecuteDeleteForwarder($fw_id_pk)
    {
        global $zdbh;
        global $controller;
        runtime_hook::Execute('OnBeforeDeleteForwarder');
        runtime_hook::Execute('OnBeforeDeleteForwarer'); // the old misspelt name, for modules that still listen to it
        //$rowforwarder = $zdbh->query("SELECT * FROM x_forwarders WHERE fw_id_pk=" . $fw_id_pk . "")->fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_forwarders WHERE fw_id_pk=:fw_id_pk");
        $numrows->bindParam(':fw_id_pk', $fw_id_pk);
        $numrows->execute();
        $rowforwarder = $numrows->fetch();
        self::$delete = true;
        // Include mail server specific file here.
        $MailServerFile = 'modules/' . $controller->GetControllerRequest('URL', 'module') . '/code/' . ctrl_options::GetSystemOption('mailserver_php');
        if (file_exists($MailServerFile))
            include($MailServerFile);
        $sql = "UPDATE x_forwarders SET fw_deleted_ts=:time WHERE fw_id_pk=:fw_id_pk";
        $sql = $zdbh->prepare($sql);
        $sql->bindParam(':fw_id_pk', $fw_id_pk);
        $sql->bindValue(':time', time(), PDO::PARAM_INT); // bindParam refuses time() in PHP 8
        $sql->execute();
        runtime_hook::Execute('OnAfterDeleteForwarder');
        self::$ok = true;
    }

    /**
     * Validate a new forwarder.
     *
     * Reads the source address from the posted 'inAddress' (the $address argument is
     * overwritten). Sets self::$noaddress, self::$validemail, self::$validmailbox or
     * self::$alreadyexistssame on failure.
     *
     * @global runtime_controller $controller The request controller.
     * @param string $address The source mailbox address (ignored, see above).
     * @param string $dname The destination local part.
     * @param string $ddomain The destination domain.
     * @param int $keepmessage Keep-copy flag (not used).
     * @return bool True when the forwarder can be created.
     */
    static function CheckCreateForErrors($address, $dname, $ddomain, $keepmessage)
    {
        global $zdbh;
        $address = strtolower(trim((string) $address)); // the address given (this read the form again)
        $destination = strtolower(str_replace(' ', '', $dname . '@' . $ddomain));
        if (fs_director::CheckForEmptyValue($address)) {
            self::$noaddress = true;
            return false;
        }
        if (!self::IsValidEmail($destination)) {
            self::$validemail = true;
            return false;
        }
         if (!self::IsValidMailbox($address)) {
            self::$validmailbox = true;
            return false;
        }  
        
        if ($address == $destination) {
            self::$alreadyexistssame = true;
            return false;
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_forwarders WHERE fw_address_vc = :a AND fw_destination_vc = :d AND fw_deleted_ts IS NULL");
        $q->execute(array(':a' => $address, ':d' => $destination));
        if ((int) $q->fetchColumn() > 0) {
            self::$alreadyexists = true;
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
     * Check that an address is one of the logged-in user's mailboxes.
     *
     * @param string $address The mailbox address.
     * @return bool True when the user owns the mailbox.
     */
    static function IsValidMailbox($address)
    {
        foreach (self::getMailboxList() as $key => $checkMailbox) {
            if (array_key_exists('address', $checkMailbox) && $checkMailbox['address'] == $address) {
                return true;
            }
        }
        return false;
    }

    // End 'worker' methods.

    /**
     * Handle the create form (start of the web interface methods).
     *
     * @global runtime_controller $controller The request controller.
     * @return true Always true.
     */
    static function doCreateForwarder()
    {
        global $controller;
        runtime_csfr::Protect();
        // The package's limit on forwarders, checked here as well: the page only hides the
        // form at the limit, so a posted form could create more than the package allows
        if (!self::getQuotaLimit()) {
            return false;
        }
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        $keepmessage = (isset($formvars['inKeepMessage'])) ? fs_director::GetCheckboxValue($formvars['inKeepMessage']) : 0;
        $dname = strtolower(trim(isset($formvars['inDestinationName']) ? (string) $formvars['inDestinationName'] : ''));
        $ddomain = strtolower(trim(isset($formvars['inDestinationDomain']) ? (string) $formvars['inDestinationDomain'] : ''));
        if ($ddomain === '' && strpos($dname, '@') !== false) {
            $ddomain = substr($dname, strrpos($dname, '@') + 1);
            $dname = substr($dname, 0, strrpos($dname, '@'));
        }
        if (self::ExecuteCreateForwarder($currentuser['userid'], isset($formvars['inAddress']) ? strtolower((string) $formvars['inAddress']) : '', $dname, $ddomain, $keepmessage))
            self::$ok = true;
        return true;
    }

    /**
     * Handle a list Delete button: redirect to the delete view and exit.
     *
     * @global runtime_controller $controller The request controller.
     * @return true True when no Delete button matched (otherwise the script exits).
     */
    static function doDeleteForwarder()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListForwarders($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['id'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['id']);
                exit;
            }
        }
        return true;
    }

    /**
     * Handle the delete confirmation form.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when the forwarder is missing or not owned, otherwise null.
     */
    static function doConfirmDeleteForwarder()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inDelete']) || !ctrl_ownership::Owns('x_forwarders', 'fw_id_pk', 'fw_acc_fk', $formvars['inDelete'], ' AND fw_deleted_ts IS NULL')) {
            return false;
        }
        return self::ExecuteDeleteForwarder($formvars['inDelete']);
    }

    /**
     * Template loop <% loop ForwarderList %>: the logged-in user's forwarders.
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return array<int, array<string, mixed>>|false See ListForwarders().
     */
    static function getForwarderList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListForwarders($currentuser['userid']);
    }

    /**
     * Template flag <% if isCreateForwarder %>: show the list and create form?
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when there is no ?show=.
     */
    static function getisCreateForwarder()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        return !isset($urlvars['show']);
    }

    /**
     * Template flag <% if isDeleteForwarder %>: show the delete view?
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|null $uid Optional. The account ID. Default null (the logged-in user).
     * @return bool|null True when ?show=Delete and the forwarder in ?other= is the user's; null when it is not.
     */
    static function getisDeleteForwarder($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete Mail forwarders.
        // This shall avoid exposing mail forwarder based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

    	$sql = "SELECT * FROM x_forwarders WHERE fw_acc_fk=:userid AND fw_id_pk=:editedUsrID AND fw_deleted_ts IS NULL";
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
     * Template value <@ EditCurrentForwarderName @>: address of the forwarder in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The address ('' when there is no ?other=; null when it is not owned).
     */
    static function getEditCurrentForwarderName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentForwarder($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['address'];
        } else {
            return '';
        }
    }

    /**
     * Template value <@ EditCurrentForwarderID @>: ID of the forwarder in ?other=.
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The ID ('' when there is no ?other=; null when it is not owned).
     */
    static function getEditCurrentForwarderID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentForwarder($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['id'];
        } else {
            return '';
        }
    }

    /**
     * Read a mail setting from x_mail_settings.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name The setting name (mbs_name_vc).
     * @return string|false The value, or false when the setting does not exist.
     */
    static function GetMailOption($name)
    {
        global $zdbh;
        //$result = $zdbh->query("SELECT mbs_value_tx FROM x_mail_settings WHERE mbs_name_vc = '$name'")->Fetch();
        $numrows = $zdbh->prepare("SELECT mbs_value_tx FROM x_mail_settings WHERE mbs_name_vc = :name");
        $numrows->bindParam(':name', $name);
        $numrows->execute();
        $result = $numrows->fetch();
        return ($result) ? $result['mbs_value_tx'] : false;
    }

    /**
     * Template flag <% if QuotaLimit %>: may the user create another forwarder?
     *
     * @return bool True when the quota is unlimited (-1) or not used up.
     */
    static function getQuotaLimit()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['forwardersquota'] < 0) or //-1 = unlimited
                ($currentuser['forwardersquota'] > self::UsedForwards($currentuser['userid']));
    }

    /**
     * How many forwarders and aliases the account has: both count toward the package's forwarder limit (as in Aliases).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID.
     * @return int The number.
     */
    static function UsedForwards($uid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_aliases WHERE al_acc_fk = :u AND al_deleted_ts IS NULL");
        $q->execute(array(':u' => (int) $uid));
        return (int) ctrl_users::GetQuotaUsages('forwarders', $uid) + (int) $q->fetchColumn();
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
     * The account's forwarders as plain rows.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>> x_forwarders rows, by mailbox then destination.
     */
    static function Rows()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_forwarders WHERE fw_acc_fk = :u AND fw_deleted_ts IS NULL ORDER BY fw_address_vc, fw_destination_vc");
        $q->execute(array(':u' => (int) ctrl_users::GetUserDetail()['userid']));
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The figures at the top (<@ Tiles @>): forwarders, mailboxes forwarded, and forwarders and aliases against the package's forwarder limit.
     *
     * @return string The HTML.
     */
    static function getTiles()
    {
        $me = ctrl_users::GetUserDetail();
        $rows = self::Rows();
        $boxes = count(array_unique(array_map(function ($r) { return strtolower($r['fw_address_vc']); }, $rows)));
        $max = (int) $me['forwardersquota'];
        $used = self::UsedForwards($me['userid']);
        $pct = $max > 0 ? min(100, round($used / $max * 100)) : 0;
        $ico = function ($d) { return '<svg class="fw-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>'; };
        return '<div class="fw-tile">' . $ico('<path d="M15 4h5v5"/><path d="M20 4 13 11"/><path d="M4 8v11a1 1 0 0 0 1 1h11"/>') . '<div><b>' . count($rows) . '</b><span>' . self::t('Forwarders') . '</span></div></div>'
            . '<div class="fw-tile">' . $ico('<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/>') . '<div><b>' . $boxes . '</b><span>' . self::t('Mailboxes forwarded') . '</span></div></div>'
            . '<div class="fw-tile' . ($max >= 0 && $used >= $max ? ' full' : '') . '">' . $ico('<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>')
            . '<div><b>' . $used . ($max < 0 ? '' : ' <small>/ ' . $max . '</small>') . '</b><span>' . self::t('Forwarders and aliases') . ($max < 0 ? ' &middot; ' . self::t('no limit') : '') . '</span>'
            . ($max > 0 ? '<div class="fw-bar"><i style="width:' . $pct . '%"></i></div>' : '') . '</div></div>';
    }

    /**
     * The forwarders grouped by mailbox (<@ ForwarderRows @>): each mailbox with where its mail goes, whether a copy stays, and Delete.
     *
     * @return string The HTML ('' when there are none).
     */
    static function getForwarderRows()
    {
        $groups = array();
        foreach (self::Rows() as $r) {
            $groups[strtolower($r['fw_address_vc'])][] = $r;
        }
        $own = array_map('strtolower', array_column(self::getMailboxList() ?: array(), 'address'));
        $out = '';
        foreach ($groups as $box => $rows) {
            $keep = count(array_filter($rows, function ($r) { return (int) $r['fw_keepmessage_in'] === 1; })) > 0;
            $dest = '';
            foreach ($rows as $r) {
                $here = in_array(strtolower($r['fw_destination_vc']), $own, true);
                $dest .= '<div class="fw-dest"><svg class="fw-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>'
                    . '<span class="fw-to">' . self::h($r['fw_destination_vc']) . '</span>'
                    . '<span class="fw-where ' . ($here ? 'here' : 'out') . '">' . self::t($here ? 'your mailbox' : 'outside address') . '</span>'
                    . '<button class="btn btn-default btn-sm fw-del" type="submit" name="inDelete_' . (int) $r['fw_id_pk'] . '" value="inDelete_' . (int) $r['fw_id_pk'] . '" title="' . self::t('Delete') . '" aria-label="' . self::t('Stop forwarding to') . ' ' . self::h($r['fw_destination_vc']) . '">'
                    . '<svg class="fw-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></button></div>';
            }
            $out .= '<div class="fw-group" data-s="' . self::h($box . ' ' . implode(' ', array_column($rows, 'fw_destination_vc'))) . '">'
                . '<div class="fw-from"><svg class="fw-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/></svg>'
                . '<b>' . self::h($box) . '</b><span class="fw-keep ' . ($keep ? 'yes' : 'no') . '">' . self::t($keep ? 'keeps a copy' : 'no copy kept') . '</span></div>'
                . '<div class="fw-dests">' . $dest . '</div></div>';
        }
        return $out;
    }

    /**
     * The forwarder being deleted, as "mailbox to destination" (<@ DeleteName @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The escaped text.
     */
    static function getDeleteName()
    {
        global $controller;
        $cur = self::ListCurrentForwarder($controller->GetControllerRequest('URL', 'other'));
        return $cur ? self::h($cur[0]['address']) . ' &rarr; ' . self::h($cur[0]['destination']) : '';
    }

    /**
     * The mailbox choices for a new forwarder, the one picked before selected (<@ MailboxOptions @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The <option> HTML.
     */
    static function getMailboxOptions()
    {
        global $controller;
        $picked = strtolower((string) $controller->GetControllerRequest('FORM', 'inAddress'));
        $out = '';
        foreach (self::getMailboxList() ?: array() as $m) {
            $out .= '<option value="' . self::h($m['address']) . '"' . (strtolower($m['address']) === $picked ? ' selected' : '') . '>' . self::h($m['address']) . '</option>';
        }
        return $out;
    }

    /**
     * The destination typed when adding failed, so it is not lost (<@ PostedDestination @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The escaped address.
     */
    static function getPostedDestination()
    {
        global $controller;
        if (self::$ok) {
            return '';
        }
        $n = (string) $controller->GetControllerRequest('FORM', 'inDestinationName');
        $d = (string) $controller->GetControllerRequest('FORM', 'inDestinationDomain');
        return self::h($d !== '' ? $n . '@' . $d : $n);
    }

    /**
     * Template value <@ Result @>: the outcome of the last action.
     *
     * @return string|null The error or success message HTML, or null when there is none.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$alreadyexistssame)) {
            return ui_sysmessage::shout(ui_language::translate("You cannot forward a mailbox to itself!"), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate('That mailbox already forwards to that address.'), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$validemail)) {
            return ui_sysmessage::shout(ui_language::translate("Your email address is not valid."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$noaddress)) {
            return ui_sysmessage::shout(ui_language::translate("Your email address cannot be blank."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$validmailbox)) {
            return ui_sysmessage::shout(ui_language::translate("The mailbox chosen is invalid."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your forwarders have been saved successfully!"), "zannounceok");
        } else {
            return NULL;
        }
        return;
    }

    // Webinterface sudo methods.
}
