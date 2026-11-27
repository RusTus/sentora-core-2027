<?php

/**
 * Apache Status: fetches one of Apache's status pages from this server.
 *
 * http://127.0.0.1/server-status (the panel's port, apache_port). Where the server keeps a status key
 * (hidden setting apache_status_key: a file only Apache's account and the administrators may read, as on
 * Windows), Apache answers only requests that carry it (X-Sentora-Status), so the websites' own PHP, which
 * runs on this server too, can not read the other sites' requests and visitors there.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-27
 */

/**
 * @param string $query '' for the HTML page, '?auto' for the summary.
 * @return array{0: int, 1: string} The HTTP status code (0 when Apache did not answer) and the body.
 */
function apst_fetch($query)
{
    $port = (int) ctrl_options::GetSystemOption('apache_port');
    $url = 'http://127.0.0.1' . ($port > 0 && $port !== 80 ? ':' . $port : '') . '/server-status' . $query;
    $head = array();
    $keyFile = (string) ctrl_options::GetSystemOption('apache_status_key');
    if ($keyFile !== '' && is_readable($keyFile)) {
        $key = trim((string) @file_get_contents($keyFile));
        if (preg_match('/^[A-Za-z0-9]{16,128}$/', $key)) {
            $head[] = 'X-Sentora-Status: ' . $key;
        }
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 8388608, CURLOPT_HTTPHEADER => $head));
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return array($code, $body);
}
