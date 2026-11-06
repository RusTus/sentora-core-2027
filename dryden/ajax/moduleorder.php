<?php
/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * AJAX Handler to save Module Category block positions on dashboard page
 * @package zpanelx
 * @subpackage dryden -> ajax
 * @version 1.1.0
 * @author Jason Davis (jason.davis.fl@gmail.com)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */

/* Include Library files*/
$rawPath = str_replace("\\", "/", dirname(__FILE__));
$rootPath = str_replace("/dryden/ajax", "/", $rawPath);
chdir($rootPath);

// Same session cookie settings as the panel itself (index.php)
require_once 'inc/session.inc.php';
session_start();

require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

// The panel's own error setting (production: errors go to the log, not the page)
debug_phperrors::SetMode(ctrl_options::GetSystemOption('debug_mode'));


if (isset($_SESSION['zpuid'])) {
    if (!runtime_csfr::Valid()) {
        // The page's CSRF token (from <meta name="csfr-token">) must come with the request
        $return = array('sucess' => false, 'status' => 'Invalid token');
    } elseif (isAjax()){

        // Prep $_POST['moduleorder'] to insert into DB
        if(isset($_POST['moduleorder'])){

            //ChromePhp::log($_POST['moduleorder']);

            // Only a list of category IDs (numbers) is stored
            $ids = is_array($_POST['moduleorder']) ? array_values($_POST['moduleorder']) : array();
            foreach ($ids as $id) {
                if (!is_scalar($id) || !ctype_digit((string) $id)) {
                    $ids = array();
                    break;
                }
            }
            $order = json_encode(array_map('strval', $ids));

            // Store in DB a JSON string like this [1","2","3","4","5","6","7","8"]
            if(UpdateModuleOrder($_SESSION['zpuid'], $order)){
                $return = array('sucess' => true, 'status' => 'Module Order Saved to Database');
            }

        }else{
            $return = array('sucess' => false, 'status' => 'Module Order was not Valid');
        }

    }else{
        $return = array('sucess' => false, 'status' => 'Must be an AJAX Request');
    }

} else {
    $return = array('sucess' => false, 'status' => 'You Must be Logged In!');
}

/* Set JSON Header */
header('Content-type: application/json');
echo json_encode($return);


/**
 * Check if the current request is an XMLHttpRequest (AJAX) request.
 *
 * @return bool True when the X-Requested-With header is "XMLHttpRequest".
 */
function isAjax() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Save the dashboard module category order for an account.
 *
 * Writes x_accounts.ac_catorder_vc for the given account.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @param int|string $uid The account ID (from the session 'zpuid').
 * @param string|false $order The JSON-encoded category order list (json_encode() output).
 * @return int Number of rows updated (0 when nothing changed, so the caller treats it as falsy).
 */
function UpdateModuleOrder($uid, $order) {
    global $zdbh;
    $sqlString = "
        UPDATE x_accounts
        SET ac_catorder_vc = :order
        WHERE ac_id_pk = :uid
        ";
    $bindArray = array(
        ':order' => $order,
        ':uid'   => $uid,
    );
    $zdbh->bindQuery( $sqlString, $bindArray );

    return $zdbh->returnResult();
}

/** @todo need to check session against database plus beforehand check request is from localhost */
?>