<?php

/**
 * Why an account is switched off, when the API switched it off with a reason (SuspendClient: "not
 * paid", "abuse"...). Switching it on again (by the API or on Manage Clients) leaves the reason as
 * history until it is switched off again; GetClient shows it only while the account is off.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class ws_suspend
{
    /** Longest reason (characters). */
    const MAX = 200;

    /**
     * Create the x_account_suspend table if missing (once per request).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if (!$done) {
            $zdbh->exec("CREATE TABLE IF NOT EXISTS x_account_suspend (
                as_acc_fk INT NOT NULL PRIMARY KEY, as_reason_vc VARCHAR(255) NOT NULL DEFAULT '', as_by_fk INT NOT NULL DEFAULT 0,
                as_time_in INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $done = true;
        }
    }

    /**
     * A reason as kept: one line of plain text.
     *
     * @param string $reason
     * @return string|false False when it is too long or not plain text.
     */
    static function Clean($reason)
    {
        $reason = trim(preg_replace('/\s+/u', ' ', (string) $reason));
        if (mb_strlen($reason) > self::MAX || preg_match('/[\x00-\x1F\x7F<>\p{Cf}]/u', $reason)) {
            return false;
        }
        return $reason;
    }

    /**
     * Keep why an account was switched off.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account.
     * @param string $reason From Clean().
     * @param int $by The account that did it.
     * @return void
     */
    static function Set($uid, $reason, $by)
    {
        global $zdbh;
        self::Ensure();
        $zdbh->prepare("REPLACE INTO x_account_suspend (as_acc_fk, as_reason_vc, as_by_fk, as_time_in) VALUES (:u, :r, :b, :t)")
            ->execute(array(':u' => (int) $uid, ':r' => (string) $reason, ':b' => (int) $by, ':t' => time()));
    }

    /**
     * Why the account was switched off ('' when no reason was given).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account.
     * @return string
     */
    static function Reason($uid)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT as_reason_vc FROM x_account_suspend WHERE as_acc_fk = :u");
        $q->execute(array(':u' => (int) $uid));
        return (string) $q->fetchColumn();
    }

    /**
     * Forget the reason (switched on again).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account.
     * @return void
     */
    static function Clear($uid)
    {
        global $zdbh;
        self::Ensure();
        $zdbh->prepare("DELETE FROM x_account_suspend WHERE as_acc_fk = :u")->execute(array(':u' => (int) $uid));
    }
}
