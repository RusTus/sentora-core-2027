<?php

/**
 * PHP Versions module: controller.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-10
 */
/**
 * PHP Versions module (administrators): the PHP versions on the server (the default one Apache runs,
 * and the extra ones installed in /opt/sentora-php), which of them domains may choose, how many
 * domains use each, and how long each gets security fixes. The daemon starts and stops the PHP-FPM
 * services and rewrites the vhosts within 5 minutes of a change.
 */
class module_controller extends ctrl_module
{

    /** @var bool|null True after a save (shown by getResult()). */
    static $ok;

    /** @var array{0:string,1:string}|null A message about older logins: (HTML, class). */
    static $old;

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
     * How long a version gets security fixes, as a badge.
     *
     * @param string $version For example '8.3'.
     * @return string The HTML ('' when unknown).
     */
    static function Support($version)
    {
        $days = sys_phpversions::DaysLeft($version);
        if ($days === null) {
            return '';
        }
        $ends = sys_phpversions::$supportEnds[$version];
        if ($days < 0) {
            return '<span class="pv-sup bad">' . str_replace('%s', self::h($ends), self::t('No security fixes since %s')) . '</span>';
        }
        return '<span class="pv-sup' . ($days <= 180 ? ' warn' : ' ok') . '">' . str_replace('%s', self::h($ends), self::t('Security fixes until %s')) . ($days <= 180 ? ' ' . str_replace('%d', (string) (int) $days, self::t('(%d days)')) : '') . '</span>';
    }

    /**
     * How to add PHP 7.1 for moving old Sentora 2.0.2 websites (<@ LegacyHint @>), when it is not installed.
     *
     * @return string The HTML ('' when PHP 7.1 is installed).
     */
    static function getLegacyHint()
    {
        $have = sys_phpversions::Installed();
        $out = '';
        if (!in_array('7.1', $have, true)) {
            $out .= '<p class="pv-sub">' . self::t('To move websites from an old Sentora 2.0.2 server that do not run on PHP 8 yet, PHP 7.1 can be added (switched off; it gets no security fixes). As root on this server:')
                . ' <code>sentora-php-install 7.1</code></p>';
        }
        // the steps between: old apps are upgraded through versions that need PHP 7.4, then 8.1
        $steps = array_values(array_diff(array('7.4', '8.1'), $have));
        if ($steps) {
            $out .= '<p class="pv-sub">' . self::t('Old apps are upgraded in steps on their way to PHP 8 (such as osTicket 1.14, Snipe-IT 5 and Magento 2.3 on PHP 7.4, then Snipe-IT 6 and Magento 2.4 on PHP 8.1). These versions can be added for that (switched off; they get no security fixes). As root on this server:')
                . ' <code>' . implode('</code> <code>', array_map(function ($v) { return 'sentora-php-install ' . $v; }, $steps)) . '</code></p>';
        }
        return $out;
    }

    /**
     * The versions as cards (<@ VersionCards @>): the default, then each installed extra version with its switch.
     *
     * @return string The HTML.
     */
    static function getVersionCards()
    {
        $counts = sys_phpversions::UsageCounts();
        $enabled = sys_phpversions::Enabled();
        $default = sys_phpversions::DefaultVersion();
        $used = 0;
        foreach ($counts as $n) {
            $used += (int) $n;
        }
        $out = '<div class="pv-card is-default"><div class="pv-head"><span class="pv-ver">PHP ' . self::h($default) . '</span><span class="pv-tag">' . self::t('Default') . '</span></div>'
            . '<p>' . self::t('Built into Apache and used by the panel. Every domain that has not chosen another version runs on it.') . '</p>'
            . '<div class="pv-meta">' . self::Support($default) . '<span>' . self::t('version') . ' ' . self::h(PHP_VERSION) . '</span></div></div>';
        foreach (sys_phpversions::Installed() as $v) {
            $on = in_array($v, $enabled, true);
            $running = sys_phpversions::IsRunning($v);
            $n = isset($counts[$v]) ? (int) $counts[$v] : 0;
            $field = 'inEnabled_' . str_replace('.', '_', $v);
            // a version kept only to move old websites over: marked, explained, and switched on only after a question
            $legacy = sys_phpversions::IsMigrationOnly($v);
            $out .= '<div class="pv-card' . ($on ? '' : ' is-off') . ($legacy ? ' is-legacy' : '') . '"><div class="pv-head"><span class="pv-ver">PHP ' . self::h($v) . '</span>'
                . ($legacy ? '<span class="pv-tag pv-legacy">' . self::t('For moving old sites only') . '</span>' : '')
                . '<label class="sf-switch" title="' . self::t('Domains may choose it') . '"><input type="checkbox" name="' . $field . '" value="1"' . ($on ? ' checked' : '')
                . ($legacy ? ' data-confirm="' . ($v === '7.1' ? self::t('PHP 7.1 has had no security fixes since 2019. Offer it only while moving websites from an old Sentora 2.0.2 server, and move them to PHP 8 as soon as they run. Offer it now?')
                    : str_replace(array('%1$s', '%2$s'), array(self::h($v), self::h(sys_phpversions::$supportEnds[$v] ?? '')), self::t('PHP %1$s has had no security fixes since %2$s. Offer it only while a site needs it on its way to a supported PHP, and move the site on as soon as it runs. Offer it now?'))) . '"' : '')
                . ' aria-label="' . str_replace('%s', self::h($v), self::t('Domains may choose PHP %s')) . '"><span class="sf-toggle"></span></label></div>'
                . ($legacy ? '<p class="pv-legacy-note">' . ($v === '7.1' ? self::t('For websites moved from an old Sentora 2.0.2 server that do not run on PHP 8 yet. It gets no security fixes: switch it on for the move, then move each site to PHP 8 and switch it off again.')
                    : self::t('A step for websites whose apps are upgraded on their way to a supported PHP. It gets no security fixes: switch it on while a site needs it, then move the site on and switch it off again.')) . '</p>' : '')
                . '<p>' . self::t($on ? 'Domains may choose it.' : 'Not offered to domains.') . ' '
                . ($n ? ($n === 1 ? self::t('1 domain has chosen it') : str_replace('%d', (string) $n, self::t('%d domains have chosen it'))) . ($on ? '.' : ': ' . self::t($n === 1 ? 'it runs on the default until this is offered again.' : 'they run on the default until this is offered again.')) : self::t('No domain uses it.')) . '</p>'
                . '<div class="pv-meta">' . self::Support($v) . '<span class="pv-svc ' . ($running ? 'ok' : ($on ? 'bad' : '')) . '"><i></i>sentora-php' . self::h($v) . '-fpm ' . self::t($running ? 'running' : 'stopped') . '</span></div></div>';
        }
        return $out;
    }

    /**
     * Template flag <% if HasExtra %>: extra versions are installed.
     *
     * @return bool
     */
    static function getHasExtra()
    {
        return count(sys_phpversions::Installed()) > 0;
    }

    /**
     * Template flag <% if NoExtra %>.
     *
     * @return bool
     */
    static function getNoExtra()
    {
        return !self::getHasExtra();
    }

    /**
     * The old table rows (<@ VersionRows @>), kept for themes that use them.
     *
     * @return string The HTML.
     */
    static function getVersionRows()
    {
        $html = '';
        foreach (sys_phpversions::Installed() as $version) {
            $html .= '<tr><td><input type="checkbox" name="inEnabled_' . str_replace('.', '_', $version) . '" value="1"' . (sys_phpversions::IsEnabled($version) ? ' checked' : '') . '></td><td>PHP ' . self::h($version) . '</td></tr>';
        }
        return $html;
    }

    /**
     * Save which installed versions domains may choose, then let the daemon apply it.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return bool
     */
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
        $q = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = 'php_versions_enabled'");
        $q->execute(array(':value' => implode(',', $chosen)));
        // Rewrite the vhosts and start/stop PHP-FPM services at the next daemon run
        $zdbh->prepare("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc = 'apache_changed'")->execute();
        self::$ok = true;
        return true;
    }

    /**
     * The older logins kept for moved websites (<@ OldLogins @>): MySQL users on the older login
     * method (with a button to move each one to the modern one), whether MySQL still allows that
     * method (and a button to switch it off once nothing uses it), and mailboxes on MD5.
     *
     * @return string The HTML.
     */
    static function getOldLogins()
    {
        $tok = runtime_csfr::Token();
        $users = sys_oldlogins::NativeUsers();
        $active = sys_oldlogins::NativeActive();
        $md5 = sys_oldlogins::Md5Mailboxes();
        $state = sys_oldlogins::OffState();
        $rows = '';
        foreach ($users as $u) {
            $old = $u['mu_acc_fk'] ? sys_oldlogins::OldPhpDomains((int) $u['mu_acc_fk']) : array();
            $btn = $u['mu_id_pk']
                ? '<form method="post" action="./?module=php_versions&amp;action=ModernizeUser">' . $tok . '<input type="hidden" name="inUser" value="' . (int) $u['mu_id_pk'] . '">'
                    . '<button class="btn btn-default btn-sm" type="submit"' . ($old ? ' data-confirm="' . str_replace('%s', self::h(implode(', ', $old)), self::t('These domains of its account still run on PHP 7.1, which can not log in with the modern method: %s. Their sites will lose their database until they move to PHP 8. Move this user anyway?')) . '"' : '') . '>' . self::t('Use the modern login') . '</button></form>'
                : '<span class="pv-sub">' . self::t('not a panel user: change it in MySQL') . '</span>';
            $rows .= '<div class="pv-old-row"><div><b>' . self::h($u['user']) . '</b> <span class="pv-sub">@' . self::h($u['host']) . ($u['owner'] ? ' · ' . self::h($u['owner']) : '') . '</span>'
                . ($old ? '<div class="pv-old-why">' . str_replace('%s', self::h(implode(', ', $old)), self::t('Needed while these run on PHP 7.1: %s')) . '</div>' : '<div class="pv-old-why ok">' . self::t('No domain of its account needs it any more') . '</div>')
                . '</div>' . $btn . '</div>';
        }
        $parts = array();
        // MySQL's older login method
        if (sys_oldlogins::IsMysql()) {
            $offBtn = '';
            if ($active && !$users && $state !== 'requested') {
                $offBtn = '<form method="post" action="./?module=php_versions&amp;action=NativeOff">' . $tok . '<button class="btn btn-primary btn-sm" type="submit">' . self::t('Switch the older method off') . '</button></form>';
            }
            $status = $active
                ? ($state === 'requested' ? self::t('On: the daemon switches it off within 5 minutes (MySQL restarts once).') : ($users ? str_replace('%d', (string) count($users), self::t('On, for %d MySQL user(s) below. Once none of them needs it, switch it off here.')) : self::t('On, and no MySQL user uses it any more: switch it off.')))
                : self::t('Off: every MySQL user logs in with the modern method (caching_sha2_password).');
            if (strpos($state, 'failed') === 0) {
                $status .= ' <span class="pv-old-fail">' . self::h($state) . '</span>';
            }
            $parts[] = '<div class="pv-old-sec"><div class="pv-old-head"><b>' . self::t('MySQL: older login method') . '</b> <code>mysql_native_password</code>'
                . '<span class="pv-sup ' . ($active ? 'warn' : 'ok') . '">' . self::t($active ? 'On' : 'Off') . '</span></div>'
                . '<p>' . self::t('PHP 7.1 can not log in with MySQL\'s modern method, so the MySQL users of sites moved to PHP 7.1 use this older, weaker one (SHA-1). MySQL only listens on this server.') . '</p>'
                . '<p>' . $status . '</p>' . $offBtn . ($rows !== '' ? '<div class="pv-old-rows">' . $rows . '</div>' : '') . '</div>';
        }
        // mailboxes on MD5
        $parts[] = '<div class="pv-old-sec"><div class="pv-old-head"><b>' . self::t('Mailboxes: older password hashes') . '</b> <code>{PLAIN-MD5}</code>'
            . '<span class="pv-sup ' . ($md5 ? 'warn' : 'ok') . '">' . ($md5 ? str_replace('%d', (string) count($md5), self::t('%d mailbox(es)')) : self::t('None')) . '</span></div>'
            . '<p>' . ($md5
                ? self::t('These mailboxes came from an old server and still have an MD5 password hash, which is quick to crack. They move to SHA-512 crypt when their password is next changed (Mailboxes, or the webmail\'s Password setting):') . ' <span class="pv-old-list">' . self::h(implode(', ', $md5)) . '</span>'
                : self::t('Every mailbox password is a SHA-512 crypt hash.')) . '</p></div>';
        $clean = !$active && !$users && !$md5;
        return '<div class="pv-old' . ($clean ? ' is-clean' : '') . '"><h4>' . self::t('Older logins (for moved websites)') . '</h4>'
            . '<p class="pv-sub">' . self::t($clean ? 'None in use: nothing on this server logs in with an older, weaker method.' : 'Kept so websites moved from an old server keep working. Retire each one once its site runs on PHP 8.') . '</p>'
            . implode('', $parts) . '</div>';
    }

    /**
     * Move one MySQL user to the modern login method (from the Older logins card).
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doModernizeUser()
    {
        global $controller;
        runtime_csfr::Protect();
        $id = (int) $controller->GetControllerRequest('FORM', 'inUser');
        $why = sys_oldlogins::Modernize($id);
        if ($why === '') {
            self::$old = array(self::t('The MySQL user logs in with the modern method now, with the same password.'), 'zannounceok');
        } elseif (strpos($why, 'MySQL refused: ') === 0) {
            // (MySQL's own message as it is: only the sentence around it is translated)
            self::$old = array(str_replace('%s', self::h(substr($why, 15)), self::t('MySQL refused: %s')), 'zannounceerror');
        } else {
            self::$old = array(self::t($why), 'zannounceerror');
        }
    }

    /**
     * Ask the daemon to switch MySQL's older login method off (only when no user uses it).
     *
     * @return void
     */
    static function doNativeOff()
    {
        runtime_csfr::Protect();
        if (sys_oldlogins::NativeUsers()) {
            self::$old = array(self::t('Some MySQL users still use the older method: move them first.'), 'zannounceerror');
            return;
        }
        sys_oldlogins::RequestOff();
        self::$old = array(self::t('The daemon switches MySQL\'s older login method off within 5 minutes (MySQL restarts once).'), 'zannounceok');
    }

    /**
     * The updates of the extra versions (<@ BundleUpdates @>): each one's state from the daemon's daily
     * check, with an Update button where the release has a newer build (sys_phpbundles).
     *
     * @return string The HTML ('' when no extra version is installed).
     */
    static function getBundleUpdates()
    {
        $state = sys_phpbundles::State();
        if (!sys_phpversions::Installed()) {
            return '';
        }
        $rows = '';
        $asked = sys_phpbundles::Setting(sys_phpbundles::REQUEST);
        foreach ((array) $state['versions'] as $mm => $v) {
            if ($v['state'] === 'ready') {
                $what = !empty($v['rebuilt']) && $v['built'] === $v['installed']
                    ? '<span class="pv-sup warn">' . str_replace('%s', self::h($v['built']), self::t('A new build of %s is ready (the same version, built again)')) . '</span>'
                    : '<span class="pv-sup warn">' . str_replace(array('%1$s', '%2$s'), array(self::h($v['built']), self::h($v['installed'])), self::t('%1$s is ready (this server runs %2$s)')) . '</span>';
                $btn = ($asked === (string) $mm)
                    ? '<span class="pv-sub">' . self::t('Updating within 5 minutes') . '</span>'
                    : '<button class="btn btn-primary btn-sm" type="submit" name="inVersion" value="' . self::h($mm) . '" data-confirm="'
                        . str_replace('%s', self::h($v['built']), self::t('Install PHP %s now? The sites on this version pause for a few seconds; the old files come back if the new build does not start.')) . '">' . self::t('Update') . '</button>';
            } elseif ($v['state'] === 'waiting') {
                $what = '<span class="pv-sup warn">' . str_replace(array('%1$s', '%2$s'), array(self::h($v['latest']), self::h($v['installed'])), self::t('%1$s is out, its build is not ready yet (this server runs %2$s)')) . '</span>';
                $btn = '';
            } else {
                $what = '<span class="pv-sup ok">' . str_replace('%s', self::h($v['installed']), self::t('%s, the newest')) . '</span>';
                $btn = '';
            }
            $rows .= '<div class="pv-old-row"><span><b>PHP ' . self::h($mm) . '</b> ' . $what . '</span>' . $btn . '</div>';
        }
        $when = (int) $state['checked'] > 0 ? str_replace('%s', self::h(date('Y-m-d H:i', (int) $state['checked'])), self::t('Checked %s (once a day).')) : self::t('Not checked yet: the daemon checks within 5 minutes.');
        $res = json_decode(sys_phpbundles::Setting(sys_phpbundles::RESULT), true);
        $last = (is_array($res) && (int) $res['time'] > time() - 7 * 86400)
            ? '<p class="' . ($res['ok'] ? 'pv-old-why ok' : 'pv-old-fail') . '">' . str_replace(array('%1$s', '%2$s'), array(self::h($res['version']), self::h(date('Y-m-d H:i', (int) $res['time']))), self::t($res['ok'] ? 'PHP %1$s was updated on %2$s.' : 'The update of PHP %1$s on %2$s did not work:'))
                . ($res['ok'] ? '' : ' ' . self::h($res['text'])) . '</p>' : '';
        return '<form class="pv-old is-clean" action="./?module=php_versions&amp;action=UpdateBundle" method="post"><h4>' . self::t('Updates') . '</h4>'
            . '<p class="pv-sub">' . self::t('The extra versions are Sentora\'s own builds, not the system\'s packages: a new PHP release is offered here when its build is published.') . ' ' . $when . '</p>'
            . ($state['error'] !== '' ? '<p class="pv-old-fail">' . self::t($state['error']) . '</p>' : '')
            . $last . '<div class="pv-old-rows">' . $rows . '</div>' . runtime_csfr::Token() . '</form>';
    }

    /**
     * Ask the daemon to install the ready build of a version (the Update button).
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doUpdateBundle()
    {
        global $controller;
        runtime_csfr::Protect();
        $mm = (string) $controller->GetControllerRequest('FORM', 'inVersion');
        if (sys_phpbundles::Request($mm)) {
            self::$old = array(str_replace('%s', self::h($mm), self::t('The daemon installs the new PHP %s within 5 minutes.')), 'zannounceok');
        } else {
            self::$old = array(self::t('There is no update ready for that version.'), 'zannounceerror');
        }
    }

    /**
     * The message after a save (<@ Result @>).
     *
     * @return string|null
     */
    static function getResult()
    {
        if (self::$old) {
            return ui_sysmessage::shout(self::$old[0], self::$old[1]);
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate('Saved. The daemon starts or stops the PHP services and updates the websites within 5 minutes.'), 'zannounceok');
        }
        return null;
    }

}
