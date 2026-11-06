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
     * The module's assets folder (template: <@ Assets @>).
     *
     * @return string The path, relative to the panel.
     */
    static function getAssets()
    {
        return 'modules/apache_status/assets/';
    }

}
