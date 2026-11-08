<?php
/**
	* Controller for sencrypt module for sentora version 2.0.0
	* Version : 3.0.0
	* Author : TGates
	* Additional work by Diablo925, Jettaman
 *
 * Updated by RusTus, 2026-11-08
 */
 
// Lescript automatic updating script.
//
// This is an example of how Lescript can be used to automatically update
// expiring certificates.
//
// This code is based on FreePBX's LetsEncrypt integration
//
// Copyright (c) 2016 Rob Thomas <rthomas@sangoma.com>
// Licence:  AGPLv3.
//
// In addition, Stanislav Humplik <sh@analogic.cz> is explicitly granted permission
// to relicence this code under the open source licence of their choice.
 

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
$logger = new Logger();

echo fs_filehandler::NewLine() . "START Sencrypt Manager SSL Renewal Hook." . fs_filehandler::NewLine();
if (ui_module::CheckModuleEnabled('Sencrypt SSL')) {
	
    echo "Sencrypt Manager module ENABLED..." . fs_filehandler::NewLine();
	
	if ( ctrl_options::GetSystemOption('panel_ssl_tx') != null && strpos(ctrl_options::GetSystemOption('panel_ssl_tx'), '/ssl/sencrypt/') === false ) {
		// The panel uses its own certificate (the installer's self-signed one, or one
		// you installed by hand), not a Sencrypt one: nothing to renew here
		echo fs_filehandler::NewLine() . "Control Panel uses its own certificate (not from Sencrypt), skipping panel renewal." . fs_filehandler::NewLine();
	} elseif ( ctrl_options::GetSystemOption('panel_ssl_tx') != null) {
		
		echo fs_filehandler::NewLine() . "RENEWING Control Panel certificates..." . fs_filehandler::NewLine();
			// Run renew panel cert function
			renewPanelCertificates();
			
		echo fs_filehandler::NewLine()."RENEWING Control Panel certificates completed." . fs_filehandler::NewLine();
	}
	
	echo fs_filehandler::NewLine() . "RENEWING client certificates..." . fs_filehandler::NewLine();
		// Run renew cert function
		renewCertificates();
		
	echo "RENEWING client certificates completed." . fs_filehandler::NewLine();
	
	// Restart Apache service
	RestartHttpdServicesForSSL();

} else {
	
    echo "Sencrypt Manager module DISABLED...nothing to do." . fs_filehandler::NewLine();
}

echo "END Sencrypt Manager SSL Renewal Hook." . fs_filehandler::NewLine();

// Start functions here
/**
 * Renew the Let's Encrypt certificates of all SSL vhosts with a third of their lifetime left (Lescript::renewalDue()).
 *
 * Skips domains with no address in DNS. A failed renewal is logged
 * (modules/sencrypt/sencrypt.log, with the domain) and the next domain is renewed; the
 * failures are listed at the end.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @global runtime_controller $controller The request controller (unused).
 * @return void
 */
function renewCertificates() {
	global $zdbh, $controller;
	$logger = new Logger();

	$rowvhost = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_active_in = '1' AND vh_ssl_tx IS NOT NULL AND vh_ssl_port_in IS NOT NULL AND vh_enabled_in = '1' AND vh_deleted_ts IS NULL");
	$rowvhost->execute();
	$sslVhosts = $rowvhost->fetchAll();
	$result = "";
	$failed = array();
	
	foreach($sslVhosts as $sslVhost) {
		//if (strpos($sslVhost['vh_ssl_tx'], 'Sencrypt') !== false) {
		if ($sslVhost['vh_ssl_tx'] !== false) {
			
			$vhostOwner = ctrl_users::GetUserDetail($sslVhost['vh_acc_fk']);
			$domainPath = ctrl_options::GetSystemOption('hosted_dir') . $vhostOwner['username'] . "/public_html" . $sslVhost['vh_directory_vc'];
			echo "Checking certificate for Client: " . $vhostOwner['username'] . " / Domain: " . $sslVhost['vh_name_vc'] . fs_filehandler::NewLine();
			//echo "At location: " . $domainPath . fs_filehandler::NewLine(); - For DEBUGGING
			
			// Configuration:
			$domains = $sslVhost['vh_name_vc'];
			$domains = array($domains);
			$domain = $sslVhost['vh_name_vc'];
			$webroot = $domainPath;
			
			$accountDir = ctrl_options::GetSystemOption('hosted_dir') . $vhostOwner['username'] . "/ssl/sencrypt/letsencrypt/";
			// Changed to help with backup and compability
			$certlocation = ctrl_options::GetSystemOption('hosted_dir') . $vhostOwner['username'] . "/ssl/sencrypt/letsencrypt/" . $sslVhost['vh_name_vc'] . "/";
			
			// Require Lescript for renewal of SSL certs
			require_once 'modules/sencrypt/code/Lescript.php';
			
			// Always use UTC
			date_default_timezone_set("UTC");
			
			// Do we need to create or upgrade our cert? Assume no to start with.
			$needsgen = false;
			
			// country and region for the request, from the server's public address
			list($countryCode, $state) = \Analogic\ACME\Lescript::serverLocation();
			
			// Default if API failed
			//$countryCode = "US";
			//$state = "NM";
			//$ipStatus = "sucess";
			
			// Check if Domain is LIVE and Pointing to this server
			// Check DNS before continuing
			// (the old check compared the panel's IP with ip-api.com's view of it: never true behind a router,
			// so every renewal was skipped there; Let's Encrypt itself says when the name does not reach here)
			if (!\Analogic\ACME\Lescript::hasAddress($domain)) {
				//$dnsInvalid = true;
				//return false;
				echo "   " . $domain . " has no address in DNS. SKIPPING." . fs_filehandler::NewLine();
				
			} else {
				// Do we HAVE a certificate for all our domains?
				foreach ($domains as $d) {
					$certfile = "$certlocation/cert.pem";
					if (!file_exists($certfile)) {
						// We don't have a cert, so we need to request one.
						$needsgen = true;
					} else {
						// We DO have a certificate.
						$certdata = openssl_x509_parse(file_get_contents($certfile));
						echo "   Checking certificate for renewal: " . $d . "..." . fs_filehandler::NewLine();
						// renewed when a third of its lifetime is left (30 days of 90; right for 64 and 45 too)
						if (\Analogic\ACME\Lescript::renewalDue($certfile)) {
							// Less than a month left, we need to renew.
							echo "   --- Renewing certificate : " . $d . "" . fs_filehandler::NewLine();
							$needsgen = true;
						}					
					}
				}
			}
			
			// Do we need to generate a certificate?
			if ($needsgen) {
				try {
					//$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $webroot, $logger);
					// or without logger:
					// Pass the logger itself: "$logger = NULL" here set $logger to null, so the
					// catch block below crashed with a fatal error and stopped the whole daemon run
					$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $webroot, $logger, $countryCode, $state);
					$le->initAccount();

					// Check if domain is a subdomain
					$sql = "SELECT vh_type_in FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_name_vc=:domain AND vh_enabled_in = '1' AND vh_deleted_ts IS NULL";
					$query = $zdbh->prepare($sql);
					$query->bindParam(':userid', $sslVhost['vh_acc_fk']);
					$query->bindParam(':domain', $domain);
					$query->execute();
				
					// Get domain type
					$domainType = $query->fetchColumn();
											
					if ($domainType == 2 ) {
						// Create domain without www. becuase its a subdomain
						$le->signDomains(array($domain));
					} else {
						// Create a SSL with www. because its a root domain
						// www. too, unless public DNS has no address for it (it would fail the whole renewal)
						$le->signDomains(\Analogic\ACME\Lescript::namesFor($domain, false, $wwwNote));
						if ($wwwNote) {
							echo "   " . $wwwNote . fs_filehandler::NewLine();
						}
					}

				}
				catch (\Throwable $e) {
					// One failed renewal (a blocked challenge, a rate limit, a PHP error in
					// Lescript) must not stop the others: this used to exit(1), which ended the
					// whole daemon run, so every site after it went unrenewed and its
					// certificate expired. Log it with the domain and carry on; Server Monitor
					// alerts when a certificate gets within 14 days of expiring.
					echo "   ERROR renewing " . $domain . ": " . \Analogic\ACME\Lescript::explain($e->getMessage()) . fs_filehandler::NewLine();
					$logger->error($e->getMessage());
					$logger->error($e->getTraceAsString());
					$errorCatahed = $e->getMessage();
					error_log( date('Y-m-d H:i:s') . " - DOMAIN " . $domain . ": " . $errorCatahed . fs_filehandler::NewLine(), 3, ctrl_options::GetSystemOption('sentora_root') . 'modules/sencrypt/sencrypt.log');
					$failed[] = $domain;
				}
			}
			
			echo "Domain: " . $sslVhost['vh_name_vc'] . " analyzed." . fs_filehandler::NewLine() . fs_filehandler::NewLine();
		}
	}
	if ($failed) {
		echo count($failed) . " renewal(s) FAILED: " . implode(', ', $failed) . " (see modules/sencrypt/sencrypt.log)." . fs_filehandler::NewLine();
	}
	
}

/**
 * Renew the panel domain's Let's Encrypt certificate with a third of its lifetime left (Lescript::renewalDue()).
 *
 * Only when the panel has SSL settings and runs on port 443. A failed renewal is logged
 * (modules/sencrypt/sencrypt.log) and the daemon run carries on.
 *
 * @global db_driver $zdbh The panel's database connection (unused).
 * @global runtime_controller $controller The request controller (unused).
 * @return void
 */
function renewPanelCertificates() {
	global $zdbh, $controller;
	$logger = new Logger();
	
	$result = "";
	
		if ((ctrl_options::GetSystemOption('panel_ssl_tx') != NULL) && (ctrl_options::GetSystemOption('sentora_port' ) == 443 )) {
			
			// Other panel subomains to include - BETA DONT NOT ENABLE
			//$otherSubDomains = "ftp, webmail, mail, smtp, imap, pop";
			
			// Renew values
			$panelOwner = "zadmin";
			$domainPath = ctrl_options::GetSystemOption('sentora_root');
			echo "Checking certificate for Control Panel Domain: " . ctrl_options::GetSystemOption('sentora_domain') . fs_filehandler::NewLine();
			//echo "At location: " . $domainPath . fs_filehandler::NewLine();  - For DEBUGGING
			
			// Configuration:
			$domains = ctrl_options::GetSystemOption('sentora_domain');
			$domains = array($domains);
			$domain = ctrl_options::GetSystemOption('sentora_domain');
			$webroot = $domainPath;
			
			$accountDir = ctrl_options::GetSystemOption('hosted_dir') . $panelOwner . "/ssl/sencrypt/letsencrypt/";
			// Changed to help with backup and compability
			$certlocation = ctrl_options::GetSystemOption('hosted_dir') . $panelOwner . "/ssl/sencrypt/letsencrypt/" . $domain . "/";
			
			// Require Lescript for renewal of SSL certs
			require_once 'modules/sencrypt/code/Lescript.php';
			
			// Always use UTC
			date_default_timezone_set("UTC");
			
			// Do we need to create or upgrade our cert? Assume no to start with.
			$needsgen = false;
			
			// country and region for the request, from the server's public address
			list($countryCode, $state) = \Analogic\ACME\Lescript::serverLocation();
			
			// Default if API failed
			//$countryCode = "US";
			//$state = "NM";
			//$ipStatus = "sucess";
			
			// Check if Domain is LIVE and Pointing to this server
			// Check DNS before continuing
			// (the old check compared the panel's IP with ip-api.com's view of it: never true behind a router,
			// so every renewal was skipped there; Let's Encrypt itself says when the name does not reach here)
			if (!\Analogic\ACME\Lescript::hasAddress($domain)) {
				//$dnsInvalid = true;
				//return false;
				echo "   " . $domain . " has no address in DNS. SKIPPING." . fs_filehandler::NewLine();
				
			} else {
				// Do we HAVE a certificate for all our domains?
				$certfile = "$certlocation/cert.pem";
				if (!file_exists($certfile)) {
					// We don't have a cert, so we need to request one.
					$needsgen = true;
				} else {
					// We DO have a certificate.
					$certdata = openssl_x509_parse(file_get_contents($certfile));
					echo "   Checking certificate for renewal: " . $domain . "..." . fs_filehandler::NewLine();
					// renewed when a third of its lifetime is left (30 days of 90; right for 64 and 45 too)
					if (\Analogic\ACME\Lescript::renewalDue($certfile)) {
						// Less than a month left, we need to renew.
						echo "   --- Renewing certificate : " . $domain . "" . fs_filehandler::NewLine();
						$needsgen = true;
					} 				
				}
			}
			
			// Do we need to generate a certificate?
			if ($needsgen) {
				try {
					//$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $webroot, $logger);
					// or without logger:
					// Pass the logger itself: "$logger = NULL" here set $logger to null, so the
					// catch block below crashed with a fatal error and stopped the whole daemon run
					$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $webroot, $logger, $countryCode, $state);
					$le->initAccount();
									
					// Create panel SSL if no other sub domains are listed
					//if ($otherSubDomain == NULL ) {
						
						// Create panel domain cart
						$le->signDomains(array($domain));
						
					/*	
					} else {
						
						# Create a SSL for panel domain and other subdomains - BETA code DO NOT ENABLE
						
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
						if ($otherSubDomains) {
						 
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
				catch (\Throwable $e) {
					// Logged, and the daemon run carries on (this used to exit(1) and end it,
					// skipping every client renewal); Server Monitor alerts near expiry
					echo "   ERROR renewing the panel certificate (" . $domain . "): " . \Analogic\ACME\Lescript::explain($e->getMessage()) . fs_filehandler::NewLine();
					$logger->error($e->getMessage());
					$logger->error($e->getTraceAsString());
					$errorCatahed = $e->getMessage();
					error_log( date('Y-m-d H:i:s') . " - PANEL DOMAIN " . $domain . ": " . $errorCatahed . fs_filehandler::NewLine(), 3, ctrl_options::GetSystemOption('sentora_root') . 'modules/sencrypt/sencrypt.log');
				}
			}

			echo "Control Panel Domain: " . $domain . " analyzed." . fs_filehandler::NewLine();
		}
		
}

/**
 * Reload Apache so renewed certificates are used.
 *
 * @global db_driver $zdbh The panel's database connection (unused).
 * @return void
 */
function RestartHttpdServicesForSSL() {
	   
    global $zdbh;
   
	// Restart Apache after SSL renewal	
	echo "Finished Renewing Sencrypt SSL's... Now reloading Apache..." . fs_filehandler::NewLine();

	$returnValue = 0;

	if (sys_versions::ShowOSPlatformVersion() == "Windows") {
		system("" . ctrl_options::GetSystemOption('httpd_exe') . " " . ctrl_options::GetSystemOption('apache_restart') . "", $returnValue);
	} else {
		$command = ctrl_options::GetSystemOption('zsudo');
		$args = array(
			"service",
			ctrl_options::GetSystemOption('apache_sn'),
			ctrl_options::GetSystemOption('apache_restart')
		);
		$returnValue = ctrl_system::systemCommand($command, $args);
	}

	echo "Apache reload " . ((0 === $returnValue ) ? "suceeded" : "failed") . "." . fs_filehandler::NewLine();

}


?>