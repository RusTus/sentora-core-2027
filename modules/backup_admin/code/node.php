<?php

/**
 * The backup node's address on the panel (no login): a new backup node's installer fetches this
 * panel's backup key here (?key=1). It is a public key, not a secret: the person installing checks
 * the panel's code the installer shows against the code on Backup Config's page before the node
 * trusts it (see dryden/sys/nodes.class.php). Nothing else is answered.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-13
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=us-ascii');
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_GET['key'])) {
    http_response_code(400);
    exit;
}
$key = trim((string) ctrl_options::GetSystemOption('offsite_pubkey'));
if (!preg_match('/^ssh-ed25519 [A-Za-z0-9+\/]{40,100}={0,3}( [A-Za-z0-9@._-]{1,100})?$/', $key)) {
    // the panel's daemon makes the key on its first run after an update
    http_response_code(503);
    exit;
}
echo $key, "\n";
