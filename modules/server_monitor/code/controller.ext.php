<?php

/**
 * Server Monitor module: controller.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-06
 */

/**
 * Server Monitor (Server Admin): a live dashboard of the server itself. The page
 * (assets/monitor.js) polls code/stats.php, which reads the kernel's figures.
 */
class module_controller extends ctrl_module
{

    /**
     * Template value <@ Assets @>: the module's assets folder.
     *
     * @return string The relative path 'modules/server_monitor/assets/'.
     */
    static function getAssets()
    {
        return 'modules/server_monitor/assets/';
    }

}

?>
