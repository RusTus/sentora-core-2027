<?php
/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Class provides functionallity to generate secure random strings
 * @package zpanelx
 * @implimentation To be inpliment into zpanel's core fucntions by 10.0.3
 * @subpackage dryden -> runtime
 * @version 1.0.2
 * @author Sam Mottley (smottley@zpanelcp.com)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */

/**
 * Cryptographically secure random strings for tokens, keys and salts.
 */
class runtime_randomstring{
    /**
     * Generate a random string
     * @author Sam Mottley (smottley@zpanelcp.com)
     * @param int|false $size The size of the hash default 50 (false or below 1 also means 50)
     * @param string $characters list of all allowed chars
     * @param bool|string $hash True or False or Type of algeritherm to hash the the mixed string with. Any non-false value now gives a sha256 hex digest (the algorithm name is ignored).
     * @return string A random string
     */    
     static public function randomHash($size = 50, $characters='1234567890qwertyuiopasdfghjklzxcvbnm', $hash = false) {
        // Cryptographically secure (was built from crc32(uniqid(microtime... rand)),
        // which made password-reset keys and other tokens guessable)
        $size = ($size === false || (int) $size < 1) ? 50 : (int) $size;
        $characters = (string) $characters;
        $max = strlen($characters) - 1;
        $out = '';
        for ($i = 0; $i < $size; $i++) {
            $out .= $characters[random_int(0, $max)];
        }
        if ($hash != false) {
            return hash('sha256', $out);
        }
        return $out;
    }

    
}

?>
