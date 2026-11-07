<?php
/**
 * The panel, every hour: is a newer panel published?
 *
 * Asks "sentora-update --status" (GitHub's release API only, nothing is downloaded) and keeps
 * one notice for the administrators (key sentora-update): shown while an update is available,
 * closed by an administrator only until the next login, and taken away once the panel is up to
 * date (sentora-update takes it away itself after updating). When GitHub can not be asked, the
 * notice stays as it was.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
echo fs_filehandler::NewLine() . "START Panel update check" . fs_filehandler::NewLine();
echo SentoraUpdateNotice() . fs_filehandler::NewLine();
echo "END Panel update check" . fs_filehandler::NewLine();

/**
 * Check for a newer panel and send, keep or take away the administrators' notice.
 *
 * @return string What it found, for the daemon log.
 */
function SentoraUpdateNotice()
{
    $bin = realpath('bin/sentora-update');
    if ($bin === false || !function_exists('exec') || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        return 'Skipped (needs root and bin/sentora-update).';
    }
    $out = array();
    exec(escapeshellarg($bin) . ' --status 2> /dev/null', $out, $rc);
    $line = trim((string) end($out));
    if ($rc === 0 && $line === 'current') {
        $n = ctrl_notices::Withdraw('sentora-update', 0);
        return 'The panel is up to date.' . ($n ? ' Took the update notice away.' : '');
    }
    if ($rc !== 100 || !preg_match('/^available (\S+) (\S+) (\S+)$/', $line, $m)) {
        return 'Could not check for a newer panel (' . ($line !== '' ? $line : 'no answer') . '); the next check is in an hour.';
    }
    list(, $tag, $date) = $m;
    $when = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? date('j M Y', strtotime($date)) : '';
    $body = 'A newer version of the Sentora panel is published' . ($when !== '' ? ' (' . $when . ', release ' . $tag . ')' : ' (release ' . $tag . ')') . '. '
        // (no links: the notice only says where to go; the change log is linked on the Panel Update page)
        . 'In the panel: **Admin > Server Admin > Panel Update**, or run this as root on the server\'s console:' . "\n"
        . '- **sentora-update**' . "\n"
        . 'Either way the panel is backed up first. If the panel does not answer afterwards, go back from the server\'s console, as root: **sentora-update --rollback**';
    $id = ctrl_notices::Send(array('scope' => 'groups', 'targets' => array(1), 'type' => 'info', 'title' => 'A panel update is available',
        'body' => $body, 'closable' => 'login', 'key' => 'sentora-update', 'source' => 'sentora-update'));
    return $id ? 'An update is available (release ' . $tag . ($when !== '' ? ', ' . $when : '') . '): the administrators have a notice.'
        : 'An update is available, but the notice was refused: ' . ctrl_notices::$error;
}
