<?php

/**
 * Branding templates: ready-made looks an account that can brand the panel applies with one click
 * (Branding > Templates). Two kinds:
 * - built in: a folder in modules/branding/assets/templates/<id>/ with template.json (title, about,
 *   name, colour, welcome, variant) and logo.png / logo-dark.png; "acme" is the example;
 * - the account's own: its look saved under a title (at most MAX), to switch between looks.
 * Applying one replaces the look (company name, colour, welcome line, Light/Dark/Auto, logos), never
 * the panel address; the look it replaces is kept as "Your previous look", so one click undoes it
 * (and applying the previous look keeps the one it replaces in its place: a second click redoes it).
 * Everything a template holds goes through the same checks as Branding itself (ctrl_branding::Save()),
 * and its logos are pictures the panel drew itself.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class ctrl_brandtemplates
{
    /** Most templates an account may save (its previous look not counted). */
    const MAX = 10;

    /** Longest template title (characters). */
    const TITLE_MAX = 40;

    /** Where the built-in templates are (relative to the panel's folder). */
    const DIR = 'modules/branding/assets/templates/';

    /**
     * Create the x_branding_tpl table if missing (once per request).
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
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_branding_tpl (
            bt_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            bt_acc_fk INT NOT NULL,
            bt_prev_in TINYINT NOT NULL DEFAULT 0,
            bt_title_vc VARCHAR(120) NOT NULL DEFAULT '',
            bt_name_vc VARCHAR(120) NOT NULL DEFAULT '',
            bt_colour_vc VARCHAR(7) NOT NULL DEFAULT '',
            bt_welcome_vc VARCHAR(255) NOT NULL DEFAULT '',
            bt_variant_vc VARCHAR(5) NOT NULL DEFAULT '',
            bt_logo_mb MEDIUMBLOB NULL,
            bt_logodark_mb MEDIUMBLOB NULL,
            bt_created_ts INT NULL,
            KEY bt_acc (bt_acc_fk)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    /**
     * The built-in templates.
     *
     * @return array<string, array{id:string, title:string, about:string, name:string, colour:string, welcome:string, variant:string, logo:string, logodark:string}>
     *         By id; logo and logodark are the files' web paths ('' when the template has none).
     */
    static function BuiltIn()
    {
        static $list = null;
        if ($list !== null) {
            return $list;
        }
        $list = array();
        $root = dirname(dirname(__DIR__)) . '/';
        foreach ((array) glob($root . self::DIR . '*/template.json') as $f) {
            $id = basename(dirname($f));
            if (!preg_match('/^[a-z0-9_-]{1,40}$/', $id) || is_link(dirname($f))) {
                continue;
            }
            $j = json_decode((string) file_get_contents($f), true);
            if (!is_array($j)) {
                continue;
            }
            $dir = self::DIR . $id . '/';
            $list[$id] = array(
                'id' => $id,
                'title' => (string) ($j['title'] ?? $id),
                'about' => (string) ($j['about'] ?? ''),
                'name' => (string) ($j['name'] ?? ''),
                'colour' => (string) ($j['colour'] ?? ''),
                'welcome' => (string) ($j['welcome'] ?? ''),
                'variant' => (string) ($j['variant'] ?? ''),
                'logo' => is_file($root . $dir . 'logo.png') ? '/' . $dir . 'logo.png' : '',
                'logodark' => is_file($root . $dir . 'logo-dark.png') ? '/' . $dir . 'logo-dark.png' : '',
            );
        }
        ksort($list);
        return $list;
    }

    /**
     * An account's own templates (newest first; its previous look first of all), without the pictures.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @return array<int, array{id:int, prev:bool, title:string, name:string, colour:string, welcome:string, variant:string, logo:bool, logodark:bool, created:int}>
     */
    static function Own($uid)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT bt_id_pk, bt_prev_in, bt_title_vc, bt_name_vc, bt_colour_vc, bt_welcome_vc, bt_variant_vc,
            bt_logo_mb IS NOT NULL AS has_logo, bt_logodark_mb IS NOT NULL AS has_dark, bt_created_ts
            FROM x_branding_tpl WHERE bt_acc_fk = :id ORDER BY bt_prev_in DESC, bt_id_pk DESC");
        $q->execute(array(':id' => (int) $uid));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = array('id' => (int) $r['bt_id_pk'], 'prev' => (int) $r['bt_prev_in'] === 1, 'title' => (string) $r['bt_title_vc'],
                'name' => (string) $r['bt_name_vc'], 'colour' => (string) $r['bt_colour_vc'], 'welcome' => (string) $r['bt_welcome_vc'],
                'variant' => (string) $r['bt_variant_vc'], 'logo' => (bool) $r['has_logo'], 'logodark' => (bool) $r['has_dark'],
                'created' => (int) $r['bt_created_ts']);
        }
        return $out;
    }

    /**
     * Keep the account's current look as a template.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param string $title Its title.
     * @param bool $prev As "Your previous look" (replacing the one kept before), not a titled template.
     * @return string '' when saved, else 'title' (empty, too long or not plain text), 'full' or 'empty' (no look to keep).
     */
    static function SaveCurrent($uid, $title, $prev = false)
    {
        global $zdbh;
        self::Ensure();
        $b = ctrl_branding::Get($uid);
        if (!ctrl_branding::HasLook($b)) {
            return 'empty';
        }
        if ($prev) {
            $title = '';
            $zdbh->prepare("DELETE FROM x_branding_tpl WHERE bt_acc_fk = :id AND bt_prev_in = 1")->execute(array(':id' => (int) $uid));
        } else {
            $title = trim(preg_replace('/\s+/u', ' ', (string) $title));
            if ($title === '' || mb_strlen($title) > self::TITLE_MAX || preg_match('/[\x00-\x1F\x7F<>\p{Cf}]/u', $title)) {
                return 'title';
            }
            $q = $zdbh->prepare("SELECT COUNT(*) FROM x_branding_tpl WHERE bt_acc_fk = :id AND bt_prev_in = 0");
            $q->execute(array(':id' => (int) $uid));
            if ((int) $q->fetchColumn() >= self::MAX) {
                return 'full';
            }
        }
        // the pictures are copied inside the database (never through PHP)
        $q = $zdbh->prepare("INSERT INTO x_branding_tpl (bt_acc_fk, bt_prev_in, bt_title_vc, bt_name_vc, bt_colour_vc, bt_welcome_vc, bt_variant_vc, bt_logo_mb, bt_logodark_mb, bt_created_ts)
            SELECT br_acc_fk, :prev, :title, br_name_vc, br_colour_vc, br_welcome_vc, br_variant_vc, br_logo_mb, br_logodark_mb, :t FROM x_branding WHERE br_acc_fk = :id");
        $q->execute(array(':prev' => $prev ? 1 : 0, ':title' => $title, ':t' => time(), ':id' => (int) $uid));
        return '';
    }

    /**
     * Apply a template: its look replaces the account's (the panel address stays). The look it
     * replaces is kept as "Your previous look" first.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param string $which 'b:<id>' for a built-in template, 'u:<id>' for one of the account's own.
     * @return string '' when applied, else 'missing' (no such template) or what ctrl_branding::Save() says is wrong.
     */
    static function Apply($uid, $which)
    {
        global $zdbh;
        self::Ensure();
        $t = null;
        $logo = null;
        $dark = null;
        if (preg_match('/^b:([a-z0-9_-]{1,40})$/', (string) $which, $m)) {
            $all = self::BuiltIn();
            if (!isset($all[$m[1]])) {
                return 'missing';
            }
            $t = $all[$m[1]];
            $root = dirname(dirname(__DIR__));
            $logo = $t['logo'] !== '' ? self::Png($root . $t['logo']) : null;
            $dark = $t['logodark'] !== '' && $logo !== null ? self::Png($root . $t['logodark']) : null;
        } elseif (preg_match('/^u:(\d{1,10})$/', (string) $which, $m)) {
            $q = $zdbh->prepare("SELECT bt_name_vc AS name, bt_colour_vc AS colour, bt_welcome_vc AS welcome, bt_variant_vc AS variant, bt_logo_mb, bt_logodark_mb
                FROM x_branding_tpl WHERE bt_id_pk = :t AND bt_acc_fk = :id");
            $q->execute(array(':t' => (int) $m[1], ':id' => (int) $uid));
            $t = $q->fetch(PDO::FETCH_ASSOC);
            if (!$t) {
                return 'missing';
            }
            foreach (array('bt_logo_mb', 'bt_logodark_mb') as $k) {
                if (is_resource($t[$k])) {
                    $t[$k] = stream_get_contents($t[$k]);
                }
            }
            $logo = self::IsPng($t['bt_logo_mb']) ? $t['bt_logo_mb'] : null;
            $dark = $logo !== null && self::IsPng($t['bt_logodark_mb']) ? $t['bt_logodark_mb'] : null;
        } else {
            return 'missing';
        }
        // (checked by ctrl_branding::Save() below, as when the look is typed in)
        $in = array('name' => (string) $t['name'], 'colour' => (string) $t['colour'], 'welcome' => (string) $t['welcome'], 'variant' => (string) $t['variant']);
        // keep the look being replaced as the previous look (the template is read already, so applying
        // the previous look swaps the two: a second click puts the other one back)
        self::SaveCurrent($uid, '', true);
        $err = ctrl_branding::Save($uid, $in, false);
        if ($err !== '') {
            return $err;
        }
        $q = $zdbh->prepare("UPDATE x_branding SET br_logo_mb = :l, br_logodark_mb = :d, br_logov_vc = :v WHERE br_acc_fk = :id");
        $q->bindValue(':l', $logo, $logo === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $q->bindValue(':d', $dark, $dark === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $q->bindValue(':v', substr(bin2hex(random_bytes(8)), 0, 12));
        $q->bindValue(':id', (int) $uid, PDO::PARAM_INT);
        $q->execute();
        return '';
    }

    /**
     * Delete one of the account's own templates.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID.
     * @param int $id The template.
     * @return bool Whether one was deleted.
     */
    static function Delete($uid, $id)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("DELETE FROM x_branding_tpl WHERE bt_id_pk = :t AND bt_acc_fk = :id");
        $q->execute(array(':t' => (int) $id, ':id' => (int) $uid));
        return $q->rowCount() > 0;
    }

    /**
     * A logo of one of the account's own templates (for its preview).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account ID (only its own templates).
     * @param int $id The template.
     * @param bool $dark The one for dark backgrounds (falls back to the main one).
     * @return string The PNG, or ''.
     */
    static function LogoData($uid, $id, $dark = false)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT " . ($dark ? 'COALESCE(bt_logodark_mb, bt_logo_mb)' : 'bt_logo_mb') . " FROM x_branding_tpl WHERE bt_id_pk = :t AND bt_acc_fk = :id");
        $q->execute(array(':t' => (int) $id, ':id' => (int) $uid));
        $v = $q->fetchColumn();
        if (is_resource($v)) {
            $v = stream_get_contents($v);
        }
        return self::IsPng($v) ? $v : '';
    }

    /**
     * Is it a PNG?
     *
     * @param mixed $data
     * @return bool
     */
    static function IsPng($data)
    {
        return is_string($data) && substr($data, 0, 8) === "\x89PNG\r\n\x1a\n";
    }

    /**
     * A built-in template's logo file, when it is a PNG of a sensible size.
     *
     * @param string $file
     * @return string|null
     */
    static function Png($file)
    {
        if (!is_file($file) || is_link($file) || filesize($file) > ctrl_branding::UPLOAD_MAX) {
            return null;
        }
        $d = (string) file_get_contents($file);
        return self::IsPng($d) ? $d : null;
    }
}
