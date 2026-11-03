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

class runtime_randomstring{
    /**
     * Generate a random string
     * @author Sam Mottley (smottley@zpanelcp.com)
     * @param int $size The size of the hash default 50 
     * @param string $charachters list of all allowed chars
     * @param string $hash True or False or Type of algeritherm to hash the the mixed string with. Hash may break other settings depending on the argirithom selected.
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
