<?php

/**
 * Support contact: a reseller's (or administrator's) support email, phone, website and short note, which its
 * own clients see on My Account. Set on My Account by any account whose group has Manage Clients
 * (so it can have clients); a client sees its own reseller's, and nothing when that one is empty.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-07
 * Updated by RusTus, 2026-11-09
 */
class ctrl_support
{
    /** Longest note (characters). */
    const NOTE_MAX = 200;

    /** Longest phone number (characters). */
    const PHONE_MAX = 40;

    /** Longest website address (characters). */
    const WEBSITE_MAX = 255;

    /**
     * Create the x_support_contact table if missing (once per request).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if (!$done) {
            $zdbh->exec("CREATE TABLE IF NOT EXISTS x_support_contact (
                sc_acc_fk INT NOT NULL PRIMARY KEY, sc_email_vc VARCHAR(255) NOT NULL DEFAULT '', sc_phone_vc VARCHAR(40) NOT NULL DEFAULT '',
                sc_note_vc VARCHAR(255) NOT NULL DEFAULT '', sc_website_vc VARCHAR(255) NOT NULL DEFAULT '', sc_updated_ts INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // the website came later: added to a table made before it
            if (!$zdbh->query("SHOW COLUMNS FROM x_support_contact LIKE 'sc_website_vc'")->fetch()) {
                $zdbh->exec("ALTER TABLE x_support_contact ADD COLUMN sc_website_vc VARCHAR(255) NOT NULL DEFAULT '' AFTER sc_note_vc");
            }
            $done = true;
        }
    }

    /**
     * May this account set a support contact? (Its group has Manage Clients: it can have clients.)
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return bool
     */
    static function CanSet($uid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts a JOIN x_permissions p ON p.pe_group_fk = a.ac_group_fk
            JOIN x_modules m ON m.mo_id_pk = p.pe_module_fk AND m.mo_folder_vc = 'manage_clients' AND m.mo_enabled_en = 'true'
            WHERE a.ac_id_pk = :id AND a.ac_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $uid));
        return (int) $q->fetchColumn() > 0;
    }

    /**
     * An account's own support contact.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return array{email:string, phone:string, website:string, note:string} Empty strings when it has none.
     */
    static function Get($uid)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT sc_email_vc, sc_phone_vc, sc_website_vc, sc_note_vc FROM x_support_contact WHERE sc_acc_fk = :id");
        $q->execute(array(':id' => (int) $uid));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return array('email' => $r ? (string) $r['sc_email_vc'] : '', 'phone' => $r ? (string) $r['sc_phone_vc'] : '',
            'website' => $r ? (string) $r['sc_website_vc'] : '', 'note' => $r ? (string) $r['sc_note_vc'] : '');
    }

    /**
     * A website address as saved: http(s) only; "example.com" becomes "https://example.com".
     *
     * @param string $url What was typed.
     * @return string|false The address, '' for none, or false when it is not a web address.
     */
    static function CleanWebsite($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            $url = 'https://' . $url;
        }
        $p = parse_url($url);
        if (strlen($url) > self::WEBSITE_MAX || preg_match('~[\s"\'<>\\\\`]~', $url) || filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($p)
            || !in_array(strtolower((string) ($p['scheme'] ?? '')), array('http', 'https'), true) || empty($p['host'])
            || isset($p['user']) || isset($p['pass']) || !preg_match('/^[a-z0-9.-]+$/i', $p['host']) || strpos($p['host'], '.') === false) {
            return false;
        }
        return $url;
    }

    /**
     * Check and save an account's support contact (all three empty: removed).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param string $email The support email ('' for none).
     * @param string $phone The phone number ('' for none).
     * @param string $note The note ('' for none; one line).
     * @param string $website The website ('' for none; http or https).
     * @return string '' when saved, otherwise what is wrong: 'email', 'phone', 'website' or 'note'.
     */
    static function Save($uid, $email, $phone, $note, $website = '')
    {
        global $zdbh;
        self::Ensure();
        $email = trim((string) $email);
        $phone = trim(preg_replace('/\s+/', ' ', (string) $phone));
        $note = trim(preg_replace('/\s+/u', ' ', (string) $note));
        if ($email !== '' && (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            return 'email';
        }
        if ($phone !== '' && (strlen($phone) > self::PHONE_MAX || !preg_match('/^\+?[0-9 ().\/-]+$/', $phone) || !preg_match('/[0-9]/', $phone))) {
            return 'phone';
        }
        $website = self::CleanWebsite($website);
        if ($website === false) {
            return 'website';
        }
        if (mb_strlen($note) > self::NOTE_MAX) {
            return 'note';
        }
        if ($email === '' && $phone === '' && $website === '' && $note === '') {
            $zdbh->prepare("DELETE FROM x_support_contact WHERE sc_acc_fk = :id")->execute(array(':id' => (int) $uid));
            return '';
        }
        $zdbh->prepare("REPLACE INTO x_support_contact (sc_acc_fk, sc_email_vc, sc_phone_vc, sc_website_vc, sc_note_vc, sc_updated_ts) VALUES (:id, :e, :p, :w, :n, :t)")
            ->execute(array(':id' => (int) $uid, ':e' => $email, ':p' => $phone, ':w' => $website, ':n' => $note, ':t' => time()));
        return '';
    }

    /**
     * The support contact an account sees: its own reseller's (never its own; nothing when that one
     * set none).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return array{email:string, phone:string, website:string, note:string}|null Null when there is nothing to show.
     */
    static function ForClient($uid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT ac_reseller_fk FROM x_accounts WHERE ac_id_pk = :id");
        $q->execute(array(':id' => (int) $uid));
        $reseller = (int) $q->fetchColumn();
        if ($reseller <= 0 || $reseller === (int) $uid) {
            return null;
        }
        $c = self::Get($reseller);
        return ($c['email'] === '' && $c['phone'] === '' && $c['website'] === '' && $c['note'] === '') ? null : $c;
    }

    /**
     * A note as HTML: escaped, with http(s) addresses made links (opened in a new tab).
     *
     * @param string $note The note.
     * @return string HTML.
     */
    static function NoteHtml($note)
    {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $out = '';
        foreach (preg_split('~(https?://[^\s<>"\']+)~i', (string) $note, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part) {
            if ($i % 2 === 1) {
                $url = rtrim($part, '.,;:!?)');
                $tail = substr($part, strlen($url));
                $out .= '<a href="' . $h($url) . '" target="_blank" rel="noopener noreferrer">' . $h($url) . '</a>' . $h($tail);
            } else {
                $out .= $h($part);
            }
        }
        return $out;
    }
}
