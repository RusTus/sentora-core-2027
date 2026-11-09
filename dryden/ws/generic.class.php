<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Generic web services class.
 * @package zpanelx
 * @subpackage dryden -> webservices
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-06
 */
class ws_generic {

    /**
     * Provides very basic way of retrieving a result as a string from a given URL (RAW) this does not need to be a 'true' web service.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $requestURL The URL to the resource.
     * @return string|false If the request was successful it will return the contents of the requested URL otherwise will return 'false'.
     */
    static function ReadURLRequestResult($requestURL) {
        ob_start();
        @readfile($requestURL);
        $reqcontent = ob_get_contents();
        ob_clean();
        if ($reqcontent)
            return $reqcontent;
        $ws_log = new debug_logger();
        $ws_log->logcode = "903";
        $ws_log->detail = "Unable to connect to webservice URL (" . $requestURL . ") as requested in ws_generic::ReadURLRequestResult()";
        $ws_log->writeLog();
        return false;
    }

    /**
     * Generic method to send POST data to a web service and then return its response (without the need to use cURL or another HTTP client).
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $url The URL of which to POST the data too.
     * @param string $data The data content of which to send.
     * @param string|null $optional_headers Option headers if you require to send them.
     * @return string|false The response recieved, or false when the URL could not be opened or read.
     */
    static function DoPostRequest($url, $data, $optional_headers = null) {
//$ws_log = new debug_logger();
//$ws_log->logcode = "904";
        $params = array('http' => array(
                'method' => 'POST',
                'content' => $data
                ));
        if ($optional_headers !== null) {
            $params['http']['header'] = $optional_headers;
        }
        $ctx = stream_context_create($params);
        $fp = @fopen($url, 'rb', false, $ctx);
        if (!$fp) {
//$ws_log->detail = "Problem with " .$url. ", ".$php_errormsg."";
//$ws_log->writeLog();
            // PHP 8 throws a TypeError if stream_get_contents() gets false, which stopped the daemon
            return false;
        }
        $response = @stream_get_contents($fp);
        if ($response == false) {
//$ws_log->detail = "Problem reading data from ".$url. ", ".$php_errormsg."";
//$ws_log->writeLog();
        }
        return $response;
    }

    /**
     * Captures the RAW POST data passed to this script.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return string|false The raw request data, or false when there is none.
     */
    static function ProcessRawRequest() {
        $xml_raw_data = fs_filehandler::ReadFileContents('php://input');
        return $xml_raw_data;
    }

    /**
     * Returns the value of a tag from an XML string.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $tagname The name of the tag of which to retrieve the value from.
     * @param string $xml The XML string
     * @return string|null The XML tag value, or null (with a warning) when the tag is not found.
     */
    static function GetTagValue($tagname, $xml) {
        $matches = array();
        $t = preg_quote((string) $tagname, '/');
        // (s: a value may span lines; none sent: null, as before, without a PHP warning)
        if (!preg_match('/<' . $t . '>(.*?)<\/' . $t . '>/s', (string) $xml, $matches)) {
            return null;
        }
        return $matches[1];
    }

    /**
     * Takes an XML string and converts it into a usable PHP array.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $contents The XML content to convert to a PHP array.
     * @param int $get_attributes Retieve the tag attrubtes too (1 = yes, 0 =no)?
     * @param string $priority What should take priority? 'tag' or attributes?
     * @return array<string, mixed>|null Associated array of the XML tag data (nested); null when the XML could not be parsed.
     */
    static function XMLToArray($contents, $get_attributes = 1, $priority = 'tag') {
        if (!function_exists('xml_parser_create')) {
            return array('message' => 'xml_parser_create function does not exist on the server!');
        }
        $parser = xml_parser_create('');
        xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8");
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
        xml_parse_into_struct($parser, trim($contents), $xml_values);
        unset($parser);
        if (!$xml_values)
            return; //Hmm...
        $xml_array = array();
        $parents = array();
        $opened_tags = array();
        $arr = array();
        $current = & $xml_array;
        $repeated_tag_index = array();
        foreach ($xml_values as $data) {
            unset($attributes, $value);
            extract($data);
            $result = array();
            $attributes_data = array();
            if (isset($value)) {
                if ($priority == 'tag') {
                    $result = $value;
                } else {
                    $result['value'] = $value;
                }
            }
            if (isset($attributes) and $get_attributes) {
                foreach ($attributes as $attr => $val) {
                    if ($priority == 'tag') {
                        $attributes_data[$attr] = $val;
                    } else {
                        $result['attr'][$attr] = $val; //Set all the attributes in a array called 'attr'
                    }
                }
            }
            if ($type == "open") {
                $parent[$level - 1] = & $current;
                if (!is_array($current) or (!in_array($tag, array_keys($current)))) {
                    $current[$tag] = $result;
                    if ($attributes_data)
                        $current[$tag . '_attr'] = $attributes_data;
                    $repeated_tag_index[$tag . '_' . $level] = 1;
                    $current = & $current[$tag];
                }else {
                    if (isset($current[$tag][0])) {
                        $current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;
                        $repeated_tag_index[$tag . '_' . $level]++;
                    } else {
                        $current[$tag] = array(
                            $current[$tag],
                            $result
                        );
                        $repeated_tag_index[$tag . '_' . $level] = 2;
                        if (isset($current[$tag . '_attr'])) {
                            $current[$tag]['0_attr'] = $current[$tag . '_attr'];
                            unset($current[$tag . '_attr']);
                        }
                    }
                    $last_item_index = $repeated_tag_index[$tag . '_' . $level] - 1;
                    $current = & $current[$tag][$last_item_index];
                }
            } elseif ($type == "complete") {
                if (!isset($current[$tag])) {
                    $current[$tag] = $result;
                    $repeated_tag_index[$tag . '_' . $level] = 1;
                    if ($priority == 'tag' and $attributes_data)
                        $current[$tag . '_attr'] = $attributes_data;
                }else {
                    if (isset($current[$tag][0]) and is_array($current[$tag])) {
                        $current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;
                        if ($priority == 'tag' and $get_attributes and $attributes_data) {
                            $current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
                        }
                        $repeated_tag_index[$tag . '_' . $level]++;
                    } else {
                        $current[$tag] = array(
                            $current[$tag],
                            $result
                        );
                        $repeated_tag_index[$tag . '_' . $level] = 1;
                        if ($priority == 'tag' and $get_attributes) {
                            if (isset($current[$tag . '_attr'])) {
                                $current[$tag]['0_attr'] = $current[$tag . '_attr'];
                                unset($current[$tag . '_attr']);
                            }
                            if ($attributes_data) {
                                $current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
                            }
                        }
                        $repeated_tag_index[$tag . '_' . $level]++; //0 and 1 index is already taken
                    }
                }
            } elseif ($type == 'close') {
                $current = & $parent[$level - 1];
            }
        }
        return ($xml_array);
    }

    /**
     * Takes an JSON string and converts it into a usable PHP array.
     * @param string $content The JSON string of which to decode.
     * @return mixed Associated array of the JSON tag data (whatever json_decode() gives: null on invalid JSON, a scalar for scalar JSON).
     */
    static function JSONToArray($content) {
        return json_decode($content, true);
    }

}

?>
