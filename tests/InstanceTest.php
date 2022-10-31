<?php

declare(strict_types=1);

use AsyncOrm\ORM;
use Amp\PHPUnit\AsyncTestCase;
use AsyncOrm\OrmObject;

final class InstanceTest extends AsyncTestCase
{
    public function testInstance()
    {
        ORM::reset();

        yield ORM::connect('localhost', 'user', 'pass', 'db1');
        $orm = new ORM;
        $res1 = yield $orm->findOne('table1');
        $this->assertNotNull($res1);

        yield ORM::connect('localhost', 'user', 'pass', 'db2');
        $orm1 = new ORM;
        $res2 = yield $orm1->findOne('table2');
        $this->assertNotNull($res2);

        ORM::selectDB('db1');
        $res3 = yield $orm1->findOne('table2');
        $this->assertNotNull($res3);
    }
}
