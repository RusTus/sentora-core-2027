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
 * Template tag <# ui_tpl_breadcrumbs #>: the breadcrumb trail.
 */
class ui_tpl_breadcrumbs {

    /**
     * Builds the Home > category > module breadcrumb for the current module page. The settings
     * pages opened from Module Admin (Apache Config, DNS Config and the like) have no menu
     * category of their own: theirs is Home > Module Admin's category > Module Admin > the page,
     * so there is a way back. Names are translated and escaped.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller (reads the module URL parameter).
     * @return string A <ul class="breadcrumb"> list, or an empty string on the dashboard or when the module is unknown.
     */
    public static function Template() {
        global $zdbh, $controller;
        $module = (string) $controller->GetControllerRequest('URL', 'module');
        if ($module === '') {
            return '';
        }
        $row = self::Row($module);
        if (!$row) {
            return '';
        }
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $t = function ($s) use ($h) { return $h(ui_language::translate((string) $s)); };
        $cat = function ($name) { return './#' . strtolower(str_replace(' ', '-', (string) $name)); };
        $crumbs = array(array($t('Home'), '.'));
        if ($row['mo_type_en'] === 'modadmin') {
            $admin = self::Row('moduleadmin');
            if ($admin && (string) $admin['mc_name_vc'] !== '') {
                $crumbs[] = array($t($admin['mc_name_vc']), $cat($admin['mc_name_vc']));
            }
            $crumbs[] = array($t($admin ? $admin['mo_name_vc'] : 'Module Admin'), './?module=moduleadmin');
        } elseif ((string) $row['mc_name_vc'] !== '') {
            $crumbs[] = array($t($row['mc_name_vc']), $cat($row['mc_name_vc']));
        }
        $line = '<ul class="breadcrumb">';
        foreach ($crumbs as $c) {
            $line .= '<li><a href="' . $h($c[1]) . '">' . $c[0] . '</a></li>';
        }
        return $line . '<li class="active">' . $t($row['mo_name_vc']) . '</li></ul>';
    }

    /**
     * A module's name, type and menu category.
     *
     * @param string $folder The module's folder name.
     * @return array<string, mixed>|false mo_name_vc, mo_type_en, mc_name_vc (null without a category), or false.
     */
    private static function Row($folder) {
        global $zdbh;
        $q = $zdbh->prepare('SELECT m.mo_name_vc, m.mo_type_en, c.mc_name_vc FROM x_modules m LEFT JOIN x_modcats c ON c.mc_id_pk = m.mo_category_fk WHERE m.mo_folder_vc = :m');
        $q->execute(array(':m' => $folder));
        return $q->fetch(PDO::FETCH_ASSOC);
    }
}
?>