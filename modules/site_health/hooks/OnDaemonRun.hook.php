<?php
/**
 * Site Health, every daemon run: once, when the module is new on this server, give it to every
 * user group (each account sees only its own websites; Module Admin decides after that), and make
 * the first check at once instead of at the next hourly run.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
global $zdbh;
if (ctrl_options::GetSystemOption('site_health_setup') === false) {
    $q = $zdbh->prepare("SELECT mo_id_pk FROM x_modules WHERE mo_folder_vc = 'site_health'");
    $q->execute();
    $moduleId = (int) $q->fetchColumn();
    if ($moduleId > 0) {
        $has = $zdbh->prepare("SELECT COUNT(*) FROM x_permissions WHERE pe_group_fk = :g AND pe_module_fk = :m");
        $add = $zdbh->prepare("INSERT INTO x_permissions (pe_group_fk, pe_module_fk) VALUES (:g, :m)");
        foreach ($zdbh->query("SELECT ug_id_pk FROM x_groups")->fetchAll(PDO::FETCH_COLUMN) as $g) {
            $has->execute(array(':g' => (int) $g, ':m' => $moduleId));
            if ((int) $has->fetchColumn() === 0) {
                $add->execute(array(':g' => (int) $g, ':m' => $moduleId));
            }
        }
        $zdbh->exec("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
            VALUES ('site_health_setup', 'site_health_setup', '1', NULL, 'Site Health given to every user group', 'Sentora Config', 'false')");
        echo "Site Health: available to every user group; making the first check now." . fs_filehandler::NewLine();
        foreach (sys_sitehealth::Collect() as $siteHealthLine) {
            echo "Site Health: " . $siteHealthLine . fs_filehandler::NewLine();
        }
    }
}
