<?php

/**
 * Apache Status: the page itself is app/index.php (admin only, see app/sentora_guard.php).
 * This only checks that Apache's status page answers on this server.
 */
class module_controller extends ctrl_module
{

    static $status = null;

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

    static function getIsModServerStatus()
    {
        $s = self::Status();
        return $s['ok'];
    }

    static function getIsExtendedStatus()
    {
        $s = self::Status();
        return $s['ok'];
    }

    static function getStatusCode()
    {
        $s = self::Status();
        return (int) $s['code'];
    }

}

?>
