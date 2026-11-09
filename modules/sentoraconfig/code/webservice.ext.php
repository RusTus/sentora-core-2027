<?php

/**
 * Sentora Config module: web service (XMWS API).
 *
 * @package zpanelx
 * @subpackage modules
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 *
 * Updated by RusTus, 2026-11-09
 */

/**
 * Sentora Config web service (methods called by name from the 'request' tag).
 */
class webservice extends ws_xmws {

    /**
     * Get the full list of system options (every row of x_settings).
     *
     * Empty values are sent as "NULL".
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<string, string> The response data object: 'response' and 'content' (one <setting> section per option: id, name, value, description, usereditable).
     */
    function GetAllSystemOptions() {
        global $zdbh;
        if (!self::IsAdmin()) {
            return self::AdminOnly();
        }
        $response_xml = "\n";
        $sql = $zdbh->prepare("SELECT * FROM x_settings ORDER BY so_id_pk ASC");
        $sql->execute();

        while ($rowoptions = $sql->fetch()) {

            if ($rowoptions['so_value_tx'] == "") {
                $value = "NULL";
            } elseif (preg_match('/pass|secret|token|apikey|api_key|_key$|private|credential|smtp_username/i', (string) $rowoptions['so_name_vc'])) {
                // secrets (the API key itself, the mail server's password, tokens) are never sent:
                // a program reading the settings sees that one is set, not what it is
                $value = '********';
            } else {
                $value = $rowoptions['so_value_tx'];
            }

            $response_xml = $response_xml . ws_xmws::NewXMLContentSection('setting', array(
                        'id' => $rowoptions['so_id_pk'],
                        'name' => $rowoptions['so_name_vc'],
                        'value' => $value,
                        'description' => $rowoptions['so_desc_tx'],
                        'usereditable' => $rowoptions['so_usereditable_en'],
                    ));
        }

        $dataobject = new runtime_dataobject();
        $dataobject->addItemValue('response', '');
        $dataobject->addItemValue('content', $response_xml);

        return $dataobject->getDataObject();
    }

}

?>
