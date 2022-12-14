<?php

namespace AsyncOrm\Driver;

use AsyncOrm\Driver;

use function Amp\call;
use Amp\Promise;
use Amp\Mysql;

class MysqlDriver extends Driver
{

    /**
     * @var Mysql\Connection
     */
    private $db;

    public function __construct(Mysql\Connection $db)
    {
        $this->db = $db;
        $this->initCache();
    }

    public static function createDriver($host, $user, $pass, $db): Promise
    {
        return call(function () use ($host, $user, $pass, $db) {
            $config = Mysql\ConnectionConfig::fromString("host=$host;user=$user;pass=$pass;db=$db");
            $db = yield Mysql\connect($config);

            return new MysqlDriver($db);
        });
    }

    public function isReady(): bool
    {
        return $this->db->isReady() && $this->db->isAlive();
    }
    public function execute($sql, $bindings): Promise
    {
        if (!$this->isReady()) {
            throw new \Error('mysql db is not ready');
        }

        return $this->db->execute($sql, $bindings);
    }
    public function query($sql): Promise
    {
        if (!$this->isReady()) {
            throw new \Error('mysql db is not ready');
        }

        return $this->db->query($sql);
    }
}
