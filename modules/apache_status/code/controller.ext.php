<?php

/**
 * Apache Status module: controller.
 *
 * The page (module.zpm) is a live dashboard filled by assets/status.js from
 * code/status.php. This controller only tells the template whether Apache's status
 * page answers, and where the module's files are.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * Apache Status controller (template values).
 */
class module_controller extends ctrl_module
{

    /** @var array{ok: bool, code: int}|null Apache's status page check, once per page. */
    static $status = null;

    /**
     * Ask Apache's status page whether it answers (http://127.0.0.1/server-status?auto).
     *
     * @return array{ok: bool, code: int} Whether it answered with the status summary, and the HTTP code.
     */
    static function Status()
    {
        if (self::$status === null) {
            $ch = curl_init('http://127.0.0.1/server-status?auto');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            self::$status = array('ok' => ($code === 200 && strpos($body, 'Total Accesses') !== false), 'code' => $code);
        }
        return self::$status;
    }

    /**
     * Whether Apache's status page answers (template: <% if IsModServerStatus %>).
     *
     * @return bool True when it answers.
     */
    static function getIsModServerStatus()
    {
        $s = self::Status();
        return $s['ok'];
    }

    /**
     * The HTTP code Apache's status page answered with (template: <@ StatusCode @>).
     *
     * @return int The code, 0 when Apache did not answer.
     */
    static function getStatusCode()
    {
        $s = self::Status();
        return (int) $s['code'];
    }

    /**
     * The texts assets/status.js shows, in the account's language (template: data-texts="<@ Texts @>").
     *
     * The script looks each English text up here (a %s in it is a value the script puts in).
     *
     * @return string JSON (English => translation), escaped for an HTML attribute.
     */
    static function getTexts()
    {
        $texts = array(
            '%s finishing', '%s idle',
            '%s of the worker slots have no process. That is normal on a quiet server: Apache starts more when traffic grows.', '%s per request',
            '%s processes', '%s requests', '%s requests running', '%s since the restart', '%s slots listed',
            '%s slots · %s with a process · %s open', '1 process', '1 request', '1 request running',
            'Almost every worker is busy (%s). New requests wait for one to come free: raise MaxRequestWorkers, or find what holds them (Requests tab).',
            'Apache did not list its worker slots. They need ExtendedStatus On.', 'Built', 'Busy', 'CPU load', 'CPU, child processes', 'CPU, system',
            'CPU, user', 'Closing the connection', 'Config generation', 'Could not read Apache\'s status: %s Retrying…', 'DNS lookup',
            'Every request took under a millisecond.', 'Finishing gracefully', 'Idle', 'Idle cleanup', 'Keep-alive', 'Logging', 'MPM generation',
            'Most workers are busy (%s). If this lasts, Apache will soon make requests wait.', 'No request is running right now.',
            'Nothing matches the filter.', 'Nothing to count yet.', 'Open', 'Open slot, no process', 'Pause', 'Per request', 'Processes',
            'Reading the request', 'Requests', 'Requests per second', 'Restarted', 'Resume', 'Sending the reply', 'Sent', 'Sent per second',
            'Slot %s: %s', 'Starting up', 'Stopping', 'Time per request', 'Time serving', 'Up for', 'Version', 'Waiting for a connection',
            'Without a thread', 'average %s', 'built %s', 'children %s s', 'connection %s · child process %s · slot %s', 'not reported', 'now',
            'reported by the event MPM only', 'restarted %s', 'up %s', 'user %s s · system %s s', 'writing %s · keep-alive %s · closing %s'
        );
        $out = array();
        foreach ($texts as $en) {
            $out[$en] = ui_language::translate($en);
        }
        return htmlspecialchars((string) json_encode($out, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    }

    /**
     * The module's assets folder (template: <@ Assets @>).
     *
     * @return string The path, relative to the panel.
     */
    static function getAssets()
    {
        return 'modules/apache_status/assets/';
    }

}
