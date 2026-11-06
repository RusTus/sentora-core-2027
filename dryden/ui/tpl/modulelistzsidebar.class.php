<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Generic template place holder class.
 * @package zpanelx
 * @subpackage dryden -> ui -> tpl
 * @version 1.1.0
 * @author Jason Davis (jason.davis.fl@gmail.com)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
/**
 * Template tag <# ui_tpl_modulelistzsidebar #>: the side bar module menu.
 */
class ui_tpl_modulelistzsidebar {

    /**
     * Builds the side bar: one heading and list of module links per module category.
     *
     * @return string The <li> items of the side bar.
     */
    public static function Template() {
        $line = '';
        $modcats = ui_moduleloader::GetModuleCats();

        foreach ($modcats as $modcat) {
            $mods = ui_moduleloader::GetModuleList($modcat['mc_id_pk'], 'modadmin');

            if ($mods) {
                $line .= '<li>';
                $line .= '<div class="heading">' .$modcat['mc_name_vc']. ' <span class="open">+</span></div>';
                $line .= '<ul>';
                
                foreach ($mods as $mod) {
                    $class_name = str_replace(array(' ', '_'), '-', strtolower($mod['mo_folder_vc']));
                    $line .= '<li>';
                    
                    $themeIcon = ui_template::MenuIcon($mod['mo_folder_vc']);
                    if ($themeIcon) {
                        $line .= '<a href="?module=' . $mod['mo_folder_vc']. '">' . $themeIcon . ' ';
                    } elseif ($mod['mo_installed_ts'] != 0) {
                        $line .= '<a href="?module=' . $mod['mo_folder_vc']. '"><i class="icon-' . $class_name . ' greyscale transparent"><img src="/modules/' . $mod['mo_folder_vc'] . '/assets/icon.png" height="16px" width="16px"></i> ';
                    } else {
                        $line .= '<a href="?module=' . $mod['mo_folder_vc']. '"><i class="icon-'.$class_name.'"></i> ';
                    }
                    $line .= '<: '.$mod['mo_name_vc'].' :>';
                    $line .= '</a>';
                    $line .= '</li>';
                }
                $line .= '</ul></li>';
            }
        }

        return $line;
    }

}

?>
