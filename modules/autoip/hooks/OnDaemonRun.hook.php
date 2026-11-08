<?php

/**
 *
 * ZPanel - AutoIP, written by RusTus: www.zpanelcp.com.
 *
 * Updated by RusTus, 2026-11-06
 */

	echo fs_filehandler::NewLine() . "START AutoIP Update Hook." . fs_filehandler::NewLine();
	// A server setting: only Administrators see the module (older installs gave it to
	// resellers and clients too; the module refuses them either way)
	global $zdbh;
	$zdbh->exec("DELETE p FROM x_permissions p JOIN x_modules m ON m.mo_id_pk = p.pe_module_fk WHERE m.mo_folder_vc = 'autoip' AND p.pe_group_fk <> 1");
		if (ui_module::CheckModuleEnabled('AutoIP Updater')){
			UpdateServerIPHook();
		} else {
			echo "AutoIP Module DISABLED." . fs_filehandler::NewLine();
		}
	echo "END AutoIP Update Hook." . fs_filehandler::NewLine();
	
	/**
	 * Detect the public IP and, when it changed, update server_ip and the DNS records.
	 *
	 * Asks the configured service (then a few public ones) for the IPv4 address. On a
	 * change it updates x_settings, x_dns and x_autoip, flags the DNS for rewriting,
	 * emails the alert addresses and runs the configured script (only when
	 * AutoIPScriptIsSafe()). Prints progress for the daemon log.
	 * @global db_driver $zdbh The panel's database connection.
	 * @return void
	 */
	function UpdateServerIPHook() {
		global $zdbh;
		$oldip = $zdbh->query("SELECT * FROM x_settings WHERE so_name_vc='server_ip'")->Fetch();
		if ($oldip){
			$autoipsettings = $zdbh->query("SELECT * FROM x_autoip")->fetch();
        	if ($autoipsettings) {
				if ($autoipsettings['ai_enabled_in'] == 1){
					// Try the configured service first, then fall back to others: a single
					// service that is down or rate limiting (HTTP 429) left the IP empty
					$detectedip = '';
					$ctx = stream_context_create(array('http' => array('timeout' => 10)));
					// only an http(s) address (not a local file or another kind of stream)
					$own = (string) $autoipsettings['ai_command_vc'];
					if (!preg_match('#^https?://[^\s\'"<>]+$#i', $own)) {
						$own = 'https://api.ipify.org';
					}
					foreach (array_unique(array($own, 'https://api.ipify.org', 'https://ipv4.icanhazip.com', 'https://checkip.amazonaws.com')) as $ipservice) {
						$answer = trim((string) @file_get_contents($ipservice, false, $ctx));
						if (filter_var($answer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
							$detectedip = $answer;
							break;
						}
					}
					$regexp = "^\b(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b^";
					$matchresult = preg_match_all($regexp, $detectedip, $matches);
					if(!empty($matchresult)){
						$detectedip = $matches[0][0];
					}
					echo "AutoIP ENABLED..." . fs_filehandler::NewLine();
					echo "Checking if Server IP has changed..." . fs_filehandler::NewLine();
					echo "Current  IP is: " . $oldip['so_value_tx'] . fs_filehandler::NewLine();
					echo "Reported IP is: " . $detectedip . fs_filehandler::NewLine();
					echo "(Using command: '" . $autoipsettings['ai_command_vc'] . "')" . fs_filehandler::NewLine();
					if ($detectedip != $oldip['so_value_tx']){
						if (filter_var($detectedip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)){
							echo "IP Address has changed: Updating server IP and all DNS zone records now..." . fs_filehandler::NewLine();
			        		// bound values, not pasted into the SQL
			        		$zdbh->prepare("UPDATE x_settings SET so_value_tx = :new WHERE so_name_vc = 'server_ip'")->execute(array(':new' => $detectedip));
			        		$zdbh->prepare("UPDATE x_dns SET dn_target_vc = :new WHERE dn_target_vc = :old")->execute(array(':new' => $detectedip, ':old' => (string) $oldip['so_value_tx']));
			        		$zdbh->prepare("UPDATE x_autoip SET ai_newip_vc = :new, ai_oldip_vc = :old, ai_lastupdate_ts = :ts WHERE ai_id_pk = 1")
			        			->execute(array(':new' => $detectedip, ':old' => (string) $oldip['so_value_tx'], ':ts' => (string) time()));
							TriggerDNSUpdateAutoIPHook("0");
							//TriggerApacheUpdateAutoIPHook();
							if ($autoipsettings['ai_email_vc'] != ""){
								include(ctrl_options::GetOption('sentora_root') . 'dryden/sys/email.class.php');
								$emails = explode(",", $autoipsettings['ai_email_vc']);
								foreach ($emails as $email){
									if (IsValidAutoIPEmail($email)){
										echo "Sending alert email to: " . $email . fs_filehandler::NewLine();
										$emailsubject = "AutoIP Alert";
										$emailbody = "The IP address of your Sentora panel has changed.\r\rOld IP: ".$oldip['so_value_tx']."\rNew IP: ".$detectedip."\r\rAutoIP has updated your settings.";
									    $phpmailer = new sys_email();
							        	$phpmailer->Subject = $emailsubject;
								        $phpmailer->Body = $emailbody;
								        $phpmailer->AddAddress($email);
								        $phpmailer->SendEmail();
									} else{
										echo "WARNING - Email: " . $email . " is not a valid email. Skipping..." . fs_filehandler::NewLine();
									}
								}
							}
							if ($autoipsettings['ai_script_vc'] != ""){
								$script = (string) $autoipsettings['ai_script_vc'];
								if (AutoIPScriptIsSafe($script)) {
									echo "Running script: " . $script . fs_filehandler::NewLine();
									@exec(escapeshellarg($script) . ' ' . escapeshellarg($detectedip) . ' ' . escapeshellarg($oldip['so_value_tx']) . ' > /dev/null 2>&1');
								} else {
									echo "WARNING - not running " . $script . ": the script must be a file owned by root that only root may change, named by its full path." . fs_filehandler::NewLine();
								}
							}
						}
					} else {
						echo "IP Address has NOT changed." . fs_filehandler::NewLine();
					}
				} else {
					echo "AutoIP DISABLED..." . fs_filehandler::NewLine();
				}			
			}			
		}
    }

   	/**
   	 * Add a zone ID to the dns_hasupdates setting so the DNS module rewrites it.
   	 *
   	 * @global db_driver $zdbh The panel's database connection.
   	 * @global runtime_controller $controller The request controller. Declared but not used.
   	 * @param int|string $id The domain ID ("0" = all zones).
   	 * @return true|null True when the ID was added, null when it was already listed.
   	 */
   	function TriggerDNSUpdateAutoIPHook($id) {
		global $zdbh;
        global $controller;
        $GetRecords = ctrl_options::GetOption('dns_hasupdates');
		$records = explode(",", $GetRecords);
		foreach ($records as $record){
			$RecordArray[] = $record;
		}
		if (!in_array($id, $RecordArray)){	
        	$newlist = $GetRecords . "," . $id;
	        $newlist = str_replace(",,", ",", $newlist);
	        $sql = "UPDATE x_settings SET so_value_tx='" . $newlist . "' WHERE so_name_vc='dns_hasupdates'";
			$sql = $zdbh->prepare($sql);
			$sql->execute();
	        return true;
		}
    }

    /**
     * Set apache_changed to 'true' so the daemon rewrites the Apache config (not used at present).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    function TriggerApacheUpdateAutoIPHook() {
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_settings
								SET so_value_tx='true'
								WHERE so_name_vc='apache_changed'");
        $sql->execute();
    }

	/**
	 * Run a command in the background (not used at present).
	 *
	 * @param string $cmd The command line, already escaped.
	 * @return void
	 */
	function execAutoIPScriptInBackground($cmd) {
	    if (sys_versions::ShowOSPlatformVersion() == "Windows"){
	        pclose(popen("start /B ". $cmd, "r")); 
	    }
	    else {
	        exec($cmd . " > /dev/null &");  
	    }
	}

    /**
     * May the daemon run this script?
     *
     * The daemon runs as root: the script must be a plain file (not a link) named by its
     * full path, owned by root, executable, and not writable by anyone else, in folders
     * only root may change (otherwise whoever can write it runs commands as root)
     * @param string $path The script's full path.
     * @return bool
     */
    function AutoIPScriptIsSafe($path) {
        if (!preg_match('#^/[A-Za-z0-9._/-]+$#', $path) || strpos($path, '..') !== false) {
            return false;
        }
        clearstatcache();
        if (is_link($path) || !is_file($path) || !is_executable($path)) {
            return false;
        }
        $dir = $path;
        do {
            $st = @lstat($dir);
            if (!$st || $st['uid'] !== 0 || ($st['mode'] & 0022) || (($st['mode'] & 0170000) === 0120000)) {
                return false;
            }
            $dir = dirname($dir);
        } while ($dir !== '/' && $dir !== '.');
        $st = @lstat('/');
        return $st && $st['uid'] === 0 && !($st['mode'] & 0022);
    }

    /**
     * Is $email a valid email address?
     *
     * @param string $email The address.
     * @return bool
     */
    function IsValidAutoIPEmail($email) {
        if (!preg_match('/^[a-z0-9]+([_\\.-][a-z0-9]+)*@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i', $email)) {
            return false;
        }
        return true;
    }
?>