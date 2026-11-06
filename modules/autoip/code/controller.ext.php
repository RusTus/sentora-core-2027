<?php

/**
 *
 * ZPanel - AutoIP, written by RusTus: www.zpanelcp.com.
 *
 */
 
/**
 * AutoIP module: controller.
 *
 * Lets an administrator set how the server's public IP is detected (an http(s)
 * "what is my IP" address), the script and emails run on a change, and switch it on
 * or off. The daemon hook does the update.
 */
class module_controller {

	/** @var bool|null True when the settings were saved (shown by getResult()). */
	static $ok;
	/** @var bool|null True when a setting was not valid and nothing was saved. */
	static $bad;

	/**
	 * Is the logged-in user an administrator (group 1)?
	 *
	 * AutoIP rewrites the server's IP and every customer's DNS records, and the daemon
	 * (root) runs its script: administrators only, whatever the module's permissions say
	 * @return bool
	 */
	static function IsAdmin() {
		$user = ctrl_users::GetUserDetail();
		return $user && (int) $user['usergroupid'] === 1;
	}

	/**
	 * Is $url an http(s) address for the "what is my IP" service?
	 *
	 * @param mixed $url The address from the form or the database.
	 * @return bool
	 */
	static function ValidCommand($url) {
		$url = trim((string) $url);
		return $url !== '' && strlen($url) <= 255 && filter_var($url, FILTER_VALIDATE_URL)
			&& preg_match('#^https?://[^\s\'"<>]+$#i', $url);
	}

	/**
	 * Is $path a valid script: empty, or the full path of a file (no arguments, no shell characters)?
	 *
	 * @param mixed $path The path from the form.
	 * @return bool
	 */
	static function ValidScript($path) {
		$path = trim((string) $path);
		return $path === '' || (strlen($path) <= 255 && preg_match('#^/[A-Za-z0-9._/-]+$#', $path) && strpos($path, '..') === false);
	}

    /**
     * Return the AutoIP settings row for the template.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>>|false One row: 'ai_script_vc', 'ai_email_vc', 'ai_command_vc', 'ai_newip_vc', 'ai_oldip_vc' (the current server_ip), 'ai_enabled_in', 'ischecked' ('CHECKED' or ''), 'ai_lastupdate_ts' (formatted date or 'NEVER'); false for non-administrators or when there is no row.
     */
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

    /**
     * Save the AutoIP settings (row 1 of x_autoip) after checking them.
     *
     * Sets $bad when a value is not valid, $ok when saved.
     * @global db_driver $zdbh The panel's database connection.
     * @param string $inScript Full path of the script to run on an IP change, or ''.
     * @param string $inEmail Comma separated email addresses, or ''.
     * @param string $inCommand The http(s) address that reports the public IP.
     * @param int|bool $inEnabled Truthy to switch AutoIP on.
     * @return bool True when saved.
     */
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

    /**
     * Is $email a valid email address?
     *
     * @param string $email The address.
     * @return bool
     */
    static function IsValidEmail($email) {
        if (!preg_match('/^[a-z0-9]+([_\\.-][a-z0-9]+)*@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i', $email)) {
            return false;
        }
        return true;
    }

    /**
     * Create the x_autoip table with its default row if it does not exist (template: <@ InstallDatabase @>).
     *
     * Reads $dbname from cnf/db.php.
     * @global db_driver $zdbh The panel's database connection.
     * @return void Prints nothing; called only for its side effect.
     */
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

    /**
     * Return the settings for <% if/loop AutoIPSettings %>.
     *
     * @return array<int, array<string, mixed>>|false See ListAutoIPSettings().
     */
    static function getAutoIPSettings() {
        $settings = self::ListAutoIPSettings();
        if (!fs_director::CheckForEmptyValue($settings)) {
            return $settings;
        } else {
            return false;
        }
    }
	
    /**
     * Ask the configured address for this server's public IP (template: <@ DetectedIP @>).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The detected public IPv4 address, an "IP NOT DETECTED" HTML message, or '' for non-administrators.
     */
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

    /**
     * Return the status icon (template: <@ StatusIcon @>).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller. Declared but not used.
     * @return string Icon HTML (enabled and in sync, check the settings, disabled), or '' for non-administrators.
     */
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

    /**
     * Return the status message (template: <@ StatusMessage @>).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller. Declared but not used.
     * @return string HTML message, or '' for non-administrators.
     */
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

    /**
     * Handle the settings form (POST, inUpdate): save the settings.
     *
     * Checks the CSFR token (dies when it is wrong).
     * @global runtime_controller $controller The request controller.
     * @return false|null False when not an administrator or inUpdate is missing; null after a save attempt (see $ok/$bad).
     */
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
			
	/**
	 * Return the module description (template: <@ Description @>).
	 *
	 * @return string
	 */
	static function getDescription() {
			return ui_module::GetModuleDescription();
    }

	/**
	 * Return the module name (template: <@ ModuleName @>).
	 *
	 * @return string
	 */
	static function getModuleName() {
		$module_name = ui_module::GetModuleName();
        return $module_name;
    }

	/**
	 * Return the module's icon URL (template: <@ ModuleIcon @>).
	 *
	 * @global runtime_controller $controller The request controller.
	 * @return string An absolute path: /modules/<module>/assets/icon.png.
	 */
	static function getModuleIcon() {
		global $controller;
		$module_icon = "/modules/" . $controller->GetControllerRequest('URL', 'module') . "/assets/icon.png";
        return $module_icon;
    }

    /**
     * Return the message for the last action (template: <@ Result @>).
     *
     * @return string|null The HTML message, or null when there is none.
     */
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