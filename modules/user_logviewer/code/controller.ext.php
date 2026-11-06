<?php
/**
*
* @package user_logviewer
* @version 100
* @author Simon Mora - samt2497
*
*/

//--
class module_controller extends ctrl_module
{
	/** @var bool|null True when the requested domain is not the account's own. */
	static $notmine;
	/** @var bool|null True when the requested log file does not exist. */
	static $notfile;

	/** @var string|null File name of the log being previewed. */
	static $CurrentLogFile;
	/** @var string|false|null The last lines of the log being previewed (false when it could not be read). */
	static $PreviewBuffer;

	/** @var bool|null True when a log preview is shown. */
	static $preview;
	/** @var mixed Not used in this module. */
	static $pbuffer;
	/** @var int How many lines of a log the preview shows. */
	static $preview_lines = 50;
	// The 'worker' methods.
	/**
	 * Send a file as a download and exit.
	 *
	 * @param string $filepath The full path of the file.
	 * @return void Never returns (ends with die()).
	 */
	static function downloadFile($filepath){
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename='.basename($filepath));
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . filesize($filepath));
		readfile($filepath);
		die();
	}
	/**
	 * List vhosts: all domains when $uid is 0, else all of the account's vhosts.
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @param int $uid Optional. The account ID, or 0 for every account's domains. Default 0.
	 * @return array<int, array<string, mixed>>|false One row per vhost: 'uid', 'name', 'directory', 'active', 'id'; false when there are none.
	 */
	static function ListVhosts($uid = 0)
	{
		global $zdbh;
		if ($uid == 0) {
		$sql = "SELECT * FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_type_in=1 ORDER BY vh_name_vc ASC";
		$numrows = $zdbh->prepare($sql);
		} else {
		$sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL ORDER BY vh_name_vc ASC";
		$numrows = $zdbh->prepare($sql);
		$numrows->bindParam(':uid', $uid);
		}
		//$numrows = $zdbh->query($sql);
		$numrows->execute();
		if ($numrows->fetchColumn() <> 0) {
		if ($uid == 0) {
		$sql = $zdbh->prepare($sql);
		} else {
		$sql = $zdbh->prepare($sql);
		$sql->bindParam(':uid', $uid);
		}
		$res = array();
		$sql->execute();
		while ($rowdomains = $sql->fetch()) {
		array_push($res, array(
		'uid' => $rowdomains['vh_acc_fk'],
		'name' => $rowdomains['vh_name_vc'],
		'directory' => $rowdomains['vh_directory_vc'],
		'active' => $rowdomains['vh_active_in'],
		'id' => $rowdomains['vh_id_pk'],
		));
		}
			return $res;
		} else {
			return false;
		}
	}
	// End 'worker' methods.
	// Webinterface sudo methods.

	//-- Some Tail-like function from lorenzos
	/**
	 * Read the last lines of a file, like tail.
	 *
	 * @param string $filepath The full path of the file.
	 * @param int $lines Optional. How many lines to return. Default 1.
	 * @param bool $adaptive Optional. Pick the read buffer size from the number of lines. Default true.
	 * @return string|false The lines (trimmed), or false when the file cannot be opened.
	 */
	static function tailCustom($filepath, $lines = 1, $adaptive = true) {
		$f = @fopen($filepath, "rb");
		if ($f === false) return false;
		if (!$adaptive) $buffer = 4096;
		else $buffer = ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096));
		fseek($f, -1, SEEK_END);
		if (fread($f, 1) != "\n") $lines -= 1;
		$output = '';
		$chunk = '';
		while (ftell($f) > 0 && $lines >= 0) {
		$seek = min(ftell($f), $buffer);
		fseek($f, -$seek, SEEK_CUR);
		$output = ($chunk = fread($f, $seek)) . $output;
		fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);
		$lines -= substr_count($chunk, "\n");
		}
		while ($lines++ < 0) {
		$output = (substr($output, strpos($output, "\n") + 1));
		}
		fclose($f);
		return trim($output);
	} 
	/**
	 * Template loop: the logged-in account's domains with their access log.
	 *
	 * @return array<int, array<string, mixed>>|false One row per vhost: 'name', 'directory', 'active', 'filepath' (file name), 'fsize', 'id'; false when there are none.
	 */
	static function getLogAccessList()
	{
		$currentuser = ctrl_users::GetUserDetail();
		$res = array();
		$domains = self::ListVhosts($currentuser['userid']);
		if (!fs_director::CheckForEmptyValue($domains)) {
		foreach ($domains as $row) {
		$filepath = ('/var/sentora/logs/domains/'.$currentuser['username'].'/'.$row['name'].'-access.log');
		if(file_exists($filepath)){
		//fs_director::ShowHumanFileSize($used)
			$fsize = fs_director::ShowHumanFileSize(filesize($filepath));
		}else{
			$fsize = 'Not Found';
		}
		$res[] = array(
			'name' => $row['name'],
			'directory' => $row['directory'],
			'active' => $row['active'],
			'filepath' => basename($filepath),
			'fsize' => $fsize,
			'id' => $row['id']
		);
		}
		return $res;
		} else {
		return false;
		}
	}
	/**
	 * Template loop: the logged-in account's domains with their error log.
	 *
	 * @return array<int, array<string, mixed>>|false One row per vhost: 'name', 'directory', 'active', 'filepath' (file name), 'fsize', 'id'; false when there are none.
	 */
	static function getLogErrorList()
	{
		$currentuser = ctrl_users::GetUserDetail();
		$res = array();
		$domains = self::ListVhosts($currentuser['userid']);
		if (!fs_director::CheckForEmptyValue($domains)) {
		foreach ($domains as $row) {
		$filepath = ('/var/sentora/logs/domains/'.$currentuser['username'].'/'.$row['name'].'-error.log');
		if(file_exists($filepath)){
		//fs_director::ShowHumanFileSize($used)
			$fsize = fs_director::ShowHumanFileSize(filesize($filepath));
		}else{
			$fsize = 'Not Found';
		}
		$res[] = array(
			'name' => $row['name'],
			'directory' => $row['directory'],
			'active' => $row['active'],
			'filepath' => basename($filepath),
			'fsize' => $fsize,
			'id' => $row['id']
		);
		}
		return $res;
		} else {
		return false;
		}
	}
	/**
	 * Template flag: a log preview is shown.
	 *
	 * @return bool
	 */
	static function getisPreviewLog(){
		return !fs_director::CheckForEmptyValue(self::$preview);
	}
	/**
	 * Template value: the file name of the log being previewed.
	 *
	 * @return string|null
	 */
	static function getCurrentLogFile(){
		return self::$CurrentLogFile;
	}
	/**
	 * Template value: the previewed log lines, HTML-escaped.
	 *
	 * @return string
	 */
	static function getPreviewBuffer(){
		// Log lines contain whatever visitors sent (URLs, user agents): shown as text
		return htmlspecialchars((string) self::$PreviewBuffer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	/**
	 * Preview or download an access or error log of one of the account's vhosts.
	 *
	 * The vhost ID comes from the inPreview or inDownload form field. A download sends the file and exits.
	 *
	 * @global runtime_controller $controller The request controller.
	 * @global db_driver $zdbh The panel's database connection.
	 * @param string $mode 'access' for the access log; anything else for the error log.
	 * @return void
	 */
	static function ActionProcess($mode){
		$currentuser = ctrl_users::GetUserDetail();
		global $controller;
		global $zdbh;	
		$download = false;
		$id = $controller->GetControllerRequest('FORM', 'inPreview');
		if($id <= 0){
			$id = $controller->GetControllerRequest('FORM', 'inDownload');
			$download = true;
		}
		$uid = $currentuser['userid'];
		$sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_id_pk=:id AND vh_deleted_ts IS NULL";
		$query = $zdbh->prepare($sql);
		$query->bindParam(':uid', $uid);
		$query->bindParam(':id', $id);
		$query->execute();
		if ($data = $query->fetch()) {
			switch($mode){
				case 'access':
					$filepath = ('/var/sentora/logs/domains/'.$currentuser['username'].'/'.$data['vh_name_vc'].'-access.log');			
					break;
				default:
					$filepath = ('/var/sentora/logs/domains/'.$currentuser['username'].'/'.$data['vh_name_vc'].'-error.log');
					break;
			}
			self::$preview = true;
			if(file_exists($filepath)){
				if($download){
					self::downloadFile($filepath);
				}else{
					self::$CurrentLogFile = basename($filepath);
					self::$PreviewBuffer = self::tailCustom($filepath,self::$preview_lines);
				}
			}else{		
				self::$notfile = true;	
			}
		}else{// No domains? well something is wrong for sure
			self::$notmine = true;
		}
	}
	/**
	 * Handle the error log form POST (preview or download).
	 *
	 * @return void
	 */
	static function doErrorLogActions(){
		self::ActionProcess('error');
	}
	/**
	 * Handle the access log form POST (preview or download).
	 *
	 * @return void
	 */
	static function doAccessLogActions(){
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
			return ui_sysmessage::shout(ui_language::translate("Unable to get log preview, this domain does not belong to you."), "zannounceerror");
		}
		if (!fs_director::CheckForEmptyValue(self::$notfile)) {
			return ui_sysmessage::shout(ui_language::translate("The log does not exit yet."), "zannounceerror");
		}
		return;
	}
	// Webinterface sudo methods.
}