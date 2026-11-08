<?php

/**
 * Protected Directories: password-protect folders of the account's website with
 * Apache basic authentication.
 *
 * Each protected folder gets a marked block in its .htaccess (AuthType Basic, the
 * message as AuthName, its own password file, Require valid-user) and a password file
 * in the account's htpasswd/ folder, outside public_html. Passwords are stored as
 * bcrypt ($2y$), which Apache checks itself.
 *
 * Safety: every record is read and changed with the account's own id in the SQL;
 * folders are chosen in a tree of the account's own public_html and checked again
 * on submit (pd_safe: real folders only, no links, nothing hidden or outside);
 * files are written through a temporary file and a rename, so a symbolic link put in
 * place of .htaccess or a password file is replaced, never written through; the
 * message and the usernames are limited to plain characters, so nothing else can be
 * added to either file.
 *
 * Updated by RusTus, 2026-11-07
 */
require_once dirname(__FILE__) . '/pdsafe.php';

/**
 * Protected Directories module: password-protect folders of the account's website.
 */
class module_controller extends ctrl_module
{
    const BEGIN = '# BEGIN Sentora protected directory (managed by the panel: do not edit this block)';
    const END = '# END Sentora protected directory';

    /** @var string[] Success messages for getResult() (translated; escaped when shown). */
    static $messages = array();
    /** @var string[] Error messages for getResult() (translated and escaped when shown). */
    static $errors = array();

    /**
     * Escape a value for HTML.
     *
     * @param mixed $s The value (cast to string).
     * @return string The escaped text.
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Return the logged-in user's details (read once per request).
     *
     * @return array<string, string> User details from ctrl_users::GetUserDetail().
     */
    static function Me()
    {
        static $me = null;
        if ($me === null) {
            $me = ctrl_users::GetUserDetail();
        }
        return $me;
    }

    /**
     * Add an error message for getResult().
     *
     * @param string $text The message (English; translated when shown).
     * @return false Always false, so callers can return it.
     */
    static function Fail($text)
    {
        self::$errors[] = $text;
        return false;
    }

    /**
     * Clean the message shown in the login box: plain characters only, never a quote or a line break.
     *
     * @param mixed $m The submitted message (cast to string).
     * @return string|false The message ('Protected area' when empty), or false when it has other characters or is too long.
     */
    static function CleanMessage($m)
    {
        $m = trim(preg_replace('/\s+/', ' ', (string) $m));
        if ($m === '') {
            $m = 'Protected area';
        }
        if (!preg_match('/^[\p{L}\p{N} .,:;!()_\-\/@&+]{1,64}$/u', $m)) {
            return false;
        }
        return $m;
    }

    /**
     * Check a username for a protected folder: 1 to 32 letters, digits, '.', '_' or '-'.
     *
     * @param mixed $u The username (only strings pass).
     * @return bool True when valid.
     */
    static function ValidUsername($u)
    {
        return is_string($u) && preg_match('/^[A-Za-z0-9._-]{1,32}$/', $u) === 1;
    }

    /**
     * Check a password: 8 to 128 characters, no control characters.
     *
     * @param mixed $p The password (only strings pass).
     * @return bool True when valid.
     */
    static function ValidPassword($p)
    {
        return is_string($p) && strlen($p) >= 8 && strlen($p) <= 128 && !preg_match('/[\x00-\x1f\x7f]/', $p);
    }

    // --- records (always the account's own) ----------------------------------------------

    /**
     * Return a protected folder of this account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $id The x_htpasswd_file ID (cast to int).
     * @return array<int|string, mixed>|false The x_htpasswd_file row, or false when not found.
     */
    static function Protection($id)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_htpasswd_file WHERE x_htpasswd_file_id = :id AND x_htpasswd_sentora_user_id = :u AND x_htpasswd_file_deleted IS NULL");
        $q->execute(array(':id' => (int) $id, ':u' => (int) self::Me()['userid']));
        $row = $q->fetch();
        return $row ? $row : false;
    }

    /**
     * List the users of a protected folder of this account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $fileId The x_htpasswd_file ID (cast to int).
     * @return array<int, array<int|string, mixed>> x_htpasswd_user rows, by username.
     */
    static function Users($fileId)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT u.* FROM x_htpasswd_user u JOIN x_htpasswd_mapper m ON m.x_htpasswd_user_id = u.x_htpasswd_user_id
            JOIN x_htpasswd_file f ON f.x_htpasswd_file_id = m.x_htpasswd_file_id
            WHERE m.x_htpasswd_file_id = :f AND f.x_htpasswd_sentora_user_id = :u AND u.x_htpasswd_sentora_user_id = :u2
            AND u.x_htpasswd_user_deleted IS NULL ORDER BY u.x_htpasswd_user_username");
        $q->execute(array(':f' => (int) $fileId, ':u' => (int) self::Me()['userid'], ':u2' => (int) self::Me()['userid']));
        return $q->fetchAll();
    }

    // --- the files ------------------------------------------------------------------------

    /**
     * Return the folder of a record, checked again (it may have been replaced by a link since).
     *
     * @param array<int|string,mixed> $row The x_htpasswd_file row.
     * @return string|false The folder's full path, or false when it is no longer a plain folder of the website.
     */
    static function TargetDir($row)
    {
        $rel = pd_safe::RelPath(self::Me()['username'], (string) $row['x_htpasswd_file_target']);
        $dir = ($rel === false) ? false : pd_safe::ResolveDir(self::Me()['username'], $rel);
        return ($dir !== false && $dir === $row['x_htpasswd_file_target']) ? $dir : false;
    }

    /**
     * Remove this panel's block from .htaccess contents (and the block older versions wrote).
     *
     * @param string $htaccess The current .htaccess contents.
     * @param string $passwdFile Full path of the folder's password file.
     * @return string The rest of the file ('' when nothing else is left).
     */
    static function StripBlock($htaccess, $passwdFile)
    {
        // the block, and the blank line written after it
        $s = preg_replace('/' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '(\R\R?)?/s', '', $htaccess);
        $s = preg_replace('/AuthName "[^"\r\n]*"\R+AuthType Basic\R+AuthUserFile ' . preg_quote($passwdFile, '/') . '\R+Require valid-user\R?/', '', $s);
        return trim($s) === '' ? '' : ltrim(rtrim($s), "\r\n") . "\n";
    }

    /**
     * Write a folder's .htaccess with or without the protection block.
     *
     * Removes the file when nothing else is left in it.
     *
     * @param string $dir The folder's full path (checked).
     * @param string $passwdFile Full path of the folder's password file.
     * @param string $message The login box message (already cleaned).
     * @param bool $protect True to add the block, false to only remove it.
     * @return bool True when written (or nothing to change); false with an error added.
     */
    static function WriteHtaccess($dir, $passwdFile, $message, $protect)
    {
        $current = pd_safe::Read($dir . '/.htaccess');
        if ($current === false) {
            return self::Fail('The .htaccess in that folder is not a plain file (for example a link), so it is not changed.');
        }
        $new = self::StripBlock($current, $passwdFile);
        if ($protect) {
            $block = self::BEGIN . "\nAuthType Basic\nAuthName \"" . $message . "\"\nAuthUserFile " . $passwdFile . "\nRequire valid-user\n" . self::END . "\n";
            $new = $block . ($new === '' ? '' : "\n" . $new);
        }
        if ($new === '' && $current !== '') {
            return pd_safe::Remove($dir . '/.htaccess') ? true : self::Fail('Could not remove the .htaccess file.');
        }
        if ($new === '' || $new === $current) {
            return true;
        }
        return pd_safe::Write($dir, '.htaccess', $new, 0644) ? true : self::Fail('Could not write the .htaccess file.');
    }

    /**
     * Write a protected folder's password file from its users.
     *
     * @param array<int|string,mixed> $row The x_htpasswd_file row.
     * @return bool True when written; false with an error added.
     */
    static function WritePasswd($row)
    {
        $pdir = pd_safe::PasswdDir(self::Me()['username']);
        if ($pdir === false) {
            return self::Fail('The htpasswd folder of your account can not be used (is it a link?).');
        }
        $lines = '';
        foreach (self::Users($row['x_htpasswd_file_id']) as $u) {
            if (self::ValidUsername($u['x_htpasswd_user_username']) && strpos($u['x_htpasswd_user_password'], "\n") === false && strpos($u['x_htpasswd_user_password'], ':') === false) {
                $lines .= $u['x_htpasswd_user_username'] . ':' . $u['x_htpasswd_user_password'] . "\n";
            }
        }
        return pd_safe::Write($pdir, basename(pd_safe::PasswdFile(self::Me()['username'], $row['x_htpasswd_file_target'])), $lines, 0640)
            ? true : self::Fail('Could not write the password file.');
    }

    // --- actions (POST, with the CSRF token) -------------------------------------------------------

    /**
     * Protect the chosen folder: empty password file, .htaccess block and a record.
     *
     * Redirects to the folder's edit page and exits on success.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return false On failure (with an error added); otherwise it redirects and exits.
     */
    static function doCreateProtection()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $me = self::Me();
        $dir = pd_safe::ResolveDir($me['username'], isset($f['inPath']) ? $f['inPath'] : '');
        if ($dir === false) {
            return self::Fail('Choose a folder of your website in the tree.');
        }
        $message = self::CleanMessage(isset($f['inMessage']) ? $f['inMessage'] : '');
        if ($message === false) {
            return self::Fail('The message may have letters, digits, spaces and . , : ; ! ( ) _ - / @ & + only (64 at most).');
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_htpasswd_file WHERE x_htpasswd_file_target = :t AND x_htpasswd_file_deleted IS NULL");
        $q->execute(array(':t' => $dir));
        if ((int) $q->fetchColumn() > 0) {
            return self::Fail('That folder is already protected.');
        }
        if (pd_safe::PasswdDir($me['username']) === false) {
            return self::Fail('The htpasswd folder of your account can not be used (is it a link?).');
        }
        $passwd = pd_safe::PasswdFile($me['username'], $dir);
        if (!pd_safe::Write(dirname($passwd), basename($passwd), '', 0640) || !self::WriteHtaccess($dir, $passwd, $message, true)) {
            return self::Fail(self::$errors ? array_pop(self::$errors) : 'Could not protect that folder.');
        }
        $zdbh->prepare("INSERT INTO x_htpasswd_file (x_htpasswd_file_target, x_htpasswd_file_message, x_htpasswd_file_created, x_htpasswd_sentora_user_id) VALUES (:t, :m, :c, :u)")
            ->execute(array(':t' => $dir, ':m' => $message, ':c' => time(), ':u' => (int) $me['userid']));
        $id = (int) $zdbh->lastInsertId();
        header('location: ./?module=protected_directories&control=Edit&id=' . $id . '&done=created');
        exit;
    }

    /**
     * Change the login box message of a protected folder.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return false|null False on failure (with an error added), null when changed.
     */
    static function doUpdateMessage()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $row = self::Protection(isset($f['inId']) ? $f['inId'] : 0);
        if (!$row) {
            return self::Fail('That protected folder was not found.');
        }
        $message = self::CleanMessage(isset($f['inMessage']) ? $f['inMessage'] : '');
        if ($message === false) {
            return self::Fail('The message may have letters, digits, spaces and . , : ; ! ( ) _ - / @ & + only (64 at most).');
        }
        $dir = self::TargetDir($row);
        if ($dir === false) {
            return self::Fail('That folder is no longer a plain folder of your website (removed, or replaced by a link).');
        }
        if (!self::WriteHtaccess($dir, pd_safe::PasswdFile(self::Me()['username'], $dir), $message, true)) {
            return false;
        }
        $zdbh->prepare("UPDATE x_htpasswd_file SET x_htpasswd_file_message = :m WHERE x_htpasswd_file_id = :id AND x_htpasswd_sentora_user_id = :u")
            ->execute(array(':m' => $message, ':id' => (int) $row['x_htpasswd_file_id'], ':u' => (int) self::Me()['userid']));
        self::$messages[] = ui_language::translate('The message has been changed.');
    }

    /**
     * Remove the protection from a folder: .htaccess block, password file and records.
     *
     * Redirects to the list and exits on success.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return false On failure (with an error added); otherwise it redirects and exits.
     */
    static function doDeleteProtection()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $row = self::Protection(isset($f['inId']) ? $f['inId'] : 0);
        if (!$row) {
            return self::Fail('That protected folder was not found.');
        }
        $me = self::Me();
        $passwd = pd_safe::PasswdFile($me['username'], $row['x_htpasswd_file_target']);
        $dir = self::TargetDir($row);
        if ($dir !== false && !self::WriteHtaccess($dir, $passwd, '', false)) {
            return false;
        }
        pd_safe::Remove($passwd);
        $u = (int) $me['userid'];
        $zdbh->prepare("UPDATE x_htpasswd_user u JOIN x_htpasswd_mapper m ON m.x_htpasswd_user_id = u.x_htpasswd_user_id
            SET u.x_htpasswd_user_deleted = :t WHERE m.x_htpasswd_file_id = :f AND u.x_htpasswd_sentora_user_id = :u")
            ->execute(array(':t' => time(), ':f' => (int) $row['x_htpasswd_file_id'], ':u' => $u));
        $zdbh->prepare("UPDATE x_htpasswd_file SET x_htpasswd_file_deleted = :t WHERE x_htpasswd_file_id = :f AND x_htpasswd_sentora_user_id = :u")
            ->execute(array(':t' => time(), ':f' => (int) $row['x_htpasswd_file_id'], ':u' => $u));
        header('location: ./?module=protected_directories&done=removed');
        exit;
    }

    /**
     * Add a user (bcrypt password) to a protected folder and rewrite its password file.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return false|null False on failure (with an error added), else null.
     */
    static function doAddUser()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $row = self::Protection(isset($f['inId']) ? $f['inId'] : 0);
        if (!$row) {
            return self::Fail('That protected folder was not found.');
        }
        $name = isset($f['inUser']) ? trim((string) $f['inUser']) : '';
        $pass = isset($f['inPassword']) ? (string) $f['inPassword'] : '';
        if (!self::ValidUsername($name)) {
            return self::Fail('A username has 1 to 32 letters, digits, dots, dashes or underscores.');
        }
        if (!self::ValidPassword($pass)) {
            return self::Fail('A password has 8 to 128 characters.');
        }
        foreach (self::Users($row['x_htpasswd_file_id']) as $u) {
            if (strcasecmp($u['x_htpasswd_user_username'], $name) === 0) {
                return self::Fail('That folder already has a user with that name.');
            }
        }
        $me = (int) self::Me()['userid'];
        $zdbh->prepare("INSERT INTO x_htpasswd_user (x_htpasswd_user_username, x_htpasswd_user_password, x_htpasswd_user_created, x_htpasswd_sentora_user_id) VALUES (:n, :p, :c, :u)")
            ->execute(array(':n' => $name, ':p' => password_hash($pass, PASSWORD_BCRYPT), ':c' => time(), ':u' => $me));
        $zdbh->prepare("INSERT INTO x_htpasswd_mapper (x_htpasswd_file_id, x_htpasswd_user_id) VALUES (:f, :u)")
            ->execute(array(':f' => (int) $row['x_htpasswd_file_id'], ':u' => (int) $zdbh->lastInsertId()));
        if (self::WritePasswd($row)) {
            self::$messages[] = str_replace('%s', $name, ui_language::translate('User %s can now open the folder.'));
        }
    }

    /**
     * Return one user of a protected folder.
     *
     * @param array<int|string,mixed> $row The x_htpasswd_file row.
     * @param int|string $userId The x_htpasswd_user ID.
     * @return array<int|string, mixed>|false The user row, or false when not found.
     */
    static function UserOfProtection($row, $userId)
    {
        foreach (self::Users($row['x_htpasswd_file_id']) as $u) {
            if ((int) $u['x_htpasswd_user_id'] === (int) $userId) {
                return $u;
            }
        }
        return false;
    }

    /**
     * Set a new password for a user of a protected folder and rewrite its password file.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return false|null False on failure (with an error added), else null.
     */
    static function doSetPassword()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $row = self::Protection(isset($f['inId']) ? $f['inId'] : 0);
        $u = $row ? self::UserOfProtection($row, isset($f['inUserId']) ? $f['inUserId'] : 0) : false;
        if (!$u) {
            return self::Fail('That user was not found.');
        }
        $pass = isset($f['inPassword']) ? (string) $f['inPassword'] : '';
        if (!self::ValidPassword($pass)) {
            return self::Fail('A password has 8 to 128 characters.');
        }
        $zdbh->prepare("UPDATE x_htpasswd_user SET x_htpasswd_user_password = :p WHERE x_htpasswd_user_id = :id AND x_htpasswd_sentora_user_id = :u")
            ->execute(array(':p' => password_hash($pass, PASSWORD_BCRYPT), ':id' => (int) $u['x_htpasswd_user_id'], ':u' => (int) self::Me()['userid']));
        if (self::WritePasswd($row)) {
            self::$messages[] = str_replace('%s', $u['x_htpasswd_user_username'], ui_language::translate('The password of %s has been changed.'));
        }
    }

    /**
     * Remove a user from a protected folder and rewrite its password file.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return false|null False on failure (with an error added), else null.
     */
    static function doDeleteUser()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $row = self::Protection(isset($f['inId']) ? $f['inId'] : 0);
        $u = $row ? self::UserOfProtection($row, isset($f['inUserId']) ? $f['inUserId'] : 0) : false;
        if (!$u) {
            return self::Fail('That user was not found.');
        }
        $zdbh->prepare("UPDATE x_htpasswd_user SET x_htpasswd_user_deleted = :t WHERE x_htpasswd_user_id = :id AND x_htpasswd_sentora_user_id = :u")
            ->execute(array(':t' => time(), ':id' => (int) $u['x_htpasswd_user_id'], ':u' => (int) self::Me()['userid']));
        if (self::WritePasswd($row)) {
            self::$messages[] = str_replace('%s', $u['x_htpasswd_user_username'], ui_language::translate('User %s has been removed.'));
        }
    }

    // --- the page ----------------------------------------------------------------------------

    /**
     * Return the protected folder ID from the URL ('id').
     *
     * @global runtime_controller $controller The request controller.
     * @return int The ID (0 when none).
     */
    static function CurrentId()
    {
        global $controller;
        return (int) $controller->GetControllerRequest('URL', 'id');
    }

    /**
     * Check whether the edit page of one of the account's folders is shown (<% if IsEdit %>).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool True for control=Edit with a valid ID.
     */
    static function getIsEdit()
    {
        global $controller;
        return $controller->GetControllerRequest('URL', 'control') === 'Edit' && self::Protection(self::CurrentId()) !== false;
    }

    /**
     * Check whether the list page is shown (<% if IsIndex %>).
     *
     * @return bool True when not editing.
     */
    static function getIsIndex()
    {
        return !self::getIsEdit();
    }

    /**
     * Return the messages for the last action (<@ Result @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string HTML messages ('' when none).
     */
    static function getResult()
    {
        global $controller;
        $done = (string) $controller->GetControllerRequest('URL', 'done');
        $out = '';
        if ($done === 'created') {
            $out .= ui_sysmessage::shout(ui_language::translate('The folder is protected. Add a user below so that someone can open it.'), 'zannounceok');
        } elseif ($done === 'removed') {
            $out .= ui_sysmessage::shout(ui_language::translate('The protection has been removed: the folder is open again.'), 'zannounceok');
        }
        foreach (self::$errors as $e) {
            $out .= ui_sysmessage::shout(self::h(ui_language::translate($e)), 'zannounceerror');
        }
        foreach (self::$messages as $m) {
            $out .= ui_sysmessage::shout(self::h($m), 'zannounceok');
        }
        return $out;
    }

    /**
     * Return the account's public_html path, HTML-escaped.
     *
     * @return string The escaped path.
     */
    static function getPublicRoot()
    {
        return self::h(pd_safe::PublicRoot(self::Me()['username']));
    }

    /**
     * Return the edited folder as 'public_html/...', HTML-escaped (<@ EditPath @>).
     *
     * @return string The escaped path.
     */
    static function getEditPath()
    {
        $row = self::Protection(self::CurrentId());
        $rel = $row ? pd_safe::RelPath(self::Me()['username'], $row['x_htpasswd_file_target']) : '';
        return self::h('public_html/' . $rel);
    }

    /**
     * Return the edited folder's login box message, HTML-escaped (<@ EditMessage @>).
     *
     * @return string The escaped message ('' when not found).
     */
    static function getEditMessage()
    {
        $row = self::Protection(self::CurrentId());
        return $row ? self::h($row['x_htpasswd_file_message']) : '';
    }

    /**
     * Return the edited folder's ID (<@ EditId @>).
     *
     * @return int The ID.
     */
    static function getEditId()
    {
        return self::CurrentId();
    }

    /**
     * The account's protected folders, by path.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array<int|string, mixed>> x_htpasswd_file rows.
     */
    static function Protections()
    {
        global $zdbh;
        static $rows = null;
        if ($rows === null) {
            $q = $zdbh->prepare("SELECT * FROM x_htpasswd_file WHERE x_htpasswd_sentora_user_id = :u AND x_htpasswd_file_deleted IS NULL ORDER BY x_htpasswd_file_target");
            $q->execute(array(':u' => (int) self::Me()['userid']));
            $rows = $q->fetchAll();
        }
        return $rows;
    }

    /**
     * A folder of a record as shown: 'public_html/...'.
     *
     * @param array<int|string,mixed> $r The x_htpasswd_file row.
     * @return string The path.
     */
    static function Shown($r)
    {
        $rel = pd_safe::RelPath(self::Me()['username'], $r['x_htpasswd_file_target']);
        return $rel === false ? (string) $r['x_htpasswd_file_target'] : rtrim('public_html/' . $rel, '/');
    }

    /**
     * The path split for display: the folders before it (muted) and its own name (bold).
     *
     * @param string $path A path like 'public_html/shop/admin'.
     * @return string The HTML.
     */
    static function PathHtml($path)
    {
        $pos = strrpos($path, '/');
        return $pos === false ? '<b>' . self::h($path) . '</b>'
            : '<span class="pd-dir">' . self::h(substr($path, 0, $pos + 1)) . '</span><b>' . self::h(substr($path, $pos + 1)) . '</b>';
    }

    /**
     * The figures at the top (<@ Summary @>): protected folders, users, and folders nobody can open yet.
     *
     * @return string The HTML.
     */
    static function getSummary()
    {
        $folders = self::Protections();
        $users = 0;
        $empty = 0;
        foreach ($folders as $r) {
            $n = count(self::Users($r['x_htpasswd_file_id']));
            $users += $n;
            if ($n === 0) {
                $empty++;
            }
        }
        $tiles = array(
            array('Protected folders', count($folders), '', '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>'),
            array('Users who can log in', $users, '', '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6"/>'),
            array('Folders with no users yet', $empty, $empty ? 'warn' : '', '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>'),
        );
        $out = '';
        foreach ($tiles as $t) {
            $out .= '<div class="pd-tile ' . $t[2] . '"><svg class="pd-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $t[3] . '</svg>'
                . '<div><b>' . (int) $t[1] . '</b><span>' . self::h(ui_language::translate($t[0])) . '</span></div></div>';
        }
        return $out;
    }

    /**
     * One card per protected folder (<@ ProtectionRows @>): its path, login box message, users, Manage and Remove protection.
     *
     * @return string The HTML.
     */
    static function getProtectionRows()
    {
        $t = function ($s) { return self::h(ui_language::translate($s)); };
        $html = '';
        foreach (self::Protections() as $r) {
            $users = self::Users($r['x_htpasswd_file_id']);
            $n = count($users);
            $id = (int) $r['x_htpasswd_file_id'];
            $names = array_map(function ($u) { return $u['x_htpasswd_user_username']; }, array_slice($users, 0, 4));
            $html .= '<div class="pd-item' . ($n ? '' : ' is-empty') . '"><div class="pd-item-main">'
                . '<div class="pd-path"><svg class="pd-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>'
                . self::PathHtml(self::Shown($r)) . '</div>'
                . '<div class="pd-meta"><span title="' . $t('Message in the login box') . '">&ldquo;' . self::h($r['x_htpasswd_file_message']) . '&rdquo;</span>'
                . ($n ? '<span class="pd-users">' . str_replace('%d', (string) $n, $t($n === 1 ? '1 user' : '%d users')) . ': ' . self::h(implode(', ', $names)) . ($n > 4 ? ', &hellip;' : '') . '</span>'
                    : '<span class="pd-pill warn">' . $t('No users yet: nobody can open it') . '</span>')
                . '</div></div>'
                . '<div class="pd-act"><a class="btn btn-primary btn-sm" href="./?module=protected_directories&amp;control=Edit&amp;id=' . $id . '">'
                . '<svg class="pd-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/></svg>' . $t('Users and message') . '</a>'
                . '<form method="post" action="./?module=protected_directories&amp;action=DeleteProtection" class="pd-confirm" data-confirm="' . $t('Remove the protection from this folder? Anyone can open it again.') . '">'
                . '<input type="hidden" name="inId" value="' . $id . '">' . runtime_csfr::Token()
                . '<button class="btn btn-default btn-sm pd-unlock" type="submit"><svg class="pd-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/></svg>' . $t('Remove protection') . '</button></form>'
                . '</div></div>';
        }
        return $html === '' ? '<div class="pd-none"><svg class="pd-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>'
            . '<b>' . $t('No folder is protected yet.') . '</b><span>' . $t('Choose one below, for example an admin or staging folder.') . '</span></div>' : $html;
    }

    /**
     * The edited folder's path as HTML, folders muted and its name bold (<@ EditPathHtml @>).
     *
     * @return string The HTML.
     */
    static function getEditPathHtml()
    {
        $row = self::Protection(self::CurrentId());
        return $row ? self::PathHtml(self::Shown($row)) : '';
    }

    /**
     * How many users the edited folder has (<@ EditUserCount @>).
     *
     * @return int
     */
    static function getEditUserCount()
    {
        $row = self::Protection(self::CurrentId());
        return $row ? count(self::Users($row['x_htpasswd_file_id'])) : 0;
    }

    /**
     * The username typed when adding a user failed, so it is not lost (<@ PostedUser @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string The escaped username ('' after a success or without a post).
     */
    static function getPostedUser()
    {
        global $controller;
        return self::$errors ? self::h((string) $controller->GetControllerRequest('FORM', 'inUser')) : '';
    }

    /**
     * One row per user of the edited folder (<@ UserRows @>), with a new password that opens in place and Remove.
     *
     * @return string The HTML ('' when the folder is not found).
     */
    static function getUserRows()
    {
        $row = self::Protection(self::CurrentId());
        if (!$row) {
            return '';
        }
        $t = function ($s) { return self::h(ui_language::translate($s)); };
        $fid = (int) $row['x_htpasswd_file_id'];
        $html = '';
        foreach (self::Users($fid) as $u) {
            $strong = (strncmp($u['x_htpasswd_user_password'], '$2y$', 4) === 0);
            $id = (int) $u['x_htpasswd_user_id'];
            $html .= '<div class="pd-user"><div class="pd-user-row">'
                . '<span class="pd-avatar" aria-hidden="true">' . self::h(strtoupper(substr($u['x_htpasswd_user_username'], 0, 1))) . '</span>'
                . '<div class="pd-user-name"><b>' . self::h($u['x_htpasswd_user_username']) . '</b>'
                . ($strong ? '<small>' . $t('Password stored with bcrypt') . '</small>'
                    : '<span class="pd-pill warn" title="' . $t('Set a new password to store it with bcrypt.') . '">' . $t('weak password hash') . '</span>')
                . (!empty($u['x_htpasswd_user_created']) ? '<small>' . $t('Added') . ' ' . self::h(date('Y-m-d', (int) $u['x_htpasswd_user_created'])) . '</small>' : '') . '</div>'
                . '<div class="pd-act"><button type="button" class="btn btn-default btn-sm pd-pw-btn" aria-expanded="false">'
                . '<svg class="pd-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3"/></svg>' . $t('New password') . '</button>'
                . '<form method="post" action="./?module=protected_directories&amp;control=Edit&amp;id=' . $fid . '&amp;action=DeleteUser" class="pd-confirm" data-confirm="' . $t('Remove this user? They can no longer open the folder.') . '">'
                . '<input type="hidden" name="inId" value="' . $fid . '"><input type="hidden" name="inUserId" value="' . $id . '">' . runtime_csfr::Token()
                . '<button class="btn btn-default btn-sm pd-del" type="submit" title="' . $t('Remove') . '" aria-label="' . $t('Remove') . ' ' . self::h($u['x_htpasswd_user_username']) . '">'
                . '<svg class="pd-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></button></form></div></div>'
                . '<form method="post" action="./?module=protected_directories&amp;control=Edit&amp;id=' . $fid . '&amp;action=SetPassword" class="pd-pw" hidden>'
                . '<input type="hidden" name="inId" value="' . $fid . '"><input type="hidden" name="inUserId" value="' . $id . '">'
                . '<div class="pd-pass"><input type="password" name="inPassword" class="form-control" placeholder="' . $t('New password, 8 characters or more') . '" minlength="8" maxlength="128" autocomplete="new-password" required>'
                . '<button type="button" class="btn btn-default pd-show" aria-label="' . $t('Show the password') . '" data-show="' . $t('Show') . '" data-hide="' . $t('Hide') . '">' . $t('Show') . '</button>'
                . '<button type="button" class="btn btn-default pd-gen">' . $t('Make one') . '</button></div>'
                . runtime_csfr::Token() . '<button class="btn btn-primary" type="submit">' . $t('Set password') . '</button></form></div>';
        }
        return $html === '' ? '<div class="pd-none"><b>' . $t('No users yet') . '</b><span>' . $t('Nobody can open the folder until you add one below.') . '</span></div>' : $html;
    }

    /**
     * Return the module's assets folder (<@ Assets @>).
     *
     * @return string Relative URL path with a trailing slash.
     */
    static function getAssets()
    {
        return 'modules/protected_directories/assets/';
    }
}
