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
 * Template tag <# ui_tpl_notice #>: the reseller's notice to their clients.
 *
 * A notice is plain text: line breaks are kept, web addresses become links and **text** is bold.
 * Notices saved by the old HTML editor (they start with a tag such as <p>) are read as text, their tags removed.
 */
class ui_tpl_notice {

    /**
     * Returns the notice the user's reseller set (Client Notice Manager) as an alert box.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string|false The alert HTML from ui_sysmessage::shout(), or false when the reseller has no notice.
     */
    public static function Template() {
        $user_array = ctrl_users::GetUserDetail();
        global $zdbh;
        $q = $zdbh->prepare("SELECT ac_notice_tx FROM x_accounts WHERE ac_id_pk = :r");
        $q->execute(array(':r' => (int) $user_array['resellerid']));
        $result = $q->fetch();
        if ($result && self::Text($result['ac_notice_tx']) !== '') {
            return ui_sysmessage::shout(self::Format($result['ac_notice_tx']), 'notice', ui_language::translate('Notice:'), true);
        }
        return false;
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
     * A notice as safe HTML: escaped, with line breaks, links for web addresses and **bold**.
     *
     * @param string|null $notice The stored notice.
     * @return string The HTML.
     */
    public static function Format($notice) {
        $s = htmlspecialchars(self::Text($notice), ENT_QUOTES, 'UTF-8');
        $s = preg_replace('#\*\*(\S(?:.*?\S)?)\*\*#', '<b>$1</b>', $s);
        $s = preg_replace_callback('#\bhttps?://[^\s<]+#i', function ($m) {
            $url = $m[0];
            $tail = '';
            // leave the sentence's punctuation and quotes (escaped by now) outside the link
            while (preg_match('#(&quot;|&\#039;|&gt;|[.,;:!?)])$#', $url, $p)) {
                $tail = $p[1] . $tail;
                $url = substr($url, 0, -strlen($p[1]));
            }
            return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $tail;
        }, $s);
        return nl2br($s, false);
    }

}

?>
