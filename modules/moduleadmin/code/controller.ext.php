<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 *
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Updated by RusTus, 2026-11-07
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True when the last action failed. */
    static $error;
    /** @var bool|null True after doEditModule() saved the module settings. */
    static $ok;
    /** @var string|null The module folder queued for installation by doInstallModule(). */
    static $uploadqueued = null;
    /** @var string|null Untranslated error message from doInstallModule(). */
    static $error_message;

    /**
     * Escape text for HTML.
     *
     * @param mixed $s The text.
     * @return string
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translated and HTML-escaped text.
     *
     * @param string $s The English text.
     * @return string
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * A module's icon: the theme's own (img/icons/<module>.svg), else the module's icon.png.
     *
     * @param string $folder The module folder.
     * @return string The image address.
     */
    static function IconOf($folder)
    {
        $theme = ui_template::ThemeIcon($folder);
        return $theme ? './' . $theme : './modules/' . rawurlencode($folder) . '/assets/icon.png';
    }

    /**
     * Every module but the core one, with its category name.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<string, mixed>> x_modules rows plus 'cat'.
     */
    static function Modules()
    {
        global $zdbh;
        static $rows = null;
        if ($rows === null) {
            $rows = $zdbh->query("SELECT m.*, c.mc_name_vc AS cat FROM x_modules m LEFT JOIN x_modcats c ON c.mc_id_pk = m.mo_category_fk
                WHERE m.mo_folder_vc <> 'zpx_core_module' ORDER BY m.mo_name_vc ASC")->fetchAll();
        }
        return $rows;
    }

    /**
     * Template HTML: counts of the modules (all, on, off, by type).
     *
     * @return string HTML.
     */
    static function getSummary()
    {
        $n = array('all' => 0, 'on' => 0, 'off' => 0, 'user' => 0, 'modadmin' => 0);
        foreach (self::Modules() as $m) {
            $n['all']++;
            $n[$m['mo_enabled_en'] === 'true' ? 'on' : 'off']++;
            if (isset($n[$m['mo_type_en']])) {
                $n[$m['mo_type_en']]++;
            }
        }
        $card = function ($value, $label, $color) {
            return '<div class="mo-stat" style="--c:' . $color . '"><b>' . (int) $value . '</b><span>' . self::t($label) . '</span></div>';
        };
        return $card($n['all'], 'Modules', '#447fb3') . $card($n['on'], 'Switched on', '#2db87d') . $card($n['off'], 'Switched off', $n['off'] ? '#f5a623' : '#9aa5b1')
            . $card($n['user'], 'In the menus', '#1fa2b5') . $card($n['modadmin'], 'Administration', '#8e6bd8');
    }

    /**
     * Template HTML: the enabled administration modules (modadmin), as tiles that open them.
     *
     * @return string HTML.
     */
    static function getAdminModules()
    {
        $out = '';
        foreach (self::Modules() as $m) {
            if ($m['mo_type_en'] === 'modadmin' && $m['mo_enabled_en'] === 'true') {
                $out .= '<a class="mo-tile" href="./?module=' . rawurlencode($m['mo_folder_vc']) . '"><img src="' . self::h(self::IconOf($m['mo_folder_vc'])) . '" alt="" width="32" height="32">'
                    . '<span>' . self::h(ui_language::translate($m['mo_name_vc'])) . '</span></a>';
            }
        }
        return $out !== '' ? '<div class="mo-tiles">' . $out . '</div>' : '<p class="mo-empty">' . self::t('You have no administration modules at this time.') . '</p>';
    }

    /**
     * Template HTML: the module settings form, by category: on/off, category and group access.
     *
     * The field names are what doEditModule() reads: inDisable_<id> ('true' or 'false'),
     * inCategory_<id> and inEnable_<group>_<id>. Module Admin itself always stays on and
     * open to administrators; system modules are shown but can not be changed.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string HTML.
     */
    static function getConfigModules()
    {
        global $zdbh;
        $groups = $zdbh->query("SELECT * FROM x_groups ORDER BY ug_id_pk ASC")->fetchAll();
        $cats = $zdbh->query("SELECT * FROM x_modcats ORDER BY mc_name_vc ASC")->fetchAll();
        // the modules by section: each menu category, then administration, then system
        $sections = array();
        foreach (self::Modules() as $m) {
            $key = $m['mo_type_en'] === 'user' ? 'c:' . ($m['cat'] !== null ? $m['cat'] : ui_language::translate('No category')) : ($m['mo_type_en'] === 'modadmin' ? 'z1' : 'z2');
            $sections[$key][] = $m;
        }
        ksort($sections);
        $head = '<tr><th>' . self::t('Module') . '</th><th class="mo-c">' . self::t('On') . '</th><th>' . self::t('Menu category') . '</th>';
        foreach ($groups as $g) {
            $head .= '<th class="mo-c">' . self::h(ui_language::GroupName((string) $g['ug_name_vc'])) . '</th>';
        }
        $head .= '</tr>';
        $cols = 3 + count($groups);
        $rows = '';
        foreach ($sections as $key => $mods) {
            $title = $key === 'z1' ? ui_language::translate('Administration (Module Admin)') : ($key === 'z2' ? ui_language::translate('System (always on)') : substr($key, 2));
            $rows .= '<tr class="mo-section"><th colspan="' . $cols . '">' . self::h($title) . ' <span>' . count($mods) . '</span></th></tr>';
            foreach ($mods as $m) {
                $id = (int) $m['mo_id_pk'];
                $system = $m['mo_type_en'] === 'system';
                $self = $m['mo_folder_vc'] === 'moduleadmin';
                $on = $m['mo_enabled_en'] === 'true';
                $rows .= '<tr class="mo-row' . ($on ? '' : ' off') . '" data-s="' . self::h(strtolower(ui_language::translate($m['mo_name_vc']) . ' ' . $m['mo_folder_vc'])) . '">'
                    . '<td><div class="mo-name"><img src="' . self::h(self::IconOf($m['mo_folder_vc'])) . '" alt="" width="26" height="26"><div>'
                    . '<a href="./?module=moduleadmin&amp;showinfo=' . rawurlencode($m['mo_folder_vc']) . '">' . self::h(ui_language::translate($m['mo_name_vc'])) . '</a>'
                    . '<span>' . self::h($m['mo_folder_vc']) . '</span></div></div></td>';
                // on / off: a switch that sends "false" when off; Module Admin can not be switched off
                if ($system) {
                    $rows .= '<td class="mo-c"><span class="mo-lock" title="' . self::t('System modules are always on') . '">' . self::t('always') . '</span></td>';
                } elseif ($self) {
                    $rows .= '<td class="mo-c"><input type="hidden" name="inDisable_' . $id . '" value="true"><label class="mo-switch" title="' . self::t('Module Admin can not be switched off') . '"><input type="checkbox" checked disabled><span class="mo-toggle"></span></label></td>';
                } else {
                    $rows .= '<td class="mo-c"><label class="mo-switch"><input type="hidden" name="inDisable_' . $id . '" value="false"><input type="checkbox" name="inDisable_' . $id . '" value="true"' . ($on ? ' checked' : '') . '><span class="mo-toggle" aria-hidden="true"></span></label></td>';
                }
                // menu category (only modules shown in the menus have one)
                if ($m['mo_type_en'] === 'user') {
                    $opts = '';
                    foreach ($cats as $c) {
                        $opts .= '<option value="' . (int) $c['mc_id_pk'] . '"' . ((int) $m['mo_category_fk'] === (int) $c['mc_id_pk'] ? ' selected' : '') . '>' . self::h(ui_language::translate((string) $c['mc_name_vc'])) . '</option>';
                    }
                    $rows .= '<td><select class="form-control input-sm" name="inCategory_' . $id . '">' . $opts . '</select></td>';
                } else {
                    $rows .= '<td class="mo-na">' . self::t($system ? 'not in the menus' : 'Module Admin page') . '<input type="hidden" name="inCategory_' . $id . '" value="0"></td>';
                }
                // which groups may use it
                foreach ($groups as $g) {
                    $gid = (int) $g['ug_id_pk'];
                    $checked = ctrl_groups::CheckGroupModulePermissions($gid, $id) ? ' checked' : '';
                    $locked = $system || ($self && $g['ug_name_vc'] === 'Administrators');
                    $rows .= '<td class="mo-c">' . ($self && $g['ug_name_vc'] === 'Administrators' ? '<input type="hidden" value="1" name="inEnable_' . $gid . '_' . $id . '">' : '')
                        . '<input type="checkbox" value="1" name="inEnable_' . $gid . '_' . $id . '"' . $checked . ($locked ? ' disabled' : '') . ' aria-label="' . self::h(ui_language::GroupName((string) $g['ug_name_vc'])) . '"></td>';
                }
                $rows .= '</tr>';
            }
        }
        return '<form action="./?module=moduleadmin&amp;action=EditModule" method="post" class="mo-form">'
            . '<div class="mo-toolbar"><label class="mo-find"><input type="search" class="form-control" placeholder="' . self::t('Find a module') . '" aria-label="' . self::t('Find a module') . '"></label>'
            . '<span class="mo-changed" hidden>' . self::t('Unsaved changes') . '</span><button class="btn btn-primary" type="submit" name="inSave" value="inSave">' . self::t('Save changes') . '</button></div>'
            . '<div class="mo-scroll"><table class="mo-table"><thead>' . $head . '</thead><tbody>' . $rows . '</tbody></table></div>'
            . self::getCSFR_Tag() . '</form>';
    }

    /**
     * Template HTML: the details of the module named by ?showinfo= (from its module.xml, escaped).
     *
     * @global runtime_controller $controller The request controller.
     * @return string HTML.
     */
    static function getModuleInfoCard()
    {
        global $controller;
        $folder = (string) $controller->GetControllerRequest('URL', 'showinfo');
        $row = null;
        foreach (self::Modules() as $m) {
            if ($m['mo_folder_vc'] === $folder) {
                $row = $m;
            }
        }
        if ($row === null) {
            return '<p class="mo-empty">' . self::t('There is no module of that name.') . '</p>';
        }
        $x = (array) ui_module::GetModuleXMLTags($folder);
        $g = function ($k) use ($x) { return isset($x[$k]) ? trim((string) $x[$k]) : ''; };
        $types = array('user' => 'In the menus', 'modadmin' => 'Administration (Module Admin)', 'system' => 'System');
        $author = self::h($g('authorname'));
        $url = $g('authorurl');
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $author = '<a href="' . self::h($url) . '" target="_blank" rel="noopener noreferrer">' . ($author !== '' ? $author : self::h($url)) . '</a>';
        }
        if ($g('authoremail') !== '') {
            $author .= ' <span class="mo-sub">' . self::h($g('authoremail')) . '</span>';
        }
        $dl = array(
            array('Folder', '<code>modules/' . self::h($folder) . '</code>'),
            array('Type', self::t($types[$row['mo_type_en']] ?? $row['mo_type_en'])),
            array('Menu category', $row['mo_type_en'] === 'user' && $row['cat'] !== null ? self::h(ui_language::translate((string) $row['cat'])) : '–'),
            array('Version', self::h($g('version') !== '' ? $g('version') : $row['mo_version_in'])),
            array('Developer', $author !== '' ? $author : '–'),
            array('Installed', (int) $row['mo_installed_ts'] > 0 ? self::h(date('Y-m-d', (int) $row['mo_installed_ts'])) : '–'),
        );
        $out = '<div class="mo-info"><div class="mo-info-head"><img src="' . self::h(self::IconOf($folder)) . '" alt="" width="48" height="48"><div><h3>' . self::h(ui_language::translate($g('name') !== '' ? $g('name') : $row['mo_name_vc'])) . '</h3>'
            . '<span class="mo-pill ' . ($row['mo_enabled_en'] === 'true' ? 'on' : 'off') . '">' . self::t($row['mo_enabled_en'] === 'true' ? 'On' : 'Off') . '</span></div>'
            . '<a class="btn btn-default mo-back" href="./?module=moduleadmin">&larr; ' . self::t('All modules') . '</a></div>'
            . ($g('desc') !== '' ? '<p class="mo-desc">' . self::h(ui_language::translate($g('desc'))) . '</p>' : '') . '<dl class="mo-dl">';
        foreach ($dl as $d) {
            $out .= '<dt>' . self::t($d[0]) . '</dt><dd>' . $d[1] . '</dd>';
        }
        $out .= '</dl>';
        if ($row['mo_enabled_en'] === 'true' && $row['mo_type_en'] !== 'system') {
            $out .= '<a class="btn btn-primary" href="./?module=' . rawurlencode($folder) . '">' . self::t('Open this module') . '</a>';
        }
        return $out . '</div>';
    }


    /**
     * Handle the module settings form POST: save group access, on/off and category of each non-system module.
     *
     * Sets $ok, or $error when there are no modules.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doEditModule()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $sql = "SELECT COUNT(*) FROM x_modules";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_modules WHERE mo_type_en <> 'system' ORDER BY mo_name_vc ASC");
                $sql->execute();
                while ($rowmodule = $sql->fetch()) {
                    $groupssql = $zdbh->query("SELECT * FROM x_groups ORDER BY ug_name_vc ASC");
                    while ($groups = $groupssql->fetch()) {
                        if (isset($_POST['inEnable_' . $groups['ug_id_pk'] . '_' . $rowmodule['mo_id_pk'] . ''])) {
                            ctrl_groups::AddGroupModulePermissions($groups['ug_id_pk'], $rowmodule['mo_id_pk']);
                        } else {
                            ctrl_groups::DeleteGroupModulePermissions($groups['ug_id_pk'], $rowmodule['mo_id_pk']);
                        }
                    }
                    $sql2 = $zdbh->prepare("UPDATE x_modules SET mo_enabled_en = :enabled, mo_category_fk = :category WHERE mo_id_pk = :moduleid");
                    $disable = $controller->GetControllerRequest('FORM', 'inDisable_' . $rowmodule['mo_id_pk'] . '');
                    $category = $controller->GetControllerRequest('FORM', 'inCategory_' . $rowmodule['mo_id_pk'] . '');
                    $sql2->bindParam(':enabled', $disable);
                    $sql2->bindParam(':category', $category);
                    $sql2->bindParam(':moduleid', $rowmodule['mo_id_pk']);
                    $sql2->execute();
                }
                // the same permission stored more than once (every save added it again, before
                // ctrl_groups::AddGroupModulePermissions was fixed): keep one of each
                $zdbh->exec("DELETE p1 FROM x_permissions p1 JOIN x_permissions p2
                    ON p1.pe_group_fk = p2.pe_group_fk AND p1.pe_module_fk = p2.pe_module_fk AND p1.pe_id_pk > p2.pe_id_pk");
                self::$ok = TRUE;
                return;
            }
        }
        self::$error = TRUE;
        return;
    }

    /**
     * Template HTML: the result of the last action, else the module description.
     *
     * @return string|null HTML message or the translated module description.
     */
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$error_message))
            return ui_sysmessage::shout(ui_language::translate(self::$error_message), 'zannounceerror', 'zannounce');
        if (!fs_director::CheckForEmptyValue(self::$uploadqueued)) {
            return ui_sysmessage::shout(ui_language::translate("The module has been queued for installation (this can take up to 5 minutes)."));
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your module options have been saved successfully!"));
        }
        return;
    }

    /**
     * Template flag: the URL asks for a module's information (?showinfo=).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getIsShowModuleInfo()
    {
        global $controller;
        if (isset($_GET['showinfo']) && $controller->GetControllerRequest('URL', 'showinfo'))
            return true;
        return false;
    }










    /**
     * Handle the module upload POST: check the .zpp package and queue it for the root installer.
     *
     * Sets $uploadqueued on success, else $error_message and $error.
     *
     * @global runtime_controller $controller The request controller (unused).
     * @return void
     */
    static function doInstallModule()
    {
        global $controller;
        runtime_csfr::Protect();
        require_once ctrl_options::GetSystemOption('sentora_root') . 'modules/repo_browser/code/repohelper.php';
        self::$error_message = "";
        self::$error = false;
        if (!isset($_FILES['modulefile']) || $_FILES['modulefile']['error'] > 0) {
            self::$error_message = "Couldn't upload the file.";
        } else {
            $archive_ext = fs_director::GetFileExtension($_FILES['modulefile']['name']);
            $module_folder = fs_director::GetFileNameNoExtentsion($_FILES['modulefile']['name']);
            if ($archive_ext != 'zpp') {
                self::$error_message = "Package type was not detected as a .zpp (Sentora Package) archive.";
            } elseif (!repo_browser_helper::ValidModule($module_folder) || repo_browser_helper::IsCoreModule($module_folder)) {
                self::$error_message = "The package name must be a module folder name (letters, numbers, _ and -) and not one of the panel's own modules.";
            } elseif (fs_director::CheckFolderExists(ctrl_options::GetSystemOption('sentora_root') . 'modules/' . $module_folder)) {
                self::$error_message = "The module folder already exists!";
            } elseif (repo_browser_helper::Pending($module_folder) !== false) {
                // the earlier upload is still waiting for the daemon: keep it, do not overwrite it
                self::$error_message = "This module is already queued for installation (this can take up to 5 minutes).";
            } else {
                // Modules are installed by root (the modules folder is not writable by the
                // panel): keep the upload for the root job and queue it
                $dir = repo_browser_helper::UPLOAD_DIR;
                if (!is_dir($dir)) {
                    @mkdir($dir, 0700, true);
                }
                if (move_uploaded_file($_FILES['modulefile']['tmp_name'], $dir . $module_folder . '.zpp')
                    && repo_browser_helper::Enqueue(array('install-upload', $module_folder))) {
                    self::$uploadqueued = $module_folder;
                } else {
                    self::$error_message = "The package could not be queued for installation.";
                }
            }
        }
        if (self::$error_message != "") {
            self::$error = true;
        }
        return;
    }

}
