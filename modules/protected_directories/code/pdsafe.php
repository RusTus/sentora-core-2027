<?php

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
    /** the account's hosting folder, without a trailing slash */
    static function Home($username)
    {
        return rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . '/' . $username;
    }

    static function PublicRoot($username)
    {
        return self::Home($username) . '/public_html';
    }

    /**
     * A folder inside the account's public_html, given relative to it ('' = public_html
     * itself): its full path, or false. Every part must be a real folder (no links), no
     * part may be hidden (.name), "..", or contain control characters.
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

    /** the path relative to public_html of a full folder path ('' for public_html), or false */
    static function RelPath($username, $full)
    {
        $root = self::PublicRoot($username);
        if ($full === $root) {
            return '';
        }
        return (strpos($full, $root . '/') === 0) ? substr($full, strlen($root) + 1) : false;
    }

    /** the folder for the account's password files (outside public_html), made if needed */
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

    /** the password file of a protected folder */
    static function PasswdFile($username, $target)
    {
        return self::Home($username) . '/htpasswd/htpasswd-' . md5($target);
    }

    /** a file's contents, never through a link; '' when it does not exist, false when refused */
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
     * Write a file in a folder that was checked: a new temporary file (O_EXCL, never an
     * existing file or link), then renamed over the target (a link there is replaced,
     * not followed). The folder is checked again right before.
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

    /** remove a file, never through a link (a link of that name is removed itself) */
    static function Remove($file)
    {
        clearstatcache(true, $file);
        if (is_link($file) || is_file($file)) {
            return @unlink($file);
        }
        return !file_exists($file);
    }
}
