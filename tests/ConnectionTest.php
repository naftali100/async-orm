<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use async_orm\ORM;
use Amp\PHPUnit\AsyncTestCase;

final class ConnectionTest extends AsyncTestCase
{

    protected function setUpAsync()
    {
        yield ORM::connect('localhost', 'naftali', 'linux1221', 'telegram');
        $this->assertTrue(ORM::isReady());
    }

    protected function tearDownAsync(){
        // Amp\PHPUnit\AsyncTestCase::setTimeout(1); // for destructor to complete
    }

    public function testQuery(){
        $user = ORM::create('user');
        $user->name2 = 'john';
        $res = yield ORM::store($user);
        $this->assertIsNumeric($res);
    }
}


// Amp\Loop::run(function () {
//     $db = yield orm::connect('127.0.0.1', 'user', 'pass', 'db');

//     $user = $db->create('user');
//     $user->name = 'jon';
//     $user->age = 30;
//     $user->data = ['sity' => 'NY', 'hight' => 45];
//     $userid = yield $db->store($user);

//     $same_user = yield $db->load('user', $userid);
//     print $same_user->id; // id
//     print $same_user->name; // jon

//     $res = yield $db->find('user', 'name is null');
//     foreach ($res as $user) {
//         $user->name = 'new name';
//     }
//     yield $db->store($res);
// });
