<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use AsyncOrm\ORM;
use Amp\PHPUnit\AsyncTestCase;
use AsyncOrm\OrmObject;

final class CURDTest extends AsyncTestCase
{

    protected function setUpAsync()
    {
        ORM::reset();
        yield ORM::connect('localhost', 'user', 'pass', 'db');
        $this->assertTrue(ORM::isReady());
    }

    public function testCreate()
    {
        $user = ORM::create('user');
        $user->name = 'john';
        $id = yield ORM::store($user);
        $this->assertIsNumeric($id);

        $reload = yield ORM::load('user', $id);
        $this->assertFalse($reload->getMeta('created'));
        $this->assertEquals($id, $reload->id);
    }
    function testFind()
    {
        $res = yield ORM::find('user', 'name =?', ['john']);
        $this->assertNotEmpty($res);
        $this->assertInstanceOf(OrmObject::class, $res[0]);
    }
    function testFindOne()
    {
        $res = yield ORM::findOne('user', 'name =?', ['john']);
        $this->assertNotEmpty($res);
        $this->assertInstanceOf(OrmObject::class, $res);
        $this->assertEquals('john', $res->name);
    }
    function testLoad()
    {
        $res = yield ORM::load('user', 1);
        $this->assertInstanceOf(OrmObject::class, $res);
    }
    function testUpdate()
    {
        $res = yield ORM::find('user');
        $res = $res[0];

        $newName = 'avi_' . time();
        $res->name = $newName;
        yield ORM::store($res);

        $res->name = 111;

        $newRes = yield $res->reload();
        $this->assertEquals($newName, $newRes->name);
    }
    public function testDelete()
    {
        $forTest = ORM::create('user');
        $forTest->name = 'John';
        $id = yield ORM::store($forTest);

        yield ORM::trash($forTest);
        $newRes = yield ORM::load('user', $id);
        $this->assertTrue($newRes->getMeta('created'));
        $this->assertEquals(0, $newRes->id);
    }

    function testCache()
    {
        $user = ORM::create('user');
        $user->name = 'John';
        yield ORM::store($user);
        $user->name = 'John1';
        yield ORM::store($user);
    }
    // function testAddCol(){
    //     $user = ORM::create('user');
    // TODO: check why without name it errors
    //     $user->{'name_'.time()} = 'John';
    //     print_r($user);
    //     yield ORM::store($user);
    // }

    public function testArrayValue()
    {
        $res = ORM::create('user');
        $res->new_data = ['hello' => 'world'];
        yield ORM::store($res);
        $new = yield $res->reload();
        $this->assertJsonStringEqualsJsonString($new->new_data, json_encode(['hello' => 'world']));
    }
}
