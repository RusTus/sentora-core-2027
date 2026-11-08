<?php
/**
 * Apache admin module hook (OnAfterUpdatePackage): flag the Apache config for rewriting.
 *
 * Sets apache_changed so the daemon rewrites the vhost configuration on its next run.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Updated by RusTus, 2026-11-06
 */

SetWriteApacheConfigTrue();

/**
 * Set the apache_changed setting to 'true' so the daemon rewrites the Apache config.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function SetWriteApacheConfigTrue() {
    global $zdbh;
    $sql = $zdbh->prepare("UPDATE x_settings
								SET so_value_tx='true'
								WHERE so_name_vc='apache_changed'");
    $sql->execute();
}

?>