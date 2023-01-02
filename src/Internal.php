<?php

namespace AsyncOrm;

use Amp;
use Amp\Mysql;
use function \Amp\call;

class Internal
{
    // create array full of '?' and implode it with ','
    public static function question($num)
    {
        return implode(',', array_fill(0, $num, '?'));
    }

    static function resultSetToArray($resultSet): Amp\Promise
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
     * take resultSet and return the first result 
     */
    static function getOneFromSet($resultSet): Amp\Promise
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

    // https://stackoverflow.com/a/15875555/12893054
    static function uuidv4()
    {
        $data = random_bytes(16);
        assert(strlen($data) == 16);
    
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
