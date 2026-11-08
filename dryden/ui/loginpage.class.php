<?php

/**
 * The frame of every page shown before login: the login form (the theme's login.ztml), the two-factor
 * code and the two-factor setup (inc/init.inc.php). One look for all of them, the same as the panel's
 * modules: the theme's login.css, the administrator's Light, Dark or Auto, the logo, a card.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
class ui_loginpage
{
    /**
     * The look before login: the administrator's (zadmin's) theme and its variant, or zadmin's own
     * choice in My Account > Appearance.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array{theme:string, variant:string} variant: 'light', 'dark' or 'auto'.
     */
    static function Look()
    {
        global $zdbh;
        static $look = null;
        if ($look !== null) {
            return $look;
        }
        $theme = 'Modern';
        $variant = 'light';
        try {
            $row = $zdbh->query("SELECT ac_id_pk, ac_usertheme_vc, ac_usercss_vc FROM x_accounts WHERE ac_user_vc = 'zadmin'")->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $theme = ctrl_users::SafeTheme($row['ac_usertheme_vc']) ?: 'Modern';
                $variant = (string) $row['ac_usercss_vc'];
                $pref = ui_template::GetStylePref((int) $row['ac_id_pk']);
                if ($pref !== '') {
                    $variant = $pref;
                }
            }
        } catch (Exception $e) {
        }
        if (!in_array($variant, array('light', 'dark', 'auto'), true)) {
            $variant = 'light';
        }
        return $look = array('theme' => $theme, 'variant' => $variant);
    }

    /**
     * Start the page: the head, and the card with the logo.
     *
     * @param string $title The page title (plain text).
     * @param bool $wide A wider card (the two-factor setup, with its QR code).
     * @return void
     */
    static function Open($title, $wide = false)
    {
        $look = self::Look();
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $t = '/etc/styles/' . rawurlencode($look['theme']);
        $logoDark = $t . '/img/logos/sentora_logo_light.png';
        $logo = $look['variant'] === 'dark' ? $logoDark : $t . '/img/logos/sentora_logo.png';
        echo '<!DOCTYPE html>' . "\n" . '<html lang="en" class="ml-' . $h($look['variant']) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
            . '<title>' . $h($title) . '</title>'
            . '<link href="/etc/styles/_shared/global-css/bootstrap.min.css" rel="stylesheet">'
            . '<link href="' . $h($t) . '/global-css/login.css" rel="stylesheet">'
            . '<link rel="icon" href="/etc/styles/_shared/img/ico/fav32.png" sizes="32x32" type="image/png">'
            . '<link rel="apple-touch-icon-precomposed" href="/etc/styles/_shared/img/ico/apple-touch-icon-144-precomposed.png">'
            // not shown inside another site's frame (the X-Frame-Options header does the same)
            . '<style id="antiClickjack">body{display:none !important}</style>'
            . '<script>if (self === top) { var a = document.getElementById("antiClickjack"); a.parentNode.removeChild(a); } else { top.location = self.location; }</script>'
            . '</head><body><main class="ml-wrap' . ($wide ? ' ml-wide' : '') . '"><div class="ml-card">'
            . '<div class="ml-head"><picture>' . ($look['variant'] === 'auto' ? '<source srcset="' . $h($logoDark) . '" media="(prefers-color-scheme: dark)">' : '')
            . '<img src="' . $h($logo) . '" alt="Sentora" width="226" height="63"></picture></div>' . "\n";
    }

    /**
     * End the page: close the card, the footer line.
     *
     * @return void
     */
    static function Close()
    {
        echo "\n</div><p class=\"ml-powered\">Powered by <a href=\"https://github.com/RusTus/sentora-core-2027\" target=\"_blank\" rel=\"noopener noreferrer\">Sentora</a> Hosting Control Panel</p></main></body></html>\n";
    }

    /**
     * A message at the top of the card.
     *
     * @param string $type 'error', 'ok' or 'info'.
     * @param string $text The message (plain text).
     * @return string HTML.
     */
    static function Message($type, $text)
    {
        $type = in_array($type, array('error', 'ok', 'info'), true) ? $type : 'info';
        return '<div class="ml-msg ml-msg-' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8') . '</div>';
    }
}
