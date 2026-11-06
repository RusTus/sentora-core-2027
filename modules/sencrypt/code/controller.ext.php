<?php
/**
	* Controller for sencrypt module for sentora version 2.0.0
	* Version : 3.0.0
	* Author : TGates
	* Additional work by Diablo925, Jettaman
 */

// search [(lecrypt) for LetsEncrypt code changes: tg] [make ssl: LeClient or LePanel]

// for LEscript you can use any logger according to Psr\Log\LoggerInterface
/**
 * Minimal logger for Lescript: echoes every call as a timestamped line.
 */
class Logger {
	/**
	 * Echo a log line for any method name (info, error, ...).
	 *
	 * @param string $name The called method, used as the log level.
	 * @param array<int,mixed> $arguments The arguments; the first is the message.
	 * @return void
	 */
	function __call($name, $arguments) {
		echo date('Y-m-d H:i:s')." [$name] {$arguments[0]}\n";
	}
}
//$logger = new Logger();

/**
 * Sencrypt module controller: Let's Encrypt, self-signed and third-party SSL certificates for the account's domains and the panel.
 */
class module_controller extends ctrl_module {
	
	/** @var bool|null True after a Let's Encrypt certificate was made. */
	static $okletsencrypt;
	/** @var bool|null True after a self-signed certificate was made. */
	static $okselfsigned;
	/** @var bool|null True after a third-party certificate was uploaded. */
	static $okthirdparty;
	/** @var bool|null True after a certificate was deleted. */
	static $delok;
	/** @var bool|null True when a certificate with that name already exists (or a folder check failed). */
	static $error;
	/** @var bool|null True when the domain's DNS does not point to this server. */
	static $dnsInvalid;
	/** @var bool|null True when PHP lacks OpenSSL or curl (set by getCheckModReq()). */
	static $modReqsError;
	/** @var bool|null True when port 443 is not open (set by getCheckPortReq()). */
	static $portReqsError;
	/** @var bool|null True after a certificate was revoked. */
	static $revokecert;
	/** @var bool|null True after a CSR was made and emailed. */
	static $keyadd;
	/** @var bool|null True when Let's Encrypt did not issue the certificate. */
	static $certFailed;
	//static $loggererror;

    /* Load CSS and JS files */
    /**
     * Template HTML: the module's stylesheet link.
     *
     * @global runtime_controller $controller The request controller.
     * @return string
     */
    static function getInit() {
        global $controller;
		// load module spcific style sheet
        $line = '<link rel="stylesheet" type="text/css" href="modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/sencrypt.css">';
        return $line;
    }

	/**
	 * Template hook: check PHP version and the OpenSSL and curl extensions; sets $modReqsError.
	 *
	 * @return void
	 */
	static function getCheckModReq() {
		// Post message if PHP requirments are not met 
		if (!defined("PHP_VERSION_ID") || PHP_VERSION_ID < 50500 || !extension_loaded('openssl') || !extension_loaded('curl')) {
			self::$modReqsError = true;
		}
	}

	/**
	 * Template hook: check that port 443 is open; sets $portReqsError.
	 *
	 * @return void
	 */
	static function getCheckPortReq() {
		// Post message if port 443 is not open. This used to ask api.sentora.org, which
		// reported an error whenever that site could not be reached; check locally instead.
		if (!sys_monitoring::PortStatus(443)) {
			self::$portReqsError = true;
		}
	}

// Module & Dispaly stuff - START
	/**
	 * Template value: CSS class of the "view certificates" tab.
	 *
	 * @return string|null "active" when ?ShowPanel= is set, else null.
	 */
	static function getShowViewCerts() {
		if (isset($_GET['ShowPanel']) == true) {
			if ($_GET['ShowPanel'] == 'viewcerts') {
				$showActiveTab = "active";
				return $showActiveTab;
			} else {
				$showActiveTab = "active";
				return $showActiveTab;
			}
		}
	}

	/**
	 * Template value: CSS class of the "create certificates" tab.
	 *
	 * @return string|null "active" or "none" when ?ShowPanel= is set, else null.
	 */
	static function getShowCreateCerts() {
		if (isset($_GET['ShowPanel']) == true ) {
			if ($_GET['ShowPanel'] == 'createcerts') {
				$showActiveTab = "active";
				return $showActiveTab;
			} else {
				$showActiveTab = "none";
				return $showActiveTab;
			}
		}
	}

	/**
	 * Template value: CSS display of the Let's Encrypt panel.
	 *
	 * @return string|null "block" for ?ShowPanel=letsencrypt, "none" without ?ShowPanel=, else null.
	 */
	static function getShowLetsEncryptTab() {
		if (isset($_GET['ShowPanel']) == true ) {
			if ($_GET['ShowPanel'] == 'letsencrypt') {
				$showPanel = "block";
				return $showPanel;
			}
		} else {
			$showPanel = "none";
			return $showPanel;
		}
	}

	/**
	 * Template value: CSS display of the third-party panel.
	 *
	 * @return string|null "block" for ?ShowPanel=third-party, "none" without ?ShowPanel=, else null.
	 */
	static function getShowThirdPartyTab() {
		if (isset($_GET['ShowPanel']) == true ) {
			if ($_GET['ShowPanel'] == 'third-party' )  {
				$showPanel = "block";
				return $showPanel;
			}		
		} else {
			$showPanel = "none";
			return $showPanel;
		}
	}

	/**
	 * Template value: CSS class of the Let's Encrypt tab.
	 *
	 * @return string|null "active" or "none" when ?ShowPanel= is set, else null.
	 */
	static function getShowLetsencryptActive() {
		if (isset($_GET['ShowPanel']) == true ) {
			if ($_GET['ShowPanel'] == 'letsencrypt') {
				$showActiveTab = "active";
				return $showActiveTab;
			} else {
				$showActiveTab = "none";
				return $showActiveTab;
			}
		}
	}

	/**
	 * Template value: CSS class of the third-party tab.
	 *
	 * @return string|null "active" or "none" when ?ShowPanel= is set, else null.
	 */
	static function getShowThird_partyActive() {
		if (isset($_GET['ShowPanel']) == true ) {
			if ($_GET['ShowPanel'] == 'third-party') {
				$showActiveTab = "active";
				return $showActiveTab;
			} else {
				$showActiveTab = "none";
				return $showActiveTab;
			}
		}
	}

	/**
	 * Template flag: the logged-in account is in the Administrators group.
	 *
	 * @return bool
	 */
	static function getAdmin() {
		$user = ctrl_users::GetUserDetail();
		return ($user['usergroup'] == 'Administrators');
	}
	
	/**
	 * Template loop: the panel domain with its "Encrypt" button, when it has no certificate.
	 *
	 * @return array<int, array<string, string>>|false|null See Show_Panel_Domains().
	 */
	static function getList_of_Panel_Domains() {
		$currentuser = ctrl_users::GetUserDetail();
		return self::Show_Panel_domains($currentuser['userid']);
	}	
	
	// panel ssl show - START
	/**
	 * Template loop: the panel domain's certificate.
	 *
	 * @return array<int, array<string, string|null>> See Show_Active_Panel_SSL_Domains().
	 */
	static function getList_of_Active_Panel_SSL() {
		$currentuser = ctrl_users::GetUserDetail();
		return self::Show_Active_Panel_SSL_Domains($currentuser['userid']);
	}
	
	/**
	 * List the panel domain's certificate (Let's Encrypt or third-party) with its buttons.
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @global runtime_controller $controller The request controller (unused).
	 * @return array<int, array<string, string|null>> One row: 'Active_Panel_Domain', 'Active_Panel_Provider', 'Active_Panel_Days', 'Active_Panel_Button', 'Active_Panel_Revoke' (HTML or null).
	 */
	static function Show_Active_Panel_SSL_Domains() {
		global $zdbh, $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$panelCertPath = ctrl_options::GetSystemOption('hosted_dir');
		$panelDomain = ctrl_options::GetSystemOption('sentora_domain');
		
		// Check if Panel ssl folder exists - This file should have been created during install.
		// Check if cert exist or not
		if ( is_file( $panelCertPath . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" . $panelDomain . "/cert.pem" ) ) {	
			$panelDeleteButton = '<form action="./?module=sencrypt&ShowPanel=letsencrypt&action=DeletePanelSSL" method="post">
			<input type="hidden" name="inName" value="'. $panelDomain.'">
			<button class="button-loader btn btn-warning" type="submit" id="button" name="inDeleteSSL" id="inDeletePanelSSL" value="inDeletePanelSSL">' . ui_language::translate("Delete") . '</button>
			</form>';
			$certinfo = openssl_x509_parse(file_get_contents(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" . $panelDomain . "/cert.pem"));
			$validTo = date('Y-m-d', $certinfo["validTo_time_t"]);
			$now = time();
			$your_date = strtotime("$validTo");
			$datediff = $your_date - $now;
			$panelday = floor($datediff / (60 * 60 * 24));
			$reNewDay = $panelday - 30;
			$sslvendor = "Lets Encrypt";
			
			if($panelday <= "-1700") {
				$paneldays = ui_language::translate("Not initialized yet");
				} else {
				$paneldays = ui_language::translate("Expiry in") . ' ' . $panelday . ' ' . ui_language::translate("days") . ' - ' . ui_language::translate("Auto-renewal in") . ' ' . $reNewDay . ' ' . ui_language::translate("days");
			}
			
			// Revoke button just incase its needed
			$panelRevokeButton = '<form action="./?module=sencrypt&action=RevokePanelSSL" method="post">
				<input type="hidden" name="inDomain" value="'. $panelDomain.'">
				<button class="button-loader btn btn-danger" type="submit" id="button" name="inRevokeSSL" id="inRevokePanelSSL" value="inRevokePanelSSL">' . ui_language::translate("Revoke") . '</button>
			</form>';
		
			$panelres[] = array('Active_Panel_Domain' => $panelDomain, 'Active_Panel_Provider' => $sslvendor, 'Active_Panel_Days' =>  $paneldays, 'Active_Panel_Button' => $panelDeleteButton,  'Active_Panel_Revoke' => $panelRevokeButton);
		
		// If third party ssl show
		} elseif ( is_dir( $panelCertPath . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $panelDomain . "/" ) ) {
						
			$panelDeleteButton = '<form action="./?module=sencrypt&ShowPanel=third_party&action=TPDelete" method="post">
			<input type="hidden" name="inName" value="'. $panelDomain.'">
			<button class="button-loader btn btn-warning" type="submit" id="button" name="inDeleteSSL" id="inDeletePanelSSL" value="inDeletePanelSSL">' . ui_language::translate("Delete") . '</button>
			</form>';
			$certinfo = openssl_x509_parse(file_get_contents(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $panelDomain . "/cert.pem"));
			$validTo = date('Y-m-d', $certinfo["validTo_time_t"]);
			$now = time();
			$your_date = strtotime("$validTo");
			$datediff = $your_date - $now;
			$panelday = floor($datediff / (60 * 60 * 24));
			$reNewDay = $panelday - 30;
			$sslvendor = "Third-Party";
			
			if($panelday <= "-1700") {
				$paneldays = ui_language::translate("Not initialized yet");
			} else {
				$paneldays = ui_language::translate("Expiry in") . ' ' . $panelday . ' ' . ui_language::translate("days") . ".";
			}
		
			$panelres[] = array('Active_Panel_Domain' => $panelDomain, 'Active_Panel_Provider' => $sslvendor, 'Active_Panel_Days' =>  $paneldays, 'Active_Panel_Button' => $panelDeleteButton, 'Active_Panel_Revoke' => NULL);
		
		} else {

			$panelres[] = array('Active_Panel_Domain' => ui_language::translate("No Active Panel Domain Certificates"), 'Active_Panel_Provider' => NULL, 'Active_Panel_Days' =>  NULL, 'Active_Panel_Button' => NULL, 'Active_Panel_Revoke' => NULL);
			
		}
		return $panelres;
		
	}

	/**
	 * List the panel domain with an "Encrypt" button when it has no certificate yet.
	 *
	 * @global db_driver $zdbh The panel's database connection (unused).
	 * @global runtime_controller $controller The request controller (unused).
	 * @return array<int, array<string, string>>|false|null One row: 'Panel_Domain', 'Panel_Button'; false when it has a Let's Encrypt certificate; null (undefined variable) when it has a third-party one.
	 */
	static function Show_Panel_Domains() {
		global $zdbh, $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$panelCertPath = ctrl_options::GetSystemOption('hosted_dir');
		$panelDomain = ctrl_options::GetSystemOption('sentora_domain');
		
				
		// Check if cert exist or not
		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] ."/ssl/sencrypt/letsencrypt/". $panelDomain ."/") ) {

			// Check if ssl exists else where
			if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] ."/ssl/sencrypt/third_party/". $panelDomain ."/") ) {
				// do nothing cert exists
				$panelbutton = '<form action="./?module=sencrypt&ShowPanel=letsencrypt&action=MakePanelSSL" method="post">
				<input type="hidden" name="inDomain" value="'. $panelDomain.'">
				<button class="button-loader btn btn-primary" type="submit" id="button" name="in" id="inMakePanelSSL" value="inMakePanelSSL">' . ui_language::translate("Encrypt") . '</button>
				</form>';
				$paneldays = "";
				
				$panelres[] = array('Panel_Domain' => $panelDomain, 'Panel_Button' => $panelbutton);
				
				$panelresult = $panelres;
			}
			
		} else {
	
			$panelresult = false;

		}
		
		return $panelresult;
	}
	// panel ssl show - END

	// domain ssl show -START
	/**
	 * Template loop: the account's domains that have no certificate yet.
	 *
	 * @return array<int, array<string, string|null>> See Show_list_of_domains().
	 */
	static function getList_of_domains() {
		$currentuser = ctrl_users::GetUserDetail();
		return self::Show_list_of_domains($currentuser['userid']);
	}

	/**
	 * List the account's domains without a certificate, each with an "Encrypt" button.
	 *
	 * Creates the account's ssl/sencrypt/letsencrypt folder when missing.
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @global runtime_controller $controller The request controller (unused).
	 * @return array<int, array<string, string|null>> Rows: 'Vh_Domain', 'Vh_Button' (HTML or null); one message row when there are none.
	 */
	static function Show_list_of_domains() {
		global $zdbh, $controller;
        $currentuser = ctrl_users::GetUserDetail();
		
		$sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_enabled_in=1 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc ASC";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $currentuser['userid']);
            $res = array();
            $sql->execute();
            while ($rowdomains = $sql->fetch()) {
				// Check if folder ssl exists
				if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] ."/ssl/sencrypt/letsencrypt/") ) {
					fs_director::CreateDirectory( ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] ."/ssl/sencrypt/letsencrypt/" );
				}
				
				// Check if cert exist or not
				if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] ."/ssl/sencrypt/letsencrypt/". $rowdomains['vh_name_vc'] ."/") ) {

					// Check if ssl exists else where
					if (is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] ."/ssl/sencrypt/third_party/". $rowdomains['vh_name_vc'] ."/") ) {

						// Do nothing

					} else {

						$button = '<form action="./?module=sencrypt&ShowPanel=letsencrypt&action=MakeSSL" method="post">
							<input type="hidden" name="inDomain" value="'.$rowdomains['vh_name_vc'].'">
							<button class="button-loader btn btn-primary" type="submit" id="button" name="in" id="inMakeSSL" value="inMakeSSL">' . ui_language::translate("Encrypt") . '</button>
						</form>';
						
						$res[] = array('Vh_Domain' => $rowdomains['vh_name_vc'], 'Vh_Button' => $button);
					}
				}
			}

			if (!$res) {
				$res[] = array('Vh_Domain' => ui_language::translate("All domains have certificates."), 'Vh_Button' => NULL);
			}

		} else {	
		
			// Must be a list of rows: the template loops over it (a flat array is a fatal error in PHP 8)
			$res = array(array('Vh_Domain' => ui_language::translate("You have no available domains. Add one to continue."), 'Vh_Button' => NULL));
	
		}
		return $res;
	}

	/**
	 * Template loop: the account's domain certificates.
	 *
	 * @return array<int, array<string, string|null>> See Show_list_of_active_domain_ssl().
	 */
	static function getList_of_active_domains_ssl() {
		$currentuser = ctrl_users::GetUserDetail();
		return self::Show_list_of_active_domain_ssl($currentuser['userid']);
	}
	
	/**
	 * List the account's domain certificates (third-party or Let's Encrypt) with expiry and buttons.
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @global runtime_controller $controller The request controller (unused).
	 * @return array<int, array<string, string|null>> Rows: 'Domain_AC', 'Button_AC', 'Vendor_AC', 'Days_AC', 'Download_AC', 'Revoke_AC'; one message row when there are none.
	 */
	static function Show_list_of_active_domain_ssl() {
		global $zdbh, $controller;
	    $currentuser = ctrl_users::GetUserDetail();
		$panelDomain = ctrl_options::GetSystemOption('sentora_domain');
		
		// Show Client SSL certs
		$sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_enabled_in=1 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc ASC";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();
        if ($numrows->fetchColumn() > 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $currentuser['userid']);
            $res = array();
            $sql->execute();
            while ($rowdomains = $sql->fetch()) {
				// Check if folder ssl exists
				if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/") ) {
					fs_director::CreateDirectory( ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" );
				}
				
				// If Third Party Vhost cert	
				if ( is_file(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $rowdomains['vh_name_vc'] . "/cert.pem" ) ) {
	
					$button = '<form action="./?module=sencrypt&ShowPanel=third-party&action=TPDelete" method="post">
						<input type="hidden" name="inName" value="'. $rowdomains['vh_name_vc'] .'">
						<button class="btn btn-warning" type="submit" id="button" name="inDelete_'. $currentuser["username"].'" id="inDelete_'. $currentuser["username"].'" value="inDelete_' . $currentuser["username"] . '">' . ui_language::translate("Delete") . '</button></td>
						 '.runtime_csfr::Token().'
					</form>';
					
					$Downloadbutton = '<form action="./?module=sencrypt&ShowPanel=third-party&action=Download" method="post">
							<input type="hidden" name="inName" value="'. $rowdomains['vh_name_vc'] .'">
							<button class="btn btn-primary1" type="submit" id="button" name="inDownload_'. $currentuser["username"].'" id="inDownload_'. $currentuser["username"].'" value="inDownload_'. $currentuser["username"].'">' . ui_language::translate("Download") . '</button></td>
					</form>';
					
					$certinfo = openssl_x509_parse(file_get_contents(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $rowdomains['vh_name_vc'] . "/cert.pem"));
					$validTo = date('Y-m-d', $certinfo["validTo_time_t"]);
					$now = time();
					$your_date = strtotime("$validTo");
					$datediff = $your_date - $now;
					$day = floor($datediff / (60 * 60 * 24));
					$sslvendor = "Third-Party";
					
					$reNewDay = $day - 30;
					
					if($day <= "-1700") {
						$days = ui_language::translate("Not initialized yet"); } else {
						$days = ui_language::translate("Expiry in") . ' ' . $day . ' ' . ui_language::translate("days") . ".";
					}
							
					$res[] = array('Domain_AC' => $rowdomains['vh_name_vc'], 'Button_AC' => $button, 'Vendor_AC' => $sslvendor, 'Days_AC' =>  $days, 'Download_AC' => $Downloadbutton, 'Revoke_AC' => NULL );
					
				// If Letsencrypt cert	
				} elseif ( is_file(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" . $rowdomains['vh_name_vc'] . "/cert.pem" ) ) {
					
					$button = '<form action="./?module=sencrypt&ShowPanel=letsencrypt&action=Delete" method="post">
						<input type="hidden" name="inDomain" value="'. $rowdomains['vh_name_vc'].'">
						<button class="button-loader btn btn-warning" type="submit" id="button" name="inDeleteSSL" id="inDeleteSSL" value="inDeleteSSL">' . ui_language::translate("Delete") . '</button>
					</form>';
					
					// Revoke button just incase its needed
					$RevokeButton = '<form action="./?module=sencrypt&action=RevokeSSL" method="post">
						<input type="hidden" name="inDomain" value="'. $rowdomains['vh_name_vc'].'">
						<button class="button-loader btn btn btn-danger" type="submit" id="button" name="inRevokeSSL" id="inRevokeSSL" value="inRevokeSSL">' . ui_language::translate("Revoke") . '</button>
					</form>';
					
					$certinfo = openssl_x509_parse(file_get_contents(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" . $rowdomains['vh_name_vc'] . "/cert.pem"));
					$validTo = date('Y-m-d', $certinfo["validTo_time_t"]);
					$now = time();
					$your_date = strtotime("$validTo");
					$datediff = $your_date - $now;
					$day = floor($datediff / (60 * 60 * 24));
					$sslvendor = "Lets Encrypt";

					$reNewDay = $day - 30;

					if($day <= "-1700") {
						$days = ui_language::translate("Not initialized yet"); } else {
						$days = ui_language::translate("Expiry in") . ' ' . $day . ' ' . ui_language::translate("days") . ' - ' . ui_language::translate("Auto-renewal in") . ' ' . $reNewDay . ' ' . ui_language::translate("days") . '.';
					}
					
					$res[] = array('Domain_AC' => $rowdomains['vh_name_vc'], 'Button_AC' => $button, 'Vendor_AC' => $sslvendor, 'Days_AC' =>  $days, 'Download_AC' => NULL, 'Revoke_AC' => $RevokeButton);
					
				}
			}
			if (!$res)
			{
				$res[] = array('Domain_AC' => ui_language::translate("No Active Domain Certificates"), 'Button_AC' => NULL, 'Vendor_AC' => NULL, 'Days_AC' =>  NULL, 'Download_AC' => NULL, 'Revoke_AC' => NULL);
			}

		} else {		
								
			$res[] = array('Domain_AC' => ui_language::translate("No Active Domain Certificates"), 'Button_AC' => NULL, 'Vendor_AC' => NULL, 'Days_AC' =>  NULL, 'Download_AC' => NULL, 'Revoke_AC' => NULL);
			
		}
		
		return $res;
	}

// Module & Dispaly stuff - END

// Third_Party code below - START

	/**
	 * Send a third-party certificate's folder as a zip download.
	 *
	 * @global db_driver $zdbh The panel's database connection (unused).
	 * @global runtime_controller $controller The request controller (unused).
	 * @param string $domain The domain name.
	 * @param string $username The account's username.
	 * @return true Always true (the zip has already been sent).
	 */
	static function ExecuteDownload($domain, $username) {
		set_time_limit(0);
		global $zdbh;
		global $controller;
		$rootdir = str_replace('.', '_', $domain);
		
		$temp_dir = ctrl_options::GetSystemOption('sentora_root') . "etc/tmp/";
		$homedir = ctrl_options::GetSystemOption('hosted_dir') . $username;
    	$backupname = $rootdir;
		// -y: symbolic links are stored as links, not followed (a link in the account's
		// folder could otherwise put any file the panel can read into this download)
		$resault = exec("cd " . escapeshellarg($homedir . "/ssl/sencrypt/third_party/" . $domain . "/") . " && " . escapeshellarg(ctrl_options::GetSystemOption('zip_exe')) . " -r9y " . escapeshellarg($temp_dir . $backupname) . " .");
        @chmod($temp_dir . $backupname . ".zip", 0600);
		$filename = $backupname . ".zip";
		$filepath = $temp_dir;
		header("Pragma: public");
		header("Expires: 0");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header("Cache-Control: public");
		header("Content-Description: File Transfer");
		header("Content-type: application/octet-stream");
		header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
		header("Content-Transfer-Encoding: binary");
		header("Content-Length: ".filesize($filepath. $filename));
		ob_end_flush();
		readfile($filepath. $filename);
		unlink($temp_dir . $backupname . ".zip");
		
		return true;
		
		// Return to page. Reload issue. Fix below
		header('Location: ' . $_SERVER['HTTP_REFERER']);
	}
	
	/**
	 * Check that a name is a domain or subdomain of the logged-in account (or the panel domain for an administrator).
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @param mixed $domain The name (from the form; cast to string).
	 * @return bool
	 */
	static function OwnsDomainName($domain) {
		global $zdbh;
		$domain = (string) $domain;
		if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $domain) || strpos($domain, '..') !== false) {
			return false;
		}
		$currentuser = ctrl_users::GetUserDetail();
		if ($domain === (string) ctrl_options::GetSystemOption('sentora_domain') && (int) $currentuser['usergroupid'] === 1) {
			return true;
		}
		$sql = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_name_vc = :domain AND vh_acc_fk = :uid AND vh_deleted_ts IS NULL");
		$sql->bindParam(':domain', $domain);
		$sql->bindParam(':uid', $currentuser['userid']);
		$sql->execute();
		return (int) $sql->fetchColumn() > 0;
	}

	/**
	 * Handle the third-party certificate download POST.
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return bool|null False when the domain is not the account's own, true after the download, else null.
	 */
	static function doDownload() {
		
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
		// Only this account's own domains (the name comes from the form, and is also
		// used in file paths, so a "../" or another account's domain must never get through)
		if (!self::OwnsDomainName(isset($formvars['inDomain']) ? $formvars['inDomain'] : '')) {
			return false;
		}
        if (self::ExecuteDownload($formvars['inName'], $currentuser["username"])) {
            return true;
		}
    }

	/**
	 * Handle the CSR form POST: make a key and CSR and email them.
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return bool|null False on a check failure, true when sent, else null.
	 */
	static function doMakeCSR() {
		global $controller;
		runtime_csfr::Protect();
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		// Only this account's own domains (the name comes from the form, and is also
		// used in file paths, so a "../" or another account's domain must never get through)
		if (!self::OwnsDomainName(isset($formvars['inDomain']) ? $formvars['inDomain'] : '')) {
			return false;
		}
		if (empty($formvars['inDomain']) || empty($formvars['inName']) || empty($formvars['inAddress']) || empty($formvars['inCity']) || empty($formvars['inCountry']) || empty($formvars['inCompany'])) { 
			self::$empty = true;
			return false;
		}
		if (self::ExecuteCSR($formvars['inDomain'], $formvars['inName'], $formvars['inAddress'], $formvars['inCity'], $formvars['inCountry'], $formvars['inCompany'], $formvars['inPassword'])) {
			return true;
		}
	}
		
	/**
	 * Make a 4096-bit key and a CSR and email both to the given address.
	 *
	 * @global db_driver $zdbh The panel's database connection (unused).
	 * @global runtime_controller $controller The request controller.
	 * @param string $domain The common name.
	 * @param string $name The state or province.
	 * @param string $address The email address (also the CSR's emailAddress).
	 * @param string $city The locality.
	 * @param string $country The two-letter country code.
	 * @param string $company The organization.
	 * @param string|null $password The pass phrase for the exported key.
	 * @return true Always true.
	 */
	static function ExecuteCSR($domain, $name, $address, $city, $country, $company, $password) {
		global $zdbh;
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		$config = array('digest_alg' => 'sha256', 'private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA,  'encrypt_key' => true);
		$csrconfig = array('digest_alg' => 'sha256');
		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/key/") ) {
			fs_director::CreateDirectory(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/key/" );
		}
			
		$dn = array(
					"countryName" => "$country",
					"stateOrProvinceName" => "$name",
					"localityName" => "$city",
					"organizationName" => "$company",
					"commonName" => "$domain",
					"emailAddress" => "$address"
		);
			
		$privkey = openssl_pkey_new($config);
		$csr = openssl_csr_new($dn, $privkey, $csrconfig);
			
		openssl_csr_export($csr, $csrout);
		openssl_pkey_export($privkey, $pkeyout, $password);
			
		openssl_pkey_export_to_file($privkey, ctrl_options::GetSystemOption('hosted_dir'). $currentuser["username"] . "/ssl/sencrypt/third_party/key/" . $domain . ".key");
			
			$email = $address;
			$emailsubject = "Certificate Signing Request";
			$emailbody = "Hi $currentuser[username]\n\n
			---------------------------------CSR START-------------------------------
			\n\n\n
			$csrout
			\n\n\n
			---------------------------------CSR END-------------------------------";
			
			// PHP Mailer option
			$phpmailer = new sys_email();
			//$phpmailer->From = "info@sentora.org";
			$phpmailer->Subject = $emailsubject;
			$phpmailer->Body = $emailbody;
			$phpmailer->AddAttachment(ctrl_options::GetSystemOption('hosted_dir'). $currentuser["username"] . "/ssl/sencrypt/third_party/key/" . $domain . ".key");
			$phpmailer->AddAddress($email);
			$phpmailer->SendEmail();

			unlink(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/key/" . $domain . ".key");
			rmdir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/key/");
			self::$keyadd = true;
			return true;
			
	}

	/**
	 * Handle the third-party certificate delete POST.
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return bool|null False when the domain is not the account's own, true when deleted, else null.
	 */
	static function doTPDelete() {
        global $controller;
        runtime_csfr::Protect(); // Sentora: was commented out
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
		// Only this account's own domains (the name comes from the form, and is also
		// used in file paths, so a "../" or another account's domain must never get through)
		if (!self::OwnsDomainName(isset($formvars['inDomain']) ? $formvars['inDomain'] : '')) {
			return false;
		}
		
		$sub_module = "third_party";
		
        if (self::ExecuteTPDelete($formvars['inName'], $currentuser["username"], $sub_module)) {
            return true;
		}
    }

	/**
	 * Delete a certificate's folder and remove its Apache SSL config from the panel settings or the vhost.
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @global runtime_controller $controller The request controller (unused).
	 * @param string $domain The domain name.
	 * @param string $username The account's username.
	 * @param string $sub_module The certificate kind: 'letsencrypt', 'third_party' or 'self_signed'.
	 * @return true Always true.
	 */
	static function ExecuteTPDelete($domain, $username, $sub_module) {
		global $zdbh;
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$dir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/" . $sub_module . "/" . $domain;
		$objects = scandir($dir);
		
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				unlink($dir."/" . $object);
			}
		}
			
		rmdir($dir);

		// Sentora domain CERTS
		if($domain == ctrl_options::GetSystemOption('sentora_domain')) {
			
			// For Letsencrypt or third-party NON Self signed (lecrypt)
			$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();
			$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/private.pem" . fs_filehandler::NewLine();

			// If Letsencrypt or purchased SSL
			if ( $sub_module == "letsencrypt" ) {
				$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/chain.pem" . fs_filehandler::NewLine();
				
			} elseif ( $sub_module == "third_party" ) {
				$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/intermediate.crt". fs_filehandler::NewLine();
				
			} elseif ( $sub_module == "self_signed" ) {
				// self signed - DO NOthing
			}
			
			$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();
				
		// NEW CODE
			if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
				// For older Sentora support
				$new = '';
				$name = 'global_zpcustom';
				
				$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = replace(so_value_tx, :data, :new) WHERE so_name_vc = :name");
				$sql->bindParam(':data', $line);
				$sql->bindParam(':new', $new);
				$sql->bindParam(':name', $name);
				$sql->execute();
	
			} else {
				// For Sentora 2.0
				$new = NULL;
				$name = 'panel_ssl_tx';
					
				$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = replace(so_value_tx, :data, :new) WHERE so_name_vc = :name");
				$sql->bindParam(':data', $line);
				$sql->bindParam(':new', $new);
				$sql->bindParam(':name', $name);
				$sql->execute();		
			
			}
		
		// For Self signed
		// NEW CODE - this should be for self signed...

			$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();
			$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/private.pem" . fs_filehandler::NewLine();

			$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();
				
			// Update Data
			$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = replace(so_value_tx, :data, :new) WHERE so_name_vc = :name");
			$sql->bindParam(':data', $line);
			$sql->bindParam(':new', $new);
			$sql->bindParam(':name', $name);
			$sql->execute();
			
			// Update Port
			//$portname = "sentora_port";
			//$port = "80";
			
			//$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
			//$updatesql->bindParam(':value', $port);
			//$updatesql->bindParam(':name', $portname);
			//$updatesql->execute();
			
		} else {
			
			// USER Domain Letsencrypt and Third-party CERT (lecrypt)
			
			$port 			= NULL;
			$portforward	= NULL;
						
			$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();			
			$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/private.pem" . fs_filehandler::NewLine();
			
			// If Letsencrypt or purchased SSL
			if ( $sub_module == "letsencrypt" ) {
				$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/chain.pem" . fs_filehandler::NewLine();
				
			} elseif ( $sub_module == "third_party" ) {
				$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/intermediate.crt". fs_filehandler::NewLine();
				
			} elseif ( $sub_module == "self_signed" ) {
				// self signed - DO NOthing
			}
			
			$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();
				
			if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {	
				// For older Sentora support
				$new = '';
				
				$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx = replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port WHERE vh_name_vc = :domain");
				//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx = replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
				$sql->bindParam(':data', $line);
				$sql->bindParam(':new', $new);
				$sql->bindParam(':domain', $domain);
				$sql->bindParam(':port', $new);
				//$sql->bindParam(':portforward', $new);
				$sql->execute();
				
			} else {
				// For Sentora 2.0
				$new = NULL;
				
				$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx = replace(vh_ssl_tx, :data, :new), vh_ssl_port_in=:port, vh_custom_port_in=:customport WHERE vh_name_vc = :domain");
				//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx = replace(vh_ssl_tx, :data, :new), vh_ssl_port_in=:port, vh_custom_port_in=:customport, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
				$sql->bindParam(':data', $line);
				$sql->bindParam(':new', $new);
				$sql->bindParam(':port', $port);
				$sql->bindParam(':customport', $new);
				//$sql->bindParam(':portforward', $new);
				$sql->bindParam(':domain', $domain);
				$sql->execute();
													
			}
			
			// Self Signed 

				$ssline = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
				$ssline .= fs_filehandler::NewLine();
				$ssline .= 'SSLEngine On' . fs_filehandler::NewLine();
				$ssline .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();	
				$ssline .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
				$ssline .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();				
				$ssline .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
				$ssline .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/private.pem" . fs_filehandler::NewLine();
				$ssline .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();
	
				if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {	
					// For older Sentora support
					$new = '';
					
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx = replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port WHERE vh_name_vc = :domain");
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx = replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $ssline);
					$sql->bindParam(':new', $new);
					$sql->bindParam(':domain', $domain);
					$sql->bindParam(':port', $new);
					//$sql->bindParam(':portforward', $new);
					$sql->execute();
					
				} else {
					// For Sentora 2.0
					$new = NULL;
					
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx = replace(vh_ssl_tx, :data, :new), vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $ssline);
					$sql->bindParam(':new', $new);
					$sql->bindParam(':domain', $domain);
					$sql->bindParam(':port', $new);
					$sql->execute();
				}
// Self sign END
	
		}

	  	self::SetWriteApacheConfigTrue();
		self::$delok = true;
		return true;

	}

	/**
	 * Handle the third-party certificate upload POST (key, certificate and intermediate).
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @global runtime_controller $controller The request controller.
	 * @return bool True when stored and configured, false on a check failure.
	 */
	static function doUploadSSL() {
		global $zdbh;
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		// Only this account's own domains (the name comes from the form, and is also
		// used in file paths, so a "../" or another account's domain must never get through)
		if (!self::OwnsDomainName(isset($formvars['inDomain']) ? $formvars['inDomain'] : '')) {
			return false;
		}
		$domain = $formvars["inDomain"];

		if (empty($_FILES["inkey"]["name"]) || empty($_FILES["inWCA"]["name"])) { 
			self::$empty = true;
			return false; 
		}
		
		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/") ) {
			mkdir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/", 0777);
		}
		// These folders are in the account's own folder, which the account controls: each
		// must be a real folder there, not a link (through one the panel would write the
		// uploaded files wherever the link points)
		$home = rtrim(ctrl_options::GetSystemOption('hosted_dir'), '/') . '/' . $currentuser["username"];
		foreach (array('', '/ssl', '/ssl/sencrypt', '/ssl/sencrypt/third_party') as $sub) {
			clearstatcache(true, $home . $sub);
			if (is_link($home . $sub) || !is_dir($home . $sub) || realpath($home . $sub) !== $home . $sub) {
				self::$error = true;
				return false;
			}
		}
			
		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/") ) {
				mkdir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/", 0777);
			} else {
				self::$error = true;
				return false;
			}
			
			$target_dir = ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/";
			
			$uploadkey = $target_dir . "private.pem";
			$uploadwcrt = $target_dir . "cert.pem";
			$uploadicrt = $target_dir . "intermediate.crt";
			
			move_uploaded_file($_FILES["inkey"]["tmp_name"], $uploadkey);
			move_uploaded_file($_FILES["inWCA"]["tmp_name"], $uploadwcrt);
			move_uploaded_file($_FILES["inICA"]["tmp_name"], $uploadicrt);
			// the private key: the panel and Apache (root) only
			@chmod($uploadkey, 0600);
			
			if($domain == ctrl_options::GetSystemOption('sentora_domain')) {
			
				$line = "# Made from Sencrypt - third_party - start" . fs_filehandler::NewLine();
				$line  .= fs_filehandler::NewLine();
				$line .= 'SSLEngine On' . fs_filehandler::NewLine();
				$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();		
				$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
				$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();				
				$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
				$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/private.pem" . fs_filehandler::NewLine();
				$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/intermediate.crt". fs_filehandler::NewLine();
				$line .= "# Made from Sencrypt - third_party - end" . fs_filehandler::NewLine();

				if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
					// For older Sentora support
					$sql = $zdbh->prepare("SELECT * FROM x_settings WHERE so_name_vc  = :name");
					$sql->bindParam(':name', $name);
					$sql->execute();
				
					while ($row = $sql->fetch()) { $olddata = $row['so_value_tx']; }
						$data = $olddata. $line;
						$name = 'global_zpcustom';
						
						$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
						$updatesql->bindParam(':value', $data);
						$updatesql->bindParam(':name', $name);
						$updatesql->execute();
							
						$portname = "sentora_port";
						$port = "443";
						$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
						$updatesql->bindParam(':value', $port);
						$updatesql->bindParam(':name', $portname);
						$updatesql->execute();

				} else {
					// For Sentora 2.0
						$name = 'panel_ssl_tx';
						
						// update panel data
						$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
						$updatesql->bindParam(':value', $line);
						$updatesql->bindParam(':name', $name);
						$updatesql->execute();
					
						// Update panel port					
						$portname = "sentora_port";
						$port = "443";
						$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
						$updatesql->bindParam(':value', $port);
						$updatesql->bindParam(':name', $portname);
						$updatesql->execute();
						
				}

			} else {
				
				$line = "# Made from Sencrypt - third_party - start" . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
                $line .= 'SSLEngine On' . fs_filehandler::NewLine();
				$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();	
				$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
				$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();			
				$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
				$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/private.pem" . fs_filehandler::NewLine();
				$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/intermediate.crt". fs_filehandler::NewLine();
				$line .= "# Made from Sencrypt - third_party - end" . fs_filehandler::NewLine();
				
				$port = "443";
				
				if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
					// For older Sentora support
					$portforward 	= "1";
					
					$sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc = :domain AND vh_deleted_ts IS NULL");
					$sql->bindParam(':domain', $domain);
					$sql->execute();
					
					while ($row = $sql->fetch()) { 
						$olddata = $row['vh_custom_tx']; 
					}	
						
						$data = $olddata. $line;
						
						$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx=:data, vh_custom_port_in=:port WHERE vh_name_vc = :domain");
						//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx=:data, vh_custom_port_in=:port, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
						$sql->bindParam(':data', $data);
						$sql->bindParam(':domain', $domain);
						$sql->bindParam(':port', $port);
						//$sql->bindParam(':portforward', $portforward);
						$sql->execute();
					
				} else {
					// For Sentora 2.0
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx=:data, vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $line);
					$sql->bindParam(':domain', $domain);
					$sql->bindParam(':port', $port);
					$sql->execute();
						
				}
			}
			
			self::SetWriteApacheConfigTrue();
			self::$okthirdparty = true;
			return true;
	}

	/**
	 * Handle the self-signed certificate form POST.
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return bool|null False on a check failure, true when made, else null.
	 */
	static function doMakenew() {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
		// Only this account's own domains (the name comes from the form, and is also
		// used in file paths, so a "../" or another account's domain must never get through)
		if (!self::OwnsDomainName(isset($formvars['inDomain']) ? $formvars['inDomain'] : '')) {
			return false;
		}
		if (empty($formvars['inDomain']) || empty($formvars['inName']) || empty($formvars['inAddress']) || empty($formvars['inCity']) || empty($formvars['inCountry']) || empty($formvars['inCompany'])) { 
			self::$empty = true;
			return false;
		}
        if (self::ExecuteMakeTPssl($formvars['inDomain'], $formvars['inName'], $formvars['inAddress'], $formvars['inCity'], $formvars['inCountry'], $formvars['inCompany']))
	        return true;
	}
		
	/**
	 * Make a self-signed certificate (365 days) for a domain and configure it.
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @global runtime_controller $controller The request controller.
	 * @param string $domain The common name.
	 * @param string $name The state or province.
	 * @param string $address The email address.
	 * @param string $city The locality.
	 * @param string $country The two-letter country code.
	 * @param string $company The organization.
	 * @return bool True when made, false when a certificate folder already exists.
	 */
	static function ExecuteMakeTPssl($domain, $name, $address, $city, $country, $company) {
		global $zdbh;
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		$rootdir = str_replace('.', '_', $domain);
		
		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party") ) {
			mkdir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party", 0777);
		}

		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/") ) {
			mkdir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/", 0777);	
		} else {
			
			self::$error = true;
			return false;
		}
		
		// GET user info
		$dn = array(
					"countryName" => "$country",
					"stateOrProvinceName" => "$name",
					"localityName" => "$city",
					"organizationName" => "$company",
					"commonName" => "$domain",
					"emailAddress" => "$address",
					"subjectAltName" => "DNS: $domain, DNS: www. $domain"
		); 		

		// Make Key
		$privkey = openssl_pkey_new();
			
		// Generate a certificate signing request
		$csr = openssl_csr_new($dn, $privkey);
			
		$config = array("digest_alg" => "sha256", "x509_extensions" => "v3_req");
			
		$sscert = openssl_csr_sign($csr, null, $privkey, 365, $config);
			
		openssl_x509_export_to_file($sscert, ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/cert.pem");
		openssl_pkey_export_to_file($privkey, ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/private.pem");	
		
		if ( $domain == ctrl_options::GetSystemOption('sentora_domain') ) {
					
			$line = "# Made from Sencrypt - third_party - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();			
			$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/private.pem" . fs_filehandler::NewLine();
			$line .= "# Made from Sencrypt - third_party - end" . fs_filehandler::NewLine();					

			$portname = "sentora_port";
			$port = "443";
				
			if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
				// For older Sentora support
				$name = 'global_zpcustom';
				$sql = $zdbh->prepare("SELECT * FROM x_settings WHERE so_name_vc  = :name");
				$sql->bindParam(':name', $name);
				$sql->execute();
						
				while ($row = $sql->fetch()) { 
					$olddata = $row['so_value_tx']; 
					
				}
					$data = $olddata. $line;
								
					// Update data
					$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
					$updatesql->bindParam(':value', $data);
					$updatesql->bindParam(':name', $name);
					$updatesql->execute();
								
					// Update port
					//$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
					//$updatesql->bindParam(':value', $port);
					//$updatesql->bindParam(':name', $portname);
					//$updatesql->execute();
								
			} else {
				// For Sentora 2.0
					$name = 'panel_ssl_tx';
					
					// Update data
					$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
					$updatesql->bindParam(':value', $line);
					$updatesql->bindParam(':name', $name);
					$updatesql->execute();
								
					// Update port
					//$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
					//$updatesql->bindParam(':value', $port);
					//$updatesql->bindParam(':name', $portname);
					//$updatesql->execute();
						
			}

		} else {
					
			$line = "# Made from Sencrypt - third_party - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();	
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();
			$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/private.pem" . fs_filehandler::NewLine();
			$line .= "# Made from Sencrypt - third_party - end" . fs_filehandler::NewLine();
			
			$port = "443";
	
			if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
				// For older Sentora support
				$portforward 	= "1";								

				$sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc = :domain AND vh_deleted_ts IS NULL");
				$sql->bindParam(':domain', $domain);
				$sql->execute();
							
				while ($row = $sql->fetch()) { 
					$olddata = $row['vh_custom_tx']; 
				
				}
					$data = $olddata. $line;
					
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx=:data, vh_custom_port_in=:port WHERE vh_name_vc = :domain");
					//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx=:data, vh_custom_port_in=:port, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $data);
					$sql->bindParam(':domain', $domain);
					$sql->bindParam(':port', $port);
					$sql->bindParam(':portforward', $portforward);
					$sql->execute();
	
			} else {
					// For Sentora 2.0
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx=:data, vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $line);
					$sql->bindParam(':domain', $domain);
					$sql->bindParam(':port', $port);
					$sql->execute();
		
			}	
		}

			
		self::SetWriteApacheConfigTrue();
		self::$okselfsigned = true;	
		return true;	
				
	}

	/**
	 * List the domains (and panel domain) of an account that have no certificate yet.
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @global runtime_controller $controller The request controller (unused).
	 * @param int $uid The account ID.
	 * @return array<int, array<string, string>>|false One row per domain: 'domain'; false when the account has no domains.
	 */
	static function ListDomains($uid) {
        global $zdbh;
		global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_enabled_in=1 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc ASC";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $currentuser['userid']);
            $res = array();
            $sql->execute();

			// Panel values
			$panel_Domain = ctrl_options::GetSystemOption('sentora_domain');
			
			// Check if ssl exists else where
			if ( !is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" . $panel_Domain . "/") ) {
			
				// check if cert exist or not
				if ( is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $panel_Domain . "/") ) {
					// Do nothing

				} else {
					
					$name = $panel_Domain;
					$res[] = array('domain' => "$name");
				}
				
			} else {
				// Do nothing
				
			}
	
            while ($rowdomains = $sql->fetch()) {
				
				//check if cert exist or not
				if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $rowdomains['vh_name_vc'] . "/") ) {

					// Check if ssl exists else where
					if ( is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" . $rowdomains['vh_name_vc'] . "/") ) {
						// Do nothing

					} else {
						$res[] = array('domain' => $rowdomains['vh_name_vc']);
				
					}				
				} else {
					// Do nothing

				}				
            }
            return $res;
			
        } else {
            return false;
        }
	}
		
	/**
	 * Template loop: the logged-in account's domains without a certificate.
	 *
	 * @return array<int, array<string, string>>|false See ListDomains().
	 */
	static function getDomainList() {
			$currentuser = ctrl_users::GetUserDetail();
			return self::ListDomains($currentuser['userid']);
	}
	
	/**
	 * List the account's third-party certificate folders.
	 *
	 * @global runtime_controller $controller The request controller (unused).
	 * @param string $uname The account's username.
	 * @return array<int, array<string, string>>|null One row per folder: 'name'; null when there are none.
	 */
	static function ListSSL($uname) {
		global $controller;
		$retval = null;
		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $uname . "/ssl/sencrypt/third_party/") ) {
			mkdir( ctrl_options::GetSystemOption('hosted_dir'). $uname . "/ssl/sencrypt/third_party/", 0777);
		}
		
		$dir = ctrl_options::GetSystemOption('hosted_dir') . $uname . "/ssl/sencrypt/third_party/";
		if(substr($dir, -1) != "/") $dir .= "/";
			$d = @dir($dir);
			while(false !== ($entry = $d->read())) {
			$entry1 = str_replace('_', '.', $entry);
			if($entry[0] == ".") continue;
				$retval[] = array("name" => "$entry1");
		}
		
		$d->close();
		return $retval;
	}

	/**
	 * Template loop: the logged-in account's third-party certificates.
	 *
	 * @return array<int, array<string, string>>|null See ListSSL().
	 */
	static function getSSLList() {
		$currentuser = ctrl_users::GetUserDetail();
		return self::ListSSL($currentuser['username']);
	}
		
	/**
	 * Template flag: show the CSR form (?show=ShowCSR).
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return bool
	 */
	static function getisShowCSR() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        return (isset($urlvars['show'])) && ($urlvars['show'] == "ShowCSR");
	}
	
	/**
	 * Template flag: show the self-signed form (?show=ShowSelf).
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return bool
	 */
	static function getisShowSelf() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        return (isset($urlvars['show'])) && ($urlvars['show'] == "ShowSelf");
	}
	
	/**
	 * Template flag: show the upload form for a bought certificate (?show=Bought).
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return bool
	 */
	static function getisBought() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Bought");
	}

	/**
	 * Handle the third-party choice POST: redirect to the chosen form and exit.
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return true True when no choice was sent (otherwise it exits).
	 */
	static function doselect() {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
		
            if (isset($formvars['inSSLself'])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . '&ShowPanel=third-party' . '&show=ShowSelf');
                exit;
            }
			if (isset($formvars['inSSLbought'])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . '&ShowPanel=third-party' . '&show=Bought');
                exit;
            }
			if (isset($formvars['inSSLCSR'])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . '&ShowPanel=third-party' . '&show=ShowCSR');
                exit;
            }
        return true;
	}

	/**
	 * Template HTML: the country select options.
	 *
	 * @return string HTML option tags.
	 */
	static function getListCountry() {
			$res = '<option value="AF">Afghanistan</option> <option value="AX">Åland Islands</option> <option value="AL">Albania</option> <option value="DZ">Algeria</option> <option value="AS">American Samoa</option> <option value="AD">Andorra</option> <option value="AO">Angola</option> <option value="AI">Anguilla</option> <option value="AQ">Antarctica</option> <option value="AG">Antigua and Barbuda</option> <option value="AR">Argentina</option> <option value="AM">Armenia</option> <option value="AW">Aruba</option> <option value="AU">Australia</option> <option value="AT">Austria</option> <option value="AZ">Azerbaijan</option> <option value="BS">Bahamas</option> <option value="BH">Bahrain</option> <option value="BD">Bangladesh</option> <option value="BB">Barbados</option> <option value="BY">Belarus</option> <option value="BE">Belgium</option> <option value="BZ">Belize</option> <option value="BJ">Benin</option> <option value="BM">Bermuda</option> <option value="BT">Bhutan</option> <option value="BO">Bolivia</option> <option value="BA">Bosnia and Herzegovina</option> <option value="BW">Botswana</option> <option value="BV">Bouvet Island</option> <option value="BR">Brazil</option> <option value="IO">British Indian Ocean Territory</option> <option value="BN">Brunei Darussalam</option> <option value="BG">Bulgaria</option> <option value="BF">Burkina Faso</option> <option value="BI">Burundi</option> <option value="KH">Cambodia</option> <option value="CM">Cameroon</option> <option value="CA">Canada</option> <option value="CV">Cape Verde</option> <option value="KY">Cayman Islands</option> <option value="CF">Central African Republic</option> <option value="TD">Chad</option> <option value="CL">Chile</option> <option value="CN">China</option> <option value="CX">Christmas Island</option> <option value="CC">Cocos (Keeling) Islands</option> <option value="CO">Colombia</option> <option value="KM">Comoros</option> <option value="CG">Congo</option> <option value="CD">Congo, The Democratic Republic of The</option> <option value="CK">Cook Islands</option> <option value="CR">Costa Rica</option> <option value="CI">Cote D´ivoire</option> <option value="HR">Croatia</option> <option value="CU">Cuba</option> <option value="CY">Cyprus</option> <option value="CZ">Czech Republic</option> <option value="DK">Denmark</option> <option value="DJ">Djibouti</option> <option value="DM">Dominica</option> <option value="DO">Dominican Republic</option> <option value="EC">Ecuador</option> <option value="EG">Egypt</option> <option value="SV">El Salvador</option> <option value="GQ">Equatorial Guinea</option> <option value="ER">Eritrea</option> <option value="EE">Estonia</option> <option value="ET">Ethiopia</option> <option value="FK">Falkland Islands (Malvinas)</option> <option value="FO">Faroe Islands</option> <option value="FJ">Fiji</option> <option value="FI">Finland</option> <option value="FR">France</option> <option value="GF">French Guiana</option> <option value="PF">French Polynesia</option> <option value="TF">French Southern Territories</option> <option value="GA">Gabon</option> <option value="GM">Gambia</option> <option value="GE">Georgia</option> <option value="DE">Germany</option> <option value="GH">Ghana</option> <option value="GI">Gibraltar</option> <option value="GR">Greece</option> <option value="GL">Greenland</option> <option value="GD">Grenada</option> <option value="GP">Guadeloupe</option> <option value="GU">Guam</option> <option value="GT">Guatemala</option> <option value="GG">Guernsey</option> <option value="GN">Guinea</option> <option value="GW">Guinea-bissau</option> <option value="GY">Guyana</option> <option value="HT">Haiti</option> <option value="HM">Heard Island and Mcdonald Islands</option> <option value="VA">Holy See (Vatican City State)</option> <option value="HN">Honduras</option> <option value="HK">Hong Kong</option> <option value="HU">Hungary</option> <option value="IS">Iceland</option> <option value="IN">India</option> <option value="ID">Indonesia</option> <option value="IR">Iran, Islamic Republic of</option> <option value="IQ">Iraq</option> <option value="IE">Ireland</option> <option value="IM">Isle of Man</option> <option value="IL">Israel</option> <option value="IT">Italy</option> <option value="JM">Jamaica</option> <option value="JP">Japan</option> <option value="JE">Jersey</option> <option value="JO">Jordan</option> <option value="KZ">Kazakhstan</option> <option value="KE">Kenya</option> <option value="KI">Kiribati</option> <option value="KP">Korea, Democratic People´s Republic of</option> <option value="KR">Korea, Republic of</option> <option value="KW">Kuwait</option> <option value="KG">Kyrgyzstan</option> <option value="LA">Lao People´s Democratic Republic</option> <option value="LV">Latvia</option> <option value="LB">Lebanon</option> <option value="LS">Lesotho</option> <option value="LR">Liberia</option> <option value="LY">Libyan Arab Jamahiriya</option> <option value="LI">Liechtenstein</option> <option value="LT">Lithuania</option> <option value="LU">Luxembourg</option> <option value="MO">Macao</option> <option value="MK">Macedonia, The Former Yugoslav Republic of</option> <option value="MG">Madagascar</option> <option value="MW">Malawi</option> <option value="MY">Malaysia</option> <option value="MV">Maldives</option> <option value="ML">Mali</option> <option value="MT">Malta</option> <option value="MH">Marshall Islands</option> <option value="MQ">Martinique</option> <option value="MR">Mauritania</option> <option value="MU">Mauritius</option> <option value="YT">Mayotte</option> <option value="MX">Mexico</option> <option value="FM">Micronesia, Federated States of</option> <option value="MD">Moldova, Republic of</option> <option value="MC">Monaco</option> <option value="MN">Mongolia</option> <option value="ME">Montenegro</option> <option value="MS">Montserrat</option> <option value="MA">Morocco</option> <option value="MZ">Mozambique</option> <option value="MM">Myanmar</option> <option value="NA">Namibia</option> <option value="NR">Nauru</option> <option value="NP">Nepal</option> <option value="NL">Netherlands</option> <option value="AN">Netherlands Antilles</option> <option value="NC">New Caledonia</option> <option value="NZ">New Zealand</option> <option value="NI">Nicaragua</option> <option value="NE">Niger</option> <option value="NG">Nigeria</option> <option value="NU">Niue</option> <option value="NF">Norfolk Island</option> <option value="MP">Northern Mariana Islands</option> <option value="NO">Norway</option> <option value="OM">Oman</option> <option value="PK">Pakistan</option> <option value="PW">Palau</option> <option value="PS">Palestinian Territory, Occupied</option> <option value="PA">Panama</option> <option value="PG">Papua New Guinea</option> <option value="PY">Paraguay</option> <option value="PE">Peru</option> <option value="PH">Philippines</option> <option value="PN">Pitcairn</option> <option value="PL">Poland</option> <option value="PT">Portugal</option> <option value="PR">Puerto Rico</option> <option value="QA">Qatar</option> <option value="RE">Reunion</option> <option value="RO">Romania</option> <option value="RU">Russian Federation</option> <option value="RW">Rwanda</option> <option value="SH">Saint Helena</option> <option value="KN">Saint Kitts and Nevis</option> <option value="LC">Saint Lucia</option> <option value="PM">Saint Pierre and Miquelon</option> <option value="VC">Saint Vincent and The Grenadines</option> <option value="WS">Samoa</option> <option value="SM">San Marino</option> <option value="ST">Sao Tome and Principe</option> <option value="SA">Saudi Arabia</option> <option value="SN">Senegal</option> <option value="RS">Serbia</option> <option value="SC">Seychelles</option> <option value="SL">Sierra Leone</option> <option value="SG">Singapore</option> <option value="SK">Slovakia</option> <option value="SI">Slovenia</option> <option value="SB">Solomon Islands</option> <option value="SO">Somalia</option> <option value="ZA">South Africa</option> <option value="GS">South Georgia and The South Sandwich Islands</option> <option value="ES">Spain</option> <option value="LK">Sri Lanka</option> <option value="SD">Sudan</option> <option value="SR">Suriname</option> <option value="SJ">Svalbard and Jan Mayen</option> <option value="SZ">Swaziland</option> <option value="SE">Sweden</option> <option value="CH">Switzerland</option> <option value="SY">Syrian Arab Republic</option> <option value="TW">Taiwan, Province of China</option> <option value="TJ">Tajikistan</option> <option value="TZ">Tanzania, United Republic of</option> <option value="TH">Thailand</option> <option value="TL">Timor-leste</option> <option value="TG">Togo</option> <option value="TK">Tokelau</option> <option value="TO">Tonga</option> <option value="TT">Trinidad and Tobago</option> <option value="TN">Tunisia</option> <option value="TR">Turkey</option> <option value="TM">Turkmenistan</option> <option value="TC">Turks and Caicos Islands</option> <option value="TV">Tuvalu</option> <option value="UG">Uganda</option> <option value="UA">Ukraine</option> <option value="AE">United Arab Emirates</option> <option value="GB">United Kingdom</option> <option value="US">United States</option> <option value="UM">United States Minor Outlying Islands</option> <option value="UY">Uruguay</option> <option value="UZ">Uzbekistan</option> <option value="VU">Vanuatu</option> <option value="VE">Venezuela</option> <option value="VN">Viet Nam</option> <option value="VG">Virgin Islands, British</option> <option value="VI">Virgin Islands, U.S.</option> <option value="WF">Wallis and Futuna</option> <option value="EH">Western Sahara</option> <option value="YE">Yemen</option> <option value="ZM">Zambia</option> <option value="ZW">Zimbabwe</option>';
			return $res;
	}

// Third_Party code - END

// LETS Encrypt code - START
// Client
	/**
	 * Handle the Let's Encrypt POST for one of the account's domains.
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return bool|null False on a failed check, true when made, else null.
	 */
	static function doMakeSSL() {
		global $controller;
		
		$sub_module = "letsencrypt";
		
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		// Only this account's own domains (the name comes from the form, and is also
		// used in file paths, so a "../" or another account's domain must never get through)
		if (!self::OwnsDomainName(isset($formvars['inDomain']) ? $formvars['inDomain'] : '')) {
			return false;
		}

		if (self::ExecuteMakeSSL($formvars['inDomain'], $currentuser["username"], $sub_module))
		return true;
	}
	
// Panel		
	/**
	 * Handle the Let's Encrypt POST for the panel domain (administrators only).
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return bool|null False on a failed check, true when made, else null.
	 */
	static function doMakePanelSSL() {
		global $controller;
		
		$sub_module = "letsencrypt";
		
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		// The panel's own certificate: administrators only, for the panel domain only
		$sentoraUser = ctrl_users::GetUserDetail();
		if ((int) $sentoraUser['usergroupid'] !== 1 || !isset($formvars['inDomain']) || (string) $formvars['inDomain'] !== (string) ctrl_options::GetSystemOption('sentora_domain')) {
			return false;
		}
		if (self::ExecuteMakePanelSSL($formvars['inDomain'], $currentuser["username"], $sub_module))
		return true;
	}

// make client	
	/**
	 * Get a Let's Encrypt certificate for a domain of the logged-in account and configure its vhost.
	 *
	 * Looks up the server's country and region at ip-api.com for the CSR and checks DNS first.
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @global runtime_controller $controller The request controller (unused).
	 * @param string $domain The domain name.
	 * @param string $username The account's username (replaced by the logged-in account's).
	 * @param string $sub_module The certificate kind ('letsencrypt').
	 * @return bool True when made, false when DNS is not live or Let's Encrypt failed.
	 */
	static function ExecuteMakeSSL($domain, $username, $sub_module) {
		global $zdbh, $controller;

		$zsudo = ctrl_options::GetOption('zsudo');
		$currentuser = ctrl_users::GetUserDetail();
		$username = $currentuser["username"];
		$userid = $currentuser["userid"];
		$accountDir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/";
		$certlocation = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/" . $domain;
		// NEW CODE - tg - get hosted folder from DB (vh_directory_vc) so that challenge is in propper location
		$sql = $zdbh->prepare("SELECT vh_directory_vc FROM x_vhosts WHERE vh_name_vc = :domain");
			$sql->bindParam(':domain', $domain);
			$sql->execute();
		$domain_folder=$sql->fetchColumn();
		//$domain_folder = str_replace(".","_", $domain);
		$domainRoot = ctrl_options::GetSystemOption('hosted_dir') . $username . "/public_html/" . $domain_folder;

		require("modules/sencrypt/code/Lescript.php");
		date_default_timezone_set("UTC");
		
		// Set country/state- tg & Jettaman	
		$user_ip = ctrl_options::GetSystemOption('server_ip');
		$ip_response = file_get_contents('http://ip-api.com/json/'.$user_ip);
		$ip_array = json_decode($ip_response);
		$countryCode = $ip_array->countryCode; 
		$state = $ip_array->regionName;
		$ipStatus = $ip_array->status;
		$querydata = $ip_array->query;
	
		// Check DNS before continuing
		if (self::checkDNSIsLive($domain, $ipStatus, $querydata, $user_ip) == false) {
			self::$dnsInvalid = true;
			return false;
		}
		
		// Make Let´s encrypt SSL
		$logger = new Logger();
	
		try {

			$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $domainRoot, $logger, $countryCode, $state);
			
			// uses client's email used during registration
			$le->contact = array('mailto:'. $currentuser['email']); // optional
			
			// Init. Account and update account email to keep current.
			$le->initAccount();
			$le->postUpdateRegEmail();
			
			// Start signing here
			
			// NEW CODE - start - tg
			// Check if domain has a shared hostdata dir
			$sql = "SELECT COUNT(*) FROM x_vhosts WHERE vh_acc_fk = :userid AND vh_directory_vc = :dom_directory";
				$query = $zdbh->prepare($sql);
				$query->bindParam(':userid', $currentuser['userid']);
				$query->bindParam(':dom_directory', $domain_folder);
				$query->execute();
			$count = $query->fetchColumn();
			// NEW CODE - end - tg
	
			// Check if domain is a subdomain
			$sql = "SELECT vh_type_in FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_name_vc=:domain AND vh_enabled_in=1 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc ASC";
				$query = $zdbh->prepare($sql);
				$query->bindParam(':userid', $currentuser['userid']);
				$query->bindParam(':domain', $domain);
				$query->execute();
		
			while ($row = $query->fetch()) {

				if (($row['vh_type_in'] == 2 ) || ($count != 1 )) {
					// Create domain without www. because its a subdomain
					$le->signDomains(array($domain));

				} else {

					// Create a SSL for domain and with www. because its a root domain
					$le->signDomains(array($domain, 'www.'. $domain));
				}
			}			
		}
	
		catch (\Exception $e) {
			$errorCatahed = $e->getMessage();
			$logger->error($e->getTraceAsString());
			$logger->error($e->getMessage());
			// Throw error and log to file
			error_log( date('Y-m-d H:i:s') . " - DOMAIN: " . $domain . fs_filehandler::NewLine() . $errorCatahed . fs_filehandler::NewLine(), 3, ctrl_options::GetSystemOption('sentora_root') . 'modules/sencrypt/sencrypt.log');
			self::$certFailed = true;
			return false;
			exit(1);
		}

			$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();			
			$line .= "SSLCertificateFile " . $certlocation . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . $certlocation . "/private.pem" . fs_filehandler::NewLine();
			$line .= "SSLCACertificateFile " . $certlocation . "/chain.pem" . fs_filehandler::NewLine();
			$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();

			$port 			= 443;
			$portforward 	= 1;

			$sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc = :domain AND vh_deleted_ts IS NULL");
			$sql->bindParam(':domain', $domain);
			$sql->execute();
			
			while ($row = $sql->fetch()) {
				$olddata = $row['vh_custom_tx'];
			}
				$data = $olddata. $line;
				
				if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
					// For older Sentora support
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx=:data, vh_custom_port_in=:port WHERE vh_name_vc = :domain");
					//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx=:data, vh_custom_port_in=:port, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $data);
					$sql->bindParam(':port', $port);
					$sql->bindParam(':portforward', $portforward);
					$sql->bindParam(':domain', $domain);
					$sql->execute();	
					
				} else {
					// For Sentora 2.0
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx=:data, vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
					//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx=:data, vh_ssl_port_in=:port, vh_portforward_in=:portforward, vh_custom_port_in=:customport WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $data);
					$sql->bindParam(':port', $port);
					// added portforward & custom port for forwarding- tg
					//$sql->bindParam(':portforward', $portforward);
					//$sql->bindParam(':customport', $port);
					$sql->bindParam(':domain', $domain);
					$sql->execute();			
					
				}

				self::SetWriteApacheConfigTrue();
				self::$okletsencrypt = true;
				return true;
	}
	
	// Make SSL for Panel domain
	/**
	 * Get a Let's Encrypt certificate for the panel domain and switch the panel to port 443.
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @global runtime_controller $controller The request controller (unused).
	 * @param string $domain The panel domain.
	 * @param string $username The account's username (replaced by the logged-in account's).
	 * @param string $sub_module The certificate kind ('letsencrypt').
	 * @return bool True when made, false when DNS is not live or Let's Encrypt failed.
	 */
	static function ExecuteMakePanelSSL($domain, $username, $sub_module) {
		global $zdbh, $controller;
		$zsudo = ctrl_options::GetOption('zsudo');
		$currentuser = ctrl_users::GetUserDetail();
		$username = $currentuser["username"];
		$userid = $currentuser["userid"];
					
		$domainRoot = ctrl_options::GetSystemOption('sentora_root');
		$panelDomain = ctrl_options::GetSystemOption('sentora_domain');
		$accountDir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/";
		$certlocation = $accountDir . $panelDomain . "/";
		
		// Other panel subomains to include
		//$otherSubDomains = "ftp, webmail, mail, smtp, imap, pop";

		require("modules/sencrypt/code/Lescript.php");
		
		
		// Set country/state - tg & Jettaman
		$user_ip = ctrl_options::GetSystemOption('server_ip');
		$ip_response = file_get_contents('http://ip-api.com/json/'.$user_ip);
		$ip_array = json_decode($ip_response);
		$countryCode = $ip_array->countryCode; 
		$state = $ip_array->regionName;
		$ipStatus = $ip_array->status;
		$querydata = $ip_array->query;
		
		// Check DNS before continuing
		if (self::checkDNSIsLive($domain, $ipStatus, $querydata, $user_ip) == false) {
			self::$dnsInvalid = true;
			return false;
		}
		
		// Make Let´s encrypt SSL
		$logger = new Logger();

		try {
			$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $domainRoot, $logger, $countryCode, $state);
			
			// uses client's email used during registration
			$le->contact = array('mailto:' . $currentuser['email']); // optional
		
			// Init. Account and update account email to keep current.
			$le->initAccount();
			$le->postUpdateRegEmail();
			
			
			// Create panel SSL if no other sub domains are listed
			//if ($otherSubDomain == NULL ) {
			
				// Sign Panel domain 
				$le->signDomains(array($domain));
			


			// BETA CODE TO SEE IF WE CAN CONTROL DOMAIN SUB ATL CERTS (FTP, POP, SMTP, IMAP) in works!! Might not happen! Moving to Certbot for more control.
			/*
			} else {
			
				# Checks if domain is sub or root by checking how many dots(.) there are. Returns dot(.) count
				if (substr_count($domain, '.') == 2 ) {
					# If panel domain is a sub domain
					$removeNeedle = ".";
					$pos = strpos($domain, $removeNeedle);
					$rootDomain = substr($domain, $pos . ".");
					
				} else {
					# If panel domain is a root domain
					$rootDomain = "." . $domain;
				
				}
				
				# If other panel subdomains are listed include them in SSL cert
				if ($otherSubDomains != NULL) {
				 
					$str_arr = preg_split ("/\,/", $otherSubDomains);
					
					# Set subdomain array
					$subList = array("");
					
					# Push each subdomain domain into an array for SSL
					foreach ($str_arr as $value) {
						array_push($subList, ', ' . $value.$rootDomain);
					}
					
					# Put list into string format for letsencrypt
					$subFinalList = implode($subList);
					
				}
				
				# Sign panel domain plus other subdomain
				$le->signDomains(array($domain . $subFinalList));
				
			}
			*/
			
		}
		catch (\Exception $e) {
			$errorCatahed = $e->getMessage();
			$logger->error($e->getTraceAsString());
			$logger->error($e->getMessage());
			// Throw error and log to file
			error_log( date('Y-m-d H:i:s') . " - PANEL DOMAIN: " . $domain . fs_filehandler::NewLine() . $errorCatahed . fs_filehandler::NewLine(), 3, ctrl_options::GetSystemOption('sentora_root') . 'modules/sencrypt/sencrypt.log');
			self::$certFailed = true;
			return false;
			exit(1);
		}

			$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();			
			$line .= "SSLCertificateFile " . $certlocation . "cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . $certlocation . "private.pem" . fs_filehandler::NewLine();
			$line .= "SSLCACertificateFile " . $certlocation . "chain.pem" . fs_filehandler::NewLine();
			$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();

			$port = 443;
			
			// new code down below
			// Update panel Port
			//$portname = "sentora_port";
			//$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
			//$updatesql->bindParam(':value', $port);
			//$updatesql->bindParam(':name', $portname);
			//$updatesql->execute();

			// MAY HAVE ISSUE HERE.
// NEW CODE
			if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
				
				// Update panel Port
				$portname = "sentora_port";
				$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
				$updatesql->bindParam(':value', $port);
				$updatesql->bindParam(':name', $portname);
				$updatesql->execute();
				
				// For older Sentora support
				// Update panel SSL data
				$panel_so_name = "global_zpcustom";
				$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :data WHERE so_name_vc = :panel_so_name");
				$sql->bindParam(':data', $line);
				$sql->bindParam(':panel_so_name', $panel_so_name);
				
			} else {
				// For Sentora 2.0
				// Update panel Port
				$portname = "sentora_port";
				$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
				$updatesql->bindParam(':value', $port);
				$updatesql->bindParam(':name', $portname);
				$updatesql->execute();

				// update panel ssl data
				$panel_so_name = "panel_ssl_tx";
				$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :data WHERE so_name_vc = :panel_so_name");
				$sql->bindParam(':data', $line);
				$sql->bindParam(':panel_so_name', $panel_so_name);
				
			}
// NEW CODE

		$sql->bindParam(':data', $line);
		$sql->execute();
			
		self::SetWriteApacheConfigTrue();
		self::$okletsencrypt = true;
		return true;
	}

	/**
	 * Handle the Let's Encrypt certificate delete POST.
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return bool|null False when the domain is not the account's own, true when deleted, else null.
	 */
	static function doDelete() {
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		// Only this account's own domains (the name comes from the form, and is also
		// used in file paths, so a "../" or another account's domain must never get through)
		if (!self::OwnsDomainName(isset($formvars['inDomain']) ? $formvars['inDomain'] : '')) {
			return false;
		}
		
		$sub_module = "letsencrypt";
		
		if (self::ExecuteTPDelete($formvars['inDomain'], $currentuser["username"], $sub_module))
		return true;
	}
/*
	static function ExecuteDelete($domain, $username) {
		global $zdbh;
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$rootdir = str_replace('.', '_', $domain);
		$dir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/" . $domain;
		$objects = scandir($dir);
		
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				 unlink($dir."/" . $object);
			}
		}
	
		rmdir($dir);

		$port = NULL;
		$new = NULL;

		$line = "# Lets Encrypt start" . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();
		$line .= 'SSLEngine On' . fs_filehandler::NewLine();
		$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/letsencrypt/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
		$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/letsencrypt/" . $domain . "/private.pem" . fs_filehandler::NewLine();
		$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/letsencrypt/" . $domain . "/chain.pem" . fs_filehandler::NewLine();
		$line .= "SSLProtocol All -SSLv2 -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
		$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
		$line .= "SSLCipherSuite \"EECDH+ECDSA+AESGCM EECDH+aRSA+AESGCM EECDH+ECDSA+SHA384 EECDH+ECDSA+SHA256 EECDH+aRSA+SHA384 EECDH+aRSA+SHA256 EECDH+AESGCM EECDH EDH+AESGCM EDH+aRSA HIGH !MEDIUM !LOW !aNULL !eNULL !LOW !RC4 !MD5 !EXP !PSK !SRP !DSS\"" . fs_filehandler::NewLine();
		$line .= "# Lets Encrypt end" . fs_filehandler::NewLine();

		//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx = replace(vh_ssl_tx, :data, :new), vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
		
		# NEW CODE
		if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
			# For older Sentora support
			//$portforward 	= 1;
			
			$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx = replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port WHERE vh_name_vc = :domain");
			//$sql->bindParam(':portforward', $portforward);
			
		} else {
			# For Sentora 2.0
			$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx = replace(vh_ssl_tx, :data, :new), vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
			
		}
		# NEW CODE

		$sql->bindParam(':data', $line);
		$sql->bindParam(':new', $new);
		$sql->bindParam(':domain', $domain);
		$sql->bindParam(':port', $port);
		$sql->execute();
		self::SetWriteApacheConfigTrue();
		self::$delok = true;
		return true;
	}
*/
	// Delete Panel SSL		
	// do we need to pass panel domain since panel only uses one domain?
	/**
	 * Handle the panel certificate delete POST (administrators only).
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return bool|null False on a failed check, true when deleted, else null.
	 */
	static function doDeletePanelSSL() {
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');			
		// The panel's own certificate: administrators only, for the panel domain only
		$sentoraUser = ctrl_users::GetUserDetail();
		if ((int) $sentoraUser['usergroupid'] !== 1 || !isset($formvars['inDomain']) || (string) $formvars['inDomain'] !== (string) ctrl_options::GetSystemOption('sentora_domain')) {
			return false;
		}
		//if (self::ExecuteDeletePanelSSL($formvars['inDomain'], $currentuser["username"]))
		if (self::ExecuteDeletePanelSSL(ctrl_options::GetSystemOption('sentora_domain'), $currentuser["username"]))
		return true;
	}
	
	// do we need to pass panel domain since panel only uses one domain?
	/**
	 * Delete the panel's Let's Encrypt certificate and switch the panel back to port 80.
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @global runtime_controller $controller The request controller (unused).
	 * @param string $panelDomain The panel domain.
	 * @param string $username The account's username.
	 * @return true Always true.
	 */
	static function ExecuteDeletePanelSSL($panelDomain, $username) {
		global $zdbh;
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$certlocation = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/";
		
		$sub_module = "letsencrypt";
		
		$dir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/" . $panelDomain;
		
		// delete cert files
		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				 unlink($dir."/" . $object);
			}
		}
	
		rmdir($dir);

		$new = NULL;

		$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();
		$line .= 'SSLEngine On' . fs_filehandler::NewLine();
		$line .= "SSLProtocol All -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
		$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
		$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();		
		$line .= "SSLCertificateFile " . $certlocation . $panelDomain . "/cert.pem" . fs_filehandler::NewLine();
		$line .= "SSLCertificateKeyFile " . $certlocation . $panelDomain . "/private.pem" . fs_filehandler::NewLine();
		$line .= "SSLCACertificateFile " . $certlocation . $panelDomain . "/chain.pem" . fs_filehandler::NewLine();
		$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();

// NEW CODE
		
		if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
			// For older Sentora support
			$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = replace(so_value_tx, :data, :new) WHERE so_name_vc = :panel_ssl");
			$panelssltxt = "global_zpcustom";
			$sql->bindParam(':panel_ssl', $panelssltxt);
			
		} else {
			// For Sentora 2.0
			$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = replace(so_value_tx, :data, :new) WHERE so_name_vc = :panel_ssl");
			$panelssltxt = "panel_ssl_tx";
			$sql->bindParam(':panel_ssl', $panelssltxt);
		}

// NEW CODE

		$sql->bindParam(':data', $line);
		$sql->bindParam(':new', $new);
		$sql->execute();
	
		// change panel port back to 80
		$sentora_port = 80;
		$so_name_vc = "sentora_port";
		
		$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx=:sentora_port WHERE so_name_vc = :so_name_vc");
		$panelssltxt = "panel_ssl_tx";
		$sql->bindParam(':sentora_port', $sentora_port);
		$sql->bindParam(':so_name_vc', $so_name_vc);
		$sql->execute();
		
		self::SetWriteApacheConfigTrue();
		self::$delok = true;
		return true;
	}	

	/**
	 * Handle the Let's Encrypt revoke POST.
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return bool|null False when the domain is not the account's own, true when revoked, else null.
	 */
	static function doRevokeSSL() {
		global $controller;
		$sub_module = "letsencrypt";
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		// Only this account's own domains (the name comes from the form, and is also
		// used in file paths, so a "../" or another account's domain must never get through)
		if (!self::OwnsDomainName(isset($formvars['inDomain']) ? $formvars['inDomain'] : '')) {
			return false;
		}
		
		if (self::ExecuteRevokeSSL($formvars['inDomain'], $currentuser["username"], $sub_module))
		return true;
	}

	/**
	 * Revoke a domain's Let's Encrypt certificate and remove its SSL config from the vhost.
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @global runtime_controller $controller The request controller (unused).
	 * @param string $domain The domain name.
	 * @param string $username The account's username (replaced by the logged-in account's).
	 * @param string $sub_module The certificate kind ('letsencrypt').
	 * @return true True when done; exits the request on an ACME error.
	 */
	static function ExecuteRevokeSSL($domain, $username, $sub_module) {
		global $zdbh, $controller;
		$zsudo = ctrl_options::GetOption('zsudo');
		$currentuser = ctrl_users::GetUserDetail();
		$username = $currentuser["username"];
		$userid = $currentuser["userid"];
		$accountDir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/";
		$certlocation = $accountDir . $domain;
		$domainRoot = ctrl_options::GetSystemOption('hosted_dir') . $username . "/public_html/" . $domain;

		// Convert PEM cert to DER format base64url for revoke
		// tg
		$pem_data = file_get_contents($certlocation . $domain . "/cert.pem");
		$pem2der = self::base64url(self::pem2der($pem_data));
		

		require("modules/sencrypt/code/Lescript.php");
		date_default_timezone_set("UTC");
		
		$logger = new Logger();
		
		// Revoke Let´s encrypt SSL
		try {
			$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $domainRoot, $logger);
			
			// uses client's email used during registration
			//$le->contact = array('mailto:' . $currentuser['email']); // optional
		
			$le->initAccount();
							
			// start revoke
			$le->postRevoke($pem2der);
		}
		
		catch (\Exception $e) {
			$logger->error($e->getMessage());
			$logger->error($e->getTraceAsString());
			exit(1);
		}
		
		// Delete Letsencrypt Cert from DB & System
		
			$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();
			$line .= "SSLCertificateFile " . $certlocation . $domain . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . $certlocation . $domain . "/private.pem" . fs_filehandler::NewLine();
			$line .= "SSLCACertificateFile " . $certlocation . $domain . "/chain.pem" . fs_filehandler::NewLine();
			$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();

			$port 			= NULL;
			$portforward 	= NULL;
			$new = '';

			$sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc = :domain AND vh_deleted_ts IS NULL");
			$sql->bindParam(':domain', $domain);
			$sql->execute();
			
			while ($row = $sql->fetch()) {
				$olddata = $row['vh_custom_tx'];
			}
			
				$data = $olddata. $line;
				
				//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx=:data, vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
			
				// NEW CODE
				if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
					// For older Sentora support						
					//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx=:data, vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
					
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx= replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port WHERE vh_name_vc = :domain");
					//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx= replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $line);
					$sql->bindParam(':new', $new);
					$sql->bindParam(':port', $port);
					$sql->bindParam(':domain', $domain);
					$sql->bindParam(':portforward', $portforward);
					$sql->execute();	
					
				} else {
					// For Sentora 2.0
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx= (vh_ssl_tx, :data, :new), vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $data);
					$sql->bindParam(':new', $new);
					$sql->bindParam(':port', $port);
					$sql->bindParam(':domain', $domain);
					$sql->execute();
					
				}
				// NEW CODE END
				

				//$sql->bindParam(':data', $data);
				//$sql->bindParam(':domain', $domain);
				//$sql->bindParam(':port', $port);
				//$sql->bindParam(':portforward', $portforward);
				//$sql->execute();			

			self::SetWriteApacheConfigTrue();
			self::$revokecert = true;
			return true;
	}

	/**
	 * Convert a PEM certificate to DER bytes.
	 *
	 * @param string $pem The PEM text.
	 * @return string|false The DER bytes, or false on invalid base64.
	 */
	static function pem2der($pem) {
		return base64_decode(implode('',array_slice(
			array_map('trim',explode("\n",trim($pem))),1,-1
		)));
	}

	/**
	 * Encode bytes as base64url without padding (RFC 7515 appendix C).
	 *
	 * @param string $data The bytes.
	 * @return string
	 */
	static function base64url($data){ // RFC7515 - Appendix C
		return rtrim(strtr(base64_encode($data),'+/','-_'),'=');
	}
	
// LETS Encrypt code - END

	/**
	 * Flag the Apache config for rewriting by the daemon (apache_changed option).
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @return void
	 */
	static function SetWriteApacheConfigTrue() {
		global $zdbh;
		$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx='true'	WHERE so_name_vc='apache_changed'");
		$sql->execute();
	}

    //static function getPortWarning() {
		
		//return '<font face="ariel" size="4">' . ui_language::translate("ZADMIN ALERT: Make sure port 443 is OPEN before adding any SSL certificates!") . '</font>';
    //}
	
	/**
	 * Check that a domain has an A record and that ip-api.com saw this server's IP.
	 *
	 * @param string $domain The domain name.
	 * @param string $ipStatus The ip-api.com 'status' value.
	 * @param string $querydata The ip-api.com 'query' value (the IP it looked up).
	 * @param string $server_ip The server's IP (server_ip option).
	 * @return bool|null True when live, false without an A record, null when the IP check fails.
	 */
	static function checkDNSIsLive ($domain, $ipStatus, $querydata, $server_ip) { 
		// Check DNS for domain is live and public before continuing
		 
		 // Pull IP from sentora
		 ctrl_options::GetSystemOption('server_ip');
		
		if(checkdnsrr($domain,"A")) {
			
			if (($ipStatus == "success") && ($querydata == $server_ip)) {
				return true;		
			}
		} else {
			return false;
		}
	}
	

	/**
	 * Template HTML: the error or success message of the last action.
	 *
	 * @return string|null HTML message, or null when there is nothing to show.
	 */
	static function getResult() {
		if (self::$modReqsError)
		{
			return ui_sysmessage::shout(ui_language::translate("You need at least PHP 5.3.0+ with OpenSSL and curl extension installed. Contact your admin for help. This Module may not work correctly until the issues are fixed."), "zannounceerror");
		}
		if (self::$portReqsError)
		{
			return ui_sysmessage::shout(ui_language::translate("ALERT: Port (443) appears to be CLOSED. Sencrypt will not work until port (443) is OPEN. Contact your Administator."), "zannounceerror");
		}
		if (self::$okletsencrypt)
		{
			return ui_sysmessage::shout(ui_language::translate("Your FREE Letsencrypt SSL Certificate has been Created. It will be active in about 5 minutes."), "zannounceok");
		}
		if (self::$okselfsigned)
		{
			return ui_sysmessage::shout(ui_language::translate("Your Self-Signed SSL Certificate has been Created. It will be active in about 5 minutes."), "zannounceok");
		}
		if (self::$okthirdparty)
		{
			return ui_sysmessage::shout(ui_language::translate("Your Third-Party SSL Certificate has been uploaded. It will be active in about 5 minutes."), "zannounceok");
		}
		if (self::$delok)
		{
			return ui_sysmessage::shout(ui_language::translate("The selected certificate has been deleted."), "zannounceok");
		}
		if (self::$error)
		{
			return ui_sysmessage::shout(ui_language::translate("A certificate with that name already exists."), "zannounceerror");
		}
		if (self::$dnsInvalid)
		{
			return ui_sysmessage::shout(ui_language::translate("Your DNS for this domain is not LIVE or POINTING to this server yet. Please check your DNS and retry again later."), "zannounceerror");
		}
		if (self::$revokecert) {
            return ui_sysmessage::shout(ui_language::translate("The Requested Certificate has been revoked"), "zannounceok");
        }
		if (self::$keyadd) {
            return ui_sysmessage::shout(ui_language::translate("Certificate Signing Request was made and sent to the mail you have entered"), "zannounceok");
        }
		if (self::$certFailed) {
            return ui_sysmessage::shout(ui_language::translate("Oops! Something went wrong. Your Lets Encrypt certificate was not created. Check if your website is LIVE."), "zannounceerror");
        }
		return;
	}
}
?>