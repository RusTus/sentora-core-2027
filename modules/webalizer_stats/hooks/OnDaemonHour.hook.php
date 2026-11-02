<?php

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
    while ( $rowvhost = $sql->fetch() ) {
      $basedir = ctrl_options::GetSystemOption( 'sentora_root' ) . "modules/webalizer_stats/stats/" . $rowvhost[ 'ac_user_vc' ] . "/" . $rowvhost[ 'vh_name_vc' ];
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

        $logFile = realpath( ctrl_options::GetSystemOption( 'log_dir' ) .
            'domains/' .
            $rowvhost[ 'ac_user_vc' ] .
            '/' .
            $rowvhost[ 'vh_name_vc' ] .
            '-access.log' );

        // No traffic yet: without a log file webalizer would wait on stdin
        if ( $logFile === false ) {
            echo "No access log yet for: " . $rowvhost[ 'ac_user_vc' ] . "/" . $rowvhost[ 'vh_name_vc' ] . fs_filehandler::NewLine();
            continue;
        }

        $statsPath = ctrl_options::GetSystemOption( 'sentora_root' ) .
            "modules/webalizer_stats/stats/" .
            $rowvhost[ 'ac_user_vc' ] .
            '/' .
            $rowvhost[ 'vh_name_vc' ];

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
}

?>
