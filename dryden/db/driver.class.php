<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Database access class, enables PDO database access.
 * @package zpanelx
 * @subpackage dryden -> db
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */

/**
 * The panel's database connection: PDO with Sentora's own error pages.
 *
 * Wraps query(), exec() and prepare() so that a PDOException prints an error
 * (HTML in the browser, plain text on the CLI) and stops the script, and adds
 * bindQuery()/returnRow()/returnRows() helpers. Created in inc/dbc.inc.php as
 * the global $zdbh.
 */
class db_driver extends PDO {

    /**
     * @var \PDOStatement|false|null The statement last prepared by bindQuery().
     */
    private $_prepared = null;

    /**
     * @var \PDOStatement|false|null The statement last executed by bindQuery() (read by returnRow() etc.).
     */
    private $_executed = null;

    /**
     * @var array|null Unused.
     */
    private $_result = null;

    /**
     * @var string[] SQL of every statement passed to prepare(), for the debug output.
     */
    private $queriesExecuted = array();

    /**
     * Remember the statement last prepared by bindQuery().
     *
     * @param \PDOStatement|false $prepared The prepared statement.
     * @return void
     */
    private function setPrepared($prepared) {
        $this->_prepared = $prepared;
    }

    /**
     * Remember the statement last executed by bindQuery().
     *
     * @param \PDOStatement|false $executed The executed statement.
     * @return void
     */
    private function setExecuted($executed) {
        $this->_executed = $executed;
    }

    /**
     * Get the statement last prepared by bindQuery().
     *
     * @return \PDOStatement|false|null Null before the first bindQuery().
     */
    private function getPrepared() {
        return $this->_prepared;
    }

    /**
     * Get the statement last executed by bindQuery().
     *
     * @return \PDOStatement|false|null Null before the first bindQuery().
     */
    private function getExecuted() {
        return $this->_executed;
    }

    /**
     * Open the database connection.
     *
     * @param string $dsn The PDO data source name.
     * @param string|null $username Optional. Database user name. Default null.
     * @param string|null $password Optional. Database password. Default null.
     * @param array|null $driver_options Optional. PDO driver options. Default null.
     */
    public function __construct($dsn, $username = null, $password = null, $driver_options = null) {
        // PHP 8 PDO throws on errors by default; Sentora handles them itself below.
        parent::__construct($dsn, $username, $password, $driver_options);
    }

    /**
     * @var string CSS printed before the HTML database error message.
     */
    var $css = "<style type=\"text/css\"><!--
            .dbwarning {
                    font-family: Verdana, Geneva, sans-serif;
                    font-size: 14px;
                    color: #C00;
                    background-color: #FCC;
                    padding: 30px;
                    border: 1px solid #C00;
            }
            p {
                    font-size: 12px;
                    color: #000;
                    white-space: pre-wrap;
            }
            pre {
                color: #666;
            }
            </style>";

    /**
     * Extract the message and stack trace from a PDOException for the error page.
     *
     * @param \PDOException|string $exception The exception (used as a string).
     * @return string The error message followed by the stack trace.
     */
    private function cleanexpmessage($exception) {
        $res = strstr($exception, "]: ", false);
        $res1 = str_replace(']: ', '', $res);
        $res2 = strstr($res1, 'Stack', true);
        $stack = strstr($exception, 'Stack trace:', false);
        $stack1 = strstr($stack, '}', true);
        $stack2 = str_replace("Stack trace:", "", $stack1);
        return $res2 . $stack2 . "}";
    }

    /**
     * Run an SQL query; on error print the error and stop the script.
     *
     * @param string $query The SQL to run.
     * @param int|null $fetchMode Optional. PDO::FETCH_* mode. Default null.
     * @param mixed ...$fetchModeArgs Extra arguments for the fetch mode.
     * @return \PDOStatement|false The result set (dies instead of throwing on error).
     */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        try {
            $result = ($fetchMode === null) ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
            return($result);
        } catch (PDOException $e) {
            $errormessage = $this->errorInfo();
            $clean = $this->cleanexpmessage($e);
            if (!runtime_controller::IsCLI()) {
                $error_html = $this->css . "<div class=\"dbwarning\"><strong>Critical Error:</strong> [0144] - Sentora database 'query' error (" . $this->errorCode() . ").<p>A database query has caused an error, the details of which can be found below.</p><p><strong>Error message:</strong></p><pre> " . $errormessage[2] . "</pre><p><strong>SQL Executed:</strong></p><pre>" . $query . "</pre><p><strong>Stack trace: </strong></p><pre>" . $clean . "</pre></div>";
            } else {
                $error_html = "SQL Error: " . $errormessage[2] . "\n";
            }
            die($error_html);
        }
    }

    /**
     * Execute an SQL statement; on error print the error and stop the script.
     *
     * @param string $query The SQL to run.
     * @deprecated since version 10.0.1
     * @return int|false Number of affected rows.
     */
    public function exec(string $query): int|false {
        try {
            $result = parent::exec($query);
            return($result);
        } catch (PDOException $e) {
            $errormessage = $this->errorInfo();
            $clean = $this->cleanexpmessage($e);
            if (!runtime_controller::IsCLI()) {
                $error_html = $this->css . "<div class=\"dbwarning\"><strong>Critical Error:</strong> [0144] - Sentora database 'exec' error (" . $this->errorCode() . ").<p>A database query has caused an error, the details of which can be found below.</p><p><strong>Error message:</strong></p><pre> " . $errormessage[2] . "</pre><p><strong>SQL Executed:</strong></p><pre>" . $query . "</pre><p><strong>Stack trace: </strong></p><pre>" . $clean . "</pre></div>";
            } else {
                $error_html = "SQL Error: " . $errormessage[2] . "\n";
            }
            die($error_html);
        }
    }

    /**
     * The main query function using bind variables for SQL injection protection.
     * Returns an array of results.
     * @author Kevin Andrews (kandrews@zpanelcp.com)
     * @param string $sqlString The SQL with named placeholders.
     * @param array<string,mixed> $bindArray Placeholder (e.g. ':uid') => value.
     * @param array $driver_options Optional. PDO driver options. Default empty.
     * @return \PDOStatement The executed statement.
     */
    public function bindQuery($sqlString, array $bindArray, $driver_options = array()) {
        $sqlPrepare = $this->prepare($sqlString, $driver_options);
        $this->setPrepared($sqlPrepare);

        $this->bindParams($sqlPrepare, $bindArray);

        $sqlPrepare->execute();
        $this->setExecuted($sqlPrepare);

        return $sqlPrepare;
    }

    /**
     * Prepare an SQL statement and log it; on error print the error and stop the script.
     *
     * @param string $query The SQL to prepare.
     * @param array $driver_options Optional. PDO driver options. Default empty.
     * @return \PDOStatement|false The prepared statement.
     */
    public function prepare(string $query, array $driver_options = array()): PDOStatement|false {
        try {
            $result = parent::prepare($query, $driver_options);
            $this->queriesExecuted[] = $query;
            return($result);
        } catch (PDOException $e) {
            $errormessage = $this->errorInfo();
            $clean = $this->cleanexpmessage($e);
            if (!runtime_controller::IsCLI()) {
                $error_html = $this->css . "<div class=\"dbwarning\"><strong>Critical Error:</strong> [0144] - Sentora database 'prepare' error (" . $this->errorCode() . ").<p>A database query has caused an error, the details of which can be found below.</p><p><strong>Error message:</strong></p><pre> " . $errormessage[2] . "</pre><p><strong>SQL Executed:</strong></p><pre>" . $query . "</pre><p><strong>Stack trace: </strong></p><pre>" . $clean . "</pre></div>";
            } else {
                $error_html = "SQL Error: " . $errormessage[2] . "\n";
            }
            die($error_html);
        }
    }

    /**
     * Binding an array of bind variable pairs to a prepared sql statement.
     * @author Kevin Andrews (kandrews@zpanelcp.com)
     * @param PDOStatement $sqlPrepare The prepared statement.
     * @param array<string,mixed> $bindArray Placeholder => value (bound by reference).
     * @return void
     */
    public function bindParams(PDOStatement $sqlPrepare, array $bindArray) {
        foreach ($bindArray as $bindKey => &$bindValue) {
            $sqlPrepare->bindParam($bindKey, $bindValue);
        }
    }

    /**
     * Returns the first result or next result if previously called.
     * @return array<string, mixed>|false The row, or false when there are no more rows.
     */
    public function returnRow() {
        return $this->getExecuted()->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Returns a multidimensional array of results.
     * @return array<int, array<int|string, mixed>> All remaining rows (FETCH_BOTH).
     */
    public function returnRows() {
        return $this->getExecuted()->fetchAll();
    }

    /**
     * Returns the rows affected by any query.
     * @return int
     */
    public function returnResult() {
        return $this->getExecuted()->rowCount();
    }

    /**
     * The function is the equilivent to mysql_real_escape_string needed due to PDO issues with `
     * @author Sam Mottley (smottley@zpanelcp.com)
     * @param string $string string to be cleaned
     * @return string Clean version of the string
     */
    public function mysqlRealEscapeString($string) {
        $search = array("\\", "\0", "\n", "\r", "\x1a", "'", '"', "`"); //`
        $replace = array("\\\\", "\\0", "\\n", "\\r", "\Z", "\'", '\"', ""); //`
        $cleanString = str_replace($search, $replace, $string);
        return $cleanString;
    }

    /**
     * Returns a list of all the current queries executed. (Implemented for the Debug/Execution class!)
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return string[] List of executed SQL queries.
     */
    public function getExecutedQueries() {
        return $this->queriesExecuted;
    }

}

?>
