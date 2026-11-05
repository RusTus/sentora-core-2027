<?php

/**
 *
 * ZPanel - AutoIP, written by RusTus: www.zpanelcp.com.
 *
 */
 
class module_controller {

	static $ok;
	static $bad;

	/**
	 * AutoIP rewrites the server's IP and every customer's DNS records, and the daemon
	 * (root) runs its script: administrators only, whatever the module's permissions say
	 */
	static function IsAdmin() {
		$user = ctrl_users::GetUserDetail();
		return $user && (int) $user['usergroupid'] === 1;
	}

	/** an http(s) address for the "what is my IP" service */
	static function ValidCommand($url) {
		$url = trim((string) $url);
		return $url !== '' && strlen($url) <= 255 && filter_var($url, FILTER_VALIDATE_URL)
			&& preg_match('#^https?://[^\s\'"<>]+$#i', $url);
	}

	/** the script: empty, or the full path of a file (no arguments, no shell characters) */
	static function ValidScript($path) {
		$path = trim((string) $path);
		return $path === '' || (strlen($path) <= 255 && preg_match('#^/[A-Za-z0-9._/-]+$#', $path) && strpos($path, '..') === false);
	}

    static function ListAutoIPSettings() {
        global $zdbh;
        if (!self::IsAdmin()) {
            return false;
        }
        $sql = "SELECT * FROM x_autoip";
        $numrows = $zdbh->query($sql);
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->execute();
            while ($row = $sql->fetch()) {
				if ($row['ai_lastupdate_ts'] == NULL){
					$lastupdate = "NEVER";
				} else {
					$lastupdate = date(ctrl_options::GetOption('sentora_df'), $row['ai_lastupdate_ts']);
				}
				if ($row['ai_enabled_in'] == 1){
					$ischecked = "CHECKED";
				} else {
					$ischecked = "";
				}
                array_push($res, array('ai_script_vc'     => $row['ai_script_vc'],
									   'ai_email_vc'      => $row['ai_email_vc'],
									   'ai_command_vc'    => $row['ai_command_vc'],
									   'ai_newip_vc'      => $row['ai_newip_vc'],
									   'ai_oldip_vc'      => ctrl_options::GetOption('server_ip'),
									   'ai_enabled_in'    => $row['ai_enabled_in'],
									   'ischecked'        => $ischecked,
                    				   'ai_lastupdate_ts' => $lastupdate));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ExecuteUpdateAutoIP($inScript, $inEmail, $inCommand, $inEnabled) {
        global $zdbh;
        if (!self::IsAdmin()) {
            return false;
        }
        $inScript = trim((string) $inScript);
        $inCommand = trim((string) $inCommand);
        $emails = array();
        foreach (explode(',', strtolower(str_replace(' ', '', (string) $inEmail))) as $email) {
            if ($email === '') {
                continue;
            }
            if (!self::IsValidEmail($email)) {
                self::$bad = true;
                return false;
            }
            $emails[] = $email;
        }
        if (!self::ValidCommand($inCommand) || !self::ValidScript($inScript)) {
            self::$bad = true;
            return false;
        }
        $sql = $zdbh->prepare("UPDATE x_autoip SET ai_script_vc = :script, ai_command_vc = :command,
                               ai_enabled_in = :enabled, ai_email_vc = :email, ai_lastupdate_ts = :ts WHERE ai_id_pk = 1");
        $sql->execute(array(':script' => $inScript, ':command' => $inCommand, ':enabled' => $inEnabled ? 1 : 0,
                            ':email' => implode(',', $emails), ':ts' => (string) time()));
        self::$ok = true;
        return true;
    }

    static function IsValidEmail($email) {
        if (!preg_match('/^[a-z0-9]+([_\\.-][a-z0-9]+)*@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i', $email)) {
            return false;
        }
        return true;
    }

    static function getInstallDatabase() {
		global $zdbh;
		include(ctrl_options::GetOption('sentora_root') . '/cnf/db.php');
		$sql = "SELECT COUNT(*)
				FROM information_schema.tables 
				WHERE table_schema = '".$dbname."' 
				AND table_name = 'x_autoip'";
        $numrows = $zdbh->query($sql);
        if ($numrows->fetchColumn() == 0) {
		$sql = $zdbh->prepare("CREATE TABLE `x_autoip` (
  								`ai_id_pk` int(6) NOT NULL DEFAULT '0',
								`ai_script_vc` varchar(255) DEFAULT NULL,
								`ai_email_vc` varchar(255) DEFAULT NULL,
								`ai_command_vc` varchar(255) DEFAULT NULL,
								`ai_newip_vc` varchar(50) DEFAULT NULL,
								`ai_oldip_vc` varchar(50) DEFAULT NULL,
								`ai_enabled_in` int(1) DEFAULT '1',
								`ai_lastupdate_ts` varchar(50) DEFAULT NULL,
								PRIMARY KEY (`ai_id_pk`)
								);");
		$sql->execute();
        $sql = $zdbh->prepare("INSERT INTO `x_autoip` VALUES ('1', null, null, 'http://myip.dnsomatic.com/', null, null, '1', null)");
        $sql->execute();
		}
    }

    static function getAutoIPSettings() {
        $settings = self::ListAutoIPSettings();
        if (!fs_director::CheckForEmptyValue($settings)) {
            return $settings;
        } else {
            return false;
        }
    }
	
    static function getDetectedIP() {
		global $zdbh;
		$error = "<font color=\"red\">IP NOT DETECTED</font>";
		if (!self::IsAdmin()) {
			return '';
		}
		$getip = $zdbh->query("SELECT ai_command_vc FROM x_autoip")->fetch();
        if ($getip && self::ValidCommand($getip['ai_command_vc'])) {
			$detectedip = NULL;
			try {
				$detectedip = @file_get_contents($getip['ai_command_vc'], false, stream_context_create(array('http' => array('timeout' => 5))));
			} catch (Exception $e) {
				return $error;
			}
			$regexp = "^\b(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b^";
			$matchresult = preg_match_all($regexp, $detectedip, $matches);
			if(!empty($matchresult)){
				//print_r($matches);
				$detectedip = $matches[0][0];
			} else {
				return $error;
			}
			if (filter_var($detectedip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)){
				return $detectedip;
			} else {
				return $error;
			}
		} else {
			return $error;
		}
    }

    static function getStatusIcon() {
		global $zdbh;
		if (!self::IsAdmin()) {
			return '';
		}
		global $controller;
		$enabled  = ui_status::Ok('Enabled');
		$disabled = ui_status::Bad('Disabled');
		$caution  = ui_status::Warn('Check the settings');
		$getenabled = $zdbh->query("SELECT * FROM x_autoip")->fetch();
        if ($getenabled) {
			if ($getenabled['ai_enabled_in'] == 1){
				if (self::getDetectedIP() == ctrl_options::GetOption('server_ip')){
					return $enabled;
				} else {
					if (self::getDetectedIP() == "<font color=\"red\">IP NOT DETECTED</font>"){
						return $caution;
					}
					return $caution;
				}
			} else {
				return $disabled;
			}
		} else {
			return $disabled;
		}
    }

    static function getStatusMessage() {
		global $zdbh;
		if (!self::IsAdmin()) {
			return '';
		}
		global $controller;
		$enabled  = "<font color=\"green\">AutoIP is ENABLED and IP Address is in sync with reported public IP</font>";
		$disabled = "<font color=\"red\">AutoIP is DISABLED</font>";
		$error    = "<font color=\"red\">AutoIP cannot determine Public IP Address.  IP will NOT be updated.</font>";
		$caution  = "<font color=\"orange\">AutoIP is ENABLED but IP Address is different than reported public IP.<br>IP address will be updated on next daemon run.</font>";
		$getenabled = $zdbh->query("SELECT * FROM x_autoip")->fetch();
        if ($getenabled) {
			if ($getenabled['ai_enabled_in'] == 1){
				if (self::getDetectedIP() == ctrl_options::GetOption('server_ip')){
					return $enabled;
				} else {
					if (self::getDetectedIP() == "<font color=\"red\">IP NOT DETECTED</font>"){
						return $error;
					}
					return $caution;
				}
			} else {
				return $disabled;
			}
		} else {
			return $disabled;
		}
    }

    static function doupdateautoip() {
		global $controller;
		runtime_csfr::Protect();
		if (!self::IsAdmin()) {
			return false;
		}
        $formvars = $controller->GetAllControllerRequests('FORM');
        if(isset($formvars['inUpdate'])){
			if(isset($formvars['inEnabled'])){
				$enabled = fs_director::GetCheckboxValue($formvars['inEnabled']);
			} else {
				$enabled = 0;
			}
        	self::ExecuteUpdateAutoIP($formvars['inScript'], $formvars['inEmail'], $formvars['inCommand'], $enabled);
		} else {
        	return false;
		}
    }
			
	static function getDescription() {
			return ui_module::GetModuleDescription();
    }

	static function getModuleName() {
		$module_name = ui_module::GetModuleName();
        return $module_name;
    }

	static function getModuleIcon() {
		global $controller;
		$module_icon = "/modules/" . $controller->GetControllerRequest('URL', 'module') . "/assets/icon.png";
        return $module_icon;
    }

    static function getResult() {
        if (!self::IsAdmin()) {
            return ui_sysmessage::shout(ui_language::translate("Only administrators can see or change the AutoIP settings."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$bad)) {
            return ui_sysmessage::shout(ui_language::translate("Not saved: the command must be an http(s) address, the script the full path of a file (no arguments), and each email address valid."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your settings have been saved successfully!"), "zannounceok");
        }
        return;
    }

}
?>