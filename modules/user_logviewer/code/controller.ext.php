<?php
/**
 * View Site Logs: an account's own error and access logs, followed live.
 *
 * The page uses the System Log Viewer's viewer (modules/system_logviewer/assets) and
 * reads new lines from code/read.php, which opens only the account's own logs.
 *
 * @package user_logviewer
 * @version 100
 * @author Simon Mora - samt2497
 */

/**
 * View Site Logs controller.
 */
class module_controller extends ctrl_module
{
	/** @var bool|null True when the requested domain is not the account's own. */
	static $notmine;
	/** @var bool|null True when the requested log file does not exist. */
	static $notfile;

	/**
	 * Escape text for HTML.
	 *
	 * @param mixed $s The text.
	 * @return string
	 */
	static function h($s)
	{
		return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Translated and HTML-escaped text.
	 *
	 * @param string $s The English text.
	 * @return string
	 */
	static function t($s)
	{
		return self::h(ui_language::translate($s));
	}

	/**
	 * The path of one of an account's site logs.
	 *
	 * @param string $username The account's username.
	 * @param string $domain The domain name.
	 * @param string $kind 'error' or 'access'.
	 * @return string
	 */
	static function LogPath($username, $domain, $kind)
	{
		return '/var/sentora/logs/domains/' . $username . '/' . $domain . '-' . ($kind === 'access' ? 'access' : 'error') . '.log';
	}

	/**
	 * Send a file as a download and exit.
	 *
	 * @param string $filepath The full path of the file.
	 * @return void Never returns (ends with die()).
	 */
	static function downloadFile($filepath)
	{
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . filesize($filepath));
		readfile($filepath);
		die();
	}

	/**
	 * The logged-in account's domains, subdomains and parked domains.
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @return array<int, array{id: int, name: string}>
	 */
	static function Domains()
	{
		global $zdbh;
		$currentuser = ctrl_users::GetUserDetail();
		$sql = $zdbh->prepare("SELECT vh_id_pk, vh_name_vc FROM x_vhosts WHERE vh_acc_fk = :uid AND vh_deleted_ts IS NULL ORDER BY vh_name_vc ASC");
		$sql->bindParam(':uid', $currentuser['userid']);
		$sql->execute();
		$out = array();
		while ($r = $sql->fetch()) {
			$out[] = array('id' => (int) $r['vh_id_pk'], 'name' => (string) $r['vh_name_vc']);
		}
		return $out;
	}

	/**
	 * Template flag: the account has any domain (template: <% if HasDomains %>).
	 *
	 * @return bool
	 */
	static function getHasDomains()
	{
		return count(self::Domains()) > 0;
	}

	/**
	 * Format a size in bytes.
	 *
	 * @param int|float $bytes The size.
	 * @return string For example "12.3 MB".
	 */
	static function HumanSize($bytes)
	{
		$units = array('bytes', 'KB', 'MB', 'GB');
		$i = 0;
		$n = (float) $bytes;
		while ($n >= 1024 && $i < 3) {
			$n /= 1024;
			$i++;
		}
		return ($i ? number_format($n, 1) : (int) $n) . ' ' . $units[$i];
	}

	/**
	 * Template HTML: each domain with its error and access log, to pick from, and a download button for each log.
	 *
	 * @return string HTML.
	 */
	static function getLogMenu()
	{
		$currentuser = ctrl_users::GetUserDetail();
		$out = '';
		foreach (self::Domains() as $d) {
			$out .= '<div class="lv-group" data-s="' . self::h(strtolower($d['name'])) . '"><div class="lv-group-name lv-domain">' . self::h($d['name']) . '</div>';
			foreach (array('error' => array('Errors', 'ErrorLogActions'), 'access' => array('Visits (access log)', 'AccessLogActions')) as $kind => $k) {
				$path = self::LogPath($currentuser['username'], $d['name'], $kind);
				$exists = is_file($path) && !is_link($path);
				$label = ui_language::translate($k[0]);
				$out .= '<div class="lv-row"><button type="button" class="lv-item' . ($exists ? '' : ' missing') . '" data-id="' . $d['id'] . '-' . $kind . '" data-path="' . self::h(basename($path)) . '"'
					. ' data-group="' . self::h($d['name'] . ' · ' . $label) . '" data-readable="' . ($exists ? '1' : '0') . '" data-s="' . self::h(strtolower($d['name'] . ' ' . $label)) . '">'
					. '<span class="lv-item-name">' . self::h($label) . '</span>'
					. '<span class="lv-item-meta">' . ($exists ? self::h(self::HumanSize((int) @filesize($path))) . ' · <span class="lv-ago" data-mtime="' . (int) @filemtime($path) . '"></span>' : self::t('no log yet')) . '</span></button>';
				if ($exists) {
					$out .= '<form class="lv-dl" action="./?module=user_logviewer&amp;action=' . $k[1] . '" method="post"><input type="hidden" name="inDownload" value="' . $d['id'] . '">' . runtime_csfr::Token()
						. '<button type="submit" title="' . self::t('Download') . ' ' . self::h(basename($path)) . '" aria-label="' . self::t('Download') . '"><svg class="lv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 15v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/><path d="m8 11 4 4 4-4"/><path d="M12 15V4"/></svg></button></form>';
				}
				$out .= '</div>';
			}
			$out .= '</div>';
		}
		return $out;
	}

	/**
	 * Download an access or error log of one of the account's domains (the vhost ID comes from the inDownload field).
	 *
	 * Sends the file and exits; sets $notmine or $notfile otherwise.
	 *
	 * @global runtime_controller $controller The request controller.
	 * @global db_driver $zdbh The panel's database connection.
	 * @param string $mode 'access' for the access log; anything else for the error log.
	 * @return void
	 */
	static function ActionProcess($mode)
	{
		global $controller;
		global $zdbh;
		$currentuser = ctrl_users::GetUserDetail();
		$id = $controller->GetControllerRequest('FORM', 'inDownload');
		$sql = $zdbh->prepare("SELECT vh_name_vc FROM x_vhosts WHERE vh_acc_fk = :uid AND vh_id_pk = :id AND vh_deleted_ts IS NULL");
		$sql->bindParam(':uid', $currentuser['userid']);
		$sql->bindParam(':id', $id);
		$sql->execute();
		$domain = $sql->fetchColumn();
		if (!$domain) {
			self::$notmine = true;
			return;
		}
		$filepath = self::LogPath($currentuser['username'], $domain, $mode);
		if (is_file($filepath) && !is_link($filepath)) {
			self::downloadFile($filepath);
		}
		self::$notfile = true;
	}

	/**
	 * Handle the error log download POST.
	 *
	 * @return void
	 */
	static function doErrorLogActions()
	{
		self::ActionProcess('error');
	}

	/**
	 * Handle the access log download POST.
	 *
	 * @return void
	 */
	static function doAccessLogActions()
	{
		self::ActionProcess('access');
	}

	/**
	 * Template HTML: the error message of the last action.
	 *
	 * @return string|null HTML message, or null when there is nothing to show.
	 */
	static function getResult()
	{
		if (!fs_director::CheckForEmptyValue(self::$notmine)) {
			return ui_sysmessage::shout(ui_language::translate("That domain does not belong to your account."), "zannounceerror");
		}
		if (!fs_director::CheckForEmptyValue(self::$notfile)) {
			return ui_sysmessage::shout(ui_language::translate("That log does not exist yet."), "zannounceerror");
		}
		return;
	}
}
