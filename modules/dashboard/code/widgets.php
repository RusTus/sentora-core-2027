<?php

/**
 * Dashboard module: the widget registry (class dashboard_widgets).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
/**
 * Dashboard widgets. Each widget is an id, a title, who may add it (everyone, or
 * administrators) and a function that draws it for the logged-in account only.
 * Links go only to modules the account may open.
 */
class dashboard_widgets
{
    const UPLOADED_DIR = 'etc/dashboard-widgets/';

    /**
     * Return the built-in widgets: id => array(title, description, admin only, on by default).
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: bool}>
     */
    static function BuiltIn()
    {
        return array(
            'getting_started' => array('Getting started', 'The steps to get a website running, ticked off as you do them.', false, true),
            'usage' => array('Account usage', 'Disk, bandwidth, domains, mailboxes and databases against your package.', false, true),
            'site_health' => array('Site health', 'Whether each of your websites works, and what needs attention (Site Health).', false, true),
            'domains' => array('Your domains', 'Your domains and subdomains, with quick links.', false, false),
            'security' => array('Security', 'Two-factor login and recovery codes.', false, false),
            'backups' => array('Backups', 'Your latest backup and your FTP backup.', false, false),
            'server' => array('Server status', 'Services, disk space and open alerts (administrators).', true, false),
            'spamav' => array('Spam and viruses', 'What spam and virus filtering caught (administrators: the whole server and the scanners).', false, false),
        );
    }

    /**
     * Widgets uploaded through Widget Admin (installed by the daemon into
     * etc/dashboard-widgets/<id>/): id => widget.xml's fields
     *
     * Only widgets whose folder has a valid widget.xml (same id) and a widget.php. Cached per request.
     * @return array<string, array<string, mixed>> id => ReadXml() fields plus 'dir' (the folder, ending in /).
     */
    static function Uploaded()
    {
        static $list = null;
        if ($list !== null) {
            return $list;
        }
        $list = array();
        $root = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/' . self::UPLOADED_DIR;
        foreach ((array) glob($root . '*/widget.xml') as $xml) {
            $id = basename(dirname($xml));
            $info = self::ReadXml((string) @file_get_contents($xml));
            if ($info !== false && $info['id'] === $id && is_file(dirname($xml) . '/widget.php')) {
                $list[$id] = $info + array('dir' => dirname($xml) . '/');
            }
        }
        return $list;
    }

    /**
     * Return a widget.xml's fields, or false (read without external entities).
     *
     * @param string $text The contents of widget.xml.
     * @return array<string, mixed>|false 'id', 'name', 'description', 'version', 'author' (strings), 'default' (bool), 'groups' (int[] of group IDs); false when not valid.
     */
    static function ReadXml($text)
    {
        if ($text === '' || strlen($text) > 65536 || stripos($text, '<!DOCTYPE') !== false || stripos($text, '<!ENTITY') !== false) {
            return false;
        }
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($x === false || $x->getName() !== 'widget') {
            return false;
        }
        $id = trim((string) $x->id);
        $name = trim((string) $x->name);
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $id) || $name === '' || strlen($name) > 60) {
            return false;
        }
        $groups = array();
        foreach (preg_split('/[\s,]+/', strtolower(trim((string) $x->groups))) as $g) {
            if (in_array($g, array('administrators', 'resellers', 'users'), true)) {
                $groups[] = array('administrators' => 1, 'resellers' => 2, 'users' => 3)[$g];
            }
        }
        return array('id' => $id, 'name' => $name, 'description' => substr(trim((string) $x->description), 0, 200),
            'version' => substr(trim((string) $x->version), 0, 20), 'author' => substr(trim((string) $x->author), 0, 80),
            'default' => strtolower(trim((string) $x->default)) === 'true', 'groups' => $groups ? $groups : array(1, 2, 3));
    }

    /**
     * Return every widget: id => array(title, description, admin only, on by default, source, version).
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: bool, 4: string, 5: string}> Source is 'built-in' or 'uploaded'.
     */
    static function All()
    {
        $all = array();
        foreach (self::BuiltIn() as $id => $w) {
            $all[$id] = array($w[0], $w[1], $w[2], $w[3], 'built-in', '');
        }
        foreach (self::Uploaded() as $id => $w) {
            if (!isset($all[$id])) {
                $all[$id] = array($w['name'], $w['description'], false, $w['default'], 'uploaded', $w['version']);
            }
        }
        return $all;
    }

    /**
     * Widget Admin's settings for every widget (x_dashboard_widgets): id => array(enabled,
     * default, order, groups); new widgets get theirs from the built-in list or widget.xml
     *
     * Creates the table if it is missing and stores settings for new widgets. Cached per request.
     * @global db_driver $zdbh The panel's database connection.
     * @param bool $fresh Optional. Read them again (after a save in the same request). Default false.
     * @return array<string, array{enabled: bool, default: bool, order: int, groups: int[]}>
     */
    static function Settings($fresh = false)
    {
        global $zdbh;
        static $set = null;
        if ($set !== null && !$fresh) {
            return $set;
        }
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dashboard_widgets (dwc_id_vc VARCHAR(40) NOT NULL PRIMARY KEY, dwc_enabled_in TINYINT NOT NULL DEFAULT 1,
            dwc_default_in TINYINT NOT NULL DEFAULT 0, dwc_order_in INT NOT NULL DEFAULT 100, dwc_groups_tx VARCHAR(255) NOT NULL DEFAULT '[]') DEFAULT CHARSET=utf8");
        $set = array();
        foreach ($zdbh->query("SELECT * FROM x_dashboard_widgets")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $set[$r['dwc_id_vc']] = array('enabled' => (int) $r['dwc_enabled_in'] === 1, 'default' => (int) $r['dwc_default_in'] === 1,
                'order' => (int) $r['dwc_order_in'], 'groups' => array_map('intval', (array) json_decode($r['dwc_groups_tx'], true)));
        }
        $i = 0;
        $groups = array_map('intval', $zdbh->query("SELECT ug_id_pk FROM x_groups")->fetchAll(PDO::FETCH_COLUMN));
        $up = self::Uploaded();
        foreach (self::All() as $id => $w) {
            $i++;
            if (!isset($set[$id])) {
                $g = $w[2] ? array(1) : (isset($up[$id]) ? array_values(array_intersect($up[$id]['groups'], $groups)) : $groups);
                $set[$id] = array('enabled' => true, 'default' => (bool) $w[3], 'order' => $i * 10, 'groups' => $g);
                $zdbh->prepare("INSERT IGNORE INTO x_dashboard_widgets (dwc_id_vc, dwc_enabled_in, dwc_default_in, dwc_order_in, dwc_groups_tx) VALUES (:i, 1, :d, :o, :g)")
                    ->execute(array(':i' => $id, ':d' => $w[3] ? 1 : 0, ':o' => $i * 10, ':g' => json_encode($g)));
            }
            if ($w[2]) {
                $set[$id]['groups'] = array(1); // administrators only, whatever is ticked
            }
        }
        return $set;
    }

    /**
     * Return the widgets a new (or reset) dashboard starts with, in Widget Admin's order.
     *
     * @param array<string,string>|null $user Optional. The account (only widgets it may have), or null for all widgets. Default null.
     * @return string[] Widget IDs.
     */
    static function Defaults($user = null)
    {
        $set = self::Settings();
        $avail = ($user === null) ? self::All() : self::Available($user);
        $ids = array();
        foreach ($avail as $id => $w) {
            if (!empty($set[$id]['default'])) {
                $ids[$id] = $set[$id]['order'];
            }
        }
        asort($ids);
        return array_keys($ids);
    }

    /**
     * Return the widgets this account may have: switched on, and allowed for its group.
     *
     * @param array<string,string> $user The logged-in account (ctrl_users::GetUserDetail()).
     * @return array<string, array{0: string, 1: string, 2: bool, 3: bool, 4: string, 5: string}> Same shape as All().
     */
    static function Available($user)
    {
        $set = self::Settings();
        $out = array();
        foreach (self::All() as $id => $w) {
            $s = isset($set[$id]) ? $set[$id] : null;
            if ($s && $s['enabled'] && in_array((int) $user['usergroupid'], $s['groups'], true) && (!$w[2] || (int) $user['usergroupid'] === 1)) {
                $out[$id] = $w;
            }
        }
        return $out;
    }

    /**
     * A widget package: exactly widget.xml + widget.php (+ widget.css), no folders,
     * 1 MB unpacked at most, a valid widget.xml whose id is not a built-in widget.
     * Returns widget.xml's fields, or why it can not be used. (Widget Admin and the daemon.)
     *
     * @param string $zipFile Path of the uploaded zip file.
     * @return array<string, mixed>|string The ReadXml() fields, or the reason (a sentence) it was refused.
     */
    static function CheckPackage($zipFile)
    {
        $z = new ZipArchive();
        if ($z->open($zipFile, ZipArchive::RDONLY) !== true) {
            return 'it is not a zip file.';
        }
        $allowed = array('widget.xml', 'widget.php', 'widget.css');
        $seen = array();
        $total = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            $name = (string) $st['name'];
            if (!in_array($name, $allowed, true) || isset($seen[$name])) {
                $z->close();
                return 'it may only hold widget.xml, widget.php and widget.css at its top level (found "' . substr($name, 0, 60) . '").';
            }
            $seen[$name] = true;
            $total += (int) $st['size'];
        }
        if (!isset($seen['widget.xml'], $seen['widget.php'])) {
            $z->close();
            return 'widget.xml and widget.php are both needed.';
        }
        if ($total > 1048576) {
            $z->close();
            return 'it unpacks to more than 1 MB.';
        }
        $info = dashboard_widgets::ReadXml((string) $z->getFromName('widget.xml'));
        $php = (string) $z->getFromName('widget.php');
        $z->close();
        if ($info === false) {
            return 'widget.xml is not valid (see etc/dashboard-widgets/README.md).';
        }
        if (isset(dashboard_widgets::BuiltIn()[$info['id']])) {
            return 'its id "' . $info['id'] . '" is a built-in widget.';
        }
        if (!preg_match('/^\s*<\?php/', $php) || !preg_match('/\bclass\s+dashboard_widget_' . preg_quote($info['id'], '/') . '\b/i', $php)) {
            return 'widget.php must start with <?php and define the class dashboard_widget_' . $info['id'] . '.';
        }
        return $info;
    }

    /**
     * Escape text for HTML (UTF-8, quotes too).
     *
     * @param mixed $s The text.
     * @return string
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translate a text into the user's language, HTML-escaped (for use in the widgets' HTML).
     *
     * @param string $s The English text.
     * @return string
     */
    static function t($s)
    {
        return htmlspecialchars(ui_language::translate($s), ENT_QUOTES, 'UTF-8');
    }

    /**
     * May this account open that module (enabled, and allowed for its group)?
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array<string,string> $user The logged-in account (ctrl_users::GetUserDetail()).
     * @param string $folder The module folder name.
     * @return bool
     */
    static function CanOpen($user, $folder)
    {
        global $zdbh;
        static $ids = null;
        if ($ids === null) {
            $ids = array();
            foreach ($zdbh->query("SELECT mo_id_pk, mo_folder_vc FROM x_modules WHERE mo_enabled_en = 'true'")->fetchAll() as $r) {
                $ids[$r['mo_folder_vc']] = (int) $r['mo_id_pk'];
            }
        }
        return isset($ids[$folder]) && ctrl_groups::CheckGroupModulePermissions($user['usergroupid'], $ids[$folder]);
    }

    /**
     * Run a COUNT(*) query for one account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $sql The query, with the account ID as :u.
     * @param int|string $uid The account ID.
     * @return int
     */
    static function Count($sql, $uid)
    {
        global $zdbh;
        $q = $zdbh->prepare($sql);
        $q->execute(array(':u' => (int) $uid));
        return (int) $q->fetchColumn();
    }

    /**
     * Draw one widget for the account.
     *
     * A built-in widget is drawn by its W<Id>() method, an uploaded one by its class
     * dashboard_widget_<id>::Render(); an uploaded widget that throws shows a short message
     * instead (and is logged).
     * @param string $id The widget ID.
     * @param array<string,string> $user The logged-in account (ctrl_users::GetUserDetail()).
     * @return string The widget's HTML ('' for an unknown widget).
     */
    static function Render($id, $user)
    {
        $f = 'W' . str_replace('_', '', ucwords($id, '_'));
        if (isset(self::BuiltIn()[$id])) {
            return method_exists(__CLASS__, $f) ? self::$f($user) : '';
        }
        $up = self::Uploaded();
        if (!isset($up[$id])) {
            return '';
        }
        // an uploaded widget: its class draws it; a widget that fails does not break the page
        $class = 'dashboard_widget_' . $id;
        $level = ob_get_level();
        try {
            ob_start();
            require_once $up[$id]['dir'] . 'widget.php';
            $html = (class_exists($class, false) && method_exists($class, 'Render')) ? (string) $class::Render($user) : '';
            $stray = ob_get_clean();
            return $html . $stray;
        } catch (Throwable $e) {
            while (ob_get_level() > $level) { ob_end_clean(); }
            error_log('Dashboard widget ' . $id . ' failed: ' . $e->getMessage());
            return '<p class="text-muted">' . self::t('This widget could not be shown.') . '</p>';
        }
    }

    /**
     * Return the stylesheets of uploaded widgets on this dashboard (widget.css).
     *
     * @param string[] $ids The widget IDs on the dashboard.
     * @return string <link> tags.
     */
    static function Styles(array $ids)
    {
        $up = self::Uploaded();
        $out = '';
        foreach ($ids as $id) {
            if (isset($up[$id]) && is_file($up[$id]['dir'] . 'widget.css')) {
                $out .= '<link rel="stylesheet" href="' . self::h(self::UPLOADED_DIR . $id . '/widget.css') . '">';
            }
        }
        return $out;
    }

    // --- the widgets ----------------------------------------------------------------------

    /**
     * Draw the "Getting started" widget: the steps to a running website, ticked off when done.
     *
     * @param array<string,string> $user The logged-in account (ctrl_users::GetUserDetail()).
     * @return string HTML.
     */
    static function WGettingStarted($user)
    {
        $uid = (int) $user['userid'];
        $steps = array(
            array('domains', 'Add your domain', 'The name of your website, for example example.com. It gets its own folder in your files.',
                self::Count("SELECT COUNT(*) FROM x_vhosts WHERE vh_acc_fk = :u AND vh_type_in = 1 AND vh_deleted_ts IS NULL", $uid) > 0),
            array('dns_manager', 'Check its DNS', 'Point your domain at this server: the DNS Manager shows the records, and the nameservers to set where you registered the domain.', null),
            array('sub_domains', 'Add subdomains', 'Extra names like shop.example.com or blog.example.com, each with its own folder.',
                self::Count("SELECT COUNT(*) FROM x_vhosts WHERE vh_acc_fk = :u AND vh_type_in = 2 AND vh_deleted_ts IS NULL", $uid) > 0),
            array('mailboxes', 'Create email addresses', 'Mailboxes like info@example.com; read them in webmail, SOGo or any mail app.',
                self::Count("SELECT COUNT(*) FROM x_mailboxes WHERE mb_acc_fk = :u AND mb_deleted_ts IS NULL", $uid) > 0),
            array('mysql_databases', 'Create a database', 'Most website software (WordPress, shops, forums) needs a MySQL database.',
                self::Count("SELECT COUNT(*) FROM x_mysql_databases WHERE my_acc_fk = :u AND my_deleted_ts IS NULL", $uid) > 0),
            array('mysql_users', 'Add a database user', 'The login your website uses for its database: give it access to the database you made.',
                self::Count("SELECT COUNT(*) FROM x_mysql_users WHERE mu_acc_fk = :u AND mu_deleted_ts IS NULL", $uid) > 0),
            array('ftp_management', 'Create an FTP account', 'To upload your website from your computer with an FTP program (FTPS is supported).',
                self::Count("SELECT COUNT(*) FROM x_ftpaccounts WHERE ft_acc_fk = :u AND ft_deleted_ts IS NULL", $uid) > 0),
            array('file_manager', 'Upload your website', 'Or upload and edit files right here in the browser with the File Manager.', null),
            array('sencrypt', 'Turn on HTTPS', 'A free Let\'s Encrypt certificate, so visitors see the padlock.', null),
            array('backupmgr', 'Make a backup', 'Of your websites and databases; you can also copy them to your own FTP server every day.',
                count(sys_backup::ListFiles($user['username'])) > 0),
        );
        $items = '';
        $done = 0;
        $shown = 0;
        foreach ($steps as $s) {
            if (!self::CanOpen($user, $s[0])) {
                continue;
            }
            $shown++;
            if ($s[3] === true) {
                $done++;
            }
            // steps that can be checked are numbered (or ticked); the others are tips
            $mark = ($s[3] === true) ? '<span class="dw-done" title="' . self::t('Done') . '">&#10003;</span>'
                : (($s[3] === null) ? '<span class="dw-tip" title="' . self::t('Tip') . '">i</span>' : '<span class="dw-todo">&bull;</span>');
            $items .= '<li class="' . ($s[3] === true ? 'is-done' : '') . '">' . $mark . '<div><a href="./?module=' . self::h($s[0]) . '"><strong>' . self::t($s[1]) . '</strong></a>'
                . '<br><span class="text-muted">' . self::t($s[2]) . '</span></div></li>';
        }
        $counted = 0;
        foreach ($steps as $s) {
            if ($s[3] !== null && self::CanOpen($user, $s[0])) {
                $counted++;
            }
        }
        $bar = $counted > 0 ? '<div class="dw-bar"><span style="width: ' . (int) round(100 * $done / $counted) . '%"></span></div>'
            . '<p class="text-muted" style="margin-top: 4px;">' . str_replace(array('%done%', '%total%'), array((string) $done, (string) $counted), self::t('%done% of %total% steps done')) . ' &middot; <span class="dw-tip" style="display:inline-block">i</span> ' . self::t('tips') . '</p>' : '';
        return $bar . '<ol class="dw-steps">' . $items . '</ol>';
    }

    /**
     * Draw the "Account usage" widget: usage against the package's quotas.
     *
     * @param array<string,string> $user The logged-in account (ctrl_users::GetUserDetail()).
     * @return string HTML table.
     */
    static function WUsage($user)
    {
        $rows = array(
            array('Disk space', ctrl_users::GetQuotaUsages('diskspace', $user['userid']), $user['diskquota'], true),
            array('Bandwidth this month', ctrl_users::GetQuotaUsages('bandwidth', $user['userid']), $user['bandwidthquota'], true),
            array('Domains', ctrl_users::GetQuotaUsages('domains', $user['userid']), $user['domainquota'], false),
            array('Subdomains', ctrl_users::GetQuotaUsages('subdomains', $user['userid']), $user['subdomainquota'], false),
            array('Mailboxes', ctrl_users::GetQuotaUsages('mailboxes', $user['userid']), $user['mailboxquota'], false),
            array('Databases', ctrl_users::GetQuotaUsages('mysql', $user['userid']), $user['mysqlquota'], false),
            array('FTP accounts', ctrl_users::GetQuotaUsages('ftpaccounts', $user['userid']), $user['ftpaccountsquota'], false),
        );
        $out = '<table class="dw-usage">';
        foreach ($rows as $r) {
            $used = (float) $r[1];
            $max = (float) $r[2];
            $fmt = function ($v) use ($r) { return $r[3] ? fs_director::ShowHumanFileSize($v) : (string) (int) $v; };
            $limit = ($max > 0) ? $fmt($max) : self::t('no limit');
            $pct = ($max > 0) ? min(100, (int) round(100 * $used / $max)) : 0;
            $cls = $pct >= 90 ? 'is-high' : ($pct >= 75 ? 'is-mid' : '');
            $out .= '<tr><th>' . self::t($r[0]) . '</th><td>' . self::h($fmt($used)) . ' / ' . self::h($limit)
                . ($max > 0 ? '<div class="dw-bar ' . $cls . '"><span style="width: ' . $pct . '%"></span></div>' : '') . '</td></tr>';
        }
        return $out . '</table>';
    }

    /**
     * Draw the "Your domains" widget: the account's domains and subdomains with quick links.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array<string,string> $user The logged-in account (ctrl_users::GetUserDetail()).
     * @return string HTML.
     */
    static function WDomains($user)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT vh_name_vc, vh_type_in FROM x_vhosts WHERE vh_acc_fk = :u AND vh_deleted_ts IS NULL ORDER BY vh_type_in, vh_name_vc LIMIT 25");
        $q->execute(array(':u' => (int) $user['userid']));
        $rows = $q->fetchAll();
        if (!$rows) {
            return '<p>' . self::t('You have no domains yet.') . (self::CanOpen($user, 'domains') ? ' <a href="./?module=domains">' . self::t('Add your first domain') . '</a>.' : '') . '</p>';
        }
        $types = array(1 => 'domain', 2 => 'subdomain', 3 => 'parked');
        $out = '<ul class="dw-list">';
        foreach ($rows as $r) {
            $out .= '<li><a href="http://' . self::h($r['vh_name_vc']) . '/" target="_blank" rel="noopener">' . self::h($r['vh_name_vc']) . '</a>'
                . ' <span class="label label-default">' . self::t($types[(int) $r['vh_type_in']] ?? 'domain') . '</span></li>';
        }
        $out .= '</ul>';
        foreach (array('domains' => 'Domains', 'sub_domains' => 'Subdomains', 'parked_domains' => 'Parked domains') as $m => $label) {
            if (self::CanOpen($user, $m)) {
                $out .= '<a class="btn btn-default btn-sm" href="./?module=' . $m . '">' . self::t($label) . '</a> ';
            }
        }
        return $out;
    }

    /**
     * Draw the "Security" widget: two-factor login and recovery codes.
     *
     * @param array<string,string> $user The logged-in account (ctrl_users::GetUserDetail()).
     * @return string HTML.
     */
    static function WSecurity($user)
    {
        $uid = (int) $user['userid'];
        $out = '<table class="dw-usage">';
        if (ctrl_twofactor::Enabled()) {
            $on = ctrl_twofactor::Required($uid);
            $out .= '<tr><th>' . self::t('Two-factor login') . '</th><td>' . ($on ? '<span class="label label-success">' . self::t('on') . '</span>'
                : '<span class="label label-warning">' . self::t('off') . '</span> <a href="./?module=my_account">' . self::t('Turn it on') . '</a>') . '</td></tr>';
            if ($on) {
                $left = ctrl_twofactor::RecoveryLeft($uid);
                $out .= '<tr><th>' . self::t('Recovery codes left') . '</th><td>' . $left . ($left < 3 ? ' <a href="./?module=my_account">' . self::t('Make new codes') . '</a>' : '') . '</td></tr>';
            }
        } else {
            $out .= '<tr><th>' . self::t('Two-factor login') . '</th><td class="text-muted">' . self::t('not offered on this server') . '</td></tr>';
        }
        $out .= '</table><p class="text-muted" style="margin-top: 8px;">' . self::t('Use a long password you use nowhere else, and keep your website software up to date.') . '</p>';
        return $out;
    }

    /**
     * Draw the "Backups" widget: the latest backup and the FTP backup.
     *
     * @param array<string,string> $user The logged-in account (ctrl_users::GetUserDetail()).
     * @return string HTML.
     */
    static function WSiteHealth($user)
    {
        if (!self::CanOpen($user, 'site_health')) {
            return '<p class="text-muted">' . self::t('Site Health is not available to your account.') . '</p>';
        }
        $sum = sys_sitehealth::Load((int) $user['userid']);
        $sites = array();
        foreach (sys_sitehealth::Domains((int) $user['userid']) as $dom) {
            $a = sys_sitehealth::Assess(isset($sum['domains'][$dom['name']]) ? $sum['domains'][$dom['name']] : array());
            $sites[] = array('name' => $dom['name'], 'level' => $a['level'], 'why' => $a['reasons'] ? $a['reasons'][0]['text'] : '');
        }
        if (!$sites) {
            return '<p class="text-muted">' . self::t('You have no websites yet.') . '</p>';
        }
        $rank = array('bad' => 0, 'warn' => 1, 'unknown' => 2, 'ok' => 3);
        usort($sites, function ($a, $b) use ($rank) { return ($rank[$a['level']] - $rank[$b['level']]) ?: strcmp($a['name'], $b['name']); });
        $n = array('ok' => 0, 'warn' => 0, 'bad' => 0, 'unknown' => 0);
        foreach ($sites as $x) {
            $n[$x['level']]++;
        }
        $out = '<p class="dw-sh-sum">' . ($n['bad'] + $n['warn'] === 0 && $n['unknown'] === 0
            ? '<span class="dw-sh-dot is-ok"></span>' . self::t('All your websites are working.')
            : '<span class="dw-sh-dot is-' . ($n['bad'] ? 'bad' : ($n['warn'] ? 'warn' : 'unknown')) . '"></span>'
                . str_replace(array('%1', '%2', '%3'), array((string) $n['bad'], (string) $n['warn'], (string) $n['ok']), self::t('%1 not working · %2 need attention · %3 working'))) . '</p>';
        $out .= '<ul class="dw-sh">';
        foreach (array_slice($sites, 0, 5) as $x) {
            $out .= '<li class="is-' . self::h($x['level']) . '"><a href="./?module=site_health&amp;domain=' . self::h(rawurlencode($x['name'])) . '">' . self::h($x['name']) . '</a>'
                . '<span>' . self::h($x['level'] === 'ok' ? sys_sitehealth::LevelText('ok') : $x['why']) . '</span></li>';
        }
        $out .= '</ul>';
        if (count($sites) > 5) {
            $out .= '<p class="text-muted dw-sh-more">' . str_replace('%1', (string) (count($sites) - 5), self::t('and %1 more')) . '</p>';
        }
        return $out . '<a class="btn btn-default btn-sm" href="./?module=site_health">' . self::t('Site Health') . '</a>';
    }

    static function WBackups($user)
    {
        $files = sys_backup::ListFiles($user['username']);
        $out = '<table class="dw-usage">';
        if ($files) {
            $name = key($files);
            $out .= '<tr><th>' . self::t('Latest backup') . '</th><td>' . self::h(date('Y-m-d H:i', $files[$name]['mtime'])) . ' (' . self::h(fs_director::ShowHumanFileSize($files[$name]['size'])) . ')</td></tr>';
        } else {
            $out .= '<tr><th>' . self::t('Latest backup') . '</th><td><span class="label label-warning">' . self::t('none yet') . '</span></td></tr>';
        }
        if (sys_clientftp::Allowed()) {
            $c = sys_clientftp::Get($user['userid']);
            $s = sys_clientftp::Status($user['userid']);
            $state = ((int) $c['bf_enabled_in'] === 1)
                ? (isset($s['last_ok']) ? self::t('last copy') . ' ' . self::h(date('Y-m-d H:i', (int) $s['last_ok'])) : self::t('on, no copy yet'))
                : self::t('off');
            $out .= '<tr><th>' . self::t('Copy to my FTP server') . '</th><td>' . $state . '</td></tr>';
        }
        $out .= '</table>';
        if (self::CanOpen($user, 'backupmgr')) {
            $out .= '<a class="btn btn-default btn-sm" href="./?module=backupmgr">' . self::t('Backups') . '</a>';
        }
        return $out;
    }

    /**
     * Draw the "Spam and viruses" widget: what filtering caught (administrators also see the whole server and the scanners).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array<string,string> $user The logged-in account (ctrl_users::GetUserDetail()).
     * @return string HTML.
     */
    static function WSpamav($user)
    {
        global $zdbh;
        if (!class_exists('sys_spamav') || !is_file(sys_platform::Get('spamd_bin')) && !is_file(sys_platform::Get('clamd_bin'))) {
            return '<p class="text-muted">' . self::t('Spam and virus filtering is not installed on this server.') . '</p>';
        }
        sys_spamav::Ensure();
        $admin = ((int) $user['usergroupid'] === 1);
        $acc = (int) $user['userid'];
        $sum = function ($days) use ($zdbh, $admin, $acc) {
            $q = $zdbh->prepare("SELECT COALESCE(SUM(dd_scanned_in),0), COALESCE(SUM(dd_spam_in),0), COALESCE(SUM(dd_virus_in),0), COALESCE(SUM(dd_rejected_in),0)
                FROM x_spamav_daily WHERE dd_day >= :d" . ($admin ? '' : ' AND dd_acc_fk = :a'));
            $args = array(':d' => date('Y-m-d', time() - ($days - 1) * 86400));
            if (!$admin) {
                $args[':a'] = $acc;
            }
            $q->execute($args);
            return $q->fetch(PDO::FETCH_NUM);
        };
        $out = '';
        if ($admin) {
            // the scanners, from the daemon's last check
            $st = @json_decode((string) @file_get_contents(sys_spamav::STATUS), true);
            $u = is_array($st) && isset($st['units']) ? $st['units'] : array();
            $line = function ($label, $on, $units) use ($u) {
                if (!$on) {
                    return '<tr><th>' . self::t($label) . '</th><td class="text-muted">' . self::t('off') . '</td></tr>';
                }
                $up = true;
                foreach ($units as $x) {
                    $up = $up && !empty($u[$x]);
                }
                return '<tr><th>' . self::t($label) . '</th><td>' . ($up ? ui_status::Ok('Running') . ' ' . self::t('running') : ui_status::Bad('Not running') . ' ' . self::t('not running')) . '</td></tr>';
            };
            $clam = sys_spamav::On('clam_enabled');
            $out .= '<table class="dw-usage">' . $line('SpamAssassin', sys_spamav::On('sa_enabled'), array('spamd', 'spamass-milter'));
            if ($clam && is_array($st) && empty($st['have_sigs'])) {
                $out .= '<tr><th>ClamAV</th><td>' . ui_status::Warn('Waiting') . ' ' . self::t('waiting for its first signatures') . '</td></tr>';
            } else {
                $out .= $line('ClamAV', $clam, array('clamav-daemon', 'clamav-milter'));
            }
            if (is_array($st) && isset($st['sigs']['daily'])) {
                $out .= '<tr><th>' . self::t('Signatures') . '</th><td>' . self::h($st['sigs']['daily']['version'] . ' (' . $st['sigs']['daily']['built'] . ')') . '</td></tr>';
            }
            $out .= '</table>';
        }
        $t = $sum(1);
        $w = $sum(7);
        $out .= '<table class="dw-usage" style="margin-top: 6px;"><tr><th></th><th>' . self::t('Spam') . '</th><th>' . self::t('Viruses') . '</th><th>' . self::t('Refused') . '</th></tr>'
            . '<tr><th>' . self::t('Today') . '</th><td>' . number_format((int) $t[1]) . '</td><td>' . number_format((int) $t[2]) . '</td><td>' . number_format((int) $t[3]) . '</td></tr>'
            . '<tr><th>' . self::t('Last 7 days') . '</th><td>' . number_format((int) $w[1]) . '</td><td>' . number_format((int) $w[2]) . '</td><td>' . number_format((int) $w[3]) . '</td></tr></table>'
            . '<p class="text-muted" style="margin: 4px 0 8px;">' . ($admin ? str_replace('%s', number_format((int) $w[0]), self::t('All mail on this server, %s checked in 7 days')) : self::t('For your mailboxes')) . '</p>';
        // the latest few events (the whole server for administrators, else the account's own)
        $q = $zdbh->prepare("SELECT * FROM x_spamav_events" . ($admin ? '' : ' WHERE ev_acc_fk = :a') . " ORDER BY ev_ts DESC, ev_id_pk DESC LIMIT 5");
        $q->execute($admin ? array() : array(':a' => $acc));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $out .= '<ul class="dw-list" style="list-style: none; padding-left: 0;">';
            foreach ($rows as $r) {
                $what = ($r['ev_kind_vc'] === 'virus') ? ui_status::Bad('Virus') . ' ' . self::h($r['ev_detail_vc']) : ui_status::Warn('Spam') . ' ' . self::t('Spam') . ($r['ev_score_vc'] !== '' ? ', ' . self::t('score') . ' ' . self::h($r['ev_score_vc']) : '');
                $out .= '<li style="margin-bottom: 4px;">' . $what . ' <span class="text-muted">' . str_replace('%s', self::h($r['ev_mailbox_vc']), self::t('to %s')) . ', ' . self::h(ui_language::Date('M j H:i', (int) $r['ev_ts'])) . '</span></li>';
            }
            $out .= '</ul>';
        } else {
            $out .= '<p class="text-muted">' . self::t('Nothing caught yet.') . '</p>';
        }
        $links = '';
        if (self::CanOpen($user, 'spamav')) {
            $links .= '<a class="btn btn-default btn-sm" href="./?module=spamav">' . self::t('SpamAV') . '</a> ';
        }
        if ($admin && self::CanOpen($user, 'spamav_admin')) {
            $links .= '<a class="btn btn-default btn-sm" href="./?module=spamav_admin">' . self::t('SpamAV Admin') . '</a>';
        }
        return $out . $links;
    }

    /**
     * Draw the "Server status" widget (administrators): services, disk, load and open alerts.
     *
     * @param array<string,string> $user The logged-in account (ctrl_users::GetUserDetail()).
     * @return string HTML, or '' for non-administrators.
     */
    static function WServer($user)
    {
        if ((int) $user['usergroupid'] !== 1) {
            return '';
        }
        $out = '<table class="dw-usage">';
        $total = @disk_total_space('/');
        $free = @disk_free_space('/');
        if ($total > 0) {
            $pct = (int) round(100 * ($total - $free) / $total);
            $out .= '<tr><th>' . self::t('Disk') . ' /</th><td>' . $pct . '% ' . self::t('used') . ', ' . self::h(fs_director::ShowHumanFileSize($free)) . ' ' . self::t('free')
                . '<div class="dw-bar ' . ($pct >= 90 ? 'is-high' : ($pct >= 75 ? 'is-mid' : '')) . '"><span style="width: ' . $pct . '%"></span></div></td></tr>';
        }
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        if ($load) {
            $out .= '<tr><th>' . self::t('Load') . '</th><td>' . self::h(sprintf('%.2f  %.2f  %.2f', $load[0], $load[1], $load[2])) . '</td></tr>';
        }
        $alerts = json_decode((string) @file_get_contents('/var/lib/sentora/alerts.json'), true);
        $open = is_array($alerts) ? count($alerts) : 0;
        $out .= '<tr><th>' . self::t('Open alerts') . '</th><td>' . ($open === 0 ? '<span class="label label-success">' . self::t('none') . '</span>' : '<span class="label label-danger">' . $open . '</span>') . '</td></tr>';
        if (is_array($alerts)) {
            foreach (array_slice($alerts, 0, 4) as $a) {
                $out .= '<tr><td colspan="2" class="text-danger">' . self::h($a['msg'] ?? '') . '</td></tr>';
            }
        }
        $out .= '</table>';
        foreach (array('server_monitor' => 'Server Monitor', 'services' => 'Service Status') as $m => $label) {
            if (self::CanOpen($user, $m)) {
                $out .= '<a class="btn btn-default btn-sm" href="./?module=' . $m . '">' . self::t($label) . '</a> ';
            }
        }
        return $out;
    }
}
