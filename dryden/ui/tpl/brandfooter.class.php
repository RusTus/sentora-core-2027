<?php

/**
 * Template tag <# ui_tpl_brandfooter #>: the line at the foot of every panel page. With a brand
 * (ctrl_branding::ForUser()): its name, linked to its support website when it has one, and a small
 * "Powered by Sentora"; without one, Sentora's copyright line.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class ui_tpl_brandfooter
{
    /**
     * The footer's contents.
     *
     * @return string HTML.
     */
    public static function Template()
    {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $b = ctrl_branding::Current();
        if ($b && $b['name'] !== '') {
            $site = '';
            try {
                $site = (string) (ctrl_support::Get($b['uid'])['website'] ?? '');
            } catch (Throwable $e) {
            }
            $name = $site !== '' ? '<a href="' . $h($site) . '" target="_blank" rel="noopener noreferrer">' . $h($b['name']) . '</a>' : $h($b['name']);
            return $name . ' <span class="brand-powered">&middot; Powered by <a href="https://github.com/RusTus/sentora-core-2027" target="_blank" rel="noopener noreferrer">Sentora</a></span>';
        }
        return 'Copyright &copy; 2004-' . date('Y') . ' <a href="http://www.sentora.org/" target="_blank">Sentora Project</a>.';
    }
}
