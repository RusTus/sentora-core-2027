<?php

/**
 * Matomo module: controller.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
require_once dirname(__FILE__) . '/matomohelper.php';

/**
 * Matomo: each account sees only its own domains. The panel opens Matomo with
 * the account's own view-only Matomo user (no second login).
 */
class module_controller extends ctrl_module
{

    /** @var string|false The visit source doSetSource() saved ('log' or 'js'), shown by getResult(); false when nothing was saved. */
    static $saved = false;

    /**
     * Escape a value for HTML.
     *
     * @param mixed $s The value (cast to string).
     * @return string
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Template flag: Matomo is installed.
     *
     * @return bool
     */
    static function getIsInstalled()
    {
        return matomo_helper::Installed();
    }

    /**
     * Template flag: Matomo is not installed.
     *
     * @return bool
     */
    static function getIsNotInstalled()
    {
        return !matomo_helper::Installed();
    }

    /**
     * Template flag: the logged-in account is in the Administrators group.
     *
     * @return bool
     */
    static function getIsAdmin()
    {
        $u = ctrl_users::GetUserDetail();
        return (int) $u['usergroupid'] === 1;
    }

    /**
     * Template value: the Matomo address, HTML-escaped.
     *
     * @return string
     */
    static function getMatomoUrl()
    {
        return self::h(matomo_helper::Url());
    }

    /**
     * Find one of the logged-in account's domains.
     *
     * @param mixed $domain The domain name (from the URL or form; compared as a string).
     * @return array<string, mixed>|false The row from matomo_helper::Domains() ('domain', 'idsite', 'source'), or false when it is not the account's.
     */
    static function MyDomain($domain)
    {
        $u = ctrl_users::GetUserDetail();
        foreach (matomo_helper::Domains($u['userid']) as $d) {
            if ($d['domain'] === (string) $domain) {
                return $d;
            }
        }
        return false;
    }

    /**
     * The domain whose reports are shown (?show=), when it is the account's and ready.
     *
     * @global runtime_controller $controller The request controller.
     * @return array<string, mixed>|false The row from matomo_helper::Domains(), or false.
     */
    static function Shown()
    {
        global $controller;
        $d = self::MyDomain($controller->GetControllerRequest('URL', 'show'));
        return ($d && $d['idsite'] > 0 && matomo_helper::Token(ctrl_users::GetUserDetail()['userid'])) ? $d : false;
    }

    /**
     * Template flag: reports of one domain are shown (<% if Showing %>).
     *
     * @return bool
     */
    static function getShowing()
    {
        return (bool) self::Shown();
    }

    /**
     * Template flag: the list of domains is shown (<% if Listing %>).
     *
     * @return bool
     */
    static function getListing()
    {
        return !self::Shown();
    }

    /**
     * The address of Matomo's reports for a site, opened with the account's view-only token.
     *
     * @param int $idsite The Matomo site ID.
     * @return string|false The address, or false without a token.
     */
    static function ReportUrl($idsite)
    {
        $token = matomo_helper::Token(ctrl_users::GetUserDetail()['userid']);
        return $token ? 'etc/apps/matomo/index.php?module=CoreHome&action=index&idSite=' . (int) $idsite . '&period=day&date=yesterday&token_auth=' . $token . '&force_api_session=1' : false;
    }

    /**
     * Template HTML: the figures at the top (<@ Summary @>): domains, ready, from the logs, from tracking code.
     *
     * @return string The HTML.
     */
    static function getSummary()
    {
        $all = matomo_helper::Domains(ctrl_users::GetUserDetail()['userid']);
        $ready = count(array_filter($all, function ($d) { return $d['idsite'] > 0; }));
        $js = count(array_filter($all, function ($d) { return $d['idsite'] > 0 && $d['source'] === 'js'; }));
        $tiles = array(
            array('Domains', count($all), '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>', ''),
            array('Ready', $ready, '<path d="M20 6 9 17l-5-5"/>', 'ok'),
            array('From the server logs', $ready - $js, '<path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/>', ''),
            array('From tracking code', $js, '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>', ''),
        );
        $out = '';
        foreach ($tiles as $t) {
            $out .= '<div class="mt-tile ' . $t[3] . '"><svg class="mt-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $t[2] . '</svg>'
                . '<div><b>' . (int) $t[1] . '</b><span>' . self::h(ui_language::translate($t[0])) . '</span></div></div>';
        }
        return $out;
    }

    /**
     * Template HTML: one row per domain of the logged-in account (<@ DomainRows @>), with its state, where its visits
     * come from (a two-way switch), Open reports, and its tracking code opening in place (open for ?code=).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getDomainRows()
    {
        global $controller;
        $u = ctrl_users::GetUserDetail();
        $open = (string) $controller->GetControllerRequest('URL', 'code');
        $t = function ($s) { return self::h(ui_language::translate($s)); };
        $globe = '<svg class="mt-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>';
        $rows = '';
        foreach (matomo_helper::Domains($u['userid']) as $d) {
            $dom = self::h($d['domain']);
            $ready = $d['idsite'] > 0;
            $js = $d['source'] === 'js';
            $isOpen = $ready && $d['domain'] === $open;
            $rows .= '<div class="mt-dom' . ($isOpen ? ' open' : '') . '" data-s="' . $dom . '"><div class="mt-dom-row">'
                . '<div class="mt-dom-name">' . $globe . '<b>' . $dom . '</b>'
                . ($ready ? '<span class="mt-id">' . str_replace('%d', (string) (int) $d['idsite'], $t('site %d')) . '</span>' : '<span class="mt-pill wait">' . $t('Being set up (within 5 minutes)') . '</span>') . '</div>';
            if ($ready) {
                $rows .= '<form class="mt-src" action="./?module=matomo&amp;action=SetSource" method="post">'
                    . '<input type="hidden" name="inDomain" value="' . $dom . '">' . runtime_csfr::Token()
                    . '<span class="mt-src-label">' . $t('Visits from') . '</span><span class="mt-seg" role="group" aria-label="' . $t('Visits from') . '">'
                    . '<button type="submit" name="inSource" value="log"' . (!$js ? ' class="active" aria-pressed="true" disabled' : ' aria-pressed="false"') . '>' . $t('Server logs') . '</button>'
                    . '<button type="submit" name="inSource" value="js"' . ($js ? ' class="active" aria-pressed="true" disabled' : ' aria-pressed="false"') . '>' . $t('Tracking code') . '</button></span></form>'
                    . '<div class="mt-act"><button type="button" class="btn btn-default btn-sm mt-code-btn" aria-expanded="' . ($isOpen ? 'true' : 'false') . '">'
                    . '<svg class="mt-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/></svg>' . $t('Tracking code') . '</button>'
                    . '<a class="btn btn-primary btn-sm" href="./?module=matomo&amp;show=' . self::h(rawurlencode($d['domain'])) . '"><svg class="mt-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>' . $t('Open reports') . '</a></div>';
            }
            $rows .= '</div>' . ($ready ? self::CodePanel($d) : '') . '</div>';
        }
        return $rows === '' ? '<p class="mt-empty">' . $t('You have no domains yet. Add one in Domains, and it is set up here within 5 minutes.') . '</p>' : $rows;
    }

    /**
     * The tracking code panel of one domain: the steps, Matomo's address and the site ID, and the code, each with Copy.
     *
     * @param array<string, mixed> $d The row from matomo_helper::Domains().
     * @return string The HTML.
     */
    static function CodePanel($d)
    {
        $t = function ($s) { return self::h(ui_language::translate($s)); };
        $copy = '<button type="button" class="btn btn-default btn-xs mt-copy" data-done="' . $t('Copied') . '">' . $t('Copy') . '</button>';
        return '<div class="mt-code">'
            . '<ol class="mt-steps"><li>' . $t('Paste the code just before </head> on every page. WordPress and most other site builders have a Matomo plugin that only needs the address and site ID.') . '</li>'
            . '<li>' . $t('Then switch "Visits from" to Tracking code, so visits are not counted twice.') . '</li></ol>'
            . '<div class="mt-kv"><span>' . $t('Matomo address') . '</span><code>' . self::h(matomo_helper::Url()) . '</code>' . $copy . '</div>'
            . '<div class="mt-kv"><span>' . $t('Site ID') . '</span><code>' . (int) $d['idsite'] . '</code>' . $copy . '</div>'
            . '<div class="mt-snippet"><div class="mt-snippet-head"><span>' . $t('Tracking code') . '</span>' . $copy . '</div><pre>' . self::h(matomo_helper::TrackingCode($d['idsite'])) . '</pre></div>'
            . '</div>';
    }

    /**
     * Template HTML: the bar above the reports (<@ ViewerBar @>): a searchable picker of the account's ready domains,
     * where the domain's visits come from, and All domains and New window.
     *
     * @return string The HTML ('' when no domain is shown).
     */
    static function getViewerBar()
    {
        $cur = self::Shown();
        if (!$cur) {
            return '';
        }
        $t = function ($s) { return self::h(ui_language::translate($s)); };
        $items = '';
        $options = '';
        foreach (matomo_helper::Domains(ctrl_users::GetUserDetail()['userid']) as $d) {
            if ($d['idsite'] <= 0) {
                continue;
            }
            $is = $d['domain'] === $cur['domain'];
            $items .= '<li><a class="mt-item' . ($is ? ' is-current' : '') . '" href="./?module=matomo&amp;show=' . self::h(rawurlencode($d['domain'])) . '" data-s="' . self::h($d['domain']) . '">'
                . '<span>' . self::h($d['domain']) . '</span><small>' . $t($d['source'] === 'js' ? 'tracking code' : 'server logs') . '</small></a></li>';
            $options .= '<option value="' . self::h($d['domain']) . '"' . ($is ? ' selected' : '') . '>' . self::h($d['domain']) . '</option>';
        }
        $url = self::ReportUrl($cur['idsite']);
        return '<div class="mt-pick">'
            . '<button type="button" class="mt-pick-btn" aria-haspopup="listbox" aria-expanded="false" hidden><svg class="mt-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>'
            . '<span class="mt-pick-label"><small>' . $t('Reports for') . '</small><b>' . self::h($cur['domain']) . '</b></span><span class="mt-caret" aria-hidden="true">&#9662;</span></button>'
            . '<div class="mt-pop" hidden><input type="search" class="form-control mt-find" placeholder="' . $t('Find a domain') . '" aria-label="' . $t('Find a domain') . '"><ul class="mt-list">' . $items . '</ul><p class="mt-none" hidden>' . $t('No domain matches.') . '</p></div>'
            . '<form method="get" action="./" class="mt-plain"><input type="hidden" name="module" value="matomo"><select name="show" class="form-control" onchange="this.form.submit()" aria-label="' . $t('Reports for') . '">' . $options . '</select></form>'
            . '</div>'
            . '<span class="mt-pill ' . ($cur['source'] === 'js' ? 'js' : 'log') . '">' . $t($cur['source'] === 'js' ? 'Visits from tracking code' : 'Visits from the server logs') . '</span>'
            . '<div class="mt-btns"><a class="btn btn-default btn-sm" href="./?module=matomo"><svg class="mt-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>' . $t('All domains') . '</a>'
            . '<a class="btn btn-default btn-sm" href="' . self::h($url) . '" target="_blank" rel="noopener"><svg class="mt-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg>' . $t('New window') . '</a></div>';
    }

    /**
     * Template HTML: the full Matomo interface for the domain in ?show=, in a frame.
     *
     * @return string HTML with the iframe, or '' when the domain is not ready or not the account's.
     */
    static function getFrame()
    {
        $d = self::Shown();
        if (!$d) {
            return '';
        }
        return '<iframe class="mt-frame sn-autoheight" data-sn-free="#secondNavBar" src="' . self::h(self::ReportUrl($d['idsite'])) . '" title="' . self::h('Matomo: ' . $d['domain']) . '"></iframe>'
            . '<script src="etc/lib/sentora/frame-height.js"></script>';
    }

    /**
     * Handle the POST that switches a domain between log import and tracking code.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doSetSource()
    {
        global $zdbh, $controller;
        runtime_csfr::Protect();
        $domain = (string) $controller->GetControllerRequest('FORM', 'inDomain');
        $source = (string) $controller->GetControllerRequest('FORM', 'inSource');
        $u = ctrl_users::GetUserDetail();
        if (in_array($source, array('log', 'js'), true) && self::MyDomain($domain)) {
            $sql = $zdbh->prepare("UPDATE x_matomo_sites SET ms_source_vc = :s WHERE ms_domain_vc = :d AND ms_acc_fk = :a");
            $sql->execute(array(':s' => $source, ':d' => $domain, ':a' => (int) $u['userid']));
            self::$saved = $source;
        }
    }

    /**
     * Template HTML: the "saved" message.
     *
     * @return string HTML message, or ''.
     */
    static function getResult()
    {
        if (self::$saved) {
            return ui_sysmessage::shout(ui_language::translate(self::$saved === 'js'
                ? 'Saved: visits now come from the tracking code on your pages. The hourly log import skips this domain from its next run.'
                : 'Saved: visits now come from the server logs, imported every hour from the next run.'), 'zannounceok');
        }
        return '';
    }

}

?>
