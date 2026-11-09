<?php

/**
 * Template tag <# ui_tpl_brandlogo #>: the logo in the panel's top bar. The account's brand's logo
 * (ctrl_branding::ForUser(): its own, its reseller's or zadmin's), else Sentora's two logos (one for
 * light bars, one for dark bars). Without a logo for dark backgrounds, the brand's logo sits on a
 * light tile when the bar is dark.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class ui_tpl_brandlogo
{
    /**
     * The logo's <img> elements, inside the bar's home link.
     *
     * @return string HTML.
     */
    public static function Template()
    {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $b = ctrl_branding::Current();
        if ($b && $b['logo']) {
            $alt = $b['name'] !== '' ? $b['name'] : 'Home';
            return '<img class="logo-ink brand-logo" src="' . $h(ctrl_branding::LogoUrl($b)) . '" height="36" alt="' . $h($alt) . '">'
                . '<img class="logo-white brand-logo' . ($b['logodark'] ? '' : ' brand-chip') . '" src="' . $h(ctrl_branding::LogoUrl($b, true)) . '" height="36" alt="' . $h($alt) . '">';
        }
        if ($b && $b['name'] !== '') {
            return '<span class="brand-name">' . $h($b['name']) . '</span>';
        }
        $dir = 'etc/styles/' . ui_template::GetUserTemplate() . '/img/logos/';
        return '<img class="logo-ink" src="' . $h($dir) . 'sentora_logo_header_ink.png" width="96" height="36" alt="Home">'
            . '<img class="logo-white" src="' . $h($dir) . 'sentora_logo_header.png" width="96" height="36" alt="Home">';
    }
}
