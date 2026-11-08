<?php

/**
 * Panel Update: the progress of the running job and the "last checked" figures, as JSON, for the
 * page to draw again every few seconds (administrators only).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-07
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once 'modules/panel_update/code/state.php';
require_once 'modules/panel_update/code/view.php';
$user = panel_update_state::RequireAdmin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$run = panel_update_view::Run(panel_update_state::Run(), (int) $user['pu_asked']);
$a = panel_update_state::Available();
echo json_encode(array('live' => $run['live'], 'run' => $run['html'], 'checked' => $a['checked'], 'state' => $a['state']), JSON_INVALID_UTF8_SUBSTITUTE);
