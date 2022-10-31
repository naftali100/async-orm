<?php

namespace AsyncOrm;

use AsyncOrm\Driver\MysqlDriver;
use AsyncOrm\Driver;

use function Amp\call;

class ORM
{
    /**
     * @var Driver[]
     */
    private static $drivers;

    /**
     * @var Driver
     */
    private static $currentDriver;

    /**
     * init the connection
     */
    public static function connect($host, $user, $pass, $db, $dbName = null)
    {
        return call(function () use ($host, $user, $pass, $db, $dbName) {
            if(isset(self::$drivers[$dbName ?? $db])){
                throw new \Error('connection to: "' . ($dbName ?? $db) . '" already exist');
            }
            self::$currentDriver = self::$drivers[$dbName ?? $db] = yield MysqlDriver::createDriver($host, $user, $pass, $db);
        });
    }

    public static function selectDB($name)
    {
        if (isset(self::$drivers[$name])) {
            self::$currentDriver = self::$drivers[$name];
        } else {
            throw new \Error('db name: "' . $name . '" not exist');
        }
    }

    public static function isReady(): bool
    {
        return self::$currentDriver->isReady();
    }

    public static function reset(){
        self::$drivers = [];
    }

    /// orm operations - CURD

    /**
     * create empty row in $table that can be stored later in db
     */
    public static function create($table): OrmObject
    {
        return new OrmObject($table);
    }

    public static function load($type, $id)
    {
        return self::$currentDriver->load($type, $id);
    }

    /**
     * @return Promise<OrmObject>
     */
    public static function find($type, $where = '1', $bindings = [])
    {
        return self::$currentDriver->find($type, $where, $bindings);
    }

    public static function findOne($type, $where = '1', $bindings = [])
    {
        return self::$currentDriver->findOne($type, $where, $bindings);
    }
    public static function findOneOrCreate($type, $where = '1', $bindings = []){
        return call(function() use($type, $where , $bindings){
            $res = yield self::findOne($type, $where, $bindings);
            return $res ?? new OrmObject('type');
        });
    }

    public static function store($ormObj)
    {
        return self::$currentDriver->store($ormObj);
    }

    public static function trash($ormObj)
    {
        return self::$currentDriver->trash($ormObj);
    }

    public static function count($type, $where = '1', $bindings = [])
    {
        return self::$currentDriver->count($type, $where, $bindings);
    }
}
