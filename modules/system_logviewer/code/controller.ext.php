<?php
/**
 * System Log Viewer module: controller.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-06
 */

require_once dirname(__FILE__) . '/logs.php';

/**
 * System Log Viewer (administrators only).
 */
class module_controller extends ctrl_module
{

    /**
     * Escape text for HTML.
     *
     * @param mixed $s The text.
     * @return string
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translated and HTML-escaped text.
     *
     * @param string $s The English text.
     * @return string
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * Whether any log was found (template: <% if LogList %>).
     *
     * @return bool
     */
    static function getLogList()
    {
        return count(system_logviewer_logs::All()) > 0;
    }

    /**
     * Template HTML: the logs, grouped by service, as a list to pick from.
     *
     * Each entry carries its id, path, group and whether the panel can read it; dates
     * are shown as "changed ... ago" by the page script from data-mtime.
     *
     * @return string HTML.
     */
    static function getLogMenu()
    {
        $groups = array();
        foreach (system_logviewer_logs::All() as $log) {
            $groups[$log['group']][] = $log;
        }
        $out = '';
        foreach ($groups as $group => $logs) {
            usort($logs, function ($a, $b) { return strcmp($a['path'], $b['path']); });
            $out .= '<div class="lv-group"><div class="lv-group-name">' . self::h(ui_language::translate($group)) . ' <span>' . count($logs) . '</span></div>';
            foreach ($logs as $log) {
                $dir = dirname($log['path']);
                $out .= '<button type="button" class="lv-item' . ($log['readable'] ? '' : ' locked') . '" data-id="' . self::h($log['id']) . '" data-path="' . self::h($log['path']) . '"'
                    . ' data-group="' . self::h(ui_language::translate($group)) . '" data-readable="' . ($log['readable'] ? '1' : '0') . '" data-s="' . self::h(strtolower($log['path'] . ' ' . $group)) . '">'
                    . '<span class="lv-item-name">' . self::h(basename($log['path'])) . '</span>'
                    . '<span class="lv-item-meta">' . self::h($dir) . '</span>'
                    . '<span class="lv-item-meta">' . ($log['readable'] ? self::h(system_logviewer_logs::HumanSize($log['size'])) . ' · <span class="lv-ago" data-mtime="' . (int) $log['mtime'] . '"></span>'
                        : self::t('root or adm only')) . '</span></button>';
            }
            $out .= '</div>';
        }
        return $out;
    }

}

?>
