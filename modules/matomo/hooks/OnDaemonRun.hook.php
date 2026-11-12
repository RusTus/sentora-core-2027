<?php

/**
 * Matomo: keeps Matomo's sites, users and access in step with the panel
 * (every 5 minutes, as root; Matomo itself runs as the panel user).
 *
 * Written by RusTus, 2026-11-09
 */
require_once dirname(__FILE__) . '/../code/matomohelper.php';

echo fs_filehandler::NewLine() . "START Matomo sync." . fs_filehandler::NewLine();
if (ui_module::CheckModuleEnabled('Matomo') && matomo_helper::Installed()) {
    MatomoSync();
} else {
    echo "Matomo is not installed or the module is disabled." . fs_filehandler::NewLine();
}
echo "END Matomo sync." . fs_filehandler::NewLine();

/**
 * Bring Matomo's sites, users and access in step with the panel's domains and accounts.
 *
 * Writes a JSON spec, runs the sentora:sync console command and stores the site IDs
 * and tokens it returns. Skips Matomo when nothing changed since the last run.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @return void
 */
function MatomoSync()
{
    global $zdbh;
    matomo_helper::Ensure();
    if (!matomo_helper::PluginActive()) {
        $failed = matomo_helper::SetUp();
        echo 'Matomo: Sentora\'s plugin and settings ' . (count($failed) ? 'could NOT all be set: ' . implode('; ', $failed) : 'set (they were missing)') . '.' . fs_filehandler::NewLine();
        if (!matomo_helper::PluginActive()) {
            return;
        }
    }
    // What the panel has: domains and subdomains, plus the panel's own domain
    $wanted = array();
    $accounts = array();
    $sql = $zdbh->prepare("SELECT v.vh_name_vc, a.ac_id_pk, a.ac_user_vc FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk
        WHERE v.vh_deleted_ts IS NULL AND v.vh_type_in IN (1, 2) AND a.ac_deleted_ts IS NULL");
    $sql->execute();
    $rows = $sql->fetchAll();
    $panel = ctrl_statsdomains::PanelDomain();
    $owner = ctrl_statsdomains::OwnerName();
    if ($panel !== false && $owner !== false) {
        $rows[] = array('vh_name_vc' => $panel, 'ac_id_pk' => ctrl_statsdomains::OWNER_ID, 'ac_user_vc' => $owner);
    }
    foreach ($rows as $r) {
        $d = strtolower((string) $r['vh_name_vc']);
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $d) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $r['ac_user_vc'])) {
            continue;
        }
        $wanted[$d] = (int) $r['ac_id_pk'];
        $accounts[(int) $r['ac_id_pk']]['username'] = $r['ac_user_vc'];
        $accounts[(int) $r['ac_id_pk']]['domains'][] = $d;
    }
    // Known sites; ones whose domain left the panel are deleted in Matomo too
    $known = array();
    foreach ($zdbh->query("SELECT * FROM x_matomo_sites WHERE ms_deleted_in = 0")->fetchAll() as $r) {
        $known[$r['ms_domain_vc']] = $r;
    }
    $spec = array('sites' => array(), 'accounts' => array(), 'delete' => array(), 'delete_users' => array());
    foreach ($wanted as $d => $acc) {
        $spec['sites'][] = array('domain' => $d, 'idsite' => isset($known[$d]) ? (int) $known[$d]['ms_idsite_in'] : 0);
    }
    $gone = array();
    foreach ($known as $d => $r) {
        if (!isset($wanted[$d]) && (int) $r['ms_idsite_in'] > 0) {
            $spec['delete'][] = (int) $r['ms_idsite_in'];
            $gone[] = $d;
        }
    }
    $fqdn = ctrl_options::GetSystemOption('sentora_domain');
    foreach ($accounts as $id => $a) {
        $spec['accounts'][] = array('login' => matomo_helper::Login($a['username']), 'email' => 'matomo-' . $a['username'] . '@' . $fqdn, 'domains' => $a['domains']);
    }
    foreach ($zdbh->query("SELECT mu_acc_fk, mu_login_vc FROM x_matomo_users")->fetchAll() as $u) {
        if (!isset($accounts[(int) $u['mu_acc_fk']])) {
            $spec['delete_users'][] = $u['mu_login_vc'];
        }
    }
    // (also folds the extra rows earlier panels left into one: see matomo_helper::ImporterToken())
    if (matomo_helper::ImporterToken() === '') {
        $spec['importer'] = 'sentora_importer';
        $spec['importer_new_token'] = true;
    }
    // Nothing changed since the last run: skip starting Matomo
    @mkdir(matomo_helper::SPEC_DIR, 0750, true);
    // The panel user (which runs Matomo) reads the files the daemon leaves here
    @chgrp(matomo_helper::SPEC_DIR, 'sentora');
    @chmod(matomo_helper::SPEC_DIR, 0750);
    $json = json_encode($spec);
    $hashFile = matomo_helper::SPEC_DIR . 'spec.sha256';
    $missingTokens = (int) $zdbh->query("SELECT COUNT(*) FROM x_matomo_users WHERE mu_token_vc IS NULL OR mu_token_vc = ''")->fetchColumn();
    if (is_file($hashFile) && trim(file_get_contents($hashFile)) === hash('sha256', $json) && $missingTokens === 0 && !isset($spec['importer'])) {
        echo "No changes." . fs_filehandler::NewLine();
        return;
    }
    $specFile = matomo_helper::SPEC_DIR . 'spec.json';
    file_put_contents($specFile, $json);
    chown($specFile, 'sentora');
    chmod($specFile, 0600);
    list($rc, $out) = matomo_helper::Console(array('sentora:sync', '--spec=' . $specFile));
    @unlink($specFile);
    $lines = explode("\n", trim($out));
    $res = json_decode((string) end($lines), true);
    if ($rc !== 0 || !is_array($res)) {
        // (Matomo's own log prefix says nothing; the exception's message is what tells why)
        echo "Matomo sync FAILED: " . substr(trim(preg_replace('/^ERROR\s+\[[^\]]*\]\s+\d+\s+/m', '', $out)), 0, 1500) . fs_filehandler::NewLine();
        return;
    }
    $up = $zdbh->prepare("INSERT INTO x_matomo_sites (ms_domain_vc, ms_acc_fk, ms_idsite_in, ms_deleted_in) VALUES (:d, :a, :i, 0)
        ON DUPLICATE KEY UPDATE ms_acc_fk = VALUES(ms_acc_fk), ms_idsite_in = VALUES(ms_idsite_in), ms_deleted_in = 0");
    foreach ((array) $res['sites'] as $d => $id) {
        if (isset($wanted[$d])) {
            $up->execute(array(':d' => $d, ':a' => $wanted[$d], ':i' => (int) $id));
        }
    }
    $del = $zdbh->prepare("UPDATE x_matomo_sites SET ms_deleted_in = 1 WHERE ms_domain_vc = :d");
    foreach ($gone as $d) {
        $del->execute(array(':d' => $d));
    }
    $tok = $zdbh->prepare("INSERT INTO x_matomo_users (mu_acc_fk, mu_login_vc, mu_token_vc) VALUES (:a, :l, :t)
        ON DUPLICATE KEY UPDATE mu_login_vc = VALUES(mu_login_vc), mu_token_vc = VALUES(mu_token_vc)");
    foreach ($accounts as $id => $a) {
        $login = matomo_helper::Login($a['username']);
        if (isset($res['tokens'][$login])) {
            $tok->execute(array(':a' => $id, ':l' => $login, ':t' => $res['tokens'][$login]));
        }
    }
    foreach ($spec['delete_users'] as $login) {
        $zdbh->prepare("DELETE FROM x_matomo_users WHERE mu_login_vc = :l")->execute(array(':l' => $login));
    }
    if (isset($res['tokens']['sentora_importer'])) {
        matomo_helper::ImporterToken((string) $res['tokens']['sentora_importer']);
    }
    file_put_contents($hashFile, hash('sha256', $json));
    echo "Matomo: " . count($res['sites']) . " sites, " . count($accounts) . " accounts in step." . fs_filehandler::NewLine();
}

?>
