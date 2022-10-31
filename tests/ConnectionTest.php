<?php

declare(strict_types=1);

use AsyncOrm\ORM;
use Amp\PHPUnit\AsyncTestCase;

final class ConnectionTest extends AsyncTestCase
{

    public function testConnection()
    {
        ORM::reset();
        yield ORM::connect('localhost', 'user', 'pass', 'db');
        $this->assertTrue(ORM::isReady());
    }
}
