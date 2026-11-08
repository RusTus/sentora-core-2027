<?php

/**
 * Sencrypt module hook (OnDaemonRun): copy the panel's Sencrypt certificate to the mail and FTP services.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-06
 */
/*
 * Keep the mail and FTP services on the panel's Sencrypt certificate.
 *
 * Apache reads the panel's Sencrypt (Let's Encrypt) files directly. Postfix,
 * Dovecot and ProFTPD read /etc/sentora/configs/ssl/sentora.crt and sentora.key,
 * which the installer filled with a self-signed certificate. When the panel has
 * a Sencrypt certificate, copy it there whenever it is new or renewed, then
 * reload those services. The daemon runs as root every 5 minutes, so a new or
 * renewed panel certificate reaches mail and FTP within 5 minutes.
 */

echo fs_filehandler::NewLine() . "START Sencrypt service certificate sync." . fs_filehandler::NewLine();
SyncPanelCertificateToServices();
echo "END Sencrypt service certificate sync." . fs_filehandler::NewLine();

/**
 * Install the panel's Sencrypt certificate for Postfix, Dovecot and ProFTPD when it is new or renewed.
 *
 * Copies fullchain.pem, private.pem and chain.pem to /etc/sentora/configs/ssl/ (keeping the installer's
 * self-signed pair once as selfsigned.*) and reloads those services. Needs root.
 *
 * @return void
 */
function SyncPanelCertificateToServices()
{
    $panelSSL = (string) ctrl_options::GetSystemOption('panel_ssl_tx');
    if (strpos($panelSSL, '/ssl/sencrypt/') === false) {
        echo "The panel does not use a Sencrypt certificate, nothing to do." . fs_filehandler::NewLine();
        return;
    }
    if (!preg_match('/^\s*SSLCertificateFile\s+(\S+)/mi', $panelSSL, $match)) {
        echo "No SSLCertificateFile in the panel SSL settings, nothing to do." . fs_filehandler::NewLine();
        return;
    }

    // Sencrypt saves cert.pem, chain.pem, fullchain.pem and private.pem side by side
    $source = dirname($match[1]) . '/';
    $sourceChain = $source . 'fullchain.pem';
    $sourceKey = $source . 'private.pem';
    // ProFTPD reads only the first certificate of a file, so it gets the
    // intermediate certificate(s) separately
    $sourceIntermediate = $source . 'chain.pem';
    if (!is_readable($sourceChain) || !is_readable($sourceKey)) {
        echo "Sencrypt panel certificate files not found in " . $source . fs_filehandler::NewLine();
        return;
    }

    // Never install a certificate and key that do not belong together
    $cert = @openssl_x509_read(file_get_contents($sourceChain));
    $key = @openssl_pkey_get_private(file_get_contents($sourceKey));
    if ($cert === false || $key === false || !openssl_x509_check_private_key($cert, $key)) {
        echo "WARNING: the Sencrypt panel certificate and key do not match, services left unchanged." . fs_filehandler::NewLine();
        return;
    }

    $target = '/etc/sentora/configs/ssl/';
    if (is_file($target . 'sentora.crt') && hash_file('sha256', $sourceChain) === hash_file('sha256', $target . 'sentora.crt')
        && is_file($target . 'sentora.key') && hash_file('sha256', $sourceKey) === hash_file('sha256', $target . 'sentora.key')
        && is_file($target . 'sentora-chain.crt')) {
        echo "Mail and FTP already use the current panel certificate." . fs_filehandler::NewLine();
        return;
    }

    // Keep the installer's self-signed pair once, so it can be put back by hand
    if (is_file($target . 'sentora.crt') && !is_file($target . 'selfsigned.crt')) {
        @copy($target . 'sentora.crt', $target . 'selfsigned.crt');
        @copy($target . 'sentora.key', $target . 'selfsigned.key');
        @chmod($target . 'selfsigned.key', 0600);
    }

    // Write new files next to the old ones, then swap them in
    $intermediate = (is_readable($sourceIntermediate) && filesize($sourceIntermediate) > 0) ? $sourceIntermediate : $sourceChain;
    if (!@copy($sourceChain, $target . 'sentora.crt.new') || !@copy($sourceKey, $target . 'sentora.key.new')
        || !@copy($intermediate, $target . 'sentora-chain.crt.new')) {
        echo "WARNING: could not write to " . $target . " (the daemon must run as root)." . fs_filehandler::NewLine();
        @unlink($target . 'sentora.crt.new');
        @unlink($target . 'sentora.key.new');
        @unlink($target . 'sentora-chain.crt.new');
        return;
    }
    @chmod($target . 'sentora.crt.new', 0644);
    @chmod($target . 'sentora-chain.crt.new', 0644);
    @chmod($target . 'sentora.key.new', 0600);
    rename($target . 'sentora.key.new', $target . 'sentora.key');
    rename($target . 'sentora.crt.new', $target . 'sentora.crt');
    rename($target . 'sentora-chain.crt.new', $target . 'sentora-chain.crt');
    echo "Installed the panel's Sencrypt certificate for Postfix, Dovecot and ProFTPD." . fs_filehandler::NewLine();

    foreach (array('postfix', 'dovecot', 'proftpd') as $service) {
        exec('systemctl reload-or-restart ' . escapeshellarg($service) . ' 2>&1', $output, $result);
        echo "   " . $service . ": " . ($result === 0 ? 'reloaded' : 'reload FAILED') . fs_filehandler::NewLine();
    }
}

?>
