<?php

/**
 * PHP Versions: choose which extra PHP versions domains may use.
 * The versions themselves are installed by the installer in /opt/sentora-php/.
 */
class module_controller extends ctrl_module
{

    static $ok;

    static function getVersionRows()
    {
        $nl = fs_filehandler::NewLine();
        $counts = sys_phpversions::UsageCounts();
        $enabled = sys_phpversions::Enabled();
        $default = sys_phpversions::DefaultVersion();

        $html = '<tr><td><input type="checkbox" checked="checked" disabled="disabled" /></td>'
            . '<td><strong>PHP ' . $default . '</strong> (' . ui_language::translate('default, used by the panel') . ')</td>'
            . '<td>' . ui_language::translate('built into Apache') . '</td>'
            . '<td>' . sys_phpversions::SupportNote($default) . '</td>'
            . '<td>' . ui_language::translate('all other domains') . '</td></tr>' . $nl;

        $installed = sys_phpversions::Installed();
        if (count($installed) == 0) {
            $html .= '<tr><td colspan="5">' . ui_language::translate('No extra PHP versions are installed on this server.') . '</td></tr>' . $nl;
            return $html;
        }
        foreach ($installed as $version) {
            $on = in_array($version, $enabled, true);
            $running = sys_phpversions::IsRunning($version);
            $note = sys_phpversions::SupportNote($version);
            $expired = isset(sys_phpversions::$supportEnds[$version]) && strtotime(sys_phpversions::$supportEnds[$version] . ' 23:59:59') < time();
            $html .= '<tr>'
                . '<td><input type="checkbox" name="inEnabled_' . str_replace('.', '_', $version) . '" value="1"' . ($on ? ' checked="checked"' : '') . ' /></td>'
                . '<td>PHP ' . $version . '</td>'
                . '<td>' . ($running ? ui_language::translate('running') : ui_language::translate('stopped')) . '</td>'
                . '<td>' . ($expired ? '<span class="text-danger"><strong>' . $note . '</strong></span>' : $note) . '</td>'
                . '<td>' . (isset($counts[$version]) ? $counts[$version] : 0) . '</td>'
                . '</tr>' . $nl;
        }
        return $html;
    }

    static function doSaveVersions()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        $chosen = array();
        foreach (sys_phpversions::Installed() as $version) {
            $field = 'inEnabled_' . str_replace('.', '_', $version);
            if (isset($formvars[$field]) && $formvars[$field] == '1') {
                $chosen[] = $version;
            }
        }
        $value = implode(',', $chosen);
        $sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = 'php_versions_enabled'");
        $sql->bindParam(':value', $value);
        $sql->execute();
        // Rewrite the vhosts and start/stop PHP-FPM services at the next daemon run
        $sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc = 'apache_changed'");
        $sql->execute();
        self::$ok = true;
        return true;
    }

    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("PHP versions saved. They apply at the next daemon run (within 5 minutes)."), "zannounceok");
        }
        return;
    }

}

?>
