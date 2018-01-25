<?php

namespace DreamFactory\Core\Database\Components;

use DreamFactory\Core\Contracts\DbSchemaInterface;
use DreamFactory\Core\Database\Schema\ColumnSchema;
use DreamFactory\Core\Database\Schema\FunctionSchema;
use DreamFactory\Core\Database\Schema\NamedResourceSchema;
use DreamFactory\Core\Database\Schema\ParameterSchema;
use DreamFactory\Core\Database\Schema\ProcedureSchema;
use DreamFactory\Core\Database\Schema\RoutineSchema;
use DreamFactory\Core\Database\Schema\TableSchema;
use DreamFactory\Core\Enums\DbResourceTypes;
use DreamFactory\Core\Enums\DbSimpleTypes;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotImplementedException;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;

/**
 * Schema is the base class for retrieving metadata information.
 *
 */
class Schema implements DbSchemaInterface
{
    /**
     * @const integer Maximum size of a string
     */
    const DEFAULT_STRING_MAX_SIZE = 255;

    /**
     * @const string Quoting characters
     */
    const LEFT_QUOTE_CHARACTER = '';

    /**
     * @const string Quoting characters
     */
    const RIGHT_QUOTE_CHARACTER = '';

    /**
     * Default fetch mode for procedures and functions
     */
    const ROUTINE_FETCH_MODE = \PDO::FETCH_NAMED;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * Constructor.
     *
     * @param ConnectionInterface $conn database connection.
     */
    public function __construct($conn)
    {
        $this->connection = $conn;
    }

    /**
     * @return ConnectionInterface database connection. The connection is active.
     */
    public function getDbConnection()
    {
        return $this->connection;
    }

    /**
     * @return mixed
     */
    public function getUserName()
    {
        return $this->connection->getConfig('username');
    }

    /**
     * @param       $query
     * @param array $bindings
     * @param null  $column
     *
     * @return array
     */
    public function selectColumn($query, $bindings = [], $column = null)
    {
        $rows = $this->connection->select($query, $bindings);
        foreach ($rows as $key => $row) {
            if (!empty($column)) {
                $rows[$key] = data_get($row, $column);
            } else {
                $row = (array)$row;
                $rows[$key] = reset($row);
            }
        }

        return $rows;
    }

    /**
     * @param       $query
     * @param array $bindings
     * @param null  $column
     *
     * @return mixed|null
     */
    public function selectValue($query, $bindings = [], $column = null)
    {
        if (null !== $row = $this->connection->selectOne($query, $bindings)) {
            if (!empty($column)) {
                return data_get($row, $column);
            } else {
                $row = (array)$row;

                return reset($row);
            }
        }

        return null;
    }

    /**
     * Quotes a string value for use in a query.
     *
     * @param string $str string to be quoted
     *
     * @return string the properly quoted string
     * @see http://www.php.net/manual/en/function.PDO-quote.php
     */
    public function quoteValue($str)
    {
        if (is_int($str) || is_float($str)) {
            return $str;
        }

        if (($value = $this->connection->getPdo()->quote($str)) !== false) {
            return $value;
        } else  // the driver doesn't support quote (e.g. oci)
        {
            return "'" . addcslashes(str_replace("'", "''", $str), "\000\n\r\\\032") . "'";
        }
    }

    /**
     * Returns the default schema name for the database.
     * This method should be overridden by child classes in order to support this feature
     * because the default implementation simply returns null.
     *
     * @throws \Exception
     * @return string Default schema name for this connection
     */
    public function getDefaultSchema()
    {
        return null;
    }

    /**
     * Returns all schema names in the database.
     * This method should be overridden by child classes in order to support this feature
     * because the default implementation simply throws an exception.
     *
     * @throws \Exception
     * @return array all schema names in the database.
     */
    public function getSchemas()
    {
//        throw new \Exception( "{get_class( $this )} does not support fetching all schema names." );
        return [''];
    }

    /**
     * Return an array of supported schema resource types.
     * @return array
     */
    public function getSupportedResourceTypes()
    {
        return [DbResourceTypes::TYPE_TABLE];
    }

    /**
     * @param string $type Resource type
     *
     * @return boolean
     */
    public function supportsResourceType($type)
    {
        return in_array($type, $this->getSupportedResourceTypes());
    }

    /**
     * @param string $type Resource type
     * @param string $name
     * @param bool   $returnName
     *
     * @return mixed
     * @throws \Exception
     */
    public function doesResourceExist($type, $name, $returnName = false)
    {
        if (empty($name)) {
            throw new \InvalidArgumentException('Resource name cannot be empty.');
        }

        //  Build the lower-cased resource array
        $names = $this->getResourceNames($type);

        //	Search normal, return real name
        $ndx = strtolower($name);
        if (false !== array_key_exists($ndx, $names)) {
            return $returnName ? $names[$ndx]->name : true;
        }

        return false;
    }

    /**
     * Return an array of names of a particular type of resource.
     *
     * @param string $type   Resource type
     * @param string $schema Schema name if any specific requested
     *
     * @return array
     * @throws \Exception
     */
    public function getResourceNames($type, $schema = '')
    {
        switch ($type) {
            case DbResourceTypes::TYPE_SCHEMA:
                return $this->getSchemas();
            case DbResourceTypes::TYPE_TABLE:
                return $this->getTableNames($schema);
            case DbResourceTypes::TYPE_VIEW:
                return $this->getViewNames($schema);
            case DbResourceTypes::TYPE_TABLE_CONSTRAINT:
                return $this->getTableConstraints($schema);
            case DbResourceTypes::TYPE_PROCEDURE:
                return $this->getProcedureNames($schema);
            case DbResourceTypes::TYPE_FUNCTION:
                return $this->getFunctionNames($schema);
            default:
                return [];
        }
    }

    /**
     * Return the metadata about a particular schema resource.
     *
     * @param string                     $type Resource type
     * @param string|NamedResourceSchema $name Resource name
     *
     * @return null|mixed
     * @throws \Exception
     */
    public function getResource($type, &$name)
    {
        switch ($type) {
            case DbResourceTypes::TYPE_SCHEMA:
                return $name;
            case DbResourceTypes::TYPE_TABLE:
                $this->loadTable($name);

                return $name;
            case DbResourceTypes::TYPE_VIEW:
                $this->loadView($name);

                return $name;
            case DbResourceTypes::TYPE_PROCEDURE:
                $this->loadProcedure($name);

                return $name;
            case DbResourceTypes::TYPE_FUNCTION:
                $this->loadFunction($name);

                return $name;
            default:
                return null;
        }
    }

    /**
     * @param string $type Resource type
     * @param string $name Resource name
     * @return mixed
     * @throws \Exception
     */
    public function dropResource($type, $name)
    {
        switch ($type) {
            case DbResourceTypes::TYPE_SCHEMA:
                throw new \Exception('Dropping the schema resource is not currently supported.');
                break;
            case DbResourceTypes::TYPE_TABLE:
                $this->dropTable($name);
                break;
            case DbResourceTypes::TYPE_TABLE_FIELD:
                if (!is_array($name) || (2 > count($name))) {
                    throw new \InvalidArgumentException('Invalid resource name for type.');
                }
                $this->dropColumns($name[0], $name[1]);
                break;
            case DbResourceTypes::TYPE_TABLE_CONSTRAINT:
                if (!is_array($name) || (2 > count($name))) {
                    throw new \InvalidArgumentException('Invalid resource name for type.');
                }
                $this->dropRelationship($name[0], $name[1]);
                break;
            case DbResourceTypes::TYPE_PROCEDURE:
                throw new \Exception('Dropping the stored procedure resource is not currently supported.');
                break;
            case DbResourceTypes::TYPE_FUNCTION:
                throw new \Exception('Dropping the stored function resource is not currently supported.');
                break;
            default:
                return false;
        }

        return true;
    }

    /**
     * Loads the metadata for the specified table.
     *
     * @param TableSchema $table Any already known info about the table
     */
    protected function loadTable(TableSchema $table)
    {
        $this->loadTableColumns($table);
    }

    /**
     * Loads the metadata for the specified view.
     *
     * @param TableSchema $table Any already known info about the view
     */
    protected function loadView(TableSchema $table)
    {
        $this->loadTableColumns($table);
    }

    /**
     * Finds the column metadata from the database for the specified table.
     *
     * @param TableSchema $table Any already known info about the table
     */
    protected function loadTableColumns(
        /** @noinspection PhpUnusedParameterInspection */
        TableSchema $table
    ) {
    }

    /**
     * Returns all table constraints in the database for the specified schemas.
     * This method should be overridden by child classes in order to support this feature
     * because the default implementation simply returns empty array.
     *
     * @param string $schema the schema of the tables. Defaults to empty string, meaning all schemas.
     * @return array All table constraints in the database
     */
    protected function getTableConstraints($schema = '')
    {
        return [];
    }

    /**
     * Returns all table names in the database.
     * This method should be overridden by child classes in order to support this feature
     * because the default implementation simply throws an exception.
     *
     * @param string $schema the schema of the tables. Defaults to empty string, meaning the current or default schema.
     *                       If not empty, the returned table names will be prefixed with the schema name.
     *
     * @return TableSchema[] all table names in the database.
     * @throws \Exception
     */
    protected function getTableNames(
        /** @noinspection PhpUnusedParameterInspection */
        $schema = ''
    ) {
        throw new NotImplementedException("Database or driver does not support fetching all table names.");
    }

    /**
     * Returns all view names in the database.
     * This method should be overridden by child classes in order to support this feature
     * because the default implementation simply throws an exception.
     *
     * @param string $schema the schema of the views. Defaults to empty string, meaning the current or default schema.
     *                       If not empty, the returned view names will be prefixed with the schema name.
     *
     * @return TableSchema[] all view names in the database.
     * @throws \Exception
     */
    protected function getViewNames(
        /** @noinspection PhpUnusedParameterInspection */
        $schema = ''
    ) {
        throw new NotImplementedException("Database or driver does not support fetching all view names.");
    }

    /**
     * Returns all stored procedure names in the database.
     * This method should be overridden by child classes in order to support this feature
     * because the default implementation simply throws an exception.
     *
     * @param string $schema the schema of the procedures. Defaults to empty string, meaning the current or default
     *                       schema. If not empty, the returned procedure names will be prefixed with the schema name.
     *
     * @return array all procedure names in the database.
     */
    public function getProcedureNames($schema = '')
    {
        return $this->getRoutineNames('PROCEDURE', $schema);
    }

    /**
     * Returns all stored functions names in the database.
     * This method should be overridden by child classes in order to support this feature
     * because the default implementation simply throws an exception.
     *
     * @param string $schema the schema of the functions. Defaults to empty string, meaning the current or default
     *                       schema. If not empty, the returned functions names will be prefixed with the schema name.
     *
     * @return array all stored functions names in the database.
     */
    public function getFunctionNames($schema = '')
    {
        return $this->getRoutineNames('FUNCTION', $schema);
//        throw new NotImplementedException("Database or driver does not support fetching all stored function names.");
    }

    /**
     * Returns all routines in the database.
     *
     * @param string $type   "procedure" or "function"
     * @param string $schema the schema of the routine. Defaults to empty string, meaning the current or
     *                       default schema. If not empty, the returned stored function names will be prefixed with the
     *                       schema name.
     *
     * @throws \InvalidArgumentException
     * @return array all stored function names in the database.
     */
    protected function getRoutineNames($type, $schema = '')
    {
        return [];
    }

    /**
     * Loads the metadata for the specified stored procedure.
     *
     * @param ProcedureSchema $procedure procedure
     *
     * @throws \Exception
     */
    protected function loadProcedure(ProcedureSchema $procedure)
    {
        $this->loadParameters($procedure);
    }

    /**
     * Loads the metadata for the specified stored function.
     *
     * @param FunctionSchema $function
     */
    protected function loadFunction(FunctionSchema $function)
    {
        $this->loadParameters($function);
    }

    /**
     * Loads the parameter metadata for the specified stored procedure or function.
     *
     * @param RoutineSchema $holder
     */
    protected function loadParameters(RoutineSchema $holder)
    {
    }

    /**
     * Quotes a table name for use in a query.
     * If the table name contains schema prefix, the prefix will also be properly quoted.
     *
     * @param string $name table name
     *
     * @return string the properly quoted table name
     * @see quoteSimpleTableName
     */
    public function quoteTableName($name)
    {
        if (strpos($name, '.') === false) {
            return $this->quoteSimpleTableName($name);
        }
        $parts = explode('.', $name);
        foreach ($parts as $i => $part) {
            $parts[$i] = $this->quoteSimpleTableName($part);
        }

        return implode('.', $parts);
    }

    /**
     * Quotes a simple table name for use in a query.
     * A simple table name does not schema prefix.
     *
     * @param string $name table name
     *
     * @return string the properly quoted table name
     */
    public function quoteSimpleTableName($name)
    {
        return static::LEFT_QUOTE_CHARACTER . $name . static::RIGHT_QUOTE_CHARACTER;
    }

    /**
     * Quotes a column name for use in a query.
     * If the column name contains prefix, the prefix will also be properly quoted.
     *
     * @param string $name column name
     *
     * @return string the properly quoted column name
     * @see quoteSimpleColumnName
     */
    public function quoteColumnName($name)
    {
        if (($pos = strrpos($name, '.')) !== false) {
            $prefix = $this->quoteTableName(substr($name, 0, $pos)) . '.';
            $name = substr($name, $pos + 1);
        } else {
            $prefix = '';
        }

        if ('*' !== $name) {
            $name = $this->quoteSimpleColumnName($name);
        }

        return $prefix . $name;
    }

    /**
     * Quotes a simple column name for use in a query.
     * A simple column name does not contain prefix.
     *
     * @param string $name column name
     *
     * @return string the properly quoted column name
     */
    public function quoteSimpleColumnName($name)
    {
        return static::LEFT_QUOTE_CHARACTER . $name . static::RIGHT_QUOTE_CHARACTER;
    }

    /**
     * Compares two table names.
     * The table names can be either quoted or unquoted. This method
     * will consider both cases.
     *
     * @param string $name1 table name 1
     * @param string $name2 table name 2
     *
     * @return boolean whether the two table names refer to the same table.
     */
    public function compareTableNames($name1, $name2)
    {
        $name1 = str_replace(['"', '`', "'"], '', $name1);
        $name2 = str_replace(['"', '`', "'"], '', $name2);
        if (($pos = strrpos($name1, '.')) !== false) {
            $name1 = substr($name1, $pos + 1);
        }
        if (($pos = strrpos($name2, '.')) !== false) {
            $name2 = substr($name2, $pos + 1);
        }
        if ($this->connection->getTablePrefix() !== null) {
            if (strpos($name1, '{') !== false) {
                $name1 = $this->connection->getTablePrefix() . str_replace(['{', '}'], '', $name1);
            }
            if (strpos($name2, '{') !== false) {
                $name2 = $this->connection->getTablePrefix() . str_replace(['{', '}'], '', $name2);
            }
        }

        return $name1 === $name2;
    }

    /**
     * Resets the sequence value of a table's primary key.
     * The sequence will be reset such that the primary key of the next new row inserted
     * will have the specified value or max value of a primary key plus one (i.e. sequence trimming).
     *
     * @param TableSchema  $table   the table schema whose primary key sequence will be reset
     * @param integer|null $value   the value for the primary key of the next new row inserted.
     *                              If this is not set, the next new row's primary key will have the max value of a
     *                              primary key plus one (i.e. sequence trimming).
     */
    public function resetSequence($table, $value = null)
    {
    }

    public static function isUndiscoverableType($type)
    {
        switch ($type) {
            // keep our type extensions
            case DbSimpleTypes::TYPE_USER_ID:
            case DbSimpleTypes::TYPE_USER_ID_ON_CREATE:
            case DbSimpleTypes::TYPE_USER_ID_ON_UPDATE:
            case DbSimpleTypes::TYPE_TIMESTAMP_ON_CREATE:
            case DbSimpleTypes::TYPE_TIMESTAMP_ON_UPDATE:
                return true;
        }

        return false;
    }

    /**
     * @param array $info
     */
    protected function translateSimpleColumnTypes(array &$info)
    {
    }

    /**
     * @param array $info
     */
    protected function validateColumnSettings(array &$info)
    {
    }

    /**
     * @param array $info
     *
     * @return string
     * @throws \Exception
     */
    protected function buildColumnDefinition(array $info)
    {
        // This works for most except Oracle
        $type = (isset($info['type'])) ? $info['type'] : null;
        $typeExtras = (isset($info['type_extras'])) ? $info['type_extras'] : null;

        $definition = $type . $typeExtras;

        $allowNull = (isset($info['allow_null'])) ? $info['allow_null'] : null;
        $definition .= ($allowNull) ? ' NULL' : ' NOT NULL';

        $default = (isset($info['db_type'])) ? $info['db_type'] : null;
        if (isset($default)) {
            if (is_array($default)) {
                $expression = (isset($default['expression'])) ? $default['expression'] : null;
                if (null !== $expression) {
                    $definition .= ' DEFAULT ' . $expression;
                }
            } else {
                $default = $this->quoteValue($default);
                $definition .= ' DEFAULT ' . $default;
            }
        }

        if (isset($info['is_primary_key']) && filter_var($info['is_primary_key'], FILTER_VALIDATE_BOOLEAN)) {
            $definition .= ' PRIMARY KEY';
        } elseif (isset($info['is_unique']) && filter_var($info['is_unique'], FILTER_VALIDATE_BOOLEAN)) {
            $definition .= ' UNIQUE KEY';
        }

        return $definition;
    }

    /**
     * Converts an abstract column type into a physical column type.
     * The conversion is done using the type map specified in {@link columnTypes}.
     * These abstract column types are supported (using MySQL as example to explain the corresponding
     * physical types):
     * <ul>
     * <li>pk: an auto-incremental primary key type, will be converted into "int(11) NOT NULL AUTO_INCREMENT PRIMARY
     * KEY"</li>
     * <li>string: string type, will be converted into "varchar(255)"</li>
     * <li>text: a long string type, will be converted into "text"</li>
     * <li>integer: integer type, will be converted into "int(11)"</li>
     * <li>boolean: boolean type, will be converted into "tinyint(1)"</li>
     * <li>float: float number type, will be converted into "float"</li>
     * <li>decimal: decimal number type, will be converted into "decimal"</li>
     * <li>datetime: datetime type, will be converted into "datetime"</li>
     * <li>timestamp: timestamp type, will be converted into "timestamp"</li>
     * <li>time: time type, will be converted into "time"</li>
     * <li>date: date type, will be converted into "date"</li>
     * <li>binary: binary data type, will be converted into "blob"</li>
     * </ul>
     *
     * If the abstract type contains two or more parts separated by spaces or '(' (e.g. "string NOT NULL" or
     * "decimal(10,2)"), then only the first part will be converted, and the rest of the parts will be appended to the
     * conversion result. For example, 'string NOT NULL' is converted to 'varchar(255) NOT NULL'.
     *
     * @param string $info abstract column type
     *
     * @return string physical column type including arguments, null designation and defaults.
     * @throws \Exception
     */
    protected function getColumnType($info)
    {
        $out = [];
        $type = '';
        if (is_string($info)) {
            $type = trim($info); // cleanup
        } elseif (is_array($info)) {
            $sql = (isset($info['sql'])) ? $info['sql'] : null;
            if (!empty($sql)) {
                return $sql; // raw SQL statement given, pass it on.
            }

            $out = $info;
            $type = (isset($info['type'])) ? $info['type'] : null;
            if (empty($type)) {
                $type = (isset($info['db_type'])) ? $info['db_type'] : null;
                if (empty($type)) {
                    throw new \Exception("Invalid schema detected - no type or db_type element.");
                }
            }
            $type = trim($type); // cleanup
        }

        if (empty($type)) {
            throw new \Exception("Invalid schema detected - no type definition.");
        }

        //  If there are extras, then pass it on through
        if ((false !== strpos($type, ' ')) || (false !== strpos($type, '('))) {
            return $type;
        }

        $out['type'] = $type;
        $this->translateSimpleColumnTypes($out);
        $this->validateColumnSettings($out);

        return $this->buildColumnDefinition($out);
    }

    /**
     * Builds a SQL statement for renaming a DB table.
     *
     * @param string $table   the table to be renamed. The name will be properly quoted by the method.
     * @param string $newName the new table name. The name will be properly quoted by the method.
     *
     * @return string the SQL statement for renaming a DB table.
     */
    public function renameTable($table, $newName)
    {
        return 'RENAME TABLE ' . $this->quoteTableName($table) . ' TO ' . $this->quoteTableName($newName);
    }

    /**
     * Builds a SQL statement for truncating a DB table.
     *
     * @param string $table the table to be truncated. The name will be properly quoted by the method.
     *
     * @return string the SQL statement for truncating a DB table.
     */
    public function truncateTable($table)
    {
        return "TRUNCATE TABLE " . $this->quoteTableName($table);
    }

    /**
     * Builds a SQL statement for adding a new DB column.
     *
     * @param string $table  The quoted table that the new column will be added to.
     * @param string $column The name of the new column. The name will be properly quoted by the method.
     * @param string $type   The column type. The {@link getColumnType} method will be invoked to convert abstract
     *                       column type (if any) into the physical one. Anything that is not recognized as abstract
     *                       type will be kept in the generated SQL. For example, 'string' will be turned into
     *                       'varchar(255)', while 'string not null' will become 'varchar(255) not null'.
     *
     * @return string the SQL statement for adding a new column.
     */
    public function addColumn($table, $column, $type)
    {
        return <<<MYSQL
ALTER TABLE $table ADD COLUMN {$this->quoteColumnName($column)} {$this->getColumnType($type)};
MYSQL;
    }

    /**
     * Builds a SQL statement for renaming a column.
     *
     * @param string $table   the table whose column is to be renamed. The name will be properly quoted by the method.
     * @param string $name    the old name of the column. The name will be properly quoted by the method.
     * @param string $newName the new name of the column. The name will be properly quoted by the method.
     *
     * @return string the SQL statement for renaming a DB column.
     */
    public function renameColumn($table, $name, $newName)
    {
        return <<<MYSQL
ALTER TABLE $table RENAME COLUMN {$this->quoteColumnName($name)} TO {$this->quoteColumnName($newName)};
MYSQL;
    }

    /**
     * Builds a SQL statement for changing the definition of a column.
     *
     * @param string $table      the table whose column is to be changed. The table name will be properly quoted by the
     *                           method.
     * @param string $column     the name of the column to be changed. The name will be properly quoted by the method.
     * @param string $definition the new column type. The {@link getColumnType} method will be invoked to convert
     *                           abstract column type (if any) into the physical one. Anything that is not recognized
     *                           as abstract type will be kept in the generated SQL. For example, 'string' will be
     *                           turned into 'varchar(255)', while 'string not null' will become 'varchar(255) not
     *                           null'.
     *
     * @return string the SQL statement for changing the definition of a column.
     */
    public function alterColumn($table, $column, $definition)
    {
        return <<<MYSQL
ALTER TABLE $table CHANGE {$this->quoteColumnName($column)} {$this->quoteColumnName($column)} {$this->getColumnType($definition)};
MYSQL;
    }

    /**
     * @param string      $prefix
     * @param string      $table
     * @param string|null $column
     *
     * @return string
     */
    public function makeConstraintName($prefix, $table, $column = null)
    {
        $temp = $prefix . '_' . str_replace('.', '_', $table);
        if (!empty($column)) {
            $temp .= '_' . $column;
        }

        return $temp;
    }

    /**
     * Builds a SQL statement for adding a foreign key constraint to an existing table.
     * The method will properly quote the table and column names.
     *
     * @param string $name       the name of the foreign key constraint.
     * @param string $table      the table that the foreign key constraint will be added to.
     * @param string $columns    the name of the column to that the constraint will be added on. If there are multiple
     *                           columns, separate them with commas.
     * @param string $refTable   the table that the foreign key references to.
     * @param string $refColumns the name of the column that the foreign key references to. If there are multiple
     *                           columns, separate them with commas.
     * @param string $delete     the ON DELETE option. Most DBMS support these options: RESTRICT, CASCADE, NO ACTION,
     *                           SET DEFAULT, SET NULL
     * @param string $update     the ON UPDATE option. Most DBMS support these options: RESTRICT, CASCADE, NO ACTION,
     *                           SET DEFAULT, SET NULL
     *
     * @return string the SQL statement for adding a foreign key constraint to an existing table.
     */
    public function addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete = null, $update = null)
    {
        $columns = preg_split('/\s*,\s*/', $columns, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($columns as $i => $col) {
            $columns[$i] = $this->quoteColumnName($col);
        }
        $refColumns = preg_split('/\s*,\s*/', $refColumns, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($refColumns as $i => $col) {
            $refColumns[$i] = $this->quoteColumnName($col);
        }
        $sql =
            'ALTER TABLE ' .
            $this->quoteTableName($table) .
            ' ADD CONSTRAINT ' .
            $this->quoteColumnName($name) .
            ' FOREIGN KEY (' .
            implode(', ', $columns) .
            ')' .
            ' REFERENCES ' .
            $this->quoteTableName($refTable) .
            ' (' .
            implode(', ', $refColumns) .
            ')';
        if ($delete !== null) {
            $sql .= ' ON DELETE ' . $delete;
        }
        if ($update !== null) {
            $sql .= ' ON UPDATE ' . $update;
        }

        return $sql;
    }

    /**
     * Builds a SQL statement for dropping a foreign key constraint.
     *
     * @param string $name  the name of the foreign key constraint to be dropped. The name will be properly quoted by
     *                      the method.
     * @param string $table the table whose foreign is to be dropped. The name will be properly quoted by the method.
     *
     * @return string the SQL statement for dropping a foreign key constraint.
     */
    public function dropForeignKey($name, $table)
    {
        return 'ALTER TABLE ' . $this->quoteTableName($table) . ' DROP CONSTRAINT ' . $this->quoteColumnName($name);
    }

    /**
     * @param bool $unique
     * @param bool $on_create_table
     *
     * @return bool
     */
    public function requiresCreateIndex($unique = false, $on_create_table = false)
    {
        return true;
    }

    /**
     * @return bool
     */
    public function allowsSeparateForeignConstraint()
    {
        return true;
    }

    /**
     * Builds a SQL statement for creating a new index.
     *
     * @param string  $name   the name of the index. The name will be properly quoted by the method.
     * @param string  $table  the table that the new index will be created for. The table name will be properly quoted
     *                        by the method.
     * @param string  $column the column(s) that should be included in the index. If there are multiple columns, please
     *                        separate them by commas. Each column name will be properly quoted by the method, unless a
     *                        parenthesis is found in the name.
     * @param boolean $unique whether to add UNIQUE constraint on the created index.
     *
     * @return string the SQL statement for creating a new index.
     */
    public function createIndex($name, $table, $column, $unique = false)
    {
        $cols = [];
        $columns = preg_split('/\s*,\s*/', $column, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($columns as $col) {
            if (strpos($col, '(') !== false) {
                $cols[] = $col;
            } else {
                $cols[] = $this->quoteColumnName($col);
            }
        }

        return
            ($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ') .
            $this->quoteTableName($name) .
            ' ON ' .
            $this->quoteTableName($table) .
            ' (' .
            implode(', ', $cols) .
            ')';
    }

    /**
     * Builds a SQL statement for dropping an index.
     *
     * @param string $name  the name of the index to be dropped. The name will be properly quoted by the method.
     * @param string $table the table whose index is to be dropped. The name will be properly quoted by the method.
     *
     * @return string the SQL statement for dropping an index.
     */
    public function dropIndex($name, $table)
    {
        return 'DROP INDEX ' . $this->quoteTableName($name) . ' ON ' . $this->quoteTableName($table);
    }

    /**
     * Builds a SQL statement for adding a primary key constraint to an existing table.
     *
     * @param string       $name    the name of the primary key constraint.
     * @param string       $table   the table that the primary key constraint will be added to.
     * @param string|array $columns comma separated string or array of columns that the primary key will consist of.
     *                              Array value can be passed.
     *
     * @return string the SQL statement for adding a primary key constraint to an existing table.
     */
    public function addPrimaryKey($name, $table, $columns)
    {
        if (is_string($columns)) {
            $columns = preg_split('/\s*,\s*/', $columns, -1, PREG_SPLIT_NO_EMPTY);
        }
        foreach ($columns as $i => $col) {
            $columns[$i] = $this->quoteColumnName($col);
        }

        return
            'ALTER TABLE ' .
            $this->quoteTableName($table) .
            ' ADD CONSTRAINT ' .
            $this->quoteColumnName($name) .
            '  PRIMARY KEY (' .
            implode(', ', $columns) .
            ' )';
    }

    /**
     * Builds a SQL statement for removing a primary key constraint to an existing table.
     *
     * @param string $name  the name of the primary key constraint to be removed.
     * @param string $table the table that the primary key constraint will be removed from.
     *
     * @return string the SQL statement for removing a primary key constraint from an existing table.
     */
    public function dropPrimaryKey($name, $table)
    {
        return 'ALTER TABLE ' . $this->quoteTableName($table) . ' DROP CONSTRAINT ' . $this->quoteColumnName($name);
    }

    /**
     * @param $table
     * @param $column
     *
     * @return array
     */
    public function getPrimaryKeyCommands($table, $column)
    {
        return [];
    }

    /**
     * @return mixed
     */
    public function getTimestampForSet()
    {
        return $this->connection->raw('(NOW())');
    }

    /**
     * Builds a SQL statement for creating a new DB view of an existing table.
     *
     *
     * @param string $table   the name of the view to be created. The name will be properly quoted by the method.
     * @param array  $columns optional mapping to the columns in the select of the new view.
     * @param string $select  SQL statement defining the view.
     * @param string $options additional SQL fragment that will be appended to the generated SQL.
     *
     * @return string the SQL statement for creating a new DB table.
     */
    public function createView($table, $columns, $select, $options = null)
    {
        $sql = "CREATE VIEW " . $this->quoteTableName($table);
        if (!empty($columns)) {
            if (is_array($columns)) {
                foreach ($columns as &$name) {
                    $name = $this->quoteColumnName($name);
                }
                $columns = implode(',', $columns);
            }
            $sql .= " ($columns)";
        }
        $sql .= " AS " . $select;

        return $sql;
    }

    /**
     * Builds a SQL statement for dropping a DB view.
     *
     * @param string $table the view to be dropped. The name will be properly quoted by the method.
     *
     * @return string the SQL statement for dropping a DB view.
     */
    public function dropView($table)
    {
        return "DROP VIEW " . $this->quoteTableName($table);
    }

    /**
     * Builds and executes a SQL statement for creating a new DB table.
     *
     * The columns in the new table should be specified as name-definition pairs (e.g. 'name'=>'string'),
     * where name stands for a column name which will be properly quoted by the method, and definition
     * stands for the column type which can contain an abstract DB type.
     * The {@link getColumnType} method will be invoked to convert any abstract type into a physical one.
     *
     * If a column is specified with definition only (e.g. 'PRIMARY KEY (name, type)'), it will be directly
     * inserted into the generated SQL.
     *
     * @param array $table   the whole schema of the table to be created. The name will be properly quoted by the
     *                       method.
     * @param array $options the options for the new table, including columns.
     *
     * @return int 0 is always returned. See <a
     *             href='http://php.net/manual/en/pdostatement.rowcount.php'>http://php.net/manual/en/pdostatement.rowcount.php</a>
     *             for more for more information.
     * @throws \Exception
     */
    public function createTable($table, $options)
    {
        if (empty($tableName = array_get($table, 'name'))) {
            throw new \Exception("No valid name exist in the received table schema.");
        }

        if (empty($columns = array_get($options, 'columns'))) {
            throw new \Exception("No valid fields exist in the received table schema.");
        }

        $cols = [];
        foreach ($columns as $name => $type) {
            if (is_string($name)) {
                $cols[] = "\t" . $this->quoteColumnName($name) . ' ' . $this->getColumnType($type);
            } else {
                $cols[] = "\t" . $type;
            }
        }

        $sql = "CREATE TABLE {$this->quoteTableName($tableName)} (\n" . implode(",\n", $cols) . "\n)";

        // string additional SQL fragment that will be appended to the generated SQL
        if (!empty($addOn = array_get($table, 'options'))) {
            $sql .= ' ' . $addOn;
        }

        return $this->connection->statement($sql);
    }

    /**
     * @param TableSchema $tableSchema
     * @param array       $changes
     *
     * @throws \Exception
     */
    public function updateTable($tableSchema, $changes)
    {
        //  Is there a name update
        if (!empty($changes['new_name'])) {
            // todo change table name, has issue with references
        }

        // update column types
        if (isset($changes['columns']) && is_array($changes['columns'])) {
            foreach ($changes['columns'] as $name => $definition) {
                $this->connection->statement($this->addColumn($tableSchema->quotedName, $name, $definition));
            }
        }
        if (isset($changes['alter_columns']) && is_array($changes['alter_columns'])) {
            foreach ($changes['alter_columns'] as $name => $definition) {
                $this->connection->statement($this->alterColumn($tableSchema->quotedName, $name, $definition));
            }
        }
        if (isset($changes['drop_columns']) && is_array($changes['drop_columns']) && !empty($changes['drop_columns'])) {
            $this->connection->statement($this->dropColumns($tableSchema->quotedName, $changes['drop_columns']));
        }
    }

    /**
     * Builds and executes a SQL statement for dropping a DB table.
     *
     * @param string $table The internal table name to be dropped.
     *
     * @return integer 0 is always returned. See {@link http://php.net/manual/en/pdostatement.rowcount.php} for more
     *                 information.
     */
    public function dropTable($table)
    {
        return $this->connection->statement("DROP TABLE $table");
    }

    /**
     * @param string       $table
     * @param string|array $columns
     *
     * @return bool|int
     */
    public function dropColumns($table, $columns)
    {
        $commands = [];
        foreach ((array)$columns as $column) {
            if (!empty($column)) {
                $commands[] = "DROP COLUMN " . $column;
            }
        }

        if (!empty($commands)) {
            return $this->connection->statement("ALTER TABLE $table " . implode(',', $commands));
        }

        return false;
    }

    /**
     * @param string $table
     * @param        $relationship
     *
     * @return bool|int
     */
    public function dropRelationship($table, $relationship)
    {
        // todo anything we can do for database foreign keys here?
        return false;
    }

    /**
     * @param array $references
     *
     */
    public function createFieldReferences($references)
    {
        if (!empty($references)) {
            foreach ($references as $reference) {
                $name = $reference['name'];
                $table = $reference['table'];
                $drop = (isset($reference['drop'])) ? boolval($reference['drop']) : false;
                if ($drop) {
                    try {
                        $this->connection->statement($this->dropForeignKey($name, $table));
                    } catch (\Exception $ex) {
                        \Log::debug($ex->getMessage());
                    }
                }
                // add new reference
                $refTable = (isset($reference['ref_table'])) ? $reference['ref_table'] : null;
                if (!empty($refTable)) {
                    $this->connection->statement($this->addForeignKey(
                        $name,
                        $table,
                        $reference['column'],
                        $refTable,
                        $reference['ref_field'],
                        $reference['delete'],
                        $reference['update']
                    ));
                }
            }
        }
    }

    /**
     * @param array $indexes
     *
     */
    public function createFieldIndexes($indexes)
    {
        if (!empty($indexes)) {
            foreach ($indexes as $index) {
                $name = $index['name'];
                $table = $index['table'];
                $drop = (isset($index['drop'])) ? boolval($index['drop']) : false;
                if ($drop) {
                    try {
                        $this->connection->statement($this->dropIndex($name, $table));
                    } catch (\Exception $ex) {
                        \Log::debug($ex->getMessage());
                    }
                }
                $unique = (isset($index['unique'])) ? boolval($index['unique']) : false;

                $this->connection->statement($this->createIndex($name, $table, $index['column'], $unique));
            }
        }
    }

    /**
     * @param FunctionSchema $function
     * @param array          $in_params
     *
     * @throws \Exception
     * @return mixed
     */
    public function callFunction($function, array $in_params)
    {
        if (!$this->supportsResourceType(DbResourceTypes::TYPE_FUNCTION)) {
            throw new \Exception('Stored Functions are not supported by this database connection.');
        }

        $paramSchemas = $function->getParameters();
        $values = $this->determineRoutineValues($paramSchemas, $in_params);

        $sql = $this->getFunctionStatement($function, $paramSchemas, $values);
        /** @type \PDOStatement $statement */
        if (!$statement = $this->connection->getPdo()->prepare($sql)) {
            throw new InternalServerErrorException('Failed to prepare statement: ' . $sql);
        }

        // do binding
        $this->doRoutineBinding($statement, $paramSchemas, $values);

        // support multiple result sets
        $result = [];
        try {
            $statement->execute();
            $reader = new DataReader($statement);
            $reader->setFetchMode(static::ROUTINE_FETCH_MODE);
            do {
                $temp = $reader->readAll();
                if (!empty($temp)) {
                    $result[] = $temp;
                }
            } while ($reader->nextResult());
        } catch (\Exception $ex) {
            if (!$this->handleRoutineException($ex)) {
                $errorInfo = $ex instanceof \PDOException ? $ex : null;
                $message = $ex->getMessage();
                throw new \Exception($message, (int)$ex->getCode(), $errorInfo);
            }
        }

        // if there is only one data set, just return it
        if (1 == count($result)) {
            $result = $result[0];
            // if there is only one data set, search for an output
            if (1 == count($result)) {
                $result = current($result);
                if (array_key_exists('output', $result)) {
                    $value = $result['output'];

                    return $this->typecastToClient($value, $function->returnType);
                } elseif (array_key_exists($function->name, $result)) {
                    // some vendors return the results as the function's name
                    $value = $result[$function->name];

                    return $this->typecastToClient($value, $function->returnType);
                }
            }
        }

        return $result;
    }

    /**
     * @param array $param_schemas
     * @param array $values
     *
     * @return string
     */
    protected function getRoutineParamString(array $param_schemas, array &$values)
    {
        $paramStr = '';
        foreach ($param_schemas as $key => $paramSchema) {
            switch ($paramSchema->paramType) {
                case 'IN':
                case 'INOUT':
                case 'OUT':
                    $pName = ':' . $paramSchema->name;
                    $paramStr .= (empty($paramStr)) ? $pName : ", $pName";
                    break;
                default:
                    break;
            }
        }

        return $paramStr;
    }

    /**
     * @param \DreamFactory\Core\Database\Schema\RoutineSchema $routine
     * @param array                                            $param_schemas
     * @param array                                            $values
     *
     * @return string
     */
    protected function getFunctionStatement(RoutineSchema $routine, array $param_schemas, array &$values)
    {
        $paramStr = $this->getRoutineParamString($param_schemas, $values);

        return "SELECT {$routine->quotedName}($paramStr) AS " . $this->quoteColumnName('output');
    }

    /**
     * @param \Exception $ex
     *
     * @return bool
     */
    protected function handleRoutineException(
        /** @noinspection PhpUnusedParameterInspection */
        \Exception $ex
    ) {
        return false;
    }

    /**
     * @param ProcedureSchema $procedure
     * @param array           $in_params
     * @param array           $out_params
     *
     * @throws \Exception
     * @return mixed
     */
    public function callProcedure($procedure, array $in_params, array &$out_params)
    {
        if (!$this->supportsResourceType(DbResourceTypes::TYPE_PROCEDURE)) {
            throw new BadRequestException('Stored Procedures are not supported by this database connection.');
        }

        $paramSchemas = $procedure->getParameters();
        $values = $this->determineRoutineValues($paramSchemas, $in_params);

        $sql = $this->getProcedureStatement($procedure, $paramSchemas, $values);

        /** @type \PDOStatement $statement */
        if (!$statement = $this->connection->getPdo()->prepare($sql)) {
            throw new InternalServerErrorException('Failed to prepare statement: ' . $sql);
        }

        // do binding
        $this->doRoutineBinding($statement, $paramSchemas, $values);

        // support multiple result sets
        $result = [];
        try {
            $statement->execute();
            $reader = new DataReader($statement);
            $reader->setFetchMode(static::ROUTINE_FETCH_MODE);
            do {
                try {
                    if (0 < $reader->getColumnCount()) {
                        $temp = $reader->readAll();
                    }
                } catch (\Exception $ex) {
                    // latest oracle driver seems to kick this back for all OUT params even though it works, ignore for now
                    if (false === stripos($ex->getMessage(),
                            'ORA-24374: define not done before fetch or execute and fetch')
                    ) {
                        throw $ex;
                    }
                }
                if (!empty($temp)) {
                    $keep = true;
                    if (1 == count($temp)) {
                        $check = array_change_key_case(current($temp), CASE_LOWER);
                        foreach ($paramSchemas as $key => $paramSchema) {
                            switch ($paramSchema->paramType) {
                                case 'OUT':
                                case 'INOUT':
                                    if (array_key_exists($key, $check)) {
                                        $values[$paramSchema->name] = $check[$key];
                                        // todo problem here if the result contains field name = param name!
                                        $keep = false;
                                    }
                                    break;
                            }
                        }
                    }
                    if ($keep) {
                        $result[] = $temp;
                    }
                }
            } while ($reader->nextResult());
        } catch (\Exception $ex) {
            if (!$this->handleRoutineException($ex)) {
                $errorInfo = $ex instanceof \PDOException ? $ex : null;
                $message = $ex->getMessage();
                throw new \Exception($message, (int)$ex->getCode(), $errorInfo);
            }
        }

        // if there is only one data set, just return it
        if (1 == count($result)) {
            $result = $result[0];
        }

        // any post op?
        $this->postProcedureCall($paramSchemas, $values);

        $values = array_change_key_case($values, CASE_LOWER);
        foreach ($paramSchemas as $key => $paramSchema) {
            switch ($paramSchema->paramType) {
                case 'OUT':
                case 'INOUT':
                    if (array_key_exists($key, $values)) {
                        $value = $values[$key];
                        $out_params[$paramSchema->name] = $this->typecastToClient($value, $paramSchema);
                    }
                    break;
            }
        }

        return $result;
    }

    protected static function cleanParameters(array $param_schemas, array $in_params)
    {
        $out = [];
        foreach ($in_params as $key => $value) {
            if (is_string($key)) {
                // $key is name, check if we have array with value
                if (is_array($value)) {
                    $value = array_get(array_change_key_case($value, CASE_LOWER), 'value');
                }
                $out[strtolower($key)] = $value;
            } else {
                if (is_array($value)) {
                    $param = array_change_key_case($value, CASE_LOWER);
                    if (array_key_exists('name', $param)) {
                        $out[strtolower($param['name'])] = array_get($param, 'value');
                    }
                } else {
                    if ($name = array_get(array_keys($param_schemas), $key)) {
                        $out[$name] = $value;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param array $param_schemas
     * @param array $in_params
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     */
    protected function determineRoutineValues(array $param_schemas, array $in_params)
    {
        $in_params = static::cleanParameters($param_schemas, $in_params);
        $values = [];
        $index = -1;
        // key is lowercase index
        foreach ($param_schemas as $key => $paramSchema) {
            $index++;
            switch ($paramSchema->paramType) {
                case 'IN':
                case 'INOUT':
                    if (array_key_exists($key, $in_params)) {
                        $value = $in_params[$key];
                    } else {
                        $value = $paramSchema->defaultValue;
                    }
                    $values[$key] = $this->typecastToClient($value, $paramSchema);
                    break;
                case 'OUT':
                    $values[$key] = null;
                    break;
                default:
                    break;
            }
        }

        return $values;
    }

    /**
     * @param       $statement
     * @param array $paramSchemas
     * @param array $values
     */
    protected function doRoutineBinding($statement, array $paramSchemas, array &$values)
    {
        // do binding
        foreach ($paramSchemas as $key => $paramSchema) {
            switch ($paramSchema->paramType) {
                case 'IN':
                    $this->bindValue($statement, ':' . $paramSchema->name, array_get($values, $key));
                    break;
                case 'INOUT':
                case 'OUT':
                    $pdoType = $this->extractPdoType($paramSchema->type);
//                    $values[$key] = $this->formatValue($values[$key], $paramSchema->type);
                    $this->bindParam(
                        $statement, ':' . $paramSchema->name,
                        $values[$key],
                        $pdoType | \PDO::PARAM_INPUT_OUTPUT,
                        $paramSchema->length
                    );
                    break;
            }
        }
    }

    /**
     * @param RoutineSchema $routine
     * @param array         $param_schemas
     * @param array         $values
     *
     * @return string
     */
    protected function getProcedureStatement(RoutineSchema $routine, array $param_schemas, array &$values)
    {
        $paramStr = $this->getRoutineParamString($param_schemas, $values);

        return "CALL {$routine->quotedName}($paramStr)";
    }

    /**
     * @param array $param_schemas
     * @param array $values
     */
    protected function postProcedureCall(array $param_schemas, array &$values)
    {
    }

    /**
     * @param \PDOStatement $statement
     * @param               $name
     * @param               $value
     * @param null          $dataType
     * @param null          $length
     * @param null          $driverOptions
     */
    public function bindParam($statement, $name, &$value, $dataType = null, $length = null, $driverOptions = null)
    {
        if ($dataType === null) {
            $statement->bindParam($name, $value, $this->getPdoType(gettype($value)));
        } elseif ($length === null) {
            $statement->bindParam($name, $value, $dataType);
        } elseif ($driverOptions === null) {
            $statement->bindParam($name, $value, $dataType, $length);
        } else {
            $statement->bindParam($name, $value, $dataType, $length, $driverOptions);
        }
    }

    /**
     * Binds a value to a parameter.
     *
     * @param \PDOStatement $statement
     * @param mixed         $name     Parameter identifier. For a prepared statement
     *                                using named placeholders, this will be a parameter name of
     *                                the form :name. For a prepared statement using question mark
     *                                placeholders, this will be the 1-indexed position of the parameter.
     * @param mixed         $value    The value to bind to the parameter
     * @param integer       $dataType SQL data type of the parameter. If null, the type is determined by the PHP type
     *                                of the value.
     *
     * @see http://www.php.net/manual/en/function.PDOStatement-bindValue.php
     */
    public function bindValue($statement, $name, $value, $dataType = null)
    {
        if ($dataType === null) {
            $statement->bindValue($name, $value, $this->getPdoType(gettype($value)));
        } else {
            $statement->bindValue($name, $value, $dataType);
        }
    }

    /**
     * Binds a list of values to the corresponding parameters.
     * This is similar to {@link bindValue} except that it binds multiple values.
     * Note that the SQL data type of each value is determined by its PHP type.
     *
     * @param \PDOStatement $statement
     * @param array         $values the values to be bound. This must be given in terms of an associative
     *                              array with array keys being the parameter names, and array values the corresponding
     *                              parameter values. For example, <code>array(':name'=>'John', ':age'=>25)</code>.
     */
    public function bindValues($statement, $values)
    {
        foreach ($values as $name => $value) {
            $statement->bindValue($name, $value, $this->getPdoType(gettype($value)));
        }
    }

    /**
     * Extracts the PHP type from DF type.
     *
     * @param string $type DF type
     *
     * @return string
     */
    public static function extractPhpType($type)
    {
        return DbSimpleTypes::toPhpType($type);
    }

    /**
     * Extracts the PHP PDO type from DF type.
     *
     * @param string $type DF type
     *
     * @return int|null
     */
    public static function extractPdoType($type)
    {
        switch ($type) {
            case DbSimpleTypes::TYPE_BINARY:
                return \PDO::PARAM_LOB;
            default:
                switch (static::extractPhpType($type)) {
                    case 'boolean':
                        return \PDO::PARAM_BOOL;
                    case 'integer':
                        return \PDO::PARAM_INT;
                    case 'string':
                        return \PDO::PARAM_STR;
                }
        }

        return null;
    }

    /**
     * Determines the PDO type for the specified PHP type.
     *
     * @param string $type The PHP type (obtained by gettype() call).
     *
     * @return integer the corresponding PDO type
     */
    public function getPdoType($type)
    {
        static $map = [
            'boolean'  => \PDO::PARAM_BOOL,
            'integer'  => \PDO::PARAM_INT,
            'string'   => \PDO::PARAM_STR,
            'resource' => \PDO::PARAM_LOB,
            'NULL'     => \PDO::PARAM_NULL,
        ];

        return isset($map[$type]) ? $map[$type] : \PDO::PARAM_STR;
    }

    /**
     * @param      $type
     * @param null $size
     * @param null $scale
     *
     * @return string
     */
    public function extractSimpleType($type, $size = null, $scale = null)
    {
        switch (strtolower($type)) {
            case 'bit':
            case (false !== strpos($type, 'bool')):
                $value = DbSimpleTypes::TYPE_BOOLEAN;
                break;

            case 'number': // Oracle for boolean, integers and decimals
                if ($size == 1) {
                    $value = DbSimpleTypes::TYPE_BOOLEAN;
                } elseif (empty($scale)) {
                    $value = DbSimpleTypes::TYPE_INTEGER;
                } else {
                    $value = DbSimpleTypes::TYPE_DECIMAL;
                }
                break;

            case 'decimal':
            case 'numeric':
            case 'percent':
                $value = DbSimpleTypes::TYPE_DECIMAL;
                break;

            case (false !== strpos($type, 'double')):
                $value = DbSimpleTypes::TYPE_DOUBLE;
                break;

            case 'real':
            case (false !== strpos($type, 'float')):
                if ($size == 53) {
                    $value = DbSimpleTypes::TYPE_DOUBLE;
                } else {
                    $value = DbSimpleTypes::TYPE_FLOAT;
                }
                break;

            case (false !== strpos($type, 'money')):
                $value = DbSimpleTypes::TYPE_MONEY;
                break;

            case 'binary_integer': // oracle integer
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'integer':
            case 'serial': // Informix
                // watch out for point here!
                if ($size == 1) {
                    $value = DbSimpleTypes::TYPE_BOOLEAN;
                } else {
                    $value = DbSimpleTypes::TYPE_INTEGER;
                }
                break;

            case 'varint': // java type used in cassandra, possibly others, can be really big
            case 'bigint':
            case 'bigserial': // Informix
            case 'serial8': // Informix
                // bigint too big to represent as number in php
                $value = DbSimpleTypes::TYPE_BIG_INT;
                break;

            case (false !== strpos($type, 'timestamp')):
            case 'datetimeoffset': //  MSSQL
                $value = DbSimpleTypes::TYPE_TIMESTAMP;
                break;

            case (false !== strpos($type, 'datetime')):
                $value = DbSimpleTypes::TYPE_DATETIME;
                break;

            case 'date':
                $value = DbSimpleTypes::TYPE_DATE;
                break;

            case 'timeuuid': // type 1 time-based UUID
                $value = DbSimpleTypes::TYPE_TIME_UUID;
                break;

            case (false !== strpos($type, 'time')):
                $value = DbSimpleTypes::TYPE_TIME;
                break;

            case (false !== strpos($type, 'binary')):
            case (false !== strpos($type, 'blob')):
                $value = DbSimpleTypes::TYPE_BINARY;
                break;

            //	String types
            case (false !== strpos($type, 'clob')):
            case (false !== strpos($type, 'text')):
            case 'lvarchar': // informix
                $value = DbSimpleTypes::TYPE_TEXT;
                break;

            case 'varchar':
                if ($size == -1) {
                    $value = DbSimpleTypes::TYPE_TEXT; // varchar(max) in MSSQL
                } else {
                    $value = DbSimpleTypes::TYPE_STRING;
                }
                break;

            case 'uuid':
                $value = DbSimpleTypes::TYPE_UUID;
                break;

            // common routine return types
            case 'ref cursor':
                $value = DbSimpleTypes::TYPE_REF_CURSOR;
                break;

            case 'table':
                $value = DbSimpleTypes::TYPE_TABLE;
                break;

            case 'array':
            case 'map':
            case 'set':
            case 'list':
            case 'tuple':
                $value = DbSimpleTypes::TYPE_ARRAY;
                break;

            case 'column':
                $value = DbSimpleTypes::TYPE_COLUMN;
                break;

            case 'row':
                $value = DbSimpleTypes::TYPE_ROW;
                break;

            case 'string':
            case (false !== strpos($type, 'char')):
            default:
                $value = DbSimpleTypes::TYPE_STRING; // default to string to handle anything
                break;
        }

        return $value;
    }

    /**
     * Extracts the DreamFactory simple type from DB type.
     *
     * @param ColumnSchema $column
     * @param string       $dbType DB type
     */
    public function extractType(ColumnSchema $column, $dbType)
    {
        if (false !== $simpleType = strstr($dbType, '(', true)) {
        } elseif (false !== $simpleType = strstr($dbType, '<', true)) {
        } else {
            $simpleType = $dbType;
        }

        $column->type = static::extractSimpleType($simpleType, $column->size, $column->scale);
    }

    /**
     * @param $dbType
     *
     * @return bool
     */
    public function extractMultiByteSupport($dbType)
    {
        switch ($dbType) {
            case (false !== strpos($dbType, 'national')):
            case (false !== strpos($dbType, 'nchar')):
            case (false !== strpos($dbType, 'nvarchar')):
                return true;
        }

        return false;
    }

    /**
     * @param $dbType
     *
     * @return bool
     */
    public function extractFixedLength($dbType)
    {
        switch ($dbType) {
            case ((false !== strpos($dbType, 'char')) && (false === strpos($dbType, 'var'))):
            case 'binary':
                return true;
        }

        return false;
    }

    /**
     * Extracts size, precision and scale information from column's DB type.
     *
     * @param ColumnSchema $field
     * @param string       $dbType the column's DB type
     */
    public function extractLimit(ColumnSchema $field, $dbType)
    {
        if (strpos($dbType, '(') && preg_match('/\((.*)\)/', $dbType, $matches)) {
            $values = explode(',', $matches[1]);
            $field->size = (int)$values[0];
            if (isset($values[1])) {
                $field->precision = (int)$values[0];
                $field->scale = (int)$values[1];
            }
        }
    }

    /**
     * Extracts the default value for the column.
     * The value is type-casted to correct PHP type.
     *
     * @param ColumnSchema $field
     * @param mixed        $defaultValue the default value obtained from metadata
     */
    public function extractDefault(ColumnSchema $field, $defaultValue)
    {
        $phpType = DbSimpleTypes::toPhpType($field->type);
        $field->defaultValue = $this->formatValueToPhpType($defaultValue, $phpType);
    }

    protected function formatValueToPhpType($value, $type, $allow_null = true)
    {
        if (gettype($value) === $type || (is_null($value) && $allow_null) || $value instanceof Expression) {
            return $value;
        }

        switch (strtolower(strval($type))) {
            case 'int':
            case 'integer':
                return intval($value);
            case 'bool':
            case 'boolean':
                return to_bool($value);
            case 'double':
            case 'float':
                return floatval($value);
            case 'string':
                return strval($value);
        }

        return $value;
    }

    /**
     * @param mixed                               $value
     * @param string|ParameterSchema|ColumnSchema $field_info
     * @param boolean                             $allow_null
     *
     * @return mixed
     */
    public function typecastToClient($value, $field_info, $allow_null = true)
    {
        if (is_null($value) && $allow_null) {
            return null;
        }

        $type = DbSimpleTypes::TYPE_STRING;
        if (is_string($field_info)) {
            $type = $field_info;
        } elseif ($field_info instanceof ColumnSchema) {
            $type = $field_info->type;
        } elseif ($field_info instanceof ParameterSchema) {
            $type = $field_info->type;
        }

        $type = strtolower(strval($type));
        switch ($type) {
            // special handling for datetime types
            case DbSimpleTypes::TYPE_DATE:
            case DbSimpleTypes::TYPE_DATETIME:
            case DbSimpleTypes::TYPE_DATETIME_TZ:
            case DbSimpleTypes::TYPE_TIME:
            case DbSimpleTypes::TYPE_TIME_TZ:
            case DbSimpleTypes::TYPE_TIMESTAMP:
            case DbSimpleTypes::TYPE_TIMESTAMP_TZ:
            case DbSimpleTypes::TYPE_TIMESTAMP_ON_CREATE:
            case DbSimpleTypes::TYPE_TIMESTAMP_ON_UPDATE:
                return static::formatDateTime(
                    static::getConfigDateTimeFormat($type),
                    $value,
                    static::getNativeDateTimeFormat($field_info)
                );
        }

        return $this->formatValueToPhpType($value, $this->extractPhpType($type));
    }

    /**
     * @param $type
     *
     * @return mixed|null
     */
    public static function getConfigDateTimeFormat($type)
    {
        switch (strtolower(strval($type))) {
            case DbSimpleTypes::TYPE_TIME:
            case DbSimpleTypes::TYPE_TIME_TZ:
                return \Config::get('df.db.time_format');

            case DbSimpleTypes::TYPE_DATE:
                return \Config::get('df.db.date_format');

            case DbSimpleTypes::TYPE_DATETIME:
            case DbSimpleTypes::TYPE_DATETIME_TZ:
                return \Config::get('df.db.datetime_format');

            case DbSimpleTypes::TYPE_TIMESTAMP:
            case DbSimpleTypes::TYPE_TIMESTAMP_TZ:
            case DbSimpleTypes::TYPE_TIMESTAMP_ON_CREATE:
            case DbSimpleTypes::TYPE_TIMESTAMP_ON_UPDATE:
                return \Config::get('df.db.timestamp_format');
        }

        return null;
    }

    /**
     * @param string|ParameterSchema|ColumnSchema $field_info
     *
     * @return mixed|null
     */
    public static function getNativeDateTimeFormat($field_info)
    {
        $type = DbSimpleTypes::TYPE_STRING;
        if (is_string($field_info)) {
            $type = $field_info;
        } elseif ($field_info instanceof ColumnSchema) {
            $type = $field_info->type;
        } elseif ($field_info instanceof ParameterSchema) {
            $type = $field_info->type;
        }
        // by default, assume no support for fractional seconds or timezone
        switch (strtolower(strval($type))) {
            case DbSimpleTypes::TYPE_DATE:
                return 'Y-m-d';

            case DbSimpleTypes::TYPE_DATETIME:
            case DbSimpleTypes::TYPE_DATETIME_TZ:
                return 'Y-m-d H:i:s';

            case DbSimpleTypes::TYPE_TIME:
            case DbSimpleTypes::TYPE_TIME_TZ:
                return 'H:i:s';

            case DbSimpleTypes::TYPE_TIMESTAMP:
            case DbSimpleTypes::TYPE_TIMESTAMP_TZ:
            case DbSimpleTypes::TYPE_TIMESTAMP_ON_CREATE:
            case DbSimpleTypes::TYPE_TIMESTAMP_ON_UPDATE:
                return 'Y-m-d H:i:s';
        }

        return null;
    }

    /**
     * @param      $out_format
     * @param null $in_value
     * @param null $in_format
     *
     * @return null|string
     */
    public static function formatDateTime($out_format, $in_value = null, $in_format = null)
    {
        //  If value is null, current date and time are returned
        if (!empty($out_format)) {
            $in_value = (is_string($in_value) || is_null($in_value)) ? $in_value : strval($in_value);
            if (!empty($in_format)) {
                if (false === $date = \DateTime::createFromFormat($in_format, $in_value)) {
                    \Log::error("Failed to create datetime with '$in_value' as format '$in_format'");
                    try {
                        $date = new \DateTime($in_value);
                    } catch (\Exception $e) {
                        \Log::error("Failed to create datetime from '$in_value': " . $e->getMessage());

                        return $in_value;
                    }
                }
            } else {
                try {
                    $date = new \DateTime($in_value);
                } catch (\Exception $e) {
                    \Log::error("Failed to create datetime from '$in_value': " . $e->getMessage());

                    return $in_value;
                }
            }

            return $date->format($out_format);
        }

        return $in_value;
    }

    /**
     * {@inheritdoc}
     */
    public function typecastToNative($value, $field_info, $allow_null = true)
    {
        if (is_null($value) && $field_info->allowNull) {
            return null;
        }

        switch ($field_info->type) {
            case DbSimpleTypes::TYPE_BOOLEAN:
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                if (preg_match('/(int|num|bit)/', $field_info->dbType)) {
                    $value = ($value ? 1 : 0);
                }
                break;

            case DbSimpleTypes::TYPE_INTEGER:
            case DbSimpleTypes::TYPE_ID:
            case DbSimpleTypes::TYPE_REF:
            case DbSimpleTypes::TYPE_USER_ID:
            case DbSimpleTypes::TYPE_USER_ID_ON_CREATE:
            case DbSimpleTypes::TYPE_USER_ID_ON_UPDATE:
                if (!is_int($value)) {
                    if (('' === $value) && $field_info->allowNull) {
                        $value = null;
//                    if (!(ctype_digit($value))) {
                    } elseif (!is_numeric($value)) {
                        throw new BadRequestException("Field '{$field_info->getName(true)}' must be a valid integer.");
                    } else {
                        if (!is_float($value)) { // bigint catch as float
                            $value = intval($value);
                        }
                    }
                }
                break;

            case DbSimpleTypes::TYPE_DECIMAL:
            case DbSimpleTypes::TYPE_DOUBLE:
            case DbSimpleTypes::TYPE_FLOAT:
                $value = floatval($value);
                break;

            case DbSimpleTypes::TYPE_STRING:
            case DbSimpleTypes::TYPE_TEXT:
                break;

            // special checks
            case DbSimpleTypes::TYPE_DATE:
            case DbSimpleTypes::TYPE_DATETIME:
            case DbSimpleTypes::TYPE_DATETIME_TZ:
            case DbSimpleTypes::TYPE_TIME:
            case DbSimpleTypes::TYPE_TIME_TZ:
            case DbSimpleTypes::TYPE_TIMESTAMP:
            case DbSimpleTypes::TYPE_TIMESTAMP_TZ:
            case DbSimpleTypes::TYPE_TIMESTAMP_ON_CREATE:
            case DbSimpleTypes::TYPE_TIMESTAMP_ON_UPDATE:
                $value = $this->formatDateTime(
                    static::getNativeDateTimeFormat($field_info),
                    $value,
                    static::getConfigDateTimeFormat($field_info->type)
                );
                break;

            default:
                break;
        }

        return $value;
    }
}
