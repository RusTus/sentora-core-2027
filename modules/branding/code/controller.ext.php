<?php

/**
 * Branding: an account that can have clients (a reseller, or the administrator) gives the panel its
 * own look for them: a logo, a company name, a main colour and a welcome line on the login page and
 * in the panel's top bar, and (resellers) its own panel address, such as panel.theirhosting.com,
 * which the daemon sets up with a certificate. The administrator's brand is the main address's, and
 * every account's without a reseller brand above it. Templates: ready-made looks (ACME, the example)
 * and the account's own saved looks, applied with one click (ctrl_brandtemplates). The data and the
 * rules: ctrl_branding.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class module_controller extends ctrl_module
{
    /** @var array<int, array{0:string, 1:string}> Messages for this page: [type ('ok'|'error'), text]. */
    static $msg = array();

    /** @var array|null What was typed when saving failed (shown again). */
    static $typed = null;

    /**
     * Escape for HTML.
     *
     * @param mixed $s The text.
     * @return string
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translate and escape.
     *
     * @param string $s English.
     * @return string
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * An inline icon (stroke style, like the panel's).
     *
     * @param string $name The icon.
     * @return string
     */
    static function Icon($name)
    {
        $p = array(
            'palette' => '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2a10 10 0 0 0 0 20c.9 0 1.6-.7 1.6-1.6 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.6 1.6-1.6h1.9A5.6 5.6 0 0 0 22 11c0-5-4.5-9-10-9z"/>',
            'image' => '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>',
            'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18"/>',
            'contact' => '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1v-6h3zM3 19a2 2 0 0 0 2 2h1v-6H3z"/>',
            'list' => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
            'layers' => '<path d="m12 2 9 5-9 5-9-5 9-5z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
            'open' => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
        );
        return '<svg class="bd-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . (isset($p[$name]) ? $p[$name] : '') . '</svg>';
    }

    /**
     * The logged-in account's ID.
     *
     * @return int
     */
    static function Uid()
    {
        return (int) ctrl_users::GetUserDetail()['userid'];
    }

    /**
     * Is the logged-in account an administrator? (Its brand is the main address's: no address field.)
     *
     * @return bool
     */
    static function Admin()
    {
        return (int) ctrl_users::GetUserDetail()['usergroupid'] === 1;
    }

    /**
     * Save the look and the address.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doSave()
    {
        global $controller;
        runtime_csfr::Protect();
        $uid = self::Uid();
        if (!ctrl_branding::CanSet($uid)) {
            return;
        }
        $f = $controller->GetAllControllerRequests('FORM');
        $in = array('host' => (string) ($f['inHost'] ?? ''), 'name' => (string) ($f['inName'] ?? ''), 'colour' => (string) ($f['inColour'] ?? ''),
            'welcome' => (string) ($f['inWelcome'] ?? ''), 'variant' => (string) ($f['inVariant'] ?? ''));
        if (empty($f['inUseColour'])) {
            $in['colour'] = '';
        }
        $err = ctrl_branding::Save($uid, $in, !self::Admin());
        if ($err === '') {
            self::$msg[] = array('ok', ui_language::translate('Branding saved.'));
            return;
        }
        self::$typed = $in;
        $text = array(
            'name' => 'The company name can have up to 60 characters, without < or > or invisible characters.',
            'welcome' => 'The welcome line can have up to 160 characters, without < or > or invisible characters.',
            'colour' => 'Choose the main colour with the colour picker (#rrggbb).',
            'variant' => 'Choose Light, Dark, Auto or the panel default for the login page.',
        );
        if (strpos($err, 'host:') === 0) {
            self::$msg[] = array('error', ui_language::translate(substr($err, 5)));
        } else {
            self::$msg[] = array('error', ui_language::translate(isset($text[$err]) ? $text[$err] : 'Branding was not saved.'));
        }
    }

    /**
     * Take an uploaded logo (the main one, or the one for dark backgrounds).
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doLogo()
    {
        global $controller;
        runtime_csfr::Protect();
        $uid = self::Uid();
        if (!ctrl_branding::CanSet($uid)) {
            return;
        }
        $f = $controller->GetAllControllerRequests('FORM');
        $dark = !empty($f['inDark']);
        if ($dark && !ctrl_branding::Get($uid)['logo']) {
            self::$msg[] = array('error', ui_language::translate('Add your main logo first.'));
            return;
        }
        $file = isset($_FILES['inLogo']) ? $_FILES['inLogo'] : null;
        if (!$file || (int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $big = $file && in_array((int) $file['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true);
            self::$msg[] = array('error', ui_language::translate($big ? 'That picture is too large: at most 2 MB.' : 'Choose a picture (PNG, JPG or WebP) to upload.'));
            return;
        }
        $err = ctrl_branding::SetLogo($uid, $file['tmp_name'], $dark);
        @unlink($file['tmp_name']);
        $text = array(
            'big' => 'That picture is too large: at most 2 MB.',
            'type' => 'That is not a PNG, JPG or WebP picture.',
            'size' => 'That picture is too small or too large (at least 16 pixels each way, at most 4 million pixels, such as 2000 x 2000).',
            'gd' => 'This server can not read pictures (the PHP GD extension is missing).',
        );
        self::$msg[] = $err === '' ? array('ok', ui_language::translate('Logo saved.')) : array('error', ui_language::translate($text[$err]));
    }

    /**
     * Remove a logo.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doRemoveLogo()
    {
        global $controller;
        runtime_csfr::Protect();
        $uid = self::Uid();
        if (!ctrl_branding::CanSet($uid)) {
            return;
        }
        $f = $controller->GetAllControllerRequests('FORM');
        ctrl_branding::RemoveLogo($uid, !empty($f['inDark']));
        self::$msg[] = array('ok', ui_language::translate('Logo removed.'));
    }

    /**
     * Apply a template (built in or the account's own).
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doApplyTemplate()
    {
        global $controller;
        runtime_csfr::Protect();
        $uid = self::Uid();
        if (!ctrl_branding::CanSet($uid)) {
            return;
        }
        $f = $controller->GetAllControllerRequests('FORM');
        $err = ctrl_brandtemplates::Apply($uid, (string) ($f['inTemplate'] ?? ''));
        if ($err === '') {
            self::$msg[] = array('ok', ui_language::translate('Template applied. Your previous look is kept under Templates, if you want it back.'));
        } else {
            self::$msg[] = array('error', ui_language::translate($err === 'missing' ? 'That template is not there any more.' : 'That template could not be applied: its settings are not valid.'));
        }
    }

    /**
     * Save the current look as a template.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doSaveTemplate()
    {
        global $controller;
        runtime_csfr::Protect();
        $uid = self::Uid();
        if (!ctrl_branding::CanSet($uid)) {
            return;
        }
        $f = $controller->GetAllControllerRequests('FORM');
        $err = ctrl_brandtemplates::SaveCurrent($uid, (string) ($f['inTitle'] ?? ''));
        $text = array(
            '' => 'Your look is saved as a template.',
            'title' => 'Give the template a title: up to 40 characters, without < or > or invisible characters.',
            'full' => 'You have 10 templates already: delete one first.',
            'empty' => 'There is no look to save yet: set a name, colour, welcome line or logo first.',
        );
        self::$msg[] = array($err === '' ? 'ok' : 'error', ui_language::translate($text[$err]));
    }

    /**
     * Delete one of the account's own templates.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doDeleteTemplate()
    {
        global $controller;
        runtime_csfr::Protect();
        $uid = self::Uid();
        if (!ctrl_branding::CanSet($uid)) {
            return;
        }
        $f = $controller->GetAllControllerRequests('FORM');
        if (ctrl_brandtemplates::Delete($uid, (int) ($f['inTemplateId'] ?? 0))) {
            self::$msg[] = array('ok', ui_language::translate('Template deleted.'));
        }
    }

    /**
     * One template's tile: a small picture of the login page in its look, and its buttons.
     *
     * @param array $t The template (name, colour, welcome).
     * @param string $logo Its logo's address ('' for none).
     * @param string $title Its title.
     * @param string $about A line under the title.
     * @param string $which What Apply sends ('b:<id>' or 'u:<id>').
     * @param int $deleteId The own template's ID (0: no Delete button).
     * @param string $tok The form token.
     * @return string HTML.
     */
    static function TemplateTile(array $t, $logo, $title, $about, $which, $deleteId, $tok)
    {
        $colour = preg_match('/^#[0-9a-f]{6}$/', (string) $t['colour']) ? $t['colour'] : '#447fb3';
        $head = $logo !== '' ? '<img src="' . self::h($logo) . '" alt="">' : '<b>' . self::h($t['name'] !== '' ? $t['name'] : 'Sentora') . '</b>';
        $out = '<div class="bd-tpl">'
            . '<div class="bd-preview bd-tpl-prev" style="--bd-brand:' . self::h($colour) . ';--bd-brand-ink:' . self::h(ctrl_branding::InkOn($colour)) . '">'
            . '<div class="bd-p-head">' . $head . '</div>'
            . ($t['welcome'] !== '' ? '<p class="bd-p-welcome">' . self::h($t['welcome']) . '</p>' : '')
            . '<div class="bd-p-body"><i></i><span class="bd-p-btn">' . self::t('Log in') . '</span></div></div>'
            . '<b class="bd-tpl-title">' . self::h($title) . '</b><small class="bd-sub">' . self::h($about) . '</small>'
            . '<div class="bd-tpl-act"><form action="./?module=branding&amp;action=ApplyTemplate" method="post" onsubmit="return confirm(this.dataset.ask)" data-ask="'
            . self::t('Apply this template? It replaces your company name, colour, welcome line and logos (your panel address stays). Your current look is kept as Your previous look.') . '">'
            . '<input type="hidden" name="inTemplate" value="' . self::h($which) . '">' . $tok
            . '<button class="btn btn-primary btn-sm" type="submit">' . self::t('Apply') . '</button></form>';
        if ($deleteId > 0) {
            $out .= '<form action="./?module=branding&amp;action=DeleteTemplate" method="post" onsubmit="return confirm(this.dataset.ask)" data-ask="' . self::t('Delete this template?') . '">'
                . '<input type="hidden" name="inTemplateId" value="' . (int) $deleteId . '">' . $tok
                . '<button class="btn btn-link btn-sm bd-remove" type="submit">' . self::t('Delete') . '</button></form>';
        }
        return $out . '</div></div>';
    }

    /**
     * <@ TemplatesCard @>: the built-in templates, the account's own, and saving the current look.
     *
     * @return string HTML.
     */
    static function getTemplatesCard()
    {
        $uid = self::Uid();
        $tok = runtime_csfr::Token();
        // the previous look first, then the ready-made ones, then the account's own (newest first)
        $prev = '';
        $own = '';
        foreach (ctrl_brandtemplates::Own($uid) as $t) {
            $logo = $t['logo'] ? '/modules/branding/code/logo.php?t=' . $t['id'] : '';
            $when = $t['created'] ? ui_language::Date('j M Y, H:i', $t['created']) : '';
            if ($t['prev']) {
                $prev .= self::TemplateTile($t, $logo, ui_language::translate('Your previous look'), str_replace('%s', $when, ui_language::translate('As it was before the last template (%s).')), 'u:' . $t['id'], $t['id'], $tok);
            } else {
                $own .= self::TemplateTile($t, $logo, $t['title'], str_replace('%s', $when, ui_language::translate('Saved %s')), 'u:' . $t['id'], $t['id'], $tok);
            }
        }
        $ready = '';
        foreach (ctrl_brandtemplates::BuiltIn() as $t) {
            $ready .= self::TemplateTile($t, $t['logo'], $t['title'], ui_language::translate('Ready-made') . ' · ' . $t['about'], 'b:' . $t['id'], 0, $tok);
        }
        $tiles = $prev . $ready . $own;
        return '<div class="bd-card bd-templates"><h4>' . self::Icon('layers') . self::t('Templates') . '</h4>'
            . '<p class="bd-sub">' . self::t('Ready-made looks, and looks you saved: Apply changes your brand in one click (your panel address stays). Use one as a starting point, then adjust it below.') . '</p>'
            . '<div class="bd-tpls">' . $tiles . '</div>'
            . '<form class="bd-tpl-save" action="./?module=branding&amp;action=SaveTemplate" method="post">'
            . '<label class="bd-field"><span>' . self::t('Save your current look as a template') . '</span>'
            . '<input class="form-control" type="text" name="inTitle" maxlength="' . ctrl_brandtemplates::TITLE_MAX . '" placeholder="' . self::t('For example: Holiday look') . '" required></label>'
            . $tok . '<button class="btn btn-default" type="submit">' . self::t('Save as template') . '</button></form></div>';
    }

    /**
     * Template flag <% if CanBrand %>.
     *
     * @return bool
     */
    static function getCanBrand()
    {
        return ctrl_branding::CanSet(self::Uid());
    }

    /**
     * Template flag <% if NoBrand %>: the account can not brand the panel.
     *
     * @return bool
     */
    static function getNoBrand()
    {
        return !self::getCanBrand();
    }

    /**
     * <@ Result @>: this page's messages.
     *
     * @return string HTML.
     */
    static function getResult()
    {
        $out = '';
        foreach (self::$msg as $m) {
            $out .= '<div class="bd-msg ' . ($m[0] === 'ok' ? 'is-ok' : 'is-bad') . '" role="' . ($m[0] === 'ok' ? 'status' : 'alert') . '">' . self::h($m[1]) . '</div>';
        }
        return $out;
    }

    /**
     * The values shown in the form: what was typed when saving failed, else what is saved.
     *
     * @return array
     */
    static function Values()
    {
        $b = ctrl_branding::Get(self::Uid());
        if (self::$typed !== null) {
            foreach (self::$typed as $k => $v) {
                $b[$k] = $v;
            }
        }
        return $b;
    }

    /**
     * <@ LookCard @>: the name, colour, welcome line and Light/Dark/Auto, with a small picture of the
     * login page that follows what is typed.
     *
     * @return string HTML.
     */
    static function getLookCard()
    {
        $b = self::Values();
        $admin = self::Admin();
        $colour = preg_match('/^#[0-9a-f]{6}$/', $b['colour']) ? $b['colour'] : '';
        $variants = array('' => 'Panel default', 'light' => 'Light', 'dark' => 'Dark', 'auto' => 'Auto (follows the visitor\'s computer)');
        $opts = '';
        foreach ($variants as $v => $label) {
            $opts .= '<option value="' . self::h($v) . '"' . ($b['variant'] === $v ? ' selected' : '') . '>' . self::t($label) . '</option>';
        }
        $logo = $b['logo'] ? '<img src="' . self::h(ctrl_branding::LogoUrl($b)) . '" alt="">' : '';
        $out = '<form class="bd-card bd-look" action="./?module=branding&amp;action=Save" method="post" id="bdForm">'
            . '<h4>' . self::Icon('palette') . self::t('Look') . '</h4>'
            . '<div class="bd-look-grid"><div class="bd-fields">'
            . '<label class="bd-field"><span>' . self::t('Company name') . '</span><input class="form-control" type="text" name="inName" id="bdName" maxlength="' . ctrl_branding::NAME_MAX . '" value="' . self::h($b['name']) . '" placeholder="' . self::t('Your hosting company') . '">'
            . '<small>' . self::t('In the browser tab, the footer, and on the login page when there is no logo.') . '</small></label>'
            . '<div class="bd-field"><span>' . self::t('Main colour') . '</span><div class="bd-colour">'
            . '<label class="bd-check"><input type="checkbox" name="inUseColour" value="1" id="bdUseColour"' . ($colour !== '' ? ' checked' : '') . '> ' . self::t('Use my colour') . '</label>'
            . '<input type="color" name="inColour" id="bdColour" value="' . self::h($colour !== '' ? $colour : '#447fb3') . '" aria-label="' . self::t('Main colour') . '"></div>'
            . '<small>' . self::t('The login button and the line above the login box. The text on the button turns black or white, whichever reads better.') . '</small></div>'
            . '<label class="bd-field"><span>' . self::t('Welcome line (optional)') . '</span><input class="form-control" type="text" name="inWelcome" id="bdWelcome" maxlength="' . ctrl_branding::WELCOME_MAX . '" value="' . self::h($b['welcome']) . '" placeholder="' . self::t('Welcome! Log in to manage your websites and email.') . '"></label>'
            . '<label class="bd-field"><span>' . self::t('Login page') . '</span><select class="form-control" name="inVariant">' . $opts . '</select></label>'
            . ($admin ? '' : self::AddressFields($b))
            . '</div>'
            . '<div class="bd-preview-wrap"><span class="bd-sub">' . self::t('Preview of the login page') . '</span>'
            . '<div class="bd-preview" id="bdPreview" style="' . ($colour !== '' ? '--bd-brand:' . self::h($colour) . ';--bd-brand-ink:' . self::h(ctrl_branding::InkOn($colour)) : '') . '">'
            . '<div class="bd-p-head">' . ($logo !== '' ? $logo : '<b id="bdPName">' . self::h($b['name'] !== '' ? $b['name'] : 'Sentora') . '</b>') . '</div>'
            . '<p class="bd-p-welcome" id="bdPWelcome"' . ($b['welcome'] === '' ? ' hidden' : '') . '>' . self::h($b['welcome']) . '</p>'
            . '<div class="bd-p-body"><i></i><i></i><span class="bd-p-btn">' . self::t('Log in') . '</span></div></div>'
            . '<small class="bd-sub">Powered by Sentora</small></div></div>'
            . '<div class="bd-foot">' . runtime_csfr::Token() . '<button class="btn btn-primary" type="submit">' . self::t('Save') . '</button></div>'
            . '</form>'
            . '<script>(function(){var f=document.getElementById("bdForm");if(!f){return;}'
            . 'var p=document.getElementById("bdPreview"),c=document.getElementById("bdColour"),u=document.getElementById("bdUseColour"),'
            . 'n=document.getElementById("bdName"),w=document.getElementById("bdWelcome"),pn=document.getElementById("bdPName"),pw=document.getElementById("bdPWelcome");'
            . 'function ink(h){var l=[1,3,5].map(function(i){var v=parseInt(h.substr(i,2),16)/255;return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4);});'
            . 'var L=0.2126*l[0]+0.7152*l[1]+0.0722*l[2];return 1.05/(L+0.05)>=(L+0.05)/0.0555?"#ffffff":"#111111";}'
            . 'function draw(){if(u.checked){p.style.setProperty("--bd-brand",c.value);p.style.setProperty("--bd-brand-ink",ink(c.value));}else{p.style.removeProperty("--bd-brand");p.style.removeProperty("--bd-brand-ink");}'
            . 'c.disabled=!u.checked;if(pn){pn.textContent=n.value.trim()||"Sentora";}pw.textContent=w.value.trim();pw.hidden=!w.value.trim();}'
            . 'c.addEventListener("input",function(){u.checked=true;draw();});[u,n,w].forEach(function(e){e.addEventListener("input",draw);e.addEventListener("change",draw);});draw();'
            . 'f.addEventListener("submit",function(){c.disabled=false;});})();</script>';
        return $out;
    }

    /**
     * The panel address field and its state (resellers).
     *
     * @param array $b The brand values.
     * @return string HTML.
     */
    static function AddressFields(array $b)
    {
        $saved = ctrl_branding::Get(self::Uid());
        $ip = trim((string) ctrl_options::GetSystemOption('server_ip'));
        $state = '';
        if ($saved['host'] !== '') {
            $map = array(
                'new' => array('wait', 'Being set up'),
                'wait' => array('wait', 'Getting its certificate'),
                'dns' => array('warn', 'Waiting for DNS'),
                'live' => array('ok', 'Live'),
                'error' => array('bad', 'Problem'),
            );
            $s = isset($map[$saved['state']]) ? $map[$saved['state']] : $map['new'];
            $state = '<div class="bd-state"><span class="bd-pill is-' . $s[0] . '">' . self::t($s[1]) . '</span>';
            if ($saved['state'] === 'live') {
                $state .= ' <a class="btn btn-default btn-sm" href="https://' . self::h($saved['host']) . '/" target="_blank" rel="noopener">' . self::Icon('open') . self::t('Open') . '</a>';
            }
            $state .= '</div>';
            if ($saved['state'] === 'dns' || $saved['state'] === 'new') {
                $state .= '<p class="bd-help">' . str_replace(array('%1', '%2'), array('<code>' . self::h($saved['host']) . '</code>', '<code>' . self::h($ip !== '' ? $ip : ui_language::translate('this server\'s address')) . '</code>'),
                    self::t('In your domain\'s DNS, add an A record for %1 pointing to %2. The panel checks every 5 minutes, gets the certificate by itself, and shows Live when it works.')) . '</p>';
            } elseif ($saved['state'] === 'wait') {
                $state .= '<p class="bd-help">' . self::t('The address is being added to the web server and its certificate requested: usually within 10 minutes.') . '</p>';
            }
            if ($saved['note'] !== '') {
                $next = $saved['next'] > time() ? ' ' . str_replace('%s', ui_language::Date('H:i', $saved['next']), ui_language::translate('Next try at %s.')) : '';
                $state .= '<p class="bd-help ' . ($saved['state'] === 'error' ? 'is-bad' : 'is-warn') . '">' . self::h(ui_language::translate($saved['note']) . $next) . '</p>';
            }
        }
        return '<label class="bd-field"><span>' . self::t('Your panel address (optional)') . '</span>'
            . '<input class="form-control" type="text" name="inHost" maxlength="253" autocapitalize="off" spellcheck="false" value="' . self::h($b['host']) . '" placeholder="panel.yourdomain.com">'
            . '<small>' . self::t('Where your clients log in, with your brand. It must be under one of your own domains. Leave it empty to use the main panel address.') . '</small></label>'
            . $state;
    }

    /**
     * <@ LogoCard @>: the logos, with upload and remove.
     *
     * @return string HTML.
     */
    static function getLogoCard()
    {
        $b = ctrl_branding::Get(self::Uid());
        $tok = runtime_csfr::Token();
        $one = function ($dark) use ($b, $tok) {
            $has = $dark ? $b['logodark'] : $b['logo'];
            $title = $dark ? self::t('Logo for dark backgrounds (optional)') : self::t('Logo');
            $help = $dark ? self::t('A light version, for dark pages. Without one, your logo sits on a white tile there.')
                : self::t('PNG, JPG or WebP, up to 2 MB. A wide logo with a transparent background looks best; it is shown up to 240 x 80 pixels on the login page and 36 pixels high in the top bar.');
            $img = $has ? '<img src="' . self::h(ctrl_branding::LogoUrl($b, $dark)) . '" alt="">' : '<span>' . self::t('None') . '</span>';
            $out = '<div class="bd-logo-row"><div class="bd-logo-box' . ($dark ? ' is-dark' : '') . '">' . $img . '</div><div class="bd-logo-side">'
                . '<b>' . $title . '</b><small>' . $help . '</small>';
            if (!$dark || $b['logo']) {
                $out .= '<form class="bd-upload" action="./?module=branding&amp;action=Logo" method="post" enctype="multipart/form-data">'
                    . '<input type="file" name="inLogo" accept="image/png,image/jpeg,image/webp" required>'
                    . ($dark ? '<input type="hidden" name="inDark" value="1">' : '') . $tok
                    . '<button class="btn btn-default btn-sm" type="submit">' . self::t($has ? 'Replace' : 'Upload') . '</button></form>';
            }
            if ($has) {
                $out .= '<form action="./?module=branding&amp;action=RemoveLogo" method="post">' . ($dark ? '<input type="hidden" name="inDark" value="1">' : '') . $tok
                    . '<button class="btn btn-link btn-sm bd-remove" type="submit">' . self::t('Remove') . '</button></form>';
            }
            return $out . '</div></div>';
        };
        return '<div class="bd-card"><h4>' . self::Icon('image') . self::t('Logo') . '</h4>' . $one(false) . $one(true) . '</div>';
    }

    /**
     * <@ ContactCard @>: the support contact the login page shows (set on My Account).
     *
     * @return string HTML.
     */
    static function getContactCard()
    {
        $sc = ctrl_support::Get(self::Uid());
        $rows = '';
        foreach (array('email' => 'Email', 'phone' => 'Phone', 'website' => 'Website') as $k => $label) {
            $v = (string) ($sc[$k] ?? '');
            $rows .= '<dt>' . self::t($label) . '</dt><dd>' . ($v !== '' ? self::h($v) : '<span class="bd-sub">' . self::t('not set') . '</span>') . '</dd>';
        }
        return '<div class="bd-card"><h4>' . self::Icon('contact') . self::t('Contact on the login page') . '</h4>'
            . '<p class="bd-sub">' . self::t('Your support contact shows under the login box, so clients know who to ask. It is the same one your clients see on My Account.') . '</p>'
            . '<dl class="bd-dl">' . $rows . '</dl>'
            . '<a class="btn btn-default btn-sm" href="./?module=my_account">' . self::t('Change it on My Account') . '</a></div>';
    }

    /**
     * <@ WhereCard @>: where this brand shows.
     *
     * @return string HTML.
     */
    static function getWhereCard()
    {
        $main = (string) ctrl_options::GetSystemOption('sentora_domain');
        if (self::Admin()) {
            $text = str_replace('%s', '<code>' . self::h($main) . '</code>', self::t('Your brand shows on the login page at %s, and in the panel for every account that has no reseller brand above it. Resellers set their own on this page.'));
        } else {
            $text = self::t('Your brand shows to your clients in the panel (top bar, browser tab, footer), and on the login page at your panel address. Clients without a brand of their own see yours; leave everything empty to show the standard look.');
        }
        return '<p class="bd-intro">' . $text . '</p>';
    }

    /**
     * <@ AddressList @>: every reseller's panel address and its state (administrators).
     *
     * @return string HTML.
     */
    static function getAddressList()
    {
        if (!self::Admin()) {
            return '';
        }
        $rows = '';
        $names = array('new' => 'Being set up', 'wait' => 'Getting its certificate', 'dns' => 'Waiting for DNS', 'live' => 'Live', 'error' => 'Problem');
        $cls = array('new' => 'wait', 'wait' => 'wait', 'dns' => 'warn', 'live' => 'ok', 'error' => 'bad');
        foreach (ctrl_branding::All() as $r) {
            $st = isset($names[$r['state']]) ? $r['state'] : 'new';
            $rows .= '<tr><td>' . self::h($r['user']) . '</td><td><code>' . self::h($r['host']) . '</code></td><td><span class="bd-pill is-' . $cls[$st] . '">' . self::t($names[$st]) . '</span></td>'
                . '<td class="bd-sub">' . self::h($r['note'] !== '' ? ui_language::translate($r['note']) : '') . '</td></tr>';
        }
        if ($rows === '') {
            return '';
        }
        return '<div class="bd-card"><h4>' . self::Icon('list') . self::t('Resellers\' panel addresses') . '</h4><div class="bd-scroll"><table class="bd-table"><thead><tr><th>'
            . self::t('Account') . '</th><th>' . self::t('Address') . '</th><th>' . self::t('State') . '</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
    }

    /**
     * Nothing to set up before the page.
     *
     * @return void
     */
    static function getInit()
    {
    }
}
