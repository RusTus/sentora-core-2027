<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Logger class logs infomation passed to it and can record and report debug infomation in a number of ways.
 * @package zpanelx
 * @subpackage dryden -> debug
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
class debug_logger {

    /**
     * @var string The type of method to use to store the debug infomation (screen, file, email or database).
     */
    var $method;

    /**
     * @var mixed Any additonal (longer) infomation such as full exception code or error stack (string, Exception or null).
     */
    var $mextra;

    /**
     * @var string|null The general description of the error.
     */
    var $detail;

    /**
     * @var string|int A log code eg. (ERR4433), 0 when unset.
     */
    var $logcode;

    /**
     * Set up the logger with the file method and empty log details.
     */
    function __construct() {
        $this->method = "file";
        $this->mextra = null;
        $this->detail = null;
        $this->logcode = 0;
    }

    /**
     * Writes the log infomation out to a predefined logging medium (from $this->method)
     * @author Bobby Allen (ballen@bobbyallen.me)
     * Method "screen" dies with the message; "db" returns true; all other methods return null.
     * @global db_driver $zdbh The ZPX database handle.
     * @return true|null True after a database write, null otherwise.
     */
    function writeLog() {
        global $zdbh;
        runtime_hook::Execute('OnWriteErrorLog');
        if ($this->method == "screen") {
            die($this->logcode . ' - ' . $this->detail);
        } elseif ($this->method == "file") {
            fs_filehandler::AddTextToFile(ctrl_options::GetSystemOption('logfile'), date('c') . ' - ' . $this->logcode . ' - ' . $this->detail, 1);
        } elseif ($this->method == "email") {
            $email_log = new sys_email();
            $email_log->Subject = "Sentora Error Log";
            $email_log->Body = "" . date('c') . ' - ' . $this->logcode . ' - ' . $this->detail . "";
            $email_log->AddAddress(ctrl_options::GetSystemOption('email_from_address'));
            $email_log->SendEmail();
        } elseif ($this->method == "db") {
            $statement = "INSERT INTO x_logs (lg_user_fk, lg_code_vc, lg_module_vc, lg_detail_tx, lg_stack_tx) VALUES (0, '" . $this->logcode . "', 'NA', '" . $this->detail . "', '" . $this->mextra . "')";
            if ($zdbh->exec($statement)) {
                $retval = true;
            } else {
                $retval = false;
            }
            try {
                $statement = "INSERT INTO x_logs (lg_user_fk, lg_code_vc, lg_module_vc, lg_detail_tx, lg_stack_tx) VALUES (0, '" . $this->logcode . "', 'NA', '" . $this->detail . "', '" . $this->mextra . "')";
                if ($zdbh->exec($statement) > 0) {
                    $retval = true;
                } else {
                    $retval = false;
                }
            } catch (Exception $e) {
                $temp_log_obj->method = "text";
                $temp_log_obj->logcode = "012";
                $temp_log_obj->detail = "Unable to log infomation to the required place (in the database)";
                $temp_log_obj->mextra = $e;
                $temp_log_obj->writeLog();
            }
            return true;
        } else {
            echo $this->logcode . " - " . $this->detail . " - " . $this->mextra;
        }
        return;
    }

    /**
     * Resets debug infomation - be careful to not use before writeLog() as it will clear the log!
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return bool 
     */
    function reset() {
        $this->mextra = null;
        $this->detail = null;
        $this->logcode = 0;
        return true;
    }

    /**
     * Checks if there is infomation in the object to be reported on (If some debug/error message is pending).
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return bool 
     */
    function hasInfo() {
        if ($this->detail != null)
            return true;
        return false;
    }

}

?>
