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
 * Updated by RusTus, 2026-11-07
 */
/**
 * Aliases module: controller.
 *
 * Lists, creates and deletes mail aliases (x_aliases) for the logged-in account; the
 * mail server specific work is in the file named by the mailserver_php setting.
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True when the destination is not one of the account's own mailboxes (shown by getResult()). */
    static $baddestination;

    /** @var bool|null True when the last change was saved (shown by getResult()). */
    static $ok;
    /** @var bool|null True when the address is already used by a mailbox, alias, forwarder or list. */
    static $alreadyexists;
    /** @var bool|null True when the address was not valid (the name is inverted). */
    static $validemail;
    /** @var bool|null True when the chosen domain is not one of the account's active domains. */
    static $validdomain;
    /** @var bool|null True when the address was left blank. */
    static $noaddress;
    /** @var bool|null Set while an alias is deleted (read by the mail server include). */
    static $delete;
    /** @var bool|null Set while an alias is created (read by the mail server include). */
    static $create;

    // The 'worker' methods.
    /**
     * List the aliases of an account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller. Declared but not used.
     * @param int|string $uid The account ID.
     * @return array<int, array<string, mixed>>|false One row per alias: 'address', 'destination', 'id'; false when there are none.
     */
    static function ListAliases($uid)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $sql = "SELECT * FROM x_aliases WHERE al_acc_fk=:userid AND al_deleted_ts IS NULL ORDER BY al_address_vc ASC";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql2 = $zdbh->prepare($sql);
            $res = array();
            $sql2->bindParam(':userid', $currentuser['userid']);
            $sql2->execute();
            while ($rowaliases = $sql2->fetch()) {
                array_push($res, array('address' => $rowaliases['al_address_vc'],
                    'destination' => $rowaliases['al_destination_vc'],
                    'id' => $rowaliases['al_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Return one alias of the logged-in account as a one-row list.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller. Declared but not used.
     * @param int|string $aid The alias ID (from ?other= or the form).
     * @return array<int, array<string, mixed>>|false One row: 'address', 'destination', 'id'; false when it is not the account's or does not exist.
     */
    static function ListCurrentAlias($aid)
    {
        // Only this account's own records (the id comes from ?other= or the form)
        if (!ctrl_ownership::Owns('x_aliases', 'al_id_pk', 'al_acc_fk', $aid)) {
            return false;
        }
        global $zdbh;
        global $controller;
        $sql = "SELECT * FROM x_aliases WHERE al_id_pk=:aid AND al_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':aid', $aid);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->bindParam(':aid', $aid);
            $sql->execute();
            while ($rowaliases = $sql->fetch()) {
                array_push($res, array('address' => $rowaliases['al_address_vc'],
                    'destination' => $rowaliases['al_destination_vc'],
                    'id' => $rowaliases['al_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the mailboxes of an account (template: <% loop MailboxList %>).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string|null $uid Optional. The account ID. Default null (the logged-in user).
     * @return array<int, array<string, mixed>>|false One row per mailbox: 'address', 'id'; false when there are none.
     */
    static function getMailboxList($uid = null)
    {
        global $zdbh;
        global $controller;
        if (($uid == '') || (empty($uid)) || ($uid == null)) {
            $uid = ctrl_auth::CurrentUserID();
        }
        $currentuser = ctrl_users::GetUserDetail($uid);
        $sql = "SELECT * FROM x_mailboxes WHERE mb_acc_fk=:userid AND mb_deleted_ts IS NULL ORDER BY mb_address_vc ASC";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sqlRun = $zdbh->prepare($sql);
            $sqlRun->bindParam(':userid', $currentuser['userid']);
            $res = array();
            $sqlRun->execute();
            while ($rowmailboxes = $sqlRun->fetch()) {
                array_push($res, array('address' => $rowmailboxes['mb_address_vc'],
                    'id' => $rowmailboxes['mb_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }
    
    /**
     * Produces a list of domain names only (template: <% loop DomainList %>).
     *
     * Only the account's enabled domains are listed.
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string|null $uid Optional. The account ID. Default null (the logged-in user).
     * @return array<int, array<string, string>>|false One row per domain: 'domain'; false when there are none.
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
     * Create an alias after checking it (CheckCreateForErrors()).
     *
     * Runs the OnBeforeCreateAlias/OnAfterCreateAlias hooks, includes the mail server
     * specific file and inserts the x_aliases row.
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $uid The account ID.
     * @param string $address The part before the @ ('*' is removed).
     * @param string $domain The domain name.
     * @param string $destination The address mail is delivered to (spaces removed, lowercased).
     * @return bool True when the alias was created.
     */
    static function ExecuteCreateAlias($uid, $address, $domain, $destination)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrors($address, $domain, $destination))) {
            return false;
        }
        runtime_hook::Execute('OnBeforeCreateAlias');
        $address = str_replace('*', '', $address);
        $fulladdress = $address . "@" . $domain;
        $destination = strtolower(str_replace(' ', '', $destination));
        self::$create = true;
        // Include mail server specific file here.
        include("modules/" . $controller->GetControllerRequest('URL', 'module') . "/code/" . ctrl_options::GetSystemOption('mailserver_php') . "");

        $bindArray = array(
            ':userid' => $currentuser['userid'],
            ':fulladdress' => $fulladdress,
            ':destination' => $destination,
            ':time' => time()
        );
        $sql = "INSERT INTO x_aliases (al_acc_fk,
											  al_address_vc,
											  al_destination_vc,
											  al_created_ts) VALUES (
											  :userid,
											  :fulladdress,
											  :destination,
											  :time)";
        if ($zdbh->bindQuery($sql, $bindArray)) {
            runtime_hook::Execute('OnAfterCreateAlias');
            self::$ok = true;
            return true;
        } else {
            return false;
        }
    }

    /**
     * Delete an alias (sets al_deleted_ts) and run the mail server specific delete.
     *
     * The caller must have checked ownership. Note: returns nothing, so
     * doConfirmDeleteAlias() always reports false.
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string $al_id_pk The alias ID.
     * @return void
     */
    static function ExecuteDeleteAlias($al_id_pk)
    {
        global $zdbh;
        global $controller;
        self::$delete = true;
        runtime_hook::Execute('OnBeforeDeleteAlias');
        //$rowalias = $zdbh->query("SELECT * FROM x_aliases WHERE al_id_pk=" . $al_id_pk . "")->Fetch();
        $bindArray = array(
            ':id' => $al_id_pk,
        );
        $sqlStatment = $zdbh->bindQuery("SELECT * FROM x_aliases WHERE al_id_pk=:id", $bindArray);
        $rowalias = $zdbh->returnRow();

        // Include mail server specific file here.
        if (file_exists("modules/" . $controller->GetControllerRequest('URL', 'module') . "/code/" . ctrl_options::GetSystemOption('mailserver_php') . "")) {
            include("modules/" . $controller->GetControllerRequest('URL', 'module') . "/code/" . ctrl_options::GetSystemOption('mailserver_php') . "");
        }
        $sqlStatmentUpdate = "UPDATE x_aliases SET al_deleted_ts=:time WHERE al_id_pk=:id";
        $sql = $zdbh->prepare($sqlStatmentUpdate);
        $sql->bindParam(':id', $al_id_pk);
        // bindValue: PHP 8 refuses time() in bindParam (it stopped here, after Postfix had
        // already dropped the alias, so the panel still listed it)
        $sql->bindValue(':time', time(), PDO::PARAM_INT);
        $sql->execute();
        runtime_hook::Execute('OnAfterDeleteAlias');
        self::$ok = true;
    }

    /**
     * Check a new alias and set the matching error flag.
     *
     * Checks the address is not blank, is valid, the domain is the account's, and that no
     * mailbox, forwarder, distribution list or alias already uses it.
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller. Declared but not used.
     * @param string $address The part before the @.
     * @param string $domain The domain name.
     * @param string $destination The destination address (not checked).
     * @return bool True when there are no errors.
     */
    static function CheckCreateForErrors($address, $domain, $destination)
    {
        global $zdbh;
        global $controller;
        $fulladdress = $address . "@" . $domain;
        $destination = strtolower(str_replace(' ', '', $destination));
        if (fs_director::CheckForEmptyValue($address)) {
            self::$noaddress = true;
            return false;
        }
        if (!self::IsValidEmail($fulladdress)) {
            self::$validemail = true;
            return false;
        }
        if(!self::IsValidDomain($domain)){
            self::$validdomain = true;
            return false;        
        }
        // The destination must be one of the account's own mailboxes: it came from the form
        // unchecked, so mail could be sent on to any address (another account's mailbox, or
        // outside this server) without counting as a forwarder
        $own = false;
        foreach (self::getMailboxList() ?: array() as $mb) {
            if (strtolower($mb['address']) === $destination) {
                $own = true;
            }
        }
        if (!$own) {
            self::$baddestination = true;
            return false;
        }
        $sql = "SELECT * FROM x_mailboxes WHERE mb_address_vc=:fulladdress AND mb_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':fulladdress', $fulladdress);
        $numrows->execute();

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
        $sql = "SELECT * FROM x_forwarders WHERE fw_destination_vc=:fulladdress AND fw_deleted_ts IS NULL";
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
     * Is $email a valid address (a '*' catch-all local part is allowed)?
     *
     * @param string $email The full address.
     * @return bool
     */
    static function IsValidEmail($email)
    {
        if (!preg_match('/^([\*]|[a-z0-9]+([_\\.-][a-z0-9]+)*)@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i', $email)) {
            return false;
        }
        return true;
    }
    
    /**
     * Is $domain one of the logged-in account's enabled domains?
     *
     * @param string $domain The domain name.
     * @return bool
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
     * Handle the create alias form (POST).
     *
     * Checks the CSFR token (dies when it is wrong) and creates the alias from inAddress,
     * inDomain and inDestination; errors are left in the static flags.
     * @global runtime_controller $controller The request controller.
     * @return bool Always true.
     */
    static function doCreateAlias()
    {
        global $controller;
        runtime_csfr::Protect();
        // The package's limit on aliases, checked here as well: the page only hides the
        // form at the limit, so a posted form could create more than the package allows
        if (!self::getQuotaLimit()) {
            return false;
        }
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // "Every other address" is the catch-all, written as * (as before)
        $address = !empty($formvars['inCatchAll']) ? '*' : (isset($formvars['inAddress']) ? strtolower(trim((string) $formvars['inAddress'])) : '');
        if (self::ExecuteCreateAlias($currentuser['userid'], $address, isset($formvars['inDomain']) ? $formvars['inDomain'] : '', isset($formvars['inDestination']) ? $formvars['inDestination'] : ''))
            self::$ok = true;
        return true;
    }

    /**
     * Handle the Delete/Edit buttons of the alias list (POST).
     *
     * Checks the CSFR token, then redirects to the Delete or Edit view of the chosen
     * alias and exits.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doDeleteAlias()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListAliases($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['id'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['id'] . "");
                exit;
            }
            if (isset($formvars['inEdit_' . $row['id'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $row['id'] . "");
                exit;
            }
        }
        return;
    }

    /**
     * Handle the confirm delete form (POST): delete the alias in inDelete.
     *
     * Checks the CSFR token and that the alias belongs to the logged-in account.
     * @global runtime_controller $controller The request controller.
     * @return bool False when not allowed; also false after a delete because ExecuteDeleteAlias() returns nothing.
     */
    static function doConfirmDeleteAlias()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this account's own records (the id comes from the form)
        if (!isset($formvars['inDelete']) || !ctrl_ownership::Owns('x_aliases', 'al_id_pk', 'al_acc_fk', $formvars['inDelete'], ' AND al_deleted_ts IS NULL')) {
            return false;
        }
        if (self::ExecuteDeleteAlias($formvars['inDelete']))
            return true;
        return false;
    }

    /**
     * Return the address of the alias in ?other= (template: <@ EditCurrentAliasName @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The address, or '' when there is no ?other=.
     */
    static function getEditCurrentAliasName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentAlias($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['address'];
        } else {
            return "";
        }
    }

    /**
     * List the logged-in account's aliases (template: <% loop AliasList %>).
     *
     * @global runtime_controller $controller The request controller. Declared but not used.
     * @return array<int, array<string, mixed>>|false See ListAliases().
     */
    static function getAliasList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListAliases($currentuser['userid']);
    }

    /**
     * Is the page in its default (create) view, i.e. no ?show=?
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisCreateAlias()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (!isset($urlvars['show']))
            return true;
        return false;
    }

    /**
     * Is the page showing the delete confirmation for an alias of this account (template: <% if isDeleteAlias %>)?
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @param int|string|null $uid Optional. The account ID. Default null (the logged-in user).
     * @return bool|null Null when the alias in ?other= is not the account's.
     */
    static function getisDeleteAlias($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete Mail Alias Account.
        // This shall avoid exposing Mail Alias based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

        $sql = " SELECT * FROM x_aliases WHERE al_acc_fk=:userid AND al_id_pk=:editedUsrID AND al_deleted_ts IS NULL";
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
     * Return the ID of the alias in ?other= (template: <@ EditCurrentAliasID @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string The alias ID, or '' when there is no ?other=.
     */
    static function getEditCurrentAliasID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentAlias($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['id'];
        } else {
            return "";
        }
    }

    /**
     * Read a mail setting from x_mail_settings.
     *
     * @global runtime_controller $controller The request controller.
     * @param string $name The setting name (mbs_name_vc).
     * @return string|false The value, or false when the setting does not exist.
     */
    static function GetMailOption($name)
    {
        global $zdbh;
        $sql = 'SELECT mbs_value_tx FROM x_mail_settings WHERE mbs_name_vc = :name';
        $bindArray = array(':name' => $name);
        $sqlStatment = $zdbh->bindQuery($sql, $bindArray);
        $result = $zdbh->returnRow();

        if ($result) {
            return $result['mbs_value_tx'];
        } else {
            return false;
        }
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
     * The figures at the top (<@ Tiles @>): aliases, catch-alls, and aliases and forwarders against the package's forwarder limit.
     *
     * @return string The HTML.
     */
    static function getTiles()
    {
        $me = ctrl_users::GetUserDetail();
        $list = self::ListAliases($me['userid']) ?: array();
        $catch = count(array_filter($list, function ($a) { return strpos($a['address'], '@') === 0; }));
        $max = (int) $me['forwardersquota'];
        $used = self::UsedForwards($me['userid']);
        $pct = $max > 0 ? min(100, round($used / $max * 100)) : 0;
        $ico = function ($d) { return '<svg class="al-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>'; };
        return '<div class="al-tile">' . $ico('<path d="M4 4h16v16H4z"/><path d="m4 7 8 6 8-6"/>') . '<div><b>' . count($list) . '</b><span>' . self::t('Aliases') . '</span></div></div>'
            . '<div class="al-tile">' . $ico('<circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/>') . '<div><b>' . $catch . '</b><span>' . self::t('Catch-all addresses') . '</span></div></div>'
            . '<div class="al-tile' . ($max >= 0 && $used >= $max ? ' full' : '') . '">' . $ico('<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>')
            . '<div><b>' . $used . ($max < 0 ? '' : ' <small>/ ' . $max . '</small>') . '</b><span>' . self::t('Aliases and forwarders') . ($max < 0 ? ' &middot; ' . self::t('no limit') : '') . '</span>'
            . ($max > 0 ? '<div class="al-bar"><i style="width:' . $pct . '%"></i></div>' : '') . '</div></div>';
    }

    /**
     * One row per alias (<@ AliasRows @>): the address (a catch-all marked as such), the mailbox it delivers to, and Delete.
     *
     * @return string The HTML ('' when there are none).
     */
    static function getAliasRows()
    {
        $list = self::ListAliases(ctrl_users::GetUserDetail()['userid']);
        if (!$list) {
            return '';
        }
        $out = '';
        foreach ($list as $a) {
            $addr = (string) $a['address'];
            $at = strrpos($addr, '@');
            $local = $at === false ? $addr : substr($addr, 0, $at);
            $domain = $at === false ? '' : substr($addr, $at + 1);
            $from = $local === '' ? '<span class="al-catch">' . self::t('Anything') . '</span><span class="al-dom">@' . self::h($domain) . '</span>'
                : '<b>' . self::h($local) . '</b><span class="al-dom">@' . self::h($domain) . '</span>';
            $out .= '<div class="al-row" data-s="' . self::h(strtolower($addr . ' ' . $a['destination'])) . '">'
                . '<div class="al-from">' . $from . '</div>'
                . '<svg class="al-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>'
                . '<div class="al-to"><svg class="al-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/></svg>' . self::h($a['destination']) . '</div>'
                . '<button class="btn btn-default btn-sm al-del" type="submit" name="inDelete_' . (int) $a['id'] . '" value="inDelete_' . (int) $a['id'] . '" title="' . self::t('Delete') . '" aria-label="' . self::t('Delete') . ' ' . self::h($addr) . '">'
                . '<svg class="al-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></button>'
                . '</div>';
        }
        return $out;
    }

    /**
     * The alias address shown in the delete confirmation, a catch-all spelled out (<@ DeleteName @>).
     *
     * @return string The escaped address.
     */
    static function getDeleteName()
    {
        $n = (string) self::getEditCurrentAliasName();
        return strpos($n, '@') === 0 ? self::t('anything') . self::h($n) : self::h($n);
    }

    /**
     * What deleting the alias does (<@ DeleteDetails @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getDeleteDetails()
    {
        global $controller;
        $cur = self::ListCurrentAlias($controller->GetControllerRequest('URL', 'other'));
        if (!$cur) {
            return '';
        }
        return '<ul class="al-lose"><li>' . self::t('Mail sent to it is no longer delivered to') . ' <b>' . self::h($cur[0]['destination']) . '</b> '
            . self::t('(it is refused, unless another address catches it).') . '</li><li>' . self::t('The mailbox itself and the mail already in it stay.') . '</li></ul>';
    }

    /**
     * The domain choices for a new alias, the one picked before selected (<@ DomainOptions @>).
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
     * The mailbox choices for a new alias, the one picked before selected (<@ MailboxOptions @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The <option> HTML.
     */
    static function getMailboxOptions()
    {
        global $controller;
        $picked = strtolower((string) $controller->GetControllerRequest('FORM', 'inDestination'));
        $out = '';
        foreach (self::getMailboxList() ?: array() as $m) {
            $out .= '<option value="' . self::h($m['address']) . '"' . (strtolower($m['address']) === $picked ? ' selected' : '') . '>' . self::h($m['address']) . '</option>';
        }
        return $out;
    }

    /**
     * Template flag <% if CanCreate %>: the account has a domain and a mailbox to make an alias with.
     *
     * @return bool
     */
    static function getCanCreate()
    {
        return self::getDomainList() && self::getMailboxList();
    }

    /**
     * Template flag <% if CannotCreate %>: no domain or no mailbox yet.
     *
     * @return bool
     */
    static function getCannotCreate()
    {
        return !self::getCanCreate();
    }

    /**
     * The name part typed when adding failed, so it is not lost (<@ PostedAddress @>).
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
     * May the account create another forwarder (template: <% if QuotaLimit %>)?
     *
     * @return bool True when the quota is unlimited (-1) or not yet reached.
     */
    static function getQuotaLimit()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['forwardersquota'] < 0 ) or //-1 = unlimited
                ($currentuser['forwardersquota'] > self::UsedForwards($currentuser['userid']));
    }

    /**
     * How many aliases and forwarders the account has: both count toward the package's forwarder limit
     * (only forwarders were counted, so aliases had no limit at all).
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
     * Return the message for the last action (template: <@ Result @>).
     *
     * @return string|null The HTML message, or null when there is none.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$baddestination)) {
            return ui_sysmessage::shout(ui_language::translate("Choose one of your own mailboxes for the alias to deliver to."), "zannounceerror");
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
            return ui_sysmessage::shout(ui_language::translate("The selected domain was not valid or not yet active."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your aliases have been saved successfully!"), "zannounceok");
        } else {
            return NULL;
        }
        return;
    }

    // Webinterface sudo methods.
}
