<?php


require 'vendor/autoload.php';


use Amp\Mysql;
use function Amp\call;

class orm{
    /**
     * if true columns dont change 
     */
    public bool $freez = false;
    
    function __construct(private Mysql\Connection $db)
    {
    }

    function __destruct()
    {
        $this->db->close();
    }

    function execute($sql, $bind): Amp\Promise
    {
        return $this->db->execute($sql, $bind);
    }

    /**
     * return orm instance 
     * 
     * @return Amp\Promise<orm>
     */
    static function connect($host, $user, $pass, $db){
        return call(function() use($host, $user, $pass, $db){
            $config = Mysql\ConnectionConfig::fromString("host=$host;user=$user;pass=$pass;db=$db");
            $db = yield Mysql\connect($config);
            return new self($db);
        });
    }

    /**
     * create empty row in $table that can be stored later in db
     */
    function create($table): OrmObject{
        return new OrmObject($table);
    }

    /** 
     * load row by id 
     */
    function load($table, $id)
    {
        return call(function() use($table,$id){
            $res = yield $this->getone($this->db->execute("SELECT * FROM $table WHERE id = ?", [$id]));
            return new OrmObject($table, $res ?? []);
        });
    }

    /** reload ormObject from db */
    function reload($ormObject){
        if($ormObject->id != 0)
            return $this->load($ormObject->getMeta('type'), $ormObject->id);
        else
            return $ormObject;
    }

    function find($table, $where = '1', $data = []){
        return call(function() use($table, $where, $data) {
            $res = [];
            foreach (yield $this->resultSetToArray($this->db->execute("SELECT * FROM $table WHERE $where", $data)) as $result) {
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
    function findone($table, $where = '1', $data = []){
        return call(function () use ($table, $where, $data) {
            $result = yield $this->getone($this->db->execute("SELECT * FROM $table WHERE $where LIMIT 1", $data));
            if($result)
                return new OrmObject($table, $result);
            return null;
        });
    }

    function trash($ormObject){
        return $this->getone($this->db->execute("DELETE FROM {$ormObject->getMeta('type')} WHERE id = ? ", [$ormObject->id]));
    }

    function count($table, $where = '1', $bind = []){
        return call(function() use($table, $where, $bind){
            $res = yield $this->getone($this->db->execute("SELECT count(*) FROM $table WHERE $where ", $bind));
            return current($res);
        });
    }

    /**
     * store object in database and also update the schema if nedded
     */
    function store($ormObject): Amp\Promise|array
    {
        if(gettype($ormObject) == 'array'){
            $p = [];
            foreach($ormObject as $obj){
                $p[] = $this->store($obj);
            }
            return $p;
        }
        return call(function () use($ormObject){
            if($ormObject->getMeta('changed')){
                $ormObject->setMeta('changed', false);

                if(! $this->freez)
                    yield SchemaHelper::adjustToObj($ormObject, $this);

                if ($ormObject->getMeta('created')) { 
                    yield $this->store_new_orm($ormObject);  
                } else { // its updated
                    $this->update_orm($ormObject);
                }
                $ormObject->save();
            }
            return $ormObject->id;
        });
    }

    private function store_new_orm($ormObject){
        return call(function() use($ormObject){
            $ormObject->setMeta('created', false);

            $table = $ormObject->getMeta('type');
            $changes = $ormObject->getChanges();

            $question = $this->question(count($changes));
            $sql = "INSERT INTO {$table} (" . implode(', ', array_keys($changes)) . ") VALUES({$question}) ";

            $res = yield $this->db->execute($sql, array_values($changes));
            $ormObject->id = $res->getLastInsertId();
            return $ormObject->id;
        });
    }

    private function update_orm($ormObject){
        return call(function() use($ormObject){
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

    private function question($num)
    {
        // create array full of '?' and implode it with ','
        return implode(',', array_fill(0, $num, '?'));
    }

    /**
     * utility functions
     */

    protected $SqlTemplates = array(
        'addColumn' => 'ALTER TABLE %s ADD %s %s ',
        'createTable' => 'CREATE TABLE %s (id INT( 11 ) UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY ( id )) ENGINE = InnoDB ',
        'widenColumn' => 'ALTER TABLE `%s` CHANGE %s %s %s '
    );

    function getTables(): Amp\Promise
    {
        return call(function () {
            $res = [];
            $tables = yield $this->resultSetToArray($this->db->query('show tables'));
            foreach ($tables as $table) {
                $res[] = reset($table); // get the vaule 
            }
            return $res;
        });
    }

    function addTable($type): Amp\Promise
    {
        return call(function () use ($type) {
            if (in_array($type, yield $this->getTables())) {
                return true;
            }

            $res = yield $this->resultSetToArray($this->db->query(sprintf($this->SqlTemplates['createTable'], $type)));
            if ($res == 0)
                return true;
            else
                return false;
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
    function addCol(string $table, string $col, string $sql_type)
    { 
        return $this->getone($this->db->query(sprintf($this->SqlTemplates['addColumn'], $table, $col, $sql_type)));
    }

    function getCols($table): Amp\Promise
    {
        return call(function () use ($table) {
            $res = [];
            $cols = yield $this->getFullCols($table);
            foreach ($cols as $col) {
                $res[$col['Field']] = $col['Type'];
            }
            return $res;
        });
    }

    function getFullCols($table): Amp\Promise
    {
        return $this->resultSetToArray($this->db->query("describe $table"));
    }

    function resultSetToArray($resultSet): Amp\Promise
    {
        return call(function () use ($resultSet) {
            $result = [];

            if ($resultSet instanceof Amp\Promise) {
                $resultSet = yield $resultSet;
            }
            if ($resultSet instanceof Mysql\CommandResult) {
                return $resultSet->getLastInsertId();
            } 

            while (yield $resultSet->advance()) {
                $result[] = $resultSet->getCurrent();
            }
            return $result;
        });
    }

    /**
     * take resuleSet and return the first result 
     */
    function getOne($resultSet): Amp\Promise
    {
        return call(function () use ($resultSet) {
            if ($resultSet instanceof Amp\Promise) {
                $resultSet = yield $resultSet;
            }
            if ($resultSet instanceof Mysql\CommandResult) {
                return $resultSet->getLastInsertId();
            }

            while (yield $resultSet->advance()) {
                return $resultSet->getCurrent();
            }
        });
    }
}

class SchemaHelper{
   
    /**
     * Data types
     */
    const C_DATATYPE_BOOL             = 0;
    const C_DATATYPE_UINT32           = 2;
    const C_DATATYPE_DOUBLE           = 3;
    const C_DATATYPE_TEXT7            = 4; //InnoDB cant index varchar(255) utf8mb4 - so keep 191 as long as possible
    const C_DATATYPE_TEXT8            = 5;
    const C_DATATYPE_TEXT16           = 6;
    const C_DATATYPE_TEXT32           = 7;
    const C_DATATYPE_SPECIAL_DATE     = 80;
    const C_DATATYPE_SPECIAL_DATETIME = 81;
    const C_DATATYPE_SPECIAL_TIME     = 83;  //MySQL time column (only manual)
    const C_DATATYPE_SPECIAL_POINT    = 90;
    const C_DATATYPE_SPECIAL_LINESTRING = 91;
    const C_DATATYPE_SPECIAL_POLYGON    = 92;
    const C_DATATYPE_SPECIAL_MONEY      = 93;
    const C_DATATYPE_SPECIAL_JSON       = 94;  //JSON support (only manual)

    const C_DATATYPE_SPECIFIED        = 99;


    /**
     * take ormObject and adjust the schema to fit the object.
     */
    static function adjustToObj($obj, $orm): Amp\Promise{
        return call(function() use($obj, $orm){
            if (!in_array($obj->getMeta('type'), yield $orm->getTables())) {
                yield $orm->addTable($obj->getMeta('type'));
            }

            $changes = $obj->getChanges();
            $table = $obj->getMeta('type');
            $cols = yield $orm->getCols($table);

            if ([] != $diff = array_diff_key($changes, $cols)) {
                $p = [];
                foreach ($diff as $newColName => $data) {
                    $sql_type = SchemaHelper::codeFromData($data, true);
                    $p[] = $orm->addCol($table, $newColName, $sql_type);
                }
                yield $p;
            }
            // TODO: check if need to wighd
        });
    }

    /** get sql type from data */
    static function codeFromData($data, $s){
        return self::code(self::scanType($data, $s));
    }

    /** get sql code name for typeno */
    static function code($typeno)
    {
        $typeno_sqltype = array(
            self::C_DATATYPE_BOOL             => ' TINYINT(1) UNSIGNED ',
            self::C_DATATYPE_UINT32           => ' INT(11) UNSIGNED ',
            self::C_DATATYPE_DOUBLE           => ' DOUBLE ',
            self::C_DATATYPE_TEXT7            => ' VARCHAR(191) ',
            self::C_DATATYPE_TEXT8               => ' VARCHAR(255) ',
            self::C_DATATYPE_TEXT16           => ' TEXT ',
            self::C_DATATYPE_TEXT32           => ' LONGTEXT ',
            self::C_DATATYPE_SPECIAL_DATE     => ' DATE ',
            self::C_DATATYPE_SPECIAL_DATETIME => ' DATETIME ',
            self::C_DATATYPE_SPECIAL_TIME     => ' TIME ',
            self::C_DATATYPE_SPECIAL_POINT    => ' POINT ',
            self::C_DATATYPE_SPECIAL_LINESTRING => ' LINESTRING ',
            self::C_DATATYPE_SPECIAL_POLYGON => ' POLYGON ',
            self::C_DATATYPE_SPECIAL_MONEY    => ' DECIMAL(10,2) ',
            self::C_DATATYPE_SPECIAL_JSON     => ' JSON '
        );
        return $typeno_sqltype[$typeno];
    }

    // value to typeno
    public static function scanType($value, $flagSpecial = FALSE): int
    {
        if (is_null($value)) return self::C_DATATYPE_BOOL;
        if ($value === INF) return self::C_DATATYPE_TEXT7;

        if ($flagSpecial) {
            if (preg_match('/^-?\d+\.\d{2}$/', $value)) {
                return self::C_DATATYPE_SPECIAL_MONEY;
            }
            if (preg_match('/^\d{4}\-\d\d-\d\d$/', $value)) {
                return self::C_DATATYPE_SPECIAL_DATE;
            }
            if (preg_match('/^\d{4}\-\d\d-\d\d\s\d\d:\d\d:\d\d$/', $value)) {
                return self::C_DATATYPE_SPECIAL_DATETIME;
            }
            if (preg_match('/^POINT\(/', $value)) {
                return self::C_DATATYPE_SPECIAL_POINT;
            }
            if (preg_match('/^LINESTRING\(/', $value)) {
                return self::C_DATATYPE_SPECIAL_LINESTRING;
            }
            if (preg_match('/^POLYGON\(/', $value)) {
                return self::C_DATATYPE_SPECIAL_POLYGON;
            }
            if (self::isJSON($value)) {
                return self::C_DATATYPE_SPECIAL_JSON;
            }
        }

        //setter turns TRUE FALSE into 0 and 1 because database has no real bools (TRUE and FALSE only for test?).
        if ($value === FALSE || $value === TRUE || $value === '0' || $value === '1' || $value === 0 || $value === 1) {
            return self::C_DATATYPE_BOOL;
        }

        if (is_float($value)) return self::C_DATATYPE_DOUBLE;

        if (!self::startsWithZeros($value)) {

            if (is_numeric($value) && (floor($value) == $value) && $value >= 0 && $value <= 4294967295) {
                return self::C_DATATYPE_UINT32;
            }

            if (is_numeric($value)) {
                return self::C_DATATYPE_DOUBLE;
            }
        }

        if (mb_strlen($value, 'UTF-8') <= 191) {
            return self::C_DATATYPE_TEXT7;
        }

        if (mb_strlen($value, 'UTF-8') <= 255) {
            return self::C_DATATYPE_TEXT8;
        }

        if (mb_strlen($value, 'UTF-8') <= 65535) {
            return self::C_DATATYPE_TEXT16;
        }

        return self::C_DATATYPE_TEXT32;
    }

    private static function isJSON($value): bool
    {
        return (is_string($value) &&
            is_array(json_decode($value, TRUE)) &&
            (json_last_error() == JSON_ERROR_NONE));        
    }

    private static function startsWithZeros($value): bool
    {
        $value = strval($value);

        if (strlen($value) > 1 && strpos($value, '0') === 0 && strpos($value, '0.') !== 0) {
            return TRUE;
        } else {
            return FALSE;
        }
    }
}

class OrmObject{
    private $__info = [];
    private $origin;
    private $new_values;

    function __construct($type, array $data = []){
        $this->__info['type'] = $type;
        $this->__info['created'] = !isset($data['id']); // if obj has id - its alredy exist in db
        if($this->__info['created']) $data['id'] = 0;
        $this->origin = $data;
    }

    function __get($key){
        if (isset($this->new_values[$key]))
            return $this->new_values[$key];

        if(isset($this->origin[$key]))
            return $this->origin[$key];
    }

    function __set($key, $value){
        if (gettype($value) == 'array')
            $value = json_encode($value);

        $this->new_values[$key] = $value;

        if (isset($this->origin[$key])) {
            if ($value != $this->origin[$key])
                $this->__info['changed'] = true;
        } else
            $this->__info['changed'] = true;
    }

    function getChanges(){
        return $this->new_values;
    }

    function getMeta($type){
        if(isset($this->__info[$type]))
            return $this->__info[$type];
    }

    /** revert all changes made to object */
    function revert(){
        $this->new_values = [];
    }

    function setMeta($type, $value)
    {
        // TODO: add allowed info values
        // if (isset($this->__info[$type]))
            $this->__info[$type] = $value;
    }

    function getOrigin($key){
        if (isset($this->origin[$key]))
            return $this->origin[$key];
    }

    function save(){
        $this->origin = array_merge($this->origin, $this->new_values);
        $this->new_values = [];
    }
}

Amp\Loop::run(function(){
    $db = yield orm::connect('127.0.0.1', 'user', 'pass', 'db');

    $user = $db->create('user');
    $user->name = 'jon';
    $user->age = 30;
    $user->data = ['sity' => 'NY', 'hight' => 45];
    $userid = yield $db->store($user);

    $same_user = yield $db->load('user', $userid);
    print $same_user->id; // id
    print $same_user->name; // jon

    $res = yield $db->find('user', 'name is null');
    foreach($res as $user){
        $user->name = 'new name';
    }
    yield $db->store($res);
});
