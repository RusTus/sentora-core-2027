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
class module_controller extends ctrl_module {

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
                array_push($res, array('ai_script_vc' => htmlspecialchars((string) $row['ai_script_vc'], ENT_QUOTES, 'UTF-8'),
									   'ai_email_vc' => htmlspecialchars((string) $row['ai_email_vc'], ENT_QUOTES, 'UTF-8'),
									   'ai_command_vc' => htmlspecialchars((string) $row['ai_command_vc'], ENT_QUOTES, 'UTF-8'),
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
        // (ai_lastupdate_ts is the time of the last IP change, set by the daemon: not touched here)
        $sql = $zdbh->prepare("UPDATE x_autoip SET ai_script_vc = :script, ai_command_vc = :command,
                               ai_enabled_in = :enabled, ai_email_vc = :email WHERE ai_id_pk = 1");
        $sql->execute(array(':script' => $inScript, ':command' => $inCommand, ':enabled' => $inEnabled ? 1 : 0,
                            ':email' => implode(',', $emails)));
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
	

    // --- the page -------------------------------------------------------------------------

    /**
     * Escape text for HTML.
     *
     * @param mixed $x The text.
     * @return string
     */
    static function h($x)
    {
        return htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translated and HTML-escaped text.
     *
     * @param string $x The English text.
     * @return string
     */
    static function t($x)
    {
        return self::h(ui_language::translate($x));
    }

    /**
     * The public IPv4 address this server is seen from, asked as the daemon asks: the
     * configured service first, then three others. Asked once per page.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array{0: string, 1: string} The address ('' when no service answered) and the service that answered.
     */
    static function Detect()
    {
        global $zdbh;
        static $found = null;
        if ($found !== null) {
            return $found;
        }
        $row = $zdbh->query("SELECT ai_command_vc FROM x_autoip")->fetch();
        $own = ($row && self::ValidCommand($row['ai_command_vc'])) ? trim((string) $row['ai_command_vc']) : 'https://api.ipify.org';
        $ctx = stream_context_create(array('http' => array('timeout' => 4), 'https' => array('timeout' => 4)));
        foreach (array_unique(array($own, 'https://api.ipify.org', 'https://ipv4.icanhazip.com', 'https://checkip.amazonaws.com')) as $url) {
            $answer = (string) @file_get_contents($url, false, $ctx, 0, 4096);
            if (preg_match('/\b((?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3})\b/', $answer, $m)
                && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $found = array($m[1], $url);
            }
        }
        return $found = array('', '');
    }

    /**
     * Template HTML: the state (in sync, about to change, not detected or off), the panel's
     * IP beside the detected public one, and the last change.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string HTML.
     */
    static function getStatus()
    {
        global $zdbh;
        if (!self::IsAdmin()) {
            return '';
        }
        $row = $zdbh->query("SELECT * FROM x_autoip")->fetch();
        $on = $row && (int) $row['ai_enabled_in'] === 1;
        $panel = (string) ctrl_options::GetSystemOption('server_ip');
        list($ip, $via) = self::Detect();
        if (!$on) {
            $state = array('off', 'AutoIP is off', 'The panel keeps its IP address as it is. Switch AutoIP on below to follow the public address.');
        } elseif ($ip === '') {
            $state = array('bad', 'The public IP address can not be found', 'None of the IP services answered from this server. Nothing is changed until one does: check the address of the service below, and that the server can reach the internet.');
        } elseif ($ip === $panel) {
            $state = array('ok', 'In sync', 'The panel\'s IP address is the public one.');
        } else {
            $state = array('warn', 'The IP address will be updated', 'The public address is not the panel\'s. Within 5 minutes the daemon sets the new one for the panel and in every DNS record that has the old one.');
        }
        $ico = array('off' => '<circle cx="12" cy="12" r="9"/><path d="M9 12h6"/>', 'bad' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
            'ok' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>', 'warn' => '<path d="M20 12a8 8 0 1 1-2.3-5.7L20 8.5"/><path d="M20 3.5v5h-5"/>');
        $svg = function ($p) { return '<svg class="ai-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>'; };
        $out = '<div class="ai-state ' . $state[0] . '">' . $svg($ico[$state[0]]) . '<div><b>' . self::t($state[1]) . '</b><span>' . self::t($state[2]) . '</span></div></div>';
        $out .= '<div class="ai-ips"><div class="ai-ip"><span>' . self::t('The panel\'s IP address') . '</span><b>' . self::h($panel !== '' ? $panel : '–') . '</b><small>' . self::t('Used for the panel and new DNS records') . '</small></div>'
            . '<div class="ai-arrow' . ($ip !== '' && $ip !== $panel ? ' diff' : '') . '" aria-hidden="true">' . ($ip !== '' && $ip === $panel ? '=' : '&larr;') . '</div>'
            . '<div class="ai-ip"><span>' . self::t('Public IP address now') . '</span><b>' . self::h($ip !== '' ? $ip : '–') . '</b><small>'
            . ($via !== '' ? self::t('as seen by') . ' ' . self::h(parse_url($via, PHP_URL_HOST)) : self::t('not found')) . '</small></div></div>';
        if ($row && (string) $row['ai_lastupdate_ts'] !== '' && (string) $row['ai_newip_vc'] !== '') {
            $out .= '<p class="ai-last">' . self::t('Last change') . ': <b>' . self::h($row['ai_oldip_vc']) . '</b> &rarr; <b>' . self::h($row['ai_newip_vc']) . '</b>, '
                . self::h(date('Y-m-d H:i', (int) $row['ai_lastupdate_ts'])) . '</p>';
        } else {
            $out .= '<p class="ai-last">' . self::t('AutoIP has not changed the IP address yet.') . '</p>';
        }
        return $out;
    }

    /**
     * Template HTML: a few IP services to pick from (they fill the service field).
     *
     * @return string HTML.
     */
    static function getServices()
    {
        $out = '';
        foreach (array('https://api.ipify.org', 'https://ipv4.icanhazip.com', 'https://checkip.amazonaws.com', 'http://myip.dnsomatic.com/') as $u) {
            $out .= '<button type="button" class="ai-chip" data-url="' . self::h($u) . '">' . self::h(parse_url($u, PHP_URL_HOST)) . '</button>';
        }
        return $out;
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