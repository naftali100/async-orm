<?php

namespace async_orm;

require 'vendor/autoload.php';

use Amp;
use Amp\Mysql;
use Amp\Cache\ArrayCache;
use Amp\Cache\SerializedCache;
use Amp\Serialization\NativeSerializer;

use function Amp\call;

class ORM
{
    /**
     * if true columns don't change 
     */
    private static bool $freeze = false;

    /**
     * @var Amp\Mysql\Connection
     */
    private static $s_db;

    /**
     * @var Amp\Cache\SerializedCache
     */
    private static $cache;

    function __destruct()
    {

        return $this->db->close();
    }

    static function isReady(): bool
    {
        return self::$s_db->isReady() && self::$s_db->isAlive();
    }

    static function execute($sql, $bind): Amp\Promise
    {
        if (self::isReady()) {
            return self::$s_db->execute($sql, $bind);
        } else {
            throw new \Error('not ready');
        }
    }
    static function query($sql): Amp\Promise
    {
        if (self::isReady()) {
            return self::$s_db->query($sql);
        } else {
            throw new \Error('not ready');
        }
    }

    /**
     * init the connection
     */
    static function connect($host, $user, $pass, $db)
    {
        return self::connectFromString("host=$host;user=$user;pass=$pass;db=$db");
    }

    static function connectFromString(string $string)
    {
        return call(function () use ($string) {
            $config = Mysql\ConnectionConfig::fromString($string);
            self::$s_db = yield Mysql\connect($config);
            // https://stackoverflow.com/a/804089/12893054
            // maybe change NativeSerializer to JsonSerializer
            self::$cache = new SerializedCache(new ArrayCache(), new NativeSerializer());
        });
    }

    /**
     * create empty row in $table that can be stored later in db
     */
    static function create($table): OrmObject
    {
        return new OrmObject($table);
    }

    /** 
     * load row by id 
     */
    function load($table, $id)
    {
        return call(function () use ($table, $id) {
            $res = yield Internal::getOneFromSet(self::execute("SELECT * FROM $table WHERE id = ?", [$id]));
            return new OrmObject($table, $res ?? []);
        });
    }

    /** reload ormObject from db */
    function reload($ormObject)
    {
        if ($ormObject->id != 0) {
            return $this->load($ormObject->getMeta('type'), $ormObject->id);
        } else {
            return $ormObject;
        }
    }

    static function find($table, $where = '1', $bindings = [])
    {
        return call(function () use ($table, $where, $bindings) {
            $res = [];
            foreach (yield Internal::resultSetToArray(self::execute("SELECT * FROM $table WHERE $where", $bindings)) as $result) {
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
     * @param array $data args to bind to the query
     * 
     * @throw Amp\Sql\QueryError if table or column not exist
     * 
     * @return Amp\Promise<ormObject>|null */
    static function findOne($table, $where = '1', $bindings = [])
    {
        return call(function () use ($table, $where, $bindings) {
            $result = yield Internal::getOneFromSet($this->db->execute("SELECT * FROM $table WHERE $where LIMIT 1", $bindings));
            if ($result) {
                return new OrmObject($table, $result);
            }
            return null;
        });
    }

    static function trash($ormObject)
    {
        return Internal::getOneFromSet(self::execute("DELETE FROM {$ormObject->getMeta('type')} WHERE id = ? ", [$ormObject->id]));
    }

    static function count($table, $where = '1', $bind = [])
    {

        return call(function () use ($table, $where, $bind) {
            return count(yield self::find($table, $where, $bind));
            // $res = yield $this->getone(self::execute("SELECT count(*) FROM $table WHERE $where ", $bind));
            // return current($res);
        });
    }

    /**
     * store object in database and also update the schema if nedded
     */
    static function store($ormObject): Amp\Promise|array
    {
        if (gettype($ormObject) == 'array') {
            $p = [];
            foreach ($ormObject as $obj) {
                $p[] = self::store($obj);
            }
            return $p;
        }
        return call(function () use ($ormObject) {
            if ($ormObject->getMeta('changed')) {
                $ormObject->setMeta('changed', false);

                if (!self::$freeze)
                    yield self::adjustToObj($ormObject);

                if ($ormObject->getMeta('created')) {
                    yield self::store_new_orm($ormObject);
                } else { // its updated
                    self::update_orm($ormObject);
                }
                $ormObject->save();
            }
            return $ormObject->id;
        });
    }

    private static function store_new_orm($ormObject)
    {
        return call(function () use ($ormObject) {
            $ormObject->setMeta('created', false);

            $table = $ormObject->getMeta('type');
            $changes = $ormObject->getChanges();

            $question = Internal::question(count($changes));
            $sql = "INSERT INTO {$table} (" . implode(', ', array_keys($changes)) . ") VALUES({$question}) ";

            $res = yield self::execute($sql, array_values($changes));
            $ormObject->id = $res->getLastInsertId();
            return $ormObject->id;
        });
    }

    private function update_orm($ormObject)
    {
        return call(function () use ($ormObject) {
            $id = $ormObject->getOrigin('id');
            $table = $ormObject->getMeta('type');
            $changes = $ormObject->getChanges();

            $sql = "UPDATE {$table} SET ";
            foreach ($changes as $key => $value) {
                $sql .= $key . ' = ?, ';
            }
            $sql = rtrim($sql, ', ');
            $sql .= " WHERE id = " . $id;

            yield $this->db->execute($sql, array_values($changes));
        });
    }

    /**
     * utility functions
     */

    protected static $SqlTemplates = array(
        'addColumn' => 'ALTER TABLE %s ADD %s %s ',
        'createTable' => 'CREATE TABLE %s (id INT( 11 ) UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY ( id )) ENGINE = InnoDB ',
        'widenColumn' => 'ALTER TABLE `%s` CHANGE %s %s %s '
    );

    /**
     * take ormObject and adjust the schema to fit the object.
     */
    private static function adjustToObj($obj): Amp\Promise
    {
        return call(function () use ($obj) {
            if (!in_array($obj->getMeta('type'), yield self::getTables())) {
                yield self::addTable($obj->getMeta('type'));
            }

            $changes = $obj->getChanges();
            $table = $obj->getMeta('type');
            $cols = yield self::getCols($table);

            if ([] != $diff = array_diff_key($changes, $cols)) {
                $p = [];
                foreach ($diff as $newColName => $data) {
                    $sql_type = SchemaHelper::codeFromData($data, true);
                    $p[] = self::addCol($table, $newColName, $sql_type);
                }
                yield $p;
            }
            // TODO: check if need to width
        });
    }

    private static function getTables(): Amp\Promise
    {
        return call(function () {
            if ($tables = (yield self::$cache->get('tables')) != null) {
                return $tables;
            }
            $res = [];
            $tables = yield Internal::resultSetToArray(self::query('show tables'));
            foreach ($tables as $table) {
                $res[] = reset($table); // get the value 
            }
            self::$cache->set('tables', $res);
            return $res;
        });
    }

    private static function addTable($type): Amp\Promise
    {
        return call(function () use ($type) {
            if (in_array($type, yield $this->getTables())) {
                return true;
            }

            yield self::$cache->delete('tables');

            $res = yield Internal::resultSetToArray($this->db->query(sprintf($this->SqlTemplates['createTable'], $type)));
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
    private static function addCol(string $table, string $col, string $sql_type)
    {
        return call(function () use ($table, $col, $sql_type) {
            yield self::$cache->delete($table . '__cols');
            return Internal::getOneFromSet(self::query(sprintf(self::$SqlTemplates['addColumn'], $table, $col, $sql_type)));
        });
    }

    private static function getCols($table): Amp\Promise
    {
        return call(function () use ($table) {
            if ($cols = (yield self::$cache->get($table . '__cols')) != null) {
                return $cols;
            }
            $res = [];
            $cols = yield self::getFullCols($table);
            foreach ($cols as $col) {
                $res[$col['Field']] = $col['Type'];
            }

            self::$cache->set($table . '__cols', $res);
            return $res;
        });
    }

    private static function getFullCols($table): Amp\Promise
    {
        return Internal::resultSetToArray(self::query("describe $table"));
    }
}
