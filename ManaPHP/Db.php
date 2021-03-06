<?php

namespace ManaPHP;

use ManaPHP\Db\AssignmentInterface;
use ManaPHP\Db\Connection;
use ManaPHP\Db\Exception as DbException;
use ManaPHP\Exception\InvalidArgumentException;
use ManaPHP\Exception\MisuseException;

class DbContext
{
    /**
     * @var \ManaPHP\Db\ConnectionInterface
     */
    public $connection;

    /**
     * Active SQL Statement
     *
     * @var string
     */
    public $sql;

    /**
     * Active SQL bound parameter variables
     *
     * @var array
     */
    public $bind = [];

    /**
     * Current transaction level
     *
     * @var int
     */
    public $transaction_level = 0;

    /**
     * Last affected rows
     *
     * @var int
     */
    public $affected_rows;
}

/**
 * Class ManaPHP\Db
 *
 * @package db
 * @property-read \ManaPHP\DbContext $_context
 */
class Db extends Component implements DbInterface
{
    const METADATA_ATTRIBUTES = 0;
    const METADATA_PRIMARY_KEY = 1;
    const METADATA_AUTO_INCREMENT_KEY = 3;
    const METADATA_INT_TYPE_ATTRIBUTES = 5;

    /**
     * @var string
     */
    protected $_uri;

    /**
     * @var bool
     */
    protected $_has_slave;

    /**
     * @var float
     */
    protected $_timeout = 1.0;

    /**
     * @var int
     */
    protected $_pool_size = 4;

    /**
     * Db constructor.
     *
     * @param string|\ManaPHP\Db\Connection $uri
     */
    public function __construct($uri)
    {
        if (is_string($uri)) {
            $this->_uri = $uri;

            if (strpos($this->_uri, 'timeout=') !== false && preg_match('#timeout=([\d\\.]+)#', $this->_uri, $matches) === 1) {
                $this->_timeout = (float)$matches[1];
            }

            if (preg_match('#pool_size=(\d+)#', $uri, $matches)) {
                $this->_pool_size = (int)$matches[1];
            }

            $adapter = 'ManaPHP\Db\Connection\Adapter\\' . ucfirst(parse_url($uri, PHP_URL_SCHEME));
            if (strpos($uri, ',') !== false) {
                $host_str = parse_url($uri, PHP_URL_HOST);
                $hosts = explode(',', $host_str);
                if ($hosts[0] !== '') {
                    $this->poolManager->add($this, ['class' => $adapter, str_replace($host_str, $hosts[0], $uri)], $this->_pool_size);
                }
                array_shift($hosts);
                foreach ($hosts as $host) {
                    $this->poolManager->add($this, ['class' => $adapter, str_replace($host_str, $host, $uri)], $this->_pool_size, 'slave');
                }
                $this->_has_slave = $hosts ? true : false;
            } else {
                $this->poolManager->add($this, ['class' => $adapter, $uri], $this->_pool_size);
            }
        } elseif ($uri instanceof Connection) {
            $this->_pool_size = 1;
            $connection = $uri;
            $this->_uri = $uri->getUri();
            $this->poolManager->add($this, $connection, $this->_pool_size);
        }
    }

    protected function _escapeIdentifier($identifier)
    {
        $list = [];
        foreach (explode('.', $identifier) as $id) {
            if ($identifier[0] === '[') {
                $list[] = $id;
            } else {
                $list[] = "[$id]";
            }
        }

        return implode('.', $list);
    }

    /**
     * @param string $type
     * @param string $sql
     * @param array  $bind
     *
     * @return int
     * @throws \ManaPHP\Db\Exception
     */
    protected function _execute($type, $sql, $bind = [])
    {
        $context = $this->_context;

        $context->sql = $sql;
        $context->bind = $bind;

        $context->affected_rows = 0;

        $this->eventsManager->fireEvent('db:before' . ucfirst($type), $this);

        if ($context->connection) {
            $connection = $context->connection;
        } else {
            $connection = $this->poolManager->pop($this, $this->_timeout);
        }

        try {
            $start_time = microtime(true);
            $context->affected_rows = $connection->execute($sql, $bind);
            $elapsed = round(microtime(true) - $start_time, 3);
        } finally {
            if (!$context->connection) {
                $this->poolManager->push($this, $connection);
            }
        }

        $count = $context->affected_rows;
        $event_data = compact('count', 'sql', 'bind', 'elapsed');
        if (is_int($context->affected_rows)) {
            $this->eventsManager->fireEvent('db:after' . ucfirst($type), $this, $event_data);
        }

        $this->logger->info($event_data, 'db.' . $type);
        return $count;
    }

    /**
     * Returns the number of affected rows by the last INSERT/UPDATE/DELETE reported by the database system
     *
     * @return int
     */
    public function affectedRows()
    {
        return $this->_context->affected_rows;
    }

    /**
     * Returns the first row in a SQL query result
     *
     * @param string $sql
     * @param array  $bind
     * @param int    $fetchMode
     * @param bool   $useMaster
     *
     * @return array|false
     */
    public function fetchOne($sql, $bind = [], $fetchMode = \PDO::FETCH_ASSOC, $useMaster = false)
    {
        return ($rs = $this->fetchAll($sql, $bind, $fetchMode, $useMaster)) ? $rs[0] : false;
    }

    /**
     * Dumps the complete result of a query into an array
     *
     * @param string $sql
     * @param array  $bind
     * @param int    $fetchMode
     * @param bool   $useMaster
     *
     * @return array
     */
    public function fetchAll($sql, $bind = [], $fetchMode = \PDO::FETCH_ASSOC, $useMaster = false)
    {
        $context = $this->_context;

        $context->sql = $sql;
        $context->bind = $bind;
        $context->affected_rows = 0;

        $this->eventsManager->fireEvent('db:beforeQuery', $this);

        if ($context->connection) {
            $type = null;
            $connection = $context->connection;
        } else {
            $type = $this->_has_slave ? 'slave' : 'default';
            $connection = $this->poolManager->pop($this, $this->_timeout, $type);
        }

        try {
            $start_time = microtime(true);
            $result = $connection->query($sql, $bind, $fetchMode, $useMaster);
            $elapsed = round(microtime(true) - $start_time, 3);
        } finally {
            if ($type) {
                $this->poolManager->push($this, $connection, $type);
            }
        }

        $count = $context->affected_rows = count($result);

        $event_data = compact('elapsed', 'count', 'sql', 'bind', 'result');

        $this->logger->debug($event_data, 'db.query');

        $this->eventsManager->fireEvent('db:afterQuery', $this, $event_data);

        return $result;
    }

    /**
     * @param string $table
     * @param array  $record
     * @param bool   $fetchInsertId
     *
     * @return int|string|null
     */
    public function insert($table, $record, $fetchInsertId = false)
    {
        $context = $this->_context;

        if (!$record) {
            throw new InvalidArgumentException(['Unable to insert into :table table without data', 'table' => $table]);
        }
        $fields = array_keys($record);
        $insertedValues = ':' . implode(',:', $fields);
        $insertedFields = '[' . implode('],[', $fields) . ']';

        $context->sql = $sql = 'INSERT' . ' INTO ' . $this->_escapeIdentifier($table) . " ($insertedFields) VALUES ($insertedValues)";

        $context->bind = $bind = $record;

        $context->affected_rows = 0;

        if ($context->connection) {
            $type = null;
            $connection = $context->connection;
        } else {
            $type = $this->_has_slave ? 'slave' : 'default';
            $connection = $this->poolManager->pop($this, $this->_timeout, $type);
        }

        $this->eventsManager->fireEvent('db:beforeInsert', $this);

        try {
            $start_time = microtime(true);
            if ($fetchInsertId) {
                $insert_id = $connection->execute($sql, $record, true);
                $context->affected_rows = 1;
            } else {
                $connection->execute($sql, $record, false);
                $insert_id = null;
            }
            $elapsed = round(microtime(true) - $start_time, 3);
        } finally {
            if ($type) {
                $this->poolManager->push($this, $connection, $type);
            }
        }

        $event_data = compact('sql', 'record', 'elapsed');

        $this->eventsManager->fireEvent('db:afterInsert', $this, $event_data);

        $this->logger->info(compact('elapsed', 'insert_id', 'sql', 'bind'), 'db.insert');

        return $insert_id;
    }

    /**
     * @param string $sql
     * @param array  $bind
     *
     * @return int
     */
    public function insertBySql($sql, $bind = [])
    {
        return $this->_execute('insert', $sql, $bind);
    }

    /**
     * Updates data on a table using custom SQL syntax
     *
     * @param string       $table
     * @param array        $fieldValues
     * @param string|array $conditions
     * @param array        $bind
     *
     * @return    int
     * @throws \ManaPHP\Db\Exception
     */
    public function update($table, $fieldValues, $conditions, $bind = [])
    {
        if (!$fieldValues) {
            throw new InvalidArgumentException(['Unable to update :table table without data', 'table' => $table]);
        }

        if (is_string($conditions)) {
            $conditions = [$conditions];
        }

        $wheres = [];

        /** @noinspection ForeachSourceInspection */
        foreach ($conditions as $k => $v) {
            if (is_int($k)) {
                $wheres[] = stripos($v, ' or ') ? "($v)" : $v;
            } else {
                $wheres[] = "[$k]=:$k";
                $bind[$k] = $v;
            }
        }

        $setFields = [];
        foreach ($fieldValues as $k => $v) {
            if (is_int($k)) {
                $setFields[] = $v;
            } elseif ($v instanceof AssignmentInterface) {
                $v->setFieldName($k);
                $setFields[] = $v->getSql();
                /** @noinspection SlowArrayOperationsInLoopInspection */
                $bind = array_merge($bind, $v->getBind());
            } else {
                $setFields[] = "[$k]=:$k";
                $bind[$k] = $v;
            }
        }

        $sql = 'UPDATE ' . $this->_escapeIdentifier($table) . ' SET ' . implode(',', $setFields) . ' WHERE ' . implode(' AND ', $wheres);

        return $this->_execute('update', $sql, $bind);
    }

    /**
     * Updates data on a table using custom SQL syntax
     *
     * @param string $sql
     * @param array  $bind
     *
     * @return    int
     */
    public function updateBySql($sql, $bind = [])
    {
        return $this->_execute('update', $sql, $bind);
    }

    /**
     * Updates data on a table using custom SQL syntax
     *
     * @param string $table
     * @param array  $insertFieldValues
     * @param array  $updateFieldValues
     * @param string $primaryKey
     *
     * @return    int
     */
    public function upsert($table, $insertFieldValues, $updateFieldValues = [], $primaryKey = null)
    {
        if (!$primaryKey) {
            $primaryKey = (string)key($insertFieldValues);
        }

        if ($this->query($table)->whereEq($primaryKey, $insertFieldValues[$primaryKey])->exists()) {
            $bind = [];
            $updates = [];
            foreach ($updateFieldValues as $k => $v) {
                $field = is_string($k) ? $k : $v;
                if ($primaryKey === $field) {
                    continue;
                }

                if (is_int($k)) {
                    $updates[] = "[$field]=:$field";
                    $bind[$field] = $insertFieldValues[$field];
                } elseif ($v instanceof AssignmentInterface) {
                    $v->setFieldName($k);
                    $updates[] = $v->getSql();
                    /** @noinspection SlowArrayOperationsInLoopInspection */
                    $bind = array_merge($bind, $v->getBind());
                } else {
                    $updates[] = $v;
                }
            }
            return $this->update($table, $updates, [$primaryKey => $insertFieldValues[$primaryKey]], $bind);
        } else {
            return $this->insert($table, $insertFieldValues);
        }
    }

    /**
     * Deletes data from a table using custom SQL syntax
     *
     * @param string       $table
     * @param string|array $conditions
     * @param array        $bind
     *
     * @return int
     * @throws \ManaPHP\Db\Exception
     */
    public function delete($table, $conditions, $bind = [])
    {
        if (is_string($conditions)) {
            $conditions = [$conditions];
        }

        $wheres = [];
        /** @noinspection ForeachSourceInspection */
        foreach ($conditions as $k => $v) {
            if (is_int($k)) {
                $wheres[] = stripos($v, ' or ') ? "($v)" : $v;
            } else {
                $wheres[] = "[$k]=:$k";
                $bind[$k] = $v;
            }
        }

        $sql = 'DELETE' . ' FROM ' . $this->_escapeIdentifier($table) . ' WHERE ' . implode(' AND ', $wheres);
        return $this->_execute('delete', $sql, $bind);
    }

    /**
     * Deletes data from a table using custom SQL syntax
     *
     * @param string $sql
     * @param array  $bind
     *
     * @return int
     */
    public function deleteBySql($sql, $bind = [])
    {
        return $this->_execute('delete', $sql, $bind);
    }

    /**
     * Active SQL statement in the object
     *
     * @return string
     */
    public function getSQL()
    {
        return $this->_context->sql;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    protected function _quote($value)
    {
        return "'" . str_replace($value, "'", "\\'") . "'";
    }

    /**
     * @param mixed $value
     * @param int   $preservedStrLength
     *
     * @return int|string
     */
    protected function _parseBindValue($value, $preservedStrLength)
    {
        if (is_string($value)) {
            $quoted = $this->_quote($value);
            if ($preservedStrLength > 0 && strlen($quoted) >= $preservedStrLength) {
                return substr($quoted, 0, $preservedStrLength) . '...';
            } else {
                return $quoted;
            }
        } elseif (is_int($value)) {
            return $value;
        } elseif ($value === null) {
            return 'NULL';
        } elseif (is_bool($value)) {
            return (int)$value;
        } else {
            return $value;
        }
    }

    /**
     * Active SQL statement in the object with replace the bind with value
     *
     * @param int $preservedStrLength
     *
     * @return string
     */
    public function getEmulatedSQL($preservedStrLength = -1)
    {
        $context = $this->_context;

        if (!$context->bind) {
            return (string)$context->sql;
        }

        $bind = $context->bind;
        if (isset($bind[0])) {
            return (string)$context->sql;
        } else {
            $replaces = [];
            foreach ($bind as $key => $value) {
                $replaces[':' . $key] = $this->_parseBindValue($value, $preservedStrLength);
            }

            return strtr($context->sql, $replaces);
        }
    }

    /**
     * Active SQL statement in the object
     *
     * @return array
     */
    public function getBind()
    {
        return $this->_context->bind;
    }

    /**
     * Starts a transaction in the connection
     *
     * @return void
     * @throws \ManaPHP\Db\Exception
     */
    public function begin()
    {
        $context = $this->_context;

        $this->logger->info('transaction begin', 'db.transaction.begin');

        if ($context->transaction_level === 0) {
            $this->eventsManager->fireEvent('db:beginTransaction', $this);

            try {
                $connection = $this->poolManager->pop($this, $this->_timeout);

                if (!$connection->beginTransaction()) {
                    throw new DbException('beginTransaction failed.');
                }
                $context->connection = $connection;
            } catch (\PDOException $exception) {
                throw new DbException('beginTransaction failed: ' . $exception->getMessage(), $exception->getCode(), $exception);
            } finally {
                if (!$context->connection) {
                    $this->poolManager->push($this, $context);
                }
            }
        }

        $context->transaction_level++;
    }

    /**
     * Checks whether the connection is under a transaction
     *
     * @return bool
     */
    public function isUnderTransaction()
    {
        $context = $this->_context;

        return $context->transaction_level !== 0;
    }

    /**
     * Rollbacks the active transaction in the connection
     *
     * @return void
     * @throws \ManaPHP\Db\Exception
     */
    public function rollback()
    {
        $context = $this->_context;

        if ($context->transaction_level > 0) {
            $this->logger->info('transaction rollback', 'db.transaction.rollback');
            $context->transaction_level--;

            if ($context->transaction_level === 0) {
                $this->eventsManager->fireEvent('db:rollbackTransaction', $this);

                try {
                    if (!$context->connection->rollBack()) {
                        throw new DbException('rollBack failed.');
                    }
                } catch (\PDOException $exception) {
                    throw new DbException('rollBack failed: ' . $exception->getMessage(), $exception->getCode(), $exception);
                } finally {
                    $this->poolManager->push($this, $context->connection);
                    $context->connection = null;
                }
            }
        }
    }

    /**
     * Commits the active transaction in the connection
     *
     * @return void
     * @throws \ManaPHP\Db\Exception
     */
    public function commit()
    {
        $context = $this->_context;

        $this->logger->info('transaction commit', 'db.transaction.commit');

        if ($context->transaction_level === 0) {
            throw new MisuseException('There is no active transaction');
        }

        $context->transaction_level--;

        if ($context->transaction_level === 0) {
            $this->eventsManager->fireEvent('db:commitTransaction', $this);

            try {
                if (!$context->connection->commit()) {
                    throw new DbException('commit failed.');
                }
            } catch (\PDOException $exception) {
                throw new DbException('commit failed: ' . $exception->getMessage(), $exception->getCode(), $exception);
            } finally {
                $this->poolManager->push($this, $context->connection);
                $context->connection = null;
            }
        }
    }

    /**
     * @return string
     */
    public function getLastSql()
    {
        return $this->_context->sql;
    }

    /**
     * @param string $schema
     *
     * @return array
     * @throws \ManaPHP\Db\Exception
     */
    public function getTables($schema = null)
    {
        $context = $this->_context;

        if ($context->connection) {
            $type = null;
            $connection = $context->connection;
        } else {
            $type = $this->_has_slave ? 'slave' : 'default';
            $connection = $this->poolManager->pop($this, $this->_timeout, $type);
        }

        try {
            return $connection->getTables($schema);
        } finally {
            if ($type) {
                $this->poolManager->push($this, $connection, $type);
            }
        }
    }

    /**
     * @param array $params
     *
     * @return string
     */
    public function buildSql($params)
    {
        $context = $this->_context;

        if ($context->connection) {
            $type = null;
            $connection = $context->connection;
        } else {
            $type = $this->_has_slave ? 'slave' : 'default';
            $connection = $this->poolManager->pop($this, $this->_timeout, $type);
        }

        try {
            return $connection->buildSql($params);
        } finally {
            if ($type) {
                $this->poolManager->push($this, $connection, $type);
            }
        }
    }

    /**
     * @param $source
     *
     * @return array
     * @throws \ManaPHP\Db\Exception
     */
    public function getMetadata($source)
    {
        $context = $this->_context;

        if ($context->connection) {
            $type = null;
            $connection = $context->connection;
        } else {
            $type = $this->_has_slave ? 'slave' : 'default';
            $connection = $this->poolManager->pop($this, $this->_timeout, $type);
        }

        try {
            $start_time = microtime(true);
            $meta = $connection->getMetadata($source);
            $elapsed = round(microtime(true) - $start_time, 3);
        } finally {
            if ($type) {
                $this->poolManager->push($this, $connection, $type);
            }
        }

        $this->logger->debug(compact('elapsed', 'source', 'meta'), 'db.metadata');

        return $meta;
    }

    public function close()
    {
        $context = $this->_context;

        if ($context->connection) {
            if ($context->transaction_level !== 0) {
                $context->transaction_level = 0;
                try {
                    $context->connection->rollBack();
                } finally {
                    $this->poolManager->push($this, $context->connection);
                }

                $this->logger->error('transaction is not close correctly', 'db.transaction.abnormal');
            }
            $context->connection = null;
        }
    }

    /**
     * @param string $table
     * @param string $alias
     *
     * @return \ManaPHP\Db\Query
     */
    public function query($table = null, $alias = null)
    {
        return $this->_di->get('ManaPHP\Db\Query', [$this])->from($table, $alias);
    }
}