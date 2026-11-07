<?php

/**
 * Core class: SpamAssassin and ClamAV settings, configuration files and mail log events.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * SpamAV: SpamAssassin and ClamAV for the mail server.
 *
 * Mail flow: Postfix hands each message to two milters while the sender is connected:
 * clamav-milter (viruses: rejected, quarantined or dropped) and spamass-milter (spam:
 * scored by spamd, X-Spam-* headers added, very high scores rejected). If a scanner is
 * down, mail is accepted (milter_default_action = accept). On delivery a per-mailbox
 * Sieve script (run before the user's own filters) files spam into the Spam folder or
 * leaves it in the inbox. spamd reads per-mailbox settings (sensitivity, allowed and
 * blocked senders) from the sentora_spamav database.
 *
 * The administrator's settings (SpamAV Admin) are x_settings rows of module 'SpamAV
 * Admin'; users' settings (SpamAV) are x_spamav_prefs rows. The daemon (root) applies
 * them: modules/spamav_admin/hooks/OnDaemonRun.hook.php.
 */
class sys_spamav
{
    const MODULE = 'SpamAV Admin';
    const STATUS = '/var/lib/sentora/spamav-status.json';
    const REQUEST = '/var/lib/sentora/tmp/spamav-request.json';
    const SIEVE_DIR = '/var/sentora/sieve/spamav/';
    const SA_DB = 'sentora_spamav';

    /**
     * Return the definitions of the administrator's settings.
     *
     * name => array(section, label, type, default, help, choices or range)
     * types: bool, int (min, max), float (min, max), select (value => label), text (max length, pattern), list (one per line)
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: mixed}> Setting name => definition.
     */
    static function Definitions()
    {
        $mem = self::MemoryMB();
        return array(
            // --- ClamAV ---
            'clam_enabled' => array('clamav', 'Scan mail for viruses', 'bool', $mem >= 1900 ? 'true' : 'false',
                // (%s: this server's memory in GB, filled in after translating)
                $mem > 0 ? 'ClamAV checks every message and its attachments. Needs about 1.5 GB of memory for its signatures (this server has %s GB).'
                    : 'ClamAV checks every message and its attachments. Needs about 1.5 GB of memory for its signatures.', null),
            'clam_on_infected' => array('clamav', 'Infected mail', 'select', 'Reject',
                'Reject: refused while the sender is connected, so they are told. Quarantine: kept on hold in the mail queue for the administrator. Drop: accepted and thrown away.',
                array('Reject' => 'Reject', 'Quarantine' => 'Quarantine (hold in the mail queue)', 'Blackhole' => 'Drop silently')),
            'clam_on_fail' => array('clamav', 'If ClamAV is not working', 'select', 'Accept',
                'Accept: mail is delivered unscanned. Try later: the sender\'s server is asked to try again later.', array('Accept' => 'Accept the mail', 'Defer' => 'Ask the sender to try again later')),
            'clam_scan_outgoing' => array('clamav', 'Scan outgoing mail', 'bool', 'true', 'Also scan mail your users send (stops infected computers sending viruses through this server).', null),
            'clam_add_header' => array('clamav', 'Add a scanned header', 'bool', 'true', 'Adds X-Virus-Scanned to scanned mail.', null),
            'clam_max_file_size' => array('clamav', 'Largest file scanned (MB)', 'int', '25', 'Larger attachments are not scanned.', array(1, 500)),
            'clam_max_scan_size' => array('clamav', 'Most data scanned per message (MB)', 'int', '100', 'Including what is unpacked from archives.', array(1, 2000)),
            'clam_max_recursion' => array('clamav', 'Archive depth', 'int', '17', 'How many archives inside archives are opened.', array(1, 64)),
            'clam_max_files' => array('clamav', 'Most files per archive', 'int', '10000', '', array(10, 100000)),
            'clam_scan_archive' => array('clamav', 'Scan inside archives', 'bool', 'true', 'zip, rar, 7z, tar and similar.', null),
            'clam_scan_pdf' => array('clamav', 'Scan PDF files', 'bool', 'true', '', null),
            'clam_scan_ole2' => array('clamav', 'Scan Office files', 'bool', 'true', 'Word, Excel and other Office documents.', null),
            'clam_block_macros' => array('clamav', 'Block Office files with macros', 'bool', 'false', 'Treats every Office file with macros as infected. Safer, but blocks legitimate spreadsheets with macros too.', null),
            'clam_scan_html' => array('clamav', 'Scan HTML', 'bool', 'true', '', null),
            'clam_phishing_sigs' => array('clamav', 'Phishing signatures', 'bool', 'true', 'Detects known phishing mail.', null),
            'clam_phishing_urls' => array('clamav', 'Check links for phishing', 'bool', 'true', 'Looks for links that pretend to go somewhere else.', null),
            'clam_heuristic' => array('clamav', 'Heuristic alerts', 'bool', 'true', 'Also flags suspicious files that match no signature.', null),
            'clam_alert_encrypted' => array('clamav', 'Block password-protected archives', 'bool', 'false', 'They can not be scanned; viruses are often sent this way.', null),
            'clam_db_checks' => array('clamav', 'Signature updates per day', 'int', '12', 'How often freshclam checks for new virus signatures.', array(1, 50)),
            'clam_threads' => array('clamav', 'Scanner threads', 'int', '10', 'Messages scanned at the same time.', array(1, 50)),
            // --- SpamAssassin ---
            'sa_enabled' => array('spamassassin', 'Check mail for spam', 'bool', 'true', 'SpamAssassin scores incoming mail; mail at or above the spam score is marked as spam.', null),
            'sa_required_score' => array('spamassassin', 'Spam score', 'float', '5.0', 'Mail scoring this or more is spam. Lower catches more spam but also more good mail. Users may choose their own if allowed below.', array(1, 20)),
            'sa_reject_score' => array('spamassassin', 'Reject at score', 'float', '15', 'Mail scoring this or more is refused while the sender is connected. 0 never refuses.', array(0, 100)),
            'sa_subject_tag' => array('spamassassin', 'Spam subject tag', 'text', '***SPAM***', 'Put in front of the subject of spam. Empty leaves subjects alone.', array(30, '/^[^\\r\\n\\\\]*$/')),
            'sa_report_safe' => array('spamassassin', 'Spam report', 'select', '0',
                'How spam is marked: with headers only, or with SpamAssassin\'s report and the original attached.', array('0' => 'Headers only (recommended)', '1' => 'Report, original attached', '2' => 'Report, original attached as text')),
            'sa_skip_outgoing' => array('spamassassin', 'Do not check mail users send', 'bool', 'true', 'Mail from logged-in senders (your own users) is not checked for spam.', null),
            'sa_max_size' => array('spamassassin', 'Largest message checked (KB)', 'int', '500', 'Spam is rarely large; bigger messages are passed without checking.', array(50, 20000)),
            'sa_children' => array('spamassassin', 'Checks at the same time', 'int', '5', 'spamd processes. Each uses about 80 MB of memory.', array(1, 30)),
            'sa_network_tests' => array('spamassassin', 'Network tests', 'bool', 'true', 'Block lists and other checks over the internet. Better results; needs working DNS.', null),
            'sa_use_bayes' => array('spamassassin', 'Learn from mail (Bayes)', 'bool', 'true', 'SpamAssassin learns what spam and good mail look like on this server.', null),
            'sa_bayes_auto_learn' => array('spamassassin', 'Learn automatically', 'bool', 'true', 'Learns from mail that is clearly spam or clearly good.', null),
            'sa_auto_learn_spam' => array('spamassassin', 'Learn as spam from score', 'float', '12', '', array(3, 50)),
            'sa_auto_learn_ham' => array('spamassassin', 'Learn as good mail below score', 'float', '0.1', '', array(-10, 3)),
            'sa_trusted_networks' => array('spamassassin', 'Trusted networks', 'list', '', 'Networks of mail servers you trust (one per line, like 192.0.2.0/24). This server itself is always trusted.', null),
            'sa_allow_from' => array('spamassassin', 'Always allow senders', 'list', '', 'Never spam, for every mailbox: one address or pattern per line (like *@partner.com).', null),
            'sa_block_from' => array('spamassassin', 'Always block senders', 'list', '', 'Always spam, for every mailbox.', null),
            'sa_rules_update' => array('spamassassin', 'Update the rules daily', 'bool', 'true', 'Gets new spam rules every day (sa-update).', null),
            'sa_user_settings' => array('spamassassin', 'Let users change their spam settings', 'bool', 'true', 'In Mail > SpamAV: spam filter on or off, sensitivity, Spam folder or inbox, allowed and blocked senders, for their own mailboxes.', null),
            'sa_default_action' => array('spamassassin', 'Where spam goes', 'select', 'folder', 'For mailboxes whose owner has not chosen.', array('folder' => 'The Spam folder', 'inbox' => 'The inbox, marked as spam')),
            'spamav_keep_days' => array('spamassassin', 'Keep activity for (days)', 'int', '30', 'How long the spam and virus activity shown in SpamAV is kept.', array(1, 365)),
        );
    }

    /**
     * Return this server's total memory from /proc/meminfo.
     *
     * @return int Memory in MB, or 0 when unknown.
     */
    static function MemoryMB()
    {
        $m = @file_get_contents('/proc/meminfo');
        return ($m && preg_match('/MemTotal:\s+(\d+)/', $m, $x)) ? (int) round($x[1] / 1024) : 0;
    }

    /**
     * Create the settings rows (with their defaults) and the SpamAV tables when missing.
     *
     * Runs once per request.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $have = $zdbh->query("SELECT so_name_vc FROM x_settings WHERE so_module_vc = '" . self::MODULE . "'")->fetchAll(PDO::FETCH_COLUMN);
        $ins = $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
            VALUES (:n, :c, :v, NULL, :d, '" . self::MODULE . "', 'false')");
        foreach (self::Definitions() as $name => $d) {
            if (!in_array($name, $have, true)) {
                $ins->execute(array(':n' => $name, ':c' => $d[1], ':v' => $d[3], ':d' => $d[4]));
            }
        }
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_spamav_prefs (
            sp_mailbox_vc VARCHAR(255) NOT NULL PRIMARY KEY, sp_acc_fk INT NOT NULL, sp_enabled_in TINYINT NOT NULL DEFAULT 1,
            sp_score_vc VARCHAR(8) NULL, sp_action_vc VARCHAR(10) NULL, sp_delete_high_in TINYINT NOT NULL DEFAULT 0,
            sp_allow_tx TEXT NULL, sp_block_tx TEXT NULL, KEY sp_acc (sp_acc_fk)) DEFAULT CHARSET=utf8");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_spamav_events (
            ev_id_pk BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, ev_ts INT NOT NULL, ev_acc_fk INT NOT NULL DEFAULT 0,
            ev_mailbox_vc VARCHAR(255) NOT NULL DEFAULT '', ev_sender_vc VARCHAR(255) NOT NULL DEFAULT '', ev_kind_vc VARCHAR(10) NOT NULL,
            ev_score_vc VARCHAR(10) NOT NULL DEFAULT '', ev_detail_vc VARCHAR(255) NOT NULL DEFAULT '', ev_action_vc VARCHAR(20) NOT NULL DEFAULT '',
            ev_direction_vc VARCHAR(3) NOT NULL DEFAULT 'in', ev_subject_vc VARCHAR(200) NOT NULL DEFAULT '',
            ev_qid_vc VARCHAR(20) NOT NULL DEFAULT '',
            KEY ev_acc_ts (ev_acc_fk, ev_ts), KEY ev_ts (ev_ts), KEY ev_qid (ev_qid_vc)) DEFAULT CHARSET=utf8");
        // columns added after the table was first made
        $col = $zdbh->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_spamav_events' AND COLUMN_NAME = 'ev_qid_vc'")->fetchColumn();
        if ((int) $col === 0) {
            $zdbh->exec("ALTER TABLE x_spamav_events ADD COLUMN ev_qid_vc VARCHAR(20) NOT NULL DEFAULT '', ADD KEY ev_qid (ev_qid_vc)");
        }
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_spamav_daily (
            dd_day DATE NOT NULL, dd_acc_fk INT NOT NULL, dd_mailbox_vc VARCHAR(255) NOT NULL,
            dd_scanned_in INT NOT NULL DEFAULT 0, dd_spam_in INT NOT NULL DEFAULT 0, dd_virus_in INT NOT NULL DEFAULT 0, dd_rejected_in INT NOT NULL DEFAULT 0,
            PRIMARY KEY (dd_day, dd_acc_fk, dd_mailbox_vc)) DEFAULT CHARSET=utf8");
    }

    /**
     * Return an administrator's setting, or its default when not set.
     *
     * @param string $name The setting name (a key of Definitions()).
     * @return string The value ('' for an unknown setting).
     */
    static function Get($name)
    {
        self::Ensure();
        $defs = self::Definitions();
        $v = ctrl_options::GetSystemOption($name);
        return ($v === false || $v === null) ? (isset($defs[$name]) ? $defs[$name][3] : '') : (string) $v;
    }

    /**
     * Check whether a yes/no setting is on.
     *
     * @param string $name The setting name.
     * @return bool True when the value is 'true'.
     */
    static function On($name)
    {
        return strtolower(self::Get($name)) === 'true';
    }

    /**
     * Check an administrator's value against its definition.
     *
     * @param string $name The setting name.
     * @param mixed $value The submitted value (anything but a string counts as '').
     * @return array{0: bool, 1: string} array(true, clean value) or array(false, error message).
     */
    static function Clean($name, $value)
    {
        $defs = self::Definitions();
        if (!isset($defs[$name])) {
            return array(false, 'unknown setting');
        }
        $d = $defs[$name];
        $value = is_string($value) ? $value : '';
        switch ($d[2]) {
            case 'bool':
                return array(true, ($value === 'true' || $value === '1') ? 'true' : 'false');
            case 'int':
                if (!preg_match('/^-?\d+$/', trim($value)) || (int) $value < $d[5][0] || (int) $value > $d[5][1]) {
                    return array(false, $d[1] . ': a whole number from ' . $d[5][0] . ' to ' . $d[5][1] . '.');
                }
                return array(true, (string) (int) $value);
            case 'float':
                if (!preg_match('/^-?\d+(\.\d+)?$/', trim($value)) || (float) $value < $d[5][0] || (float) $value > $d[5][1]) {
                    return array(false, $d[1] . ': a number from ' . $d[5][0] . ' to ' . $d[5][1] . '.');
                }
                return array(true, rtrim(rtrim(sprintf('%.2f', (float) $value), '0'), '.') ?: '0');
            case 'select':
                return isset($d[5][$value]) ? array(true, $value) : array(false, $d[1] . ': choose one of the options.');
            case 'text':
                if (strlen($value) > $d[5][0] || !preg_match($d[5][1], $value)) {
                    return array(false, $d[1] . ': at most ' . $d[5][0] . ' characters, on one line.');
                }
                return array(true, $value);
            case 'list':
                $out = array();
                foreach (preg_split('/[\r\n]+/', $value) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    if ($name !== 'sa_trusted_networks') {
                        $line = self::NormalSender($line);
                    }
                    $ok = ($name === 'sa_trusted_networks') ? self::ValidNetwork($line) : self::ValidSenderPattern($line);
                    if (!$ok) {
                        return array(false, $d[1] . ': "' . substr($line, 0, 60) . '" is not ' . ($name === 'sa_trusted_networks' ? 'a network like 192.0.2.0/24.' : 'an address or a pattern like *@example.com.'));
                    }
                    $out[strtolower($line)] = true;
                }
                if (count($out) > 500) {
                    return array(false, $d[1] . ': at most 500 lines.');
                }
                return array(true, implode("\n", array_keys($out)));
        }
        return array(false, 'unknown type');
    }

    /**
     * Check an address or pattern for allowed / blocked senders: bob@example.com, *@example.com, *.example.com.
     *
     * @param mixed $p The pattern (only strings pass).
     * @return bool True when valid.
     */
    static function ValidSenderPattern($p)
    {
        return is_string($p) && strlen($p) <= 200 && preg_match('/^[A-Za-z0-9.*?_+=-]*@[A-Za-z0-9*?-]+(\.[A-Za-z0-9*?-]+)+$/', $p) === 1;
    }

    /**
     * Normalise a sender pattern: "example.com" means everyone at example.com.
     *
     * @param string $p The pattern as entered.
     * @return string Lower-case pattern; a bare domain becomes '*@domain'.
     */
    static function NormalSender($p)
    {
        $p = strtolower(trim((string) $p));
        return (strpos($p, '@') === false && preg_match('/^[a-z0-9*?-]+(\.[a-z0-9*?-]+)+$/', $p)) ? '*@' . $p : $p;
    }

    /**
     * Check a network for trusted_networks: an IP address with an optional /prefix.
     *
     * @param string $n The network, e.g. '192.0.2.0/24'.
     * @return bool True when valid.
     */
    static function ValidNetwork($n)
    {
        if (!preg_match('#^([0-9a-fA-F:.]+)(/(\d{1,3}))?$#', $n, $m) || filter_var($m[1], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $max = (strpos($m[1], ':') !== false) ? 128 : 32;
        return !isset($m[3]) || (int) $m[3] <= $max;
    }

    /**
     * Return a mailbox's settings (with the server's defaults where it has none).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $mailbox The mail address.
     * @return array{enabled: bool, score: string, action: string, delete_high: bool, allow: string, block: string, saved: bool} 'saved' is false when the mailbox has no row.
     */
    static function Prefs($mailbox)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_spamav_prefs WHERE sp_mailbox_vc = :m");
        $q->execute(array(':m' => strtolower($mailbox)));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return array('enabled' => $r ? (int) $r['sp_enabled_in'] === 1 : true, 'score' => $r && $r['sp_score_vc'] !== null ? $r['sp_score_vc'] : '',
            'action' => $r && $r['sp_action_vc'] !== null ? $r['sp_action_vc'] : '', 'delete_high' => $r ? (int) $r['sp_delete_high_in'] === 1 : false,
            'allow' => $r ? (string) $r['sp_allow_tx'] : '', 'block' => $r ? (string) $r['sp_block_tx'] : '', 'saved' => (bool) $r);
    }

    /**
     * Return the sensitivity choices offered to users.
     *
     * @return array<string, string> Score => label ('' is the server default).
     */
    static function Sensitivities()
    {
        return array('' => 'Server default (' . self::Get('sa_required_score') . ')', '3' => 'Strict (3)', '4' => 'Fairly strict (4)', '5' => 'Normal (5)',
            '6' => 'Relaxed (6)', '8' => 'Very relaxed (8)', '10' => 'Only obvious spam (10)');
    }

    // --- the configuration files (written by the daemon) -------------------------------------

    /**
     * Return a yes/no setting as 'yes' or 'no'.
     *
     * @param string $name The setting name.
     * @return string 'yes' or 'no'.
     */
    static function YesNo($name)
    {
        return self::On($name) ? 'yes' : 'no';
    }

    /**
     * Build /etc/clamav/clamd.conf from the settings.
     *
     * @return string The file's contents.
     */
    static function ClamdConf()
    {
        return "# Written by Sentora (Admin > SpamAV Admin): changes here are replaced\n"
            . "LocalSocket /run/clamav/clamd.ctl\nFixStaleSocket true\nLocalSocketGroup clamav\nLocalSocketMode 666\nUser clamav\n"
            . "ScanMail true\nScanArchive " . (self::On('clam_scan_archive') ? 'true' : 'false') . "\nScanPDF " . (self::On('clam_scan_pdf') ? 'true' : 'false')
            . "\nScanOLE2 " . (self::On('clam_scan_ole2') ? 'true' : 'false') . "\nOLE2BlockMacros " . (self::On('clam_block_macros') ? 'true' : 'false')
            . "\nScanHTML " . (self::On('clam_scan_html') ? 'true' : 'false') . "\nPhishingSignatures " . (self::On('clam_phishing_sigs') ? 'true' : 'false')
            . "\nPhishingScanURLs " . (self::On('clam_phishing_urls') ? 'true' : 'false') . "\nHeuristicAlerts " . (self::On('clam_heuristic') ? 'true' : 'false')
            . "\nAlertEncrypted " . (self::On('clam_alert_encrypted') ? 'true' : 'false') . "\nAlertEncryptedArchive " . (self::On('clam_alert_encrypted') ? 'true' : 'false')
            . "\nScanPE true\nScanELF true\nDetectPUA false\nAlertBrokenExecutables false\n"
            . "MaxFileSize " . (int) self::Get('clam_max_file_size') . "M\nMaxScanSize " . (int) self::Get('clam_max_scan_size') . "M\nStreamMaxLength " . (int) self::Get('clam_max_scan_size') . "M\n"
            . "MaxRecursion " . (int) self::Get('clam_max_recursion') . "\nMaxFiles " . (int) self::Get('clam_max_files') . "\nMaxThreads " . (int) self::Get('clam_threads') . "\n"
            . "ReadTimeout 180\nCommandReadTimeout 30\nSendBufTimeout 200\nMaxQueue 100\nIdleTimeout 30\nSelfCheck 3600\nForeground false\n"
            . "LogSyslog true\nLogFacility LOG_LOCAL6\nLogTime true\nLogFile /var/log/clamav/clamav.log\nLogFileMaxSize 0\nLogRotate true\nExtendedDetectionInfo true\n"
            . "DatabaseDirectory /var/lib/clamav\nOfficialDatabaseOnly false\nBytecode true\nBytecodeSecurity TrustSigned\nBytecodeTimeout 60000\n";
    }

    /**
     * Build /etc/clamav/clamav-milter.conf from the settings.
     *
     * @return string The file's contents.
     */
    static function MilterConf()
    {
        return "# Written by Sentora (Admin > SpamAV Admin): changes here are replaced\n"
            . "MilterSocket /var/spool/postfix/clamav/clamav-milter.ctl\nFixStaleSocket true\nMilterSocketGroup postfix\nMilterSocketMode 660\nUser clamav\n"
            . "ClamdSocket unix:/run/clamav/clamd.ctl\nReadTimeout 120\nForeground false\nPidFile /run/clamav/clamav-milter.pid\nTemporaryDirectory /tmp\n"
            . "OnClean Accept\nOnInfected " . self::Get('clam_on_infected') . "\nOnFail " . self::Get('clam_on_fail') . "\n"
            . "RejectMsg Rejected: virus %v found in the message\nAddHeader " . (self::On('clam_add_header') ? 'Replace' : 'No') . "\nReportHostname " . (gethostname() ?: 'localhost') . "\n"
            . (self::On('clam_scan_outgoing') ? '' : "SkipAuthenticated .*\n")
            . "MaxFileSize " . (int) self::Get('clam_max_scan_size') . "M\nSupportMultipleRecipients false\n"
            . "LogSyslog true\nLogFacility LOG_MAIL\nLogInfected Full\nLogClean Off\nLogVerbose false\n";
    }

    /**
     * Build /etc/clamav/freshclam.conf from the settings.
     *
     * @return string The file's contents.
     */
    static function FreshclamConf()
    {
        return "# Written by Sentora (Admin > SpamAV Admin): changes here are replaced\n"
            . "DatabaseOwner clamav\nUpdateLogFile /var/log/clamav/freshclam.log\nLogVerbose false\nLogSyslog false\nLogFileMaxSize 0\nLogRotate true\nLogTime true\n"
            . "Foreground false\nDebug false\nMaxAttempts 5\nDatabaseDirectory /var/lib/clamav\nDNSDatabaseInfo current.cvd.clamav.net\n"
            . "ConnectTimeout 30\nReceiveTimeout 0\nTestDatabases yes\nScriptedUpdates yes\nCompressLocalDatabase no\nBytecode true\n"
            . "NotifyClamd /etc/clamav/clamd.conf\nChecks " . (int) self::Get('clam_db_checks') . "\nDatabaseMirror database.clamav.net\n";
    }

    /**
     * Build /etc/spamassassin/60_sentora.cf (with the per-mailbox settings database).
     *
     * @param string $dbPass Password of the sentora_spamav database user.
     * @return string The file's contents.
     */
    static function SaConf($dbPass)
    {
        $c = "# Written by Sentora (Admin > SpamAV Admin): changes here are replaced\n"
            . "required_score " . self::Get('sa_required_score') . "\n"
            . (self::Get('sa_subject_tag') !== '' ? "rewrite_header Subject " . self::Get('sa_subject_tag') . "\n" : "")
            . "add_header all Level _STARS(*)_\nadd_header all Checker-Version SpamAssassin _VERSION_ on _HOSTNAME_\n"
            . "report_safe " . self::Get('sa_report_safe') . "\n"
            . "use_bayes " . (self::On('sa_use_bayes') ? 1 : 0) . "\nbayes_auto_learn " . (self::On('sa_bayes_auto_learn') ? 1 : 0) . "\n"
            . "bayes_auto_learn_threshold_spam " . self::Get('sa_auto_learn_spam') . "\nbayes_auto_learn_threshold_nonspam " . self::Get('sa_auto_learn_ham') . "\n"
            . "bayes_path /var/lib/spamassassin/bayes/bayes\nbayes_file_mode 0770\n"
            . "skip_rbl_checks " . (self::On('sa_network_tests') ? 0 : 1) . "\n"
            . "";  // this server (127.0.0.0/8, ::1) is always trusted
        foreach (array_filter(explode("\n", self::Get('sa_trusted_networks'))) as $n) {
            $c .= "trusted_networks " . $n . "\n";
        }
        foreach (array_filter(explode("\n", self::Get('sa_allow_from'))) as $p) {
            $c .= "welcomelist_from " . $p . "\n";
        }
        foreach (array_filter(explode("\n", self::Get('sa_block_from'))) as $p) {
            $c .= "blocklist_from " . $p . "\n";
        }
        // per-mailbox settings: sensitivity, allowed and blocked senders (only these: see SyncUserPrefs)
        $c .= "user_scores_dsn DBI:mysql:" . self::SA_DB . ":localhost\nuser_scores_sql_username sentora_spamav\nuser_scores_sql_password " . $dbPass . "\n"
            . "user_scores_sql_custom_query SELECT preference, value FROM userpref WHERE username = _USERNAME_ OR username = '@GLOBAL' ORDER BY username ASC\n";
        return $c;
    }

    /**
     * Build /etc/default/spamd (spamd's options) from the settings.
     *
     * @return string The file's contents.
     */
    static function SpamdDefaults()
    {
        return "# Written by Sentora (Admin > SpamAV Admin)\n"
            . "OPTIONS=\"--max-children " . (int) self::Get('sa_children') . " --helper-home-dir=/var/lib/spamassassin -u debian-spamd -x --sql-config --listen 127.0.0.1 --syslog=mail\"\n"
            . "PIDFILE=\"/run/spamd.pid\"\n";
    }

    /**
     * Build /etc/default/spamass-milter from the settings.
     *
     * @return string The file's contents.
     */
    static function MilterDefaults()
    {
        $opt = "-u spamass-milter -i 127.0.0.1 " . (self::On('sa_skip_outgoing') ? '-I ' : '') . "-e " . (gethostname() ?: 'localhost');
        $r = (float) self::Get('sa_reject_score');
        if ($r > 0) {
            $opt .= " -r " . (int) ceil($r); // the start script splits OPTIONS on spaces: the default message (Blocked by SpamAssassin) is kept
        }
        return "# Written by Sentora (Admin > SpamAV Admin)\nOPTIONS=\"" . $opt . " -- -s " . ((int) self::Get('sa_max_size') * 1024) . "\"\n"
            . "SOCKET=\"/var/spool/postfix/spamass/spamass.sock\"\nSOCKETOWNER=\"spamass-milter:postfix\"\nSOCKETMODE=\"0660\"\n";
    }

    /**
     * Build the per-mailbox Sieve script: run before the user's own filters.
     *
     * @param string $mailbox The mail address.
     * @return string The Sieve script.
     */
    static function SieveScript($mailbox)
    {
        $p = self::Prefs($mailbox);
        $action = $p['action'] !== '' ? $p['action'] : self::Get('sa_default_action');
        $s = "# Spam handling for " . preg_replace('/[^A-Za-z0-9@._+-]/', '', $mailbox) . " (Mail > SpamAV); made by the panel\n";
        if (!$p['enabled']) {
            return $s . "# the spam filter is off for this mailbox\n";
        }
        $s .= "require [\"fileinto\", \"mailbox\"];\n"
            . "if header :is \"X-Spam-Flag\" \"YES\" {\n";
        if ($p['delete_high']) {
            $s .= "    if header :contains \"X-Spam-Level\" \"***************\" { discard; stop; }\n";
        }
        $s .= ($action === 'inbox') ? "    keep;\n" : "    fileinto :create \"Spam\";\n    stop;\n";
        return $s . "}\n";
    }

    /**
     * Return rows for spamd's userpref table: only these preferences are given to users.
     *
     * @param string $mailbox The mail address.
     * @return array<int, array{0: string, 1: string}> array(preference, value) pairs.
     */
    static function UserPrefRows($mailbox)
    {
        $p = self::Prefs($mailbox);
        $rows = array();
        if (!$p['enabled']) {
            $rows[] = array('required_score', '100000'); // the filter is off for this mailbox: never spam
        } elseif ($p['score'] !== '' && self::On('sa_user_settings')) {
            $rows[] = array('required_score', $p['score']);
        }
        if (self::On('sa_user_settings')) {
            foreach (array_filter(explode("\n", $p['allow'])) as $a) {
                $rows[] = array('welcomelist_from', $a);
            }
            foreach (array_filter(explode("\n", $p['block'])) as $b) {
                $rows[] = array('blocklist_from', $b);
            }
        }
        return $rows;
    }

    // --- the mail log: events for each account ----------------------------------------------

    /**
     * Find the account a mail address belongs to: by mailbox, else by its domain.
     *
     * Mailboxes and domains are read once per request.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $address The mail address (may be in angle brackets).
     * @return int The account ID, or 0 when none.
     */
    static function AccountFor($address)
    {
        global $zdbh;
        static $boxes = null, $domains = null;
        if ($boxes === null) {
            $boxes = $zdbh->query("SELECT LOWER(mb_address_vc), mb_acc_fk FROM x_mailboxes WHERE mb_deleted_ts IS NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
            $domains = $zdbh->query("SELECT LOWER(vh_name_vc), vh_acc_fk FROM x_vhosts WHERE vh_deleted_ts IS NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $a = strtolower(trim((string) $address, '<> '));
        if (isset($boxes[$a])) {
            return (int) $boxes[$a];
        }
        $d = substr(strrchr($a, '@'), 1);
        return ($d !== false && isset($domains[$d])) ? (int) $domains[$d] : 0;
    }

    /**
     * Turn log lines into events.
     *
     * $state carries mail still in progress between runs (queue id => what is known).
     * Updates are changes to events stored by an earlier run (queue id => fields), for
     * lines that came later.
     *
     * @param array<int,array{0:int,1:string,2:string}> $lines Log lines: array(time, program, message).
     * @param array<string,mixed> &$state In/out: 'midqid' and 'qid' maps kept between runs.
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, int>>, 2: array<string, array<string, mixed>>} array(events, scanned counts by day and mailbox, updates by queue id).
     */
    static function ParseLog(array $lines, array &$state)
    {
        $events = array();
        $scanned = array();
        $updates = array();
        $byMid = array();                                                   // message-id => event (this run)
        $midQid = isset($state['midqid']) ? $state['midqid'] : array();      // message-id => queue id
        $byQid = isset($state['qid']) ? $state['qid'] : array();
        foreach ($lines as $l) {
            list($ts, $prog, $msg) = $l;
            if ($prog === 'spamd' && preg_match('/^result: ([Y.])\s+(-?\d+)\s+-\s+(\S*)\s.*?user=([^,]*),.*?required_score=([\d.]+).*?mid=(<[^>]*>|\(unknown\))/', $msg, $m)) {
                $user = strtolower($m[4]);
                $day = date('Y-m-d', $ts);
                if (strpos($user, '@') !== false) {
                    $scanned[$day][$user] = isset($scanned[$day][$user]) ? $scanned[$day][$user] + 1 : 1;
                }
                if ($m[1] === 'Y') {
                    $e = array('ts' => $ts, 'mailbox' => $user, 'sender' => '', 'kind' => 'spam', 'score' => $m[2], 'detail' => substr(str_replace(',', ', ', $m[3]), 0, 250),
                        'action' => 'marked', 'direction' => 'in', 'subject' => '');
                    if (isset($midQid[$m[6]])) {
                        $e['qid'] = $midQid[$m[6]];
                    }
                    $byMid[$m[6]] = count($events);
                    $events[] = $e;
                }
                continue;
            }
            if ($prog === 'clamav-milter' && preg_match("/^Message (\S+) from <([^>]*)> to <([^>]*)>(?: <[^>]*>)* with subject '(.*)' message-id '([^']*)' date '.*' infected by (\S+)/", $msg, $m)) {
                $events[] = array('ts' => $ts, 'mailbox' => strtolower($m[3]), 'sender' => strtolower($m[2]), 'kind' => 'virus', 'score' => '', 'detail' => substr($m[6], 0, 250),
                    'action' => strtolower(self::Get('clam_on_infected')) === 'quarantine' ? 'quarantined' : (strtolower(self::Get('clam_on_infected')) === 'blackhole' ? 'dropped' : 'rejected'),
                    'direction' => self::AccountFor($m[3]) > 0 ? 'in' : 'out', 'subject' => substr($m[4], 0, 200), 'qid' => $m[1]);
                continue;
            }
            if (strpos($prog, 'postfix/') === 0) {
                if (preg_match('/^([0-9A-Za-z]+): message-id=(<[^>]*>)/', $msg, $m)) {
                    $byQid[$m[1]]['mid'] = $m[2];
                    $midQid[$m[2]] = $m[1];
                    if (isset($byMid[$m[2]]) && isset($events[$byMid[$m[2]]])) {
                        $events[$byMid[$m[2]]]['qid'] = $m[1];
                    }
                } elseif (preg_match('/^([0-9A-Za-z]+): from=<([^>]*)>/', $msg, $m)) {
                    $byQid[$m[1]]['from'] = strtolower($m[2]);
                    if (!self::EventForQid($events, $m[1])) {
                        $updates[$m[1]]['sender'] = strtolower($m[2]);   // an event from an earlier run
                    }
                } elseif (preg_match('/^([0-9A-Za-z]+|NOQUEUE): milter-reject: END-OF-MESSAGE .*?: \d\.\d\.\d (.*?); from=<([^>]*)> to=<([^>]*)>/', $msg, $m)) {
                    $isSpam = stripos($m[2], 'spam') !== false;
                    foreach ($events as $i => $e) {
                        if (isset($e['qid']) && $e['qid'] === $m[1]) {
                            $events[$i]['action'] = 'rejected';
                            $events[$i]['sender'] = strtolower($m[3]);
                        }
                    }
                    if ($isSpam && !self::EventForQid($events, $m[1])) {
                        // its score came in an earlier run: that event becomes rejected (or, if
                        // there is none, a new one is made by the caller)
                        $updates[$m[1]] = array('action' => 'rejected', 'sender' => strtolower($m[3]),
                            'new' => array('ts' => $ts, 'mailbox' => strtolower($m[4]), 'sender' => strtolower($m[3]), 'kind' => 'spam', 'score' => '', 'detail' => '',
                                'action' => 'rejected', 'direction' => 'in', 'subject' => '', 'qid' => $m[1]));
                    }
                }
            }
        }
        // senders for spam that was accepted (Postfix logs them after the scan)
        foreach ($events as $i => $e) {
            if ($e['sender'] === '' && isset($e['qid']) && isset($byQid[$e['qid']]['from'])) {
                $events[$i]['sender'] = $byQid[$e['qid']]['from'];
            }
        }
        // what is still in progress is kept a little (for the next run)
        $state['midqid'] = array_slice($midQid, -2000, null, true);
        $state['qid'] = array_slice($byQid, -2000, null, true);
        return array($events, $scanned, $updates);
    }

    /**
     * Store what ParseLog found: new events, changes to earlier ones, daily counts.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array<int,array<string,mixed>> $events New events from ParseLog().
     * @param array<string,array<string,int>> $scanned Scanned counts: day => mailbox => count.
     * @param array<string,array<string,mixed>> $updates Changes to earlier events: queue id => fields.
     * @return void
     */
    static function Store(array $events, array $scanned, array $updates)
    {
        global $zdbh;
        self::Ensure();
        $ins = $zdbh->prepare("INSERT INTO x_spamav_events (ev_ts, ev_acc_fk, ev_mailbox_vc, ev_sender_vc, ev_kind_vc, ev_score_vc, ev_detail_vc, ev_action_vc, ev_direction_vc, ev_subject_vc, ev_qid_vc)
            VALUES (:t, :a, :m, :s, :k, :sc, :d, :ac, :dir, :sub, :q)");
        $dayIns = $zdbh->prepare("INSERT INTO x_spamav_daily (dd_day, dd_acc_fk, dd_mailbox_vc, dd_scanned_in, dd_spam_in, dd_virus_in, dd_rejected_in) VALUES (:d, :a, :m, :sc, :sp, :v, :r)
            ON DUPLICATE KEY UPDATE dd_scanned_in = dd_scanned_in + VALUES(dd_scanned_in), dd_spam_in = dd_spam_in + VALUES(dd_spam_in), dd_virus_in = dd_virus_in + VALUES(dd_virus_in), dd_rejected_in = dd_rejected_in + VALUES(dd_rejected_in)");
        $store = function (array $e) use ($ins, $dayIns) {
            // a virus in mail a user sends belongs to the sender's account
            $acc = sys_spamav::AccountFor($e['mailbox']);
            if ($acc === 0 && $e['kind'] === 'virus') {
                $acc = sys_spamav::AccountFor($e['sender']);
                $e['direction'] = 'out';
            }
            $ins->execute(array(':t' => $e['ts'], ':a' => $acc, ':m' => substr($e['mailbox'], 0, 255), ':s' => substr($e['sender'], 0, 255), ':k' => $e['kind'],
                ':sc' => substr((string) $e['score'], 0, 10), ':d' => substr($e['detail'], 0, 255), ':ac' => $e['action'], ':dir' => $e['direction'],
                ':sub' => substr($e['subject'], 0, 200), ':q' => substr((string) ($e['qid'] ?? ''), 0, 20)));
            $dayIns->execute(array(':d' => date('Y-m-d', $e['ts']), ':a' => $acc, ':m' => substr($e['mailbox'], 0, 255), ':sc' => 0,
                ':sp' => $e['kind'] === 'spam' ? 1 : 0, ':v' => $e['kind'] === 'virus' ? 1 : 0, ':r' => $e['action'] === 'rejected' ? 1 : 0));
        };
        foreach ($events as $e) {
            $store($e);
        }
        foreach ($updates as $qid => $u) {
            $q = $zdbh->prepare("SELECT ev_id_pk, ev_acc_fk, ev_mailbox_vc, ev_ts, ev_action_vc FROM x_spamav_events WHERE ev_qid_vc = :q ORDER BY ev_id_pk DESC LIMIT 1");
            $q->execute(array(':q' => $qid));
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $set = array();
                if (isset($u['sender'])) {
                    $zdbh->prepare("UPDATE x_spamav_events SET ev_sender_vc = :s WHERE ev_id_pk = :i AND ev_sender_vc = ''")->execute(array(':s' => substr($u['sender'], 0, 255), ':i' => $row['ev_id_pk']));
                }
                if (isset($u['action']) && $row['ev_action_vc'] !== $u['action']) {
                    $zdbh->prepare("UPDATE x_spamav_events SET ev_action_vc = :a WHERE ev_id_pk = :i")->execute(array(':a' => $u['action'], ':i' => $row['ev_id_pk']));
                    if ($u['action'] === 'rejected') {
                        $dayIns->execute(array(':d' => date('Y-m-d', (int) $row['ev_ts']), ':a' => (int) $row['ev_acc_fk'], ':m' => $row['ev_mailbox_vc'], ':sc' => 0, ':sp' => 0, ':v' => 0, ':r' => 1));
                    }
                }
            } elseif (isset($u['new'])) {
                $store($u['new']);
            }
        }
        foreach ($scanned as $date => $boxes) {
            foreach ($boxes as $box => $n) {
                $dayIns->execute(array(':d' => $date, ':a' => sys_spamav::AccountFor($box), ':m' => substr($box, 0, 255), ':sc' => $n, ':sp' => 0, ':v' => 0, ':r' => 0));
            }
        }
    }

    /**
     * Check whether an event in the list has this queue id.
     *
     * @param array<int,array<string,mixed>> $events Events from this run.
     * @param string $qid The Postfix queue id.
     * @return bool True when one has it.
     */
    static function EventForQid(array $events, $qid)
    {
        foreach ($events as $e) {
            if (isset($e['qid']) && $e['qid'] === $qid) {
                return true;
            }
        }
        return false;
    }
}
