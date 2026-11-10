<?php

/**
 * Keep /var/lib/sentora safe for the root jobs that write into it.
 *
 * The daemon, sentora-update and other root jobs write state files there (alerts, SpamAV status,
 * widget results, restore catalog, the live PHP of each domain, the daemon's lock). The folder used
 * to belong to the panel's user, so whoever controlled the panel's PHP could plant a link under one
 * of those names (to /etc/shadow, say) and have root write through it. Now:
 *
 * - the folder belongs to root (755: the panel still reads it); the panel keeps its own subfolders
 *   (sessions, tmp, roundcube, module-uploads) and the log files it writes (LOGS), made in advance;
 * - links found at its top level, or in the root jobs' own subfolders, are removed (a legitimate
 *   install has none), and those subfolders belong to root.
 *
 * Secure() runs first thing in every daemon run (before it opens its lock there), so a server is
 * set right within 5 minutes of an update; sentora-update and the installer set it up too.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-10
 */
class sys_statedir
{
    const DIR = '/var/lib/sentora';

    /** The files the panel itself writes at the top level (it can no longer make new ones there). */
    const LOGS = array('sentora-auth.log', 'phpmyadmin-auth.log', 'twofactor.log', 'password-reveal.log', 'shadowing.log', 'panel-update.log');

    /** Subfolders only root jobs write into. */
    const ROOT_DIRS = array('accounts', 'clientftp', 'goaccess', 'matomo', 'sitehealth', 'monitor');

    /**
     * Make the folder safe (see the class comment). Root only; does nothing for anyone else.
     *
     * @param string $panelUser The panel's Linux user (default 'sentora').
     * @return string[] What was changed (empty when everything was already right).
     */
    static function Secure($panelUser = 'sentora')
    {
        $did = array();
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !is_dir(self::DIR) || is_link(self::DIR)) {
            return $did;
        }
        $pw = posix_getpwnam($panelUser);
        // the folder itself: root's, readable by all
        $st = stat(self::DIR);
        if ($st['uid'] !== 0 || $st['gid'] !== 0) {
            chown(self::DIR, 0);
            chgrp(self::DIR, 0);
            $did[] = self::DIR . ' now belongs to root';
        }
        if (($st['mode'] & 07777) !== 0755) {
            chmod(self::DIR, 0755);
        }
        // links at the top level: none belongs there
        foreach ((array) scandir(self::DIR) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = self::DIR . '/' . $name;
            if (is_link($path)) {
                unlink($path);
                $did[] = 'removed the link ' . $path;
            }
        }
        // the root jobs' own subfolders: root's, and no links in them
        foreach (self::ROOT_DIRS as $sub) {
            $dir = self::DIR . '/' . $sub;
            if (is_link($dir) || !is_dir($dir)) {
                continue;
            }
            $s = stat($dir);
            if ($s['uid'] !== 0) {
                chown($dir, 0);
                $did[] = $dir . ' now belongs to root';
            }
            foreach ((array) scandir($dir) as $name) {
                if ($name !== '.' && $name !== '..' && is_link($dir . '/' . $name)) {
                    unlink($dir . '/' . $name);
                    $did[] = 'removed the link ' . $dir . '/' . $name;
                }
            }
        }
        // the panel's log files: made in advance, the panel's own
        if ($pw !== false) {
            foreach (self::LOGS as $name) {
                $path = self::DIR . '/' . $name;
                if (is_link($path) || is_dir($path)) {
                    continue;
                }
                if (!file_exists($path)) {
                    touch($path);
                    chmod($path, 0640);
                    $did[] = 'made ' . $path;
                }
                $s = stat($path);
                if ($s['uid'] !== (int) $pw['uid'] || $s['gid'] !== (int) $pw['gid']) {
                    chown($path, (int) $pw['uid']);
                    chgrp($path, (int) $pw['gid']);
                }
            }
        }
        return $did;
    }
}
