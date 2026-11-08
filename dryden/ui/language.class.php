<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Language translation class.
 * @package zpanelx
 * @subpackage dryden -> ui
 * @version 1.0.1
 * @author Russell Skinner (rskinner@zpanelcp.com)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-07
 */
/**
 * Translates interface text into the logged-in user's language (x_translations table).
 */
class ui_language {

    /**
     * @var string|null Name of the translation column for the user's language
     *      (e.g. 'tr_de_tx'); worked out once per request by translate().
     */
    static $LangCol;

    /**
     * @var array<string, string> This request's translations so far (English => translated):
     *      a page asks for the same texts many times (each template getter runs twice).
     */
    private static $cache = array();

    /**
     * The folder of the panel's own translations: one file per language (etc/lang/de.php ...), each
     * returning an array English => translation. They come with the panel and its updates.
     *
     * @return string The folder, with a trailing slash.
     */
    static function LangDir()
    {
        return dirname(__FILE__) . '/../../etc/lang/';
    }

    /**
     * The languages the panel has: a file in etc/lang, or a column in x_translations (translations an
     * administrator added there).
     *
     * @return string[] Two-letter codes, 'en' first.
     */
    static function Languages()
    {
        static $codes = null;
        if ($codes === null) {
            $codes = array();
            foreach ((array) glob(self::LangDir() . '*.php') as $f) {
                if (preg_match('/^([a-z]{2})\.php$/', basename((string) $f), $m)) {
                    $codes[] = $m[1];
                }
            }
            foreach ((array) self::GetColumnNames('x_translations') as $col) {
                if (preg_match('/^tr_([a-z]{2})_tx$/', (string) $col, $m)) {
                    $codes[] = $m[1];
                }
            }
            $codes = array_values(array_unique(array_merge(array('en'), $codes)));
        }
        return $codes;
    }

    /**
     * The panel's own translations into a language (etc/lang/<code>.php).
     *
     * @param string $code Two-letter language code.
     * @return array<string, string> English => translation (empty when there is no file).
     */
    static function Shipped($code)
    {
        static $files = array();
        if (!isset($files[$code])) {
            $f = self::LangDir() . $code . '.php';
            $files[$code] = (preg_match('/^[a-z]{2}$/', $code) && is_file($f)) ? (array) include $f : array();
        }
        return $files[$code];
    }

    /**
     * Use an account's language for the rest of this request (translate() otherwise works it out
     * from the logged-in account; without one, English).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int|string $uid The account ID (0: English).
     * @return string The language code used, for example 'de'.
     */
    static function ForAccount($uid)
    {
        global $zdbh;
        $code = 'en';
        if ((int) $uid > 0 && isset($zdbh)) {
            $sql = $zdbh->prepare('SELECT ud_language_vc FROM x_profiles WHERE ud_user_fk = :uid');
            $sql->execute(array(':uid' => (int) $uid));
            $lang = (string) $sql->fetchColumn();
            // only a language the panel has a column for (anything else, from an old or
            // hand-edited profile, falls back to English)
            if (preg_match('/^[a-z]{2}$/', $lang) && in_array($lang, self::Languages(), true)) {
                $code = $lang;
            }
        }
        if (self::$LangCol !== 'tr_' . $code . '_tx') {
            self::$cache = array();
        }
        self::$LangCol = 'tr_' . $code . '_tx';
        return $code;
    }

    /**
     * A group's name to show: the panel's own groups (Administrators, Resellers, Users) are interface
     * words and are translated; a group an administrator or reseller made is data and is shown as named.
     *
     * @param string $name The group's name as stored.
     * @return string The name to show (not HTML-escaped).
     */
    static function GroupName($name)
    {
        $name = (string) $name;
        return in_array($name, array('Administrators', 'Resellers', 'Users'), true) ? self::translate($name) : $name;
    }

    /**
     * Used to translate a text string into the language preference of the user.
     * @author Pascal Peyremorte (ppeyremorte@sentora.org)
     * @global db_driver $zdbh The ZPX database handle.
     *
     * English text is returned as is. Text not yet in x_translations is added to the
     * table (with empty translations) and returned untranslated, so translators see it.
     * Without a logged-in account (the login page, the daemon) the text stays English.
     *
     * Translate fixed English text only: a sentence with a name or a number put into it
     * would be stored once for every name or number. Translate the fixed parts, or use a
     * placeholder (translate('%d accounts') with str_replace/sprintf afterwards).
     *
     * @param string $message The English string to translate.
     * @return string The translated string, or $message when there is no translation.
     */
    static function translate($message) {
        global $zdbh, $controller;
        $message = (string) $message;
        if (empty(self::$LangCol)) {
            // the panel's pages: the request controller; the small endpoints a page calls in the
            // background (JSON, downloads) have none, but their session says who is asking
            if (isset($controller) && is_object($controller)) {
                $uid = (int) ctrl_auth::CurrentUserID();
            } else {
                $uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
            }
            self::ForAccount($uid);
        }
        if (self::$LangCol === 'tr_en_tx' || trim($message) === '') {
            return $message; // no translation required, English used
        }
        if (isset(self::$cache[$message])) {
            return self::$cache[$message];
        }
        // 1. the panel's own translations (etc/lang), exactly as written
        $code = substr(self::$LangCol, 3, 2);
        $shipped = self::Shipped($code);
        if (isset($shipped[$message]) && $shipped[$message] !== '') {
            return self::$cache[$message] = $shipped[$message];
        }
        // a text from the database or a Windows-edited file may have \r\n where the file has \n
        $lf = str_replace("\r\n", "\n", $message);
        if ($lf !== $message && isset($shipped[$lf]) && $shipped[$lf] !== '') {
            return self::$cache[$message] = $shipped[$lf];
        }
        // 2. x_translations: what an administrator added (only when the language has a column there).
        // Older versions stored and looked texts up with addslashes(), so both forms are looked up.
        static $hasCol = null;
        if ($hasCol === null) {
            $hasCol = in_array(self::$LangCol, (array) self::GetColumnNames('x_translations'), true);
        }
        $slashed = addslashes($message);
        $sql = $zdbh->prepare('SELECT tr_en_tx' . ($hasCol ? ', ' . self::$LangCol . ' AS tr' : '') . ' FROM x_translations WHERE tr_en_tx = :m OR tr_en_tx = :s');
        $sql->execute(array(':m' => $message, ':s' => $slashed));
        $rows = $sql->fetchAll();
        if (!$rows && !isset($shipped[$message])) {
            // a text no translation knows yet: add it with empty translations, so translators see it
            $sql = $zdbh->prepare('INSERT INTO x_translations SET tr_en_tx = :m');
            $sql->execute(array(':m' => $message));
            return self::$cache[$message] = $message;
        }
        foreach ($rows as $row) {
            // (the column compares without case; "Delete" and "delete" are different texts here)
            if ($hasCol && (string) $row['tr'] !== '' && ($row['tr_en_tx'] === $message || $row['tr_en_tx'] === $slashed)) {
                return self::$cache[$message] = (string) $row['tr']; // valid translation present
            }
        }
        return self::$cache[$message] = $message; // no translation
    }

    /**
     * Like date(), but month and weekday names (F, M, l, D) in the account's language.
     *
     * The names come from the language file (keys @months, @months_short, @days, @days_short,
     * kept apart from the English texts: "May" is also a word on the pages). Other format
     * characters, and every format in English, are exactly date()'s.
     *
     * @param string $format A date() format.
     * @param int|null $ts Optional. A Unix time. Default null (now).
     * @return string The date (not HTML-escaped).
     */
    static function Date($format, $ts = null)
    {
        $ts = $ts === null ? time() : (int) $ts;
        if (empty(self::$LangCol)) {
            self::translate('');
        }
        $names = self::Shipped(substr((string) self::$LangCol, 3, 2));
        if (empty($names['@months'])) {
            return date($format, $ts);
        }
        $out = '';
        $len = strlen($format);
        for ($i = 0; $i < $len; $i++) {
            $c = $format[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $out .= $format[++$i];
                continue;
            }
            switch ($c) {
                case 'F': $out .= $names['@months'][(int) date('n', $ts) - 1]; break;
                case 'M': $out .= $names['@months_short'][(int) date('n', $ts) - 1]; break;
                case 'l': $out .= $names['@days'][(int) date('w', $ts)]; break;
                case 'D': $out .= $names['@days_short'][(int) date('w', $ts)]; break;
                case 'S': break; // the English 1st/2nd/3rd ending: none in other languages
                default: $out .= date($c, $ts);
            }
        }
        return $out;
    }

    /**
     * Return array of column names for a table of the panel's database.
     * @author Russell Skinner (rskinner@zpanelcp.com)
     * @global db_driver $zdbh The panel's database connection.
     * @param string $table The table name to return that column names from.
     * @return array<int, string>|null List of column names; null when the query throws.
     */
    static function GetColumnNames($table) {
        global $zdbh;
        // this database only: a copy of the table in another database (a backup, a test
        // copy) used to add its columns too
        $sql = "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND lower(table_name) = lower(:table) ORDER BY ordinal_position";
        $stmt = $zdbh->prepare($sql);
        $stmt->bindParam(':table', $table);
        try {
            if ($stmt->execute()) {
                return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            }
            return array();
        } catch (Exception $e) {
            return null;
        }
    }

}

?>