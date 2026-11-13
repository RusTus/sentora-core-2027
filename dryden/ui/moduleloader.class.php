<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Main module loader class.
 * @package zpanelx
 * @subpackage dryden -> ui
 * @version 1.1.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-13
 */
/**
 * Lists the module categories and modules the current user may see (menus, dashboard).
 */
class ui_moduleloader {

    /**
     * Gets all categories, or if parameter given can select a single category.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param string $catname The name of the module category to get the list of modules from.
     *        Ignored when the user has a saved category order ('catorder').
     * @return array<int, array<string, mixed>>|false One row per category with 'mc_id_pk' and 'mc_name_vc', or false when there are no categories.
     */
    static function GetModuleCats($catname = "") {
        global $zdbh;
        $user = ctrl_users::GetUserDetail();

        // The saved order goes into the SQL, so only whole-number category IDs are used
        // (it is JSON the browser sent; anything else falls back to the default order)
        $order = '';
        if (isset($user['catorder']) && $user['catorder'] != '') {
            $ids = json_decode((string) $user['catorder'], true);
            if (is_array($ids)) {
                $ids = array_values(array_filter($ids, function ($v) { return is_scalar($v) && ctype_digit((string) $v); }));
                $order = implode(',', array_map('intval', $ids));
            }
        }
        if ($order !== '') {
            $sql = 'SELECT * FROM `x_modcats`ORDER BY FIELD(`mc_id_pk`, '.$order.')';
        } else if($catname != ''){
            $sql = 'SELECT * FROM x_modcats WHERE mc_name_vc = :catname';
        }else{
            $sql = 'SELECT * FROM x_modcats';
        }

        $numrows = $zdbh->prepare($sql);
        if (strpos($sql, ':catname') !== false) {
            $numrows->bindParam(':catname', $catname);
        }
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sqlString = $sql;
            $sql = $zdbh->prepare($sqlString);
            if (strpos($sqlString, ':catname') !== false) {
                $sql->bindParam(':catname', $catname);
            }
            $res = array();
            $sql->execute();
            $has_icons = false;
            while ($row = $sql->fetch()) {
                foreach (self::Modules($row['mc_id_pk']) as $rowcheck) {
                    if (ctrl_groups::CheckGroupModulePermissions($user['usergroupid'], $rowcheck['mo_id_pk']) && self::Shown($rowcheck['mo_folder_vc'], $user)) {
                        $has_icons = true;
                    }
                }
                if ($has_icons) {
                    array_push($res, array('mc_id_pk' => $row['mc_id_pk'],
                        'mc_name_vc' => $row['mc_name_vc']));
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * Whether a module's menu entry is shown to this user. A module may leave the menu of users it has
     * nothing for (Payment Plans: clients who are not billed through the panel) with a file
     * modules/<folder>/code/menu.php that returns false for them; it sees $user (ctrl_users::GetUserDetail).
     * Only the menu: the group's permission still decides whether the module may be opened. Asked once
     * per module and page.
     *
     * @param string $folder The module's folder (x_modules.mo_folder_vc).
     * @param array<string, mixed>|false $user The user whose menu it is.
     * @return bool
     */
    static function Shown($folder, $user) {
        static $seen = array();
        $folder = (string) $folder;
        if (!is_array($user) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $folder)) {
            return true;
        }
        $key = $folder . '|' . (int) ($user['userid'] ?? 0);
        if (!isset($seen[$key])) {
            $file = 'modules/' . $folder . '/code/menu.php';
            $seen[$key] = !is_file($file) || (function ($file, $user) {
                try {
                    return (include $file) !== false;
                } catch (Throwable $e) {
                    return true;   // (a module's mistake does not take its entry away)
                }
            })($file, $user);
        }
        return $seen[$key];
    }

    /**
     * The enabled user modules of one category, in name order, from the list read once per page
     * (the menu asks for every category, and again for each check).
     *
     * @param int|string $catid
     * @return array<int, array<int|string, mixed>> x_modules rows
     */
    static function Modules($catid) {
        global $zdbh;
        $read = function () use ($zdbh) {
            return $zdbh->query("SELECT * FROM x_modules WHERE mo_type_en = 'user' AND mo_enabled_en = 'true' ORDER BY mo_name_vc")->fetchAll();
        };
        $all = ($zdbh instanceof db_driver) ? $zdbh->Remember('x_modules:user', $read) : $read();
        $out = array();
        foreach ($all as $row) {
            if ((string) $row['mo_category_fk'] === (string) $catid) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Gets the module list as an array from a given category ID.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param int|string $catid The ID of the module category to get the list of modules from; '' (default) for all modules.
     * @return array<int, array<string, mixed>>|false Array containing the list of modules for the category ID supplied
     *         (x_modules columns mo_id_pk ... mo_updateurl_tx), or false when there are none.
     */
    static function GetModuleList($catid = "") {
        global $zdbh;
        $user = ctrl_users::GetUserDetail();
        if ($catid == "") {
            $sql = "SELECT * FROM x_modules";
        } else {
            $sql = "SELECT * FROM x_modules WHERE mo_category_fk = :catid AND mo_type_en = 'user' AND mo_enabled_en = 'true' ORDER BY mo_name_vc";
        }

        // (a category's list: from the modules read once per page, in the same order)
        $rows = ($catid == "") ? null : self::Modules($catid);
        if ($rows === null) {
            $numrows = $zdbh->prepare($sql);
            $numrows->bindParam(':catid', $catid);
            $numrows->execute();
            $rows = $numrows->fetchColumn() <> 0 ? $zdbh->query($sql)->fetchAll() : array();
        }
        if ($rows) {
            $res = array();
            foreach ($rows as $row) {
                if (ctrl_groups::CheckGroupModulePermissions($user['usergroupid'], $row['mo_id_pk']) && ($catid == "" || self::Shown($row['mo_folder_vc'], $user))) {
                    array_push($res, array('mo_id_pk' => $row['mo_id_pk'],
                        'mo_category_fk' => $row['mo_category_fk'],
                        'mo_name_vc' => $row['mo_name_vc'],
                        'mo_version_in' => $row['mo_version_in'],
                        'mo_folder_vc' => $row['mo_folder_vc'],
                        'mo_type_en' => $row['mo_type_en'],
                        'mo_desc_tx' => $row['mo_desc_tx'],
                        'mo_installed_ts' => $row['mo_installed_ts'],
                        'mo_enabled_en' => $row['mo_enabled_en'],
                        'mo_updatever_vc' => $row['mo_updatever_vc'],
                        'mo_updateurl_tx' => $row['mo_updateurl_tx']));
                }
            }
            return $res;
        } else {
            return false;
        }
    }

}

?>