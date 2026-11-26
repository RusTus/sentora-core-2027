<?php

/**
 * PHP per hosting account on Windows (the panel's side of sentora-windows-2027's bin\phpaccount.ps1): every live
 * account's PHP runs as its own Windows account. The daemon (SYSTEM) writes the list of live accounts where only root's
 * jobs may write, then asks SentoraZsudo for "phpaccount-sync", which brings the PHP services in line with that list
 * (the ones the panel made; one Apache restart). A page that adds or removes a client only asks for a daemon run, which
 * it could already: a page (Apache's account) can not name an account to add or remove. Nothing here runs on Linux.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-24
 */
class sys_winphp
{
    /** Root's jobs only (the Windows installer protects it, as accounts\ and monitor\). */
    const DIR = '/var/lib/sentora/phpaccounts';

    /** Asked again once a day even with no change, so a service that went missing comes back. */
    const AGAIN = 86400;

    /**
     * An account's name for its PHP (the panel's user name, lower case), or '' when it can not be one.
     *
     * @param string $user
     * @return string
     */
    static function Name($user)
    {
        $n = strtolower(trim((string) $user));
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $n) ? $n : '';
    }

    /**
     * After a client is made or deleted (a page): ask for a daemon run, which brings the accounts' PHP in line.
     *
     * @return void
     */
    static function Soon()
    {
        if (!sys_versions::IsWindows()) {
            return;
        }
        $zsudo = (string) ctrl_options::GetSystemOption('zsudo');
        if ($zsudo !== '' && sys_versions::CanRun($zsudo) && function_exists('exec')) {
            @touch('/var/lib/sentora/tmp/daemon-again');
            $out = array();
            @exec(escapeshellcmd($zsudo) . ' daemon-run 2>&1', $out, $rc);
        }
    }

    /**
     * The daemon's part: write the live accounts' names; when they changed (or once a day), ask for the sync.
     *
     * @return string What was done, for the daemon's log.
     */
    static function Sync()
    {
        global $zdbh;
        if (!sys_versions::IsWindows()) {
            return '';
        }
        if (!is_dir(self::DIR) && !@mkdir(self::DIR, 0700, true)) {
            return 'PHP per account: ' . self::DIR . ' is not there';
        }
        // each account, then the other PHP versions its domains use (Domains page; the installer's, switched on)
        $names = array();
        $on = sys_phpversions::Enabled();
        $q = $zdbh->prepare("SELECT DISTINCT vh_php_version_vc FROM x_vhosts WHERE vh_acc_fk = :a AND vh_deleted_ts IS NULL AND vh_php_version_vc IS NOT NULL AND vh_php_version_vc <> ''");
        foreach ($zdbh->query("SELECT ac_id_pk, ac_user_vc FROM x_accounts WHERE ac_deleted_ts IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $n = self::Name($u['ac_user_vc']);
            if ($n === '') {
                continue;
            }
            $q->execute(array(':a' => (int) $u['ac_id_pk']));
            $v = array_values(array_intersect($on, $q->fetchAll(PDO::FETCH_COLUMN)));
            sort($v);
            $names[$n] = $n . ($v ? ' ' . implode(',', $v) : '');
        }
        ksort($names);
        $text = implode("\r\n", $names) . "\r\n";
        $file = self::DIR . '/wanted.txt';
        $stamp = self::DIR . '/asked';
        $same = is_file($file) && (string) @file_get_contents($file) === $text;
        if ($same && is_file($stamp) && time() - (int) @filemtime($stamp) < self::AGAIN) {
            return '';
        }
        if (@file_put_contents($file . '.new', $text) === false || !@rename($file . '.new', $file)) {
            return 'PHP per account: the list could not be written';
        }
        $zsudo = (string) ctrl_options::GetSystemOption('zsudo');
        if ($zsudo === '' || !sys_versions::CanRun($zsudo) || !function_exists('exec')) {
            return 'PHP per account: no zsudo to ask';
        }
        $out = array();
        exec(escapeshellcmd($zsudo) . ' phpaccount-sync 2>&1', $out, $rc);
        if ((int) $rc !== 0) {
            return 'PHP per account: zsudo said ' . implode(' ', $out);
        }
        @touch($stamp);
        if (!$same) {
            // (a domain's other PHP version needs its account's pool, which the sync has just made: the sites again)
            $zdbh->exec("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc = 'apache_changed'");
        }
        return 'PHP per account: ' . count($names) . ' account(s) asked for' . ($same ? ' (the daily check)' : '');
    }
}
