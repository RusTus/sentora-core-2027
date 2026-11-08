<?php
/**
 * PHP Versions: switch MySQL's older login method (mysql_native_password) off when an administrator
 * asked for it in the Older logins card and no MySQL user uses it any more (sys_oldlogins).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-07
 */
sys_oldlogins::DaemonSwitchOff();
