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
     * Used to translate a text string into the language preference of the user.
     * @author Pascal Peyremorte (ppeyremorte@sentora.org)
     * @global db_driver $zdbh The ZPX database handle.
     *
     * English text is returned as is. Text not yet in x_translations is added to the
     * table (with empty translations) and returned untranslated.
     *
     * @param string $message The English string to translate.
     * @return string The translated string, or $message when there is no translation.
     */
    static function translate($message) {
        global $zdbh;

        if (empty(self::$LangCol)) {
            $uid = ctrl_auth::CurrentUserID();
            $sql = $zdbh->prepare('SELECT ud_language_vc FROM x_profiles WHERE ud_user_fk=' . $uid);
            $sql->execute();
            $lang = $sql->fetch();
            // The language becomes part of a column name: two letters only (anything
            // else, from an old or hand-edited profile, falls back to English)
            $code = (is_array($lang) && preg_match('/^[a-z]{2}$/', (string) $lang['ud_language_vc'])) ? $lang['ud_language_vc'] : 'en';
            self::$LangCol = 'tr_' . $code . '_tx';
        }
        if (self::$LangCol == 'tr_en_tx')
            return $message; //no translation required, english used

        $SlashedMessage = addslashes($message); //protect special chars
        $sql = $zdbh->prepare('SELECT ' . self::$LangCol . ' FROM x_translations WHERE tr_en_tx =:message');
        $sql->bindParam(':message', $SlashedMessage);
        $sql->execute();
        $result = $sql->fetch();

        if ($result) {
            if (!fs_director::CheckForEmptyValue($result[self::$LangCol]))
                return $result[self::$LangCol]; //valid translation present
            else
                return $message; //translated message empty
        } else { //message not found in the table
            //add unfound message to the table with empties translations
            $sql = $zdbh->prepare('INSERT INTO x_translations SET tr_en_tx=:message');
            $sql->bindParam(':message', $SlashedMessage);
            $sql->execute();
            return $message;
        }
    }

    /**
     * Return array of column names for a table.
     * @author Russell Skinner (rskinner@zpanelcp.com)
     * @global db_driver $zdbh The panel's database connection.
     * @param string $table The table name to return that column names from.
     * @return array<int, string>|null List of column names; null when the query throws.
     */
    static function GetColumnNames($table) {
        global $zdbh;
        $sql = "select column_name from information_schema.columns where lower(table_name)=lower(:table)";
        $stmt = $zdbh->prepare($sql);
        $stmt->bindParam(':table', $table);
        try {
            if ($stmt->execute()) {
                $raw_column_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($raw_column_data as $outer_key => $array) {
                    foreach ($array as $inner_key => $value) {
                        if (!(int) $inner_key) {
                            $column_names[] = $value;
                        }
                    }
                }
            }
            return $column_names;
        } catch (Exception $e) {
            
        }
    }

}

?>