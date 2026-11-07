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
     *
     * When there is no session it first tries the "remember me" cookie; failing that it
     * runs the OnRequireUserLogin hook, includes the login page of the administrator's
     * theme and ends the request with exit.
     * @global db_driver $zdbh The ZPX database handle.
     * @return bool True when a user is logged in (otherwise the request exits).
     */
    static function RequireUser()
    {
        global $zdbh;
        if (!isset($_SESSION['zpuid'])) {
            if (ctrl_loginguard::LoginFromCookie()) {
                return true;
            }
            runtime_hook::Execute('OnRequireUserLogin');
            $sqlQuery = "SELECT ac_id_pk, ac_usertheme_vc, ac_usercss_vc FROM
                         x_accounts WHERE
                         ac_user_vc = :zadmin";
            $bindArray = array(':zadmin' => 'zadmin');
            $zdbh->bindQuery($sqlQuery, $bindArray);
            $themeRow = $zdbh->returnRow();
            $loginTheme = ctrl_users::SafeTheme($themeRow['ac_usertheme_vc']);
            // The administrator's variation of that theme (for example light or dark), for
            // login pages that offer more than one; only a plain name ever reaches the page
            $loginCss = preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $themeRow['ac_usercss_vc']) ? (string) $themeRow['ac_usercss_vc'] : '';
            // or the administrator's own choice in My Account (Light, Dark, Auto)
            $adminPref = ui_template::GetStylePref((int) $themeRow['ac_id_pk']);
            if ($adminPref !== '') {
                $loginCss = $adminPref;
            }
            include 'etc/styles/' . ($loginTheme !== '' ? $loginTheme : 'Modern') . '/login.ztml';
            exit;
        }
        return true;
    }

    /**
     * Sets a user session ID.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param int|string $zpuid The Sentora user account ID to set the session as.
     * @param bool $sessionSecuirty Optional. Turn on the IP and browser check for the session. Default true.
     * @return bool Always true ($zpuid is never null here).
     */
    static function SetUserSession($zpuid = 0, $sessionSecuirty = true)
    {
        // The caller decides: the login form's "Enable Session Security" box, or the
        // current session's setting when switching account (shadowing). This used to be
        // overwritten with the session's value, which is "off" at every new login, so
        // the IP and browser check was never turned on.
        $sessionSecuirty = (bool) $sessionSecuirty;
        if (isset($zpuid)) {
            // New session id at login, so an id planted before login is worthless
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                session_regenerate_id(true);
            }
            $_SESSION['zpuid'] = $zpuid;
            // a new login: notices closed "until the next login" show again (ui_tpl_notice::LoginKey())
            $_SESSION['sn_login'] = bin2hex(random_bytes(6));
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
     * @param mixed $value The value of the session variable to set.
     * @return bool False only when $name is null.
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
     * @param bool $isCookie The authentication request has come from a set cookie (not used).
     * @param bool $sessionSecurity Optional. Turn on the IP and browser check for the session. Default false.
     * @return int|string|false Returns false if the authentication fails, otherwise the user ID (ac_id_pk).
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
     * @return bool Always true.
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
     * Return the secret that must be on a logout link.
     *
     * The secret means another site can not log a user out just by loading ./?logout.
     * It lasts for the session (unlike the form token, which changes after each form),
     * so a logout link in an older tab still works.
     * @return string 32 hex characters, created on first use.
     */
    static function LogoutToken()
    {
        if (empty($_SESSION['zplogout']) || !is_string($_SESSION['zplogout'])) {
            $_SESSION['zplogout'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['zplogout'];
    }

    /**
     * Return the panel's logout link.
     * @return string Relative URL including the logout token.
     */
    static function LogoutUrl()
    {
        return './?logout=' . self::LogoutToken();
    }

    /**
     * Is this the session's logout secret?
     * @param mixed $token The token from the logout link (normally a string).
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
     * @return bool Always true.
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
     * @global runtime_controller $controller The Sentora controller object.
     * @return int|string|false The current user's account ID, or false when nobody is logged in.
     */
    static function CurrentUserID()
    {
        global $controller;
        return $controller->GetControllerRequest('USER', 'zpuid');
    }

}
