<?php
defined( 'WEBALIZER_STATS_DIR' ) || define( 'WEBALIZER_STATS_DIR', '/var/sentora/stats/webalizer/' );

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - Visitor Stats zpanel plugin, written by RusTus: www.zpanelcp.com.
 *
 * changes P;Peyremorte : reduced duplicate strings constructions
 */
echo fs_filehandler::NewLine() . "BEGIN Webalizer Stats" . fs_filehandler::NewLine();
if ( ui_module::CheckModuleEnabled( 'Webalizer Stats' ) ) {
    GenerateWebalizerStats();
}
else {
    echo "Webalizer Stats Module DISABLED." . fs_filehandler::NewLine();
}
echo "END Webalizer Stats" . fs_filehandler::NewLine();

function GenerateWebalizerStats()
{
    global $zdbh;
    $sql      = $zdbh->prepare( "SELECT * FROM x_vhosts LEFT JOIN x_accounts ON x_vhosts.vh_acc_fk=x_accounts.ac_id_pk WHERE vh_deleted_ts IS NULL" );
    $sql->execute();
    echo "Generating webalizer stats html..." . fs_filehandler::NewLine();
    $rows = $sql->fetchAll();
    // The panel's own traffic, under the main administrator account
    $panelDomain = ctrl_statsdomains::PanelDomain();
    $panelOwner = ctrl_statsdomains::OwnerName();
    if ( $panelDomain !== false && $panelOwner !== false ) {
        $rows[] = array( 'ac_user_vc' => $panelOwner, 'vh_name_vc' => $panelDomain, 'sentora_logfile' => ctrl_statsdomains::PanelLog() );
    }
    foreach ( $rows as $rowvhost ) {
      // Reports live outside the web root (they used to be readable by anyone who
      // guessed modules/webalizer_stats/stats/<user>/<domain>/) and are shown by
      // code/report.php to the domain's owner only.
      if ( !preg_match( '/^[A-Za-z0-9_-]{1,64}$/', (string) $rowvhost[ 'ac_user_vc' ] )
          || !preg_match( '/^[A-Za-z0-9.-]{1,253}$/', (string) $rowvhost[ 'vh_name_vc' ] ) ) {
          continue;
      }
      $basedir = WEBALIZER_STATS_DIR . $rowvhost[ 'ac_user_vc' ] . "/" . $rowvhost[ 'vh_name_vc' ];
      // Move reports made before this change (keeps the history webalizer builds on)
      $olddir = ctrl_options::GetSystemOption( 'sentora_root' ) . "modules/webalizer_stats/stats/" . $rowvhost[ 'ac_user_vc' ] . "/" . $rowvhost[ 'vh_name_vc' ];
      if ( is_dir( $olddir ) && !file_exists( $basedir . '/webalizer.hist' ) ) {
          @mkdir( dirname( $basedir ), 0755, TRUE );
          if ( is_dir( $basedir ) ) {
              exec( 'rm -rf ' . escapeshellarg( $basedir ) );
          }
          @rename( $olddir, $basedir );
      }
      if ( !file_exists( $basedir ) ) {
          @mkdir( $basedir, 0755, TRUE );
        }

        /** set webalizer command dependant on OS */
        if ( sys_versions::ShowOSPlatformVersion() == "Windows" ) {
            $command = ctrl_options::GetSystemOption( 'sentora_root' ) .
                'modules/webalizer_stats/bin/webalizer.exe';
        }
        else {
            // The installer builds webalizer into /usr/local/bin on Ubuntu 26.04,
            // older systems may still have the packaged /usr/bin/webalizer.
            $command = file_exists( '/usr/local/bin/webalizer' ) ? '/usr/local/bin/webalizer' : 'webalizer';
        }

        $logFile = isset( $rowvhost[ 'sentora_logfile' ] ) ? realpath( $rowvhost[ 'sentora_logfile' ] ) : realpath( ctrl_options::GetSystemOption( 'log_dir' ) .
            'domains/' .
            $rowvhost[ 'ac_user_vc' ] .
            '/' .
            $rowvhost[ 'vh_name_vc' ] .
            '-access.log' );

        // No traffic yet: without a log file webalizer would wait on stdin, and with
        // an empty one it stops with "No valid records found" (reported as Failed)
        if ( $logFile === false || filesize( $logFile ) == 0 ) {
            echo "No traffic yet, skipping: " . $rowvhost[ 'ac_user_vc' ] . "/" . $rowvhost[ 'vh_name_vc' ] . fs_filehandler::NewLine();
            continue;
        }

        $statsPath = $basedir;

        /**
         * Every option and value is its own argument, because each one is
         * shell-escaped separately. -Q quiet, -p incremental (keeps history
         * across log rotation), -F clf reads Apache's combined log format.
         */
        $args = array(
            '-Q',
            '-p',
            '-F', 'clf',
            '-n', $rowvhost[ 'vh_name_vc' ],
            '-o', $statsPath,
            $logFile,
        );

        echo "Generating stats for: " . $rowvhost[ 'ac_user_vc' ] . "/" . $rowvhost[ 'vh_name_vc' ] . fs_filehandler::NewLine();
        $returnValue = ctrl_system::systemCommand( $command, $args );
        echo (0 === $returnValue ? 'Succeeded' : 'Failed') . fs_filehandler::NewLine();
    }
    // Whatever is left in the old public folder (deleted domains) is removed
    $oldroot = ctrl_options::GetSystemOption( 'sentora_root' ) . "modules/webalizer_stats/stats";
    if ( is_dir( $oldroot ) ) {
        exec( 'rm -rf ' . escapeshellarg( $oldroot ) );
        echo "Removed the old public stats folder." . fs_filehandler::NewLine();
    }
}

?>
