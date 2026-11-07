<?php

/**
 * SpamAV (Mail): what spam and virus filtering caught for this account's mailboxes,
 * and each mailbox's spam settings (when the administrator allows it). Only the
 * account's own mailboxes and the mail sent to them (or by them) are shown.
 */
/**
 * SpamAV module: spam/virus totals, per-mailbox spam settings and the activity log.
 */
class module_controller extends ctrl_module
{
    /**
     * @var array<int, array<int, mixed>> Messages for getResult(): array('ok'|'error', English text), or with true third: the text already translated.
     */
    static $msg = array();

    /**
     * @var array<string, string>|null The settings form as posted when saving failed, so the form shows what was typed ('box' and the fields).
     */
    static $posted = null;

    /**
     * Escapes text for HTML.
     *
     * @param mixed $s The value (cast to string).
     * @return string The escaped text.
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translates text into the user's language.
     *
     * @param string $s The English text.
     * @return string The translated text.
     */
    static function t($s)
    {
        return ui_language::translate($s);
    }

    /**
     * Returns the logged-in user's details.
     *
     * @return array<string, mixed> From ctrl_users::GetUserDetail() ('userid', 'username', ...).
     */
    static function Me()
    {
        return ctrl_users::GetUserDetail();
    }

    /**
     * Returns this account's mailboxes.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, string> The addresses, lower case, sorted.
     */
    static function Mailboxes()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT LOWER(mb_address_vc) FROM x_mailboxes WHERE mb_acc_fk = :a AND mb_deleted_ts IS NULL ORDER BY mb_address_vc");
        $q->execute(array(':a' => (int) self::Me()['userid']));
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Tells whether an address is one of this account's mailboxes.
     *
     * @param string $mailbox The address (any case).
     * @return bool
     */
    static function Mine($mailbox)
    {
        return in_array(strtolower((string) $mailbox), self::Mailboxes(), true);
    }

    /**
     * Saves a mailbox's spam settings (POST action=SavePrefs).
     *
     * Checks the CSRF token, that the mailbox is the account's own, that the administrator
     * allows user settings (sa_user_settings) and every field, then writes the
     * x_spamav_prefs row. The result is added to $msg.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doSavePrefs()
    {
        global $zdbh, $controller;
        runtime_csfr::Protect();
        sys_spamav::Ensure();
        $box = strtolower((string) $controller->GetControllerRequest('FORM', 'inMailbox'));
        if (!self::Mine($box)) {
            self::$msg[] = array('error', 'That is not one of your mailboxes.');
            return;
        }
        if (!sys_spamav::On('sa_user_settings')) {
            self::$msg[] = array('error', 'Your administrator has not allowed changing the spam settings.');
            return;
        }
        $f = $controller->GetAllControllerRequests('FORM');
        // kept for the form when something below is refused
        self::$posted = array('box' => $box, 'enabled' => !empty($f['inEnabled']), 'score' => isset($f['inScore']) ? (string) $f['inScore'] : '',
            'action' => isset($f['inAction']) ? (string) $f['inAction'] : '', 'delete_high' => !empty($f['inDeleteHigh']),
            'allow' => isset($f['inAllow']) ? (string) $f['inAllow'] : '', 'block' => isset($f['inBlock']) ? (string) $f['inBlock'] : '');
        $score = isset($f['inScore']) ? (string) $f['inScore'] : '';
        $action = isset($f['inAction']) ? (string) $f['inAction'] : '';
        if (!array_key_exists($score, sys_spamav::Sensitivities()) || !in_array($action, array('', 'folder', 'inbox'), true)) {
            self::$msg[] = array('error', 'Choose a sensitivity and where spam goes.');
            return;
        }
        $lists = array();
        foreach (array('inAllow' => 'Allowed senders', 'inBlock' => 'Blocked senders') as $field => $label) {
            $out = array();
            foreach (preg_split('/[\r\n,]+/', isset($f[$field]) ? (string) $f[$field] : '') as $line) {
                $line = sys_spamav::NormalSender($line);
                if ($line === '') {
                    continue;
                }
                if (!sys_spamav::ValidSenderPattern($line)) {
                    // the list's name and the line are put in after translating
                    self::$msg[] = array('error', str_replace(array('%list%', '%value%'), array(self::t($label), substr($line, 0, 60)),
                        self::t('%list%: "%value%" is not an address or a pattern like *@example.com.')), true);
                    return;
                }
                $out[$line] = true;
            }
            if (count($out) > 200) {
                self::$msg[] = array('error', $label . ': at most 200.');
                return;
            }
            $lists[$field] = implode("\n", array_keys($out));
        }
        $zdbh->prepare("REPLACE INTO x_spamav_prefs (sp_mailbox_vc, sp_acc_fk, sp_enabled_in, sp_score_vc, sp_action_vc, sp_delete_high_in, sp_allow_tx, sp_block_tx)
            VALUES (:m, :a, :e, :s, :ac, :d, :al, :bl)")->execute(array(':m' => $box, ':a' => (int) self::Me()['userid'],
            ':e' => !empty($f['inEnabled']) ? 1 : 0, ':s' => $score === '' ? null : $score, ':ac' => $action === '' ? null : $action,
            ':d' => !empty($f['inDeleteHigh']) ? 1 : 0, ':al' => $lists['inAllow'], ':bl' => $lists['inBlock']));
        self::$posted = null;
        self::$msg[] = array('ok', str_replace('%s', $box, self::t('Spam settings for %s saved. They apply within 5 minutes.')), true);
    }

    /**
     * Sums of the daily counts for the account (optionally one mailbox) since a number of days ago.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $days 1 for today, 7 for the last 7 days, and so on.
     * @param string $box Optional. One mailbox; '' for all of them. Default ''.
     * @return array<int, int> Checked, spam, viruses, refused.
     */
    static function Sums($days, $box = '')
    {
        global $zdbh;
        $sql = "SELECT COALESCE(SUM(dd_scanned_in),0), COALESCE(SUM(dd_spam_in),0), COALESCE(SUM(dd_virus_in),0), COALESCE(SUM(dd_rejected_in),0) FROM x_spamav_daily WHERE dd_acc_fk = :a AND dd_day >= :d";
        $args = array(':a' => (int) self::Me()['userid'], ':d' => date('Y-m-d', time() - ($days - 1) * 86400));
        if ($box !== '') {
            $sql .= " AND dd_mailbox_vc = :m";
            $args[':m'] = $box;
        }
        $q = $zdbh->prepare($sql);
        $q->execute($args);
        return array_map('intval', $q->fetch(PDO::FETCH_NUM));
    }

    /**
     * The figures at the top (<@ Totals @>): the last 7 days checked, spam, viruses and refused, with today and 30 days under each.
     *
     * @return string The HTML.
     */
    static function getTotals()
    {
        $w = self::Sums(7);
        $d = self::Sums(1);
        $m = self::Sums(30);
        $tiles = array(
            array('Checked', 0, 'sv-t-checked', '<path d="M4 4h16v16H4z"/><path d="m4 7 8 6 8-6"/>'),
            array('Spam caught', 1, 'sv-t-spam', '<path d="M12 3 2 21h20z"/><path d="M12 10v4M12 17h.01"/>'),
            array('Viruses blocked', 2, 'sv-t-virus', '<circle cx="12" cy="12" r="5"/><path d="M12 2v5M12 17v5M2 12h5M17 12h5M5 5l3.5 3.5M15.5 15.5 19 19M5 19l3.5-3.5M15.5 8.5 19 5"/>'),
            array('Refused outright', 3, 'sv-t-refused', '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>'),
        );
        $out = '';
        foreach ($tiles as $t) {
            $out .= '<div class="sv-tile ' . $t[2] . '"><svg class="sv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $t[3] . '</svg>'
                . '<div><b>' . number_format($w[$t[1]]) . '</b><span>' . self::h(self::t($t[0])) . '</span>'
                . '<small>' . self::h(self::t('Today')) . ' ' . number_format($d[$t[1]]) . ' &middot; ' . self::h(self::t('30 days')) . ' ' . number_format($m[$t[1]]) . '</small></div></div>';
        }
        return $out;
    }

    /**
     * A bar for each of the last 14 days: spam and viruses caught for the account (<@ Chart @>).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string The HTML.
     */
    static function getChart()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT dd_day, SUM(dd_spam_in), SUM(dd_virus_in) FROM x_spamav_daily WHERE dd_acc_fk = :a AND dd_day >= :d GROUP BY dd_day");
        $q->execute(array(':a' => (int) self::Me()['userid'], ':d' => date('Y-m-d', time() - 13 * 86400)));
        $by = array();
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
            $by[$r[0]] = array((int) $r[1], (int) $r[2]);
        }
        $max = 1;
        foreach ($by as $v) {
            $max = max($max, $v[0] + $v[1]);
        }
        $bars = '';
        for ($i = 13; $i >= 0; $i--) {
            $t = time() - $i * 86400;
            $v = isset($by[date('Y-m-d', $t)]) ? $by[date('Y-m-d', $t)] : array(0, 0);
            $tip = ($i === 0 ? self::t('Today') : ui_language::Date('D j M', $t)) . ': ' . $v[0] . ' ' . self::t('spam') . ', ' . $v[1] . ' ' . self::t($v[1] === 1 ? 'virus' : 'viruses');
            $bars .= '<div class="sv-bar" title="' . self::h($tip) . '"><div class="sv-stack">'
                . '<i class="sv-b-virus" style="height:' . round($v[1] / $max * 100, 1) . '%"></i><i class="sv-b-spam" style="height:' . round($v[0] / $max * 100, 1) . '%"></i></div>'
                . '<span>' . date('j', $t) . '</span></div>';
        }
        return '<div class="sv-chart" role="img" aria-label="' . self::h(self::t('Spam and viruses caught, last 14 days')) . '">' . $bars . '</div>';
    }

    /**
     * Whether spam filtering and virus scanning are on for the server (<@ ServerState @>), as two pills.
     *
     * @return string The HTML.
     */
    static function getServerState()
    {
        sys_spamav::Ensure();
        $pill = function ($on, $label) {
            return '<span class="sv-pill ' . ($on ? 'on' : 'off') . '">' . self::h(self::t($label)) . ': ' . self::h(self::t($on ? 'on' : 'off')) . '</span>';
        };
        return $pill(sys_spamav::On('sa_enabled'), 'Spam filtering') . $pill(sys_spamav::On('clam_enabled'), 'Virus scanning');
    }

    /**
     * Whether the user may change spam settings (template <% if CanEdit %>).
     *
     * @return bool
     */
    static function getCanEdit()
    {
        return sys_spamav::On('sa_user_settings');
    }

    /**
     * One row per mailbox with its settings, 7-day counts and a settings panel that opens in place (<@ MailboxRows @>).
     *
     * The panel of the mailbox in ?edit= (or the one just saved) starts open.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return string The HTML.
     */
    static function getMailboxRows()
    {
        global $zdbh, $controller;
        $boxes = self::Mailboxes();
        if (!$boxes) {
            return '<p class="sv-empty">' . self::h(self::t('You have no mailboxes yet. Make one in Mail > Mailboxes, and what is caught for it shows here.')) . '</p>';
        }
        $can = sys_spamav::On('sa_user_settings');
        $sens = sys_spamav::Sensitivities();
        $open = strtolower((string) $controller->GetControllerRequest('URL', 'edit'));
        if (self::$posted !== null) {
            $open = self::$posted['box'];
        }
        $q = $zdbh->prepare("SELECT COALESCE(SUM(dd_spam_in),0), COALESCE(SUM(dd_virus_in),0) FROM x_spamav_daily WHERE dd_acc_fk = :a AND dd_mailbox_vc = :m AND dd_day >= :d");
        $out = '';
        foreach ($boxes as $b) {
            $p = sys_spamav::Prefs($b);
            $q->execute(array(':a' => (int) self::Me()['userid'], ':m' => $b, ':d' => date('Y-m-d', time() - 6 * 86400)));
            $c = $q->fetch(PDO::FETCH_NUM);
            $action = $p['action'] !== '' ? $p['action'] : sys_spamav::Get('sa_default_action');
            $isopen = $b === $open;
            $out .= '<div class="sv-box' . ($isopen ? ' open' : '') . '" data-s="' . self::h($b) . '">'
                . '<div class="sv-box-row">'
                . '<div class="sv-box-name"><b>' . self::h($b) . '</b>'
                . '<span class="sv-pill ' . ($p['enabled'] ? 'on' : 'off') . '">' . self::h(self::t($p['enabled'] ? 'Filter on' : 'Filter off')) . '</span></div>'
                . '<div class="sv-box-facts"><span>' . self::h(self::t($sens[$p['score']] ?? $p['score'])) . '</span>'
                . '<span>' . self::h(self::t($action === 'inbox' ? 'Spam stays in the inbox, marked' : 'Spam goes to the Spam folder')) . '</span>'
                . ($p['delete_high'] ? '<span>' . self::h(self::t('Very high scores deleted')) . '</span>' : '') . '</div>'
                . '<div class="sv-box-count" title="' . self::h(self::t('Last 7 days')) . '"><span class="sv-c-spam"><b>' . number_format((int) $c[0]) . '</b> ' . self::h(self::t('spam')) . '</span>'
                . '<span class="sv-c-virus"><b>' . number_format((int) $c[1]) . '</b> ' . self::h(self::t((int) $c[1] === 1 ? 'virus' : 'viruses')) . '</span></div>'
                . '<div class="sv-box-act"><a class="btn btn-default btn-sm" href="./?module=spamav&amp;box=' . rawurlencode($b) . '#activity" data-activity="' . self::h($b) . '">' . self::h(self::t('Activity')) . '</a>'
                . '<button class="btn btn-default btn-sm sv-open" type="button" aria-expanded="' . ($isopen ? 'true' : 'false') . '">' . self::h(self::t($can ? 'Settings' : 'View settings')) . '</button></div>'
                . '</div>' . self::Form($b, $can) . '</div>';
        }
        return $out;
    }

    /**
     * Builds the spam settings panel for one mailbox.
     *
     * Shows what was posted when saving it failed, so nothing typed is lost.
     *
     * @param string $box The mailbox address (already checked to be the account's own).
     * @param bool $can True when the user may change the settings (otherwise the fields are disabled).
     * @return string The HTML.
     */
    static function Form($box, $can)
    {
        $p = sys_spamav::Prefs($box);
        if (self::$posted !== null && self::$posted['box'] === $box) {
            $p = array_merge($p, self::$posted);
        }
        $dis = $can ? '' : ' disabled';
        $id = 'sv' . substr(md5($box), 0, 8);
        $opts = '';
        foreach (sys_spamav::Sensitivities() as $k => $l) {
            $opts .= '<option value="' . self::h($k) . '"' . ((string) $k === (string) $p['score'] ? ' selected' : '') . '>' . self::h(self::t($l)) . '</option>';
        }
        $def = sys_spamav::Get('sa_default_action') === 'inbox' ? 'the inbox' : 'the Spam folder';
        // translated here: the subject tag is the administrator's setting, not translated with the text
        $tag = (string) sys_spamav::Get('sa_subject_tag') !== '' ? self::t('The subject starts with') . ' ' . sys_spamav::Get('sa_subject_tag') : self::t('Marked in its headers only');
        $where = '';
        foreach (array('' => array('Server default', self::t('Now ' . $def)), 'folder' => array('Spam folder', self::t('Out of the way, still there to check')), 'inbox' => array('Inbox, marked', $tag)) as $k => $l) {
            $where .= '<label class="sv-choice"><input type="radio" name="inAction" value="' . self::h($k) . '"' . ((string) $k === (string) $p['action'] ? ' checked' : '') . $dis . '>'
                . '<span><b>' . self::h(self::t($l[0])) . '</b><small>' . self::h($l[1]) . '</small></span></label>';
        }
        $sw = function ($name, $on, $label, $help) use ($dis, $id) {
            return '<div class="sv-switchrow"><div><label for="' . $id . $name . '">' . self::h(self::t($label)) . '</label><small>' . self::h(self::t($help)) . '</small></div>'
                . '<label class="sv-switch"><input type="checkbox" id="' . $id . $name . '" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . $dis . '><span class="sv-toggle"></span></label></div>';
        };
        $lines = function ($text) {
            return count(array_filter(preg_split('/[\r\n,]+/', (string) $text), function ($l) { return trim($l) !== ''; }));
        };
        return '<form class="sv-form" method="post" action="./?module=spamav&amp;action=SavePrefs&amp;edit=' . rawurlencode($box) . '">'
            . '<input type="hidden" name="inMailbox" value="' . self::h($box) . '">'
            . ($can ? '' : '<p class="sv-locked">' . self::h(self::t('Your administrator sets these; they can not be changed here.')) . '</p>')
            . '<div class="sv-form-grid"><div class="sv-col">'
            . $sw('inEnabled', $p['enabled'], 'Check this mailbox for spam', 'Viruses are always blocked, whatever is chosen here.')
            . '<div class="sv-field"><label for="' . $id . 'score">' . self::h(self::t('Sensitivity')) . '</label><select class="form-control" id="' . $id . 'score" name="inScore"' . $dis . '>' . $opts . '</select>'
            . '<small>' . self::h(self::t('Stricter catches more spam, but sometimes also good mail.')) . '</small></div>'
            . '<div class="sv-field"><span class="sv-flabel">' . self::h(self::t('Spam goes to')) . '</span><div class="sv-choices">' . $where . '</div></div>'
            . $sw('inDeleteHigh', $p['delete_high'], 'Delete very high scores', 'Spam scoring 15 or more is deleted instead of kept.')
            . '</div><div class="sv-col">'
            . '<div class="sv-field"><label for="' . $id . 'allow">' . self::h(self::t('Allowed senders')) . ' <span class="sv-n">' . $lines($p['allow']) . '</span></label>'
            . '<textarea class="form-control sv-list" id="' . $id . 'allow" name="inAllow" rows="5" spellcheck="false" placeholder="friend@example.com&#10;*@partner.example"' . $dis . '>' . self::h($p['allow']) . '</textarea>'
            . '<small>' . self::h(self::t('Never spam. One per line: an address, or everyone at a domain (example.com or *@example.com).')) . '</small></div>'
            . '<div class="sv-field"><label for="' . $id . 'block">' . self::h(self::t('Blocked senders')) . ' <span class="sv-n">' . $lines($p['block']) . '</span></label>'
            . '<textarea class="form-control sv-list" id="' . $id . 'block" name="inBlock" rows="5" spellcheck="false"' . $dis . '>' . self::h($p['block']) . '</textarea>'
            . '<small>' . self::h(self::t('Always spam. Same form, up to 200 in each list.')) . '</small></div>'
            . '</div></div>'
            . '<div class="sv-form-foot">' . runtime_csfr::Token() . ($can ? '<button class="btn btn-primary" type="submit">' . self::h(self::t('Save')) . '</button>' : '')
            . '<button class="btn btn-default sv-close" type="button">' . self::h(self::t('Close')) . '</button>'
            . ($can ? '<span class="sv-note">' . self::h(self::t('Changes apply within 5 minutes.')) . '</span>' : '') . '</div></form>';
    }

    /**
     * Whether the page should open on the activity tab: a filter or page of it is in the URL (template <% if ShowActivity %>).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getShowActivity()
    {
        global $controller;
        foreach (array('box', 'kind', 'page') as $k) {
            if ((string) $controller->GetControllerRequest('URL', $k) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * The activity log (<@ Activity @>): what was caught for the account, 25 per page, newest first.
     *
     * Filtered by ?box= (one of the account's mailboxes) and ?kind= (spam or virus); page in ?page=.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @global runtime_controller $controller The request controller.
     * @return string The HTML (filters, table and pager).
     */
    static function getActivity()
    {
        global $zdbh, $controller;
        $acc = (int) self::Me()['userid'];
        $box = strtolower((string) $controller->GetControllerRequest('URL', 'box'));
        $kind = (string) $controller->GetControllerRequest('URL', 'kind');
        if ($box !== '' && !self::Mine($box)) {
            $box = '';
        }
        if (!in_array($kind, array('spam', 'virus'), true)) {
            $kind = '';
        }
        $sql = "SELECT * FROM x_spamav_events WHERE ev_acc_fk = :a";
        $args = array(':a' => $acc);
        if ($box !== '') {
            $sql .= " AND (ev_mailbox_vc = :m OR ev_sender_vc = :m2)";
            $args[':m'] = $box;
            $args[':m2'] = $box;
        }
        if ($kind !== '') {
            $sql .= " AND ev_kind_vc = :k";
            $args[':k'] = $kind;
        }
        // 25 at a time, newest first (a busy mailbox can have thousands)
        $per = 25;
        $cq = $zdbh->prepare(str_replace('SELECT *', 'SELECT COUNT(*)', $sql));
        $cq->execute($args);
        $total = (int) $cq->fetchColumn();
        $pages = max(1, (int) ceil($total / $per));
        $page = min($pages, max(1, (int) $controller->GetControllerRequest('URL', 'page')));
        $q = $zdbh->prepare($sql . " ORDER BY ev_ts DESC, ev_id_pk DESC LIMIT " . $per . " OFFSET " . (($page - 1) * $per));
        $q->execute($args);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $link = function ($p, $k = null) use ($box, $kind) {
            return './?module=spamav&amp;box=' . rawurlencode($box) . '&amp;kind=' . rawurlencode($k === null ? $kind : $k) . '&amp;page=' . (int) $p . '#activity';
        };

        $opts = '<option value="">' . self::h(self::t('All mailboxes')) . '</option>';
        foreach (self::Mailboxes() as $b) {
            $opts .= '<option value="' . self::h($b) . '"' . ($b === $box ? ' selected' : '') . '>' . self::h($b) . '</option>';
        }
        $seg = '';
        foreach (array('' => 'All', 'spam' => 'Spam', 'virus' => 'Viruses') as $k => $l) {
            $seg .= '<a class="sv-seg' . ($k === $kind ? ' active' : '') . '" href="' . $link(1, $k) . '">' . self::h(self::t($l)) . '</a>';
        }
        $out = '<form method="get" class="sv-filter" action="./"><input type="hidden" name="module" value="spamav"><input type="hidden" name="kind" value="' . self::h($kind) . '">'
            . '<select class="form-control" name="box" aria-label="' . self::h(self::t('Mailbox')) . '" onchange="this.form.submit()">' . $opts . '</select>'
            . '<noscript><button class="btn btn-default" type="submit">' . self::h(self::t('Show')) . '</button></noscript>'
            . '<div class="sv-segs" role="group">' . $seg . '</div>'
            . '<span class="sv-total">' . number_format($total) . ' ' . self::h(self::t($total === 1 ? 'entry' : 'entries')) . '</span></form>';
        if (!$rows) {
            return $out . '<p class="sv-empty">' . self::h(self::t($box !== '' || $kind !== '' ? 'Nothing caught that matches.' : 'Nothing caught yet. Spam and viruses sent to your mailboxes appear here within 5 minutes.')) . '</p>';
        }
        $actions = array('marked' => array('Marked as spam', ''), 'rejected' => array('Refused', 'bad'), 'quarantined' => array('Held by the administrator', 'warn'), 'dropped' => array('Deleted', 'bad'));
        $out .= '<div class="sv-scroll"><table class="sv-table"><thead><tr><th>' . self::h(self::t('When')) . '</th><th>' . self::h(self::t('What')) . '</th><th>' . self::h(self::t('Mailbox')) . '</th><th>' . self::h(self::t('From')) . '</th><th>' . self::h(self::t('Details')) . '</th><th>' . self::h(self::t('What happened')) . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $virus = $r['ev_kind_vc'] === 'virus';
            $what = $virus ? '<span class="sv-kind virus">' . self::h(self::t('Virus')) . '</span>'
                : '<span class="sv-kind spam">' . self::h(self::t('Spam')) . '</span>' . ($r['ev_score_vc'] !== '' ? '<span class="sv-score">' . self::h($r['ev_score_vc']) . '</span>' : '');
            $detail = $virus ? '<b>' . self::h($r['ev_detail_vc']) . '</b>' : '<span class="sv-tests">' . self::h($r['ev_detail_vc']) . '</span>';
            if ($r['ev_subject_vc'] !== '') {
                $detail .= '<small>' . self::h(self::t('Subject')) . ': ' . self::h($r['ev_subject_vc']) . '</small>';
            }
            $a = $actions[$r['ev_action_vc']] ?? array($r['ev_action_vc'], '');
            $out .= '<tr><td class="sv-when" title="' . self::h(date('Y-m-d H:i:s', (int) $r['ev_ts'])) . '">' . self::h(date('j M', (int) $r['ev_ts'])) . '<small>' . self::h(date('H:i', (int) $r['ev_ts'])) . '</small></td>'
                . '<td class="sv-what">' . $what . '</td>'
                . '<td class="sv-mb">' . self::h($r['ev_mailbox_vc']) . ($r['ev_direction_vc'] === 'out' ? ' <span class="sv-out">' . self::h(self::t('sent by you')) . '</span>' : '') . '</td>'
                . '<td class="sv-from">' . self::h($r['ev_sender_vc'] !== '' ? $r['ev_sender_vc'] : '-') . '</td><td class="sv-detail">' . $detail . '</td>'
                . '<td><span class="sv-did ' . $a[1] . '">' . self::h(self::t($a[0])) . '</span></td></tr>';
        }
        $out .= '</tbody></table></div>';
        if ($pages > 1) {
            $out .= '<div class="sv-pager">'
                . ($page > 1 ? '<a class="btn btn-default btn-sm" href="' . $link($page - 1) . '">&lsaquo; ' . self::h(self::t('Newer')) . '</a>' : '<span class="btn btn-default btn-sm disabled">&lsaquo; ' . self::h(self::t('Newer')) . '</span>')
                . '<span>' . self::h(self::t('Page')) . ' <b>' . $page . '</b> ' . self::h(self::t('of')) . ' ' . $pages . '</span>'
                . ($page < $pages ? '<a class="btn btn-default btn-sm" href="' . $link($page + 1) . '">' . self::h(self::t('Older')) . ' &rsaquo;</a>' : '<span class="btn btn-default btn-sm disabled">' . self::h(self::t('Older')) . ' &rsaquo;</span>')
                . '</div>';
        }
        return $out;
    }

    /**
     * How many mailboxes the account has (template <@ MailboxCount @>).
     *
     * @return int
     */
    static function getMailboxCount()
    {
        return count(self::Mailboxes());
    }

    /**
     * Returns the messages of this request as alert boxes (<@ Result @>).
     *
     * @return string The HTML ('' when there are none).
     */
    static function getResult()
    {
        $out = '';
        foreach (self::$msg as $m) {
            $out .= ui_sysmessage::shout(self::h(!empty($m[2]) ? $m[1] : self::t($m[1])), $m[0] === 'ok' ? 'zannounceok' : 'zannounceerror');
        }
        return $out;
    }
}
