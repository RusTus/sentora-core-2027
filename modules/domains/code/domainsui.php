<?php

/**
 * Domains, Sub Domains and Parked Domains: the parts of their pages they share, so the
 * three look and work the same (figures, a card per domain, the delete confirmation).
 * Each module keeps its own controller, actions and checks.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * Shared page parts of the three domain modules.
 */
class domains_ui
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
     * An inline line icon.
     *
     * @param string $name 'globe', 'folder', 'live', 'wait', 'open', 'trash', 'park' or 'sub'.
     * @param string $class Optional. Extra class. Default 'dm-ico'.
     * @return string The SVG.
     */
    static function Icon($name, $class = 'dm-ico')
    {
        $paths = array(
            'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
            'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
            'live' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
            'wait' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
            'open' => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
            'trash' => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
            'park' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M10 17V7h3a3 3 0 0 1 0 6h-3"/>',
            'sub' => '<path d="M6 3v12a3 3 0 0 0 3 3h9"/><path d="m15 15 3 3-3 3"/>',
            'warn' => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
        );
        return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . (isset($paths[$name]) ? $paths[$name] : '') . '</svg>';
    }

    /**
     * The figures at the top: how many against the package's limit (with a bar), live, and waiting for the next update.
     *
     * @param array<int, array<string, mixed>> $rows The domains ('active' 1 = live).
     * @param int|string $quota The package's limit (negative: no limit).
     * @param string $noun What they are, for the first figure ('Domains', 'Sub-domains', 'Parked domains').
     * @param string $icon The icon of the first figure (see Icon()).
     * @return string The HTML.
     */
    static function Tiles($rows, $quota, $noun, $icon)
    {
        $used = count($rows);
        $live = count(array_filter($rows, function ($r) { return (int) $r['active'] === 1; }));
        $max = (int) $quota;
        $pct = $max > 0 ? min(100, round($used / $max * 100)) : 0;
        $full = $max >= 0 && $used >= $max;
        return '<div class="dm-tile' . ($full ? ' full' : '') . '">' . self::Icon($icon, 'dm-ico-lg') . '<div><b>' . $used . ($max < 0 ? '' : ' <small>/ ' . $max . '</small>') . '</b>'
            . '<span>' . self::t($noun) . ($max < 0 ? ' &middot; ' . self::t('no limit') : '') . '</span>'
            . ($max > 0 ? '<div class="dm-bar"><i style="width:' . $pct . '%"></i></div>' : '') . '</div></div>'
            . '<div class="dm-tile ok">' . self::Icon('live', 'dm-ico-lg') . '<div><b>' . $live . '</b><span>' . self::t('Live') . '</span></div></div>'
            . '<div class="dm-tile' . ($used - $live ? ' wait' : '') . '">' . self::Icon('wait', 'dm-ico-lg') . '<div><b>' . ($used - $live) . '</b><span>' . self::t('Waiting for the next update') . '</span></div></div>';
    }

    /**
     * One card per domain: its name (and its Unicode form for an international name), the folder it shows,
     * Live or Waiting, its PHP version (changed in place when the module allows it), Visit and Delete.
     *
     * The Delete buttons belong to the form the cards are in (the module's confirm action); each PHP change
     * is a small form of its own.
     *
     * @param array<int, array<string, mixed>> $rows Each: 'id', 'name', 'active', and optionally 'dir' (folder in public_html, '' for public_html itself), 'php' (version, '' = default), 'created' (Unix time).
     * @param array<string, mixed> $o 'module' (folder name), 'phpAction' and 'phpField' (null: no PHP change), 'parked' (true: shows the holding page).
     * @return string The HTML ('' when there are none).
     */
    static function Cards($rows, $o)
    {
        $out = '';
        $canPhp = !empty($o['phpAction']) && count(sys_phpversions::Enabled()) > 0;
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $name = (string) $r['name'];
            $live = (int) $r['active'] === 1;
            $where = !empty($o['parked']) ? '<span class="dm-where">' . self::Icon('park') . self::t('Shows the holding page') . '</span>'
                : '<span class="dm-where" title="' . self::t('Its files') . '">' . self::Icon('folder') . '<code>public_html' . self::h(rtrim('/' . trim((string) $r['dir'], '/'), '/')) . '</code></span>';
            $phpMeta = '';
            $phpForm = '';
            if (array_key_exists('php', $r)) {
                $label = sys_phpversions::ListLabel((string) $r['php']);
                $note = strpos($label, '<span') !== false ? substr($label, strpos($label, '<span')) : '';
                if ($canPhp) {
                    $phpForm = '<form class="dm-php" method="post" action="./?module=' . self::h($o['module']) . '&amp;action=' . self::h($o['phpAction']) . '">'
                        . '<input type="hidden" name="' . self::h($o['phpField']) . '" value="' . $id . '">' . runtime_csfr::Token()
                        . '<label><span>PHP</span><select class="form-control input-sm" name="inPhpVersion" aria-label="' . self::t('PHP version for') . ' ' . self::h($name) . '" onchange="this.form.submit()">'
                        . sys_phpversions::SelectOptions((string) $r['php']) . '</select></label>'
                        . '<noscript><button class="btn btn-default btn-sm" type="submit">' . self::t('Change') . '</button></noscript>'
                        . ($note !== '' ? '<div class="dm-phpnote">' . $note . '</div>' : '') . '</form>';
                } else {
                    $phpMeta = '<span class="dm-phpv">PHP ' . $label . '</span>';
                }
            }
            $out .= '<div class="dm-card' . ($live ? '' : ' is-wait') . '" data-s="' . self::h(strtolower($name)) . '">'
                . '<div class="dm-card-main">'
                . '<div class="dm-name">' . self::Icon(!empty($o['parked']) ? 'park' : (!empty($o['sub']) ? 'sub' : 'globe'), 'dm-ico dm-nico')
                . '<a class="dm-link" href="http://' . self::h($name) . '/" target="_blank" rel="noopener" data-idn="' . self::h($name) . '">' . self::h($name) . '</a>'
                . ($live ? '<span class="dm-state live">' . self::t('Live') . '</span>'
                    : '<span class="dm-state wait" title="' . self::t('It goes live at the next update of the web server, within an hour.') . '">' . self::t('Waiting for the next update') . '</span>') . '</div>'
                . '<div class="dm-meta">' . $where
                . (!empty($r['created']) ? '<span class="dm-date">' . self::t('Added') . ' ' . self::h(date('Y-m-d', (int) $r['created'])) . '</span>' : '')
                . $phpMeta . '</div>'
                . '</div>'
                . $phpForm
                . '<div class="dm-act"><a class="btn btn-default btn-sm" href="http://' . self::h($name) . '/" target="_blank" rel="noopener">' . self::Icon('open') . self::t('Visit') . '</a>'
                . '<button class="btn btn-default btn-sm dm-del" type="submit" form="dm-delete" name="inDelete_' . $id . '" value="inDelete_' . $id . '" title="' . self::t('Delete') . '" aria-label="' . self::t('Delete') . ' ' . self::h($name) . '">' . self::Icon('trash') . '</button></div>'
                . '</div>';
        }
        return $out;
    }

    /**
     * The folders in the account's public_html, as <option>s (for "use a folder that is already there").
     *
     * @param array<int, array<string, string>>|false $dirs Rows with 'domains' (the folder name).
     * @return string The HTML.
     */
    static function FolderOptions($dirs)
    {
        $out = '<option value="">public_html/ (' . self::t('the top folder') . ')</option>';
        $names = array();
        foreach ($dirs ? $dirs : array() as $d) {
            $names[] = (string) $d['domains'];
        }
        natcasesort($names);
        foreach ($names as $n) {
            $out .= '<option value="' . self::h($n) . '">public_html/' . self::h($n) . '</option>';
        }
        return $out;
    }

    /**
     * The domain row of the logged-in account by ID and type, for the delete confirmation.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $id The x_vhosts ID.
     * @param int $type 1 domain, 2 sub-domain, 3 parked domain.
     * @return array<string, mixed>|false The row, or false when it is not the account's.
     */
    static function Mine($id, $type)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_id_pk = :id AND vh_acc_fk = :u AND vh_type_in = :t AND vh_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $id, ':u' => (int) ctrl_users::GetUserDetail()['userid'], ':t' => (int) $type));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : false;
    }

    /**
     * What deleting a domain does, for the confirmation.
     *
     * @param array<string, mixed> $row The x_vhosts row.
     * @return string The HTML list.
     */
    static function DeleteDetails($row)
    {
        $parked = (int) $row['vh_type_in'] === 3;
        return '<ul class="dm-lose"><li>' . self::t('It stops answering at the next update of the web server, within an hour.') . '</li>'
            . (!$parked ? '<li>' . self::t('Its files stay in') . ' <code>public_html' . self::h(rtrim('/' . trim((string) $row['vh_directory_vc'], '/'), '/')) . '</code>: '
                . self::t('delete them in File Manager when you no longer need them.') . '</li>' : '')
            . '</ul>';
    }

    /**
     * The domain the confirmation is for (?id=), checked to be the account's: name and ID for the template.
     *
     * @global runtime_controller $controller The request controller.
     * @param int $type 1 domain, 2 sub-domain, 3 parked domain.
     * @return array<string, mixed>|false The row, or false.
     */
    static function Confirming($type)
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'show') !== 'Delete') {
            return false;
        }
        return self::Mine($controller->GetControllerRequest('URL', 'id'), $type);
    }
}
