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
