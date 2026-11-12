<?php
/**
	* Controller for sencrypt module for sentora version 2.0.0
	* Version : 3.0.0
	* Author : TGates
	* Additional work by Diablo925, Jettaman
 *
 * Updated by RusTus, 2026-11-09
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
	/** @var string|null What Let's Encrypt said when the certificate was not made. */
	static $certFailedWhy;
	/** @var string|null Why www. was left out of the certificate. */
	static $wwwNote;
	/** @var bool|null True when PHP lacks OpenSSL or curl (set by getCheckModReq()). */
	static $modReqsError;
	/** @var bool|null True when port 443 is not open (set by getCheckPortReq()). */
	static $portReqsError;
	/** @var bool|null True after a certificate was revoked. */
	static $revokecert;
	/** @var bool|null True after a CSR was made and emailed. */
	static $keyadd;
	/** @var bool|null True when the key or the request could not be made or kept. */
	static $csrFailed;
	/** @var bool|null True when the certificate does not belong to the private key. */
	static $keymismatch;
	/** @var bool|null True when Let's Encrypt did not issue the certificate. */
	static $certFailed;
	/** @var bool|null True when a form was sent with a required field empty. */
	static $empty;
	/** @var bool|null True when Let's Encrypt did not revoke the certificate. */
	static $revokeFailed;
	//static $loggererror;

    /**
     * Template HTML: the module's stylesheet link.
     *
     * @return string
     */
    static function getInit() {
        return '<link rel="stylesheet" type="text/css" href="modules/sencrypt/assets/sencrypt.css">';
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
	 * Escape text for HTML.
	 *
	 * @param mixed $s The text.
	 * @return string
	 */
	static function h($s) {
		return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Translated and HTML-escaped text.
	 *
	 * @param string $s The English text.
	 * @return string
	 */
	static function t($s) {
		return self::h(ui_language::translate($s));
	}

	/**
	 * An inline SVG icon (stroked, in the text colour).
	 *
	 * @param string $name The icon: shield, lock, globe, panel, refresh, pen, file, upload, download, trash, ban, plus, info, key, clock.
	 * @return string SVG markup, or '' for an unknown name.
	 */
	static function Icon($name) {
		$p = array(
			'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
			'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
			'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18"/>',
			'panel' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
			'refresh' => '<path d="M20 12a8 8 0 1 1-2.3-5.7L20 8.5"/><path d="M20 3.5v5h-5"/>',
			'pen' => '<path d="M12 20h8"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
			'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/>',
			'upload' => '<path d="M20 15v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/><path d="m16 8-4-4-4 4"/><path d="M12 4v11"/>',
			'download' => '<path d="M20 15v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/><path d="m8 11 4 4 4-4"/><path d="M12 15V4"/>',
			'trash' => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="m6 7 1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
			'ban' => '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
			'plus' => '<path d="M12 5v14M5 12h14"/>',
			'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-5M12 8h.01"/>',
			'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3"/>',
			'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'open' => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
		);
		if (!isset($p[$name])) {
			return '';
		}
		return '<svg class="sc-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$name] . '</svg>';
	}

	/**
	 * Which tab the page opens on: the certificate list after a change, else the one asked for.
	 *
	 * @return string 'certs', 'letsencrypt' or 'third-party'.
	 */
	static function Tab() {
		if (self::$okletsencrypt || self::$okselfsigned || self::$okthirdparty || self::$delok || self::$revokecert) {
			return 'certs';
		}
		$panel = isset($_GET['ShowPanel']) ? (string) $_GET['ShowPanel'] : '';
		if ($panel === 'letsencrypt') {
			return 'letsencrypt';
		}
		if ($panel === 'third-party' || $panel === 'third_party' || isset($_GET['show'])) {
			return 'third-party';
		}
		return 'certs';
	}

	/**
	 * Template value: ' on' for the pane of a tab that is open.
	 *
	 * @param string $tab 'certs', 'letsencrypt' or 'third-party'.
	 * @return string
	 */
	static function On($tab) {
		return self::Tab() === $tab ? ' on' : '';
	}

	/**
	 * Template value: ' on' when the certificates tab is open.
	 *
	 * @return string
	 */
	static function getTabCerts() {
		return self::On('certs');
	}

	/**
	 * Template value: ' on' when the Let's Encrypt tab is open.
	 *
	 * @return string
	 */
	static function getTabLetsEncrypt() {
		return self::On('letsencrypt');
	}

	/**
	 * Template value: ' on' when the third-party tab is open.
	 *
	 * @return string
	 */
	static function getTabThirdParty() {
		return self::On('third-party');
	}

	/**
	 * Template HTML: the tab bar (links, so each tab also works without scripts).
	 *
	 * @return string HTML.
	 */
	static function getTabs() {
		$tabs = array(
			array('certs', './?module=sencrypt', 'shield', ui_language::translate('Your certificates')),
			array('letsencrypt', './?module=sencrypt&amp;ShowPanel=letsencrypt', 'refresh', "Let's Encrypt"),
			array('third-party', './?module=sencrypt&amp;ShowPanel=third-party', 'key', ui_language::translate('Your own certificate')),
		);
		$out = '';
		foreach ($tabs as $t) {
			$out .= '<a role="tab" class="sc-tab' . self::On($t[0]) . '" data-tab="' . $t[0] . '" href="' . $t[1] . '">' . self::Icon($t[2]) . '<span>' . self::h($t[3]) . '</span></a>';
		}
		return $out;
	}

	/**
	 * Template HTML: the three ways to add your own certificate, as cards.
	 *
	 * @return string HTML.
	 */
	static function getChoices() {
		$choices = array(
			array('ShowSelf', 'pen', 'Self-signed', 'Made here in a moment. Browsers warn about it, so it suits testing and private sites.'),
			array('ShowCSR', 'file', 'Certificate Signing Request', 'Make the request a certificate seller asks for. It is emailed to you with its private key.'),
			array('Bought', 'upload', 'Install a signed certificate', 'Upload a certificate you bought (GoDaddy, Sectigo and others) with its key and CA chain.'),
		);
		$out = '';
		foreach ($choices as $c) {
			$on = (isset($_GET['show']) && $_GET['show'] === $c[0]) ? ' on' : '';
			$out .= '<a class="sc-choice' . $on . '" href="./?module=sencrypt&amp;ShowPanel=third-party&amp;show=' . $c[0] . '">' . self::Icon($c[1])
				. '<b>' . self::t($c[2]) . '</b><span>' . self::t($c[3]) . '</span></a>';
		}
		return $out;
	}

	/**
	 * Template HTML: the information icon.
	 *
	 * @return string SVG markup.
	 */
	static function getIconInfo() {
		return self::Icon('info');
	}

	/**
	 * Template flag: the logged-in account is in the Administrators group.
	 *
	 * @return bool
	 */
	static function getAdmin() {
		$user = ctrl_users::GetUserDetail();
		return ((int) $user['usergroupid'] === 1);
	}

	/**
	 * A small form with one button that posts an action for one domain (with the session token).
	 *
	 * @param string $action The controller action (do<action>).
	 * @param string $field The form field the action reads the domain from.
	 * @param string $domain The domain.
	 * @param string $label The button text (English, translated here).
	 * @param string $icon The icon name.
	 * @param string $style The Bootstrap button style: primary, default, warning, danger.
	 * @param string $confirm A question to confirm first (English), or '' for none.
	 * @return string HTML.
	 */
	static function ActionButton($action, $field, $domain, $label, $icon, $style, $confirm = '') {
		return '<form class="sc-act" action="./?module=sencrypt&amp;action=' . self::h($action) . '" method="post"'
			. ($confirm !== '' ? ' data-confirm="' . self::h(sprintf(ui_language::translate($confirm), $domain)) . '"' : '') . '>'
			. '<input type="hidden" name="' . self::h($field) . '" value="' . self::h($domain) . '">' . runtime_csfr::Token()
			. '<button type="submit" class="btn btn-' . self::h($style) . ' btn-sm sc-btn">' . self::Icon($icon) . '<span>' . self::t($label) . '</span></button></form>';
	}

	/**
	 * The certificate a domain has in the account's folder, if any.
	 *
	 * @param string $username The account's username.
	 * @param string $domain The domain.
	 * @return array{kind: string, days: int|null, until: string}|null 'letsencrypt' or 'third_party', whole days left (null when not issued yet) and the end date; null when there is none.
	 */
	static function CertOf($username, $domain) {
		$base = ctrl_options::GetSystemOption('hosted_dir') . $username . '/ssl/sencrypt/';
		foreach (array('letsencrypt', 'third_party') as $kind) {
			$dir = $base . $kind . '/' . $domain;
			if (!is_dir($dir)) {
				continue;
			}
			$days = null;
			$until = '';
			$pem = is_file($dir . '/cert.pem') ? @file_get_contents($dir . '/cert.pem') : false;
			$info = $pem ? @openssl_x509_parse($pem) : false;
			if ($info && !empty($info['validTo_time_t'])) {
				$days = (int) floor(($info['validTo_time_t'] - time()) / 86400);
				$until = date('Y-m-d', $info['validTo_time_t']);
				if ($days <= -1700) {   // a placeholder certificate, not issued yet
					$days = null;
					$until = '';
				}
			}
			return array('kind' => $kind, 'days' => $days, 'until' => $until);
		}
		return null;
	}

	/**
	 * The account's enabled domains.
	 *
	 * @return array<int, string> Domain names, sorted.
	 */
	static function AccountDomains() {
		global $zdbh;
		$currentuser = ctrl_users::GetUserDetail();
		$sql = $zdbh->prepare("SELECT vh_name_vc FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_enabled_in=1 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc ASC");
		$sql->bindParam(':userid', $currentuser['userid']);
		$sql->execute();
		return array_map('strval', $sql->fetchAll(PDO::FETCH_COLUMN));
	}

	/**
	 * One certificate row of the certificate list.
	 *
	 * @param string $domain The domain.
	 * @param array{kind: string, days: int|null, until: string} $c Its certificate (see CertOf()).
	 * @param bool $panel True for the panel's own certificate.
	 * @return string HTML table row.
	 */
	static function CertRow($domain, $c, $panel) {
		$le = $c['kind'] === 'letsencrypt';
		$days = $c['days'];
		if ($days === null) {
			$status = '<span class="sc-pill info">' . self::t('Not issued yet') . '</span>';
		} elseif ($days < 0) {
			$status = '<span class="sc-pill bad">' . self::t('Expired') . '</span>';
		} elseif ($days <= 14) {
			$status = '<span class="sc-pill warn">' . self::t('Expires soon') . '</span>';
		} else {
			$status = '<span class="sc-pill ok">' . self::t('Valid') . '</span>';
		}
		if ($days === null) {
			$expiry = '<span class="sc-muted">–</span>';
		} else {
			$expiry = '<b>' . self::h($c['until']) . '</b><div class="sc-muted">'
				. ($days < 0 ? self::h(sprintf(ui_language::translate('%d days ago'), -$days)) : self::h(sprintf(ui_language::translate('in %d days'), $days))) . '</div>';
		}
		if ($le) {
			$renew = $days === null ? '' : ($days > 30 ? sprintf(ui_language::translate('Renews by itself in %d days'), $days - 30) : ui_language::translate('Renews by itself within a day'));
			$type = '<span class="sc-type le">' . self::Icon('refresh') . "Let's Encrypt</span>" . ($renew !== '' ? '<div class="sc-muted">' . self::h($renew) . '</div>' : '');
		} else {
			$type = '<span class="sc-type tp">' . self::Icon('key') . self::t('Your own') . '</span><div class="sc-muted">' . self::t('Self-signed or bought; renew it yourself') . '</div>';
		}
		// the site itself, over https (what the certificate is for), in a new tab
		$actions = '<a class="btn btn-default btn-sm sc-btn sc-visit" href="https://' . self::h($domain) . '/" target="_blank" rel="noopener">'
			. self::Icon('open') . '<span>' . self::t('Visit') . '</span></a>';
		if ($panel && $le) {
			$actions .= self::ActionButton('DeletePanelSSL', 'inDomain', $domain, 'Delete', 'trash', 'default', 'Delete the certificate of %s? The panel goes back to plain http.');
		} elseif ($panel) {
			$actions .= self::ActionButton('TPDelete', 'inName', $domain, 'Delete', 'trash', 'default', 'Delete the certificate of %s? The panel goes back to plain http.');
		} elseif ($le) {
			$actions .= self::ActionButton('RevokeSSL', 'inDomain', $domain, 'Revoke', 'ban', 'default', "Revoke the certificate of %s? Let's Encrypt marks it as no longer valid and it is removed from the site.");
			$actions .= self::ActionButton('Delete', 'inDomain', $domain, 'Delete', 'trash', 'default', 'Delete the certificate of %s? The site goes back to plain http.');
		} else {
			$actions .= self::ActionButton('Download', 'inName', $domain, 'Download', 'download', 'default');
			$actions .= self::ActionButton('TPDelete', 'inName', $domain, 'Delete', 'trash', 'default', 'Delete the certificate of %s? The site goes back to plain http.');
		}
		return '<tr><td><div class="sc-domain">' . self::Icon($panel ? 'panel' : 'lock') . '<span>' . self::h($domain) . '</span></div></td>'
			. '<td>' . $type . '</td><td>' . $status . '</td><td>' . $expiry . '</td><td class="sc-actions">' . $actions . '</td></tr>';
	}

	/**
	 * Template HTML: the panel's own certificate (administrators).
	 *
	 * @return string HTML table rows.
	 */
	static function getPanelCertRows() {
		$currentuser = ctrl_users::GetUserDetail();
		$domain = (string) ctrl_options::GetSystemOption('sentora_domain');
		$c = self::CertOf($currentuser['username'], $domain);
		if ($c === null) {
			return '<tr><td colspan="5" class="sc-empty">' . sprintf(self::t('The panel (%s) has no certificate yet: get a free one on the Let\'s Encrypt tab.'), self::h($domain)) . '</td></tr>';
		}
		return self::CertRow($domain, $c, true);
	}

	/**
	 * Template HTML: the account's domain certificates.
	 *
	 * @return string HTML table rows.
	 */
	static function getCertRows() {
		$currentuser = ctrl_users::GetUserDetail();
		$rows = '';
		foreach (self::AccountDomains() as $domain) {
			$c = self::CertOf($currentuser['username'], $domain);
			if ($c !== null) {
				$rows .= self::CertRow($domain, $c, false);
			}
		}
		if ($rows === '') {
			$rows = '<tr><td colspan="5" class="sc-empty">' . self::t('None of your domains has a certificate yet. Get a free one on the Let\'s Encrypt tab, or add your own.') . '</td></tr>';
		}
		return $rows;
	}

	/**
	 * Template HTML: counts over the account's certificates (the panel's included for administrators).
	 *
	 * @return string HTML.
	 */
	static function getCertSummary() {
		$currentuser = ctrl_users::GetUserDetail();
		$domains = self::AccountDomains();
		if (self::getAdmin()) {
			$domains[] = (string) ctrl_options::GetSystemOption('sentora_domain');
		}
		$n = array('all' => 0, 'le' => 0, 'soon' => 0, 'none' => 0);
		foreach ($domains as $domain) {
			$c = self::CertOf($currentuser['username'], $domain);
			if ($c === null) {
				$n['none']++;
				continue;
			}
			$n['all']++;
			if ($c['kind'] === 'letsencrypt') {
				$n['le']++;
			}
			if ($c['days'] !== null && $c['days'] <= 14) {
				$n['soon']++;
			}
		}
		$box = function ($value, $label, $class, $icon) {
			return '<div class="sc-stat ' . $class . '">' . self::Icon($icon) . '<div><b>' . (int) $value . '</b><span>' . self::t($label) . '</span></div></div>';
		};
		return $box($n['all'], 'Certificates', 'blue', 'shield') . $box($n['le'], 'Renew by themselves', 'green', 'refresh')
			. $box($n['soon'], 'Expired or expiring soon', $n['soon'] ? 'red' : 'grey', 'clock') . $box($n['none'], 'Domains without one', $n['none'] ? 'amber' : 'grey', 'globe');
	}

	/**
	 * Template HTML: the domains (and for administrators the panel) that can get a Let's Encrypt certificate.
	 *
	 * @return string HTML table rows.
	 */
	static function getLetsEncryptRows() {
		$currentuser = ctrl_users::GetUserDetail();
		$rows = '';
		if (self::getAdmin()) {
			$domain = (string) ctrl_options::GetSystemOption('sentora_domain');
			if (self::CertOf($currentuser['username'], $domain) === null) {
				$rows .= '<tr><td><div class="sc-domain">' . self::Icon('panel') . '<span>' . self::h($domain) . '</span></div><div class="sc-muted">' . self::t('The control panel') . '</div></td><td class="sc-actions">'
					. self::ActionButton('MakePanelSSL', 'inDomain', $domain, 'Get certificate', 'shield', 'primary') . '</td></tr>';
			}
		}
		foreach (self::AccountDomains() as $domain) {
			if (self::CertOf($currentuser['username'], $domain) === null) {
				$rows .= '<tr><td><div class="sc-domain">' . self::Icon('globe') . '<span>' . self::h($domain) . '</span></div></td><td class="sc-actions">'
					. self::ActionButton('MakeSSL', 'inDomain', $domain, 'Get certificate', 'shield', 'primary') . '</td></tr>';
			}
		}
		if ($rows === '') {
			$rows = '<tr><td colspan="2" class="sc-empty">' . (self::AccountDomains() ? self::t('Every domain already has a certificate.') : self::t('You have no domains yet. Add one in Domains, then come back here.')) . '</td></tr>';
		}
		return $rows;
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
		// The check is on inName, the value that goes into the path (the buttons send only
		// inName; checking inDomain refused every real download and let a posted inDomain
		// of one's own domain pass with an inName of "../another-account")
		$domain = isset($formvars['inName']) ? (string) $formvars['inName'] : '';
		if (!self::OwnsDomainName($domain)) {
			return false;
		}
        if (self::ExecuteDownload($domain, $currentuser["username"])) {
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
		if (self::ExecuteCSR($formvars['inDomain'], $formvars['inName'], $formvars['inAddress'], $formvars['inCity'], $formvars['inCountry'], $formvars['inCompany'], null)) {
			return true;
		}
	}
		
	/**
	 * Make a 4096-bit key and a CSR: the key is kept in the account's SSL folder (RequestKeyFile()),
	 * the CSR is emailed to the given address.
	 *
	 * @param string $domain The common name.
	 * @param string $name The state or province.
	 * @param string $address The email address (also the CSR's emailAddress).
	 * @param string $city The locality.
	 * @param string $country The two-letter country code.
	 * @param string $company The organization.
	 * @param string|null $password The pass phrase for the exported key.
	 * @return bool True when the key is kept and the request sent, false when either failed.
	 */
	static function ExecuteCSR($domain, $name, $address, $city, $country, $company, $password) {
		$currentuser = ctrl_users::GetUserDetail();
		$config = array('digest_alg' => 'sha256', 'private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA);
		$csrconfig = array('digest_alg' => 'sha256');
		$dn = array(
					"countryName" => "$country",
					"stateOrProvinceName" => "$name",
					"localityName" => "$city",
					"organizationName" => "$company",
					"commonName" => "$domain",
					"emailAddress" => "$address"
		);
		$privkey = openssl_pkey_new($config);
		$csr = $privkey ? openssl_csr_new($dn, $privkey, $csrconfig) : false;
		if (!$csr || !openssl_csr_export($csr, $csrout) || !openssl_pkey_export($privkey, $pkeyout, $password)) {
			self::$csrFailed = true;
			return false;
		}
		// The private key stays on the server, in the account's own SSL folder (where the installed
		// certificate's key lives too), and "Install a signed certificate" uses it. It used to be
		// e-mailed as an unencrypted attachment and deleted here.
		$keyfile = self::RequestKeyFile($currentuser['username'], $domain, true);
		if ($keyfile === false || file_put_contents($keyfile, $pkeyout) === false) {
			self::$csrFailed = true;
			return false;
		}
		@chmod($keyfile, 0600);

		// Only the request (no secret in it) is e-mailed, for the certificate seller
		$phpmailer = new sys_email();
		$phpmailer->Subject = "Certificate Signing Request for " . $domain;
		$phpmailer->Body = "Hi " . $currentuser['username'] . ",\n\n"
			. "Here is the certificate signing request (CSR) for " . $domain . ". Give it to the certificate seller.\n"
			. "Its private key stays on the server: when you have the certificate, install it in the panel under\n"
			. "Sencrypt > Third-party > Install a signed certificate (the key is filled in for you).\n\n"
			. $csrout . "\n";
		$phpmailer->AddAddress($address);
		$phpmailer->SendEmail();
		self::$keyadd = true;
		return true;
	}

	/**
	 * Where the private key of a certificate request waits for the signed certificate:
	 * <account>/ssl/sencrypt/third_party/requests/<domain>.key.
	 *
	 * Every folder on the way must be a real folder of the account (a link there would make the
	 * panel read or write wherever it points), and the key file itself must not be a link.
	 *
	 * @param string $username The account's user name.
	 * @param string $domain The domain (already checked to be the account's own).
	 * @param bool $create Optional. Make the folders that are missing. Default false.
	 * @return string|false The key file's path, or false when a folder is not safe (or missing).
	 */
	static function RequestKeyFile($username, $domain, $create = false) {
		$home = rtrim(ctrl_options::GetSystemOption('hosted_dir'), '/') . '/' . $username;
		$path = $home;
		foreach (array('/ssl', '/sencrypt', '/third_party', '/requests') as $sub) {
			$path .= $sub;
			clearstatcache(true, $path);
			if ($create && !file_exists($path) && !is_link($path)) {
				@mkdir($path, $sub === '/requests' ? 0700 : 0755);
			}
			if (is_link($path) || !is_dir($path) || realpath($path) !== $path) {
				return false;
			}
		}
		$file = $path . '/' . $domain . '.key';
		if (is_link($file)) {
			return false;
		}
		return $file;
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
		// The check is on inName, the value that goes into the path (see doDownload)
		$domain = isset($formvars['inName']) ? (string) $formvars['inName'] : '';
		if (!self::OwnsDomainName($domain)) {
			return false;
		}
		
		$sub_module = "third_party";
		
        if (self::ExecuteTPDelete($domain, $currentuser["username"], $sub_module)) {
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
			$line .= sys_tls::Lines();
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
			$line .= sys_tls::Lines();
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
			$line .= sys_tls::Lines();
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
				
				// (stored lines from older versions are today's first, so the block written above matches)
				sys_tls::Upgrade();
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
				$ssline .= sys_tls::Lines();
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
					
					// (stored lines from older versions are today's first, so the block written above matches)
					sys_tls::Upgrade();
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

		// the key: uploaded, or the one kept from "Make a Certificate Signing Request"
		$waiting = self::RequestKeyFile($currentuser['username'], $domain);
		$useWaiting = empty($_FILES["inkey"]["name"]) && $waiting !== false && is_file($waiting);
		if ((empty($_FILES["inkey"]["name"]) && !$useWaiting) || empty($_FILES["inWCA"]["name"])) {
			self::$empty = true;
			return false;
		}
		// the certificate must belong to the key: a wrong pair would stop HTTPS for the domain
		$keyPem = $useWaiting ? (string) @file_get_contents($waiting) : (string) @file_get_contents($_FILES["inkey"]["tmp_name"]);
		$certPem = (string) @file_get_contents($_FILES["inWCA"]["tmp_name"]);
		if ($keyPem === '' || $certPem === '' || !@openssl_x509_check_private_key($certPem, $keyPem)) {
			self::$keymismatch = true;
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
			
			if ($useWaiting) {
				file_put_contents($uploadkey, $keyPem);
				@unlink($waiting); // used: no second copy of the key
			} else {
				move_uploaded_file($_FILES["inkey"]["tmp_name"], $uploadkey);
			}
			move_uploaded_file($_FILES["inWCA"]["tmp_name"], $uploadwcrt);
			move_uploaded_file($_FILES["inICA"]["tmp_name"], $uploadicrt);
			// the private key: the panel and Apache (root) only
			@chmod($uploadkey, 0600);
			
			if($domain == ctrl_options::GetSystemOption('sentora_domain')) {
			
				$line = "# Made from Sencrypt - third_party - start" . fs_filehandler::NewLine();
				$line  .= fs_filehandler::NewLine();
				$line .= 'SSLEngine On' . fs_filehandler::NewLine();
				$line .= sys_tls::Lines();
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
				$line .= sys_tls::Lines();
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
			$line .= sys_tls::Lines();
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
			$line .= sys_tls::Lines();
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

			// Panel values (the panel's own domain: administrators only)
			$panel_Domain = ctrl_options::GetSystemOption('sentora_domain');
			
			// Check if ssl exists else where
			if ( (int) $currentuser['usergroupid'] === 1 && !is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" . $panel_Domain . "/") ) {
			
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
	 * Template HTML: the country select options (the names translated; the values are the country codes).
	 *
	 * @return string HTML option tags.
	 */
	static function getListCountry() {
			$res = '<option value="AF">Afghanistan</option> <option value="AX">Åland Islands</option> <option value="AL">Albania</option> <option value="DZ">Algeria</option> <option value="AS">American Samoa</option> <option value="AD">Andorra</option> <option value="AO">Angola</option> <option value="AI">Anguilla</option> <option value="AQ">Antarctica</option> <option value="AG">Antigua and Barbuda</option> <option value="AR">Argentina</option> <option value="AM">Armenia</option> <option value="AW">Aruba</option> <option value="AU">Australia</option> <option value="AT">Austria</option> <option value="AZ">Azerbaijan</option> <option value="BS">Bahamas</option> <option value="BH">Bahrain</option> <option value="BD">Bangladesh</option> <option value="BB">Barbados</option> <option value="BY">Belarus</option> <option value="BE">Belgium</option> <option value="BZ">Belize</option> <option value="BJ">Benin</option> <option value="BM">Bermuda</option> <option value="BT">Bhutan</option> <option value="BO">Bolivia</option> <option value="BA">Bosnia and Herzegovina</option> <option value="BW">Botswana</option> <option value="BV">Bouvet Island</option> <option value="BR">Brazil</option> <option value="IO">British Indian Ocean Territory</option> <option value="BN">Brunei Darussalam</option> <option value="BG">Bulgaria</option> <option value="BF">Burkina Faso</option> <option value="BI">Burundi</option> <option value="KH">Cambodia</option> <option value="CM">Cameroon</option> <option value="CA">Canada</option> <option value="CV">Cape Verde</option> <option value="KY">Cayman Islands</option> <option value="CF">Central African Republic</option> <option value="TD">Chad</option> <option value="CL">Chile</option> <option value="CN">China</option> <option value="CX">Christmas Island</option> <option value="CC">Cocos (Keeling) Islands</option> <option value="CO">Colombia</option> <option value="KM">Comoros</option> <option value="CG">Congo</option> <option value="CD">Congo, The Democratic Republic of The</option> <option value="CK">Cook Islands</option> <option value="CR">Costa Rica</option> <option value="CI">Cote D´ivoire</option> <option value="HR">Croatia</option> <option value="CU">Cuba</option> <option value="CY">Cyprus</option> <option value="CZ">Czech Republic</option> <option value="DK">Denmark</option> <option value="DJ">Djibouti</option> <option value="DM">Dominica</option> <option value="DO">Dominican Republic</option> <option value="EC">Ecuador</option> <option value="EG">Egypt</option> <option value="SV">El Salvador</option> <option value="GQ">Equatorial Guinea</option> <option value="ER">Eritrea</option> <option value="EE">Estonia</option> <option value="ET">Ethiopia</option> <option value="FK">Falkland Islands (Malvinas)</option> <option value="FO">Faroe Islands</option> <option value="FJ">Fiji</option> <option value="FI">Finland</option> <option value="FR">France</option> <option value="GF">French Guiana</option> <option value="PF">French Polynesia</option> <option value="TF">French Southern Territories</option> <option value="GA">Gabon</option> <option value="GM">Gambia</option> <option value="GE">Georgia</option> <option value="DE">Germany</option> <option value="GH">Ghana</option> <option value="GI">Gibraltar</option> <option value="GR">Greece</option> <option value="GL">Greenland</option> <option value="GD">Grenada</option> <option value="GP">Guadeloupe</option> <option value="GU">Guam</option> <option value="GT">Guatemala</option> <option value="GG">Guernsey</option> <option value="GN">Guinea</option> <option value="GW">Guinea-bissau</option> <option value="GY">Guyana</option> <option value="HT">Haiti</option> <option value="HM">Heard Island and Mcdonald Islands</option> <option value="VA">Holy See (Vatican City State)</option> <option value="HN">Honduras</option> <option value="HK">Hong Kong</option> <option value="HU">Hungary</option> <option value="IS">Iceland</option> <option value="IN">India</option> <option value="ID">Indonesia</option> <option value="IR">Iran, Islamic Republic of</option> <option value="IQ">Iraq</option> <option value="IE">Ireland</option> <option value="IM">Isle of Man</option> <option value="IL">Israel</option> <option value="IT">Italy</option> <option value="JM">Jamaica</option> <option value="JP">Japan</option> <option value="JE">Jersey</option> <option value="JO">Jordan</option> <option value="KZ">Kazakhstan</option> <option value="KE">Kenya</option> <option value="KI">Kiribati</option> <option value="KP">Korea, Democratic People´s Republic of</option> <option value="KR">Korea, Republic of</option> <option value="KW">Kuwait</option> <option value="KG">Kyrgyzstan</option> <option value="LA">Lao People´s Democratic Republic</option> <option value="LV">Latvia</option> <option value="LB">Lebanon</option> <option value="LS">Lesotho</option> <option value="LR">Liberia</option> <option value="LY">Libyan Arab Jamahiriya</option> <option value="LI">Liechtenstein</option> <option value="LT">Lithuania</option> <option value="LU">Luxembourg</option> <option value="MO">Macao</option> <option value="MK">Macedonia, The Former Yugoslav Republic of</option> <option value="MG">Madagascar</option> <option value="MW">Malawi</option> <option value="MY">Malaysia</option> <option value="MV">Maldives</option> <option value="ML">Mali</option> <option value="MT">Malta</option> <option value="MH">Marshall Islands</option> <option value="MQ">Martinique</option> <option value="MR">Mauritania</option> <option value="MU">Mauritius</option> <option value="YT">Mayotte</option> <option value="MX">Mexico</option> <option value="FM">Micronesia, Federated States of</option> <option value="MD">Moldova, Republic of</option> <option value="MC">Monaco</option> <option value="MN">Mongolia</option> <option value="ME">Montenegro</option> <option value="MS">Montserrat</option> <option value="MA">Morocco</option> <option value="MZ">Mozambique</option> <option value="MM">Myanmar</option> <option value="NA">Namibia</option> <option value="NR">Nauru</option> <option value="NP">Nepal</option> <option value="NL">Netherlands</option> <option value="AN">Netherlands Antilles</option> <option value="NC">New Caledonia</option> <option value="NZ">New Zealand</option> <option value="NI">Nicaragua</option> <option value="NE">Niger</option> <option value="NG">Nigeria</option> <option value="NU">Niue</option> <option value="NF">Norfolk Island</option> <option value="MP">Northern Mariana Islands</option> <option value="NO">Norway</option> <option value="OM">Oman</option> <option value="PK">Pakistan</option> <option value="PW">Palau</option> <option value="PS">Palestinian Territory, Occupied</option> <option value="PA">Panama</option> <option value="PG">Papua New Guinea</option> <option value="PY">Paraguay</option> <option value="PE">Peru</option> <option value="PH">Philippines</option> <option value="PN">Pitcairn</option> <option value="PL">Poland</option> <option value="PT">Portugal</option> <option value="PR">Puerto Rico</option> <option value="QA">Qatar</option> <option value="RE">Reunion</option> <option value="RO">Romania</option> <option value="RU">Russian Federation</option> <option value="RW">Rwanda</option> <option value="SH">Saint Helena</option> <option value="KN">Saint Kitts and Nevis</option> <option value="LC">Saint Lucia</option> <option value="PM">Saint Pierre and Miquelon</option> <option value="VC">Saint Vincent and The Grenadines</option> <option value="WS">Samoa</option> <option value="SM">San Marino</option> <option value="ST">Sao Tome and Principe</option> <option value="SA">Saudi Arabia</option> <option value="SN">Senegal</option> <option value="RS">Serbia</option> <option value="SC">Seychelles</option> <option value="SL">Sierra Leone</option> <option value="SG">Singapore</option> <option value="SK">Slovakia</option> <option value="SI">Slovenia</option> <option value="SB">Solomon Islands</option> <option value="SO">Somalia</option> <option value="ZA">South Africa</option> <option value="GS">South Georgia and The South Sandwich Islands</option> <option value="ES">Spain</option> <option value="LK">Sri Lanka</option> <option value="SD">Sudan</option> <option value="SR">Suriname</option> <option value="SJ">Svalbard and Jan Mayen</option> <option value="SZ">Swaziland</option> <option value="SE">Sweden</option> <option value="CH">Switzerland</option> <option value="SY">Syrian Arab Republic</option> <option value="TW">Taiwan, Province of China</option> <option value="TJ">Tajikistan</option> <option value="TZ">Tanzania, United Republic of</option> <option value="TH">Thailand</option> <option value="TL">Timor-leste</option> <option value="TG">Togo</option> <option value="TK">Tokelau</option> <option value="TO">Tonga</option> <option value="TT">Trinidad and Tobago</option> <option value="TN">Tunisia</option> <option value="TR">Turkey</option> <option value="TM">Turkmenistan</option> <option value="TC">Turks and Caicos Islands</option> <option value="TV">Tuvalu</option> <option value="UG">Uganda</option> <option value="UA">Ukraine</option> <option value="AE">United Arab Emirates</option> <option value="GB">United Kingdom</option> <option value="US">United States</option> <option value="UM">United States Minor Outlying Islands</option> <option value="UY">Uruguay</option> <option value="UZ">Uzbekistan</option> <option value="VU">Vanuatu</option> <option value="VE">Venezuela</option> <option value="VN">Viet Nam</option> <option value="VG">Virgin Islands, British</option> <option value="VI">Virgin Islands, U.S.</option> <option value="WF">Wallis and Futuna</option> <option value="EH">Western Sahara</option> <option value="YE">Yemen</option> <option value="ZM">Zambia</option> <option value="ZW">Zimbabwe</option>';
			// the names in the account's language; the two-letter codes stay as they are
			return (string) preg_replace_callback('#(<option value="[A-Z]{2}">)([^<]+)(</option>)#u', function ($m) {
				return $m[1] . self::t($m[2]) . $m[3];
			}, $res);
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
		list($countryCode, $state) = self::ServerLocation();
	
		// Check DNS before continuing
		if (!self::checkDNSIsLive($domain)) {
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
					// www. too, unless public DNS has no address for it (it would fail the whole request)
					$le->signDomains(\Analogic\ACME\Lescript::namesFor($domain, false, self::$wwwNote));
				}
			}			
		}
	
		catch (\Exception $e) {
			$errorCatahed = $e->getMessage();
			self::$certFailedWhy = $errorCatahed;
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
			$line .= sys_tls::Lines();
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
		list($countryCode, $state) = self::ServerLocation();
		
		// Check DNS before continuing
		if (!self::checkDNSIsLive($domain)) {
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
			self::$certFailedWhy = $errorCatahed;
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
			$line .= sys_tls::Lines();
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
		$line .= sys_tls::Lines();
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
			// (stored lines from older versions are today's first, so the block written above matches)
			sys_tls::Upgrade();
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
		$line .= sys_tls::Lines();
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
	 * Revoke a domain's Let's Encrypt certificate and remove it and its SSL config from the vhost.
	 *
	 * @param string $domain The domain name.
	 * @param string $username The account's username (replaced by the logged-in account's).
	 * @param string $sub_module The certificate kind ('letsencrypt').
	 * @return bool True when revoked and removed, false when there is no certificate or Let's Encrypt refused.
	 */
	static function ExecuteRevokeSSL($domain, $username, $sub_module) {
		$currentuser = ctrl_users::GetUserDetail();
		$username = $currentuser["username"];
		$accountDir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/";
		$certlocation = $accountDir . $domain;
		$domainRoot = ctrl_options::GetSystemOption('hosted_dir') . $username . "/public_html/" . $domain;

		// Let's Encrypt wants the certificate in DER, base64url-encoded
		$pem_data = is_file($certlocation . "/cert.pem") ? file_get_contents($certlocation . "/cert.pem") : false;
		if ($pem_data === false) {
			self::$revokeFailed = true;
			return false;
		}
		$pem2der = self::base64url(self::pem2der($pem_data));

		require_once("modules/sencrypt/code/Lescript.php");
		date_default_timezone_set("UTC");
		$logger = new Logger();
		$level = ob_get_level();
		ob_start();   // Lescript's logger echoes; keep it off the page
		try {
			$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $domainRoot, $logger);
			$le->initAccount();
			$le->postRevoke($pem2der);
			while (ob_get_level() > $level) {
				ob_end_clean();
			}
		} catch (\Exception $e) {
			while (ob_get_level() > $level) {
				ob_end_clean();
			}
			error_log(date('Y-m-d H:i:s') . " - REVOKE: " . $domain . fs_filehandler::NewLine() . $e->getMessage() . fs_filehandler::NewLine(), 3, ctrl_options::GetSystemOption('sentora_root') . 'modules/sencrypt/sencrypt.log');
			self::$revokeFailed = true;
			return false;
		}

		// Revoked: remove the certificate and its SSL settings from the site, as Delete does
		self::ExecuteTPDelete($domain, $username, $sub_module);
		self::$delok = false;
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
	 * Does the domain have an address at all (an A record, also through a CNAME)? Whether it reaches
	 * this server is for Let's Encrypt to find out: its own check says what is wrong. (This compared
	 * the panel's IP with what ip-api.com said about it, which is never true for a server behind a
	 * router with a local panel IP, so every request stopped here without trying.)
	 *
	 * @param string $domain The domain name.
	 * @return bool
	 */
	static function checkDNSIsLive($domain) {
		return checkdnsrr($domain, "A") || checkdnsrr($domain, "CNAME");
	}

	/**
	 * The country and region for the certificate request, from the server's public address:
	 * the panel's IP when it is a public one, else the address ip-api.com sees this server come from.
	 *
	 * @return array{0: string, 1: string} Two-letter country code (or '') and region (or '').
	 */
	static function ServerLocation() {
		require_once 'modules/sencrypt/code/Lescript.php';
		return \Analogic\ACME\Lescript::serverLocation();
	}


	/**
	 * Let's Encrypt's answer in plain words, with what to do (Lescript::explain()).
	 *
	 * @param string $message The exception message.
	 * @return string
	 */
	static function LeReason($message) {
		require_once 'modules/sencrypt/code/Lescript.php';
		return \Analogic\ACME\Lescript::explain($message);
	}
	

	/**
	 * Template HTML: the error or success message of the last action.
	 *
	 * @return string|null HTML message, or null when there is nothing to show.
	 */
	static function getResult() {
		if (self::$modReqsError)
		{
			return ui_sysmessage::shout(ui_language::translate("Sencrypt needs PHP 5.5 or newer with the OpenSSL and curl extensions. Ask your administrator to install them; until then this module may not work correctly."), "zannounceerror");
		}
		if (self::$portReqsError)
		{
			return ui_sysmessage::shout(ui_language::translate("ALERT: Port (443) appears to be CLOSED. Sencrypt will not work until port (443) is OPEN. Contact your Administrator."), "zannounceerror");
		}
		if (self::$okletsencrypt)
		{
			return ui_sysmessage::shout(ui_language::translate("Your FREE Let's Encrypt SSL Certificate has been Created. It will be active in about 5 minutes.") . (self::$wwwNote ? ' ' . htmlspecialchars(self::$wwwNote, ENT_QUOTES, 'UTF-8') : ''), "zannounceok");
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
			return ui_sysmessage::shout(ui_language::translate("This domain has no address in DNS yet (no A record): add one that points to this server's public address, then try again."), "zannounceerror");
		}
		if (self::$revokecert) {
            return ui_sysmessage::shout(ui_language::translate("The Requested Certificate has been revoked"), "zannounceok");
        }
		if (self::$keyadd) {
            return ui_sysmessage::shout(ui_language::translate("The certificate signing request was e-mailed to the address you entered. Its private key stays on the server and is used when you install the certificate."), "zannounceok");
        }
		if (self::$csrFailed) {
            return ui_sysmessage::shout(ui_language::translate("The request could not be made or its key could not be kept. Nothing was sent; try again."), "zannounceerror");
        }
		if (self::$keymismatch) {
            return ui_sysmessage::shout(ui_language::translate("That certificate does not belong to the private key. Nothing was installed: use the certificate issued for this domain's request."), "zannounceerror");
        }
		if (self::$certFailed) {
            return ui_sysmessage::shout(ui_language::translate("The Let's Encrypt certificate was not made.") . ' ' . (self::$certFailedWhy ? htmlspecialchars(self::LeReason(self::$certFailedWhy), ENT_QUOTES, 'UTF-8') : ui_language::translate("Check that the domain opens from the internet over http (port 80).")), "zannounceerror");
        }
		if (self::$revokeFailed) {
            return ui_sysmessage::shout(ui_language::translate("Let's Encrypt did not revoke the certificate. It is still in place; try again later, or delete it instead."), "zannounceerror");
        }
		if (self::$empty) {
            return ui_sysmessage::shout(ui_language::translate("Fill in every field, then try again."), "zannounceerror");
        }
		return;
	}
}
?>