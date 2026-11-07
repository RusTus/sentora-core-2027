<?php

/**
 * My Account daemon hook: two-factor secrets are stored encrypted (ctrl_secret). Encrypts the ones
 * still stored plain, from before that (they were readable to anyone with a copy of the panel's
 * database), after making room in the column. Nothing to do once they all are.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */
try {
    $n = ctrl_twofactor::EncryptStored();
    if ($n > 0) {
        echo "Two-factor login: $n stored secret(s) encrypted" . fs_filehandler::NewLine();
    }
} catch (Exception $e) {
    echo "Two-factor login: the stored secrets could not be encrypted (" . $e->getMessage() . ")" . fs_filehandler::NewLine();
}
