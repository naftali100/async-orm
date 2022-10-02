<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use async_orm\ORM;
use Amp\PHPUnit\AsyncTestCase;

final class ConnectionTest extends AsyncTestCase
{

     function testConnection()
    {
        yield ORM::connect('localhost', 'user', 'pass', 'db');
        $this->assertTrue(ORM::isReady());
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
