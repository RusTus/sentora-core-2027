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
 * FAQs module: lists FAQ items and lets administrators and resellers add and delete them.
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True when an added FAQ had no question or answer (shown by getResult()). */
    static $error;
    /** @var bool|null True when a FAQ was deleted (shown by getResult()). */
    static $delete;
    /** @var bool|null True when a FAQ was added (shown by getResult()). */
    static $ok;

    /**
     * List all FAQ items that are not deleted, HTML-escaped.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>>|false Rows with 'question', 'answer' (HTML), 'reseller', 'global', 'id'; false when there are none.
     */
    public static function getFAQS()
    {
        global $zdbh;
        $sql = "SELECT * FROM x_faqs WHERE fq_question_tx IS NOT NULL AND fq_deleted_ts IS NULL";
        $numrows = $zdbh->query($sql);
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->execute();
            while ($rowfaqs = $sql->fetch()) {
                array_push($res, array(
                    'question' => htmlspecialchars((string) $rowfaqs['fq_question_tx'], ENT_QUOTES, 'UTF-8'),
                    'answer' => nl2br(htmlspecialchars((string) $rowfaqs['fq_answer_tx'], ENT_QUOTES, 'UTF-8')),
                    'reseller' => $rowfaqs['fq_acc_fk'],
                    'global' => $rowfaqs['fq_global_in'],
                    'id' => $rowfaqs['fq_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List one FAQ item by its ID, HTML-escaped (not used by the module).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $fid The FAQ ID.
     * @return array<int, array<string, mixed>>|false Rows with 'question', 'answer', 'reseller', 'global', 'id'; false when none.
     */
    public function ListCurrentFAQ($fid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_faqs WHERE fq_id_pk=:fid IS NOT NULL AND fq_deleted_ts IS NULL";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':fid', $fid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':fid', $fid);
            $res = array();
            $sql->execute();
            while ($rowfaqs = $sql->fetch()) {
                array_push($res, array(
                    'question' => htmlspecialchars((string) $rowfaqs['fq_question_tx'], ENT_QUOTES, 'UTF-8'),
                    'answer' => nl2br(htmlspecialchars((string) $rowfaqs['fq_answer_tx'], ENT_QUOTES, 'UTF-8')),
                    'reseller' => $rowfaqs['fq_acc_fk'],
                    'global' => $rowfaqs['fq_global_in'],
                    'id' => $rowfaqs['fq_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * List the FAQ items the current user may see (<% loop UserFAQS %>).
     *
     * Items of the user's reseller, the user's own, global ones, or all for
     * administrators; 'allowdelete' holds a delete button when the user may delete it.
     *
     * @global db_driver $zdbh The panel's database connection (not used).
     * @global runtime_controller $controller The request controller (not used).
     * @return array<int, array<string, mixed>>|false Rows with 'question', 'answer', 'reseller', 'global', 'allowdelete', 'id'; false when there are no FAQs.
     */
    public static function getUserFAQS()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $faqs = self::getFAQS();
        if ($faqs) {
            $res = array();
            foreach ($faqs as $faq) {
                $createdby = NULL;
                if ($faq['reseller'] == $currentuser['resellerid'] || $faq['reseller'] == $currentuser['userid'] || $currentuser['usergroup'] == "Administrators" || $faq['global'] <> 0) {
                    if ($faq['reseller'] == $currentuser['userid'] || $currentuser['usergroup'] == "Administrators") {
                        $allowdelete = "<input type=\"image\" src=\"" . self::getModulePath() . "assets/delete_small.png\" name=\"inDelete_" . $faq['id'] . "\" id=\"inDelete_" . $faq['id'] . "\" value=\"" . $faq['id'] . "\" title=\"DELETE FAQ\">";
                        if ($currentuser['usergroup'] == "Administrators") {
                            $createdbyid = ctrl_users::GetUserDetail($faq['reseller']);
                            $createdby = " (" . $createdbyid['username'] . ")";
                        }
                    } else {
                        $allowdelete = NULL;
                    }
                    array_push($res, array(
                        'question' => $faq['question'] . $createdby,
                        'answer' => $faq['answer'],
                        'reseller' => $faq['reseller'],
                        'global' => $faq['global'],
                        'allowdelete' => $allowdelete,
                        'id' => $faq['id']));
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Check whether the current user may add FAQ items (<% if AddFAQS %>).
     *
     * @global runtime_controller $controller The request controller (not used).
     * @return bool True for administrators and resellers.
     */
    public static function getAddFAQS()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        if ($currentuser['usergroup'] == "Administrators" || $currentuser['usergroup'] == "Resellers") {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Handle a delete button: redirect to the confirmation page for that FAQ and exit.
     *
     * @global runtime_controller $controller The request controller.
     * @return void Ends with a redirect and exit when a delete button was pressed.
     */
    static function doDeleteFaq()
    {
        global $controller;
        runtime_csfr::Protect();
        $faqs = self::getFAQS();
        //print_r($_POST);
        foreach ($faqs as $faq) {
            if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDelete_' . $faq['id'] . '_x'))) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $faq['id'] . "");
                exit;
                //self::ExecuteDeleteFaq($faq['id']);
            }
        }
    }

    /**
     * May the logged-in account delete this FAQ?
     *
     * The same rule the list uses to show the delete button: the FAQ's own author, or an
     * administrator. The ID comes from the form, so it is checked again on delete.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param mixed $fq_id_pk The FAQ ID from the form or URL.
     * @return bool True when the FAQ exists, is not deleted, and the account may delete it.
     */
    static function CanDeleteFaq($fq_id_pk)
    {
        global $zdbh;
        if (!is_scalar($fq_id_pk) || !ctype_digit((string) $fq_id_pk)) {
            return false;
        }
        $currentuser = ctrl_users::GetUserDetail();
        $sql = $zdbh->prepare("SELECT fq_acc_fk FROM x_faqs WHERE fq_id_pk = :id AND fq_deleted_ts IS NULL");
        $sql->bindParam(':id', $fq_id_pk);
        $sql->execute();
        $owner = $sql->fetchColumn();
        if ($owner === false) {
            return false;
        }
        return $currentuser['usergroup'] == "Administrators" || (string) $owner === (string) $currentuser['userid'];
    }

    /**
     * Delete the FAQ confirmed on the form.
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when deleted.
     */
    static function doConfirmDeleteFAQ()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // Only an FAQ the account may delete (its own, or any for an administrator)
        if (!isset($formvars['inDelete']) || !self::CanDeleteFaq($formvars['inDelete'])) {
            return false;
        }
        if (self::ExecuteDeleteFaq($formvars['inDelete']))
            return true;
        return false;
    }

    /**
     * Add the FAQ item posted on the form (global when added by an administrator).
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when the account may not add FAQs (not an administrator or reseller), else null.
     */
    static function doAddFaq()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        // Only administrators and resellers add FAQs (the form is shown to them only)
        if (!self::getAddFAQS()) {
            return false;
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inAdd'))) {
            $question = $controller->GetControllerRequest('FORM', 'question');
            $answer = $controller->GetControllerRequest('FORM', 'answer');
            $userid = $currentuser['userid'];
            if ($currentuser['usergroup'] == "Administrators") {
                $global = 1;
            } else {
                $global = 0;
            }
            self::ExecuteAddFaq($question, $answer, $userid, $global);
        }
    }

    /**
     * Return the FAQ ID from the URL ('other'), for the delete confirmation (<@ EditCurrentFAQID @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return mixed The URL value, or '' when none.
     */
    static function getEditCurrentFAQID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            return $controller->GetControllerRequest('URL', 'other');
        } else {
            return "";
        }
    }

    /**
     * Mark a FAQ item as deleted and set the $delete flag.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $fq_id_pk The FAQ ID.
     * @return bool Always true.
     */
    static function ExecuteDeleteFaq($fq_id_pk)
    {
        global $zdbh;
        $sql = "UPDATE x_faqs SET fq_deleted_ts=:time WHERE fq_id_pk=:fq_id_pk";
        $sql = $zdbh->prepare($sql);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':fq_id_pk', $fq_id_pk);
        $sql->execute();
        self::$delete = true;
        return true;
    }

    /**
     * Add a FAQ item; sets $ok, or $error when the question or answer is empty.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $question The question.
     * @param string $answer The answer.
     * @param int|string $userid The account ID of the author.
     * @param int $global 1 to show it to everyone, else 0.
     * @return bool True when added.
     */
    static function ExecuteAddFaq($question, $answer, $userid, $global)
    {
        global $zdbh;
        if ($question != "" && $answer != "") {
            $sql = "INSERT INTO x_faqs (fq_acc_fk, fq_question_tx, fq_answer_tx, fq_global_in, fq_created_ts) VALUES (:userid, :question, :answer, :global, :time)";
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $userid);
            $sql->bindParam(':question', $question);
            $sql->bindParam(':answer', $answer);
            $sql->bindParam(':global', $global);
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->execute();
            self::$ok = true;
            return true;
        } else {
            self::$error = true;
            return false;
        }
    }

    /**
     * Check whether the delete confirmation is shown (<% if isDeleteFAQ %>).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True when the URL has show=Delete for an FAQ the account may delete.
     */
    static function getisDeleteFAQ()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "Delete") && isset($urlvars['other']) && self::CanDeleteFaq($urlvars['other']))
            return true;
        return false;
    }

    /**
     * Return the message for the last action (<@ Result @>).
     *
     * @return string|null HTML message, or null when there is none.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$error)) {
            return ui_sysmessage::shout(ui_language::translate("You need to enter a question and an answer to add a FAQ item!"), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$delete)) {
            return ui_sysmessage::shout(ui_language::translate("FAQ item was deleted successfully!"), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("FAQ item was added successfully!"), "zannounceok");
        }
        return;
    }

}
