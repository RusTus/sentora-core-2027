<?php

/**
 * Notices shown at the top of the panel: from the admin or a reseller to its clients (Client Notice
 * Manager), or from the panel's own code. They are kept in x_notices.
 *
 * Panel code sends one like this:
 *
 *     // to one account; sending again with the same key replaces it instead of adding another
 *     ctrl_notices::ToUser($uid, 'Your disk space is **90%** used.', array('type' => 'warning', 'key' => 'quota-90-' . $uid));
 *     // to every account, for a time
 *     ctrl_notices::ToEveryone('Planned maintenance tonight from 02:00 to 04:00.', array('type' => 'maintenance', 'end' => strtotime('tomorrow 04:00')));
 *     // take it away again
 *     ctrl_notices::Withdraw('quota-90-' . $uid);
 *
 * The text is plain: line breaks are kept, web addresses, [text](https://address) and [text](./?module=name) (a panel page) become links,
 * **text** is bold and lines starting with "- " are a list (see ui_tpl_notice::Format()).
 * The hooks OnBeforeSendNotice and OnAfterSendNotice run around each save, with the notice in
 * ctrl_notices::$last. From the shell, sentora-notice (bin/sentora-notice) does the same.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
class ctrl_notices
{

    /**
     * The kinds of notice, in the order they are shown (most urgent first).
     */
    const TYPES = array('outage', 'warning', 'maintenance', 'info');

    /**
     * Who a notice is for: every account, the clients of the resellers in its targets (the sender
     * when there are none), the accounts on the packages or in the groups in its targets, or the
     * one account in its targets.
     */
    const SCOPES = array('everyone', 'clients', 'packages', 'groups', 'user');

    /**
     * The longest text and title, in characters.
     */
    const MAX_BODY = 2000;
    const MAX_TITLE = 120;

    /**
     * The notice being saved, for the OnBeforeSendNotice and OnAfterSendNotice hooks (with its 'id' after).
     *
     * @var array<string, mixed>|null
     */
    static $last = null;

    /**
     * Why the last Send() refused, in English ('' when it did not).
     *
     * @var string
     */
    static $error = '';

    /**
     * Whether x_notices was checked in this request.
     *
     * @var bool
     */
    private static $ready = false;

    /**
     * Account details by ID (group and reseller), for matching.
     *
     * @var array<int, array<string, int>|false>
     */
    private static $accounts = array();

    /**
     * Make sure x_notices exists. The first time, the notice each account kept in
     * x_accounts.ac_notice_tx (the old single notice) becomes a notice to its clients.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Ready()
    {
        global $zdbh;
        if (self::$ready) {
            return;
        }
        self::$ready = true;
        if ($zdbh->query("SHOW TABLES LIKE 'x_notices'")->fetchColumn()) {
            return;
        }
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_notices (
            nt_id_pk INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nt_acc_fk INT NOT NULL DEFAULT 0,
            nt_scope_vc VARCHAR(10) NOT NULL DEFAULT 'clients',
            nt_targets_vc VARCHAR(2000) NOT NULL DEFAULT '',
            nt_type_vc VARCHAR(12) NOT NULL DEFAULT 'info',
            nt_title_vc VARCHAR(120) NOT NULL DEFAULT '',
            nt_body_tx TEXT NOT NULL,
            nt_closable_in TINYINT(1) NOT NULL DEFAULT 1,
            nt_start_ts INT NULL DEFAULT NULL,
            nt_end_ts INT NULL DEFAULT NULL,
            nt_key_vc VARCHAR(100) NULL DEFAULT NULL,
            nt_source_vc VARCHAR(50) NOT NULL DEFAULT '',
            nt_created_ts INT NULL DEFAULT NULL,
            nt_updated_ts INT NULL DEFAULT NULL,
            nt_deleted_ts INT NULL DEFAULT NULL,
            PRIMARY KEY (nt_id_pk),
            KEY nt_acc_fk (nt_acc_fk),
            KEY nt_key_vc (nt_key_vc)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $old = $zdbh->query("SELECT ac_id_pk, ac_notice_tx FROM x_accounts WHERE ac_deleted_ts IS NULL AND ac_notice_tx IS NOT NULL AND ac_notice_tx <> ''")->fetchAll(PDO::FETCH_NUM);
        $add = $zdbh->prepare("INSERT INTO x_notices (nt_acc_fk, nt_scope_vc, nt_type_vc, nt_body_tx, nt_source_vc, nt_created_ts, nt_updated_ts) VALUES (:a, 'clients', 'info', :b, 'client_notices', :t, :t2)");
        foreach ($old as $r) {
            $text = mb_substr(ui_tpl_notice::Text($r[1]), 0, self::MAX_BODY, 'UTF-8');
            if ($text !== '') {
                $add->execute(array(':a' => (int) $r[0], ':b' => $text, ':t' => time(), ':t2' => time()));
            }
        }
    }

    /**
     * Save a notice (new, or the one with the same 'id', or with the same 'key' from the same sender).
     *
     * Saving a notice exactly as it is changes nothing (no hooks, same version), so code may send
     * the same notice every day and it stays closed for those who closed it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array<string, mixed> $n 'body' (required), and optionally:
     *        'type' (see TYPES, default 'info'), 'title' (default: none, the type's name is shown),
     *        'scope' (see SCOPES, default 'clients'), 'targets' (IDs for the scope: reseller, package, group or account IDs),
     *        'from' (sending account ID, 0 = the panel; default 0), 'closable' (true: closed for good, default;
     *        'login': closed until the account's next login, so it comes back each time; false: can not be closed),
     *        'start' and 'end' (Unix times, null = now / until removed), 'key' (replace the sender's notice with this key),
     *        'source' (who sent it, such as a module name), 'id' (change this notice).
     * @return int|false The notice ID, or false when refused (the reason in $error).
     */
    static function Send(array $n)
    {
        global $zdbh;
        self::Ready();
        self::$error = '';
        $type = isset($n['type']) ? (string) $n['type'] : 'info';
        $scope = isset($n['scope']) ? (string) $n['scope'] : 'clients';
        $targets = array_values(array_unique(array_filter(array_map('intval', (array) (isset($n['targets']) ? $n['targets'] : array())), function ($v) { return $v > 0; })));
        $body = trim(str_replace(array("\r\n", "\r"), "\n", (string) (isset($n['body']) ? $n['body'] : '')));
        $title = trim(preg_replace('/\s+/', ' ', (string) (isset($n['title']) ? $n['title'] : '')));
        $from = isset($n['from']) ? (int) $n['from'] : 0;
        $start = isset($n['start']) && $n['start'] !== '' && $n['start'] !== null ? (int) $n['start'] : null;
        $end = isset($n['end']) && $n['end'] !== '' && $n['end'] !== null ? (int) $n['end'] : null;
        if (!in_array($type, self::TYPES, true)) {
            self::$error = 'Unknown notice type.';
        } elseif (!in_array($scope, self::SCOPES, true)) {
            self::$error = 'Unknown audience.';
        } elseif (in_array($scope, array('packages', 'groups', 'user'), true) && !$targets) {
            self::$error = $scope === 'user' ? 'Choose who the notice is for.' : 'Choose at least one ' . ($scope === 'packages' ? 'package.' : 'group.');
        } elseif ($scope === 'user' && count($targets) !== 1) {
            self::$error = 'A personal notice is for one account.';
        } elseif ($body === '') {
            self::$error = 'The notice has no text.';
        } elseif (mb_strlen($body, 'UTF-8') > self::MAX_BODY) {
            self::$error = 'The notice is too long.';
        } elseif (mb_strlen($title, 'UTF-8') > self::MAX_TITLE) {
            self::$error = 'The title is too long.';
        } elseif ($start !== null && $end !== null && $end <= $start) {
            self::$error = 'The notice must end after it starts.';
        }
        if (self::$error !== '') {
            return false;
        }
        $row = array(
            ':acc' => $from, ':scope' => $scope, ':targets' => implode(',', $targets), ':type' => $type, ':title' => $title, ':body' => $body,
            ':closable' => !isset($n['closable']) ? 1 : ($n['closable'] === 'login' ? 2 : ($n['closable'] ? 1 : 0)), ':start' => $start, ':end' => $end,
            ':key' => isset($n['key']) && $n['key'] !== '' ? mb_substr((string) $n['key'], 0, 100, 'UTF-8') : null,
            ':source' => mb_substr((string) (isset($n['source']) ? $n['source'] : ''), 0, 50, 'UTF-8'), ':now' => time(),
        );
        $id = isset($n['id']) ? (int) $n['id'] : 0;
        if (!$id && $row[':key'] !== null) {
            $q = $zdbh->prepare("SELECT nt_id_pk FROM x_notices WHERE nt_key_vc = :k AND nt_acc_fk = :a AND nt_deleted_ts IS NULL ORDER BY nt_id_pk DESC LIMIT 1");
            $q->execute(array(':k' => $row[':key'], ':a' => $from));
            $id = (int) $q->fetchColumn();
        }
        self::$last = array('id' => $id, 'from' => $from, 'scope' => $scope, 'targets' => $targets, 'type' => $type, 'title' => $title, 'body' => $body,
            'closable' => (bool) $row[':closable'], 'reshow' => $row[':closable'] === 2, 'start' => $start, 'end' => $end, 'key' => $row[':key'], 'source' => $row[':source']);
        if ($id) {
            // the same notice sent again (a daily check, a form saved as it was): left as it is, so it
            // keeps its version and stays closed for those who closed it
            $q = $zdbh->prepare("SELECT nt_acc_fk, nt_scope_vc, nt_targets_vc, nt_type_vc, nt_title_vc, nt_body_tx, nt_closable_in, nt_start_ts, nt_end_ts, nt_key_vc, nt_source_vc
                FROM x_notices WHERE nt_id_pk = :id AND nt_deleted_ts IS NULL");
            $q->execute(array(':id' => $id));
            $was = $q->fetch(PDO::FETCH_NUM);
            $now = array($row[':acc'], $row[':scope'], $row[':targets'], $row[':type'], $row[':title'], $row[':body'], $row[':closable'], $row[':start'], $row[':end'], $row[':key'], $row[':source']);
            if ($was && array_map('strval', $was) === array_map('strval', $now)) {
                return $id;
            }
        }
        runtime_hook::Execute('OnBeforeSendNotice');
        if ($id) {
            $q = $zdbh->prepare("UPDATE x_notices SET nt_acc_fk = :acc, nt_scope_vc = :scope, nt_targets_vc = :targets, nt_type_vc = :type, nt_title_vc = :title, nt_body_tx = :body,
                nt_closable_in = :closable, nt_start_ts = :start, nt_end_ts = :end, nt_key_vc = :key, nt_source_vc = :source, nt_updated_ts = :now WHERE nt_id_pk = :id AND nt_deleted_ts IS NULL");
            $q->execute($row + array(':id' => $id));
        } else {
            $q = $zdbh->prepare("INSERT INTO x_notices (nt_acc_fk, nt_scope_vc, nt_targets_vc, nt_type_vc, nt_title_vc, nt_body_tx, nt_closable_in, nt_start_ts, nt_end_ts, nt_key_vc, nt_source_vc, nt_created_ts, nt_updated_ts)
                VALUES (:acc, :scope, :targets, :type, :title, :body, :closable, :start, :end, :key, :source, :now, :now2)");
            $q->execute($row + array(':now2' => $row[':now']));
            $id = (int) $zdbh->lastInsertId();
        }
        self::$last['id'] = $id;
        runtime_hook::Execute('OnAfterSendNotice');
        return $id;
    }

    /**
     * Send a personal notice to one account.
     *
     * @param int $uid The account ID.
     * @param string $body The text.
     * @param array<string, mixed> $o Optional. More fields, as for Send() ('type', 'title', 'end', 'key', 'from', ...).
     * @return int|false The notice ID, or false.
     */
    static function ToUser($uid, $body, array $o = array())
    {
        return self::Send(array('scope' => 'user', 'targets' => array((int) $uid), 'body' => $body) + $o);
    }

    /**
     * Send a notice to every account on the server.
     *
     * @param string $body The text.
     * @param array<string, mixed> $o Optional. More fields, as for Send().
     * @return int|false The notice ID, or false.
     */
    static function ToEveryone($body, array $o = array())
    {
        return self::Send(array('scope' => 'everyone', 'body' => $body) + $o);
    }

    /**
     * Send a notice to the clients of a reseller (or of the admin).
     *
     * @param int $rid The reseller's account ID.
     * @param string $body The text.
     * @param array<string, mixed> $o Optional. More fields, as for Send().
     * @return int|false The notice ID, or false.
     */
    static function ToClientsOf($rid, $body, array $o = array())
    {
        return self::Send(array('scope' => 'clients', 'targets' => array((int) $rid), 'body' => $body) + $o);
    }

    /**
     * Take away the notices with a key (from any sender, or only from one).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $key The key the notice was sent with.
     * @param int|null $from Optional. Only the notices of this sender (0 = the panel). Default null (any).
     * @return int How many were taken away.
     */
    static function Withdraw($key, $from = null)
    {
        global $zdbh;
        self::Ready();
        $sql = "UPDATE x_notices SET nt_deleted_ts = :t WHERE nt_key_vc = :k AND nt_deleted_ts IS NULL" . ($from === null ? '' : ' AND nt_acc_fk = :a');
        $p = array(':t' => time(), ':k' => (string) $key) + ($from === null ? array() : array(':a' => (int) $from));
        $q = $zdbh->prepare($sql);
        $q->execute($p);
        return $q->rowCount();
    }

    /**
     * Take away one notice.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id The notice ID.
     * @return bool Whether it was there.
     */
    static function Remove($id)
    {
        global $zdbh;
        self::Ready();
        $q = $zdbh->prepare("UPDATE x_notices SET nt_deleted_ts = :t WHERE nt_id_pk = :id AND nt_deleted_ts IS NULL");
        $q->execute(array(':t' => time(), ':id' => (int) $id));
        return $q->rowCount() > 0;
    }

    /**
     * Take away the notices of a deleted account: the ones it sent and the personal ones to it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return int How many were taken away.
     */
    static function ForgetAccount($uid)
    {
        global $zdbh;
        self::Ready();
        $q = $zdbh->prepare("UPDATE x_notices SET nt_deleted_ts = :t WHERE nt_deleted_ts IS NULL AND (nt_acc_fk = :u OR (nt_scope_vc = 'user' AND nt_targets_vc = :u2))");
        $q->execute(array(':t' => time(), ':u' => (int) $uid, ':u2' => (string) (int) $uid));
        unset(self::$accounts[(int) $uid]);
        return $q->rowCount();
    }

    /**
     * One notice.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id The notice ID.
     * @return array<string, mixed>|false The notice (see Row()), or false.
     */
    static function Get($id)
    {
        global $zdbh;
        self::Ready();
        $q = $zdbh->prepare("SELECT * FROM x_notices WHERE nt_id_pk = :id AND nt_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $id));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? self::Row($r) : false;
    }

    /**
     * The notices a sender has (showing, waiting to start, and ended), newest first.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $from The sending account ID (0 = the panel).
     * @return array<int, array<string, mixed>> The notices (see Row()).
     */
    static function From($from)
    {
        global $zdbh;
        self::Ready();
        $q = $zdbh->prepare("SELECT * FROM x_notices WHERE nt_acc_fk = :a AND nt_deleted_ts IS NULL ORDER BY nt_id_pk DESC");
        $q->execute(array(':a' => (int) $from));
        return array_map(array('ctrl_notices', 'Row'), $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The notices an account sees now, most urgent first.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return array<int, array<string, mixed>> The notices (see Row()).
     */
    static function ForAccount($uid)
    {
        global $zdbh;
        self::Ready();
        $me = self::Account($uid);
        if (!$me) {
            return array();
        }
        $q = $zdbh->prepare("SELECT * FROM x_notices WHERE nt_deleted_ts IS NULL AND (nt_start_ts IS NULL OR nt_start_ts <= :t) AND (nt_end_ts IS NULL OR nt_end_ts > :t2)");
        $q->execute(array(':t' => time(), ':t2' => time()));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = self::Row($r);
            if (self::Reaches($n, (int) $uid, $me)) {
                $out[] = $n;
            }
        }
        usort($out, function ($a, $b) {
            $d = array_search($a['type'], ctrl_notices::TYPES, true) - array_search($b['type'], ctrl_notices::TYPES, true);
            return $d !== 0 ? $d : $b['id'] - $a['id'];
        });
        return $out;
    }

    /**
     * Whether a notice reaches an account.
     *
     * @param array<string, mixed> $n The notice (see Row()).
     * @param int $uid The account ID.
     * @param array<string, int> $me The account ('reseller', 'package', 'group'; see Account()).
     * @return bool
     */
    static function Reaches($n, $uid, $me)
    {
        $wide = self::IsAdmin($n['from']);
        switch ($n['scope']) {
            case 'everyone':
                return $wide;
            case 'clients':
                return in_array($me['reseller'], $n['targets'] ? $n['targets'] : array($n['from']), true);
            case 'packages':
                return in_array($me['package'], $n['targets'], true) && ($wide || $me['reseller'] === $n['from']);
            case 'groups':
                return in_array($me['group'], $n['targets'], true) && ($wide || $me['reseller'] === $n['from']);
            case 'user':
                return in_array($uid, $n['targets'], true);
        }
        return false;
    }

    /**
     * How many accounts a notice reaches (not counting its sender).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array<string, mixed> $n The notice (see Row()).
     * @return int
     */
    static function Reach($n)
    {
        global $zdbh;
        $ids = $n['targets'] ? implode(',', array_map('intval', $n['targets'])) : '0';
        $mine = self::IsAdmin($n['from']) ? '' : ' AND ac_reseller_fk = ' . (int) $n['from'];
        switch ($n['scope']) {
            case 'everyone':
                $where = '1 = 1';
                break;
            case 'clients':
                $where = 'ac_reseller_fk IN (' . ($n['targets'] ? $ids : (int) $n['from']) . ')';
                break;
            case 'packages':
                $where = 'ac_package_fk IN (' . $ids . ')' . $mine;
                break;
            case 'groups':
                $where = 'ac_group_fk IN (' . $ids . ')' . $mine;
                break;
            default:
                $where = 'ac_id_pk IN (' . $ids . ')';
        }
        return (int) $zdbh->query("SELECT COUNT(*) FROM x_accounts WHERE ac_deleted_ts IS NULL AND ac_id_pk <> " . (int) $n['from'] . " AND " . $where)->fetchColumn();
    }

    /**
     * Whether a notice is showing now, waiting to start, or has ended.
     *
     * @param array<string, mixed> $n The notice (see Row()).
     * @return string 'live', 'scheduled' or 'ended'.
     */
    static function State($n)
    {
        $now = time();
        if ($n['end'] !== null && $n['end'] <= $now) {
            return 'ended';
        }
        return $n['start'] !== null && $n['start'] > $now ? 'scheduled' : 'live';
    }

    /**
     * Whether an account is an administrator (or 0, the panel itself): its notices may reach any account.
     *
     * @param int $uid The account ID.
     * @return bool
     */
    static function IsAdmin($uid)
    {
        if ((int) $uid === 0) {
            return true;
        }
        $a = self::Account($uid);
        return $a && $a['group'] === 1;
    }

    /**
     * An account's reseller, package and group (cached).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return array<string, int>|false 'reseller', 'package', 'group', or false when there is no such account.
     */
    static function Account($uid)
    {
        global $zdbh;
        $uid = (int) $uid;
        if (!array_key_exists($uid, self::$accounts)) {
            $q = $zdbh->prepare("SELECT ac_reseller_fk, ac_package_fk, ac_group_fk FROM x_accounts WHERE ac_id_pk = :u AND ac_deleted_ts IS NULL");
            $q->execute(array(':u' => $uid));
            $r = $q->fetch(PDO::FETCH_NUM);
            self::$accounts[$uid] = $r ? array('reseller' => (int) $r[0], 'package' => (int) $r[1], 'group' => (int) $r[2]) : false;
        }
        return self::$accounts[$uid];
    }

    /**
     * A notice row as an array with short keys.
     *
     * @param array<string, mixed> $r The x_notices row.
     * @return array<string, mixed> 'id', 'from', 'scope', 'targets' (int[]), 'type', 'title', 'body', 'closable' (bool),
     *         'reshow' (bool: closing it lasts until the next login only), 'start', 'end' (int|null), 'key', 'source', 'created', 'updated'.
     */
    static function Row($r)
    {
        return array(
            'id' => (int) $r['nt_id_pk'], 'from' => (int) $r['nt_acc_fk'], 'scope' => (string) $r['nt_scope_vc'],
            'targets' => $r['nt_targets_vc'] === '' ? array() : array_map('intval', explode(',', $r['nt_targets_vc'])),
            'type' => in_array($r['nt_type_vc'], self::TYPES, true) ? (string) $r['nt_type_vc'] : 'info',
            'title' => (string) $r['nt_title_vc'], 'body' => (string) $r['nt_body_tx'], 'closable' => (int) $r['nt_closable_in'] >= 1,
            'reshow' => (int) $r['nt_closable_in'] === 2,
            'start' => $r['nt_start_ts'] === null ? null : (int) $r['nt_start_ts'], 'end' => $r['nt_end_ts'] === null ? null : (int) $r['nt_end_ts'],
            'key' => $r['nt_key_vc'], 'source' => (string) $r['nt_source_vc'], 'created' => (int) $r['nt_created_ts'], 'updated' => (int) $r['nt_updated_ts'],
        );
    }
}
