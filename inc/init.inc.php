<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * The web gui initiation script.
 * @package zpanelx
 * @subpackage core
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
global $controller, $zdbh, $zlo;
$controller = new runtime_controller();

runtime_hook::Execute('OnBoot');

$zlo->method = ctrl_options::GetSystemOption('logmode');
if ($zlo->hasInfo()) {
    $zlo->writeLog();
    $zlo->reset();
}

if (isset($_GET['logout'])) {
    runtime_hook::Execute('OnLogout');
    ctrl_auth::KillSession();
    ctrl_auth::KillCookies();
    header("location: ./?loggedout");
    exit;
}

if (isset($_GET['returnsession'])) {
    if (isset($_SESSION['ruid'])) {
        ctrl_auth::SetUserSession($_SESSION['ruid'], runtime_sessionsecurity::getSessionSecurityEnabled());
        $_SESSION['ruid'] = null;
    }
    header("location: ./");
    exit;
}

if (isset($_POST['inForgotPassword'])) {
    runtime_csfr::Protect();
    $randomkey = runtime_randomstring::randomHash();
    $forgotPass = runtime_xss::xssClean($_POST['inForgotPassword']);
    $sth = $zdbh->prepare("SELECT ac_id_pk, ac_user_vc, ac_email_vc FROM x_accounts WHERE ac_email_vc = :forgotPass AND ac_deleted_ts IS NULL");
    $sth->bindParam(':forgotPass', $forgotPass);
    $sth->execute();
    $rows = $sth->fetchAll();
    if ($rows) {
        $result = $rows['0'];
        // The key is stored with the time it was made; it works for one hour and once
        $stored = $randomkey . '.' . time();
        $upd = $zdbh->prepare("UPDATE x_accounts SET ac_resethash_tx = :hash WHERE ac_id_pk = :uid");
        $upd->bindParam(':hash', $stored);
        $upd->bindParam(':uid', $result['ac_id_pk']);
        $upd->execute();
        if (isset($_SERVER['HTTPS'])) {
            $protocol = 'https://';
        } else {
            $protocol = 'http://';
        }
        $port = ctrl_options::GetSystemOption('sentora_port');
        $domain = ctrl_options::GetSystemOption('sentora_domain');
        # If using non-standard port
        if ($port !== "80" && $port !== "443" && !empty($port)) {
            # Append port to domain
            $domain .= ":" . $port;
        }
        $phpmailer = new sys_email();
        $phpmailer->Subject = "Hosting Panel Password Reset";
        $phpmailer->Body = "Hi " . $result['ac_user_vc'] . ",
            
You, or somebody pretending to be you, has requested a password reset link to be sent for your web hosting control panel login.
        
If you wish to proceed with the password reset on your account, please use the link below to be taken to the password reset page.
            
" . $protocol . $domain . "/?resetkey=" . $randomkey . "


                ";
        $phpmailer->AddAddress($result['ac_email_vc']);
        $phpmailer->SendEmail();
        runtime_hook::Execute('OnRequestForgotPassword');
    }
}

if (isset($_POST['inConfEmail'])) {
    runtime_csfr::Protect();
    $sql = $zdbh->prepare("SELECT ac_id_pk, ac_resethash_tx FROM x_accounts WHERE ac_email_vc = :email AND ac_resethash_tx IS NOT NULL AND ac_resethash_tx <> '' AND ac_deleted_ts IS NULL");
    $sql->bindParam(':email', $_POST['inConfEmail']);
    $sql->execute();
    $result = false;
    $givenKey = isset($_GET['resetkey']) ? (string) $_GET['resetkey'] : '';
    while ($row = $sql->fetch()) {
        $parts = explode('.', (string) $row['ac_resethash_tx'], 2);
        // Constant-time compare, and only within an hour of the request
        if ($givenKey !== '' && count($parts) == 2 && hash_equals($parts[0], $givenKey) && (time() - (int) $parts[1]) <= 3600) {
            $result = $row;
            break;
        }
    }
    // Same rule as the panel's own password change: at least 8 characters
    if (strlen((string) $_POST['inNewPass']) < 8) {
        $result = false;
    }

    $crypto = new runtime_hash;
    $crypto->SetPassword($_POST['inNewPass']);
    $randomsalt = $crypto->RandomSalt();
    $crypto->SetSalt($randomsalt);
    $secure_password = $crypto->CryptParts($crypto->Crypt())->Hash;

    if ($result) {
        $sql = $zdbh->prepare("UPDATE x_accounts SET ac_resethash_tx = NULL, ac_pass_vc = :password, ac_passsalt_vc = :salt WHERE ac_id_pk = :uid");
        $sql->bindParam(':password', $secure_password);
        $sql->bindParam(':salt', $randomsalt);
        $sql->bindParam(':uid', $result['ac_id_pk']);
        $sql->execute();
        runtime_hook::Execute('OnSuccessfulPasswordReset');
    } else {
        runtime_hook::Execute('OnFailedPasswordReset');
    }
    header("location: ./?passwordreset");
    exit();
}

if (isset($_POST['inUsername'])) {
    if (ctrl_options::GetSystemOption('login_csfr') == 'false')
        runtime_csfr::Protect();

    $rememberdetails = isset($_POST['inRemember']);
    $inSessionSecuirty = isset($_POST['inSessionSecuirty']);

    // Too many wrong passwords for this account or from this address: refuse for
    // 15 minutes (same answer as a wrong password, so it reveals nothing)
    if (ctrl_loginguard::IsLocked($_POST['inUsername'])) {
        runtime_hook::Execute('OnBadUserLogin');
        header("location: ./?invalidlogin");
        exit();
    }

    $sql = $zdbh->prepare("SELECT ac_passsalt_vc FROM x_accounts WHERE ac_user_vc = :username AND ac_deleted_ts IS NULL");
    $sql->bindParam(':username', $_POST['inUsername']);
    $sql->execute();
    $result = $sql->fetch();
    $crypto = new runtime_hash;
    $crypto->SetPassword($_POST['inPassword']);
    $crypto->SetSalt($result['ac_passsalt_vc']);
    $secure_password = $crypto->CryptParts($crypto->Crypt())->Hash;

    if (!ctrl_auth::Authenticate($_POST['inUsername'], $secure_password, $rememberdetails, false, $inSessionSecuirty)) {
        ctrl_loginguard::Failed($_POST['inUsername']);
        header("location: ./?invalidlogin");
        exit();
    }
    ctrl_loginguard::Succeeded($_POST['inUsername']);
}

// "Remember me": a random token (the old cookies held the password hash)
if (!isset($_SESSION['zpuid'])) {
    ctrl_loginguard::LoginFromCookie();
}

if (!isset($_SESSION['zpuid'])) {
    ctrl_auth::RequireUser();
}


runtime_hook::Execute('OnBeforeControllerInit');
$controller->Init();
ui_templateparser::Generate("etc/styles/" . ui_template::GetUserTemplate());
?>
