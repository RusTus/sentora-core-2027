<?php

/**
 * Session cookie settings for every entry point that starts a panel session
 * (index.php and the dryden/ajax handlers): never readable by scripts, not sent
 * with cross-site posts, only over HTTPS when the panel is on HTTPS; ids the
 * server did not make are refused. Include this before session_start().
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-06
 */
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)) {
    ini_set('session.cookie_secure', '1');
    // Browsers that have seen the panel on HTTPS keep using HTTPS for it
    header('Strict-Transport-Security: max-age=31536000');
}
