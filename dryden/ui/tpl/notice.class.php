<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Generic template place holder class.
 * @package zpanelx
 * @subpackage dryden -> ui -> tpl
 * @version 1.1.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
/**
 * Template tag <# ui_tpl_notice #>: the notices the logged-in account sees (see ctrl_notices).
 *
 * A notice is plain text: line breaks are kept, web addresses, [text](https://address) and [text](./?module=name) become
 * links, **text** is bold and lines starting with "- " are a list. Notices saved by the old HTML
 * editor (they start with a tag such as <p>) are read as text, their tags removed.
 */
class ui_tpl_notice {

    /**
     * The notices the account sees, one box each, most urgent first.
     *
     * Notices a client may close stay closed (a cookie per notice and version) until the notice changes;
     * those sent with 'closable' => 'login' only until the next login (the cookie names the login, see LoginKey()).
     *
     * @return string|false The HTML, or false when there are none.
     */
    public static function Template() {
        $user = ctrl_users::GetUserDetail();
        if (empty($user['userid'])) {
            return false;
        }
        $out = '';
        foreach (ctrl_notices::ForAccount((int) $user['userid']) as $n) {
            $out .= self::Box($n, (int) $user['userid']);
        }
        if ($out === '') {
            return false;
        }
        // hide the ones closed before (here, so they do not flash), and remember a close
        return '<div class="sn-notices" id="sn-notices">' . $out . '</div><script>(function(){var w=document.getElementById("sn-notices");if(!w){return;}'
            . 'Array.prototype.forEach.call(w.querySelectorAll(".sn-notice[data-key]"),function(n){var k="sn_notice_"+n.getAttribute("data-key");'
            . 'if(document.cookie.split("; ").indexOf(k+"=1")>=0){n.parentNode.removeChild(n);return;}'
            . 'var c=n.querySelector(".sn-notice-close");if(c){c.addEventListener("click",function(){document.cookie=k+"=1; path=/; "+(n.hasAttribute("data-login")?"":"max-age=31536000; ")+"SameSite=Lax";n.parentNode.removeChild(n);});}});'
            . 'if(!w.querySelector(".sn-notice")){w.parentNode.removeChild(w);}})();</script>';
    }

    /**
     * A short key for this login (made at each login by ctrl_auth::SetUserSession()), so a notice
     * closed "until the next login" shows again after the next one.
     *
     * @return string Letters and digits.
     */
    public static function LoginKey() {
        if (empty($_SESSION['sn_login']) || !preg_match('/^[a-f0-9]{8,32}$/', (string) $_SESSION['sn_login'])) {
            $_SESSION['sn_login'] = bin2hex(random_bytes(6));
        }
        return (string) $_SESSION['sn_login'];
    }

    /**
     * The name of a kind of notice.
     *
     * @param string $type 'outage', 'warning', 'maintenance' or 'info'.
     * @return string The translated name.
     */
    public static function TypeName($type) {
        $names = array('outage' => 'Outage', 'warning' => 'Warning', 'maintenance' => 'Maintenance', 'info' => 'Information');
        return ui_language::translate(isset($names[$type]) ? $names[$type] : 'Information');
    }

    /**
     * The icon of a kind of notice.
     *
     * @param string $type 'outage', 'warning', 'maintenance' or 'info'.
     * @param string $class Optional. The class of the SVG. Default 'sn-notice-ico'.
     * @return string The SVG.
     */
    public static function Icon($type, $class = 'sn-notice-ico') {
        $paths = array(
            'outage' => '<path d="M7.9 2h8.2L22 7.9v8.2L16.1 22H7.9L2 16.1V7.9z"/><path d="M12 8v4M12 16h.01"/>',
            'warning' => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
            'maintenance' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.4-.6-.6-2.4z"/>',
            'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        );
        return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . (isset($paths[$type]) ? $paths[$type] : $paths['info']) . '</svg>';
    }

    /**
     * One notice as a box: its icon, title (or the kind's name), who it is from, the text, and Close when it may be closed.
     *
     * @param array<string, mixed> $n The notice (see ctrl_notices::Row()).
     * @param int $uid Optional. The account looking (to say "Just for you"). Default 0.
     * @param bool $preview Optional. A preview: no key, so it can not be closed for real. Default false.
     * @return string The HTML.
     */
    public static function Box($n, $uid = 0, $preview = false) {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $type = in_array($n['type'], ctrl_notices::TYPES, true) ? $n['type'] : 'info';
        $from = $n['scope'] === 'user' ? 'Just for you' : ($n['scope'] === 'everyone' ? 'For everyone' : ((int) $n['from'] === 0 ? 'From the panel' : 'From your host'));
        $login = !$preview && !empty($n['reshow']);
        return '<div class="sn-notice sn-notice-' . $type . '"' . ($preview ? '' : ' data-key="' . (int) $n['id'] . '-' . (int) $n['updated'] . ($login ? '-' . self::LoginKey() . '" data-login="1' : '') . '"')
            . ' role="' . ($type === 'outage' ? 'alert' : 'status') . '">' . self::Icon($type)
            . '<div class="sn-notice-main"><div class="sn-notice-head"><b>' . $h($n['title'] !== '' ? $n['title'] : self::TypeName($type)) . '</b>'
            . '<small>' . $h(ui_language::translate($from)) . '</small></div>'
            . '<div class="sn-notice-body">' . self::Format($n['body']) . '</div></div>'
            . (!empty($n['closable']) ? '<button type="button" class="sn-notice-close" aria-label="' . $h(ui_language::translate('Close')) . '" title="' . $h(ui_language::translate('Close')) . '">&times;</button>' : '')
            . '</div>';
    }

    /**
     * A stored notice as plain text: tags from the old HTML editor removed, with their paragraphs and line breaks kept.
     *
     * @param string|null $notice The stored notice.
     * @return string The text, trimmed.
     */
    public static function Text($notice) {
        $s = str_replace(array("\r\n", "\r"), "\n", (string) $notice);
        // the old editor saved HTML starting with a block or text tag; anything else is text, kept as typed
        if (preg_match('#^\s*<(p|div|h[1-6]|ul|ol|table|blockquote|pre|span|strong|em|b|i|u|a|br)\b[^>]*>#i', $s)) {
            $s = preg_replace('#\s*<br\s*/?>\s*#i', "\n", $s);
            $s = preg_replace('#\s*</(li|tr)>\s*#i', "\n", $s);
            $s = preg_replace('#\s*</(p|div|ul|ol|table|h[1-6])>\s*#i', "\n\n", $s);
            $s = preg_replace('#<li[^>]*>#i', '- ', $s);
            $s = preg_replace('#<(strong|b)\b[^>]*>(.*?)</\1>#is', '**$2**', $s);
            $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $s = preg_replace("#\n{3,}#", "\n\n", str_replace("\xC2\xA0", ' ', $s));
        }
        return trim($s);
    }

    /**
     * A notice as safe HTML: escaped, with line breaks, lists, links and **bold**.
     *
     * The same rules run in the browser for the editor's preview (modules/client_notices/assets/notices.js).
     *
     * @param string|null $notice The stored notice.
     * @return string The HTML.
     */
    public static function Format($notice) {
        $keep = array();
        $hold = function ($html) use (&$keep) {
            $keep[] = $html;
            return "\x01" . (count($keep) - 1) . "\x02";
        };
        $s = htmlspecialchars(self::Text($notice), ENT_QUOTES, 'UTF-8');
        // [text](https://address)
        $s = preg_replace_callback('#\[([^\]\n]+)\]\((https?://[^\s)]+)\)#i', function ($m) use ($hold) {
            return $hold('<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>');
        }, $s);
        // [text](./?module=name): a page of the panel itself, opened in the same tab
        $s = preg_replace_callback('#\[([^\]\n]+)\]\((\./\?module=[a-z0-9_]+(?:&amp;[a-z0-9_]+=[A-Za-z0-9_]*)*)\)#', function ($m) use ($hold) {
            return $hold('<a href="' . $m[2] . '">' . $m[1] . '</a>');
        }, $s);
        // a bare address; the sentence's punctuation and quotes (escaped by now) stay outside the link
        $s = preg_replace_callback('#\bhttps?://[^\s<\x01]+#i', function ($m) use ($hold) {
            $url = $m[0];
            $tail = '';
            while (preg_match('#(&quot;|&\#039;|&gt;|[.,;:!?)])$#', $url, $p)) {
                $tail = $p[1] . $tail;
                $url = substr($url, 0, -strlen($p[1]));
            }
            return $hold('<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>') . $tail;
        }, $s);
        $s = preg_replace('#\*\*(\S(?:.*?\S)?)\*\*#', '<b>$1</b>', $s);
        // lines starting with "- " are a list; other lines keep their breaks
        $out = '';
        $list = '';
        $prev = false;
        foreach (explode("\n", $s) as $line) {
            if (preg_match('#^\s*- (.*)$#', $line, $m)) {
                $list .= '<li>' . $m[1] . '</li>';
                continue;
            }
            if ($list !== '') {
                $out .= '<ul>' . $list . '</ul>';
                $list = '';
                $prev = false;
            }
            $out .= ($prev ? '<br>' : '') . $line;
            $prev = true;
        }
        if ($list !== '') {
            $out .= '<ul>' . $list . '</ul>';
        }
        return preg_replace_callback("#\x01(\d+)\x02#", function ($m) use ($keep) { return $keep[(int) $m[1]]; }, $out);
    }

}

?>
