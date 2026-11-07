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
 * Package Manager module: a reseller's hosting packages (name and quotas). The old per-package PHP
 * switch (pk_enablephp_in) is kept as 1 and no longer shown: it never had an effect.
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
    /** @var bool|null True when a package's clients were to be moved to the package being deleted, or nowhere (shown by getResult()). */
    static $samepackage;
    /** @var int The ID of the package ExecuteCreatePackage() made (0 for none). */
    static $newid = 0;
    /** @var string The view after a refused save ('new' or 'edit', '' for none), so the form shows again with what was typed. */
    static $failed = '';
    /** @var string Why the limits were refused, in English ('' when they were not). */
    static $limiterror = '';

    // The 'worker' methods.
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
                    'packagename' => $rowpackages['pk_name_vc'],
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
        // (this read fetchAll()[0], an array, so it was never 0: a package without clients could
        // not be "moved" to itself, and one with clients could leave them without a package)
        if ((int) $sql->fetchColumn() > 0 && (empty($mpk_id_pk) || (int) $pk_id_pk === (int) $mpk_id_pk)) {
            self::$samepackage = true;
            return false;
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
        $packagename = self::CleanName($packagename);
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
        $sql->bindParam(':packagename', $packagename);
        $sql->execute();
        // the new row's own ID
        $package = array('pk_id_pk' => (int) $zdbh->lastInsertId());
        self::$newid = $package['pk_id_pk'];


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
        $packagename = self::CleanName($packagename);
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
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_quotas WHERE qt_package_fk = :pid");
        $q->execute(array(':pid' => (int) $pid));
        if ((int) $q->fetchColumn() === 0) {
            $q = $zdbh->prepare("INSERT INTO x_quotas (qt_package_fk) VALUES (:pid)");
            $q->execute(array(':pid' => (int) $pid));
        }
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
        $packagename = self::CleanName($packagename);
        // Check to make sure the packagename is not blank or exists for reseller before we go any further...
        if (!fs_director::CheckForEmptyValue($packagename)) {
            $sql = "SELECT COUNT(*) FROM x_packages WHERE UPPER(pk_name_vc)=:packageNameSlashes AND pk_reseller_fk=:uid AND pk_id_pk !=:pid AND pk_deleted_ts IS NULL";
            $packageNameSlashes = strtoupper($packagename);

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
     * Checks a package name: letters, digits, spaces, dots, dashes and underscores, starting with a
     * letter or digit, at most 30 characters (what x_packages.pk_name_vc stores; 63 were allowed and cut off).
     *
     * @param string $packagename The name (see CleanName()).
     * @return bool True when valid.
     */
    static function IsValidPackageName($packagename)
    {
        return (bool) preg_match('/^[a-z\d][a-z\d ._-]{0,29}$/i', $packagename);
    }

    /**
     * A package name as typed, with its spaces trimmed and runs of them made one (spaces used to be removed).
     *
     * @param string $name The name.
     * @return string
     */
    static function CleanName($name)
    {
        return trim(preg_replace('/\s+/', ' ', (string) $name));
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

    // End 'worker' methods.

    // Webinterface sudo methods.

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
     * The limits a package sets: label, form field, the key in ListCurrentPackage(), the usage resource
     * (ctrl_users::GetQuotaUsages()), and whether it is a size (MB; 0 = no limit) or a count (-1 = no limit).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: bool, 5: string}> By key: label, field, row key, resource, size, icon path.
     */
    static function Limits()
    {
        return array(
            'domains' => array('Domains', 'inNoDomains', 'domains', 'domains', false, '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>'),
            'subdomains' => array('Sub-domains', 'inNoSubDomains', 'subdomains', 'subdomains', false, '<path d="M6 3v12a3 3 0 0 0 3 3h9"/><path d="m15 15 3 3-3 3"/>'),
            'parkeddomains' => array('Parked domains', 'inNoParkedDomains', 'parkeddomains', 'parkeddomains', false, '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M10 17V7h3a3 3 0 0 1 0 6h-3"/>'),
            'mailboxes' => array('Mailboxes', 'inNoMailboxes', 'mailboxes', 'mailboxes', false, '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>'),
            'forwarders' => array('Forwarders', 'inNoFowarders', 'fowarders', 'forwarders', false, '<path d="M15 4l5 5-5 5"/><path d="M20 9H10a6 6 0 0 0-6 6v4"/>'),
            'distlists' => array('Distribution lists', 'inNoDistLists', 'distlists', 'distlists', false, '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"/>'),
            'ftpaccounts' => array('FTP accounts', 'inNoFTPAccounts', 'ftpaccounts', 'ftpaccounts', false, '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 10v6M9 13l3 3 3-3"/>'),
            'mysql' => array('MySQL databases', 'inNoMySQL', 'mysql', 'mysql', false, '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>'),
            'disk' => array('Disk space', 'inDiskQuota', 'diskquota', 'diskspace', true, '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 14h18M7 17h.01"/>'),
            'bandwidth' => array('Monthly bandwidth', 'inBandQuota', 'bandquota', 'bandwidth', true, '<path d="M4 17l6-6 4 4 7-7"/><path d="M14 8h7v7"/>'),
        );
    }

    /**
     * A line icon.
     *
     * @param string $path The SVG's inner paths.
     * @param string $class Optional. The class. Default 'pk-ico'.
     * @return string The SVG.
     */
    static function Svg($path, $class = 'pk-ico')
    {
        return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }

    /**
     * A limit in words: a number, "No limit", or a size in MB or GB (1 GB = 1000 MB, as the panel counts).
     *
     * @param float|int|string|null $v The limit (a count, or MB for a size).
     * @param bool $size Whether it is a size.
     * @return string The escaped text.
     */
    static function LimitText($v, $size)
    {
        $v = (float) $v;
        if (($size && $v <= 0) || (!$size && $v < 0)) {
            return self::t('No limit');
        }
        if ($size) {
            return self::h($v >= 1000 ? rtrim(rtrim(number_format($v / 1000, 2, '.', ''), '0'), '.') . ' GB' : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . ' MB');
        }
        return self::h((string) (int) $v);
    }

    /**
     * The account's packages with their limits and clients, for the page.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>> By name: 'id', 'name', 'php', 'created', 'clients' (user names), and each limit's row key.
     */
    static function Packages()
    {
        global $zdbh;
        static $out = null;
        if ($out !== null) {
            return $out;
        }
        $q = $zdbh->prepare("SELECT pk_id_pk FROM x_packages WHERE pk_reseller_fk = :u AND pk_deleted_ts IS NULL ORDER BY pk_name_vc");
        $q->execute(array(':u' => (int) ctrl_users::GetUserDetail()['userid']));
        $c = $zdbh->prepare("SELECT ac_id_pk, ac_user_vc FROM x_accounts WHERE ac_package_fk = :p AND ac_deleted_ts IS NULL ORDER BY ac_user_vc");
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $r = self::ListCurrentPackage($id);
            if (!$r) {
                continue;
            }
            $r = $r[0];
            $c->execute(array(':p' => (int) $id));
            $created = $zdbh->prepare("SELECT pk_created_ts FROM x_packages WHERE pk_id_pk = :p");
            $created->execute(array(':p' => (int) $id));
            $out[] = $r + array('id' => (int) $id, 'name' => (string) $r['packagename'], 'php' => (int) $r['enablePHP'] !== 0,
                'created' => (int) $created->fetchColumn(), 'clients' => $c->fetchAll(PDO::FETCH_KEY_PAIR));
        }
        return $out;
    }

    /**
     * One of the account's packages.
     *
     * @param mixed $id The package ID.
     * @return array<string, mixed>|false See Packages().
     */
    static function Mine($id)
    {
        foreach (self::Packages() as $p) {
            if ($p['id'] === (int) $id) {
                return $p;
            }
        }
        return false;
    }

    /**
     * Which page shows: the list, the new package form, a package's edit form, or a delete confirmation.
     *
     * @global runtime_controller $controller The request controller.
     * @return string 'list', 'new', 'edit' or 'delete'.
     */
    static function View()
    {
        global $controller;
        if (self::$failed !== '') {
            return self::$failed;
        }
        $show = $controller->GetControllerRequest('URL', 'show');
        if ($show === 'New') {
            return 'new';
        }
        if (in_array($show, array('Edit', 'Delete'), true) && self::Mine($controller->GetControllerRequest('URL', 'other'))) {
            return strtolower($show);
        }
        return 'list';
    }

    /**
     * Template flag <% if IsList %>.
     *
     * @return bool
     */
    static function getIsList()
    {
        return self::View() === 'list';
    }

    /**
     * Template flag <% if IsForm %>: the new package form or a package's edit form.
     *
     * @return bool
     */
    static function getIsForm()
    {
        return in_array(self::View(), array('new', 'edit'), true);
    }

    /**
     * Template flag <% if IsNew %>.
     *
     * @return bool
     */
    static function getIsNew()
    {
        return self::View() === 'new';
    }

    /**
     * Template flag <% if IsEdit %>.
     *
     * @return bool
     */
    static function getIsEdit()
    {
        return self::View() === 'edit';
    }

    /**
     * Template flag <% if IsDelete %>.
     *
     * @return bool
     */
    static function getIsDelete()
    {
        return self::View() === 'delete';
    }

    /**
     * The package cards (<@ PackageCards @>): the limits, clients and actions of each.
     *
     * @return string The HTML ('' when there are none).
     */
    static function getPackageCards()
    {
        $out = '';
        foreach (self::Packages() as $p) {
            $lim = '';
            // short names, so the two columns fit
            $short = array('domains' => 'Domains', 'subdomains' => 'Sub-domains', 'parkeddomains' => 'Parked', 'mailboxes' => 'Mailboxes', 'forwarders' => 'Forwarders',
                'distlists' => 'Lists', 'ftpaccounts' => 'FTP', 'mysql' => 'Databases', 'disk' => 'Disk', 'bandwidth' => 'Bandwidth');
            foreach (self::Limits() as $k => $l) {
                $lim .= '<li title="' . self::t($l[0]) . '">' . self::Svg($l[5]) . '<span>' . self::t($short[$k]) . '</span><b>' . self::LimitText($p[$l[2]], $l[4]) . '</b></li>';
            }
            $n = count($p['clients']);
            $out .= '<div class="pk-card"><div class="pk-card-head"><div class="pk-card-title"><b>' . self::h($p['name']) . '</b>'
                . '<span>' . ($n === 1 ? self::t('1 client') : str_replace('%d', (string) $n, self::t('%d clients'))) . ($p['created'] ? ' &middot; ' . str_replace('%s', self::h(ui_language::Date('j M Y', $p['created'])), self::t('made %s')) : '') . '</span></div>'
                . '</div>'
                . '<ul class="pk-limits">' . $lim . '</ul>'
                . ($n ? '<div class="pk-clients">' . implode('', array_map(function ($u) { return '<span>' . self::h($u) . '</span>'; }, array_slice(array_values($p['clients']), 0, 8))) . ($n > 8 ? '<em>+' . ($n - 8) . '</em>' : '') . '</div>' : '')
                . '<div class="pk-act"><a class="btn btn-default btn-sm" href="./?module=packages&amp;show=Edit&amp;other=' . $p['id'] . '">' . self::t('Edit') . '</a>'
                . '<a class="btn btn-default btn-sm" href="./?module=packages&amp;show=New&amp;copy=' . $p['id'] . '" title="' . self::t('A new package with these limits') . '">' . self::t('Copy') . '</a>'
                . '<a class="btn btn-default btn-sm pk-del" href="./?module=packages&amp;show=Delete&amp;other=' . $p['id'] . '" title="' . self::t('Delete') . '" aria-label="' . str_replace('%s', self::h($p['name']), self::t('Delete %s')) . '">'
                . self::Svg('<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>') . '</a></div></div>';
        }
        return $out;
    }

    /**
     * Template flag <% if HasPackages %>.
     *
     * @return bool
     */
    static function getHasPackages()
    {
        return count(self::Packages()) > 0;
    }

    /**
     * Template flag <% if NoPackages %>.
     *
     * @return bool
     */
    static function getNoPackages()
    {
        return !self::getHasPackages();
    }

    /**
     * The form's values: what was typed after a refused save, the package being changed, a copy of one (?copy=), or a new one.
     *
     * @global runtime_controller $controller The request controller.
     * @return array<string, mixed> 'id', 'name', 'php' (bool), and each limit's key: a count (-1 = no limit) or MB (0 = no limit).
     */
    static function FormValues()
    {
        global $controller;
        static $v = null;
        if ($v !== null) {
            return $v;
        }
        $f = $controller->GetAllControllerRequests('FORM');
        if (self::$failed !== '') {
            $v = array('id' => (int) ($f['inPackageID'] ?? 0), 'name' => (string) ($f['inPackageName'] ?? ''), 'php' => !empty($f['inEnablePHP']), 'posted' => true);
            foreach (self::Limits() as $k => $l) {
                $v[$k] = !empty($f[$l[1] . 'Unl']) ? ($l[4] ? 0 : -1) : (string) ($f[$l[1]] ?? '');
                $v[$k . '_unit'] = (string) ($f[$l[1] . 'Unit'] ?? 'GB');
            }
            return $v;
        }
        $src = self::View() === 'edit' ? self::Mine($controller->GetControllerRequest('URL', 'other')) : self::Mine($controller->GetControllerRequest('URL', 'copy'));
        $v = array('id' => self::View() === 'edit' && $src ? $src['id'] : 0, 'name' => self::View() === 'edit' && $src ? $src['name'] : ($src ? $src['name'] . ' copy' : ''),
            'php' => $src ? $src['php'] : true, 'posted' => false);
        $defaults = array('domains' => 1, 'subdomains' => 5, 'parkeddomains' => 1, 'mailboxes' => 10, 'forwarders' => 10, 'distlists' => 2, 'ftpaccounts' => 2, 'mysql' => 2, 'disk' => 2000, 'bandwidth' => 20000);
        foreach (self::Limits() as $k => $l) {
            $v[$k] = $src ? (float) $src[$l[2]] : $defaults[$k];
        }
        return $v;
    }

    /**
     * <@ FormId @>: the package's ID in the edit form.
     *
     * @return int
     */
    static function getFormId()
    {
        return (int) self::FormValues()['id'];
    }

    /**
     * <@ FormName @>: the name, escaped.
     *
     * @return string
     */
    static function getFormName()
    {
        return self::h(self::FormValues()['name']);
    }

    /**
     * <@ FormTitle @>: the form's heading.
     *
     * @return string
     */
    static function getFormTitle()
    {
        $v = self::FormValues();
        return self::View() === 'edit' && ($p = self::Mine($v['id'])) ? self::h($p['name']) : self::t('New package');
    }

    /**
     * <@ FormPHPChecked @>: ' checked' when PHP is on.
     *
     * @return string
     */
    static function getFormPHPChecked()
    {
        return self::FormValues()['php'] ? ' checked' : '';
    }

    /**
     * The highest use of each limit among the package's clients, and by whom (for the edit form's warnings).
     *
     * @param int $pid The package ID.
     * @return array<string, array{0: float, 1: string}> By limit key: the use (a count, or MB) and the user name.
     */
    static function HighestUse($pid)
    {
        $p = self::Mine($pid);
        $out = array();
        if (!$p) {
            return $out;
        }
        foreach (self::Limits() as $k => $l) {
            $out[$k] = array(0, '');
            foreach ($p['clients'] as $id => $user) {
                $use = (float) ctrl_users::GetQuotaUsages($l[3], $id);
                if ($l[4]) {
                    $use = $use / 1024000;
                }
                if ($use > $out[$k][0]) {
                    $out[$k] = array($use, (string) $user);
                }
            }
        }
        return $out;
    }

    /**
     * The limit fields (<@ LimitFields @>): a number each, MB or GB for the sizes, and "No limit".
     *
     * @return string The HTML.
     */
    static function getLimitFields()
    {
        $v = self::FormValues();
        $high = self::View() === 'edit' ? self::HighestUse($v['id']) : array();
        $out = '';
        foreach (self::Limits() as $k => $l) {
            $val = $v[$k];
            $unl = $val !== '' && (($l[4] && (float) $val <= 0) || (!$l[4] && (float) $val < 0));
            $unit = 'MB';
            $num = $unl ? '' : $val;
            if ($l[4] && !$unl) {
                if (!empty($v['posted'])) {
                    $unit = $v[$k . '_unit'] === 'MB' ? 'MB' : 'GB';
                } elseif ((float) $val >= 1000 && fmod((float) $val, 100) == 0) {
                    $unit = 'GB';
                    $num = rtrim(rtrim(number_format((float) $val / 1000, 2, '.', ''), '0'), '.');
                } else {
                    // exactly (3 decimals, as stored), so saving unchanged does not change it
                    $num = rtrim(rtrim(number_format((float) $val, 3, '.', ''), '0'), '.');
                }
            } elseif (!$l[4] && !$unl && $num !== '') {
                $num = (string) (int) $num;
            }
            $note = '';
            if (isset($high[$k]) && $high[$k][1] !== '') {
                $note = '<small class="pk-use" data-use="' . self::h($high[$k][0]) . '" data-size="' . ($l[4] ? 1 : 0) . '">' . self::t('Highest use:') . ' ' . self::h($high[$k][1]) . ', '
                    . ($l[4] ? self::LimitText($high[$k][0], true) : (int) $high[$k][0]) . '</small>';
            }
            $out .= '<div class="pk-field' . ($unl ? ' is-unl' : '') . '"><label for="' . $l[1] . '">' . self::Svg($l[5]) . self::t($l[0]) . '</label>'
                . '<div class="pk-in"><input class="form-control" type="number" name="' . $l[1] . '" id="' . $l[1] . '" min="0" step="' . ($l[4] ? 'any' : '1') . '" value="' . self::h($num) . '"' . ($unl ? ' disabled' : '') . '>'
                . ($l[4] ? '<select class="form-control" name="' . $l[1] . 'Unit" aria-label="' . self::t('Unit') . '"' . ($unl ? ' disabled' : '') . '><option' . ($unit === 'MB' ? ' selected' : '') . '>MB</option><option' . ($unit === 'GB' ? ' selected' : '') . '>GB</option></select>' : '')
                . '</div><label class="pk-unl"><input type="checkbox" name="' . $l[1] . 'Unl" value="1"' . ($unl ? ' checked' : '') . '> ' . self::t('No limit') . '</label>' . $note . '</div>';
        }
        return $out;
    }

    /**
     * The clients on the package being edited (<@ PackageClients @>).
     *
     * @return string The HTML.
     */
    static function getPackageClients()
    {
        $p = self::Mine(self::FormValues()['id']);
        if (!$p || !$p['clients']) {
            return '<p class="pk-sub">' . self::t('No client is on this package yet.') . '</p>';
        }
        return '<p class="pk-sub">' . (count($p['clients']) === 1 ? self::t('1 client. A change applies to it straight away.') : str_replace('%d', (string) count($p['clients']), self::t('%d clients. A change applies to them straight away.'))) . '</p><div class="pk-clients">'
            . implode('', array_map(function ($u) { return '<span>' . self::h($u) . '</span>'; }, array_values($p['clients']))) . '</div>';
    }

    /**
     * Read the limit fields from the form: a count (-1 = no limit) each, and MB (0 = no limit) for the sizes.
     *
     * @param array<string, mixed> $f The posted form.
     * @return array<string, int|float>|false By limit key; false when one is not a whole number of 0 or more (sizes: a number), with $limiterror set.
     */
    static function ReadLimits($f)
    {
        $out = array();
        foreach (self::Limits() as $k => $l) {
            if (!empty($f[$l[1] . 'Unl'])) {
                $out[$k] = $l[4] ? 0 : -1;
                continue;
            }
            $raw = trim((string) ($f[$l[1]] ?? ''));
            $ok = $l[4] ? preg_match('/^\d{1,9}(\.\d+)?$/', $raw) : preg_match('/^\d{1,6}$/', $raw);
            if (!$ok) {
                self::$limiterror = ui_language::translate($l[0]) . ': ' . ui_language::translate($l[4] ? 'enter a size, or tick No limit.' : 'enter a whole number (0 for none), or tick No limit.');
                return false;
            }
            $out[$k] = $l[4] ? round((float) $raw * (($f[$l[1] . 'Unit'] ?? 'MB') === 'GB' ? 1000 : 1), 3) : (int) $raw;
            if ($l[4] && $out[$k] <= 0) {
                self::$limiterror = ui_language::translate($l[0]) . ': ' . ui_language::translate('a size must be more than 0; to allow any size, tick No limit.');
                return false;
            }
        }
        return $out;
    }

    /**
     * Creates a package from the form, then back to the list.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool|void False when refused (the form shows again); otherwise ends with a redirect and exit.
     */
    static function doCreatePackage()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        self::$failed = 'new';
        $L = self::ReadLimits($f);
        if ($L === false) {
            return false;
        }
        if (self::ExecuteCreatePackage(ctrl_users::GetUserDetail()['userid'], (string) ($f['inPackageName'] ?? ''), !empty($f['inEnablePHP']) ? 1 : 0, $L['domains'], $L['subdomains'], $L['parkeddomains'],
            $L['mailboxes'], $L['forwarders'], $L['distlists'], $L['ftpaccounts'], $L['mysql'], $L['disk'], $L['bandwidth'])) {
            header('location: ./?module=' . $controller->GetCurrentModule() . '&saved=' . rawurlencode(self::CleanName($f['inPackageName'])));
            exit;
        }
        return false;
    }

    /**
     * Saves the edit form for one of the account's packages, then back to the list.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool|void False when not the account's package or refused; otherwise ends with a redirect and exit.
     */
    static function doUpdatePackage()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        // Only this reseller's own packages
        if (!isset($f['inPackageID']) || !self::Mine($f['inPackageID'])) {
            return false;
        }
        self::$failed = 'edit';
        $L = self::ReadLimits($f);
        if ($L === false) {
            return false;
        }
        if (self::ExecuteUpdatePackage(ctrl_users::GetUserDetail()['userid'], (int) $f['inPackageID'], (string) ($f['inPackageName'] ?? ''), !empty($f['inEnablePHP']) ? 1 : 0, $L['domains'], $L['subdomains'], $L['parkeddomains'],
            $L['mailboxes'], $L['forwarders'], $L['distlists'], $L['ftpaccounts'], $L['mysql'], $L['disk'], $L['bandwidth'])) {
            header('location: ./?module=' . $controller->GetCurrentModule() . '&saved=' . rawurlencode(self::CleanName($f['inPackageName'])));
            exit;
        }
        return false;
    }

    /**
     * The old list's Edit and Delete buttons: redirect to that page and exit.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doEditPackage()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::Packages() as $row) {
            foreach (array('Delete', 'Edit') as $what) {
                if (isset($formvars['in' . $what . '_' . $row['id']])) {
                    header('location: ./?module=' . $controller->GetCurrentModule() . '&show=' . $what . '&other=' . $row['id']);
                    exit;
                }
            }
        }
    }

    /**
     * Deletes a package from the confirmation form: its clients move to another of the account's packages.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool|void False when refused; otherwise ends with a redirect and exit.
     */
    static function doDeletePackage()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $p = isset($f['inPackageID']) ? self::Mine($f['inPackageID']) : false;
        $move = !empty($f['inMovePackage']) ? self::Mine($f['inMovePackage']) : false;
        if (!$p || (!empty($f['inMovePackage']) && !$move)) {
            return false;
        }
        if (self::ExecuteDeletePackage($p['id'], $move ? $move['id'] : 0)) {
            header('location: ./?module=' . $controller->GetCurrentModule() . '&deleted=' . rawurlencode($p['name']));
            exit;
        }
        return false;
    }

    /**
     * Returns the reseller's packages (for the web service; see ListPackages()).
     *
     * @return array<int, array<int|string, mixed>>|false
     */
    static function getPackageList()
    {
        $packages = self::ListPackages(ctrl_users::GetUserDetail()['userid']);
        return $packages ? $packages : false;
    }

    /**
     * The package the delete confirmation is for (<@ DeleteName @>), escaped.
     *
     * @global runtime_controller $controller The request controller.
     * @return string
     */
    static function getDeleteName()
    {
        global $controller;
        $p = self::Mine($controller->GetControllerRequest('URL', 'other'));
        return $p ? self::h($p['name']) : '';
    }

    /**
     * The package's ID in the delete confirmation (<@ DeleteId @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return int
     */
    static function getDeleteId()
    {
        global $controller;
        $p = self::Mine($controller->GetControllerRequest('URL', 'other'));
        return $p ? $p['id'] : 0;
    }

    /**
     * What deleting does (<@ DeleteDetails @>): where its clients go, or that another package is needed first.
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getDeleteDetails()
    {
        global $controller;
        $p = self::Mine($controller->GetControllerRequest('URL', 'other'));
        if (!$p) {
            return '';
        }
        $n = count($p['clients']);
        if ($n === 0) {
            return '<p>' . self::t('No client is on it.') . '</p>';
        }
        $opts = '';
        foreach (self::Packages() as $o) {
            if ($o['id'] !== $p['id']) {
                $opts .= '<option value="' . $o['id'] . '">' . self::h($o['name']) . '</option>';
            }
        }
        if ($opts === '') {
            return '<p class="pk-block">' . ($n === 1 ? self::t('1 client is on it, and you have no other package to move it to. Make another package first.') : str_replace('%d', (string) $n, self::t('%d clients are on it, and you have no other package to move them to. Make another package first.'))) . '</p>';
        }
        return '<p>' . self::t('Its clients keep everything they have; from now on the limits of the package you choose apply to them.') . '</p>'
            . '<div class="pk-move"><label for="inMovePackage">' . ($n === 1 ? self::t('Move its 1 client to:') : str_replace('%d', (string) $n, self::t('Move its %d clients to:'))) . '</label>'
            . '<select class="form-control" name="inMovePackage" id="inMovePackage" required>' . $opts . '</select></div>';
    }

    /**
     * Template flag <% if CanDelete %>: the package can be deleted (no clients, or another package to move them to).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getCanDelete()
    {
        global $controller;
        $p = self::Mine($controller->GetControllerRequest('URL', 'other'));
        return $p && (!$p['clients'] || count(self::Packages()) > 1);
    }

    /**
     * Returns the message for the last action (<@ Result @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string|null The alert HTML, or null when there is nothing to show.
     */
    static function getResult()
    {
        global $controller;
        $err = function ($m) { return ui_sysmessage::shout(ui_language::translate($m), 'zannounceerror'); };
        if (self::$limiterror !== '') {
            return ui_sysmessage::shout(self::h(self::$limiterror), 'zannounceerror');
        }
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return $err('Give the package a name.');
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return $err('The name may have letters, digits, spaces, dots, dashes and underscores, starting with a letter or digit, at most 30 characters.');
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return $err('You already have a package with that name.');
        }
        if (!fs_director::CheckForEmptyValue(self::$error)) {
            return $err('A limit is not a number.');
        }
        if (!fs_director::CheckForEmptyValue(self::$samepackage)) {
            return $err('Its clients need another package to move to.');
        }
        foreach (array('saved' => 'The package %s is saved.', 'deleted' => 'The package %s is deleted.') as $k => $m) {
            $who = $controller->GetControllerRequest('URL', $k);
            if ($who) {
                return ui_sysmessage::shout(str_replace('%s', '<strong>' . self::h($who) . '</strong>', self::t($m)), 'zannounceok');
            }
        }
        return;
    }

    // Webinterface sudo methods.
}
