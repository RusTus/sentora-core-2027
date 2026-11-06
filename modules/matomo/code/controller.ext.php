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

    /** @var bool True after doSetSource() saved a domain's visit source (shown by getResult()). */
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
     * Template HTML: one table row per domain of the logged-in account, with its buttons.
     *
     * @return string HTML table rows.
     */
    static function getDomainRows()
    {
        $u = ctrl_users::GetUserDetail();
        $rows = '';
        foreach (matomo_helper::Domains($u['userid']) as $d) {
            $dom = self::h($d['domain']);
            $ready = $d['idsite'] > 0;
            $rows .= '<tr><td><strong>' . $dom . '</strong></td><td>';
            $rows .= ($d['source'] === 'js') ? ui_language::translate('Tracking code on your pages') : ui_language::translate('Web server logs (every hour)');
            $rows .= '</td><td nowrap="nowrap">';
            if ($ready) {
                $rows .= '<a class="btn btn-primary btn-sm" href="./?module=matomo&amp;show=' . rawurlencode($d['domain']) . '">' . ui_language::translate('Open') . '</a> ';
                $other = ($d['source'] === 'js') ? 'log' : 'js';
                $label = ($other === 'js') ? ui_language::translate('Use tracking code') : ui_language::translate('Use server logs');
                $rows .= '<form action="./?module=matomo&amp;action=SetSource" method="post" style="display:inline">'
                    . '<input type="hidden" name="inDomain" value="' . $dom . '"><input type="hidden" name="inSource" value="' . $other . '">'
                    . runtime_csfr::Token() . '<button class="btn btn-default btn-sm" type="submit">' . $label . '</button></form> ';
                $rows .= '<a class="btn btn-default btn-sm" href="./?module=matomo&amp;code=' . rawurlencode($d['domain']) . '">' . ui_language::translate('Tracking code') . '</a>';
            } else {
                $rows .= '<em>' . ui_language::translate('Being set up (within 5 minutes)') . '</em>';
            }
            $rows .= '</td></tr>';
        }
        return $rows === '' ? '<tr><td colspan="3">' . ui_language::translate('You have no domains yet.') . '</td></tr>' : $rows;
    }

    /**
     * Template HTML: the full Matomo interface for one of this account's domains (?show=).
     *
     * @global runtime_controller $controller The request controller.
     * @return string HTML with the iframe, or '' when the domain is not ready or not the account's.
     */
    static function getFrame()
    {
        global $controller;
        $d = self::MyDomain($controller->GetControllerRequest('URL', 'show'));
        $u = ctrl_users::GetUserDetail();
        $token = matomo_helper::Token($u['userid']);
        if (!$d || $d['idsite'] <= 0 || !$token) {
            return '';
        }
        $src = 'etc/apps/matomo/index.php?module=CoreHome&action=index&idSite=' . (int) $d['idsite'] . '&period=day&date=yesterday&token_auth=' . $token . '&force_api_session=1';
        return '<h3>' . self::h($d['domain']) . '</h3><p><a href="' . self::h($src) . '" target="_blank" rel="noopener">' . ui_language::translate('Open in a new window') . '</a></p>'
            . '<iframe class="sn-autoheight" data-sn-free="#secondNavBar" src="' . self::h($src) . '" title="Matomo" style="width: 100%; height: 900px; border: 1px solid var(--sn-line, #ddd);"></iframe>'
            . '<script src="etc/lib/sentora/frame-height.js"></script>';
    }

    /**
     * Template HTML: the tracking code for one of this account's domains (?code=).
     *
     * @global runtime_controller $controller The request controller.
     * @return string HTML, or '' when the domain is not ready or not the account's.
     */
    static function getCode()
    {
        global $controller;
        $d = self::MyDomain($controller->GetControllerRequest('URL', 'code'));
        if (!$d || $d['idsite'] <= 0) {
            return '';
        }
        return '<h3>' . ui_language::translate('Tracking code for') . ' ' . self::h($d['domain']) . '</h3>'
            . '<p>' . ui_language::translate('Paste this just before </head> on every page (WordPress and most other site builders have a Matomo plugin that only needs the address and site ID below), then switch the domain to "Use tracking code" so visits are not counted twice.') . '</p>'
            . '<p>' . ui_language::translate('Matomo address') . ': <code>' . self::h(matomo_helper::Url()) . '</code> &nbsp; ' . ui_language::translate('Site ID') . ': <code>' . (int) $d['idsite'] . '</code></p>'
            . '<pre style="white-space: pre-wrap">' . self::h(matomo_helper::TrackingCode($d['idsite'])) . '</pre>';
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
            self::$saved = true;
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
            return ui_sysmessage::shout(ui_language::translate('Saved. Log import for this domain follows the new setting from the next hourly run.'), 'zannounceok');
        }
        return '';
    }

}

?>
