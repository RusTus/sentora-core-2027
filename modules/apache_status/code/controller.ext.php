<?php

/**
 * Apache Status module: controller.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * Apache Status: the page itself is app/index.php (admin only, see app/sentora_guard.php).
 * This only checks that Apache's status page answers on this server.
 */
class module_controller extends ctrl_module
{

    /**
     * @var array{ok: bool, body: string, code: int}|null Cached result of Status(), null until it has run.
     */
    static $status = null;

    /**
     * Fetch Apache's server-status page once per request and cache the result.
     *
     * Requests http://127.0.0.1/server-status?auto with a 5 second timeout.
     *
     * @return array{ok: bool, body: string, code: int} 'ok' true when it answered 200 with
     *         status data, 'body' the response body, 'code' the HTTP status (0 when no answer).
     */
    static function Status()
    {
        if (self::$status === null) {
            $ch = curl_init('http://127.0.0.1/server-status?auto');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            $body = (string) curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            self::$status = array('ok' => ($code == 200 && strpos($body, 'Total Accesses') !== false), 'body' => $body, 'code' => $code);
        }
        return self::$status;
    }

    /**
     * Template flag <% if IsModServerStatus %>: does Apache's status page answer?
     *
     * @return bool True when mod_status answers on this server.
     */
    static function getIsModServerStatus()
    {
        $s = self::Status();
        return $s['ok'];
    }

    /**
     * Whether extended status is available (same check as getIsModServerStatus()).
     *
     * @return bool True when the status page answers with status data.
     */
    static function getIsExtendedStatus()
    {
        $s = self::Status();
        return $s['ok'];
    }

    /**
     * Template value <@ StatusCode @>: the HTTP status the status page answered with.
     *
     * @return int The HTTP status code (0 when there was no answer).
     */
    static function getStatusCode()
    {
        $s = self::Status();
        return (int) $s['code'];
    }

}

?>
