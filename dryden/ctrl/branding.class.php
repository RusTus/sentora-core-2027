<?php

/**
 * Branding: the look a reseller (or the administrator) gives the panel for its own clients. A logo,
 * a company name, a main colour, a welcome line and Light/Dark/Auto for the login page, and the
 * reseller's own panel address (panel.theirhosting.com), which the daemon sets up in Apache with a
 * Let's Encrypt certificate (modules/branding/hooks/OnDaemonRun.hook.php).
 *
 * Who sees what:
 * - before login, the brand of the address the page was opened at (the main panel address and any
 *   name without a brand of its own: the administrator's, zadmin's);
 * - after login, the account's own brand when it has one, else the nearest reseller's above it,
 *   else zadmin's;
 * - with no brand anywhere, the standard Sentora look.
 *
 * Nothing a reseller enters is ever used as HTML, CSS or a script: the colour must be #rrggbb, the
 * logo is drawn again as a new PNG (only its pixels are kept) and the texts are shown as text. The
 * support email, phone and website on the login page are the account's support contact (My Account,
 * ctrl_support).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class ctrl_branding
{
    /** Longest company name (characters). */
    const NAME_MAX = 60;

    /** Longest welcome line (characters). */
    const WELCOME_MAX = 160;

    /** Largest logo upload (bytes). */
    const UPLOAD_MAX = 2097152;

    /** Largest picture accepted (pixels, width times height), before it is made smaller. */
    const PIXELS_MAX = 25000000;

    /** The stored logo fits in this box (pixels; twice the size shown, for sharp screens). */
    const LOGO_W = 480;
    const LOGO_H = 160;

    /** Where the daemon keeps the certificates of the panel addresses (root's). */
    const CERT_DIR = '/etc/sentora/configs/ssl/branding/';

    /**
     * Create the x_branding table if missing (once per request).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if ($done) {
            return;
        }
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_branding (
            br_acc_fk INT NOT NULL PRIMARY KEY,
            br_host_vc VARCHAR(253) NULL DEFAULT NULL,
            br_name_vc VARCHAR(120) NOT NULL DEFAULT '',
            br_colour_vc VARCHAR(7) NOT NULL DEFAULT '',
            br_welcome_vc VARCHAR(255) NOT NULL DEFAULT '',
            br_variant_vc VARCHAR(5) NOT NULL DEFAULT '',
            br_logo_mb MEDIUMBLOB NULL,
            br_logodark_mb MEDIUMBLOB NULL,
            br_logov_vc VARCHAR(16) NOT NULL DEFAULT '',
            br_state_vc VARCHAR(10) NOT NULL DEFAULT '',
            br_note_vc VARCHAR(255) NOT NULL DEFAULT '',
            br_tries_in INT NOT NULL DEFAULT 0,
            br_next_ts INT NOT NULL DEFAULT 0,
            br_updated_ts INT NULL,
            UNIQUE KEY br_host (br_host_vc)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    /**
     * Is the Branding module on? (Switched off in Module Admin: no brand is shown anywhere and the
     * panel addresses are taken out of the web server's settings.)
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return bool
     */
    static function Enabled()
    {
        global $zdbh;
        static $on = null;
        if ($on === null) {
            try {
                $on = (bool) $zdbh->query("SELECT COUNT(*) FROM x_modules WHERE mo_folder_vc = 'branding' AND mo_enabled_en = 'true'")->fetchColumn();
            } catch (Exception $e) {
                $on = false;
            }
        }
        return $on;
    }

    /**
     * May this account brand the panel? The same accounts that can set a support contact: their group
     * has Manage Clients (so they can have clients).
     *
     * @param int $uid The account ID.
     * @return bool
     */
    static function CanSet($uid)
    {
        static $cache = array();
        $uid = (int) $uid;
        if (!isset($cache[$uid])) {
            $cache[$uid] = ctrl_support::CanSet($uid);
        }
        return $cache[$uid];
    }

    /**
     * Is the account in the administrators' group?
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return bool
     */
    static function IsAdmin($uid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT ac_group_fk FROM x_accounts WHERE ac_id_pk = :id AND ac_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $uid));
        return (int) $q->fetchColumn() === 1;
    }

    /**
     * The administrator account whose brand is the panel's own (zadmin).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return int Its ID, or 0.
     */
    static function AdminId()
    {
        global $zdbh;
        static $id = null;
        if ($id === null) {
            $id = (int) $zdbh->query("SELECT ac_id_pk FROM x_accounts WHERE ac_user_vc = 'zadmin' AND ac_deleted_ts IS NULL")->fetchColumn();
        }
        return $id;
    }

    /**
     * An account's own brand settings, as stored (without the logo pictures).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return array{uid:int, host:string, name:string, colour:string, welcome:string, variant:string, logo:bool, logodark:bool, logov:string, state:string, note:string, tries:int, next:int, updated:int}
     */
    static function Get($uid)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT br_host_vc, br_name_vc, br_colour_vc, br_welcome_vc, br_variant_vc, br_logo_mb IS NOT NULL AS has_logo,
            br_logodark_mb IS NOT NULL AS has_dark, br_logov_vc, br_state_vc, br_note_vc, br_tries_in, br_next_ts, br_updated_ts FROM x_branding WHERE br_acc_fk = :id");
        $q->execute(array(':id' => (int) $uid));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return array(
            'uid' => (int) $uid,
            'host' => $r ? (string) $r['br_host_vc'] : '',
            'name' => $r ? (string) $r['br_name_vc'] : '',
            'colour' => $r ? (string) $r['br_colour_vc'] : '',
            'welcome' => $r ? (string) $r['br_welcome_vc'] : '',
            'variant' => $r ? (string) $r['br_variant_vc'] : '',
            'logo' => $r ? (bool) $r['has_logo'] : false,
            'logodark' => $r ? (bool) $r['has_dark'] : false,
            'logov' => $r ? (string) $r['br_logov_vc'] : '',
            'state' => $r ? (string) $r['br_state_vc'] : '',
            'note' => $r ? (string) $r['br_note_vc'] : '',
            'tries' => $r ? (int) $r['br_tries_in'] : 0,
            'next' => $r ? (int) $r['br_next_ts'] : 0,
            'updated' => $r ? (int) $r['br_updated_ts'] : 0,
        );
    }

    /**
     * Does the brand change anything (a logo, a name, a colour or a welcome line)?
     *
     * @param array $b From Get().
     * @return bool
     */
    static function HasLook(array $b)
    {
        return $b['logo'] || $b['name'] !== '' || $b['colour'] !== '' || $b['welcome'] !== '';
    }

    /**
     * A panel address as typed, cleaned: lower case, no scheme, path or trailing dot.
     *
     * @param string $host What was typed.
     * @return string|false The name, '' for none, or false when it is not a host name.
     */
    static function CleanHost($host)
    {
        $host = strtolower(trim((string) $host));
        $host = preg_replace('~^[a-z]+://~', '', $host);
        $host = rtrim(preg_replace('~[/?#].*$~', '', $host), '.');
        if ($host === '') {
            return '';
        }
        if (strlen($host) > 253 || substr_count($host, '.') < 1
            || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $host)) {
            return false;
        }
        return $host;
    }

    /**
     * Can this account use the name as its panel address? It must not be in use on this server (a
     * website, its www. name, the panel's own name or another account's address), and for anyone but
     * an administrator it must be a name under one of the account's own domains, so nobody can take a
     * name that belongs to someone else.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param string $host A name from CleanHost().
     * @return string '' when it can, else why not (English, for ui_language::translate).
     */
    static function HostProblem($uid, $host)
    {
        global $zdbh;
        self::Ensure();
        $panel = strtolower(trim((string) ctrl_options::GetSystemOption('sentora_domain')));
        if ($host === $panel || $host === 'www.' . $panel) {
            return 'That is the main panel address already.';
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_deleted_ts IS NULL AND (LOWER(vh_name_vc) = :h OR CONCAT('www.', LOWER(vh_name_vc)) = :h2)");
        $q->execute(array(':h' => $host, ':h2' => $host));
        if ((int) $q->fetchColumn() > 0) {
            return 'That name is a website on this server: use a name of its own, such as panel.yourdomain.com.';
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_branding WHERE br_host_vc = :h AND br_acc_fk <> :id");
        $q->execute(array(':h' => $host, ':id' => (int) $uid));
        if ((int) $q->fetchColumn() > 0) {
            return 'Another account uses that address already.';
        }
        if (self::IsAdmin($uid)) {
            return '';
        }
        $q = $zdbh->prepare("SELECT LOWER(vh_name_vc) FROM x_vhosts WHERE vh_acc_fk = :id AND vh_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $uid));
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $d) {
            if ($d !== '' && substr($host, -strlen('.' . $d)) === '.' . $d) {
                return '';
            }
        }
        return 'The address must be under one of your own domains (for example panel.yourdomain.com, where yourdomain.com is a domain in your account).';
    }

    /**
     * Check and save an account's brand (not the logo: SetLogo()).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param array{host?:string, name?:string, colour?:string, welcome?:string, variant?:string} $in What was typed.
     * @param bool $withHost Save the address too (not for the administrator's main panel).
     * @return string '' when saved, else what is wrong: 'host:<reason>', 'name', 'colour', 'welcome' or 'variant'.
     */
    static function Save($uid, array $in, $withHost = true)
    {
        global $zdbh;
        self::Ensure();
        $old = self::Get($uid);
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')));
        $welcome = trim(preg_replace('/\s+/u', ' ', (string) ($in['welcome'] ?? '')));
        $colour = strtolower(trim((string) ($in['colour'] ?? '')));
        $variant = (string) ($in['variant'] ?? '');
        if (mb_strlen($name) > self::NAME_MAX || preg_match('/[\x00-\x1F\x7F<>]/u', $name)) {
            return 'name';
        }
        if (mb_strlen($welcome) > self::WELCOME_MAX || preg_match('/[\x00-\x1F\x7F<>]/u', $welcome)) {
            return 'welcome';
        }
        if ($colour !== '' && !preg_match('/^#[0-9a-f]{6}$/', $colour)) {
            return 'colour';
        }
        if (!in_array($variant, array('', 'light', 'dark', 'auto'), true)) {
            return 'variant';
        }
        $host = $old['host'];
        if ($withHost) {
            $host = self::CleanHost($in['host'] ?? '');
            if ($host === false) {
                return 'host:That is not a host name (for example panel.yourdomain.com).';
            }
            if ($host !== '') {
                $why = self::HostProblem($uid, $host);
                if ($why !== '') {
                    return 'host:' . $why;
                }
            }
        }
        $hostChanged = $host !== $old['host'];
        $q = $zdbh->prepare("INSERT INTO x_branding (br_acc_fk, br_host_vc, br_name_vc, br_colour_vc, br_welcome_vc, br_variant_vc, br_state_vc, br_note_vc, br_tries_in, br_next_ts, br_updated_ts)
            VALUES (:id, :host, :name, :colour, :welcome, :variant, :state, '', 0, 0, :t)
            ON DUPLICATE KEY UPDATE br_host_vc = VALUES(br_host_vc), br_name_vc = VALUES(br_name_vc), br_colour_vc = VALUES(br_colour_vc),
                br_welcome_vc = VALUES(br_welcome_vc), br_variant_vc = VALUES(br_variant_vc), br_updated_ts = VALUES(br_updated_ts)"
            . ($hostChanged ? ", br_state_vc = VALUES(br_state_vc), br_note_vc = '', br_tries_in = 0, br_next_ts = 0" : ''));
        $q->execute(array(':id' => (int) $uid, ':host' => $host === '' ? null : $host, ':name' => $name, ':colour' => $colour,
            ':welcome' => $welcome, ':variant' => $variant, ':state' => $host === '' ? '' : 'new', ':t' => time()));
        if ($hostChanged) {
            // the daemon writes (or removes) the address's web server settings
            $zdbh->exec("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc = 'apache_changed'");
        }
        return '';
    }

    /**
     * Take an uploaded logo: a PNG, JPEG or WebP picture, drawn again as a new PNG that fits
     * LOGO_W x LOGO_H (only the pixels are kept: no text, metadata or anything hidden in the file).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param string $file The uploaded file.
     * @param bool $dark The logo for dark backgrounds (else the main one).
     * @return string '' when saved, else what is wrong: 'big', 'type', 'size' or 'gd'.
     */
    static function SetLogo($uid, $file, $dark = false)
    {
        global $zdbh;
        self::Ensure();
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            return 'gd';
        }
        if (!is_file($file) || filesize($file) > self::UPLOAD_MAX) {
            return 'big';
        }
        $data = (string) file_get_contents($file);
        $info = @getimagesizefromstring($data);
        if (!$info || !in_array((int) $info[2], array(IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP), true)) {
            return 'type';
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        if ($w < 16 || $h < 16 || $w * $h > self::PIXELS_MAX) {
            return 'size';
        }
        $src = @imagecreatefromstring($data);
        if (!$src) {
            return 'type';
        }
        $scale = min(1, self::LOGO_W / $w, self::LOGO_H / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagepng($dst, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        if ($png === '') {
            return 'gd';
        }
        $zdbh->prepare("INSERT INTO x_branding (br_acc_fk, br_updated_ts) VALUES (:id, :t) ON DUPLICATE KEY UPDATE br_updated_ts = VALUES(br_updated_ts)")
            ->execute(array(':id' => (int) $uid, ':t' => time()));
        $q = $zdbh->prepare("UPDATE x_branding SET " . ($dark ? 'br_logodark_mb' : 'br_logo_mb') . " = :png, br_logov_vc = :v WHERE br_acc_fk = :id");
        $q->bindValue(':png', $png, PDO::PARAM_LOB);
        $q->bindValue(':v', substr(bin2hex(random_bytes(8)), 0, 12));
        $q->bindValue(':id', (int) $uid, PDO::PARAM_INT);
        $q->execute();
        return '';
    }

    /**
     * Remove a logo.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param bool $dark The logo for dark backgrounds (else the main one, and the dark one with it).
     * @return void
     */
    static function RemoveLogo($uid, $dark = false)
    {
        global $zdbh;
        self::Ensure();
        $zdbh->prepare("UPDATE x_branding SET " . ($dark ? 'br_logodark_mb = NULL' : 'br_logo_mb = NULL, br_logodark_mb = NULL')
            . ", br_logov_vc = :v, br_updated_ts = :t WHERE br_acc_fk = :id")
            ->execute(array(':v' => substr(bin2hex(random_bytes(8)), 0, 12), ':t' => time(), ':id' => (int) $uid));
    }

    /**
     * A stored logo picture (PNG).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param bool $dark The one for dark backgrounds (falls back to the main one).
     * @return string The PNG, or '' when there is none.
     */
    static function LogoData($uid, $dark = false)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT " . ($dark ? 'COALESCE(br_logodark_mb, br_logo_mb)' : 'br_logo_mb') . " FROM x_branding WHERE br_acc_fk = :id");
        $q->execute(array(':id' => (int) $uid));
        $v = $q->fetchColumn();
        if (is_resource($v)) {
            $v = stream_get_contents($v);
        }
        return is_string($v) ? $v : '';
    }

    /**
     * The brand for an account after login: its own when it can brand and has one, else the nearest
     * reseller's above it, else zadmin's.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return array|null From Get(), or null when no brand applies.
     */
    static function ForUser($uid)
    {
        global $zdbh;
        static $cache = array();
        $uid = (int) $uid;
        if (array_key_exists($uid, $cache)) {
            return $cache[$uid];
        }
        if (!self::Enabled()) {
            return $cache[$uid] = null;
        }
        self::Ensure();
        $up = $zdbh->prepare("SELECT ac_reseller_fk FROM x_accounts WHERE ac_id_pk = :id");
        $seen = array();
        $cur = $uid;
        $found = null;
        while ($cur > 0 && !isset($seen[$cur]) && count($seen) < 10) {
            $seen[$cur] = true;
            if (self::CanSet($cur)) {
                $b = self::Get($cur);
                if (self::HasLook($b)) {
                    $found = $b;
                    break;
                }
            }
            $up->execute(array(':id' => $cur));
            $cur = (int) $up->fetchColumn();
        }
        if ($found === null) {
            $admin = self::AdminId();
            if ($admin > 0 && !isset($seen[$admin])) {
                $b = self::Get($admin);
                $found = self::HasLook($b) ? $b : null;
            }
        }
        return $cache[$uid] = $found;
    }

    /**
     * The name the page was asked for (the Host header, lower case, without the port).
     *
     * @return string
     */
    static function RequestHost()
    {
        $h = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $h = preg_replace('/:\d+$/', '', $h);
        return preg_match('/^[a-z0-9.-]{1,253}$/', $h) ? rtrim($h, '.') : '';
    }

    /**
     * The brand before login: the one whose panel address the page was opened at, else zadmin's.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array|null From Get(), or null when no brand applies.
     */
    static function ForRequest()
    {
        global $zdbh;
        static $done = false, $brand = null;
        if ($done) {
            return $brand;
        }
        $done = true;
        if (!self::Enabled()) {
            return $brand = null;
        }
        try {
            self::Ensure();
            $host = self::RequestHost();
            if ($host !== '') {
                $q = $zdbh->prepare("SELECT br_acc_fk FROM x_branding WHERE br_host_vc = :h");
                $q->execute(array(':h' => $host));
                $owner = (int) $q->fetchColumn();
                if ($owner > 0 && self::CanSet($owner)) {
                    $b = self::Get($owner);
                    return $brand = $b;
                }
            }
            $admin = self::AdminId();
            if ($admin > 0) {
                $b = self::Get($admin);
                $brand = self::HasLook($b) ? $b : null;
            }
        } catch (Exception $e) {
            $brand = null;
        }
        return $brand;
    }

    /**
     * The brand for the page being drawn: the logged-in account's, else the address's.
     *
     * @return array|null From Get(), or null when no brand applies.
     */
    static function Current()
    {
        try {
            $uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
            return $uid > 0 ? self::ForUser($uid) : self::ForRequest();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * The address of a brand's logo (served by modules/branding/code/logo.php).
     *
     * @param array $b From Get().
     * @param bool $dark The one for dark backgrounds.
     * @return string An absolute path on the panel, or '' when the brand has no logo.
     */
    static function LogoUrl(array $b, $dark = false)
    {
        if (!$b['logo']) {
            return '';
        }
        return '/modules/branding/code/logo.php?b=' . (int) $b['uid'] . ($dark ? '&d=1' : '') . '&v=' . rawurlencode($b['logov']);
    }

    /**
     * Black or white text on a colour, whichever reads better (WCAG relative luminance).
     *
     * @param string $hex #rrggbb.
     * @return string '#ffffff' or '#111111'.
     */
    static function InkOn($hex)
    {
        $c = array();
        foreach (array(1, 3, 5) as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        $l = 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        // contrast with white (1.05 / (l + 0.05)) against contrast with near-black
        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.0555) ? '#ffffff' : '#111111';
    }

    /**
     * A colour made darker (for hover and borders).
     *
     * @param string $hex #rrggbb.
     * @param float $by 0..1.
     * @return string #rrggbb.
     */
    static function Darker($hex, $by = 0.15)
    {
        $out = '#';
        foreach (array(1, 3, 5) as $i) {
            $out .= sprintf('%02x', (int) round(hexdec(substr($hex, $i, 2)) * (1 - $by)));
        }
        return $out;
    }

    /**
     * The https address of an account's panel, for links in emails: its brand's address when that
     * is live, else ''.
     *
     * @param int $uid The account ID.
     * @return string 'https://panel.example.com' or ''.
     */
    static function PanelUrlFor($uid)
    {
        try {
            $b = self::ForUser($uid);
        } catch (Exception $e) {
            return '';
        }
        return ($b && $b['host'] !== '' && $b['state'] === 'live') ? 'https://' . $b['host'] : '';
    }

    /**
     * The panel addresses the web server should answer on, with their certificate files when they have
     * one (for the Apache config; modules/apache_admin/hooks/OnDaemonRun.hook.php).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array{uid:int, host:string, cert:string, key:string}> cert and key are '' until issued.
     */
    static function Addresses()
    {
        global $zdbh;
        if (!self::Enabled()) {
            return array();
        }
        self::Ensure();
        $out = array();
        foreach ($zdbh->query("SELECT b.br_acc_fk, b.br_host_vc FROM x_branding b JOIN x_accounts a ON a.ac_id_pk = b.br_acc_fk
            WHERE b.br_host_vc IS NOT NULL AND b.br_host_vc <> '' AND a.ac_deleted_ts IS NULL AND a.ac_enabled_in = 1 ORDER BY b.br_host_vc")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $host = self::CleanHost($r['br_host_vc']);
            if (!$host || !self::CanSet((int) $r['br_acc_fk'])) {
                continue;
            }
            $dir = self::CERT_DIR . $host . '/';
            $ok = self::CertUsable($dir);
            $out[] = array('uid' => (int) $r['br_acc_fk'], 'host' => $host, 'cert' => $ok ? $dir . 'fullchain.pem' : '', 'key' => $ok ? $dir . 'private.pem' : '');
        }
        return $out;
    }

    /**
     * Are a certificate's files there and right (the certificate and its key belong together, and it
     * has not run out)? A broken one is left out of the Apache config, which would otherwise be
     * refused as a whole.
     *
     * @param string $dir The address's certificate folder (with a trailing slash).
     * @return bool
     */
    static function CertUsable($dir)
    {
        $cert = $dir . 'fullchain.pem';
        $key = $dir . 'private.pem';
        if (!is_file($cert) || !is_file($key) || is_link($cert) || is_link($key) || !is_readable($cert) || !is_readable($key)) {
            return false;
        }
        $pem = (string) @file_get_contents($cert);
        $x = @openssl_x509_parse($pem);
        if (!$x || empty($x['validTo_time_t']) || (int) $x['validTo_time_t'] < time()) {
            return false;
        }
        return (bool) @openssl_x509_check_private_key($pem, (string) @file_get_contents($key));
    }

    /**
     * Every brand with an address, for the administrator's list.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array{uid:int, user:string, host:string, state:string, note:string}>
     */
    static function All()
    {
        global $zdbh;
        self::Ensure();
        $out = array();
        foreach ($zdbh->query("SELECT b.br_acc_fk, a.ac_user_vc, b.br_host_vc, b.br_state_vc, b.br_note_vc FROM x_branding b
            JOIN x_accounts a ON a.ac_id_pk = b.br_acc_fk WHERE a.ac_deleted_ts IS NULL AND b.br_host_vc IS NOT NULL ORDER BY a.ac_user_vc")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = array('uid' => (int) $r['br_acc_fk'], 'user' => (string) $r['ac_user_vc'], 'host' => (string) $r['br_host_vc'],
                'state' => (string) $r['br_state_vc'], 'note' => (string) $r['br_note_vc']);
        }
        return $out;
    }

    /**
     * Record where an address is (the daemon).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param string $state 'dns' (no address in DNS yet), 'wait' (getting the certificate), 'live' or 'error'.
     * @param string $note What happened (shown to the reseller; '' for none).
     * @param int|null $tries Failed attempts so far (null: unchanged).
     * @param int|null $next When to try again (null: unchanged).
     * @return void
     */
    static function SetState($uid, $state, $note = '', $tries = null, $next = null)
    {
        global $zdbh;
        $sql = "UPDATE x_branding SET br_state_vc = :s, br_note_vc = :n" . ($tries !== null ? ', br_tries_in = :tr' : '') . ($next !== null ? ', br_next_ts = :nx' : '') . " WHERE br_acc_fk = :id";
        $a = array(':s' => $state, ':n' => mb_substr((string) $note, 0, 255), ':id' => (int) $uid);
        if ($tries !== null) {
            $a[':tr'] = (int) $tries;
        }
        if ($next !== null) {
            $a[':nx'] = (int) $next;
        }
        $zdbh->prepare($sql)->execute($a);
    }
}
