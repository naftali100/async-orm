<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use AsyncOrm\ORM;
use Amp\PHPUnit\AsyncTestCase;
use AsyncOrm\OrmObject;

final class MultiDBTest extends AsyncTestCase
{

    // static function asyncSetUpBeforeClass()
    // {
    // }
    protected function setUpAsync()
    {
        ORM::reset();
        yield ORM::connect('localhost', 'user', 'pass', 'db1');
        $this->assertTrue(ORM::isReady());
        yield ORM::connect('localhost', 'user', 'pass', 'db2');
        $this->assertTrue(ORM::isReady());
    }

    public function testSwitchDB()
    {
        ORM::selectDB('db1');
        $user = ORM::create('user');
        $user->name = 'john';
        yield ORM::store($user);

        ORM::selectDB('db2');
        $user = ORM::create('user');
        $user->name = 'john';
        yield ORM::store($user);
    }
}
