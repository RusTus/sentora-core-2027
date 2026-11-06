<?php

/**
 * File Manager module: controller.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * File Manager: the page itself is app/index.php (Tiny File Manager behind
 * Sentora's login, confined to the user's hosting folder).
 */
class module_controller extends ctrl_module
{

    /**
     * Template value <@ HostingFolder @>: the current user's hosting folder path.
     *
     * @return string The path (hosted_dir + username + '/'), HTML-escaped.
     */
    static function getHostingFolder()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return htmlspecialchars(ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . '/', ENT_QUOTES, 'UTF-8');
    }

}

?>
