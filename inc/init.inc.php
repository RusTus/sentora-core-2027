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
    // An unknown user still goes through the same work (and the same answer)
    $crypto->SetSalt($result ? $result['ac_passsalt_vc'] : runtime_randomstring::randomHash(22));
    $secure_password = $crypto->CryptParts($crypto->Crypt())->Hash;

    // "Remember me" is only set once the whole login (with any second step) has succeeded
    $loggedInUid = ctrl_auth::Authenticate($_POST['inUsername'], $secure_password, false, false, $inSessionSecuirty);
    if (!$loggedInUid) {
        ctrl_loginguard::Failed($_POST['inUsername']);
        header("location: ./?invalidlogin");
        exit();
    }
    ctrl_loginguard::Succeeded($_POST['inUsername']);
    if (ctrl_twofactor::Required($loggedInUid)) {
        // Password is right; the account is not logged in until the code is too
        unset($_SESSION['zpuid']);
        $_SESSION['zp2fa'] = array('uid' => (int) $loggedInUid, 'user' => (string) $_POST['inUsername'],
            'remember' => $rememberdetails, 'secure' => $inSessionSecuirty, 'time' => time());
        header("location: ./?twofactor");
        exit();
    }
    if ($rememberdetails) {
        ctrl_loginguard::Remember($loggedInUid);
    }
}

// Second login step: the code from the authenticator app
if (isset($_SESSION['zp2fa']) && empty($_SESSION['zpuid'])) {
    $pending = $_SESSION['zp2fa'];
    if (time() - (int) $pending['time'] > ctrl_twofactor::PENDING_SECONDS || !ctrl_twofactor::Required($pending['uid'])) {
        unset($_SESSION['zp2fa']);
        header("location: ./?invalidlogin");
        exit();
    }
    if (isset($_POST['inTwoFactorCode'])) {
        runtime_csfr::Protect();
        $key = '2fa:' . $pending['user'];
        if (ctrl_loginguard::IsLocked($key)) {
            unset($_SESSION['zp2fa']);
            header("location: ./?invalidlogin");
            exit();
        }
        if (ctrl_twofactor::Verify($pending['uid'], $_POST['inTwoFactorCode'])) {
            unset($_SESSION['zp2fa']);
            ctrl_loginguard::Succeeded($key);
            ctrl_auth::SetUserSession($pending['uid'], $pending['secure']);
            if ($pending['remember']) {
                ctrl_loginguard::Remember($pending['uid']);
            }
            header("location: ./");
            exit();
        }
        ctrl_loginguard::Failed($key);
        header("location: ./?twofactor&wrong");
        exit();
    }
    $tfWrong = isset($_GET['wrong']);
    $tfTheme = 'Sentora_Default';
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Control Panel - Login code</title>
<link href="etc/styles/<?php echo $tfTheme; ?>/global-css/bootstrap.min.css" rel="stylesheet" type="text/css">
<style>body{background:#f4f4f4} .box{max-width:380px;margin:12vh auto;background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px 28px}
.code{font-size:28px;letter-spacing:6px;text-align:center;font-family:monospace}</style></head>
<body><div class="box">
<h3>Login code</h3>
<p>Enter the 6-digit code from the authenticator app for <strong><?php echo htmlspecialchars($pending['user'], ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
<?php if ($tfWrong) { ?><div class="alert alert-danger">That code is not right, or it was already used. Try the next one.</div><?php } ?>
<form method="post" action="./?twofactor" autocomplete="off">
<input class="form-control code" name="inTwoFactorCode" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
<?php echo runtime_csfr::Token(); ?>
<p style="margin-top:16px"><button class="btn btn-primary btn-block" type="submit">Log in</button></p>
<p class="text-muted" style="margin:0"><a href="./?logout">Cancel</a></p>
</form></div></body></html>
<?php
    exit();
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
