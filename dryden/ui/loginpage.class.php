<?php

/**
 * The frame of every page shown before login: the login form (the theme's login.ztml), the two-factor
 * code and the two-factor setup (inc/init.inc.php). One look for all of them, the same as the panel's
 * modules: the theme's login.css, the administrator's Light, Dark or Auto, the logo, a card.
 * With a brand (ctrl_branding: the reseller's at its own panel address, else the administrator's):
 * its logo and name, its main colour, its welcome line, its Light/Dark/Auto and its support contact,
 * with a small "Powered by Sentora" line.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-08
 * Updated by RusTus, 2026-11-09
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
        $brand = self::Brand();
        if ($brand && $brand['variant'] !== '') {
            $variant = $brand['variant'];
        }
        if (!in_array($variant, array('light', 'dark', 'auto'), true)) {
            $variant = 'light';
        }
        return $look = array('theme' => $theme, 'variant' => $variant);
    }

    /**
     * The brand of this page (ctrl_branding::ForRequest()), or null.
     *
     * @return array|null
     */
    static function Brand()
    {
        static $done = false, $brand = null;
        if (!$done) {
            $done = true;
            try {
                $brand = ctrl_branding::ForRequest();
            } catch (Throwable $e) {
                $brand = null;
            }
        }
        return $brand;
    }

    /**
     * The brand's main colour as page styles (only a checked #rrggbb ever goes in).
     *
     * @param array $b The brand.
     * @return string A <style> element, or ''.
     */
    static function BrandStyle(array $b)
    {
        if (!preg_match('/^#[0-9a-f]{6}$/', $b['colour'])) {
            return '';
        }
        $c = $b['colour'];
        $dark = ctrl_branding::Darker($c);
        $ink = ctrl_branding::InkOn($c);
        // (as specific as the theme's own html.ml-* rules, and after them, so these win in every variant)
        $css = 'html.ml-light,html.ml-dark,html.ml-auto{--ml-blue:' . $c . ';--ml-blue-dark:' . $dark . ';--ml-btn-ink:' . $ink . '}';
        // links take the colour only on light pages, and only when it reads on white
        if ($ink === '#ffffff') {
            $links = '--ml-link:' . $c . ';--ml-link-hover:' . $dark;
            $css .= 'html.ml-light{' . $links . '}@media (prefers-color-scheme: light){html.ml-auto{' . $links . '}}';
        }
        return '<style>' . $css . '</style>';
    }

    /**
     * Start the page: the head, and the card with the logo (the brand's when there is one).
     *
     * @param string $title The page title (plain text).
     * @param bool $wide A wider card (the two-factor setup, with its QR code).
     * @return void
     */
    static function Open($title, $wide = false)
    {
        $look = self::Look();
        $brand = self::Brand();
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $t = '/etc/styles/' . rawurlencode($look['theme']);
        $logoDark = $t . '/img/logos/sentora_logo_light.png';
        $logo = $look['variant'] === 'dark' ? $logoDark : $t . '/img/logos/sentora_logo.png';
        $head = '<picture>' . ($look['variant'] === 'auto' ? '<source srcset="' . $h($logoDark) . '" media="(prefers-color-scheme: dark)">' : '')
            . '<img src="' . $h($logo) . '" alt="Sentora" width="226" height="63"></picture>';
        $style = '';
        $welcome = '';
        if ($brand) {
            $name = $brand['name'];
            if ($name !== '') {
                $title .= ' - ' . $name;
            }
            if ($brand['logo']) {
                $main = ctrl_branding::LogoUrl($brand);
                $dk = ctrl_branding::LogoUrl($brand, true);
                // without a logo for dark pages, the logo sits on a light tile there
                $chip = $brand['logodark'] ? '' : ' ml-chip';
                if ($look['variant'] === 'dark') {
                    $head = '<img class="ml-brand' . $chip . '" src="' . $h($dk) . '" alt="' . $h($name !== '' ? $name : 'Logo') . '">';
                } else {
                    $head = '<picture>' . ($look['variant'] === 'auto' && $brand['logodark'] ? '<source srcset="' . $h($dk) . '" media="(prefers-color-scheme: dark)">' : '')
                        . '<img class="ml-brand' . ($look['variant'] === 'auto' ? $chip : '') . '" src="' . $h($main) . '" alt="' . $h($name !== '' ? $name : 'Logo') . '"></picture>';
                }
                if ($name !== '') {
                    $head .= '<span class="ml-brand-name ml-sr">' . $h($name) . '</span>';
                }
            } elseif ($name !== '') {
                $head = '<span class="ml-brand-name">' . $h($name) . '</span>';
            }
            $style = self::BrandStyle($brand);
            if ($brand['welcome'] !== '') {
                $welcome = '<p class="ml-welcome">' . $h($brand['welcome']) . '</p>';
            }
        }
        echo '<!DOCTYPE html>' . "\n" . '<html lang="en" class="ml-' . $h($look['variant']) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
            . '<title>' . $h($title) . '</title>'
            . '<link href="/etc/styles/_shared/global-css/bootstrap.min.css" rel="stylesheet">'
            . '<link href="' . $h($t) . '/global-css/login.css" rel="stylesheet">' . $style
            . '<link rel="icon" href="/etc/styles/_shared/img/ico/fav32.png" sizes="32x32" type="image/png">'
            . '<link rel="apple-touch-icon-precomposed" href="/etc/styles/_shared/img/ico/apple-touch-icon-144-precomposed.png">'
            // not shown inside another site's frame (the X-Frame-Options header does the same)
            . '<style id="antiClickjack">body{display:none !important}</style>'
            . '<script>if (self === top) { var a = document.getElementById("antiClickjack"); a.parentNode.removeChild(a); } else { top.location = self.location; }</script>'
            . '</head><body><main class="ml-wrap' . ($wide ? ' ml-wide' : '') . '"><div class="ml-card">'
            . '<div class="ml-head">' . $head . '</div>' . $welcome . "\n";
    }

    /**
     * End the page: close the card; the brand's support contact; the footer line.
     *
     * @return void
     */
    static function Close()
    {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $out = "\n</div>";
        $brand = self::Brand();
        if ($brand) {
            $bits = array();
            try {
                $sc = ctrl_support::Get($brand['uid']);
            } catch (Throwable $e) {
                $sc = array('email' => '', 'phone' => '', 'website' => '');
            }
            if ($sc['email'] !== '') {
                $bits[] = '<a href="mailto:' . $h($sc['email']) . '">' . $h($sc['email']) . '</a>';
            }
            if ($sc['phone'] !== '') {
                $bits[] = '<a href="tel:' . $h(preg_replace('/[^0-9+]/', '', $sc['phone'])) . '">' . $h($sc['phone']) . '</a>';
            }
            if (($sc['website'] ?? '') !== '') {
                $bits[] = '<a href="' . $h($sc['website']) . '" target="_blank" rel="noopener noreferrer">' . $h(preg_replace('~^https?://~i', '', rtrim($sc['website'], '/'))) . '</a>';
            }
            if ($bits) {
                $out .= '<p class="ml-contact">' . ($brand['name'] !== '' ? '<b>' . $h($brand['name']) . '</b> ' : '') . implode('<span aria-hidden="true"> · </span>', $bits) . '</p>';
            }
            $out .= '<p class="ml-powered ml-small">Powered by <a href="https://github.com/RusTus/sentora-core-2027" target="_blank" rel="noopener noreferrer">Sentora</a></p>';
        } else {
            $out .= '<p class="ml-powered">Powered by <a href="https://github.com/RusTus/sentora-core-2027" target="_blank" rel="noopener noreferrer">Sentora</a> Hosting Control Panel</p>';
        }
        echo $out . "</main></body></html>\n";
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
