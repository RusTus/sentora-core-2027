<?php
/**
 * Branding, every daemon run (root):
 * - once, when the module is new on this server: give it to the Administrators and Resellers groups
 *   (Module Admin decides after that);
 * - each reseller's panel address: wait until it has an address in DNS and its plain-http site is in
 *   Apache's settings, then get its Let's Encrypt certificate (and renew it when a third of its
 *   lifetime is left), and have Apache's settings written again with its https site;
 * - a failed request is tried again after 1, 2, 4... hours (at most a day), so Let's Encrypt's limits
 *   on failed checks are never reached;
 * - certificates of addresses that are no longer used are removed.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
global $zdbh;
$nl = fs_filehandler::NewLine();

if (ctrl_options::GetSystemOption('branding_setup') === false) {
    $q = $zdbh->prepare("SELECT mo_id_pk FROM x_modules WHERE mo_folder_vc = 'branding'");
    $q->execute();
    $moduleId = (int) $q->fetchColumn();
    if ($moduleId > 0) {
        $has = $zdbh->prepare("SELECT COUNT(*) FROM x_permissions WHERE pe_group_fk = :g AND pe_module_fk = :m");
        $add = $zdbh->prepare("INSERT INTO x_permissions (pe_group_fk, pe_module_fk) VALUES (:g, :m)");
        foreach (array(1, 2) as $g) {
            $has->execute(array(':g' => $g, ':m' => $moduleId));
            if ((int) $has->fetchColumn() === 0) {
                $add->execute(array(':g' => $g, ':m' => $moduleId));
            }
        }
        $zdbh->exec("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
            VALUES ('branding_setup', 'branding_setup', '1', NULL, 'Branding given to the Administrators and Resellers groups', 'Sentora Config', 'false')");
        echo "Branding: available to the Administrators and Resellers groups." . $nl;
    }
}

BrandingSyncApache();
if (ctrl_branding::Enabled()) {
    BrandingCertificates();
}

/**
 * Have Apache's settings written again when the panel addresses in them are not the ones that
 * should be there: a reseller switched off, deleted or moved out of a group with Manage Clients, an
 * address removed, the module switched off. (Saving an address does this at once; those changes
 * happen elsewhere in the panel.)
 *
 * @return void
 */
function BrandingSyncApache()
{
    global $zdbh;
    if (ctrl_options::GetSystemOption('apache_changed') === 'true') {
        return;
    }
    $file = (string) @file_get_contents((string) ctrl_options::GetSystemOption('apache_vhost'));
    preg_match_all('/^# BRANDING: (\S+)$/m', $file, $m);
    $inFile = $m[1];
    $want = array();
    foreach (ctrl_branding::Addresses() as $a) {
        $want[] = $a['host'];
    }
    sort($inFile);
    sort($want);
    if ($inFile !== $want) {
        $zdbh->exec("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc = 'apache_changed'");
        echo "Branding: the panel addresses changed; Apache's settings are written again." . fs_filehandler::NewLine();
    }
}

/**
 * Get, renew and tidy the certificates of the panel addresses.
 *
 * @return void
 */
function BrandingCertificates()
{
    global $zdbh;
    $nl = fs_filehandler::NewLine();
    $dir = ctrl_branding::CERT_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (!is_dir($dir) || is_link(rtrim($dir, '/'))) {
        echo "Branding: " . $dir . " could not be made." . $nl;
        return;
    }
    @chown($dir, 0);
    @chmod($dir, 0700);
    $addresses = ctrl_branding::Addresses();
    $vhosts = (string) @file_get_contents((string) ctrl_options::GetSystemOption('apache_vhost'));
    $pending = ctrl_options::GetSystemOption('apache_changed') === 'true';
    $used = array();
    foreach ($addresses as $a) {
        $host = $a['host'];
        $used[$host] = true;
        $b = ctrl_branding::Get($a['uid']);
        $certDir = $dir . $host . '/';
        $have = ctrl_branding::CertUsable($certDir);
        require_once 'modules/sencrypt/code/Lescript.php';
        $due = !$have || \Analogic\ACME\Lescript::renewalDue($certDir . 'cert.pem');
        if ($have && strpos($vhosts, 'SSLCertificateFile ' . $certDir . 'fullchain.pem') === false && !$pending) {
            // issued, but Apache's settings do not have the https site yet
            $zdbh->exec("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc = 'apache_changed'");
        }
        if (!$due) {
            if ($b['state'] !== 'live' || ($b['note'] !== '' && $b['tries'] === 0)) {
                ctrl_branding::SetState($a['uid'], 'live', '', 0, 0);
            }
            continue;
        }
        if ($b['next'] > time()) {
            continue;
        }
        if (!\Analogic\ACME\Lescript::hasAddress($host)) {
            if ($have) {
                ctrl_branding::SetState($a['uid'], 'live', 'The address has no DNS record any more, so its certificate can not be renewed.');
            } else {
                ctrl_branding::SetState($a['uid'], 'dns', '');
            }
            continue;
        }
        // Let's Encrypt checks http://<host>/.well-known/acme-challenge/: the plain-http site must be live
        if ($pending || strpos($vhosts, "# BRANDING: " . $host . "\n") === false) {
            if (!$have) {
                ctrl_branding::SetState($a['uid'], 'wait', '');
            }
            continue;
        }
        echo "Branding: " . ($have ? "renewing" : "getting") . " the certificate for " . $host . "..." . $nl;
        if (!$have) {
            ctrl_branding::SetState($a['uid'], 'wait', '');
        }
        if (!is_dir($certDir)) {
            @mkdir($certDir, 0700, true);
        }
        try {
            date_default_timezone_set("UTC");
            list($country, $region) = \Analogic\ACME\Lescript::serverLocation();
            $logger = new class {
                /**
                 * Write a line of Let's Encrypt's progress to the daemon's output.
                 *
                 * @param string $name The level.
                 * @param array $args The message first.
                 * @return void
                 */
                public function __call($name, $args)
                {
                    echo "   [" . $name . "] " . (isset($args[0]) ? $args[0] : '') . fs_filehandler::NewLine();
                }
            };
            $le = new \Analogic\ACME\Lescript($dir, $certDir, rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/'), $logger, $country, $region);
            $le->initAccount();
            $le->signDomains(array($host));
            foreach (array('cert.pem', 'fullchain.pem', 'chain.pem', 'private.pem', 'public.pem', 'last.csr') as $f) {
                if (is_file($certDir . $f)) {
                    @chmod($certDir . $f, 0600);
                }
            }
            if (!ctrl_branding::CertUsable($certDir)) {
                throw new RuntimeException('The new certificate files are not usable.');
            }
            ctrl_branding::SetState($a['uid'], 'live', '', 0, 0);
            // the https site (or the renewed certificate) goes into Apache at the next run
            $zdbh->exec("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc = 'apache_changed'");
            echo "Branding: the certificate for " . $host . " is ready." . $nl;
        } catch (\Throwable $e) {
            $tries = $b['tries'] + 1;
            $wait = min(86400, 3600 * (int) pow(2, min(5, $tries - 1)));
            $why = \Analogic\ACME\Lescript::explain($e->getMessage());
            ctrl_branding::SetState($a['uid'], $have ? 'live' : 'error', ($have ? 'Renewing the certificate failed: ' : '') . $why, $tries, time() + $wait);
            echo "Branding: no certificate for " . $host . ": " . $why . " (next try in " . round($wait / 3600) . " h)" . $nl;
        }
    }
    // the certificates of addresses no longer used
    foreach ((array) scandir($dir) as $name) {
        if ($name === '.' || $name === '..' || $name[0] === '_' || isset($used[$name]) || !preg_match('/^[a-z0-9.-]+$/', $name)) {
            continue;
        }
        $old = $dir . $name;
        if (is_link($old)) {
            @unlink($old);
            continue;
        }
        if (!is_dir($old) || strpos($vhosts, "# BRANDING: " . $name . "\n") !== false) {
            // (still in Apache's settings until they are written again)
            continue;
        }
        foreach ((array) scandir($old) as $f) {
            if ($f !== '.' && $f !== '..' && (is_file($old . '/' . $f) || is_link($old . '/' . $f))) {
                @unlink($old . '/' . $f);
            }
        }
        @rmdir($old);
        echo "Branding: removed the certificate of " . $name . " (no longer used)." . $nl;
    }
}
