<?php

/**
 * Protected Directories module: path and file safety helper (pd_safe).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-06
 */

/**
 * Protected Directories: path and file safety, shared by the module and its folder
 * tree (folders.php).
 *
 * The panel's PHP can write into every hosting account's folder, and each account can
 * make symbolic links in its own. So nothing here follows a link: a folder is accepted
 * only if every part of its path under public_html is a real folder, and files are
 * written as a new temporary file in that folder and then renamed into place, which
 * replaces a link that was put there instead of writing through it.
 */
class pd_safe
{
    /**
     * Return the account's hosting folder, without a trailing slash.
     *
     * @param string $username The account's panel user name.
     * @return string The folder path.
     */
    static function Home($username)
    {
        return rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . '/' . $username;
    }

    /**
     * Return the account's public_html folder, without a trailing slash.
     *
     * @param string $username The account's panel user name.
     * @return string The folder path.
     */
    static function PublicRoot($username)
    {
        return self::Home($username) . '/public_html';
    }

    /**
     * Resolve a folder inside the account's public_html, given relative to it ('' = public_html itself).
     *
     * Every part must be a real folder (no links), no part may be hidden (.name),
     * "..", or contain control characters.
     *
     * @param string $username The account's panel user name.
     * @param mixed $rel The relative path (cast to string).
     * @return string|false Its full path, or false.
     */
    static function ResolveDir($username, $rel)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $username)) {
            return false;
        }
        $rel = trim(str_replace('\\', '/', (string) $rel), '/');
        if (strlen($rel) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $rel)) {
            return false;
        }
        $home = self::Home($username);
        $path = self::PublicRoot($username);
        foreach (array($home, $path) as $base) {
            if (is_link($base) || !is_dir($base)) {
                return false;
            }
        }
        if ($rel !== '') {
            foreach (explode('/', $rel) as $part) {
                if ($part === '' || $part === '.' || $part === '..' || $part[0] === '.') {
                    return false;
                }
                $path .= '/' . $part;
                clearstatcache(true, $path);
                if (is_link($path) || !is_dir($path)) {
                    return false;
                }
            }
        }
        // the real path must be exactly that (no link anywhere above either)
        $real = realpath($path);
        if ($real === false || $real !== $path || strpos($real . '/', self::PublicRoot($username) . '/') !== 0) {
            return false;
        }
        return $path;
    }

    /**
     * Return the path relative to public_html of a full folder path.
     *
     * @param string $username The account's panel user name.
     * @param string $full The folder's full path.
     * @return string|false The relative path ('' for public_html), or false when it is outside.
     */
    static function RelPath($username, $full)
    {
        $root = self::PublicRoot($username);
        if ($full === $root) {
            return '';
        }
        return (strpos($full, $root . '/') === 0) ? substr($full, strlen($root) + 1) : false;
    }

    /**
     * Return the folder for the account's password files (outside public_html), made if needed.
     *
     * @param string $username The account's panel user name.
     * @return string|false The folder path, or false when it is a link or can not be made.
     */
    static function PasswdDir($username)
    {
        $home = self::Home($username);
        $dir = $home . '/htpasswd';
        if (is_link($home) || !is_dir($home) || is_link($dir)) {
            return false;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750)) {
            return false;
        }
        clearstatcache(true, $dir);
        return (!is_link($dir) && is_dir($dir) && realpath($dir) === $dir) ? $dir : false;
    }

    /**
     * Return the password file of a protected folder.
     *
     * @param string $username The account's panel user name.
     * @param string $target The protected folder's full path.
     * @return string Full path of the password file.
     */
    static function PasswdFile($username, $target)
    {
        return self::Home($username) . '/htpasswd/htpasswd-' . md5($target);
    }

    /**
     * Read a file's contents, never through a link.
     *
     * @param string $file Full path of the file.
     * @return string|false The contents, '' when it does not exist, false when refused (link, not a plain file, over 1 MB).
     */
    static function Read($file)
    {
        clearstatcache(true, $file);
        if (is_link($file)) {
            return false;
        }
        if (!file_exists($file)) {
            return '';
        }
        if (!is_file($file) || filesize($file) > 1048576) {
            return false;
        }
        $s = @file_get_contents($file);
        return ($s === false) ? false : $s;
    }

    /**
     * Write a file in a folder that was checked.
     *
     * A new temporary file (O_EXCL, never an existing file or link), then renamed
     * over the target (a link there is replaced, not followed). The folder is
     * checked again right before.
     *
     * @param string $dir The folder's full path, without a trailing slash.
     * @param string $name The file name (no slashes).
     * @param string $content The contents.
     * @param int $mode Permissions, e.g. 0644.
     * @return bool True when written.
     */
    static function Write($dir, $name, $content, $mode)
    {
        clearstatcache(true, $dir);
        if (is_link($dir) || !is_dir($dir) || realpath($dir) !== $dir || strpos($name, '/') !== false) {
            return false;
        }
        $target = $dir . '/' . $name;
        clearstatcache(true, $target);
        if (file_exists($target) && !is_link($target) && !is_file($target)) {
            return false; // a folder or device with that name
        }
        $tmp = $dir . '/.sentora-tmp-' . bin2hex(random_bytes(8));
        $fp = @fopen($tmp, 'x');
        if ($fp === false) {
            return false;
        }
        $ok = (fwrite($fp, $content) === strlen($content));
        $ok = fclose($fp) && $ok;
        @chmod($tmp, $mode);
        if (!$ok || !@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Remove a file, never through a link (a link of that name is removed itself).
     *
     * @param string $file Full path of the file.
     * @return bool True when removed or not there.
     */
    static function Remove($file)
    {
        clearstatcache(true, $file);
        if (is_link($file) || is_file($file)) {
            return @unlink($file);
        }
        return !file_exists($file);
    }
}
