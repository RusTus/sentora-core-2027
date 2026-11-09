<?php
/**
 * API Access, every daemon run: once, when the module is new on this server, give it to the
 * Administrators and Resellers groups (Module Admin decides after that).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
global $zdbh;
if (ctrl_options::GetSystemOption('api_access_setup') === false) {
    $q = $zdbh->prepare("SELECT mo_id_pk FROM x_modules WHERE mo_folder_vc = 'api_access'");
    $q->execute();
    $moduleId = (int) $q->fetchColumn();
    if ($moduleId > 0) {
        $has = $zdbh->prepare("SELECT COUNT(*) FROM x_permissions WHERE pe_group_fk = :g AND pe_module_fk = :m");
        $add = $zdbh->prepare("INSERT INTO x_permissions (pe_group_fk, pe_module_fk) VALUES (:g, :m)");
        foreach (array(1, 2) as $g) {
            $has->execute(array(':g' => $g, ':m' => $moduleId));
            if ((int) $has->fetchColumn() === 0) {
                $add->execute(array(':g' => $g, ':m' => $moduleId));
            }
        }
        $zdbh->exec("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
            VALUES ('api_access_setup', 'api_access_setup', '1', NULL, 'API Access given to the Administrators and Resellers groups', 'Sentora Config', 'false')");
        echo "API Access: available to the Administrators and Resellers groups." . fs_filehandler::NewLine();
    }
}
