<?php

/**
 * Dashboard: every group (administrators, resellers, users) may open it. A server
 * updated from an earlier version gets the module registered for administrators only
 * (sentora-update), so the other groups are added here, once.
 */
global $zdbh; // hooks are included inside a function
if (ctrl_options::GetSystemOption('dashboard_groups_done') === false) {
    $dwId = (int) $zdbh->query("SELECT mo_id_pk FROM x_modules WHERE mo_folder_vc = 'dashboard'")->fetchColumn();
    if ($dwId > 0) {
        foreach ($zdbh->query("SELECT ug_id_pk FROM x_groups")->fetchAll(PDO::FETCH_COLUMN) as $dwGroup) {
            if (!ctrl_groups::CheckGroupModulePermissions((int) $dwGroup, $dwId)) {
                $zdbh->prepare("INSERT INTO x_permissions (pe_group_fk, pe_module_fk) VALUES (:g, :m)")->execute(array(':g' => (int) $dwGroup, ':m' => $dwId));
            }
        }
        $zdbh->exec("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
            VALUES ('dashboard_groups_done', 'dashboard_groups_done', '1', NULL, 'Dashboard given to every group', 'Sentora Config', 'false')");
        echo "Dashboard: available to every group" . fs_filehandler::NewLine();
    }
}
