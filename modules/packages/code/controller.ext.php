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
 * Package Manager module: a reseller's hosting packages (name, PHP switch and quotas).
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True when a quota field was not a number (shown by getResult()). */
    static $error;
    /** @var bool|null True when the package name is already used by this reseller (shown by getResult()). */
    static $alreadyexists;
    /** @var bool|null True when the package name is not valid (shown by getResult()). */
    static $badname;
    /** @var bool|null True when no package name was given (shown by getResult()). */
    static $blank;
    /** @var bool|null True after a package was created, updated or deleted (shown by getResult()). */
    static $ok;
    /** @var mixed Not used (never set). */
    static $edit;
    /** @var bool|null True when a package's clients were to be moved to the package being deleted (shown by getResult()). */
    static $samepackage;

    /**
     * The 'worker' methods.
     */
	// get package quotas - tgates
	/**
	 * Formats a package's quotas as readable HTML lines ("Domains = 5<br>...").
	 *
	 * @param array<int,array<string,mixed>>|false $pkg_quotas The rows from ListCurrentPackage().
	 * @return string|null The text for the last row; null when there are no rows.
	 */
	static function GetQuotas($pkg_quotas)
	{
		foreach($pkg_quotas as $pkg_quota) {
			// remove unused keys
			unset($pkg_quota['packageid']);
			unset($pkg_quota['PHPChecked']);
			unset($pkg_quota['packagename']);
			// make the array managable
			$pkg_quota = json_encode($pkg_quota);
			// make values translatable and readable
			$pkg_quota = str_replace('enablePHP', ui_language::translate('PHP Enabled'), $pkg_quota);
			$pkg_quota = str_replace('domains', ui_language::translate('Domains'), $pkg_quota);
			$pkg_quota = str_replace('subDomains', ui_language::translate('Sub Domains'), $pkg_quota);
			$pkg_quota = str_replace('parkedDomains', ui_language::translate('Park Domains'), $pkg_quota);
			$pkg_quota = str_replace('fowarders', ui_language::translate('Mail Forwarders'), $pkg_quota);
			$pkg_quota = str_replace('distlists', ui_language::translate('Distribution Lists'), $pkg_quota);
			$pkg_quota = str_replace('ftpaccounts', ui_language::translate('FTP Accounts'), $pkg_quota);
			$pkg_quota = str_replace('mysql', ui_language::translate('Databases'), $pkg_quota);
			$pkg_quota = str_replace('diskquota', ui_language::translate('Disc Quota'), $pkg_quota);
			$pkg_quota = str_replace('bandquota', ui_language::translate('Bandwidth Quota'), $pkg_quota);
			$pkg_quota = str_replace('mailboxes', ui_language::translate('Mailboxes'), $pkg_quota);
			// clean up the string
			$pkg_quota = str_replace('{', '', $pkg_quota);
			$pkg_quota = str_replace('}', '', $pkg_quota);
			$pkg_quota = str_replace('"', '', $pkg_quota);
			$pkg_quota = str_replace(':', ' &nbsp;= &nbsp;', $pkg_quota);
			$pkg_quota = str_replace('-1', ui_language::translate('Unlimited'), $pkg_quota);
			$pkg_quota = str_replace(',', '<br>', $pkg_quota);
		}
		return $pkg_quota;
	}

    /**
     * Lists a reseller's packages.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The reseller's account ID.
     * @return array<int, array<int|string, mixed>>|false One row per package: 'packageid', 'created' (date),
     *         'clients' (number of accounts), 'packagename' (translated), 'pkg_quota' (from GetQuotas()),
     *         plus the ListCurrentPackage() result under key 0; false when there are none.
     */
    static function ListPackages($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_packages WHERE pk_reseller_fk=:uid AND pk_deleted_ts IS NULL";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            while ($rowpackages = $sql->fetch()) {
                //$numrows = $zdbh->query("SELECT COUNT(*) FROM x_accounts WHERE ac_package_fk=" . $rowpackages['pk_id_pk'] . " AND ac_deleted_ts IS NULL")->fetchColumn();
                $numrows = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_package_fk=:pk_id_pk AND ac_deleted_ts IS NULL");
                $numrows->bindParam(':pk_id_pk', $rowpackages['pk_id_pk']);
                $numrows->execute();
                $Column = $numrows->fetchColumn();
                array_push($res, array(
					'packageid' => $rowpackages['pk_id_pk'],
                    'created' => date(ctrl_options::GetSystemOption('sentora_df'), $rowpackages['pk_created_ts']),
                    // fetchColumn() returns the count itself, [0] on it always gave null
                    'clients' => $Column,
                    'packagename' => ui_language::translate($rowpackages['pk_name_vc']),
					// get package quotas - tgates
					$pkg_quotas = self::ListCurrentPackage($rowpackages['pk_id_pk']),
					'pkg_quota' => self::GetQuotas($pkg_quotas)
					));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Returns one of the logged-in reseller's packages with its quotas.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $id The package ID (x_packages.pk_id_pk).
     * @return array<int, array<string, mixed>>|false A list with the package row: 'packageid', 'enablePHP',
     *         'PHPChecked' ('CHECKED' or ''), the quotas ('domains', 'subdomains', ..., 'diskquota' and
     *         'bandquota' in MB) and 'packagename'; false when it is not the reseller's or does not exist.
     */
    static function ListCurrentPackage($id)
    {
        // Only this account's own records (the id comes from ?other= or the form)
        if (!ctrl_ownership::Owns('x_packages', 'pk_id_pk', 'pk_reseller_fk', $id)) {
            return false;
        }
        global $zdbh;
        $sql = "SELECT * FROM x_packages
				LEFT JOIN x_quotas  ON (x_packages.pk_id_pk=x_quotas.qt_package_fk)
				WHERE pk_id_pk=:id AND pk_deleted_ts IS NULL";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':id', $id);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':id', $id);
            $res = array();
            $sql->execute();
            while ($rowpackages = $sql->fetch()) {
                $PHPChecked = "";
                if ($rowpackages['pk_enablephp_in'] <> 0) {
                    $PHPChecked = "CHECKED";
                }
                array_push($res, array('packageid' => $rowpackages['pk_id_pk'],
                    'enablePHP' => $rowpackages['pk_enablephp_in'],
                    'PHPChecked' => $PHPChecked,
                    'domains' => $rowpackages['qt_domains_in'],
                    'subdomains' => $rowpackages['qt_subdomains_in'],
                    'parkeddomains' => $rowpackages['qt_parkeddomains_in'],
                    'fowarders' => $rowpackages['qt_fowarders_in'],
                    'distlists' => $rowpackages['qt_distlists_in'],
                    'ftpaccounts' => $rowpackages['qt_ftpaccounts_in'],
                    'mysql' => $rowpackages['qt_mysql_in'],
                    'diskquota' => ($rowpackages['qt_diskspace_bi'] / 1024000),
                    'bandquota' => ($rowpackages['qt_bandwidth_bi'] / 1024000),
                    'mailboxes' => $rowpackages['qt_mailboxes_in'],
                    'packagename' => stripslashes($rowpackages['pk_name_vc'])));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Deletes a package, moving its clients to another package.
     *
     * Runs the OnBeforeDeletePackage / OnAfterDeletePackage hooks and sets $ok; sets
     * $samepackage when both IDs are the same.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $pk_id_pk The package to delete.
     * @param int|string|null $mpk_id_pk The package its clients move to.
     * @return bool True when deleted.
     */
    static function ExecuteDeletePackage($pk_id_pk, $mpk_id_pk)
    {
        global $zdbh;

        $sql = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_package_fk=:packageid AND ac_deleted_ts IS NULL");
        $sql->bindParam(':packageid', $pk_id_pk);
        $sql->execute();
        $numrows = $sql->fetchAll();
        if ($numrows[0] <> 0) {
            if ($pk_id_pk == $mpk_id_pk) {
                self::$samepackage = true;
                return false;
            }
        }
        runtime_hook::Execute('OnBeforeDeletePackage');
        $sql = $zdbh->prepare("
            UPDATE x_accounts
            SET ac_package_fk = :mpk_id_pk
            WHERE ac_package_fk =:pk_id_pk");
        $sql->bindParam(':mpk_id_pk', $mpk_id_pk);
        $sql->bindParam(':pk_id_pk', $pk_id_pk);
        $sql->execute();
        $sql = $zdbh->prepare("
            UPDATE x_profiles
            SET ud_package_fk = :mpk_id_pk
            WHERE ud_package_fk = :pk_id_pk");
        $sql->bindParam(':mpk_id_pk', $mpk_id_pk);
        $sql->bindParam(':pk_id_pk', $pk_id_pk);
        $sql->execute();
        $sql = $zdbh->prepare("
			UPDATE x_packages
			SET pk_deleted_ts = :time
			WHERE pk_id_pk = :pk_id_pk");
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':pk_id_pk', $pk_id_pk);
        $sql->execute();
        runtime_hook::Execute('OnAfterDeletePackage');
        self::$ok = true;
        return true;
    }

    /**
     * Creates a package and its quotas.
     *
     * Runs the OnBeforeCreatePackage / OnAfterCreatePackage hooks and sets $ok; the error
     * flags are set by CheckNumeric() and CheckCreateForErrors().
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The reseller's account ID.
     * @param string $packagename The package name (spaces are removed).
     * @param int|string $EnablePHP 1 or 0 (checkbox value).
     * @param int|string $Domains Domain quota (-1 = unlimited).
     * @param int|string $SubDomains Sub-domain quota.
     * @param int|string $ParkedDomains Parked domain quota.
     * @param int|string $Mailboxes Mailbox quota.
     * @param int|string $Fowarders Mail forwarder quota.
     * @param int|string $DistLists Distribution list quota.
     * @param int|string $FTPAccounts FTP account quota.
     * @param int|string $MySQL MySQL database quota.
     * @param int|string $DiskQuota Disk quota in MB (stored as MB * 1024000 bytes).
     * @param int|string $BandQuota Monthly bandwidth quota in MB (stored as MB * 1024000 bytes).
     * @return bool True when created.
     */
    static function ExecuteCreatePackage($uid, $packagename, $EnablePHP, $Domains, $SubDomains, $ParkedDomains, $Mailboxes, $Fowarders, $DistLists, $FTPAccounts, $MySQL, $DiskQuota, $BandQuota)
    {
        global $zdbh;
        if (fs_director::CheckForEmptyValue(self::CheckNumeric($EnablePHP, $Domains, $SubDomains, $ParkedDomains, $Mailboxes, $Fowarders, $DistLists, $FTPAccounts, $MySQL, $DiskQuota, $BandQuota))) {
            return false;
        }
        $packagename = str_replace(' ', '', $packagename);
        // Check for errors before we continue...
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrors($packagename, $uid))) {
            return false;
        }
        runtime_hook::Execute('OnBeforeCreatePackage');
        // If the user submitted a 'new' request then we will simply add the package to the database...
        $sql = $zdbh->prepare("INSERT INTO x_packages (pk_reseller_fk,
										pk_name_vc,
										pk_enablephp_in,
										pk_created_ts) VALUES (
										:uid,
										:packagename,
										:php,
										:time);");
        $php = fs_director::GetCheckboxValue($EnablePHP);
        $sql->bindParam(':php', $php);
        $sql->bindParam(':uid', $uid);
        $time = time();
        $sql->bindParam(':time', $time);
        $pack = addslashes($packagename);
        $sql->bindParam(':packagename', $pack);
        $sql->execute();
        // Now lets pull back the package ID so we can use it in the other tables we are about to manipulate.
        //$package = $zdbh->query("SELECT * FROM x_packages WHERE pk_reseller_fk=" . $uid . " AND pk_name_vc='" . $packagename . "' AND pk_deleted_ts IS NULL")->Fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_packages WHERE pk_reseller_fk=:uid AND pk_name_vc=:packagename AND pk_deleted_ts IS NULL");
        $numrows->bindParam(':uid', $uid);
        $numrows->bindParam(':packagename', $packagename);
        $numrows->execute();
        $package = $numrows->fetch();


        $sql = $zdbh->prepare("INSERT INTO x_quotas (qt_package_fk,
										qt_domains_in,
										qt_subdomains_in,
										qt_parkeddomains_in,
										qt_mailboxes_in,
										qt_fowarders_in,
										qt_distlists_in,
										qt_ftpaccounts_in,
										qt_mysql_in,
										qt_diskspace_bi,
										qt_bandwidth_bi) VALUES (
										:pk_id_pk,
										:Domains,
										:SubDomains,
										:ParkedDomains,
										:Mailboxes,
										:Fowarders,
										:DistLists,
										:FTPAccounts,
										:MySQL,
										:DiskQuotaFinal,
										:BandQuotaFinal)");
        $DiskQuotaFinal = $DiskQuota * 1024000;
        $BandQuotaFinal = $BandQuota * 1024000;
        $sql->bindParam(':DiskQuotaFinal', $DiskQuotaFinal);
        $sql->bindParam(':BandQuotaFinal', $BandQuotaFinal);
        $sql->bindParam(':MySQL', $MySQL);
        $sql->bindParam(':DistLists', $DistLists);
        $sql->bindParam(':Fowarders', $Fowarders);
        $sql->bindParam(':Mailboxes', $Mailboxes);
        $sql->bindParam(':SubDomains', $SubDomains);
        $sql->bindParam(':FTPAccounts', $FTPAccounts);
        $sql->bindParam(':ParkedDomains', $ParkedDomains);
        $sql->bindParam(':Domains', $Domains);
        $sql->bindParam(':pk_id_pk', $package['pk_id_pk']);
        $sql->execute();
        runtime_hook::Execute('OnAfterCreatePackage');
        self::$ok = true;
        return true;
    }

    /**
     * Updates a package's name, PHP switch and quotas.
     *
     * Runs the OnBeforeUpdatePackage / OnAfterUpdatePackage hooks and sets $ok.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The reseller's account ID.
     * @param int|string $pid The package ID.
     * @param string $packagename The package name (spaces are removed).
     * @param int|string $EnablePHP 1 or 0 (checkbox value).
     * @param int|string $Domains Domain quota (-1 = unlimited).
     * @param int|string $SubDomains Sub-domain quota.
     * @param int|string $ParkedDomains Parked domain quota.
     * @param int|string $Mailboxes Mailbox quota.
     * @param int|string $Fowarders Mail forwarder quota.
     * @param int|string $DistLists Distribution list quota.
     * @param int|string $FTPAccounts FTP account quota.
     * @param int|string $MySQL MySQL database quota.
     * @param int|string $DiskQuota Disk quota in MB (stored as MB * 1024000 bytes).
     * @param int|string $BandQuota Monthly bandwidth quota in MB (stored as MB * 1024000 bytes).
     * @return bool True when updated.
     */
    static function ExecuteUpdatePackage($uid, $pid, $packagename, $EnablePHP, $Domains, $SubDomains, $ParkedDomains, $Mailboxes, $Fowarders, $DistLists, $FTPAccounts, $MySQL, $DiskQuota, $BandQuota)
    {
        global $zdbh;
        if (fs_director::CheckForEmptyValue(self::CheckNumeric($EnablePHP, $Domains, $SubDomains, $ParkedDomains, $Mailboxes, $Fowarders, $DistLists, $FTPAccounts, $MySQL, $DiskQuota, $BandQuota))) {
            return false;
        }
        $packagename = str_replace(' ', '', $packagename);
        // Check for errors before we continue...
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrors($packagename, $uid, $pid))) {
            return false;
        }
        runtime_hook::Execute('OnBeforeUpdatePackage');
        $sql = $zdbh->prepare("UPDATE x_packages SET pk_name_vc=:packagename,
								pk_enablephp_in = :php
								WHERE pk_id_pk  = :pid");

        $php = fs_director::GetCheckboxValue($EnablePHP);
        $sql->bindParam(':php', $php);
        $sql->bindParam(':pid', $pid);
        $sql->bindParam(':packagename', $packagename);
        $sql->execute();
        $sql = $zdbh->prepare("UPDATE x_quotas SET qt_domains_in = :Domains,
								qt_parkeddomains_in = :ParkedDomains,
								qt_ftpaccounts_in   = :FTPAccounts,
								qt_subdomains_in    = :SubDomains,
								qt_mailboxes_in     = :Mailboxes,
								qt_fowarders_in     = :Fowarders,
								qt_distlists_in     = :DistLists,
								qt_diskspace_bi     = :DiskQuotaFinal,
								qt_bandwidth_bi     = :BandQuotaFinal,
								qt_mysql_in         = :MySQL
                                                                WHERE qt_package_fk = :pid");
        $DiskQuotaFinal = $DiskQuota * 1024000;
        $BandQuotaFinal = $BandQuota * 1024000;
        $sql->bindParam(':DiskQuotaFinal', $DiskQuotaFinal);
        $sql->bindParam(':BandQuotaFinal', $BandQuotaFinal);
        $sql->bindParam(':MySQL', $MySQL);
        $sql->bindParam(':DistLists', $DistLists);
        $sql->bindParam(':Fowarders', $Fowarders);
        $sql->bindParam(':Mailboxes', $Mailboxes);
        $sql->bindParam(':SubDomains', $SubDomains);
        $sql->bindParam(':FTPAccounts', $FTPAccounts);
        $sql->bindParam(':ParkedDomains', $ParkedDomains);
        $sql->bindParam(':Domains', $Domains);
        $sql->bindParam(':pid', $pid);
        $sql->execute();
        runtime_hook::Execute('OnAfterUpdatePackage');
        self::$ok = true;
        return true;
    }

    /**
     * Checks a package name, setting $blank, $alreadyexists or $badname on a problem.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $packagename The package name.
     * @param int|string $uid The reseller's account ID.
     * @param int|string $pid Optional. The package being edited (left out of the duplicate check). Default 0.
     * @return bool True when the name can be used.
     */
    static function CheckCreateForErrors($packagename, $uid, $pid = 0)
    {
        global $zdbh;
        $packagename = str_replace(' ', '', $packagename);
        // Check to make sure the packagename is not blank or exists for reseller before we go any further...
        if (!fs_director::CheckForEmptyValue($packagename)) {
            $sql = "SELECT COUNT(*) FROM x_packages WHERE UPPER(pk_name_vc)=:packageNameSlashes AND pk_reseller_fk=:uid AND pk_id_pk !=:pid AND pk_deleted_ts IS NULL";
            $packageNameSlashes = addslashes(strtoupper($packagename));

            $numrows = $zdbh->prepare($sql);
            $numrows->bindParam(':packageNameSlashes', $packageNameSlashes);
            $numrows->bindParam(':uid', $uid);
            $numrows->bindParam(':pid', $pid);

            if ($numrows->execute()) {
                if ($numrows->fetchColumn() <> 0) {
                    self::$alreadyexists = true;
                    return false;
                }
            }
        } else {
            self::$blank = true;
            return false;
        }
        // Check packagename format.
        if (!self::IsValidPackageName($packagename)) {
            self::$badname = true;
            return false;
        }
        return true;
    }

    /**
     * Checks a package name.
     *
     * @param string $packagename Letters, digits and dashes, up to 63, not starting or ending with a dash.
     * @return bool True when valid.
     */
    static function IsValidPackageName($packagename)
    {
        if (!preg_match('/^[a-z\d][a-z\d-]{0,62}$/i', $packagename) || preg_match('/-$/', $packagename)) {
            return false;
        }
        return true;
    }

    /**
     * Checks that every quota value is numeric, setting $error when one is not.
     *
     * @param int|string $EnablePHP 1 or 0 (checkbox value).
     * @param int|string $Domains Domain quota (-1 = unlimited).
     * @param int|string $SubDomains Sub-domain quota.
     * @param int|string $ParkedDomains Parked domain quota.
     * @param int|string $Mailboxes Mailbox quota.
     * @param int|string $Fowarders Mail forwarder quota.
     * @param int|string $DistLists Distribution list quota.
     * @param int|string $FTPAccounts FTP account quota.
     * @param int|string $MySQL MySQL database quota.
     * @param int|string $DiskQuota Disk quota in MB (stored as MB * 1024000 bytes).
     * @param int|string $BandQuota Monthly bandwidth quota in MB (stored as MB * 1024000 bytes).
     * @return bool True when all are numeric.
     */
    static function CheckNumeric($EnablePHP, $Domains, $SubDomains, $ParkedDomains, $Mailboxes, $Fowarders, $DistLists, $FTPAccounts, $MySQL, $DiskQuota, $BandQuota)
    {
        if (!is_numeric($EnablePHP) ||
                !is_numeric($Domains) ||
                !is_numeric($SubDomains) ||
                !is_numeric($ParkedDomains) ||
                !is_numeric($Mailboxes) ||
                !is_numeric($Fowarders) ||
                !is_numeric($DistLists) ||
                !is_numeric($FTPAccounts) ||
                !is_numeric($MySQL) ||
                !is_numeric($DiskQuota) ||
                !is_numeric($BandQuota)) {
            self::$error = true;
            return false;
        } else {
            return true;
        }
    }

    /**
     * Sets a creation time on the reseller's packages that have none (pk_created_ts empty).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The reseller's account ID.
     * @return void
     */
    static function AddDefaultPackageTime($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_packages WHERE pk_reseller_fk=:uid AND pk_deleted_ts IS NULL";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $sql->execute();
            while ($rowpackages = $sql->fetch()) {
                if ($rowpackages['pk_created_ts'] == "") {
                    $add = $zdbh->prepare("UPDATE x_packages SET pk_created_ts=:time
									WHERE pk_id_pk  =:pk_id_pk");
                    $time = time();
                    $add->bindParam(':time', $time);
                    $add->bindParam(':pk_id_pk', $rowpackages['pk_id_pk']);
                    $add->execute();
                }
            }
        }
    }

    /**
     * End 'worker' methods.
     */

    /**
     * Webinterface sudo methods.
     */

    /**
     * Creates a package from the form (POST action=CreatePackage).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when created.
     */
    static function doCreatePackage()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inEnablePHP'])) {
            $EnablePHP = fs_director::GetCheckboxValue($formvars['inEnablePHP']);
        } else {
            $EnablePHP = 0;
        }
        if (self::ExecuteCreatePackage($currentuser['userid'], $formvars['inPackageName'], $EnablePHP, $formvars['inNoDomains'], $formvars['inNoSubDomains'], $formvars['inNoParkedDomains'], $formvars['inNoMailboxes'], $formvars['inNoFowarders'], $formvars['inNoDistLists'], $formvars['inNoFTPAccounts'], $formvars['inNoMySQL'], $formvars['inDiskQuota'], $formvars['inBandQuota']))
            return true;
        return false;
    }

    /**
     * Saves the edit form (POST action=UpdatePackage) for one of the reseller's packages.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when updated; false when not the reseller's package or on an error.
     */
    static function doUpdatePackage()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this reseller's own packages
        if (!isset($formvars['inPackageID']) || !ctrl_ownership::Owns('x_packages', 'pk_id_pk', 'pk_reseller_fk', $formvars['inPackageID'], ' AND pk_deleted_ts IS NULL')) {
            return false;
        }
        if (isset($formvars['inEnablePHP'])) {
            $EnablePHP = fs_director::GetCheckboxValue($formvars['inEnablePHP']);
        } else {
            $EnablePHP = 0;
        }
        if (self::ExecuteUpdatePackage($currentuser['userid'], $formvars['inPackageID'], $formvars['inPackageName'], $EnablePHP, $formvars['inNoDomains'], $formvars['inNoSubDomains'], $formvars['inNoParkedDomains'], $formvars['inNoMailboxes'], $formvars['inNoFowarders'], $formvars['inNoDistLists'], $formvars['inNoFTPAccounts'], $formvars['inNoMySQL'], $formvars['inDiskQuota'], $formvars['inBandQuota']))
            return true;
        return false;
    }

    /**
     * Handles the Edit / Delete buttons in the package list (POST action=EditPackage).
     *
     * Redirects to ?show=Edit or ?show=Delete with &other=<id> and exits when a button
     * for one of the reseller's packages was pressed.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doEditPackage()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListPackages($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['packageid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['packageid'] . "");
                exit;
            }
            if (isset($formvars['inEdit_' . $row['packageid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $row['packageid'] . "");
                exit;
            }
        }
        return;
    }

    /**
     * Deletes a package from the confirmation form (POST action=DeletePackage).
     *
     * Fields inPackageID (the package) and inMovePackage (where its clients go); both
     * must be the reseller's own.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when deleted.
     */
    static function doDeletePackage()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only this reseller's own packages (and move its clients to another of its own)
        if (!isset($formvars['inPackageID']) || !ctrl_ownership::Owns('x_packages', 'pk_id_pk', 'pk_reseller_fk', $formvars['inPackageID'], ' AND pk_deleted_ts IS NULL')
            || (!empty($formvars['inMovePackage']) && !ctrl_ownership::Owns('x_packages', 'pk_id_pk', 'pk_reseller_fk', $formvars['inMovePackage'], ' AND pk_deleted_ts IS NULL'))) {
            return false;
        }
        if (self::ExecuteDeletePackage($formvars['inPackageID'], $formvars['inMovePackage']))
            return true;
        return false;
    }

    /**
     * Returns the reseller's packages (<% loop PackageList %>, <% if PackageList %>).
     *
     * @return array<int, array<int|string, mixed>>|false See ListPackages().
     */
    static function getPackageList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $packages = self::ListPackages($currentuser['userid']);
        if ($packages)
            return $packages;
        return false;
    }

    /**
     * Returns the packages clients can be moved to: all but the one in ?other= (<% loop PackageListDropdown %>).
     *
     * @return array<int, array<int|string, mixed>>|false See ListPackages(); false when there are none.
     */
    static function getPackageListDropdown()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $packages = self::ListPackages($currentuser['userid']);
        $available = array();
        foreach ($packages as $package) {
            if ($package['packageid'] != $_GET['other']) $available[] = $package;
        }
        if (count($available) > 0)
            return $available;
        return false;
    }

    /**
     * Tells whether to show the create form (<% if isCreatePackage %>): when no ?show= is given.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisCreatePackage()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (!isset($urlvars['show']))
            return true;
        return false;
    }

    /**
     * Tells whether to show the delete confirmation (<% if isDeletePackage %>): ?show=Delete.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisDeletePackage()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "Delete"))
            return true;
        return false;
    }

    /**
     * Tells whether to show the edit form (<% if isEditPackage %>): ?show=Edit.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisEditPackage()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "Edit")) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Returns the 'packagename' field of the package in ?other= (<@ EditCurrentPackageName @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string|null The package name ('packagename'); '' when no ?other= is given, null when the package is not found.
     */
    static function getEditCurrentPackageName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['packagename'];
        } else {
            return "";
        }
    }

    /**
     * Returns the 'packageid' field of the package in ?other= (<@ EditCurrentPackageID @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string|null The package ID ('packageid'); '' when no ?other= is given, null when the package is not found.
     */
    static function getEditCurrentPackageID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['packageid'];
        } else {
            return "";
        }
    }

    /**
     * Returns the 'domains' field of the package in ?other= (<@ EditCurrentDomains @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string|null The domain quota ('domains'); '' when no ?other= is given, null when the package is not found.
     */
    static function getEditCurrentDomains()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['domains'];
        } else {
            return "";
        }
    }

    /**
     * Returns the 'subdomains' field of the package in ?other= (<@ EditCurrentSubDomains @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string|null The sub-domain quota ('subdomains'); '' when no ?other= is given, null when the package is not found.
     */
    static function getEditCurrentSubDomains()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['subdomains'];
        } else {
            return "";
        }
    }

    /**
     * Returns the 'parkeddomains' field of the package in ?other= (<@ EditCurrentParkedDomains @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string|null The parked domain quota ('parkeddomains'); '' when no ?other= is given, null when the package is not found.
     */
    static function getEditCurrentParkedDomains()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['parkeddomains'];
        } else {
            return "";
        }
    }

    /**
     * Returns the 'mailboxes' field of the package in ?other= (<@ EditCurrentMailboxes @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string|null The mailbox quota ('mailboxes'); '' when no ?other= is given, null when the package is not found.
     */
    static function getEditCurrentMailboxes()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['mailboxes'];
        } else {
            return "";
        }
    }

    /**
     * Returns the 'fowarders' field of the package in ?other= (<@ EditCurrentForwarders @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string|null The mail forwarder quota ('fowarders'); '' when no ?other= is given, null when the package is not found.
     */
    static function getEditCurrentForwarders()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['fowarders'];
        } else {
            return "";
        }
    }

    /**
     * Returns the 'distlists' field of the package in ?other= (<@ EditCurrentDistLists @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string|null The distribution list quota ('distlists'); '' when no ?other= is given, null when the package is not found.
     */
    static function getEditCurrentDistLists()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['distlists'];
        } else {
            return "";
        }
    }

    /**
     * Returns the 'ftpaccounts' field of the package in ?other= (<@ EditCurrentFTP @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string|null The FTP account quota ('ftpaccounts'); '' when no ?other= is given, null when the package is not found.
     */
    static function getEditCurrentFTP()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['ftpaccounts'];
        } else {
            return "";
        }
    }

    /**
     * Returns the 'mysql' field of the package in ?other= (<@ EditCurrentMySQL @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|string|null The MySQL database quota ('mysql'); '' when no ?other= is given, null when the package is not found.
     */
    static function getEditCurrentMySQL()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['mysql'];
        } else {
            return "";
        }
    }

    /**
     * Returns the 'diskquota' field of the package in ?other= (<@ EditCurrentDisk @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|float|string|null The disk quota in MB ('diskquota'); '' when no ?other= is given, null when the package is not found.
     */
    static function getEditCurrentDisk()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['diskquota'];
        } else {
            return "";
        }
    }

    /**
     * Returns the 'bandquota' field of the package in ?other= (<@ EditCurrentBandWidth @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int|float|string|null The bandwidth quota in MB ('bandquota'); '' when no ?other= is given, null when the package is not found.
     */
    static function getEditCurrentBandWidth()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['bandquota'];
        } else {
            return "";
        }
    }

    /**
     * Fills in missing creation times of the reseller's packages (<@ AddDefaultPackageTime @>, prints nothing).
     *
     * @return void
     */
    static function getAddDefaultPackageTime()
    {
        $currentuser = ctrl_users::GetUserDetail();
        self::AddDefaultPackageTime($currentuser['userid']);
    }

    /**
     * Returns 'CHECKED' when PHP is enabled in the package in ?other= (<@ PHPChecked @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string|null 'CHECKED' or ''; null when the package is not found.
     */
    static function getPHPChecked()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['PHPChecked'];
        } else {
            return "";
        }
    }

    /**
     * Returns the message for the last action (<@ Result @>): the first error flag set, else success.
     *
     * @return string|null The alert HTML, or null when there is nothing to show.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return ui_sysmessage::shout(ui_language::translate("You need to specify a package name to create your package."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return ui_sysmessage::shout(ui_language::translate("Your package name is not valid. Please enter a valid package name."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("A package with that name already appears to exsist."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$error)) {
            return ui_sysmessage::shout(ui_language::translate("There was an error updating your packages"), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$samepackage)) {
            return ui_sysmessage::shout(ui_language::translate("You cant move clients to the same package you are deleting!"), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your packages have been saved successfully!"), "zannounceok");
        }
        return;
    }

    /**
     * Webinterface sudo methods.
     */
}
