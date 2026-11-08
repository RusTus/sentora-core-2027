<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Generic template place holder class.
 * @package zpanelx
 * @subpackage dryden -> ui -> tpl
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-07
 */
/**
 * Template tag <# ui_tpl_modulelist #>: the dashboard module icons as tables (old layout).
 */
class ui_tpl_modulelist {

    /**
     * Builds one table of module icons per module category for the dashboard.
     *
     * @global runtime_controller $controller The request controller (reads the module URL parameter).
     * @return string|null The HTML, or null when a module page (not the dashboard) is shown.
     */
    public static function Template() {
        global $controller;
        if (!$controller->GetControllerRequest('URL', 'module')) {
            $line = "";
            $modcats = ui_moduleloader::GetModuleCats();
            foreach ($modcats as $modcat) {
                $mods = ui_moduleloader::GetModuleList($modcat['mc_id_pk'], "modadmin");
                if ($mods) {
                    $line .= "<table class=\"zcat\">";
                    $line .= "<tr>";
                    $line .= "<th align=\"left\">";
                    $line .= "<a name=\"" . str_replace(" ", "_", strtolower($modcat['mc_name_vc'])) . "\"></a>";
                    $line .= "" . ui_language::translate($modcat['mc_name_vc']) . "";
                    $line .= "<a href=\"#\" class=\"zcat\" id=\"zcat_" . str_replace(" ", "_", strtolower($modcat['mc_name_vc'])) . "_a\"></a>";
                    $line .= "</th>";
                    $line .= "</tr>";
                    $line .= "<tr>";
                    $line .= "<td align=\"left\">";
                    $line .= "<div class=\"zcat_" . str_replace(" ", "_", strtolower($modcat['mc_name_vc'])) . "\" id=\"zcat_" . str_replace(" ", "_", strtolower($modcat['mc_name_vc'])) . "\">";
                    $line .= "<table class=\"zcatcontent\" align=\"left\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\">";
                    $line .= "<tr>";
                    $line .= "<td>";
                    $line .= "<table align=\"left\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\">";
                    $line .= "<tr>";
                    $icons_per_row = ctrl_options::GetSystemOption('module_icons_pr');
                    $num_icons = 0;
                    foreach ($mods as $mod) {
                        // translated and escaped, then each space a line break
                        $cleanname = str_replace(' ', '<br/>', htmlspecialchars(ui_language::translate($mod['mo_name_vc']), ENT_QUOTES, 'UTF-8'));
                        if ($num_icons == $icons_per_row) {
                            $line .= "</tr><tr>";
                            $num_icons = 0;
                        }
                        $line .= "<td style=\"text-align:center;\" align=\"left\">";
                        $line .= "<a href=\"?module=" . $mod['mo_folder_vc'] . "\" title=\"<: " . $mod['mo_desc_tx'] . " :>\">";
                        $line .= "<img src=\"modules/" . $mod['mo_folder_vc'] . "/assets/icon.png\" border=\"0\" />";
                        $line .= "</a>";
                        $line .= "<br />";
                        $line .= "<a href=\"?module=" . $mod['mo_folder_vc'] . "\">" . $cleanname . "</a>";
                        $line .= "</td>";
                        $num_icons++;
                    }
                    $line .= "</tr></table></td></tr></table></div></td></tr></table><br>";
                }
            }
            return $line;
        }
    }

}

?>
