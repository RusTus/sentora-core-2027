<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Security configuration file.
 * @package zpanelx
 * @subpackage core -> config
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-06
 */
global $security;

// The installer replaces the placeholder below with a random key for this server.
// It is the salt/key the panel uses for its own hashing and encryption: keep it
// secret and do not change it on a running panel.

$security['server_crypto_key'] = "GENERATED_SALT_HERE";
