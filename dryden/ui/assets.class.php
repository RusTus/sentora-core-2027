<?php

/**
 * Core class: the addresses of the panel's own styles and scripts. Each one the page names
 * (etc/styles/... or modules/<name>/assets/..., a .css or .js file) gets the time the file was last
 * changed (?v=...), so the browser may keep it for a year (Apache Admin's SentoraPanelAssets) and
 * still gets a new copy as soon as the file changes: an update, an add-on's new version.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-13
 */
class ui_assets
{
    /**
     * The page with its style and script addresses dated (only files that exist under the panel's
     * folder; addresses that already have a ?query or point elsewhere are left as they are).
     *
     * @param string $html
     * @return string
     */
    static function Versioned($html)
    {
        if (strpos((string) $html, '/assets/') === false && strpos((string) $html, 'etc/styles/') === false) {
            return $html;
        }
        $root = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/') . '/';
        return preg_replace_callback(
            '#\b(href|src)=(["\'])(/?)((?:etc/styles|modules/[A-Za-z0-9_-]+/assets)/[A-Za-z0-9_./-]+\.(?:css|js))\2#',
            function ($m) use ($root) {
                if (strpos($m[4], '..') !== false) {
                    return $m[0];
                }
                $t = @filemtime($root . $m[4]);
                return $t ? $m[1] . '=' . $m[2] . $m[3] . $m[4] . '?v=' . $t . $m[2] : $m[0];
            },
            (string) $html
        );
    }
}
