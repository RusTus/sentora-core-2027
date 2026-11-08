<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Dataobject class a means to handle data arrays.
 * @package zpanelx
 * @subpackage dryden -> runtime
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-06
 */
/**
 * Simple key/value container used to pass data to templates.
 */
class runtime_dataobject {

    /**
     * The associated array of which to store all dataobject entites in.
     * @var array<string, string>
     */
    protected $object;

    /**
     * Start with an empty data object.
     */
    function __construct() {
        $this->object = array();
    }

    /**
     * Used to 'push' an associated array on to the object's $object array.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * Any further arguments are merged in too: arrays key by key, scalars become
     * keys with an empty string value.
     *
     * @param array<string,string> &$arr The associative array to add the data to (by reference).
     * @return array<string, string> The object's $object array.
     */
    private function array_push_associative(&$arr) {
        $args = func_get_args();
        foreach ($args as $arg) {
            if (is_array($arg)) {
                foreach ($arg as $key => $value) {
                    $arr[$key] = $value;
                }
            } else {
                $arr[$arg] = "";
            }
        }
        return $this->object;
    }

    /**
     * Enables an easy to use key/value pushing to the data object.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $name The name of the key.
     * @param mixed $value The value of the key (converted to a string).
     * @return void
     */
    public function addItemValue($name, $value) {
        $this->array_push_associative($this->object, array("$name" => "$value"));
    }

    /**
     * Returns an associated array (data object)
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return array<string, string> The dataobject contents.
     */
    public function getDataObject() {
        return $this->object;
    }

    /**
     * Returns a named data object record value.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $name The name of the data record (key) to return from the current object.
     * @return string The value.
     */
    public function getDataRecord($name) {
        return $this->object[$name];
    }

}

?>
