<?php

/**
 * PHP Versions, once a day: the domains still using a PHP version that gets no
 * security fixes any more, or stops getting them within 90 days, so the
 * administrator sees them in the daemon log.
 *
 * Each client with such domains also gets a personal notice at the top of the panel,
 * when there is a newer version it can choose instead (Domains, Sub-domains): one
 * notice per client (key php-support-<id>), updated when its list changes and taken
 * away when no domain needs it any more.
 *
 * Written by RusTus, 2026-11-07
 */
global $zdbh; // hooks are included inside a function
echo fs_filehandler::NewLine() . "START PHP version support" . fs_filehandler::NewLine();
$rows = $zdbh->query("SELECT v.vh_name_vc, v.vh_php_version_vc, a.ac_user_vc, a.ac_id_pk FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk
    WHERE v.vh_deleted_ts IS NULL AND a.ac_deleted_ts IS NULL ORDER BY a.ac_user_vc, v.vh_name_vc")->fetchAll();
$ended = 0;
$soon = 0;
$perClient = array(); // account ID => list of [domain, version, ended?]
foreach ($rows as $row) {
    $run = sys_phpversions::Effective((string) $row['vh_php_version_vc']);
    $days = sys_phpversions::DaysLeft($run);
    if ($days === null || $days > 90) {
        continue;
    }
    if (PhpSupportBetter($run)) {
        $perClient[(int) $row['ac_id_pk']][] = array($row['vh_name_vc'], $run, $days < 0);
    }
    if ($days < 0) {
        $ended++;
        echo "UNSUPPORTED: " . $row['vh_name_vc'] . " (" . $row['ac_user_vc'] . ") runs PHP " . $run . ", no security fixes since " . sys_phpversions::$supportEnds[$run] . fs_filehandler::NewLine();
    } else {
        $soon++;
        echo "Ending soon: " . $row['vh_name_vc'] . " (" . $row['ac_user_vc'] . ") runs PHP " . $run . ", security fixes until " . sys_phpversions::$supportEnds[$run] . " (" . $days . " days)" . fs_filehandler::NewLine();
    }
}
echo count($rows) . " domain(s): " . ($ended + $soon === 0 ? "all on PHP versions with security fixes for more than 90 days"
    : $ended . " on an unsupported PHP version, " . $soon . " ending within 90 days (choose a newer version in Domains)") . "." . fs_filehandler::NewLine();
$sent = PhpSupportNotices($perClient);
echo $sent . " client(s) have a notice about it in the panel." . fs_filehandler::NewLine();
echo "END PHP version support" . fs_filehandler::NewLine();

/**
 * Whether a domain on this version could move to a newer one that gets security fixes for
 * more than 90 days (the default version, or one the administrator enabled).
 *
 * @param string $run The version the domain runs, for example '8.1'.
 * @return bool True when there is such a version to choose.
 */
function PhpSupportBetter($run)
{
    foreach (array_merge(array(sys_phpversions::DefaultVersion()), sys_phpversions::Enabled()) as $v) {
        $left = sys_phpversions::DaysLeft($v);
        if (version_compare($v, $run, '>') && ($left === null || $left > 90)) {
            return true;
        }
    }
    return false;
}

/**
 * Send, update or take away each client's notice about its domains on old PHP versions.
 *
 * The text names each domain, its version and the date its security fixes end(ed); it only
 * changes when that list changes, so a client who closed it does not see it again every day.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @param array<int, array<int, array{0: string, 1: string, 2: bool}>> $perClient Account ID => [domain, version, ended].
 * @return int How many clients have the notice now.
 */
function PhpSupportNotices(array $perClient)
{
    global $zdbh;
    $t = function ($s) { return $s; }; // the daemon has no account, so no language to translate to
    foreach ($perClient as $uid => $list) {
        $anyEnded = false;
        $allEnded = true;
        $lines = array();
        foreach ($list as $d) {
            $anyEnded = $anyEnded || $d[2];
            $allEnded = $allEnded && $d[2];
            $date = date('j M Y', strtotime(sys_phpversions::$supportEnds[$d[1]]));
            $lines[] = '- **' . $d[0] . '**: PHP ' . $d[1] . ', ' . ($d[2] ? $t('no security fixes since') : $t('security fixes end on')) . ' ' . $date;
        }
        $body = $t($allEnded ? 'These websites run a PHP version that no longer gets security fixes:'
            : ($anyEnded ? 'These websites run a PHP version that no longer gets security fixes, or soon will not:' : 'These websites run a PHP version that stops getting security fixes soon:'))
            . "\n" . implode("\n", $lines) . "\n"
            . $t('Choose a newer PHP version for them in Domains (or Sub-domains, for a sub-domain), then check that the website still works. Ask us if you need help.');
        ctrl_notices::ToUser($uid, $body, array('type' => $anyEnded ? 'warning' : 'info', 'title' => $t('Update the PHP version of your websites'),
            'key' => 'php-support-' . $uid, 'source' => 'php_versions'));
    }
    // clients that no longer need it
    ctrl_notices::Ready();
    $q = $zdbh->query("SELECT nt_key_vc FROM x_notices WHERE nt_acc_fk = 0 AND nt_key_vc LIKE 'php-support-%' AND nt_deleted_ts IS NULL");
    foreach ($q ? $q->fetchAll(PDO::FETCH_COLUMN) : array() as $key) {
        if (!isset($perClient[(int) substr($key, 12)])) {
            ctrl_notices::Withdraw($key, 0);
        }
    }
    return count($perClient);
}
