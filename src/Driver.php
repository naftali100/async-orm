<?php

namespace AsyncOrm;

use Amp\Promise;
use Amp\Success;
use Amp\Cache\ArrayCache;
use Amp\Cache\SerializedCache;
use Amp\Serialization\NativeSerializer;
use function Amp\call;

use AsyncOrm\Internal;
use AsyncOrm\OrmObject;

abstract class Driver
{
    /**
     * if true columns don't change
     */
    protected bool $freeze = false;

    protected $driver; // connection?

    /**
     * @var Amp\Cache\SerializedCache
     */
    protected $cache;

    protected $sqlTemplates = array(
        'addColumn' => 'ALTER TABLE %s ADD %s %s ',
        'createTable' => 'CREATE TABLE %s (id INT( 11 ) UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY ( id )) ENGINE = InnoDB ',
        'widenColumn' => 'ALTER TABLE `%s` CHANGE %s %s %s '
    );


    /**
     * created driver
     *
     * @return Promise<Driver>
     */
    abstract public static function createDriver($host, $user, $pass, $db): Promise;
    abstract public function isReady(): bool;
    abstract public function execute($sql, $bindings): Promise;
    abstract public function query($sql): Promise;

    public function initCache()
    {
        if($this->cache != null){
            // https://stackoverflow.com/a/804089/12893054
            // maybe change NativeSerializer to JsonSerializer
            $this->cache = new SerializedCache(new ArrayCache(), new NativeSerializer());
        }
    }

    /**
     * load row by id
     */
    public function load($table, $id)
    {
        return call(function () use ($table, $id) {
            $res = yield Internal::getOneFromSet($this->execute("SELECT * FROM $table WHERE id = ?", [$id]));
            return new OrmObject($table, $res ?? []);
        });
    }

    /** reload ormObject from db */
    public function reload($ormObject)
    {
        if ($ormObject->id != 0) {
            // TODO: check if this change the param or not
            $ormObject = $this->load($ormObject->getMeta('type'), $ormObject->id);
            return $ormObject;
        } else {
            return new Success($ormObject);
        }
    }

    /**
     * find all rows
     *
     */
    public function find($table, $where = '1', $bindings = [])
    {
        return call(function () use ($table, $where, $bindings) {
            $res = [];
            $arraySet = yield Internal::resultSetToArray($this->execute("SELECT * FROM $table WHERE $where", $bindings));
            foreach ($arraySet as $result) {
                $res[] = new OrmObject($table, $result);
            }
            return $res;
        });
    }

    /**
     * fine one row in $table
     *
     * @param string $table  the table to query
     * @param string $where  added sql after 'WHERE'
     * @param array $bindings args to bind to the query
     *
     * @throw Amp\Sql\QueryError if table or column not exist
     *
     * @return Amp\Promise<ormObject|null> */
    public function findOne($table, $where = '1', $bindings = []): Promise
    {
        return call(function () use ($table, $where, $bindings) {
            $result = yield Internal::getOneFromSet($this->execute("SELECT * FROM $table WHERE $where LIMIT 1", $bindings));
            if ($result) {
                return new OrmObject($table, $result);
            }
            return null;
        });
    }

    public function count($table, $where = '1', $bind = [])
    {
        return call(function () use ($table, $where, $bind) {
            return count(yield $this->find($table, $where, $bind));
        });
    }


    /**
     * store object in database and also update the schema if needed
     */
    public function store($ormObject): Promise|array
    {
        if (gettype($ormObject) == 'array') {
            $p = [];
            foreach ($ormObject as $obj) {
                $p[] = $this->store($obj);
            }
            return $p;
        }
        return call(function () use ($ormObject) {
            if ($ormObject->getMeta('changed')) {
                $ormObject->setMeta('changed', false);

                if (!$this->freeze) {
                    yield $this->adjustToObj($ormObject);
                }

                if ($ormObject->getMeta('created')) {
                    yield $this->insert($ormObject);
                } else { // its updated
                    yield $this->update($ormObject);
                }
                $ormObject->save();
            }
            return $ormObject->id;
        });
    }


    public function trash($ormObject): Promise
    {
        return Internal::getOneFromSet($this->execute("DELETE FROM {$ormObject->getMeta('type')} WHERE id = ? ", [$ormObject->id]));
    }


    private function insert($ormObject)
    {
        return call(function () use ($ormObject) {
            $ormObject->setMeta('created', false);

            $table = $ormObject->getMeta('type');
            $changes = $ormObject->getChanges();

            $question = Internal::question(count($changes));
            $sql = "INSERT INTO {$table} (" . implode(', ', array_keys($changes)) . ") VALUES({$question}) ";

            $res = yield $this->execute($sql, array_values($changes));
            $ormObject->id = $res->getLastInsertId();
            return $ormObject->id;
        });
    }

    private function update($ormObject)
    {
        return call(function () use ($ormObject) {
            $id = $ormObject->getProperty('id');
            $table = $ormObject->getMeta('type');
            $changes = $ormObject->getChanges();

            $sql = "UPDATE {$table} SET ";
            foreach ($changes as $key => $value) {
                $sql .= $key . ' = ?, ';
            }
            $sql = rtrim($sql, ', '); // remove comma from last foreach iteration
            $sql .= " WHERE id = " . $id;

            return yield $this->execute($sql, array_values($changes));
        });
    }


    /**
     * take ormObject and adjust the schema to fit the object.
     */
    private function adjustToObj($obj): Promise
    {
        return call(function () use ($obj) {
            yield $this->addTable($obj->getMeta('type')); // ignores if table already exist

            $changes = $obj->getChanges();
            $table = $obj->getMeta('type');
            $cols = yield $this->getCols($table);

            if ([] != $diff = array_diff_key($changes, $cols)) {
                $p = [];
                foreach ($diff as $newColName => $data) {
                    $sql_type = SchemaHelper::codeFromData($data, true);
                    $p[] = $this->addCol($table, $newColName, $sql_type);
                }
                yield $p;
            }
            // TODO: check if need to width
        });
    }

    private function getTables(): Promise
    {
        return call(function () {
            $tables = yield $this->cache->get('tables');
            if ($tables != null) {
                return $tables;
            }
            $res = [];
            $tables = yield Internal::resultSetToArray($this->query('show tables'));
            foreach ($tables as $table) {
                $res[] = reset($table); // get the value 
            }
            yield $this->cache->set('tables', $res);
            return $res;
        });
    }

    private function addTable($type): Promise
    {
        return call(function () use ($type) {
            $tables = yield $this->getTables();
            if (in_array($type, $tables)) {
                return true;
            }

            yield $this->cache->delete('tables');

            $res = yield Internal::resultSetToArray($this->query(sprintf($this->sqlTemplates['createTable'], $type)));
            return $res == 0;
        });
    }

    /**
     * add column to table
     * 
     * @param string table the table name to add the column
     * @param string col the column name to add
     * @param string sql_type the type of the table in sql format (INT, TEXT, ect)
     * 
     * @yield 0 on success
     */
    protected function addCol(string $table, string $col, string $sql_type)
    {
        return call(function () use ($table, $col, $sql_type) {
            $cols = yield $this->getCols($table);
            if (in_array($col, $cols)) {
                return true;
            }

            yield $this->cache->delete($table . '__cols');

            return Internal::getOneFromSet($this->query(sprintf($this->sqlTemplates['addColumn'], $table, $col, $sql_type)));
        });
    }

    protected function getCols($table): Promise
    {
        return call(function () use ($table) {
            $cols = yield $this->cache->get($table . '__cols');
            if ($cols != null) {
                return $cols;
            }
            $res = [];
            $cols = yield $this->getFullCols($table);
            foreach ($cols as $col) {
                $res[$col['Field']] = $col['Type'];
            }

            yield $this->cache->set($table . '__cols', $res);
            return $res;
        });
    }

    protected function getFullCols($table): Promise
    {
        return Internal::resultSetToArray($this->query("describe $table"));
    }
}
