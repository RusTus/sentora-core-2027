<?php

/**
 * Template tag <# ui_tpl_brandname #>: the name at the start of the browser tab's title, the
 * account's brand's company name (ctrl_branding::ForUser()), else "Sentora".
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class ui_tpl_brandname
{
    /**
     * The name, escaped.
     *
     * @return string
     */
    public static function Template()
    {
        $b = ctrl_branding::Current();
        return htmlspecialchars(($b && $b['name'] !== '') ? $b['name'] : 'Sentora', ENT_QUOTES, 'UTF-8');
    }
}
