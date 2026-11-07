<?php

/**
 * FollowSymLinks for hosted websites: off, except for a domain an administrator switched it on for
 * (Apache Admin, for moving an old site whose .htaccess has "Options +FollowSymLinks").
 *
 * Off, a website gets "Options +SymLinksIfOwnerMatch": a symbolic link is followed only when it
 * and its target have the same owner. Apache can read every account, so following any link would
 * let one account publish another account's files (a link named x.txt to its wp-config.php).
 * While it is off, the custom directives (the domain's own, and those for every website or the
 * panel) may not switch it back on: such lines are refused when saved, and commented out when the
 * daemon writes the configuration.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
class sys_symlinks
{
    /** The x_vhosts column that holds the switch (0 off, 1 on). */
    const COLUMN = 'vh_symlinks_in';

    /**
     * Add the switch's column to x_vhosts on a server installed before it (off for every domain).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return bool True when the column is there.
     */
    static function EnsureColumn()
    {
        global $zdbh;
        static $done = null;
        if ($done !== null) {
            return $done;
        }
        try {
            $has = (int) $zdbh->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_vhosts' AND COLUMN_NAME = '" . self::COLUMN . "'")->fetchColumn();
            if (!$has) {
                $zdbh->exec("ALTER TABLE x_vhosts ADD COLUMN " . self::COLUMN . " TINYINT(1) NOT NULL DEFAULT 0");
            }
            $done = true;
        } catch (Exception $e) {
            $done = false;
        }
        return $done;
    }

    /**
     * Is FollowSymLinks switched on for this domain?
     *
     * @param array<string, mixed> $vhost An x_vhosts row.
     * @return bool
     */
    static function Allowed($vhost)
    {
        return isset($vhost[self::COLUMN]) && (int) $vhost[self::COLUMN] === 1;
    }

    /**
     * The lines of a custom entry that could switch FollowSymLinks on: Options with FollowSymLinks
     * or All, AllowOverride that lets .htaccess set it (All, Options without a list, or a list with
     * FollowSymLinks or All), and AllowOverrideList. Lines continued with a backslash count as one.
     *
     * @param string $text The custom entry.
     * @return array<int, string> The offending lines, as written (joined when continued).
     */
    static function Offending($text)
    {
        $found = array();
        foreach (preg_split('/\r?\n/', self::Join((string) $text)) as $line) {
            if (self::IsOffending($line)) {
                $found[] = trim($line);
            }
        }
        return $found;
    }

    /**
     * The custom entry with the lines that could switch FollowSymLinks on commented out.
     *
     * @param string $text The custom entry.
     * @return string
     */
    static function Filter($text)
    {
        $lines = preg_split('/\r?\n/', self::Join((string) $text));
        foreach ($lines as &$line) {
            if (self::IsOffending($line)) {
                $line = preg_replace('/^(\s*)/', '$1# Not allowed (FollowSymLinks is off for this domain, see Apache Admin): ', $line, 1);
            }
        }
        unset($line);
        return implode("\n", $lines);
    }

    /**
     * Apache joins a line that ends in a backslash with the next one: do the same, so a
     * directive can not be split to slip past the check.
     *
     * @param string $text
     * @return string
     */
    private static function Join($text)
    {
        return preg_replace('/\\\\[ \t]*\r?\n[ \t]*/', ' ', $text);
    }

    /**
     * @param string $line One configuration line.
     * @return bool True when it could switch FollowSymLinks on.
     */
    private static function IsOffending($line)
    {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            return false;
        }
        if (preg_match('/^Options\s+(.*)$/i', $line, $m)) {
            // "+FollowSymLinks", "FollowSymLinks" and "All" turn it on; "-FollowSymLinks" turns it off
            return (bool) preg_match('/(^|\s)[+]?(FollowSymLinks|All)(\s|$)/i', $m[1]);
        }
        if (preg_match('/^AllowOverride\s+(.*)$/i', $line, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) as $word) {
                if (preg_match('/^All$/i', $word) || preg_match('/^Options$/i', $word)) {
                    return true;
                }
                if (preg_match('/^Options=(.*)$/i', $word, $o) && preg_match('/(^|,)(FollowSymLinks|All)(,|$)/i', $o[1])) {
                    return true;
                }
            }
            return false;
        }
        return (bool) preg_match('/^AllowOverrideList\b/i', $line);
    }
}
