<?php

/**
 * The .htaccess lines of a website moved from an old Sentora server that this server's Apache
 * refuses with error 500, and how to change them so the site works the same.
 *
 * A hosted site may set in .htaccess only what its vhost allows (AllowOverride AuthConfig FileInfo
 * Indexes Limit Options=Indexes,MultiViews,SymLinksIfOwnerMatch, plus FollowSymLinks for a domain
 * with "Allow FollowSymLinks" on), and PHP runs in PHP-FPM, not in Apache (mod_php):
 *
 * - Options FollowSymLinks          -> SymLinksIfOwnerMatch (links to the site's own files keep working)
 * - Options All                     -> +SymLinksIfOwnerMatch (All also means CGI and server-side includes)
 * - Options ExecCGI / Includes / IncludesNOEXEC: left out (not allowed for hosted sites)
 * - php_value / php_flag (mod_php only) -> a .user.ini beside it (PHP-FPM reads that), the line commented out
 * - php_admin_value / php_admin_flag: commented out (PHP-FPM takes them only from the server's settings)
 *
 * Lines inside <IfModule mod_php...> blocks are left alone: Apache skips them here.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
class sys_htaccessfix
{
    /** The Options a hosted site may set (lowercase), FollowSymLinks only with the domain's switch. */
    private static $allowed = array('indexes', 'multiviews', 'symlinksifownermatch', 'none');

    /** The note put before each changed line. */
    const MARK = '# Sentora-2027 (moved site):';

    /**
     * What would change in one .htaccess file.
     *
     * @param string $text The file's content.
     * @param bool $allowSymlinks True for a domain with "Allow FollowSymLinks" on: FollowSymLinks stays.
     * @return array{text:string, changes:array<int, array{line:int, from:string, to:string, why:string}>, userini:array<string, string>}
     *         The new content, each change, and the PHP settings for a .user.ini (name => value).
     */
    static function Fix($text, $allowSymlinks = false)
    {
        $lines = preg_split('/\r?\n/', (string) $text);
        $changes = array();
        $ini = array();
        $stack = array();   // the open <IfModule> blocks: true for a mod_php one (Apache skips it here)
        foreach ($lines as $i => $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#') {
                continue;
            }
            if (preg_match('/^<IfModule\s+!?\s*([^>\s]+)\s*>/i', $t, $m)) {
                $stack[] = (bool) preg_match('/^(mod_php\d*(\.c)?|php\d*_module)$/i', trim($m[1], '"'));
                continue;
            }
            if (preg_match('/^<\/IfModule\s*>/i', $t)) {
                array_pop($stack);
                continue;
            }
            if (in_array(true, $stack, true)) {
                continue;
            }
            $indent = (string) preg_replace('/\S.*$/s', '', $line);
            if (preg_match('/^Options\s+(.+)$/i', $t, $m)) {
                $new = self::FixOptions($m[1], $allowSymlinks, $why);
                if ($new !== null) {
                    $to = $new === '' ? '' : $indent . 'Options ' . $new;
                    $lines[$i] = $indent . self::MARK . ' ' . $t . ($to === '' ? '' : "\n" . $to);
                    $changes[] = array('line' => $i + 1, 'from' => $t, 'to' => $to === '' ? '(removed)' : trim($to), 'why' => $why);
                }
                continue;
            }
            if (preg_match('/^php_(value|flag)\s+(\S+)\s+(.*)$/i', $t, $m)) {
                $val = trim($m[3]);
                $val = (strlen($val) > 1 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]) ? substr($val, 1, -1) : $val;
                if (strtolower($m[1]) === 'flag') {
                    $val = in_array(strtolower($val), array('on', '1', 'true', 'yes'), true) ? 'On' : 'Off';
                }
                $ini[$m[2]] = $val;
                $lines[$i] = $indent . self::MARK . ' ' . $t;
                $changes[] = array('line' => $i + 1, 'from' => $t, 'to' => '.user.ini: ' . $m[2] . ' = ' . self::IniValue($val), 'why' => 'mod_php only');
                continue;
            }
            if (preg_match('/^php_admin_(value|flag)\s+/i', $t)) {
                $lines[$i] = $indent . self::MARK . ' ' . $t;
                $changes[] = array('line' => $i + 1, 'from' => $t, 'to' => '(removed)', 'why' => 'mod_php only; a site can not set it');
            }
        }
        return array('text' => implode("\n", $lines), 'changes' => $changes, 'userini' => $ini);
    }

    /**
     * An Options line's words, changed so this server allows them.
     *
     * @param string $words The words after "Options".
     * @param bool $allowSymlinks FollowSymLinks allowed (the domain's switch).
     * @param string $why Set to why it changes.
     * @return string|null The new words, '' when nothing allowed is left (the line goes), or null when it is fine as it is.
     */
    private static function FixOptions($words, $allowSymlinks, &$why)
    {
        $why = '';
        $ok = self::$allowed;
        if ($allowSymlinks) {
            $ok[] = 'followsymlinks';
        }
        $items = preg_split('/\s+/', trim(preg_replace('/\s+#.*$/', '', $words)));
        $signed = false;
        foreach ($items as $w) {
            if ($w !== '' && ($w[0] === '+' || $w[0] === '-')) {
                $signed = true;
            }
        }
        $out = array();
        $changed = false;
        $reasons = array();
        foreach ($items as $w) {
            if ($w === '') {
                continue;
            }
            $sign = ($w[0] === '+' || $w[0] === '-') ? $w[0] : '';
            $name = strtolower(ltrim($w, '+-'));
            if (in_array($name, $ok, true)) {
                $out[] = $w;
                continue;
            }
            $changed = true;
            if ($name === 'followsymlinks') {
                $reasons['FollowSymLinks'] = true;
                if ($sign !== '-') {   // (switching it off is what this server does anyway)
                    $out[] = ($sign !== '' ? $sign : '') . 'SymLinksIfOwnerMatch';
                }
            } elseif ($name === 'all') {
                $reasons['All'] = true;
                $out[] = '+SymLinksIfOwnerMatch';
                $signed = true;
            } else {
                $reasons[ltrim($w, '+-')] = true;
            }
        }
        if (!$changed) {
            return null;
        }
        $why = implode(', ', array_keys($reasons)) . ' not allowed for websites here';
        // Apache wants either every word signed or none: with any +/- left, sign the rest
        if ($signed) {
            foreach ($out as &$w) {
                if ($w[0] !== '+' && $w[0] !== '-') {
                    $w = strtolower($w) === 'none' ? $w : '+' . $w;
                }
            }
            unset($w);
        }
        $out = array_values(array_unique($out));
        return implode(' ', $out);
    }

    /**
     * A value as it is written in a .user.ini (quoted when it has spaces or characters that need it).
     *
     * @param string $v
     * @return string
     */
    static function IniValue($v)
    {
        if (preg_match('/^(On|Off|-?\d+[KMG]?|[A-Za-z0-9_.\/:-]+)$/', $v)) {
            return $v;
        }
        return '"' . str_replace('"', '\"', $v) . '"';
    }

    /**
     * Add PHP settings to the .user.ini in a folder (a setting already there is left as it is).
     *
     * @param string $dir The folder.
     * @param array<string, string> $settings name => value.
     * @return string[] The settings added.
     */
    static function MergeUserIni($dir, array $settings)
    {
        $file = rtrim($dir, '/') . '/.user.ini';
        $have = is_file($file) ? (string) file_get_contents($file) : '';
        $add = array();
        foreach ($settings as $k => $v) {
            if (!preg_match('/^\s*' . preg_quote($k, '/') . '\s*=/mi', $have)) {
                $add[] = $k . ' = ' . self::IniValue($v);
            }
        }
        if ($add) {
            file_put_contents($file, rtrim($have) . ($have !== '' ? "\n" : '') . "; from .htaccess (php_value/php_flag), moved to Sentora-2027\n" . implode("\n", $add) . "\n");
        }
        return $add;
    }
}
