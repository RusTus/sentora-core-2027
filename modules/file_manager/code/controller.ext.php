<?php

/**
 * File Manager: the page itself is app/index.php (Tiny File Manager behind
 * Sentora's login, confined to the user's hosting folder).
 */
class module_controller extends ctrl_module
{

    static function getHostingFolder()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return htmlspecialchars(ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . '/', ENT_QUOTES, 'UTF-8');
    }

}

?>
