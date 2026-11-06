<?php
/**
 * System Log Viewer module: controller.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

require_once dirname(__FILE__) . '/logs.php';

/**
 * System Log Viewer (administrators only).
 */
class module_controller extends ctrl_module
{

    /**
     * List the log files for the page (template: <% loop LogList %>).
     *
     * @return array<int, array<string, string>>|false One row per log: 'id', 'group', 'path' (HTML-escaped), 'size' (human readable), 'changed' (date or ''), 'readable' (translated yes/no); false when no log was found.
     */
    static function getLogList()
    {
        $rows = array();
        foreach (system_logviewer_logs::All() as $log) {
            $rows[] = array(
                'id' => $log['id'],
                'group' => htmlspecialchars($log['group'], ENT_QUOTES, 'UTF-8'),
                'path' => htmlspecialchars($log['path'], ENT_QUOTES, 'UTF-8'),
                'size' => system_logviewer_logs::HumanSize($log['size']),
                'changed' => $log['mtime'] ? date('Y-m-d H:i', $log['mtime']) : '',
                'readable' => $log['readable'] ? ui_language::translate('yes') : ui_language::translate('no (root or adm only)'),
            );
        }
        return count($rows) ? $rows : false;
    }

}

?>
