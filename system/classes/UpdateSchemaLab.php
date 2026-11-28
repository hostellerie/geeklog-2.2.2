<?php
namespace Geeklog;

/**
 * Read-only schema probe for the laboratory. The caller supplies a connection
 * to a disposable database and the actual Geeklog vars table name.
 */
final class UpdateSchemaLab
{
    private $connection;
    private $table;

    public function __construct(\PDO $connection, $table)
    {
        if (PHP_SAPI !== 'cli' || !is_string($table) ||
            preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $table) !== 1 ||
            !in_array($connection->getAttribute(\PDO::ATTR_DRIVER_NAME), array('mysql', 'pgsql'), true)) {
            throw new \RuntimeException('Unsupported laboratory schema probe');
        }
        $this->connection = $connection;
        $this->table = $table;
    }

    public function driver()
    {
        return $this->connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    public function identity()
    {
        try {
            $driver = $this->connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $query = $driver === 'mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()';
            $statement = $this->connection->query($query);
            $database = $statement === false ? false : $statement->fetchColumn();
            if ($statement !== false) {
                $statement->closeCursor();
            }
            if (!is_string($database) || $database === '') {
                throw new \RuntimeException('Cannot identify schema source');
            }
            return hash('sha256', $driver . "\n" . $database . "\n" . $this->table);
        } catch (\PDOException $error) {
            throw new \RuntimeException('Cannot identify schema source');
        }
    }

    public function read()
    {
        try {
            $statement = $this->connection->prepare('SELECT value FROM ' . $this->table . ' WHERE name = :name');
            if ($statement === false || !$statement->execute(array('name' => 'database_version'))) {
                throw new \RuntimeException('Cannot query database version');
            }
            $rows = $statement->fetchAll(\PDO::FETCH_COLUMN, 0);
            $statement->closeCursor();
        } catch (\PDOException $error) {
            throw new \RuntimeException('Cannot query database version');
        }
        if (count($rows) !== 1 || !is_string($rows[0]) ||
            preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $rows[0]) !== 1) {
            throw new \RuntimeException('Missing, duplicate or invalid database version');
        }
        return $rows[0];
    }
}
