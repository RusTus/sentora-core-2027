<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Authentication class handles ZPanel authentication and handles user sessions.
 * @package zpanelx
 * @subpackage dryden -> controller
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
class ctrl_auth
{

    /**
     * Checks that the server has a valid session for the user if not it will redirect to the login screen.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * return bool
     */
    static function RequireUser()
    {
        global $zdbh;
        if (!isset($_SESSION['zpuid'])) {
            if (ctrl_loginguard::LoginFromCookie()) {
                return true;
            }
            runtime_hook::Execute('OnRequireUserLogin');
            $sqlQuery = "SELECT ac_usertheme_vc, ac_usercss_vc FROM
                         x_accounts WHERE
                         ac_user_vc = :zadmin";
            $bindArray = array(':zadmin' => 'zadmin');
            $zdbh->bindQuery($sqlQuery, $bindArray);
            $themeRow = $zdbh->returnRow();
            $loginTheme = ctrl_users::SafeTheme($themeRow['ac_usertheme_vc']);
            // The administrator's variation of that theme (for example light or dark), for
            // login pages that offer more than one; only a plain name ever reaches the page
            $loginCss = preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $themeRow['ac_usercss_vc']) ? (string) $themeRow['ac_usercss_vc'] : '';
            include 'etc/styles/' . ($loginTheme !== '' ? $loginTheme : 'Sentora_Default') . '/login.ztml';
            exit;
        }
        return true;
    }

    /**
     * Sets a user session ID.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param int $zpuid The Sentora user account ID to set the session as.
     * @return bool
     */
    static function SetUserSession($zpuid = 0, $sessionSecuirty = true)
    {
        $sessionSecuirty = runtime_sessionsecurity::getSessionSecurityEnabled();
        if (isset($zpuid)) {
            // New session id at login, so an id planted before login is worthless
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                session_regenerate_id(true);
            }
            $_SESSION['zpuid'] = $zpuid;
            if ($sessionSecuirty) {
                //Implamentation of session security
                runtime_sessionsecurity::setCookie();
                runtime_sessionsecurity::setUserIP();
                runtime_sessionsecurity::setUserAgent();
                runtime_sessionsecurity::setSessionSecurityEnabled(true);
            } else {
                //Implamentation of session security but set it as off
                runtime_sessionsecurity::setCookie();
                runtime_sessionsecurity::setUserIP();
                runtime_sessionsecurity::setUserAgent();
                runtime_sessionsecurity::setSessionSecurityEnabled(false);
            }

            return true;
        } else {
            return false;
        }
    }

    /**
     * Sets the value of a given named session variable, if does not exist will create the session variable too.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $name The name of the session variable to set.
     * @param string $value The value of the session variable to set.
     * @return boolean
     */
    static function SetSession($name, $value = "")
    {
        if (isset($name)) {
            $_SESSION['' . $name . ''] = $value;
            return true;
        } else {
            return false;
        }
    }

     /**
     * The main authentication mechanism, checks username and password against the database and logs the user in on a successful authenitcation request.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param string $username The username to use to authenticate with.
     * @param string $password The password to use to authenticate with.
     * @param bool $rememberMe Remember the password for 30 days? (true/false)
     * @param bool $checkingcookie The authentication request has come from a set cookie.
     * @return mixed Returns 'false' if the authentication fails otherwise will return the user ID.
     */
    static function Authenticate($username, $password, $rememberMe = false, $isCookie = false, $sessionSecurity = false)
    {
        global $zdbh;
        $sqlString = "SELECT * FROM
                      x_accounts WHERE
                      ac_user_vc = :username AND
                      ac_pass_vc = :password AND
                      ac_enabled_in = 1 AND
                      ac_deleted_ts IS NULL";

        $bindArray = array(':username' => $username,
            ':password' => $password
        );

        $zdbh->bindQuery($sqlString, $bindArray);
        $row = $zdbh->returnRow();

        if ($row) {
            //Disabled till zpanel 10.0.3
            //runtime_sessionsecurity::sessionRegen();

            ctrl_auth::SetUserSession($row['ac_id_pk'], $sessionSecurity);
            $log_logon = $zdbh->prepare("UPDATE x_accounts SET ac_lastlogon_ts = :t WHERE ac_id_pk = :id");
            $log_logon->execute(array(':t' => time(), ':id' => $row['ac_id_pk']));
            if ($rememberMe) {
                ctrl_loginguard::Remember($row['ac_id_pk']);
            }

            runtime_hook::Execute('OnGoodUserLogin');
            return $row['ac_id_pk'];
        } else {
            runtime_hook::Execute('OnBadUserLogin');
            return false;
        }
    }

    /**
     * Destroys a session and ends a user's Sentora session.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return bool
     */
    static function KillSession()
    {
        runtime_hook::Execute('OnUserLogout');
        // Nothing from the old session (shadowing, pending login steps, tokens) carries
        // into the next login on this browser, and the old session id stops working
        $_SESSION = array();
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        unset($_COOKIE['zUserSaltCookie']);
        return true;
    }

    /**
     * The secret that must be on a logout link, so another site can not log a user
     * out just by loading ./?logout. It lasts for the session (unlike the form token,
     * which changes after each form), so a logout link in an older tab still works.
     * @return string
     */
    static function LogoutToken()
    {
        if (empty($_SESSION['zplogout']) || !is_string($_SESSION['zplogout'])) {
            $_SESSION['zplogout'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['zplogout'];
    }

    /**
     * The panel's logout link.
     * @return string
     */
    static function LogoutUrl()
    {
        return './?logout=' . self::LogoutToken();
    }

    /**
     * Is this the session's logout secret?
     * @param mixed $token
     * @return bool
     */
    static function ValidLogoutToken($token)
    {
        return is_string($token) && $token !== '' && !empty($_SESSION['zplogout'])
            && is_string($_SESSION['zplogout']) && hash_equals($_SESSION['zplogout'], $token);
    }

    /**
     * Deletes the authentication 'rememberme' cookies.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return bool
     */
    static function KillCookies()
    {
        ctrl_loginguard::Forget();
        setcookie("zUser", '', time() - 3600, "/");
        setcookie("zPass", '', time() - 3600, "/");
        unset($_COOKIE['zUser']);
        unset($_COOKIE['zPass']);
        unset($_COOKIE['zSec']);
        return true;
    }

    /**
     * Returns the UID (User ID) of the current logged in user.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global obj $controller The Sentora controller object.
     * @return int The current user's session ID.
     */
    static function CurrentUserID()
    {
        global $controller;
        return $controller->GetControllerRequest('USER', 'zpuid');
    }

}
