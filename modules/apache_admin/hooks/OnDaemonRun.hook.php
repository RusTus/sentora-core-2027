<?php
defined('SENTORA_PANEL_FPM_SOCKET') || define('SENTORA_PANEL_FPM_SOCKET', '/run/php/sentora-panel.sock');

echo fs_filehandler::NewLine() . "START Apache Config Hook." . fs_filehandler::NewLine();
if (ui_module::CheckModuleEnabled('Apache Config')) {
    // Account users, PHP pools, FTP owners and cron jobs: every run (cheap when
    // nothing changed), so a new account, cron job or FTP login is ready within 5 minutes
    sys_accountphp::Sync();
    echo "Apache Admin module ENABLED..." . fs_filehandler::NewLine();
    TriggerApacheQuotaUsage();
    // Write the vhosts once more if they do not have the request guard yet (servers
    // updated from an earlier version), or its account map is missing
    $vhostFile = (string) ctrl_options::GetSystemOption('apache_vhost');
    $guardMissing = is_file(rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/etc/lib/apache/sentora-guard.lua')
        && (strpos((string) @file_get_contents($vhostFile), 'SENTORA_STATUS_BLOCK') === false || !is_file('/etc/sentora/configs/apache/sentora-accounts.map'));
    if ($guardMissing) {
        echo "Apache Config: adding the request guard for hosted sites..." . fs_filehandler::NewLine();
    }
    if ($guardMissing || ctrl_options::GetSystemOption('apache_changed') == strtolower("true")) {
        echo "Apache Config has changed..." . fs_filehandler::NewLine();
		
        echo "Begin writing Apache Config to: " . ctrl_options::GetSystemOption('apache_vhost') . fs_filehandler::NewLine();
        WriteVhostConfigFile();
		
		# If Apache vhost file passes configuration test, run Apache vhost file Backup. Helping To prevent backing up a currupt vhost.conf
		if ( ctrl_options::GetSystemOption('apache_changed') != strtolower("true") ) {
			
			if (ctrl_options::GetSystemOption('apache_backup') == strtolower("true")) {
				echo "Backing up Apache Config to: " . ctrl_options::GetSystemOption('apache_budir') . fs_filehandler::NewLine();
				BackupVhostConfigFile();
			}
		}

    } else {
        echo "Apache Config has NOT changed...nothing to do." . fs_filehandler::NewLine();
    }
} else {
    echo "Apache Admin module DISABLED...nothing to do." . fs_filehandler::NewLine();
}
echo "END Apache Config Hook." . fs_filehandler::NewLine();

/**
 *
 * @param string $vhostName
 * @param numeric $customPort
 * @param string $userEmail[5~ * @return string
 *
 */
function BuildVhostPortForward($vhostName, $customPort, $userEmail) {
		
	$customPort_in = $customPort;
	
    $line = "# DOMAIN: " . $vhostName . fs_filehandler::NewLine();
    $line .= "# PORT FORWARD FROM ".SentoraOneLine(ctrl_options::GetSystemOption('apache_port'))." TO: " . $customPort_in . fs_filehandler::NewLine();
    // $apache_port was never defined here, which wrote "<Virtualhost *:>"
    $line .= "<Virtualhost *:" . SentoraOneLine(ctrl_options::GetSystemOption('apache_port')) . ">" . fs_filehandler::NewLine();
    $line .= "ServerName " . $vhostName . fs_filehandler::NewLine();
	if ($vhostName != ctrl_options::GetSystemOption('sentora_domain') ) 
		$line .= "ServerAlias www." . $vhostName . fs_filehandler::NewLine();
    $line .= "ServerAdmin " . $userEmail . fs_filehandler::NewLine();
    $line .= "RewriteEngine on" . fs_filehandler::NewLine();
    // Apache's own status page stays on plain http for requests from this server
    // (Require local), so the Apache Status module can read it
    $line .= "RewriteCond %{REQUEST_URI} !^/server-status" . fs_filehandler::NewLine();
    $line .= "ReWriteCond %{SERVER_PORT} !^" . $customPort_in . "$" . fs_filehandler::NewLine();
    $line .= ( $customPort_in === "443" ) ? "RewriteRule ^/(.*) https://%{HTTP_HOST}/$1 [NC,R,L] " . fs_filehandler::NewLine() : "RewriteRule ^/(.*) http://%{HTTP_HOST}:" . $customPort . "/$1 [NC,R,L] " . fs_filehandler::NewLine();
    $line .= "</virtualhost>" . fs_filehandler::NewLine();
    //$line .= "# END DOMAIN: " . $vhostName . fs_filehandler::NewLine() . fs_filehandler::NewLine();
	//$line .= "################################################################" . fs_filehandler::NewLine();
	$line .= "##-------" . fs_filehandler::NewLine();
	$line .= fs_filehandler::NewLine();
		
    return $line;
}

# vhost SSL ReWrite http to https -tg
function BuildVhostReWriteSSL($vhostName, $userEmail) {
		
    $line = "# DOMAIN: " . $vhostName . fs_filehandler::NewLine();
    $line .= "# SSL REDIRECT" . fs_filehandler::NewLine();
    $line .= "<Virtualhost *:".SentoraOneLine(ctrl_options::GetSystemOption('apache_port')).">" . fs_filehandler::NewLine();
    $line .= "ServerName " . $vhostName . fs_filehandler::NewLine();
	if ($vhostName != ctrl_options::GetSystemOption('sentora_domain') ) 
		$line .= "ServerAlias www." . $vhostName . fs_filehandler::NewLine();
    $line .= "ServerAdmin " . $userEmail . fs_filehandler::NewLine();
    $line .= "RewriteEngine On" . fs_filehandler::NewLine();
	$line .= "RewriteCond %{HTTPS} !=on" . fs_filehandler::NewLine();
	// Apache's own status page stays on plain http for requests from this server
    // (Require local), so the Apache Status module can read it
    $line .= "RewriteCond %{REQUEST_URI} !^/server-status" . fs_filehandler::NewLine();
	$line .= "RewriteRule ^/?(.*) https://%{SERVER_NAME}/$1 [R,L]" . fs_filehandler::NewLine();
    $line .= "</virtualhost>" . fs_filehandler::NewLine();
    //$line .= "# END DOMAIN: " . $vhostName . fs_filehandler::NewLine() . fs_filehandler::NewLine();
	//$line .= "################################################################" . fs_filehandler::NewLine();
	$line .= fs_filehandler::NewLine();
	$line .= "##-------" . fs_filehandler::NewLine();
	$line .= fs_filehandler::NewLine();
		
    return $line;
}

/**
 * PHP handler lines for a regular domain or subdomain: the system PHP (mod_php,
 * the php_handler setting) or the PHP-FPM version chosen for the domain.
 */
/**
 * The panel also answers on this server's own IP addresses (https://<server IP>),
 * not only on its name; any other unknown name still gets "not found".
 */
function SentoraPanelAliases() {
    // Whether the panel also answers on the server's own IP addresses is controlled by
    // the 'panel_ip_access' setting (Sentora Config), which defaults to on. Only an
    // explicit off value switches it off; a missing or empty setting (for example a
    // server upgraded from before this setting existed) keeps the panel answering on its
    // IP, the previous behaviour. GetSystemOption returns false when the row is absent.
    $ipval = strtolower(trim((string) ctrl_options::GetSystemOption('panel_ip_access')));
    if (in_array($ipval, array('false', '0', 'no', 'off'), true)) {
        return '';
    }
    $ips = array();
    foreach (array_merge(preg_split('/\s+/', trim((string) @shell_exec('hostname -I 2>/dev/null'))), array((string) ctrl_options::GetSystemOption('server_ip'))) as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP) && strpos($ip, '127.') !== 0 && $ip !== '::1') {
            $ips[$ip] = true;
        }
    }
    if (!count($ips)) {
        return '';
    }
    return "ServerAlias " . implode(' ', array_map(function ($ip) {
        return strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
    }, array_keys($ips))) . fs_filehandler::NewLine();
}

/** A setting that belongs on one line of the Apache config (no line breaks) */
function SentoraOneLine($v) {
    return preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $v);
}

/**
 * Custom vhost entries (Apache Config) go into the config as written, so the
 * directives that would let them run a program as root (Apache's main process
 * starts piped loggers and rewrite-map programs as root) or load outside code
 * are kept but commented out.
 */
function SentoraSafeCustom($text) {
    $text = SentoraNoModPhp($text);
    $danger = '/^(\s*)((?:Include|IncludeOptional|LoadModule|LoadFile|RewriteMap|PidFile|ServerRoot|CoreDumpDirectory|ScriptSock|Mutex|Define|UnDefine|User|Group|Listen|SSLPassPhraseDialog|SSLCryptoDevice|GlobalLog|ScoreBoardFile|DefaultRuntimeDir)\b.*'
        . '|(?:CustomLog|ErrorLog|TransferLog|ForensicLog|CookieLog|RewriteLog|ScriptLog)\s+["\']?\s*(?:\||syslog:|exec:).*'
        . '|(?:CustomLog|TransferLog)\s+.*["\']?\s*\|.*)$/mi';
    return preg_replace($danger, '$1# Not allowed in custom entries (would run as root): $2', $text);
}

/**
 * Custom vhost entries written for mod_php ("php_value ...", "php_admin_flag ...")
 * would stop Apache now that PHP runs in PHP-FPM: they are kept, commented out.
 * (In PHP-FPM, per-site PHP settings go in a .user.ini file in the site's folder.)
 */
function SentoraNoModPhp($text) {
    return preg_replace('/^(\s*)(php_(?:admin_)?(?:value|flag)\b.*)$/mi', '$1# Not used with PHP-FPM (use .user.ini): $2', (string) $text);
}

function BuildVhostPhpHandler($rowvhost, $vhostuser) {
    // Every domain's PHP runs in its account's own PHP-FPM pool (user web<id>),
    // in the version chosen for the domain (or the default)
    $version = isset($rowvhost['vh_php_version_vc']) ? (string) $rowvhost['vh_php_version_vc'] : '';
    $version = sys_phpversions::Effective($version);
    $home = sys_accountphp::HomeDir($vhostuser['username']);
    $openBasedir = '';
    if (ctrl_options::GetSystemOption('use_openbase') == "true" && $rowvhost['vh_obasedir_in'] <> 0) {
        // The domain's own folder and the account's temp folder (not the shared /var/sentora/temp)
        $openBasedir = $home . "public_html" . $rowvhost['vh_directory_vc'] . '/' . ctrl_options::GetSystemOption('openbase_seperator') . $home . 'tmp/';
    }
    return sys_accountphp::ApacheHandler($vhostuser['userid'], $vhostuser['username'], $version, $openBasedir);
}

/**
 * What a hosted site's request may be handed to: etc/lib/apache/sentora-guard.lua
 * (mod_lua, run after .htaccess and mod_rewrite) allows a proxy handler only to the
 * account's own PHP-FPM sockets, and refuses the panel's pool, other accounts' pools,
 * server-status, server-info and proxying. Writes the account map it reads, makes
 * sure mod_lua is on, and returns the lines for the top of the vhost file.
 */
function SentoraRequestGuard()
{
    global $zdbh;
    $nl = fs_filehandler::NewLine();
    $lua = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/etc/lib/apache/sentora-guard.lua';
    if (!is_file($lua)) {
        return '';
    }
    $map = "hosted_dir " . rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . "/\n";
    foreach ($zdbh->query("SELECT ac_id_pk, ac_user_vc FROM x_accounts WHERE ac_deleted_ts IS NULL ORDER BY ac_id_pk")->fetchAll() as $a) {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $a['ac_user_vc'])) {
            $map .= $a['ac_user_vc'] . ' ' . (int) $a['ac_id_pk'] . "\n";
        }
    }
    $mapFile = '/etc/sentora/configs/apache/sentora-accounts.map';
    if (@file_get_contents($mapFile) !== $map) {
        @file_put_contents($mapFile . '.new', $map);
        @chmod($mapFile . '.new', 0644);
        @rename($mapFile . '.new', $mapFile);
    }
    if (!is_file('/etc/apache2/mods-enabled/lua.load') && is_file('/etc/apache2/mods-available/lua.load')) {
        exec('a2enmod -q lua > /dev/null 2>&1');
        echo "Apache: mod_lua enabled (hosted sites may hand requests only to their own PHP)" . $nl;
    }
    return "# What a hosted site's request may be handed to: its own PHP only (" . $lua . ")" . $nl
        . "<IfModule lua_module>" . $nl
        . "    LuaScope thread" . $nl
        // runs after mod_rewrite's and SetHandler's fixups, which are registered first
        . "    LuaHookFixups \"" . $lua . "\" sentora_guard" . $nl
        . "    LuaOutputFilter SENTORA_STATUS_BLOCK \"" . $lua . "\" sentora_status_block" . $nl
        . "</IfModule>" . $nl . $nl;
}

function WriteVhostConfigFile() {
    global $zdbh;

    // Start enabled extra PHP versions and stop disabled ones (PHP Versions module)
    sys_phpversions::SyncServices();
    // One Linux user and PHP-FPM pool per account, for the versions its domains use
    // (again here, after any PHP version changes above)
    sys_accountphp::Sync();

    // Apache refuses the whole config if a log folder is missing, so make sure
    // the panel and per-domain log folders exist before writing the vhosts
    $logDir = ctrl_options::GetSystemOption('log_dir');
    if (!is_dir($logDir . 'domains/')) {
        fs_director::CreateDirectory($logDir . 'domains/');
    }
	
	if ((float) sys_versions::ShowApacheVersion() < 2.4) {
        $apgrant = "0";
    } else {
        $apgrant = "1";
    }
	
    # Get email for server admin of Sentora
    $getserveremail = $zdbh->query("SELECT ac_email_vc FROM x_accounts where ac_id_pk=1")->fetch();
    $serveremail = ( $getserveremail['ac_email_vc'] != "" ) ? $getserveremail['ac_email_vc'] : "postmaster@" . ctrl_options::GetSystemOption('sentora_domain');

    $VHostDefaultPort = SentoraOneLine(ctrl_options::GetSystemOption('apache_port'));
    // Always listen on the standard web port too: when the panel moves to 443
    // its http:// redirect lives on this port, even before any domain exists
    $customPorts = array(ctrl_options::GetSystemOption('sentora_port'), $VHostDefaultPort);
	
    $portQuery = $zdbh->prepare("SELECT vh_custom_port_in FROM x_vhosts WHERE vh_deleted_ts IS NULL");
    $portQuery->execute();
		while ($rowport = $portQuery->fetch()) {
			$customPorts[] = (empty($rowport['vh_custom_port_in'])) ? $VHostDefaultPort : $rowport['vh_custom_port_in'];
			
			# Add vh_ssl_port_in ports to list array 
			$portQuery2 = $zdbh->prepare("SELECT vh_ssl_port_in FROM x_vhosts WHERE vh_deleted_ts IS NULL");
			$portQuery2->execute();
			while ($rowport2 = $portQuery2->fetch()) {
				$customPorts[] = (empty($rowport2['vh_ssl_port_in'])) ? $VHostDefaultPort : $rowport2['vh_ssl_port_in'];
			}	
		}
	
    $customPortList = array_unique($customPorts);
					
    /*
     * ###########################################################################?###################################
     * #
     * # Default Virtual Host Container
     * #
     * ###########################################################################?###################################
     */

    $line = "################################################################" . fs_filehandler::NewLine();
    $line .= "# Apache VHOST configuration file" . fs_filehandler::NewLine();
    $line .= "# Automatically generated by " . sys_versions::ShowSentoraName() . fs_filehandler::NewLine();
    $line .= "# Generated on: " . date(ctrl_options::GetSystemOption('sentora_df'), time()) . fs_filehandler::NewLine();
    $line .= "#==== YOU MUST NOT EDIT THIS FILE : IT WILL BE OVERWRITTEN ====" . fs_filehandler::NewLine();
    $line .= "# Use Sentora Menu -> Admin -> Module Admin -> Apache config" . fs_filehandler::NewLine();
    $line .= "################################################################" . fs_filehandler::NewLine();
    $line .= fs_filehandler::NewLine();
    $line .= SentoraRequestGuard();

    # Listen is mandatory for each port <> 80 (80 is defined in system config)
	//$line .= "Listen " . SentoraOneLine(ctrl_options::GetSystemOption('apache_port'));
	# For each custom port
    foreach ($customPortList as $port) {
        $line .= "Listen " . $port . fs_filehandler::NewLine();
    }
	
	$line .= fs_filehandler::NewLine();

	// HTTPS for a name with no certificate of its own here (a domain whose SSL is not
	// set up or failed, an address): "not found", instead of Apache falling back to the
	// first HTTPS vhost, which is the panel's login page. It must be the first one.
	$sslCert = '/etc/sentora/configs/ssl/';
	if (ctrl_options::GetSystemOption('panel_ssl_tx') != null && is_file($sslCert . 'sentora.crt') && is_file($sslCert . 'sentora.key')) {
		$line .= "# Any HTTPS name without its own vhost here: 404" . fs_filehandler::NewLine();
		$line .= "<VirtualHost *:443>" . fs_filehandler::NewLine();
		$line .= "ServerName sentora-default.invalid" . fs_filehandler::NewLine();
		$line .= "SSLEngine On" . fs_filehandler::NewLine();
		$line .= "SSLCertificateFile " . $sslCert . "sentora.crt" . fs_filehandler::NewLine();
		$line .= "SSLCertificateKeyFile " . $sslCert . "sentora.key" . fs_filehandler::NewLine();
		$line .= "DocumentRoot /var/www" . fs_filehandler::NewLine();
		$line .= "ErrorDocument 404 \"Not Found\"" . fs_filehandler::NewLine();
		$line .= "Redirect 404 /" . fs_filehandler::NewLine();
		$line .= "</VirtualHost>" . fs_filehandler::NewLine() . fs_filehandler::NewLine();
	}

	$line .= "# Configuration for Sentora control panel." . fs_filehandler::NewLine();
	
	if (ctrl_options::GetSystemOption('panel_ssl_tx') == null) {
		
		##
		## Sentora Control Panel default vhost entry
		##
		
		//$line .= "# Configuration for Sentora control panel." . fs_filehandler::NewLine();
		$line .= "<VirtualHost *:" . ctrl_options::GetSystemOption('sentora_port') . ">" . fs_filehandler::NewLine();
		$line .= "ServerAdmin " . $serveremail . fs_filehandler::NewLine();
		$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('sentora_root') . '"' . fs_filehandler::NewLine();
		$line .= "ServerName " . ctrl_options::GetSystemOption('sentora_domain') . fs_filehandler::NewLine();
		$line .= SentoraPanelAliases();
		
		# Vhost PHP settings
		// The panel runs as its own user through PHP-FPM (pool "sentora"), never as the
		// web server user that every hosted site's PHP runs as. Its session and upload
		// folders are set in that pool, out of reach of the sites.
		$line .= '<FilesMatch "\.(php|phtml)$">' . fs_filehandler::NewLine();
		$line .= '    SetHandler "proxy:unix:' . SENTORA_PANEL_FPM_SOCKET . '|fcgi://localhost/"' . fs_filehandler::NewLine();
		$line .= '</FilesMatch>' . fs_filehandler::NewLine();
		//$line .= "php_admin_value open_basedir " . '"' . ctrl_options::GetSystemOption('sentora_root') . ctrl_options::GetSystemOption('openbase_seperator') 
				//. '/etc/sentora/configs/' . ctrl_options::GetSystemOption('openbase_seperator')
				//. ctrl_options::GetSystemOption('temp_dir') . ctrl_options::GetSystemOption('openbase_seperator')  
				//. ctrl_options::GetSystemOption('hosted_dir') . '"' . fs_filehandler::NewLine();
				
				

		
		$line .= 'ErrorLog "' . ctrl_options::GetSystemOption('log_dir') . 'sentora-error.log" ' . fs_filehandler::NewLine();
		$line .= 'CustomLog "' . ctrl_options::GetSystemOption('log_dir') . 'sentora-access.log" ' . SentoraOneLine(ctrl_options::GetSystemOption('access_log_format')) . fs_filehandler::NewLine();
		$line .= 'CustomLog "' . ctrl_options::GetSystemOption('log_dir') . 'sentora-bandwidth.log" ' . SentoraOneLine(ctrl_options::GetSystemOption('bandwidth_log_format')) . fs_filehandler::NewLine();
		
		// Error documents:- Error pages are added automatically if they are found in the /etc/static/errorpages
		// directory and if they are a valid error code, and saved in the proper format, i.e. <error_number>.html
		$errorpages = ctrl_options::GetSystemOption('sentora_root') . "/etc/static/errorpages";
		if (is_dir($errorpages)) {
			if ($handle = opendir($errorpages)) {
				while (($file = readdir($handle)) !== false) {
					if ($file != "." && $file != "..") {
						$page = explode(".", $file);
						if (!fs_director::CheckForEmptyValue(CheckErrorDocument($page[0]))) {
							$line .= "ErrorDocument " . $page[0] . " /etc/static/errorpages/" . $page[0] . ".html" . fs_filehandler::NewLine();
						}
					}
				}
				closedir($handle);
			}
		}
		$line .= '<Directory "' . ctrl_options::GetSystemOption('sentora_root') . '">' . fs_filehandler::NewLine();
		$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
		$line .= "    AllowOverride All" . fs_filehandler::NewLine();
	
		if ((float) sys_versions::ShowApacheVersion() < 2.4) {
			$line .= "    Require all granted" . fs_filehandler::NewLine();
		} else {
			$line .= "    Require all granted" . fs_filehandler::NewLine();
		}
	
		$line .= "</Directory>" . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();
		// Theme and module styles, scripts and images: the browser keeps its copy but asks
		// before each use (a quick "not modified" when unchanged), so after an update every
		// page gets the new files, including the files a stylesheet @imports
		$line .= "<IfModule mod_headers.c>" . fs_filehandler::NewLine();
		$line .= '    <LocationMatch "^/(etc/styles|modules/[^/]+/assets)/">' . fs_filehandler::NewLine();
		$line .= '        Header set Cache-Control "no-cache"' . fs_filehandler::NewLine();
		$line .= "    </LocationMatch>" . fs_filehandler::NewLine();
		$line .= "</IfModule>" . fs_filehandler::NewLine();
		$line .= "# Custom settings (if any exist)" . fs_filehandler::NewLine();
	
		// Global custom Sentora entry
		$line .= ctrl_options::GetSystemOption('global_zpcustom') . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();
	
		$line .= "</VirtualHost>" . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();
		
		$line .= "# END PANEL DOMAIN: " . ctrl_options::GetSystemOption('sentora_domain') . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();
	
		# Load template file into vhost cofig to save
		//$line .= $smarty->fetch("vhost_cp.tpl") . fs_filehandler::NewLine();
	
	# Forwrd Sentora Panel if SSL is in use
	# If vhost SSL_TX not null create spearate <virtualhost>
	# Build Vhost SSL section
    } elseif (ctrl_options::GetSystemOption('panel_ssl_tx') != null) {
		
		# Build HTTP to HTTPS Redirect
		$line .= BuildVhostReWriteSSL(ctrl_options::GetSystemOption('sentora_domain'), $serveremail);
		
		//$line .= "################################################################" . fs_filehandler::NewLine();
		//$line .= fs_filehandler::NewLine();
		//$line .= "# Configuration for Sentora control panel SSL." . fs_filehandler::NewLine();
		$line .= "# PANEL HAS SSL ENABLED" . fs_filehandler::NewLine();
		$line .= "<VirtualHost *:" . ctrl_options::GetSystemOption('sentora_port') . ">" . fs_filehandler::NewLine();
		$line .= "ServerAdmin " . $serveremail . fs_filehandler::NewLine();
		$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('sentora_root') . '"' . fs_filehandler::NewLine();
		$line .= "ServerName " . ctrl_options::GetSystemOption('sentora_domain') . fs_filehandler::NewLine();
		$line .= SentoraPanelAliases();
		
		# Vhost PHP settings
		// The panel runs as its own user through PHP-FPM (pool "sentora"), never as the
		// web server user that every hosted site's PHP runs as. Its session and upload
		// folders are set in that pool, out of reach of the sites.
		$line .= '<FilesMatch "\.(php|phtml)$">' . fs_filehandler::NewLine();
		$line .= '    SetHandler "proxy:unix:' . SENTORA_PANEL_FPM_SOCKET . '|fcgi://localhost/"' . fs_filehandler::NewLine();
		$line .= '</FilesMatch>' . fs_filehandler::NewLine();
		//$line .= "php_admin_value open_basedir " . '"' . ctrl_options::GetSystemOption('sentora_root') . ctrl_options::GetSystemOption('openbase_seperator') 
				//. '/etc/sentora/configs/' . ctrl_options::GetSystemOption('openbase_seperator')
				//. ctrl_options::GetSystemOption('temp_dir') . ctrl_options::GetSystemOption('openbase_seperator')  
				//. ctrl_options::GetSystemOption('hosted_dir') . '"' . fs_filehandler::NewLine();
				
		

	
		$line .= 'ErrorLog "' . ctrl_options::GetSystemOption('log_dir') . 'sentora-error.log" ' . fs_filehandler::NewLine();
		$line .= 'CustomLog "' . ctrl_options::GetSystemOption('log_dir') . 'sentora-access.log" ' . SentoraOneLine(ctrl_options::GetSystemOption('access_log_format')) . fs_filehandler::NewLine();
		$line .= 'CustomLog "' . ctrl_options::GetSystemOption('log_dir') . 'sentora-bandwidth.log" ' . SentoraOneLine(ctrl_options::GetSystemOption('bandwidth_log_format')) . fs_filehandler::NewLine();
	
		// Error documents:- Error pages are added automatically if they are found in the /etc/static/errorpages
		// directory and if they are a valid error code, and saved in the proper format, i.e. <error_number>.html
		$errorpages = ctrl_options::GetSystemOption('sentora_root') . "/etc/static/errorpages";
		if (is_dir($errorpages)) {
			if ($handle = opendir($errorpages)) {
				while (($file = readdir($handle)) !== false) {
					if ($file != "." && $file != "..") {
						$page = explode(".", $file);
						if (!fs_director::CheckForEmptyValue(CheckErrorDocument($page[0]))) {
							$line .= "ErrorDocument " . $page[0] . " /etc/static/errorpages/" . $page[0] . ".html" . fs_filehandler::NewLine();
						}
					}
				}
				closedir($handle);
			}
		}
		$line .= '<Directory "' . ctrl_options::GetSystemOption('sentora_root') . '">' . fs_filehandler::NewLine();
		$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
		$line .= "    AllowOverride All" . fs_filehandler::NewLine();
	
		if ((float) sys_versions::ShowApacheVersion() < 2.4) {
			$line .= "    Require all granted" . fs_filehandler::NewLine();
		} else {
			$line .= "    Require all granted" . fs_filehandler::NewLine();
		}
		
		$line .= "</Directory>" . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();

		# SSL Settings
		$line .= "# SSL settings (if any exist)" . fs_filehandler::NewLine();
		$line .= ctrl_options::GetSystemOption('panel_ssl_tx') . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();

		// Theme and module assets: revalidated on each use (see the plain-HTTP block above)
		$line .= "<IfModule mod_headers.c>" . fs_filehandler::NewLine();
		$line .= '    <LocationMatch "^/(etc/styles|modules/[^/]+/assets)/">' . fs_filehandler::NewLine();
		$line .= '        Header set Cache-Control "no-cache"' . fs_filehandler::NewLine();
		$line .= "    </LocationMatch>" . fs_filehandler::NewLine();
		$line .= "</IfModule>" . fs_filehandler::NewLine();
		$line .= "# Custom settings are loaded below this line (if any exist)" . fs_filehandler::NewLine();
		// Global custom Sentora entry
		$line .= ctrl_options::GetSystemOption('global_zpcustom') . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();
		
		$line .= "</VirtualHost>" . fs_filehandler::NewLine();
		//$line .= fs_filehandler::NewLine();
		
		$line .= "# END PANEL DOMAIN: " . ctrl_options::GetSystemOption('sentora_domain') . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();
		
		############################## add back old code
		//$line .= $smarty->fetch("vhost_cp_ssl.tpl") . fs_filehandler::NewLine();
		
	}
	
	#*********Write to file
	//writetofile($server_panel . "sentora-cp.conf", $line);
	#***********
	
	//$line .= fs_filehandler::NewLine();
    $line .= "################################################################" . fs_filehandler::NewLine();
    $line .= "# Sentora generated VHOST configurations below....." . fs_filehandler::NewLine();
    $line .= "################################################################" . fs_filehandler::NewLine();
    $line .= fs_filehandler::NewLine();

    /*
     * ##############################################################################################################
     * #
     * # All Virtual Host Containers
     * #
     * ##############################################################################################################
     */
	
	#
    # Sentora virtual host container configuration
	#
	$sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_deleted_ts IS NULL");
    $sql->execute();
    while ($rowvhost = $sql->fetch()) {
	
	# Grab some variables we will use for later...
	$vhostuser = ctrl_users::GetUserDetail($rowvhost['vh_acc_fk']);
	$bandwidth = ctrl_users::GetQuotaUsages('bandwidth', $vhostuser['userid']);
	$diskspace = ctrl_users::GetQuotaUsages('diskspace', $vhostuser['userid']);
	# Set the vhosts to "LIVE"
	$vsql = $zdbh->prepare("UPDATE x_vhosts SET vh_active_in=1 WHERE vh_id_pk=:id");
	$vsql->bindParam(':id', $rowvhost['vh_id_pk']);
	$vsql->execute();
	
	# Add a default email if no email found for client.
	$useremail = ( fs_director::CheckForEmptyValue($vhostuser['email']) ) ? "postmaster@" . $rowvhost['vh_name_vc'] : $vhostuser['email'];
	
	# Check if domain or subdomain to see if we add an alias with 'www'
	$serveralias = ( $rowvhost['vh_type_in'] == 2 ) ? '' : " www." . $rowvhost['vh_name_vc'];
	
	# Check if site is ssl enabled to pevent duplicate Port 443
	//if ($rowvhost['vh_ssl_tx'] == !null) {
		//$vhostPort = $VHostDefaultPort;
	//} else {
		$vhostPort = ( fs_director::CheckForEmptyValue($rowvhost['vh_custom_port_in']) ) ? $VHostDefaultPort : $rowvhost['vh_custom_port_in'];
	//};
	
	$vhostIp = ( fs_director::CheckForEmptyValue($rowvhost['vh_custom_ip_vc']) ) ? "*" : $rowvhost['vh_custom_ip_vc'];
	
	# Get Package php and cgi enabled options
	$rows = $zdbh->prepare("SELECT * FROM x_packages WHERE pk_id_pk=:packageid AND pk_deleted_ts IS NULL");
	$rows->bindParam(':packageid', $vhostuser['packageid']);
	$rows->execute();
	$packageinfo = $rows->fetch();
	
	#*************************************************
		  
	$RootDir =  ctrl_options::GetSystemOption('hosted_dir') . $vhostuser['username'] . '/public_html' . $rowvhost['vh_directory_vc'];  
	
	# Domain is enabled
	# Line1: Domain enabled & Client also is enabled.
	# Line2: Domain enabled & Client may be disabled, but 'Allow Disabled' = 'true' in apache settings.
	if ($rowvhost['vh_enabled_in'] == 1 && ctrl_users::CheckUserEnabled($rowvhost['vh_acc_fk']) ||
		$rowvhost['vh_enabled_in'] == 1 && ctrl_options::GetSystemOption('apache_allow_disabled') == strtolower("true")) {
	
		/*
		 * ##################################################
		 * #
		 * # Disk Quotas Check
		 * #
		 * ##################################################
		 */
	
		# Domain is beyond its diskusage
		if ($vhostuser['diskquota'] != 0 && $diskspace > $vhostuser['diskquota']) {
			
			if ($rowvhost['vh_ssl_tx'] == null) {
			
				# Load template file into vhost cofig to save
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS BEEN DISABLED FOR DISK QUOTA OVERAGE" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . $vhostIp . ":" . $vhostPort . ">" . fs_filehandler::NewLine();
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				//$line .= "ServerAlias www." . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('static_dir') . 'diskexceeded"' . fs_filehandler::NewLine();
				$line .= '<Directory "' . ctrl_options::GetSystemOption('static_dir') . 'diskexceeded">' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				$line .= "    AllowOverride All" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				$line .= sys_accountphp::PanelHandler();
				$line .= SentoraOneLine(ctrl_options::GetSystemOption('dir_index')) . fs_filehandler::NewLine();
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
		
				############################## add back old code
				//$line .= $smarty->fetch("vhost_disk_quota.tpl") . fs_filehandler::NewLine();
				
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
				
				if ($rowvhost['vh_portforward_in'] <> 0) {
					$line .= fs_filehandler::NewLine();
					$line .= BuildVhostPortForward($rowvhost['vh_name_vc'], $vhostPort, $useremail);
				}
			
			# If vhost SSL_TX not null create spearate <virtualhost>
			} elseif ($rowvhost['vh_ssl_tx'] != null && $rowvhost['vh_ssl_port_in'] != null ) {
				
				# Build HTTP to HTTPS Redirect
				$line .= BuildVhostReWriteSSL($rowvhost['vh_name_vc'], $useremail);
				
				# Build Vhost SSL section
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS BEEN DISABLED FOR DISK QUOTA OVERAGE & HAS SSL ENABLED" . fs_filehandler::NewLine();
				//$line .= "<virtualhost " . $vhostIp . ":" . $vhostPort . ">" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . $vhostIp . ":" . $rowvhost['vh_ssl_port_in'] . ">" . fs_filehandler::NewLine();
				
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				//$line .= "ServerAlias www." . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('static_dir') . 'diskexceeded"' . fs_filehandler::NewLine();
				$line .= '<Directory "' . ctrl_options::GetSystemOption('static_dir') . 'diskexceeded">' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				$line .= "    AllowOverride All" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				$line .= sys_accountphp::PanelHandler();
				$line .= SentoraOneLine(ctrl_options::GetSystemOption('dir_index')) . fs_filehandler::NewLine();
				
				# SSL Settings
				$line .= "# SSL settings (if any exist)" . fs_filehandler::NewLine();
				$line .= $rowvhost['vh_ssl_tx'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
				
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
				
				############################## add back old code
				//$line .= $smarty->fetch("vhost_disk_quota_ssl.tpl") . fs_filehandler::NewLine();
				
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
			}
			$line .= fs_filehandler::NewLine();
			
			
			#*********Write to file
			//writetofile($vh_path . $rowvhost['vh_name_vc']. ".conf"  , $line);
			#***********	
			
		/*
		 * ##################################################
		 * #
		 * # Bandwidth Quotas Check
		 * #
		 * ##################################################
		 */
	
		# Domain is beyond its quota
		} elseif ($vhostuser['bandwidthquota'] != 0 && $bandwidth > $vhostuser['bandwidthquota']) {
			
			if ($rowvhost['vh_ssl_tx'] == null) {
			
				# Load template file into vhost cofig to save
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS BEEN DISABLED FOR BANDWIDTH OVERAGE" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . $vhostIp . ":" . $vhostPort . ">" . fs_filehandler::NewLine();
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				//$line .= "ServerAlias www." . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('static_dir') . 'bandwidthexceeded"' . fs_filehandler::NewLine();
				$line .= '<Directory "' . ctrl_options::GetSystemOption('static_dir') . 'bandwidthexceeded">' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				$line .= "    AllowOverride All" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				$line .= sys_accountphp::PanelHandler();
				$line .= SentoraOneLine(ctrl_options::GetSystemOption('dir_index')) . fs_filehandler::NewLine();
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
				
				############################## add back old code
				//$line .= $smarty->fetch("vhost_bandwidth.tpl") . fs_filehandler::NewLine();
				
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
				
				$line .= fs_filehandler::NewLine();
				if ($rowvhost['vh_portforward_in'] <> 0) {
					$line .= BuildVhostPortForward($rowvhost['vh_name_vc'], $vhostPort, $useremail);
				}
			
			# If vhost SSL_TX not null create spearate <virtualhost>
			} elseif ($rowvhost['vh_ssl_tx'] != null && $rowvhost['vh_ssl_port_in'] != null ) {
				
				# Build HTTP to HTTPS Redirect
				$line .= BuildVhostReWriteSSL($rowvhost['vh_name_vc'], $useremail);
				
				# Build Vhost SSL section
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS BEEN DISABLED FOR BANDWIDTH OVERAGE & HAS SSL ENABLED" . fs_filehandler::NewLine();
				//$line .= "<virtualhost " . $vhostIp . ":" . $vhostPort . ">" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . $vhostIp . ":" . $rowvhost['vh_ssl_port_in'] . ">" . fs_filehandler::NewLine();
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				//$line .= "ServerAlias www." . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('static_dir') . 'bandwidthexceeded"' . fs_filehandler::NewLine();
				$line .= '<Directory "' . ctrl_options::GetSystemOption('static_dir') . 'bandwidthexceeded">' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				$line .= "    AllowOverride All" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				$line .= sys_accountphp::PanelHandler();
				$line .= SentoraOneLine(ctrl_options::GetSystemOption('dir_index')) . fs_filehandler::NewLine();
				
				# SSL Settings
				$line .= "# SSL settings (if any exist)" . fs_filehandler::NewLine();
				$line .= $rowvhost['vh_ssl_tx'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
				
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
				
				############################## add back old code
				//$line .= $smarty->fetch("vhost_bandwidth_ssl.tpl") . fs_filehandler::NewLine();
				
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
			}
			$line .= fs_filehandler::NewLine();
			
			# Write to file
			//writetofile($vh_path . $rowvhost['vh_name_vc']. ".conf"  , $line);
			#***********				
			
		/*
		 * ##################################################
		 * #
		 * # Parked Domain
		 * #
		 * ##################################################
		 */
	
		# Domain is a PARKED domain.
		} elseif ($rowvhost['vh_type_in'] == 3) {
			
			if ($rowvhost['vh_ssl_tx'] == null) {			
			
				# Load template file into vhost config to save
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS BEEN PARKED" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . $vhostIp . ":" . $vhostPort . ">" . fs_filehandler::NewLine();
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				//$line .= "ServerAlias www." . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('parking_path') . '"' . fs_filehandler::NewLine();
				$line .= '<Directory "' . ctrl_options::GetSystemOption('parking_path') . '">' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				$line .= "    AllowOverride All" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				$line .= sys_accountphp::PanelHandler();
				$line .= SentoraOneLine(ctrl_options::GetSystemOption('dir_index')) . fs_filehandler::NewLine();
				// Global custom global vh entry
				$line .= "# Custom Global Settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom(ctrl_options::GetSystemOption('global_vhcustom')) . fs_filehandler::NewLine();
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
				
				############################## add back old code
				//$line .= $smarty->fetch("vhost_parked.tpl") . fs_filehandler::NewLine();
				
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
				
				$line .= fs_filehandler::NewLine();
				if ($rowvhost['vh_portforward_in'] <> 0) {
					$line .= BuildVhostPortForward($rowvhost['vh_name_vc'], $vhostPort, $useremail);
				}
			
			# If vhost SSL_TX not null create spearate <virtualhost>
			} elseif ($rowvhost['vh_ssl_tx'] != null && $rowvhost['vh_ssl_port_in'] != null) {
				
				# Build HTTP to HTTPS Redirect
				$line .= BuildVhostReWriteSSL($rowvhost['vh_name_vc'], $useremail);
				
				# Build Vhost SSL section
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN IS PARKED & HAS SSL ENABLED" . fs_filehandler::NewLine();
				//$line .= "<virtualhost " . $vhostIp . ":" . $vhostPort . ">" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . $vhostIp . ":" . $rowvhost['vh_ssl_port_in'] . ">" . fs_filehandler::NewLine();
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				//$line .= "ServerAlias www." . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('parking_path') . '"' . fs_filehandler::NewLine();
				$line .= '<Directory "' . ctrl_options::GetSystemOption('parking_path') . '">' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				$line .= "    AllowOverride All" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				$line .= sys_accountphp::PanelHandler();
				$line .= SentoraOneLine(ctrl_options::GetSystemOption('dir_index')) . fs_filehandler::NewLine();
				
				# SSL Settings
				$line .= "# SSL settings (if any exist)" . fs_filehandler::NewLine();
				$line .= $rowvhost['vh_ssl_tx'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
				
				// Global custom global vh entry
				$line .= "# Custom Global Settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom(ctrl_options::GetSystemOption('global_vhcustom')) . fs_filehandler::NewLine();
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
				
				############################## add back old code
				//$line .= $smarty->fetch("vhost_parked_ssl.tpl") . fs_filehandler::NewLine();
				
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
			}
			$line .= fs_filehandler::NewLine();
							
			# Write to file
			//writetofile($vh_path . $rowvhost['vh_name_vc']. ".conf"  , $line);
			#***********
			
		/*
		 * ##################################################
		 * #
		 * # Regular or Sub domain With PHP7/PHP-FPM MOD - PHP 7+
		 * #
		 * ##################################################
		 */
	
		#check
		#Domain is a regular domain or a subdomain with PHP MOD.
		} else {
		   
		    if ($rowvhost['vh_ssl_tx'] == null) {
		   
				# Create Apache Vhost direcory and log folders
				# Temp
				if ( !is_dir( ctrl_options::GetSystemOption('hosted_dir') . $vhostuser[ 'username' ] . "/tmp" ) ) {
					fs_director::CreateDirectory( ctrl_options::GetSystemOption( 'hosted_dir' ) . $vhostuser[ 'username' ] . "/tmp" );
				}
				# Logs
				if (!is_dir(ctrl_options::GetSystemOption('log_dir') . "domains/" . $vhostuser['username'] . "/")) {
					fs_director::CreateDirectory(ctrl_options::GetSystemOption('log_dir') . "domains/" . $vhostuser['username'] . "/");
				}
				###
				#START HERE
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "<virtualhost " . $vhostIp . ":" . $vhostPort . ">" . fs_filehandler::NewLine();
				// Server name, alias, email settings
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				// Document root
				$line .= 'DocumentRoot ' . '"' . $RootDir . '"' . fs_filehandler::NewLine();
				// PHP settings (open_basedir, temp and session folders) come with the account's PHP-FPM handler below
				// Logs
				if (!is_dir(ctrl_options::GetSystemOption('log_dir') . "domains/" . $vhostuser['username'] . "/")) {
					fs_director::CreateDirectory(ctrl_options::GetSystemOption('log_dir') . "domains/" . $vhostuser['username'] . "/");
				}
				$line .= 'ErrorLog "' . ctrl_options::GetSystemOption('log_dir') . "domains/" . $vhostuser['username'] . "/" . $rowvhost['vh_name_vc'] . '-error.log" ' . fs_filehandler::NewLine();
				$line .= 'CustomLog "' . ctrl_options::GetSystemOption('log_dir') . "domains/" . $vhostuser['username'] . "/" . $rowvhost['vh_name_vc'] . '-access.log" ' . SentoraOneLine(ctrl_options::GetSystemOption('access_log_format')) . fs_filehandler::NewLine();
				$line .= 'CustomLog "' . ctrl_options::GetSystemOption('log_dir') . "domains/" . $vhostuser['username'] . "/" . $rowvhost['vh_name_vc'] . '-bandwidth.log" ' . SentoraOneLine(ctrl_options::GetSystemOption('bandwidth_log_format')) . fs_filehandler::NewLine();
		
				// Directory options
				$line .= '<Directory ' . $RootDir . '>' . fs_filehandler::NewLine();
				// Symbolic links only when the link and its target have the same owner:
				// Apache can read every account, so following any link would let one
				// account serve another account's files. .htaccess may not turn that back
				// on (Options FollowSymLinks there gives an error; use SymLinksIfOwnerMatch).
				$line .= "    Options -Indexes +SymLinksIfOwnerMatch" . fs_filehandler::NewLine();
				$line .= "    AllowOverride AuthConfig FileInfo Indexes Limit Options=Indexes,MultiViews,SymLinksIfOwnerMatch" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				
				# Vhost PHP settings. For future use ( PHP Mod/FPM/Fcgi ) Coming soon!!!! :-)
				//if ($packageinfo['pk_enablephp_in'] <> 0) {
					# Test build PHP handler
					//$line .= BuildVhostPHPHandler($packageinfo['pk_enablephp_in'], $rowchost['vh_phphandler_id']);
				//}
		
				
				// Default PHP (mod_php) or the domain's chosen PHP-FPM version
				$line .= BuildVhostPhpHandler($rowvhost, $vhostuser) . fs_filehandler::NewLine();
		
				// Enable Gzip until we set this as an option , we might commenbt this too and allow manual switch
				$line .= "AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript" . fs_filehandler::NewLine();
				
				// Get Package php and cgi enabled options
				$rows = $zdbh->prepare("SELECT * FROM x_packages WHERE pk_id_pk=:packageid AND pk_deleted_ts IS NULL");
				$rows->bindParam(':packageid', $vhostuser['packageid']);
				$rows->execute();
				$packageinfo = $rows->fetch();
				
				# curently disabled because un secure
				# need correct cleaning in interface for full removal or in comment here until restoration
				#                if ( $packageinfo[ 'pk_enablecgi_in' ] <> 0 ) {
				#                     $line .= ctrl_options::GetSystemOption( 'cgi_handler' ) . fs_filehandler::NewLine();
				#                     if ( !is_dir( ctrl_options::GetSystemOption( 'hosted_dir' ) . $vhostuser[ 'username' ] . "/public_html" . $rowvhost[ 'vh_directory_vc' ] . "/_cgi-bin" ) ) {
				#                         fs_director::CreateDirectory( ctrl_options::GetSystemOption( 'hosted_dir' ) . $vhostuser[ 'username' ] . "/public_html" . $rowvhost[ 'vh_directory_vc' ] . "/_cgi-bin" );
				#                     }
				#                 }
				
				// Error documents:- Error pages are added automatically if they are found in the _errorpages directory
				// and if they are a valid error code, and saved in the proper format, i.e. <error_number>.html
				$errorpages = ctrl_options::GetSystemOption('hosted_dir') . $vhostuser['username'] . "/public_html" . $rowvhost['vh_directory_vc'] . "/_errorpages";
				if (is_dir($errorpages)) {
					if ($handle = opendir($errorpages)) {
						while (($file = readdir($handle)) !== false) {
							if ($file != "." && $file != "..") {
								$page = explode(".", $file);
								if (!fs_director::CheckForEmptyValue(CheckErrorDocument($page[0]))) {
									$line .= "ErrorDocument " . $page[0] . " /_errorpages/" . $page[0] . ".html" . fs_filehandler::NewLine();
								}
							}
						}
						closedir($handle);
					}
				}
		
				// Directory indexes
				$line .= SentoraOneLine(ctrl_options::GetSystemOption('dir_index')) . fs_filehandler::NewLine();
		
				// Global custom global vh entry
				$line .= "# Custom Global Settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom(ctrl_options::GetSystemOption('global_vhcustom')) . fs_filehandler::NewLine();
		
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
		
				// End Virtual Host Settings
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
				
				# Load template file into vhost config to save
				//$line .= $smarty->fetch("vhost_domain.tpl") . fs_filehandler::NewLine();
				
				# End Virtual Host Settings
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
				
				
				if ($rowvhost['vh_portforward_in'] <> 0) {
					$line .= fs_filehandler::NewLine();
					$line .= BuildVhostPortForward($rowvhost['vh_name_vc'], $vhostPort, $useremail);
				}
							
			# If vhost SSL_TX not null create spearate <virtualhost>
			} elseif ($rowvhost['vh_ssl_tx'] != null && $rowvhost['vh_ssl_port_in'] != null) {
				
				# Build HTTP to HTTPS Redirect
				$line .= BuildVhostReWriteSSL($rowvhost['vh_name_vc'], $useremail);
				
				# Build Vhost SSL section
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS SSL ENABLED" . fs_filehandler::NewLine();
				
				#START HERE
				//$line .= "<virtualhost " . $vhostIp . ":" . $vhostPort . ">" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . $vhostIp . ":" . $rowvhost['vh_ssl_port_in'] . ">" . fs_filehandler::NewLine();
		
				// Server name, alias, email settings
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				// Document root
		
				$line .= 'DocumentRoot ' . '"' . $RootDir . '"' . fs_filehandler::NewLine();
				// PHP settings (open_basedir, temp and session folders) come with the account's PHP-FPM handler below
				
				// Logs
				if (!is_dir(ctrl_options::GetSystemOption('log_dir') . "domains/" . $vhostuser['username'] . "/")) {
					fs_director::CreateDirectory(ctrl_options::GetSystemOption('log_dir') . "domains/" . $vhostuser['username'] . "/");
				}
				$line .= 'ErrorLog "' . ctrl_options::GetSystemOption('log_dir') . "domains/" . $vhostuser['username'] . "/" . $rowvhost['vh_name_vc'] . '-error.log" ' . fs_filehandler::NewLine();
				$line .= 'CustomLog "' . ctrl_options::GetSystemOption('log_dir') . "domains/" . $vhostuser['username'] . "/" . $rowvhost['vh_name_vc'] . '-access.log" ' . SentoraOneLine(ctrl_options::GetSystemOption('access_log_format')) . fs_filehandler::NewLine();
				$line .= 'CustomLog "' . ctrl_options::GetSystemOption('log_dir') . "domains/" . $vhostuser['username'] . "/" . $rowvhost['vh_name_vc'] . '-bandwidth.log" ' . SentoraOneLine(ctrl_options::GetSystemOption('bandwidth_log_format')) . fs_filehandler::NewLine();
		
				// Directory options
				$line .= '<Directory ' . $RootDir . '>' . fs_filehandler::NewLine();
				// Symbolic links only when the link and its target have the same owner:
				// Apache can read every account, so following any link would let one
				// account serve another account's files. .htaccess may not turn that back
				// on (Options FollowSymLinks there gives an error; use SymLinksIfOwnerMatch).
				$line .= "    Options -Indexes +SymLinksIfOwnerMatch" . fs_filehandler::NewLine();
				$line .= "    AllowOverride AuthConfig FileInfo Indexes Limit Options=Indexes,MultiViews,SymLinksIfOwnerMatch" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
		
				# Vhost PHP settings. For future use ( PHP Mod/FPM/Fcgi ) Coming soon!!!! :-)
				//if ($packageinfo['pk_enablephp_in'] <> 0) {
					# Test build PHP handler
					//$line .= BuildVhostPHPHandler($packageinfo['pk_enablephp_in'], $rowchost['vh_phphandler_id']);
				//}
				
				// Default PHP (mod_php) or the domain's chosen PHP-FPM version
				$line .= BuildVhostPhpHandler($rowvhost, $vhostuser) . fs_filehandler::NewLine();
		
				// Enable Gzip until we set this as an option , we might commenbt this too and allow manual switch
				$line .= "AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript" . fs_filehandler::NewLine();
				// Get Package php and cgi enabled options
				$rows = $zdbh->prepare("SELECT * FROM x_packages WHERE pk_id_pk=:packageid AND pk_deleted_ts IS NULL");
				$rows->bindParam(':packageid', $vhostuser['packageid']);
				$rows->execute();
				$packageinfo = $rows->fetch();
			
				# curently disabled because un secure
				# need correct cleaning in interface for full removal or in comment here until restoration
				#                if ( $packageinfo[ 'pk_enablecgi_in' ] <> 0 ) {
				#                     $line .= ctrl_options::GetSystemOption( 'cgi_handler' ) . fs_filehandler::NewLine();
				#                     if ( !is_dir( ctrl_options::GetSystemOption( 'hosted_dir' ) . $vhostuser[ 'username' ] . "/public_html" . $rowvhost[ 'vh_directory_vc' ] . "/_cgi-bin" ) ) {
				#                         fs_director::CreateDirectory( ctrl_options::GetSystemOption( 'hosted_dir' ) . $vhostuser[ 'username' ] . "/public_html" . $rowvhost[ 'vh_directory_vc' ] . "/_cgi-bin" );
				#                     }
				#                 }
		
				// Error documents:- Error pages are added automatically if they are found in the _errorpages directory
				// and if they are a valid error code, and saved in the proper format, i.e. <error_number>.html
				$errorpages = ctrl_options::GetSystemOption('hosted_dir') . $vhostuser['username'] . "/public_html" . $rowvhost['vh_directory_vc'] . "/_errorpages";
				if (is_dir($errorpages)) {
					if ($handle = opendir($errorpages)) {
						while (($file = readdir($handle)) !== false) {
							if ($file != "." && $file != "..") {
								$page = explode(".", $file);
								if (!fs_director::CheckForEmptyValue(CheckErrorDocument($page[0]))) {
									$line .= "ErrorDocument " . $page[0] . " /_errorpages/" . $page[0] . ".html" . fs_filehandler::NewLine();
								}
							}
						}
						closedir($handle);
					}
				}
		
				// Directory indexes
				$line .= SentoraOneLine(ctrl_options::GetSystemOption('dir_index')) . fs_filehandler::NewLine();
		
				# SSL Settings
				$line .= "# SSL settings (if any exist)" . fs_filehandler::NewLine();
				$line .= $rowvhost['vh_ssl_tx'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
		
				// Global custom global vh entry
				$line .= "# Custom Global Settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom(ctrl_options::GetSystemOption('global_vhcustom')) . fs_filehandler::NewLine();
		
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
		
				// End Virtual Host Settings
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
				
				############################## add back old code
				//$line .= $smarty->fetch("vhost_domain_ssl.tpl") . fs_filehandler::NewLine();
				
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
			}
			$line .= fs_filehandler::NewLine();
			
			#*********Write to file
			//writetofile($vh_path . $rowvhost['vh_name_vc']. ".conf"  , $line);
			#***********
		}
	
		/*
		 * ##################################################
		 * #
		 * # Disabled domain
		 * #
		 * ##################################################
		 */
		} else {
		# Domain is NOT enabled
		
			if ($rowvhost['vh_ssl_tx'] == null) {
		
				# Load template file into vhost cofig to save
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS BEEN DISABLED" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . $vhostIp . ":" . $vhostPort . ">" . fs_filehandler::NewLine();
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				//$line .= "ServerAlias www." . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('static_dir') . 'disabled"' . fs_filehandler::NewLine();
				$line .= '<Directory "' . ctrl_options::GetSystemOption('static_dir') . 'disabled">' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				$line .= "    AllowOverride All" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				$line .= SentoraOneLine(ctrl_options::GetSystemOption('dir_index')) . fs_filehandler::NewLine();
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
				
				############################## add back old code
				//$line .= $smarty->fetch("vhost_disabled.tpl") . fs_filehandler::NewLine();
				
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
			
			# If vhost SSL_TX not null create spearate <virtualhost>
			} elseif ( $rowvhost['vh_ssl_tx'] != NULL && $rowvhost['vh_ssl_port_in'] != NULL ) {
				
				# Build HTTP to HTTPS Redirect
				$line .= BuildVhostReWriteSSL($rowvhost['vh_name_vc'], $useremail);
				
				# Build Vhost SSL section
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS BEEN DISABLED & HAS SSL ENABLED" . fs_filehandler::NewLine();
				//$line .= "<virtualhost " . $vhostIp . ":" . $vhostPort . ">" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . $vhostIp . ":" . $rowvhost['vh_ssl_port_in'] . ">" . fs_filehandler::NewLine();
				
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				//$line .= "ServerAlias www." . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('static_dir') . 'disabled"' . fs_filehandler::NewLine();
				$line .= '<Directory "' . ctrl_options::GetSystemOption('static_dir') . 'disabled">' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				$line .= "    AllowOverride All" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				$line .= SentoraOneLine(ctrl_options::GetSystemOption('dir_index')) . fs_filehandler::NewLine();
				
				# SSL Settings
				$line .= "# SSL settings (if any exist)" . fs_filehandler::NewLine();
				$line .= $rowvhost['vh_ssl_tx'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
				
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= SentoraSafeCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
				
				############################## add back old code
				//$line .= $smarty->fetch("vhost_disabled_ssl.tpl") . fs_filehandler::NewLine();
				
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
			}
		
		#*********Write to file
		//writetofile($vh_path . $rowvhost['vh_name_vc']. ".conf"  , $line);
		#***********
		}
//}
		
	}
	
    /*
     * ##############################################################################################################
     * #
     * # Write vhost file to disk
     * #
     * ##############################################################################################################
    
	 */
	/*
    # write the vhost config file
    $vhconfigfile = ctrl_options::GetSystemOption('apache_vhost');
    if (fs_filehandler::UpdateFile($vhconfigfile, 0777, $line)) {
        # Reset Apache settings to reflect that config file has been written, until the next change.
        $time = time();
        $vsql = $zdbh->prepare("UPDATE x_settings
                                    SET so_value_tx=:time
                                    WHERE so_name_vc='apache_changed'");
        $vsql->bindParam(':time', $time);
        $vsql->execute();
        echo "Finished writting Apache Config... Now reloading Apache..." . fs_filehandler::NewLine();

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
    } else {
        return false;
    }
	*/
	
	# Check, Write config and Restart/Reload Webserver service if apache config passes check

	# Move current vhosts.conf file to backup incase Apache config test fails on new config
	$vhconfigfile = ctrl_options::GetSystemOption('apache_vhost');
	$BackupFileName = $vhconfigfile . "_bak_" . time();
	rename($vhconfigfile, $BackupFileName);
	
	# Write to new Apache vhosts.conf file
	WriteDataToFile($vhconfigfile, $line);
		
	# Check Apache vhosts file for errors
	echo "Checking Apache vhost config for errors..." . fs_filehandler::NewLine();
	# If Apache config check return 0 or False
	if ( CheckApacheVhostConfig() == FALSE ) {
		
		# Delete vhost backup file and Failed config if exists
		unlink($BackupFileName);
		
		# Delete httpd-vhost.conf_failed config file after apache config passes syntax check
		if (is_file($vhconfigfile . "-failed")) {
			unlink($vhconfigfile . "-failed");
		}
			
		# Restart Apache service
		RestartHttpdServices();
				
	} else {
		
		echo "   Error: Restoring orginal vhost file. Check in Sentora Panel Apache vhost config settings or httpd-vhosts.conf file for errors and retry." . fs_filehandler::NewLine();
		
		# If config failed. Copy file to (httpd-vhosts.conf_failed)
		fs_filehandler::CopyFile($vhconfigfile, $vhconfigfile . "-failed");
		
		# Restore orginal apache vhosts config file if failed.
		fs_filehandler::CopyFile($BackupFileName, $vhconfigfile);
		unlink($BackupFileName);
		
	}
}

function RestartHttpdServices() {
	   
    global $zdbh;
   
	# Reset Apache settings to reflect that config file has been written, until the next change.
	$time = time();
	$vsql = $zdbh->prepare("UPDATE x_settings
								SET so_value_tx=:time
								WHERE so_name_vc='apache_changed'");
	$vsql->bindParam(':time', $time);
	$vsql->execute();
	echo "Finished writting Apache Config... Now reloading Apache..." . fs_filehandler::NewLine();

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

function WriteDataToFile($panel, $line) {
	
	# Write the entire vhost string
	file_put_contents($panel , $line);
	
}

function CheckApacheVhostConfig() {
	
	# Check Apache vhost.conf for errors
	if (sys_versions::ShowOSPlatformVersion() == "Windows") {
		system("httpd -t " , $ConfigReturnValue); # NEEDS MORE TESTING
		
	} else { # Linux systems
		$command = "apachectl";
		$args = "configtest";
	// New domain log folders made above: root's and the panel's only, before Apache opens them
	sys_accountphp::LockSharedFolders();

		$ConfigReturnValue = ctrl_system::systemCommand($command, $args);
	}
	
	echo "   Apache vhost Config test " . (( 0 === $ConfigReturnValue ) ? "SUCEEDED" : "FAILED") . "." . fs_filehandler::NewLine();
	
	return $ConfigReturnValue;
}

function CheckErrorDocument($error) {
    $errordocs = array(100, 101, 102, 200, 201, 202, 203, 204, 205, 206, 207,
        300, 301, 302, 303, 304, 305, 306, 307, 400, 401, 402,
        403, 404, 405, 406, 407, 408, 409, 410, 411, 412, 413,
        414, 415, 416, 417, 418, 419, 420, 421, 422, 423, 424,
        425, 426, 500, 501, 502, 503, 504, 505, 506, 507, 508,
        509, 510);
    if (in_array($error, $errordocs)) {
        return true;
    } else {
        return false;
    }
}

function BackupVhostConfigFile() {
    echo "Apache VHost backups are enabled... Backing up current vhost.conf to: " . ctrl_options::GetSystemOption('apache_budir') . fs_filehandler::NewLine();
    if (!is_dir(ctrl_options::GetSystemOption('apache_budir'))) {
        fs_director::CreateDirectory(ctrl_options::GetSystemOption('apache_budir'));
    }
	
	# Vhost backup file name
	$CurrBackupVhostName = ctrl_options::GetSystemOption('apache_budir') . "VHOST_BACKUP_" . time();
	
	//copy(ctrl_options::GetSystemOption('apache_vhost'), ctrl_options::GetSystemOption('apache_budir') . "VHOST_BACKUP_" . time());
    copy(ctrl_options::GetSystemOption('apache_vhost'), $CurrBackupVhostName);
	
    fs_director::SetFileSystemPermissions(ctrl_options::GetSystemOption('apache_budir') . ctrl_options::GetSystemOption('apache_vhost') . ".BU", 0777);
	
    if (ctrl_options::GetSystemOption('apache_purgebu') == strtolower("true")) {
        echo "Apache VHost purges are enabled... Purging backups older than: " . ctrl_options::GetSystemOption('apache_purge_date') . " days..." . fs_filehandler::NewLine();
        echo "[FILE][PURGE_DATE][FILE_DATE][ACTION]" . fs_filehandler::NewLine();
        $purge_date = ctrl_options::GetSystemOption('apache_purge_date');
        if ($handle = @opendir(ctrl_options::GetSystemOption('apache_budir'))) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != "..") {
                    $filetime = @filemtime(ctrl_options::GetSystemOption('apache_budir') . $file);

                    if ($filetime == NULL) {
                        $filetime = @filemtime(mb_convert_encoding(ctrl_options::GetSystemOption('apache_budir') . $file, 'ISO-8859-1', 'UTF-8'));
                    }
                    $filetime = floor((time() - $filetime) / 86400);
                    echo $file . " - " . $purge_date . " - " . $filetime . "";
                    if ($purge_date < $filetime) {
                        #delete the file
                        echo " - Deleting file..." . fs_filehandler::NewLine();
                        unlink(ctrl_options::GetSystemOption('apache_budir') . $file);
                    } else {
                        echo " - Skipping file..." . fs_filehandler::NewLine();
                    }
                }
            }
        }
        echo "Purging old backups complete..." . fs_filehandler::NewLine();
    }
    echo "Apache backups complete..." . fs_filehandler::NewLine();
}
	   	   
#
# Error fucntion for all sites - Smarty template
#
function is_errorpages($type, $vhUser, $vhDir) {
	global $zdbh;
	
	# Set error pages location
	if ($type == 'cp') {
		# Return CP error pages				
		$errorpages = ctrl_options::GetSystemOption('sentora_root') . "/etc/static/errorpages";
	} elseif ($type == 'vh') {
		# Return VH error pages
		$errorpages = ctrl_options::GetSystemOption('hosted_dir') . $vhUser . '/public_html' . $vhDir . '/_errorpages';
	}
	
	if (is_dir($errorpages)) {
		if ($handle = opendir($errorpages)) {
			while (($file = readdir($handle)) !== false) {
				if ($file != "." && $file != "..") {
					$page = explode(".", $file);
					if (!fs_director::CheckForEmptyValue(CheckErrorDocument($page[0]))) {
						
						if ($type == 'cp') {				
							$loaderrorpages[] = "ErrorDocument " . $page[0] . " /etc/static/errorpages/" . $page[0] . ".html";
						} elseif ($type == 'vh') {
							$loaderrorpages[] = "ErrorDocument " . $page[0] . " /_errorpages/" . $page[0] . ".html";
						}
						
					}
				}
			}
			closedir($handle);
			return $loaderrorpages;				
		}
	}
}
	
function TriggerApacheQuotaUsage() {
    global $zdbh;
    global $controller;
    $sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_deleted_ts IS NULL");
    $sql->execute();
    while ($rowvhost = $sql->fetch()) {
        if ($rowvhost['vh_enabled_in'] == 1 && ctrl_users::CheckUserEnabled($rowvhost['vh_acc_fk']) ||
            $rowvhost['vh_enabled_in'] == 1 && ctrl_options::GetSystemOption('apache_allow_disabled') == strtolower("true")) {

            //$checksize = $zdbh->query("SELECT * FROM x_bandwidth WHERE bd_month_in = " . date("Ym") . " AND bd_acc_fk = " . $rowvhost['vh_acc_fk'] . "")->fetch();

            $date = date("Ym");
            $findsize = $zdbh->prepare("SELECT * FROM x_bandwidth WHERE bd_month_in = :date AND bd_acc_fk = :acc");
            $findsize->bindParam(':date', $date);
            $findsize->bindParam(':acc', $rowvhost['vh_acc_fk']);
            $findsize->execute();
            $checksize = $findsize->fetch();

            $currentuser = ctrl_users::GetUserDetail($rowvhost['vh_acc_fk']);
            if ($checksize['bd_diskover_in'] != $checksize['bd_diskcheck_in'] && $checksize['bd_diskover_in'] == 1) {
                echo "Disk usage over quota, triggering Apache..." . fs_filehandler::NewLine();
                $updateapache = $zdbh->prepare("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc ='apache_changed'");
                $updateapache->execute();

                //$updateapache = $zdbh->query("UPDATE x_bandwidth SET bd_diskcheck_in = 1 WHERE bd_acc_fk =" . $rowvhost['vh_acc_fk'] . "");
                $updateapache2 = $zdbh->prepare("UPDATE x_bandwidth SET bd_diskcheck_in = 1 WHERE bd_acc_fk = :acc");
                $updateapache2->bindParam(':acc', $rowvhost['vh_acc_fk']);
                $updateapache2->execute();
            }
            if ($checksize['bd_diskover_in'] != $checksize['bd_diskcheck_in'] && $checksize['bd_diskover_in'] == 0) {
                echo "Disk usage under quota, triggering Apache..." . fs_filehandler::NewLine();
                $updateapache = $zdbh->prepare("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc ='apache_changed'");
                $updateapache->execute();

                //$updateapache = $zdbh->query("UPDATE x_bandwidth SET bd_diskcheck_in = 0 WHERE bd_acc_fk =" . $rowvhost['vh_acc_fk'] . "");
                $updateapache2 = $zdbh->prepare("UPDATE x_bandwidth SET bd_diskcheck_in = 0 WHERE bd_acc_fk = :acc");
                $updateapache2->bindParam(':acc', $rowvhost['vh_acc_fk']);
                $updateapache2->execute();
            }
            if ($checksize['bd_transover_in'] != $checksize['bd_transcheck_in'] && $checksize['bd_transover_in'] == 1) {
                echo "Bandwidth usage over quota, triggering Apache..." . fs_filehandler::NewLine();
                $updateapache = $zdbh->prepare("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc ='apache_changed'");
                $updateapache->execute();

                //$updateapache = $zdbh->query("UPDATE x_bandwidth SET bd_transcheck_in = 1 WHERE bd_acc_fk =" . $rowvhost['vh_acc_fk'] . "");
                $updateapache2 = $zdbh->prepare("UPDATE x_bandwidth SET bd_transcheck_in = 1 WHERE bd_acc_fk = :acc");
                $updateapache2->bindParam(':acc', $rowvhost['vh_acc_fk']);
                $updateapache2->execute();
            }
            if ($checksize['bd_transover_in'] != $checksize['bd_transcheck_in'] && $checksize['bd_transover_in'] == 0) {
                echo "Bandwidth usage under quota, triggering Apache..." . fs_filehandler::NewLine();
                $updateapache = $zdbh->prepare("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc ='apache_changed'");
                $updateapache->execute();

                //$updateapache = $zdbh->query("UPDATE x_bandwidth SET bd_transcheck_in = 0 WHERE bd_acc_fk =" . $rowvhost['vh_acc_fk'] . "");
                $updateapache2 = $zdbh->prepare("UPDATE x_bandwidth SET bd_transcheck_in = 0 WHERE bd_acc_fk = :acc");
                $updateapache2->bindParam(':acc', $rowvhost['vh_acc_fk']);
                $updateapache2->execute();
            }
        }
    }
}

?>
