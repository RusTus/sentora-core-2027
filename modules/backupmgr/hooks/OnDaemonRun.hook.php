<?php

/**
 * Clients' FTP backups (Advanced > Backup): every daemon run (root) starts the uploads
 * due this hour and the ones asked for from the page (Test connection, Upload now),
 * each in the background (bin/sentora-client-ftp-backup).
 *
 * Written by RusTus, 2026-11-05
 */
global $zdbh; // hooks are included inside a function
sys_clientftp::Ensure();
if (sys_clientftp::Allowed()) {
    $cfWorker = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/bin/sentora-client-ftp-backup';
    $cfHourStart = strtotime(date('Y-m-d H:00:00'));
    $cfRows = $zdbh->query("SELECT f.* FROM x_backup_ftp f JOIN x_accounts a ON a.ac_id_pk = f.bf_acc_fk
        WHERE a.ac_deleted_ts IS NULL AND a.ac_enabled_in = 1 AND f.bf_host_vc <> ''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cfRows as $cf) {
        $cfMode = '';
        if ($cf['bf_request_vc'] === 'check' || $cf['bf_request_vc'] === 'now') {
            $cfMode = $cf['bf_request_vc'] === 'check' ? 'check' : 'run';
            sys_clientftp::SetRequest($cf['bf_acc_fk'], '');
        } elseif ((int) $cf['bf_enabled_in'] === 1 && (int) $cf['bf_hour_in'] === (int) date('G')) {
            $cfSt = json_decode((string) $cf['bf_status_tx'], true);
            $cfLast = is_array($cfSt) && isset($cfSt['run_time']) ? (int) $cfSt['run_time'] : 0;
            if ($cfLast < $cfHourStart) {
                $cfMode = 'run';
                sys_clientftp::SetStatus($cf['bf_acc_fk'], array('run_time' => time(), 'run_ok' => 0, 'run_message' => 'Started (daily).'));
            }
        }
        if ($cfMode !== '' && is_file($cfWorker)) {
            ctrl_system::Background('php ' . escapeshellarg($cfWorker) . ' ' . (int) $cf['bf_acc_fk'] . ' ' . $cfMode . ' > /dev/null 2>&1');
            echo "Client FTP backup: " . ($cfMode === 'check' ? 'connection test' : 'upload') . " started for account " . (int) $cf['bf_acc_fk'] . fs_filehandler::NewLine();
        }
    }
}
