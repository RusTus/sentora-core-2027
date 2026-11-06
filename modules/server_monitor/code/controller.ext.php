<?php

/**
 * Server Monitor (Server Admin): a live dashboard of the server itself. The page
 * (assets/monitor.js) polls code/stats.php, which reads the kernel's figures.
 */
class module_controller extends ctrl_module
{

    static function getAssets()
    {
        return 'modules/server_monitor/assets/';
    }

}

?>
