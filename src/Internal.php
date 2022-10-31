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
}
