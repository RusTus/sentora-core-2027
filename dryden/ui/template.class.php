<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Interface template selection class.
 * @package zpanelx
 * @subpackage dryden -> ui
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
class ui_template {

    /**
     * Returns the name (folder name) of the template that should be used for the current user.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return string The template name.
     */
    static function GetUserTemplate() {
        $user = ctrl_users::GetUserDetail();
        if (fs_director::CheckForEmptyValue($user['usertheme'])) {
            # Lets use the reseller's theme they have setup!
            $reseller = ctrl_users::GetUserDetail($user['resellerid']);
            return $reseller['usertheme'];
        } else {
            return $user['usertheme'];
        }
    }

    /**
     * Lists all avaliable templates in the 'etc/styles/' folder.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return array 
     */
    static function ListAvaliableTemplates() {
        $allstyles = array();
        $rootdir = ctrl_options::GetSystemOption('sentora_root') . 'etc/styles';
        $handle = @opendir($rootdir);
        $chkdir = $rootdir . '/';
        if ($handle) {
            while ($file = readdir($handle)) {
                if ($file != "." && $file != "..") {
                    if (is_dir($chkdir . $file)) {
                        array_push($allstyles, array('name' => $file));
                    }
                }
            }
            closedir($handle);
        }
        return $allstyles;
    }

    /**
     * Returns a list of all avaliable CSS styles for a given theme. If only a single CSS style then it will return false.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $template The template name (folder name) of which to check for extra CSS stlyes.
     * @return array List of avaliable CSS styles for this theme.
     */
    static function ListAvaliableCSS($template) {
        $allstyles = array();
        $rootdir = ctrl_options::GetSystemOption('sentora_root') . 'etc/styles/' . $template . '/css';
        $handle = @opendir($rootdir);
        $chkdir = $rootdir. '/';
        if ($handle) {
            while ($file = readdir($handle)) {
                // A file whose name starts with "_" is a part that the variations import
                // (for example Modern's _layout.css), not a variation of its own
                if ($file != "." && $file != ".." && $file[0] !== '_' && strtolower(substr($file, -4)) == ".css") {
                    if (is_file($chkdir . $file)) {
                        array_push($allstyles, array('name' => substr($file, 0, -4)));
                    }
                }
            }
            closedir($handle);
        }
        // "default" (or else "light"), then "dark", the others by name, "auto" last
        usort($allstyles, function ($a, $b) {
            $order = array('default' => 0, 'light' => 1, 'dark' => 2, 'auto' => 9);
            $rank = function ($n) use ($order) { return isset($order[$n]) ? $order[$n] : 5; };
            return ($rank($a['name']) - $rank($b['name'])) ?: strcmp($a['name'], $b['name']);
        });
        return $allstyles;
    }

    /**
     * The variation a template uses when none has been chosen (or the chosen one is
     * not in this template): "default" when it has one, otherwise its first.
     * @param string $template The template name (folder name).
     * @return string The variation name ('default' when the template has none at all).
     */
    static function DefaultCSS($template) {
        $list = self::ListAvaliableCSS($template);
        return $list ? $list[0]['name'] : 'default';
    }

    /**
     * The variation the user chose for themselves in My Account ('' = the theme's
     * default, as their reseller set it in Theme Manager). Kept in its own small table,
     * made on first use, so Theme Manager (which sets every client's variation) never
     * overwrites a person's own choice.
     * @param int $uid
     * @return string
     */
    static function GetStylePref($uid) {
        global $zdbh;
        static $cache = array();
        $uid = (int) $uid;
        if ($uid <= 0 || !isset($zdbh)) {
            return '';
        }
        if (!array_key_exists($uid, $cache)) {
            try {
                self::EnsureStylePrefs();
                $q = $zdbh->prepare("SELECT us_style_vc FROM x_user_styles WHERE us_acc_fk = :u");
                $q->execute(array(':u' => $uid));
                $v = (string) $q->fetchColumn();
                $cache[$uid] = preg_match('/^[A-Za-z0-9_-]{1,64}$/', $v) ? $v : '';
            } catch (Exception $e) {
                $cache[$uid] = '';
            }
        }
        return $cache[$uid];
    }

    /**
     * Saves the user's own variation ('' to follow the theme's default again).
     * @param int $uid
     * @param string $style
     * @return bool
     */
    static function SetStylePref($uid, $style) {
        global $zdbh;
        $uid = (int) $uid;
        $style = (string) $style;
        if ($uid <= 0 || ($style !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $style))) {
            return false;
        }
        self::EnsureStylePrefs();
        if ($style === '') {
            $zdbh->prepare("DELETE FROM x_user_styles WHERE us_acc_fk = :u")->execute(array(':u' => $uid));
        } else {
            $zdbh->prepare("INSERT INTO x_user_styles (us_acc_fk, us_style_vc) VALUES (:u, :s) ON DUPLICATE KEY UPDATE us_style_vc = :s2")
                ->execute(array(':u' => $uid, ':s' => $style, ':s2' => $style));
        }
        return true;
    }

    private static function EnsureStylePrefs() {
        global $zdbh;
        static $done = false;
        if (!$done) {
            $zdbh->exec("CREATE TABLE IF NOT EXISTS x_user_styles (us_acc_fk INT NOT NULL PRIMARY KEY, us_style_vc VARCHAR(64) NOT NULL) ENGINE=InnoDB");
            $done = true;
        }
    }

    /**
     * A variation's name as people read it: "dark" -> "Dark", "high_contrast" -> "High contrast".
     * @param string $name
     * @return string
     */
    static function CSSLabel($name) {
        return ucfirst(str_replace(array('_', '-'), ' ', (string) $name));
    }

    /**
     * A template can draw module icons of its own: img/icons/<module>.svg (Modern does).
     * Returns that file's path for the current user's template, or false.
     * @param string $module The module folder name.
     * @return string|false
     */
    static function ThemeIcon($module) {
        static $cache = array();
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $module)) {
            return false;
        }
        if (!array_key_exists($module, $cache)) {
            $path = 'etc/styles/' . self::CurrentTemplateCached() . '/img/icons/' . $module . '.svg';
            $cache[$module] = is_file($path) ? $path : false;
        }
        return $cache[$module];
    }

    /**
     * Does the current user's template have module icons of its own (img/icons/)?
     * @return bool
     */
    static function HasThemeIcons() {
        static $has = null;
        if ($has === null) {
            $has = is_dir('etc/styles/' . self::CurrentTemplateCached() . '/img/icons');
        }
        return $has;
    }

    /**
     * Dashboard icon HTML for a module: the template's own icon (a mask the theme's CSS
     * colours) when it has one, its PNG on the same tile when the template has icons
     * but not for this module, otherwise the plain PNG as before.
     * @param string $module The module folder name.
     * @param string $png The module's PNG icon path.
     * @return string
     */
    static function DashboardIcon($module, $png) {
        $svg = self::ThemeIcon($module);
        if ($svg) {
            $url = htmlspecialchars($svg, ENT_QUOTES);
            return '<span class="theme-icon"><span style="-webkit-mask-image:url(' . $url . ');mask-image:url(' . $url . ')"></span></span>';
        }
        if (self::HasThemeIcons()) {
            return '<span class="theme-icon"><img src="' . htmlspecialchars($png, ENT_QUOTES) . '" alt=""></span>';
        }
        return '<img src="' . htmlspecialchars($png, ENT_QUOTES) . '" border="0">';
    }

    /**
     * Small icon for menus (sidebar, top bar): the template's own icon in the link's
     * colour, or false when the template has none for this module.
     * @param string $module The module folder name.
     * @return string|false
     */
    static function MenuIcon($module) {
        $svg = self::ThemeIcon($module);
        if (!$svg) {
            return false;
        }
        $url = htmlspecialchars($svg, ENT_QUOTES);
        return '<i class="theme-icon-sm" style="-webkit-mask-image:url(' . $url . ');mask-image:url(' . $url . ')"></i>';
    }

    /** the current user's template, looked up once per page */
    private static function CurrentTemplateCached() {
        static $tpl = null;
        if ($tpl === null) {
            $tpl = ctrl_users::SafeTheme(self::GetUserTemplate());
            if ($tpl === '') {
                $tpl = 'Modern';
            }
        }
        return $tpl;
    }

}

?>
