<?php
/**
 * Apache admin module hook (OnAfterDeleteClient): clean up after deleted clients.
 *
 * Flags the Apache config for rewriting and removes the home folders of deleted
 * accounts whose user name is not used by an active account.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

SetWriteApacheConfigTrue();
DeleteApacheClientFiles();

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

/**
 * Remove the hosted_dir folder of every deleted account (unless an active account has the same user name).
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function DeleteApacheClientFiles()
{
    global $zdbh;
    $sql     = "SELECT * FROM x_accounts WHERE ac_deleted_ts IS NOT NULL";
    $numrows = $zdbh->query( $sql );
    if ( $numrows->fetchColumn() <> 0 ) {
        $sql                = $zdbh->prepare( $sql );
        $res                = array( );
        $sql->execute();
        while ( $rowdeletedaccounts = $sql->fetch() ) {
            // Check for an active user with same username
            $sql2     = "SELECT COUNT(*) FROM x_accounts WHERE ac_user_vc=:user AND ac_deleted_ts IS NULL";
            $numrows2 = $zdbh->prepare( $sql2 );
            $user     = $rowdeletedaccounts[ 'ac_user_vc' ];
            $numrows2->bindParam( ':user', $user );
            if ( $numrows2->execute() ) {
                if ( $numrows2->fetchColumn() == 0 ) {
                    if ( file_exists( ctrl_options::GetSystemOption( 'hosted_dir' ) . $rowdeletedaccounts[ 'ac_user_vc' ] ) ) {
                        fs_director::RemoveDirectory( ctrl_options::GetSystemOption( 'hosted_dir' ) . $rowdeletedaccounts[ 'ac_user_vc' ] );
                    }
                }
            }
        }
    }
}


?>
