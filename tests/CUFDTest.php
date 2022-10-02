<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use async_orm\ORM;
use Amp\PHPUnit\AsyncTestCase;
use async_orm\OrmObject;

final class CUFDTest extends AsyncTestCase
{

    protected function setUpAsync()
    {
        yield ORM::connect('localhost', 'naftali', 'linux1221', 'telegram');
        $this->assertTrue(ORM::isReady());
    }

    public function testCreate(){
        $user = ORM::create('user');
        $user->name = 'john';
        $id = yield ORM::store($user);
        $this->assertIsNumeric($id);

        $reload = yield ORM::load('user', $id);
        $this->assertFalse($reload->getMeta('created'));
        $this->assertEquals($id, $reload->id);
    }
    function testFind(){
        $res = yield ORM::find('user', 'name =?', ['john']);
        $this->assertNotEmpty($res);
        $this->assertInstanceOf(OrmObject::class, $res[0]);
    }
    function testLoad(){
        $res = yield ORM::load('user', 1);
        $this->assertInstanceOf(OrmObject::class, $res);
    }
    function testUpdate(){
        $res = yield ORM::find('user');
        $res = $res[0];
        
        $newName = 'avi_' . time();
        $res->name = $newName;
        yield ORM::store($res);

        $newRes = yield ORM::reload($res);
        $this->assertEquals($newName, $newRes->name);
    }
    function testDelete(){
        $forTest = ORM::create('user');
        $forTest->name = 'John';
        $id = yield ORM::store($forTest);
        
        yield ORM::trash($forTest);
        $newRes = yield ORM::load('user', $id);
        $this->assertTrue($newRes->getMeta('created'));
        $this->assertEquals(0, $newRes->id);
    }

    function testCache(){
        $user = ORM::create('user');
        $user->name = 'John';
        yield ORM::store($user);
        $user->name = 'John1';
        yield ORM::store($user);
    }
