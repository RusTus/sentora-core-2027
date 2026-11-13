<?php

/**
 * Core class: the statements of the panel's database connection (db_driver). The same as PDO's own,
 * except that running one that changes something (anything but SELECT, SHOW, DESCRIBE, EXPLAIN)
 * tells the connection, so what a page remembered of the database (db_driver::Remember) is read again.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-13
 */
class db_statement extends PDOStatement
{
    /** @var db_driver The connection this statement belongs to. */
    public $db;

    /**
     * @param db_driver $db
     */
    protected function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Run the statement (as PDOStatement::execute).
     *
     * @param array|null $params
     * @return bool
     */
    public function execute(?array $params = null): bool
    {
        $r = parent::execute($params);
        if (!db_driver::IsRead($this->queryString)) {
            $this->db->Changed();
        }
        return $r;
    }
}
