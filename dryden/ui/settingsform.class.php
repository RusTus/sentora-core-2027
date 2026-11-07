<?php

/**
 * A settings page for a module's rows in x_settings (Mail Admin, Apache Admin): the settings in
 * tabs, each with the control that fits it (a switch, a list with readable choices, a number with
 * its range, a time zone, text or a block of configuration), checked before anything is saved.
 *
 * A module describes its settings in a spec: name => array(
 *     'section' => tab title, 'label' => name, 'help' => what it does,
 *     'type' => 'switch' | 'select' | 'number' | 'text' | 'code' | 'timezone',
 *     'options' => array(value => label) (select), 'min' / 'max' / 'unit' (number),
 *     'pattern' => PCRE a text must match, 'pattern_help' => what it must look like, 'empty' => true when it may be empty).
 * Editable settings of the module that the spec does not list are shown too, under "Other".
 * Tab titles, labels, help texts, option labels and units are English and translated when shown
 * (labels and help texts from x_settings too, at display only); stored values never are.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
class ui_settingsform
{

    /**
     * Escape a value for HTML.
     *
     * @param mixed $s The value (cast to string).
     * @return string The escaped text.
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translate and escape a text.
     *
     * @param string $s The English text.
     * @return string The escaped translation.
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * The module's editable settings, each with its spec (or one made from its row).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $module The module name (x_settings.so_module_vc).
     * @param array<string, array<string, mixed>> $spec See the class description.
     * @return array<string, array<string, mixed>> By name, in the spec's order: the spec plus 'value'.
     */
    static function Fields($module, array $spec)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx FROM x_settings WHERE so_module_vc = :m AND so_usereditable_en = 'true' ORDER BY so_cleanname_vc");
        $q->execute(array(':m' => $module));
        $rows = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[$r['so_name_vc']] = $r;
        }
        $out = array();
        foreach ($spec as $name => $f) {
            if (isset($rows[$name])) {
                $out[$name] = $f + array('label' => $rows[$name]['so_cleanname_vc'], 'help' => $rows[$name]['so_desc_tx'], 'value' => (string) $rows[$name]['so_value_tx']);
                $out[$name]['value'] = (string) $rows[$name]['so_value_tx'];
                unset($rows[$name]);
            }
        }
        foreach ($rows as $name => $r) {
            $opts = ctrl_options::CheckForPredefinedOptions($r['so_defvalues_tx']) ? explode('|', (string) $r['so_defvalues_tx']) : array();
            $bool = $opts && count(array_diff(array_map('strtolower', $opts), array('true', 'false'))) === 0;
            $out[$name] = array('section' => 'Other', 'label' => $r['so_cleanname_vc'], 'help' => $r['so_desc_tx'], 'value' => (string) $r['so_value_tx'],
                'type' => $bool ? 'switch' : ($opts ? 'select' : 'text'), 'options' => $opts ? array_combine($opts, $opts) : array(), 'raw_options' => true, 'empty' => true);
        }
        return $out;
    }

    /**
     * The settings as tabs and fields, in one form.
     *
     * @param array<string, array<string, mixed>> $fields From Fields().
     * @param string $action The form's action URL (escaped here).
     * @param string $button The save button's text (English).
     * @param string $extra Optional. HTML put before the save button (such as a tick box). Default ''.
     * @return string The HTML.
     */
    static function Render(array $fields, $action, $button, $extra = '')
    {
        $sections = array();
        foreach ($fields as $name => $f) {
            $sections[$f['section']][$name] = $f;
        }
        $tabs = '';
        $panes = '';
        $n = 0;
        foreach ($sections as $title => $list) {
            $id = 'sf-p' . $n++;
            $tabs .= '<button type="button" class="sf-tab" role="tab" data-pane="' . $id . '">' . self::t($title) . '</button>';
            $rows = '';
            foreach ($list as $name => $f) {
                $rows .= self::Row($name, $f);
            }
            $panes .= '<section class="sf-pane" id="' . $id . '" role="tabpanel">' . $rows . '</section>';
        }
        return '<form class="sf" method="post" action="' . self::h($action) . '" autocomplete="off" data-unsaved="' . self::t('You have changes that are not saved.') . '">'
            . (count($sections) > 1 ? '<nav class="sf-tabs" role="tablist">' . $tabs . '</nav>' : '') . $panes
            . '<div class="sf-foot">' . $extra . '<span class="sf-dirty" hidden>' . self::t('Unsaved changes') . '</span>'
            . (count($sections) > 1 ? '<span class="sf-hint">' . self::t('Changes on every tab are saved together.') . '</span>' : '')
            . runtime_csfr::Token() . '<button class="btn btn-primary" type="submit" name="inSaveSystem">' . self::t($button) . '</button></div></form>'
            . '<script>(function(){var f=document.querySelector(".sf");if(!f){return;}var tabs=f.querySelectorAll(".sf-tab"),panes=f.querySelectorAll(".sf-pane");'
            . 'var show=function(id){Array.prototype.forEach.call(panes,function(p){p.hidden=tabs.length>0&&p.id!==id;});Array.prototype.forEach.call(tabs,function(b){var on=b.getAttribute("data-pane")===id;b.classList.toggle("active",on);b.setAttribute("aria-selected",on?"true":"false");});try{sessionStorage.setItem("sf-tab-"+location.search,id);}catch(e){}};'
            . 'Array.prototype.forEach.call(tabs,function(b){b.addEventListener("click",function(){show(b.getAttribute("data-pane"));});});'
            . 'var s=null;try{s=sessionStorage.getItem("sf-tab-"+location.search);}catch(e){}show(s&&document.getElementById(s)?s:(panes[0]?panes[0].id:""));'
            . 'var bad=f.querySelector(".sf-row.is-bad");if(bad){show(bad.parentNode.id);}'
            . 'var dirty=false,d=f.querySelector(".sf-dirty");f.addEventListener("input",function(){dirty=true;d.hidden=false;});f.addEventListener("change",function(){dirty=true;d.hidden=false;});'
            . 'f.addEventListener("submit",function(){dirty=false;});window.addEventListener("beforeunload",function(e){if(dirty){e.preventDefault();e.returnValue=f.getAttribute("data-unsaved");}});})();</script>';
    }

    /**
     * One setting: its name and help on the left, its control on the right.
     *
     * @param string $name The setting name.
     * @param array<string, mixed> $f Its spec and value (and 'error' after a refused save).
     * @return string The HTML.
     */
    static function Row($name, array $f)
    {
        $id = 'sf_' . self::h($name);
        $v = (string) $f['value'];
        $n = self::h($name);
        switch ($f['type']) {
            case 'switch':
                $ctl = '<input type="hidden" name="' . $n . '" value="false"><label class="sf-switch"><input type="checkbox" name="' . $n . '" id="' . $id . '" value="true"'
                    . (strtolower($v) === 'true' ? ' checked' : '') . '><span class="sf-toggle"></span></label>';
                break;
            case 'select':
                $ctl = '<select class="form-control" name="' . $n . '" id="' . $id . '">';
                foreach ($f['options'] as $val => $label) {
                    $ctl .= '<option value="' . self::h($val) . '"' . ((string) $val === $v ? ' selected' : '') . '>' . (empty($f['raw_options']) ? self::t($label) : self::h($label)) . '</option>';
                }
                $ctl .= '</select>';
                break;
            case 'number':
                $ctl = '<div class="sf-num"><input class="form-control" type="number" name="' . $n . '" id="' . $id . '" value="' . self::h($v) . '" step="1"'
                    . (isset($f['min']) ? ' min="' . (int) $f['min'] . '"' : '') . (isset($f['max']) ? ' max="' . (int) $f['max'] . '"' : '') . ' required>'
                    . (!empty($f['unit']) ? '<span>' . self::t($f['unit']) . '</span>' : '') . '</div>';
                break;
            case 'code':
                $ctl = '<textarea class="form-control sf-code" name="' . $n . '" id="' . $id . '" rows="6" spellcheck="false">' . self::h($v) . '</textarea>';
                break;
            case 'timezone':
                $ctl = '<input class="form-control" type="text" name="' . $n . '" id="' . $id . '" value="' . self::h($v) . '" list="sf-tz" required spellcheck="false">'
                    . '<datalist id="sf-tz">' . implode('', array_map(function ($z) { return '<option value="' . $z . '">'; }, timezone_identifiers_list())) . '</datalist>';
                break;
            default:
                $ctl = '<input class="form-control" type="text" name="' . $n . '" id="' . $id . '" value="' . self::h($v) . '"' . (empty($f['empty']) ? ' required' : '') . ' spellcheck="false">';
        }
        $range = $f['type'] === 'number' && isset($f['min'], $f['max']) ? ' <span class="sf-range">' . (int) $f['min'] . '&ndash;' . (int) $f['max'] . '</span>' : '';
        return '<div class="sf-row' . (!empty($f['error']) ? ' is-bad' : '') . ($f['type'] === 'code' ? ' is-wide' : '') . '"><div class="sf-what"><label for="' . $id . '">' . self::t($f['label']) . $range . '</label>'
            . (!empty($f['help']) ? '<small>' . self::t($f['help']) . '</small>' : '') . (!empty($f['error']) ? '<em>' . self::h(self::ErrorText($f)) . '</em>' : '') . '</div><div class="sf-ctl">' . $ctl . '</div></div>';
    }

    /**
     * A setting's error, translated, with its numbers put into the %d places (not escaped).
     *
     * @param array<string, mixed> $f Its spec with 'error' (English) and, optionally, 'error_args' (numbers for each %d in order).
     * @return string The translated text.
     */
    static function ErrorText(array $f)
    {
        $text = ui_language::translate((string) $f['error']);
        foreach (isset($f['error_args']) ? (array) $f['error_args'] : array() as $a) {
            $text = preg_replace('/%d/', (string) (int) $a, $text, 1);
        }
        return $text;
    }

    /**
     * Check the posted settings. A value that is not allowed is not saved, and gets 'error' (its posted value kept to show).
     *
     * @param array<string, array<string, mixed>> $fields From Fields() (changed in place: 'value' and 'error').
     * @param array<string, mixed> $form The posted form.
     * @return array<string, string> The changed, allowed values, by setting name.
     */
    static function Read(array &$fields, array $form)
    {
        $changes = array();
        foreach ($fields as $name => &$f) {
            if (!array_key_exists($name, $form)) {
                continue;
            }
            $v = is_array($form[$name]) ? (string) end($form[$name]) : (string) $form[$name];
            $err = '';
            switch ($f['type']) {
                case 'switch':
                    $v = $v === 'true' ? 'true' : 'false';
                    break;
                case 'select':
                    if (!array_key_exists($v, $f['options'])) {
                        $err = 'Choose one of the listed values.';
                    }
                    break;
                case 'number':
                    $v = trim($v);
                    if (!preg_match('/^-?\d{1,9}$/', $v) || (isset($f['min']) && (int) $v < $f['min']) || (isset($f['max']) && (int) $v > $f['max'])) {
                        $err = isset($f['min'], $f['max']) ? 'Enter a whole number from %d to %d.' : 'Enter a whole number.';
                        $f['error_args'] = isset($f['min'], $f['max']) ? array((int) $f['min'], (int) $f['max']) : array();
                    }
                    break;
                case 'timezone':
                    $v = trim($v);
                    if (!in_array($v, timezone_identifiers_list(), true)) {
                        $err = 'Choose a time zone from the list, such as Europe/London.';
                    }
                    break;
                case 'code':
                    $v = rtrim(str_replace("\r\n", "\n", $v));
                    if (strlen($v) > 20000) {
                        $err = 'This is too long.';
                    }
                    break;
                default:
                    $v = trim($v);
                    if ($v === '' && empty($f['empty'])) {
                        $err = 'This can not be empty.';
                    } elseif (strpbrk($v, "\r\n") !== false || strlen($v) > 2000) {
                        $err = 'Enter a single line.';
                    } elseif ($v !== '' && !empty($f['pattern']) && !preg_match($f['pattern'], $v)) {
                        $err = !empty($f['pattern_help']) ? $f['pattern_help'] : 'This is not in the right form.';
                    }
            }
            if ($err !== '') {
                $f['error'] = $err;
                $f['value'] = $v;
                continue;
            }
            if ($v !== (string) $f['value']) {
                $changes[$name] = $v;
                $f['value'] = $v;
            }
        }
        unset($f);
        return $changes;
    }

    /**
     * Save settings.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array<string, string> $changes By setting name (from Read()).
     * @return int How many were saved.
     */
    static function Write(array $changes)
    {
        global $zdbh;
        $q = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = :n AND so_usereditable_en = 'true'");
        foreach ($changes as $name => $v) {
            $q->execute(array(':v' => $v, ':n' => $name));
        }
        return count($changes);
    }

    /**
     * The message after a save: what was saved, and what was refused.
     *
     * @param array<string, array<string, mixed>> $fields From Read().
     * @param int $saved How many were saved.
     * @param string $applies Optional. When the change applies (English), added to the saved message. Default ''.
     * @return string The HTML.
     */
    static function Result(array $fields, $saved, $applies = '')
    {
        $bad = array();
        foreach ($fields as $f) {
            if (!empty($f['error'])) {
                $bad[] = self::t($f['label']);
            }
        }
        $out = '';
        if ($bad) {
            $out .= ui_sysmessage::shout(self::t('Not saved, as the value is not allowed:') . ' <b>' . implode(', ', $bad) . '</b>. ' . self::t('The reason is shown next to each.'), 'zannounceerror');
        }
        if ($saved > 0) {
            $out .= ui_sysmessage::shout(($saved === 1 ? self::t('1 setting saved.') : str_replace('%d', (string) (int) $saved, self::t('%d settings saved.'))) . ($applies !== '' ? ' ' . self::t($applies) : ''), 'zannounceok');
        } elseif (!$bad) {
            $out .= ui_sysmessage::shout(self::t('Nothing was changed.'), 'zannounceinfo');
        }
        return $out;
    }
}
