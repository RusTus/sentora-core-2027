<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Email class used for sending out emails from ZPanel. This class extends on the PHPMailer library included in etc/lib/PHPMailer!
 * @package zpanelx
 * @subpackage dryden -> sys
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
// PHPMailer 7 (the 5.2.7 Sentora shipped dated from 2013 and had several published
// security flaws). Without exceptions: send() returns false and sets ErrorInfo.
require_once __DIR__ . '/../../etc/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../../etc/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../../etc/lib/PHPMailer/src/SMTP.php';

class sys_email extends \PHPMailer\PHPMailer\PHPMailer {

    public function __construct() {
        parent::__construct(false);
        $this->CharSet = 'UTF-8';
        $this->XMailer = ' ';   // no X-Mailer header (it named the exact library version)
    }

    /**
     * Sends the email with the contents of the object (Body etc. set using the parant calls in phpMailer!)
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return boolean 
     */
    public function SendEmail() {
        $this->Mailer = ctrl_options::GetSystemOption('mailer_type');
        $this->From = ctrl_options::GetSystemOption('email_from_address');
        $this->FromName = ctrl_options::GetSystemOption('email_from_name');
        if (ctrl_options::GetSystemOption('email_smtp') <> 'false') {
            $this->IsSMTP();
            if (ctrl_options::GetSystemOption('smtp_auth') <> 'false') {
                $this->SMTPAuth = true;
                $this->Username = ctrl_options::GetSystemOption('smtp_username');
                $this->Password = ctrl_options::GetSystemOption('smtp_password');
            }
            if (ctrl_options::GetSystemOption('smtp_secure') <> 'false') {
                $this->SMTPSecure = ctrl_options::GetSystemOption('smtp_secure');
            }
            $this->Host = ctrl_options::GetSystemOption('smtp_server');
            $this->Port = ctrl_options::GetSystemOption('smtp_port');
            // PHPMailer checks the mail server's certificate. This server's own mail
            // server (Postfix) has a self-signed one until Sencrypt replaces it, and
            // nothing sent to it leaves the machine, so for this server only it is not
            // checked; any other mail server's certificate is.
            if (in_array(strtolower(trim((string) $this->Host)), array('localhost', '127.0.0.1', '::1', '[::1]'), true)) {
                $this->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
            }
        }

        ob_start();
        $send_resault = $this->Send();
        $error = trim(ob_get_contents() . ' ' . $this->ErrorInfo);
        ob_end_clean();
        if ($send_resault) {
            runtime_hook::Execute('OnSuccessfulSendEmail');
            return true;
        } else {
            $logger = new debug_logger();
            $logger->method = ctrl_options::GetSystemOption('logmode');
            $logger->logcode = "061";
            $logger->detail = 'Error sending email (using sys_email): ' . $error . '';
            $logger->writeLog();
            runtime_hook::Execute('OnFailedSendEmail');
            return false;
        }
    }

}

?>
