<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * System command execution class.
 * @package zpanelx
 * @subpackage dryden -> ctrl
 * @version 1.1.0
 * @author Kevin Andrews (kandrews@zpanelcp.com)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-12
 */
class ctrl_system
{

    /**
     * Safely run an escaped system() command.
     * @param string $command The command of which to be executed.
     * @param string[]|string $args Any arguments seperated by a space should be in a seperate array value.
     * @return int The command's exit status.
     */
    static function systemCommand($command, $args)
    {
        $escapedCommand = escapeshellcmd($command);
        if (is_array($args)) {
            foreach ($args as $arg) {
                $escapedCommand .= ' ' . escapeshellarg($arg);
            }
        } else {
            $escapedCommand .= ' ' . escapeshellarg($args);
        }
        system(self::OwnFiles($escapedCommand), $systemReturnValue);
        return $systemReturnValue;
    }

    /**
     * Start a long job in the background and return at once (a copy to the backup machine, a restore,
     * signature updates, a scan), so it runs to its end whoever started the daemon.
     *
     * Started by root on a system with systemd, the job runs as a short-lived systemd job of its own
     * (systemd-run, a unit sentora-job-...): the daemon may itself run inside a service (the panel's
     * "Run the daemon now" and its other buttons go through zsudo's service), and systemd stops every
     * process a service left behind when the service ends, which cut a full restore off half way
     * (owner's panel, 2026-11-13). Elsewhere (no systemd, not root, Windows): setsid, as before. Either
     * way the job gets only standard input, output and error (see OwnFiles()).
     *
     * @param string $cmd A complete, already escaped shell command with its own output redirection
     *                    (e.g. "/path/tool --now > /dev/null 2>&1"); no trailing &, no input.
     * @return bool True when it was started.
     */
    static function Background($cmd)
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            pclose(popen('start /B ' . $cmd, 'r'));
            return true;
        }
        $run = is_executable('/usr/bin/systemd-run') ? '/usr/bin/systemd-run' : (is_executable('/bin/systemd-run') ? '/bin/systemd-run' : '');
        if ($run !== '' && is_dir('/run/systemd/system') && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $unit = 'sentora-job-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
            $out = array();
            $rc = 1;
            exec(self::OwnFiles($run . ' --quiet --collect --no-block --unit=' . $unit . ' /bin/sh -c ' . escapeshellarg($cmd . ' < /dev/null') . ' > /dev/null 2>&1'), $out, $rc);
            if ((int) $rc === 0) {
                return true;
            }
        }
        exec(self::OwnFiles('setsid nohup /bin/sh -c ' . escapeshellarg($cmd) . ' < /dev/null > /dev/null 2>&1 &'), $out, $rc);
        return (int) $rc === 0;
    }

    /**
     * A shell command that runs with only its standard input, output and error open.
     *
     * The daemon holds files open (its own script, the run lock) that every program it starts would
     * otherwise inherit; a confined program (SELinux) is then refused them and the refusal is logged,
     * though the program never meant to touch them. On Windows the command is returned unchanged.
     *
     * @param string $cmd A complete, already escaped shell command.
     * @return string
     */
    static function OwnFiles($cmd)
    {
        if (DIRECTORY_SEPARATOR === '\\' || !is_dir('/proc/self/fd')) {
            return $cmd;
        }
        return '/bin/bash -c ' . escapeshellarg('for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n<&-"; done; ' . $cmd);
    }

}
