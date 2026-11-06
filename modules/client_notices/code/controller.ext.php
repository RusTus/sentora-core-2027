<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 *
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Client Notices module: lets a reseller set the notice shown to its clients.
 */
class module_controller extends ctrl_module
{

    /**
     * Load the TinyMCE editor for the notice textarea (<@ Init @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string HTML <script> tags.
     */
    static function getInit() {
        global $controller;
		// TinyMCE JS
		$line = '<script type="text/javascript" src="modules/'.$controller->GetControllerRequest('URL', 'module').'/code/tinymce/tinymce.min.js"></script>
';
		$line .= '<script type="text/javascript">
			tinymce.init({
				selector: "textarea",
				plugins: [
					"advlist autolink lists link charmap",
					"searchreplace visualblocks code",
					"insertdatetime media table contextmenu paste"
				],
				toolbar: "insertfile undo redo | styleselect | bold italic | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image"
			});
			</script>
			';
        return $line;
    }
	
    /**
     * Save an account's notice text.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param string $notice The notice text (HTML from the editor).
     * @return bool Always true.
     */
    static function ExectuteUpdateNotice($uid, $notice)
    {
        global $zdbh;
        $sql = $zdbh->prepare("
            UPDATE x_accounts
            SET ac_notice_tx = :notice
            WHERE ac_id_pk = :uid");
        $sql->bindParam(':notice', $notice);
        $sql->bindParam(':uid', $uid);
        $sql->execute();
        return true;
    }

    /**
     * Return an account's notice text, XSS-cleaned.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $rid The account ID (the reseller whose notice is shown).
     * @return string|false The notice, or false when the account is not found.
     */
    static function ExecuteShowNotice($rid)
    {
        global $zdbh;
        //$result = $zdbh->query("SELECT ac_notice_tx FROM x_accounts WHERE ac_id_pk = :rid")->Fetch();
        $sql = $zdbh->prepare("SELECT ac_notice_tx FROM x_accounts WHERE ac_id_pk = :rid");
        $sql->bindParam(':rid', $rid);
        $sql->execute();
        $result = $sql->fetch();

        if ($result) {
            return runtime_xss::xssClean($result['ac_notice_tx']);
        } else {
            return false;
        }
    }

    /**
     * End 'worker' methods.
     */

    /**
     * Return the notice of the current user's reseller (<@ CurrentNoticeText @>).
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return string|false The notice, or false when not found.
     */
    static function getCurrentNoticeText()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
		return self::ExecuteShowNotice($currentuser['resellerid']);
    }

    /**
     * Save the posted notice for the current user, then redirect back and exit.
     *
     * @global runtime_controller $controller The request controller.
     * @return void Ends with a redirect header and exit.
     */
    static function doUpdateMessage()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        self::ExectuteUpdateNotice($currentuser['userid'], $formvars['inNotice']);
        header("location: ./?module=" . $controller->GetCurrentModule() . "&saved=true");
        exit;
    }

    /**
     * Webinterface sudo methods.
     */
}