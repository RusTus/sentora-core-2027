<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Cross Site Forgery Request protection class.
 * @package zpanelx
 * @subpackage dryden -> runtime
 * @version 1.0.2
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
/**
 * Cross-site request forgery (CSRF) token handling for panel forms and AJAX calls.
 *
 * The token lives in $_SESSION['zpcsfr'] and is posted back as 'csfr_token'.
 */
class runtime_csfr {

    /**
     * Builds a 'hidden' form type which is populated with the generated token.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return string The HTML form tag.
     */
    static function Token() {
        if (!isset($_SESSION['zpcsfr'])) {
            self::Tokeniser();
        }
        $token = $_SESSION['zpcsfr'];
        return "<input type=\"hidden\" name=\"csfr_token\" value=\"" . $token . "\">";
    }

    /**
     * Generates a new CSFR token.
     *
     * Stores a new random hash in $_SESSION['zpcsfr'].
     *
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return true Always true.
     */
    static function Tokeniser() {
        $_SESSION['zpcsfr'] = runtime_randomstring::randomHash();
        return true;
    }

    /**
     * Verfies that the submitted form has a valid CSFR token.
     *
     * Sentora: is the posted token right? (Does not replace the token, so a module's
     * own Protect() in the same request still works.)
     *
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return bool True when the posted 'csfr_token' matches the session token.
     */
    static function Valid() {
        return isset($_POST['csfr_token'], $_SESSION['zpcsfr']) && is_string($_POST['csfr_token'])
            && is_string($_SESSION['zpcsfr']) && hash_equals($_SESSION['zpcsfr'], $_POST['csfr_token']);
    }

    /**
     * Sentora: adds the token to every form that posts, in a whole page of panel
     * output (so no module's form can miss it).
     *
     * @param string $html The page HTML.
     * @return string The HTML with a hidden 'csfr_token' field after every POST <form> tag
     *                (unchanged when there is no form or no active session).
     */
    static function AddToForms($html) {
        if (stripos($html, '<form') === false || session_status() !== PHP_SESSION_ACTIVE) {
            return $html;
        }
        if (!isset($_SESSION['zpcsfr'])) {
            self::Tokeniser();
        }
        $field = '<input type="hidden" name="csfr_token" value="' . htmlspecialchars((string) $_SESSION['zpcsfr'], ENT_QUOTES) . '">';
        return preg_replace('/(<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>)/i', '$1' . $field, $html);
    }

    /**
     * Sentora: puts the token in a <meta name="csfr-token"> in the page head, so the
     * panel's own AJAX calls (which have no form) can send it.
     *
     * @param string $html The page HTML.
     * @return string The HTML with the meta tag after the <head> tag (unchanged when there
     *                is no head or no active session).
     */
    static function AddToHead($html) {
        if (stripos($html, '<head') === false || session_status() !== PHP_SESSION_ACTIVE) {
            return $html;
        }
        if (!isset($_SESSION['zpcsfr'])) {
            self::Tokeniser();
        }
        $meta = '<meta name="csfr-token" content="' . htmlspecialchars((string) $_SESSION['zpcsfr'], ENT_QUOTES) . '">';
        return preg_replace('/(<head\b[^>]*>)/i', '$1' . $meta, $html, 1);
    }

    /**
     * Check the posted CSRF token and stop the request when it is wrong.
     *
     * On success a new token is generated. On failure an error page is printed and
     * the script ends with die().
     *
     * @return true True when the token is valid (otherwise the script dies).
     */
    static function Protect() {
        if (isset($_POST['csfr_token'], $_SESSION['zpcsfr']) && is_string($_POST['csfr_token']) && hash_equals((string) $_SESSION['zpcsfr'], $_POST['csfr_token'])) {
            self::Tokeniser();
            return true;
        }
        $error_html = "<style type=\"text/css\"><!--
            .dbwarning {
                    font-family: Verdana, Geneva, sans-serif;
                    font-size: 14px;
                    color: #C00;
                    background-color: #FCC;
                    padding: 30px;
                    border: 1px solid #C00;
            }
            p {
                    font-size: 12px;
                    color: #666;
            }
            </style>
            <div class=\"dbwarning\"><strong>Application Error:</strong> [0204] - The form you attempted to submit had an invalid token!</p></div>";
        die($error_html);
    }

}

?>