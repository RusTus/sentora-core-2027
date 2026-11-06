<?php

/**
 * SpamAV (Mail): what spam and virus filtering caught for this account's mailboxes,
 * and each mailbox's spam settings (when the administrator allows it). Only the
 * account's own mailboxes and the mail sent to them (or by them) are shown.
 */
class module_controller extends ctrl_module
{
    static $msg = array();

    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    static function t($s)
    {
        return ui_language::translate($s);
    }

    static function Me()
    {
        return ctrl_users::GetUserDetail();
    }

    /** this account's mailboxes */
    static function Mailboxes()
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT LOWER(mb_address_vc) FROM x_mailboxes WHERE mb_acc_fk = :a AND mb_deleted_ts IS NULL ORDER BY mb_address_vc");
        $q->execute(array(':a' => (int) self::Me()['userid']));
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    static function Mine($mailbox)
    {
        return in_array(strtolower((string) $mailbox), self::Mailboxes(), true);
    }

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
                    self::$msg[] = array('error', $label . ': "' . substr($line, 0, 60) . '" is not an address or a pattern like *@example.com.');
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
        self::$msg[] = array('ok', 'Spam settings for ' . $box . ' saved. They apply within 5 minutes.');
    }

    static function Totals()
    {
        global $zdbh;
        $acc = (int) self::Me()['userid'];
        $row = function ($days) use ($zdbh, $acc) {
            $q = $zdbh->prepare("SELECT COALESCE(SUM(dd_scanned_in),0), COALESCE(SUM(dd_spam_in),0), COALESCE(SUM(dd_virus_in),0), COALESCE(SUM(dd_rejected_in),0) FROM x_spamav_daily WHERE dd_acc_fk = :a AND dd_day >= :d");
            $q->execute(array(':a' => $acc, ':d' => date('Y-m-d', time() - ($days - 1) * 86400)));
            return $q->fetch(PDO::FETCH_NUM);
        };
        $cards = '';
        $w = $row(7);
        foreach (array(array('Checked for spam', $w[0], ''), array('Spam caught', $w[1], 'is-spam'), array('Viruses blocked', $w[2], 'is-virus'), array('Refused outright', $w[3], '')) as $c) {
            $cards .= '<div class="sv-card ' . $c[2] . '"><div class="sv-num">' . number_format((int) $c[1]) . '</div><div class="sv-label">' . self::h(self::t($c[0])) . '</div></div>';
        }
        $t = $row(1);
        $m = $row(30);
        return '<div class="sv-cards">' . $cards . '</div><p class="text-muted">' . self::t('Last 7 days, for your mailboxes.') . ' '
            . self::h(self::t('Today') . ': ' . $t[1] . ' ' . self::t('spam') . ', ' . $t[2] . ' ' . self::t((int) $t[2] === 1 ? 'virus' : 'viruses') . '. '
                . self::t('Last 30 days') . ': ' . $m[1] . ' ' . self::t('spam') . ', ' . $m[2] . ' ' . self::t((int) $m[2] === 1 ? 'virus' : 'viruses') . '.') . '</p>';
    }

    static function MailboxTable()
    {
        global $zdbh, $controller;
        $boxes = self::Mailboxes();
        if (!$boxes) {
            return '<p>' . self::t('You have no mailboxes yet.') . '</p>';
        }
        $can = sys_spamav::On('sa_user_settings');
        $sens = sys_spamav::Sensitivities();
        $edit = strtolower((string) $controller->GetControllerRequest('URL', 'edit'));
        $q = $zdbh->prepare("SELECT COALESCE(SUM(dd_spam_in),0), COALESCE(SUM(dd_virus_in),0) FROM x_spamav_daily WHERE dd_acc_fk = :a AND dd_mailbox_vc = :m AND dd_day >= :d");
        $out = '<table class="table table-striped"><tr><th>' . self::t('Mailbox') . '</th><th>' . self::t('Spam filter') . '</th><th>' . self::t('Sensitivity') . '</th><th>' . self::t('Spam goes to') . '</th><th>' . self::t('Spam / viruses (7 days)') . '</th><th></th></tr>';
        foreach ($boxes as $b) {
            $p = sys_spamav::Prefs($b);
            $q->execute(array(':a' => (int) self::Me()['userid'], ':m' => $b, ':d' => date('Y-m-d', time() - 6 * 86400)));
            $c = $q->fetch(PDO::FETCH_NUM);
            $action = $p['action'] !== '' ? $p['action'] : sys_spamav::Get('sa_default_action');
            $out .= '<tr><td><strong>' . self::h($b) . '</strong></td><td>' . ($p['enabled'] ? ui_status::Ok('On') . ' ' . self::t('on') : ui_status::Bad('Off') . ' ' . self::t('off')) . '</td>'
                . '<td>' . self::h(self::t($sens[$p['score']] ?? $p['score'])) . '</td><td>' . self::h(self::t($action === 'inbox' ? 'Inbox (marked)' : 'Spam folder')) . ($p['delete_high'] ? '<br><span class="text-muted">' . self::t('very high scores deleted') . '</span>' : '') . '</td>'
                . '<td>' . (int) $c[0] . ' / ' . (int) $c[1] . '</td>'
                . '<td><a class="btn btn-default btn-sm" href="./?module=spamav&amp;edit=' . rawurlencode($b) . '#settings">' . self::t($can ? 'Settings' : 'View') . '</a></td></tr>';
        }
        $out .= '</table>';
        if ($edit !== '' && in_array($edit, $boxes, true)) {
            $out .= self::Form($edit, $can);
        }
        return $out;
    }

    static function Form($box, $can)
    {
        $p = sys_spamav::Prefs($box);
        $dis = $can ? '' : ' disabled';
        $opt = function ($list, $cur) {
            $o = '';
            foreach ($list as $k => $l) {
                $o .= '<option value="' . self::h($k) . '"' . ((string) $k === (string) $cur ? ' selected' : '') . '>' . self::h(self::t($l)) . '</option>';
            }
            return $o;
        };
        $def = sys_spamav::Get('sa_default_action') === 'inbox' ? 'Inbox' : 'Spam folder';
        return '<div id="settings" class="sv-form"><h3>' . self::t('Spam settings for') . ' ' . self::h($box) . '</h3>'
            . ($can ? '' : '<p class="text-warning">' . self::t('Your administrator sets these; they can not be changed here.') . '</p>')
            . '<form method="post" action="./?module=spamav&amp;action=SavePrefs&amp;edit=' . rawurlencode($box) . '#settings"><input type="hidden" name="inMailbox" value="' . self::h($box) . '">'
            . '<table class="table"><tr><th style="width: 220px;">' . self::t('Spam filter') . '</th><td><label style="font-weight: normal;"><input type="checkbox" name="inEnabled" value="1"' . ($p['enabled'] ? ' checked' : '') . $dis . '> ' . self::t('Check this mailbox for spam') . '</label>'
            . '<br><span class="text-muted">' . self::t('Viruses are always blocked, whatever is chosen here.') . '</span></td></tr>'
            . '<tr><th>' . self::t('Sensitivity') . '</th><td><select class="form-control" name="inScore" style="max-width: 300px;"' . $dis . '>' . $opt(sys_spamav::Sensitivities(), $p['score']) . '</select>'
            . '<span class="text-muted">' . self::t('Stricter catches more spam, but sometimes also good mail.') . '</span></td></tr>'
            . '<tr><th>' . self::t('Spam goes to') . '</th><td><select class="form-control" name="inAction" style="max-width: 300px;"' . $dis . '>' . $opt(array('' => 'Server default (' . $def . ')', 'folder' => 'The Spam folder', 'inbox' => 'The inbox, marked as spam'), $p['action']) . '</select></td></tr>'
            . '<tr><th>' . self::t('Very high scores') . '</th><td><label style="font-weight: normal;"><input type="checkbox" name="inDeleteHigh" value="1"' . ($p['delete_high'] ? ' checked' : '') . $dis . '> ' . self::t('Delete spam scoring 15 or more instead of keeping it') . '</label></td></tr>'
            . '<tr><th>' . self::t('Allowed senders') . '</th><td><textarea class="form-control" name="inAllow" rows="3" style="max-width: 420px; font-family: monospace;"' . $dis . '>' . self::h($p['allow']) . '</textarea>'
            . '<span class="text-muted">' . self::t('Never spam. One per line: an address, or everyone at a domain (example.com or *@example.com).') . '</span></td></tr>'
            . '<tr><th>' . self::t('Blocked senders') . '</th><td><textarea class="form-control" name="inBlock" rows="3" style="max-width: 420px; font-family: monospace;"' . $dis . '>' . self::h($p['block']) . '</textarea>'
            . '<span class="text-muted">' . self::t('Always spam.') . '</span></td></tr></table>'
            . runtime_csfr::Token() . ($can ? '<button class="btn btn-primary" type="submit">' . self::t('Save') . '</button> ' : '')
            . '<a class="btn btn-default" href="./?module=spamav">' . self::t('Close') . '</a></form></div>';
    }

    static function Activity()
    {
        global $zdbh, $controller;
        $acc = (int) self::Me()['userid'];
        $box = strtolower((string) $controller->GetControllerRequest('URL', 'box'));
        $kind = (string) $controller->GetControllerRequest('URL', 'kind');
        $sql = "SELECT * FROM x_spamav_events WHERE ev_acc_fk = :a";
        $args = array(':a' => $acc);
        if ($box !== '' && self::Mine($box)) {
            $sql .= " AND (ev_mailbox_vc = :m OR ev_sender_vc = :m2)";
            $args[':m'] = $box;
            $args[':m2'] = $box;
        }
        if (in_array($kind, array('spam', 'virus'), true)) {
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
        $link = function ($p) use ($box, $kind) {
            return './?module=spamav&amp;box=' . rawurlencode($box) . '&amp;kind=' . rawurlencode($kind) . '&amp;page=' . (int) $p . '#activity';
        };
        $nav = '<div class="sv-nav"><span class="text-muted">' . number_format($total) . ' ' . self::t($total === 1 ? 'entry' : 'entries')
            . ($pages > 1 ? ' &middot; ' . self::t('page') . ' ' . $page . ' ' . self::t('of') . ' ' . $pages : '') . '</span>'
            . ($pages > 1 ? ' <span class="sv-pages">'
                . ($page > 1 ? '<a class="btn btn-default btn-sm" href="' . $link(1) . '">&laquo; ' . self::t('Newest') . '</a> <a class="btn btn-default btn-sm" href="' . $link($page - 1) . '">&lsaquo; ' . self::t('Newer') . '</a> ' : '')
                . ($page < $pages ? '<a class="btn btn-default btn-sm" href="' . $link($page + 1) . '">' . self::t('Older') . ' &rsaquo;</a> <a class="btn btn-default btn-sm" href="' . $link($pages) . '">' . self::t('Oldest') . ' &raquo;</a>' : '')
                . '</span>' : '') . '</div>';
        $opts = '<option value="">' . self::t('All mailboxes') . '</option>';
        foreach (self::Mailboxes() as $b) {
            $opts .= '<option value="' . self::h($b) . '"' . ($b === $box ? ' selected' : '') . '>' . self::h($b) . '</option>';
        }
        $filter = '<form method="get" class="form-inline" style="margin-bottom: 10px;" id="activity"><input type="hidden" name="module" value="spamav">'
            . '<select class="form-control" name="box" style="display:inline-block;width:auto;">' . $opts . '</select> <select class="form-control" name="kind" style="display:inline-block;width:auto;"><option value="">' . self::t('Spam and viruses') . '</option>'
            . '<option value="spam"' . ($kind === 'spam' ? ' selected' : '') . '>' . self::t('Spam') . '</option><option value="virus"' . ($kind === 'virus' ? ' selected' : '') . '>' . self::t('Viruses') . '</option></select> '
            . '<button class="btn btn-default" type="submit">' . self::t('Show') . '</button></form>';
        if (!$rows) {
            return $filter . '<p class="text-muted">' . self::t('Nothing caught yet. Spam and viruses sent to your mailboxes appear here within 5 minutes.') . '</p>';
        }
        $actions = array('marked' => 'Marked as spam', 'rejected' => 'Refused (never delivered)', 'quarantined' => 'Held by the administrator', 'dropped' => 'Deleted');
        $out = $filter . $nav . '<div class="sv-scroll"><table class="table table-striped table-condensed"><thead><tr><th>' . self::t('When') . '</th><th>' . self::t('Mailbox') . '</th><th>' . self::t('From') . '</th><th>' . self::t('What') . '</th><th>' . self::t('What happened') . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $what = ($r['ev_kind_vc'] === 'virus')
                ? ui_status::Bad('Virus') . ' ' . self::t('Virus') . ': <strong>' . self::h($r['ev_detail_vc']) . '</strong>' . ($r['ev_subject_vc'] !== '' ? '<br><span class="text-muted">' . self::t('Subject') . ': ' . self::h($r['ev_subject_vc']) . '</span>' : '')
                : ui_status::Warn('Spam') . ' ' . self::t('Spam') . ($r['ev_score_vc'] !== '' ? ', ' . self::t('score') . ' ' . self::h($r['ev_score_vc']) : '')
                  . ($r['ev_detail_vc'] !== '' ? '<br><span class="text-muted" style="font-size: 12px;">' . self::h($r['ev_detail_vc']) . '</span>' : '');
            $out .= '<tr><td style="white-space: nowrap;">' . self::h(date('Y-m-d H:i', (int) $r['ev_ts'])) . '</td><td>' . self::h($r['ev_mailbox_vc']) . ($r['ev_direction_vc'] === 'out' ? ' <span class="label label-default">' . self::t('sent by you') . '</span>' : '') . '</td>'
                . '<td>' . self::h($r['ev_sender_vc'] !== '' ? $r['ev_sender_vc'] : '-') . '</td><td>' . $what . '</td><td>' . self::h(self::t($actions[$r['ev_action_vc']] ?? $r['ev_action_vc'])) . '</td></tr>';
        }
        return $out . '</tbody></table></div>' . ($pages > 1 ? $nav : '');
    }

    static function getPage()
    {
        sys_spamav::Ensure();
        $off = !sys_spamav::On('sa_enabled') || !sys_spamav::On('clam_enabled');
        return '<style>.sv-cards{display:flex;gap:14px;flex-wrap:wrap;margin:8px 0 6px}.sv-card{flex:1 1 160px;border:1px solid #ddd;border-radius:6px;padding:12px 16px;background:#fff}'
            . '.sv-num{font-size:26px;font-weight:600}.sv-label{color:#777}.sv-card.is-spam .sv-num{color:#a86b00}.sv-card.is-virus .sv-num{color:#c62f2b}.sv-form{border:1px solid #ddd;border-radius:6px;padding:4px 16px 14px;margin:10px 0 20px;background:#fafafa}'
            . '.sv-scroll{max-height:620px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;margin:6px 0}.sv-scroll table{margin:0}'
            . '.sv-scroll thead th{position:sticky;top:0;background:#f5f5f5;z-index:1;box-shadow:0 1px 0 #ddd}'
            . '.sv-nav{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin:6px 0}</style>'
            . ($off ? '<p class="text-muted">' . ui_status::Warn('Off') . ' ' . self::h(self::t(!sys_spamav::On('sa_enabled') && !sys_spamav::On('clam_enabled') ? 'Spam and virus filtering are switched off on this server.' : (!sys_spamav::On('sa_enabled') ? 'Spam filtering is switched off on this server.' : 'Virus scanning is switched off on this server.'))) . '</p>' : '')
            . self::Totals()
            . '<h2>' . self::t('Your mailboxes') . '</h2>' . self::MailboxTable()
            . '<h2>' . self::t('Recent activity') . '</h2>' . self::Activity();
    }

    static function getResult()
    {
        $out = '';
        foreach (self::$msg as $m) {
            $out .= ui_sysmessage::shout(self::h(self::t($m[1])), $m[0] === 'ok' ? 'zannounceok' : 'zannounceerror');
        }
        return $out;
    }
}
