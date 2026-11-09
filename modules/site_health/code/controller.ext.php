<?php

/**
 * Site Health: whether each of the account's websites works, and what needs attention, from the
 * hourly check (sys_sitehealth). The overview: a card per site; ?domain=<name>: its details (the
 * last day of checks, PHP errors, error pages, the certificate).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class module_controller extends ctrl_module
{

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
     * @param string $class Its class.
     * @return string
     */
    static function Icon($name, $class = 'sh-ico')
    {
        $p = array(
            'pulse' => '<path d="M3 12h4l3 8 4-16 3 8h4"/>',
            'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18"/>',
            'lock' => '<rect width="16" height="10" x="4" y="11" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
            'unlock' => '<rect width="16" height="10" x="4" y="11" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.8-1.2"/>',
            'php' => '<path d="m8 9-4 3 4 3"/><path d="m16 9 4 3-4 3"/><path d="m14 5-4 14"/>',
            'bug' => '<rect width="8" height="12" x="8" y="7" rx="4"/><path d="M12 7V4"/><path d="M4 13h4"/><path d="M16 13h4"/><path d="m5 8 3 2"/><path d="m19 8-3 2"/><path d="m5 19 3-2"/><path d="m19 19-3-2"/>',
            'chart' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M7 15v2"/><path d="M11 11v6"/><path d="M15 8v9"/><path d="M19 5v12"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
            'back' => '<path d="m15 18-6-6 6-6"/>',
            'right' => '<path d="m9 18 6-6-6-6"/>',
        );
        return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . (isset($p[$name]) ? $p[$name] : '') . '</svg>';
    }

    /**
     * The account's sites with their summaries and verdicts, worst first.
     *
     * @return array{time: int, sites: array<int, array>}
     */
    static function Sites()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $u = ctrl_users::GetUserDetail();
        $sum = sys_sitehealth::Load((int) $u['userid']);
        $sites = array();
        foreach (sys_sitehealth::Domains((int) $u['userid']) as $dom) {
            $d = isset($sum['domains'][$dom['name']]) ? $sum['domains'][$dom['name']] : array();
            $a = sys_sitehealth::Assess($d);
            $sites[] = array('name' => $dom['name'], 'sub' => $dom['sub'], 'd' => $d, 'level' => $a['level'], 'reasons' => $a['reasons']);
        }
        $rank = array('bad' => 0, 'warn' => 1, 'unknown' => 2, 'ok' => 3);
        usort($sites, function ($a, $b) use ($rank) { return ($rank[$a['level']] - $rank[$b['level']]) ?: strcmp($a['name'], $b['name']); });
        return $cache = array('time' => $sum['time'], 'sites' => $sites);
    }

    /**
     * The site asked for in ?domain= (one of the account's own), or null.
     *
     * @return array|null
     */
    static function Current()
    {
        global $controller;
        $want = strtolower((string) $controller->GetControllerRequest('URL', 'domain'));
        if ($want === '') {
            return null;
        }
        foreach (self::Sites()['sites'] as $s) {
            if (strtolower($s['name']) === $want) {
                return $s;
            }
        }
        return null;
    }

    /**
     * The overview is shown (template <% if Overview %>).
     *
     * @return bool
     */
    static function getOverview()
    {
        return self::Current() === null;
    }

    /**
     * One site's details are shown (template <% if Details %>).
     *
     * @return bool
     */
    static function getDetails()
    {
        return self::Current() !== null;
    }

    /**
     * A status label.
     *
     * @param string $level The level.
     * @return string HTML.
     */
    static function Pill($level)
    {
        return '<span class="sh-pill is-' . self::h($level) . '">' . self::h(sys_sitehealth::LevelText($level)) . '</span>';
    }

    /**
     * The reasons as a list, each with a link to the module that helps (when the account may open it).
     *
     * @param array $reasons From Assess().
     * @param int $max At most this many (0: all).
     * @return string HTML ('' when there are none).
     */
    static function Reasons($reasons, $max = 0, $sub = false)
    {
        if (!$reasons) {
            return '';
        }
        $u = ctrl_users::GetUserDetail();
        $names = array('sencrypt' => 'Sencrypt SSL', 'domains' => 'Domains', 'sub_domains' => 'Sub Domains', 'php_versions' => 'PHP versions');
        $out = '<ul class="sh-reasons">';
        foreach (($max ? array_slice($reasons, 0, $max) : $reasons) as $r) {
            $link = '';
            if ($sub && $r['module'] === 'domains') {
                $r['module'] = 'sub_domains'; // a sub-domain's PHP and folder are on Sub Domains
            }
            if ($r['module'] !== '' && sys_sitehealth::CanOpen($u['usergroupid'], $r['module'])) {
                $link = ' <a href="./?module=' . self::h($r['module']) . '">' . self::t(isset($names[$r['module']]) ? $names[$r['module']] : $r['module']) . self::Icon('right', 'sh-ico-sm') . '</a>';
            }
            $out .= '<li class="is-' . self::h($r['level']) . '">' . self::h($r['text']) . $link . '</li>';
        }
        if ($max && count($reasons) > $max) {
            $out .= '<li class="sh-more">' . str_replace('%1', (string) (count($reasons) - $max), self::t('and %1 more')) . '</li>';
        }
        return $out . '</ul>';
    }

    /**
     * Small bars for a series of numbers.
     *
     * @param int[] $vals The numbers, oldest first.
     * @param string[] $titles Each bar's tooltip.
     * @param string[] $classes Each bar's class ('' for the default).
     * @return string HTML.
     */
    static function Bars($vals, $titles, $classes = array())
    {
        $max = max(1, max($vals ?: array(0)));
        $out = '<span class="sh-bars" aria-hidden="true">';
        foreach ($vals as $i => $v) {
            $h = $v > 0 ? max(3, (int) round($v / $max * 26)) : 2;
            $out .= '<i class="' . self::h(isset($classes[$i]) ? $classes[$i] : '') . '" style="height:' . $h . 'px" title="' . self::h($titles[$i]) . '"></i>';
        }
        return $out . '</span>';
    }

    /**
     * The last 7 days' requests of a site, oldest first.
     *
     * @param array $d The site's summary.
     * @return array{0: int[], 1: string[]}
     */
    static function Week($d)
    {
        $vals = array();
        $titles = array();
        for ($i = 6; $i >= 0; $i--) {
            $day = sys_sitehealth::Day($i);
            $n = isset($d['traffic']['days'][$day]) ? (int) $d['traffic']['days'][$day][0] : 0;
            $vals[] = $n;
            $titles[] = $day . ': ' . number_format($n);
        }
        return array($vals, $titles);
    }

    /**
     * The top of the page: the counts, and when the sites were checked (<@ Summary @>).
     *
     * @return string HTML.
     */
    static function getSummary()
    {
        $s = self::Sites();
        $n = array('ok' => 0, 'warn' => 0, 'bad' => 0, 'unknown' => 0);
        foreach ($s['sites'] as $x) {
            $n[$x['level']]++;
        }
        $next = (int) (floor(time() / 3600) + 1) * 3600;
        $tile = function ($cls, $num, $label) {
            return '<div class="sh-tile ' . $cls . '"><b>' . $num . '</b><span>' . self::t($label) . '</span></div>';
        };
        return '<div class="sh-tiles">'
            . $tile('', count($s['sites']), 'Websites')
            . $tile('is-ok', $n['ok'], 'Working')
            . $tile('is-warn', $n['warn'], 'Need attention')
            . $tile('is-bad', $n['bad'], 'Not working')
            . '<div class="sh-tile sh-when">' . self::Icon('clock') . '<span>'
            . ($s['time'] ? self::t('Checked') . ' <b>' . self::h(date('H:i', $s['time'])) . '</b>' : self::t('Not checked yet'))
            . '<br>' . self::t('Next check') . ' <b>' . self::h(date('H:i', $next)) . '</b></span></div>'
            . '</div>';
    }

    /**
     * A card per site (<@ Cards @>).
     *
     * @return string HTML.
     */
    static function getCards()
    {
        $s = self::Sites();
        if (!$s['sites']) {
            return '<div class="sh-card sh-state">' . self::Icon('globe', 'sh-ico-lg') . '<b>' . self::t('You have no websites yet') . '</b><span>'
                . self::t('Add a domain and its health shows here within the hour.') . ' <a href="./?module=domains">' . self::t('Domains') . '</a></span></div>';
        }
        $out = '<div class="sh-grid">';
        foreach ($s['sites'] as $x) {
            $d = $x['d'];
            $c = isset($d['check']) ? $d['check'] : null;
            $lines = '';
            // up: the answer and how fast
            if ($c !== null) {
                $hist = isset($d['history']) ? array_slice($d['history'], -24) : array();
                $vals = array();
                $titles = array();
                $cls = array();
                foreach ($hist as $h) {
                    $vals[] = $h[1] ? max(1, (int) $h[2]) : 1;
                    $titles[] = date('H:i', (int) $h[0]) . ': ' . ($h[1] ? $h[1] . ', ' . self::Took($h[2]) : ui_language::translate('no answer'));
                    $cls[] = !$h[1] || $h[1] >= 500 ? 'is-bad' : ($h[1] >= 400 ? 'is-warn' : '');
                }
                $answer = $c['code'] ? $c['code'] . ($c['code'] >= 300 && $c['code'] < 400 && $c['location'] !== '' ? ' → ' . self::h(self::Short($c['location'])) : '') . ' · ' . self::Took($c['ms'])
                    : self::t('no answer');
                $lines .= '<li>' . self::Icon('pulse') . '<span>' . self::t('Answers') . ' <b>' . $answer . '</b></span>' . self::Bars($vals, $titles, $cls) . '</li>';
                // the certificate
                if (!empty($d['cert'])) {
                    $days = (int) floor(((int) $d['cert']['until'] - time()) / 86400);
                    $lines .= '<li>' . self::Icon('lock') . '<span>' . self::h($d['cert']['issuer'] !== '' ? $d['cert']['issuer'] : ui_language::translate('Certificate'))
                        . ' · ' . ($days >= 0 ? str_replace('%1', (string) $days, self::t('valid for %1 more days')) : self::t('expired')) . '</span></li>';
                } else {
                    $lines .= '<li>' . self::Icon('unlock') . '<span class="sh-muted">' . self::t('No certificate here: the site runs on http') . '</span></li>';
                }
            }
            // PHP
            if (!empty($d['php']['text'])) {
                $lines .= '<li>' . self::Icon('php') . '<span>' . self::h($d['php']['text'])
                    . (!empty($d['php']['ended']) ? ' · <span class="sh-bad-text">' . str_replace('%1', self::h($d['php']['ended']), self::t('no security fixes since %1')) . '</span>'
                        : (!empty($d['php']['until']) ? ' · <span class="sh-muted">' . str_replace('%1', self::h($d['php']['until']), self::t('security fixes until %1')) . '</span>' : ''))
                    . '</span></li>';
            }
            // today: PHP errors and visits
            $today = sys_sitehealth::Day();
            $e = isset($d['errors']['days'][$today]) ? $d['errors']['days'][$today] : array(0, 0, 0);
            $tr = isset($d['traffic']['days'][$today]) ? $d['traffic']['days'][$today] : array(0, 0, 0, 0, 0);
            $lines .= '<li>' . self::Icon('bug') . '<span>' . self::t('PHP errors today') . ' <b>' . (int) $e[0] . '</b> ' . self::t('fatal') . ' · <b>' . (int) $e[1] . '</b> ' . self::t((int) $e[1] === 1 ? 'warning' : 'warnings') . '</span></li>';
            list($wv, $wt) = self::Week($d);
            $lines .= '<li>' . self::Icon('chart') . '<span>' . self::t('Requests today') . ' <b>' . number_format((int) $tr[0]) . '</b>'
                . ((int) $tr[0] ? ' · ' . self::t('not found') . ' <b>' . number_format((int) $tr[3]) . '</b> · ' . self::t('server errors') . ' <b>' . number_format((int) $tr[4]) . '</b>' : '')
                . '</span>' . self::Bars($wv, $wt) . '</li>';
            $out .= '<article class="sh-card sh-site is-' . self::h($x['level']) . '">'
                . '<header><a class="sh-name" href="./?module=site_health&amp;domain=' . self::h(rawurlencode($x['name'])) . '">' . self::Icon('globe') . self::h($x['name']) . '</a>' . self::Pill($x['level']) . '</header>'
                . self::Reasons($x['reasons'], 3, $x['sub'])
                . '<ul class="sh-lines">' . $lines . '</ul>'
                . '<footer><a class="btn btn-default btn-sm" href="./?module=site_health&amp;domain=' . self::h(rawurlencode($x['name'])) . '">' . self::t('Details') . self::Icon('right', 'sh-ico-sm') . '</a>'
                . '<a class="btn btn-default btn-sm" href="' . self::h((!empty($c['https']) ? 'https://' : 'http://') . $x['name'] . '/') . '" target="_blank" rel="noopener">' . self::t('Visit') . '</a></footer>'
                . '</article>';
        }
        return $out . '</div>';
    }

    /**
     * A time to answer in words: milliseconds under a second, else seconds.
     *
     * @param int $ms Milliseconds.
     * @return string
     */
    static function Took($ms)
    {
        return (int) $ms < 1000 ? max(1, (int) $ms) . ' ms' : number_format($ms / 1000, 1) . ' s';
    }

    /**
     * A URL shortened for display.
     *
     * @param string $url The URL.
     * @return string
     */
    static function Short($url)
    {
        $url = preg_replace('~^https?://~i', '', (string) $url);
        return strlen($url) > 48 ? substr($url, 0, 45) . '…' : $url;
    }

    /**
     * One site's details (<@ Detail @>).
     *
     * @return string HTML.
     */
    static function getDetail()
    {
        $x = self::Current();
        if ($x === null) {
            return '';
        }
        $d = $x['d'];
        $today = sys_sitehealth::Day();
        $out = '<div class="sh-detail-head"><a class="btn btn-default btn-sm" href="./?module=site_health">' . self::Icon('back', 'sh-ico-sm') . self::t('All websites') . '</a>'
            . '<h2>' . self::h($x['name']) . '</h2>' . self::Pill($x['level']) . '</div>';
        if ($x['reasons']) {
            $out .= '<div class="sh-card">' . self::Reasons($x['reasons'], 0, $x['sub']) . '</div>';
        }
        // the last day of checks
        $hist = isset($d['history']) ? array_slice($d['history'], -24) : array();
        $rows = '';
        foreach (array_reverse($hist) as $h) {
            $rows .= '<tr><td>' . self::h(date('Y-m-d H:i', (int) $h[0])) . '</td><td>' . ($h[1] ? '<span class="sh-code ' . ($h[1] >= 500 ? 'is-bad' : ($h[1] >= 400 ? 'is-warn' : 'is-ok')) . '">' . (int) $h[1] . '</span>' : '<span class="sh-code is-bad">' . self::t('no answer') . '</span>')
                . '</td><td class="sh-num">' . ($h[1] ? self::h(self::Took($h[2])) : '–') . '</td></tr>';
        }
        $c = isset($d['check']) ? $d['check'] : null;
        $cert = '';
        if (!empty($d['cert'])) {
            $cert = '<p>' . self::Icon('lock') . ' ' . self::h($d['cert']['issuer']) . ' · ' . self::t('valid until') . ' <b>' . self::h(date('Y-m-d', (int) $d['cert']['until'])) . '</b>'
                . (!empty($d['cert']['self']) ? ' · <span class="sh-bad-text">' . self::t('self-signed') . '</span>' : '') . '</p>';
        } elseif ($c !== null) {
            $cert = '<p class="sh-muted">' . self::Icon('unlock') . ' ' . self::t('No certificate here: the site runs on http.') . '</p>';
        }
        $out .= '<div class="sh-cols">';
        $out .= '<section class="sh-card"><h3>' . self::Icon('pulse') . self::t('The last day of checks') . '</h3>'
            . ($c !== null ? '<p class="sh-muted">' . self::t('Every hour this server asks the site for its home page, as a visitor would.') . '</p>' . $cert
                . '<div class="sh-scroll"><table class="sh-table"><thead><tr><th>' . self::t('Time') . '</th><th>' . self::t('Answer') . '</th><th class="sh-num">' . self::t('Took') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
                : '<p class="sh-muted">' . self::t('Not checked yet: the first check is within the hour.') . '</p>')
            . '</section>';
        // PHP errors
        $latest = isset($d['errors']['latest']) && is_array($d['errors']['latest']) ? $d['errors']['latest'] : array();
        $evals = array();
        $etitles = array();
        $ecls = array();
        for ($i = 6; $i >= 0; $i--) {
            $day = sys_sitehealth::Day($i);
            $e = isset($d['errors']['days'][$day]) ? $d['errors']['days'][$day] : array(0, 0, 0);
            $evals[] = (int) $e[0] + (int) $e[1];
            $etitles[] = $day . ': ' . (int) $e[0] . ' ' . ui_language::translate('fatal') . ', ' . (int) $e[1] . ' ' . ui_language::translate('warnings');
            $ecls[] = (int) $e[0] ? 'is-bad' : '';
        }
        $list = '';
        foreach ($latest as $m) {
            $fatal = strpos($m['level'], 'atal') !== false || strpos($m['level'], 'Parse') !== false;
            $list .= '<li><span class="sh-code ' . ($fatal ? 'is-bad' : 'is-warn') . '">' . self::h(ui_language::translate($m['level'])) . '</span>'
                . '<code>' . self::h($m['msg']) . '</code><small>' . self::h(date('Y-m-d H:i', (int) $m['t'])) . ((int) $m['n'] > 1 ? ' · ' . str_replace('%1', (string) (int) $m['n'], self::t('%1 times')) : '') . '</small></li>';
        }
        $php = isset($d['php']) ? $d['php'] : array();
        $out .= '<section class="sh-card"><h3>' . self::Icon('bug') . self::t('PHP errors') . self::Bars($evals, $etitles, $ecls) . '</h3>'
            . (!empty($php['text']) ? '<p>' . self::Icon('php') . ' ' . self::h($php['text'])
                . (!empty($php['ended']) ? ' · <span class="sh-bad-text">' . str_replace('%1', self::h($php['ended']), self::t('no security fixes since %1')) . '</span>' : '') . '</p>' : '')
            . ($list !== '' ? '<p class="sh-muted">' . self::t('The latest from the site\'s error log (each message once, with how often it came):') . '</p><ul class="sh-errors">' . $list . '</ul>'
                : '<p class="sh-muted">' . self::t('No PHP errors or warnings seen in the site\'s error log.') . '</p>')
            . '</section>';
        // error pages
        $tables = '';
        foreach (array('missed' => array('Pages not found (404) today', 'is-warn'), 'failed' => array('Pages with a server error (5xx) today', 'is-bad')) as $k => $meta) {
            $paths = isset($d['traffic'][$k][$today]) ? $d['traffic'][$k][$today] : array();
            if (!$paths) {
                continue;
            }
            $r = '';
            foreach ($paths as $p => $n) {
                $r .= '<tr><td><code>' . self::h($p) . '</code></td><td class="sh-num"><span class="sh-code ' . $meta[1] . '">' . (int) $n . '</span></td></tr>';
            }
            $tables .= '<h4>' . self::t($meta[0]) . '</h4><div class="sh-scroll"><table class="sh-table"><tbody>' . $r . '</tbody></table></div>';
        }
        list($wv, $wt) = self::Week($d);
        $tr = isset($d['traffic']['days'][$today]) ? $d['traffic']['days'][$today] : array(0, 0, 0, 0, 0);
        $out .= '<section class="sh-card"><h3>' . self::Icon('chart') . self::t('Requests') . self::Bars($wv, $wt) . '</h3>'
            . '<p>' . self::t('Today') . ': <b>' . number_format((int) $tr[0]) . '</b> · ' . self::t('fine') . ' <b>' . number_format((int) $tr[1] + (int) $tr[2]) . '</b> · '
            . self::t('not found') . ' <b>' . number_format((int) $tr[3]) . '</b> · ' . self::t('server errors') . ' <b>' . number_format((int) $tr[4]) . '</b></p>'
            . ($tables !== '' ? $tables : '<p class="sh-muted">' . self::t('No error pages today.') . '</p>')
            . '<p class="sh-muted">' . self::t('Who visits, from where and with what: GoAccess, Webalizer Stats and Visitor Stats.') . '</p>'
            . '</section>';
        return $out . '</div>';
    }

    /**
     * Nothing to prepare (template <@ Init @>).
     *
     * @return void
     */
    static function getInit()
    {
    }
}

?>
