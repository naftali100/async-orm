<?php

namespace async_orm;

use Amp;
use function Amp\call;

class SchemaHelper
{

    /**
     * Data types
     */
    const C_DATATYPE_BOOL             = 0;
    const C_DATATYPE_UINT32           = 2;
    const C_DATATYPE_DOUBLE           = 3;
    const C_DATATYPE_TEXT7            = 4; //InnoDB cant index varchar(255) utf8mb4 - so keep 191 as long as possible
    const C_DATATYPE_TEXT8            = 5;
    const C_DATATYPE_TEXT16           = 6;
    const C_DATATYPE_TEXT32           = 7;
    const C_DATATYPE_SPECIAL_DATE     = 80;
    const C_DATATYPE_SPECIAL_DATETIME = 81;
    const C_DATATYPE_SPECIAL_TIME     = 83;  //MySQL time column (only manual)
    const C_DATATYPE_SPECIAL_POINT    = 90;
    const C_DATATYPE_SPECIAL_LINESTRING = 91;
    const C_DATATYPE_SPECIAL_POLYGON    = 92;
    const C_DATATYPE_SPECIAL_MONEY      = 93;
    const C_DATATYPE_SPECIAL_JSON       = 94;  //JSON support (only manual)

    const C_DATATYPE_SPECIFIED        = 99;   

    /** get sql type from data */
    static function codeFromData($data, $s)
    {
        return self::code(self::scanType($data, $s));
    }

    /** get sql code name for typeno */
    static function code($typeno)
    {
        $typeno_sqltype = array(
            self::C_DATATYPE_BOOL             => ' TINYINT(1) UNSIGNED ',
            self::C_DATATYPE_UINT32           => ' INT(11) UNSIGNED ',
            self::C_DATATYPE_DOUBLE           => ' DOUBLE ',
            self::C_DATATYPE_TEXT7            => ' VARCHAR(191) ',
            self::C_DATATYPE_TEXT8               => ' VARCHAR(255) ',
            self::C_DATATYPE_TEXT16           => ' TEXT ',
            self::C_DATATYPE_TEXT32           => ' LONGTEXT ',
            self::C_DATATYPE_SPECIAL_DATE     => ' DATE ',
            self::C_DATATYPE_SPECIAL_DATETIME => ' DATETIME ',
            self::C_DATATYPE_SPECIAL_TIME     => ' TIME ',
            self::C_DATATYPE_SPECIAL_POINT    => ' POINT ',
            self::C_DATATYPE_SPECIAL_LINESTRING => ' LINESTRING ',
            self::C_DATATYPE_SPECIAL_POLYGON => ' POLYGON ',
            self::C_DATATYPE_SPECIAL_MONEY    => ' DECIMAL(10,2) ',
            self::C_DATATYPE_SPECIAL_JSON     => ' JSON '
        );
        return $typeno_sqltype[$typeno];
    }

    // value to typeno
    public static function scanType($value, $flagSpecial = FALSE): int
    {
        if (is_null($value)) return self::C_DATATYPE_BOOL;
        if ($value === INF) return self::C_DATATYPE_TEXT7;

        if ($flagSpecial) {
            if (preg_match('/^-?\d+\.\d{2}$/', $value)) {
                return self::C_DATATYPE_SPECIAL_MONEY;
            }
            if (preg_match('/^\d{4}\-\d\d-\d\d$/', $value)) {
                return self::C_DATATYPE_SPECIAL_DATE;
            }
            if (preg_match('/^\d{4}\-\d\d-\d\d\s\d\d:\d\d:\d\d$/', $value)) {
                return self::C_DATATYPE_SPECIAL_DATETIME;
            }
            if (preg_match('/^POINT\(/', $value)) {
                return self::C_DATATYPE_SPECIAL_POINT;
            }
            if (preg_match('/^LINESTRING\(/', $value)) {
                return self::C_DATATYPE_SPECIAL_LINESTRING;
            }
            if (preg_match('/^POLYGON\(/', $value)) {
                return self::C_DATATYPE_SPECIAL_POLYGON;
            }
            if (self::isJSON($value)) {
                return self::C_DATATYPE_SPECIAL_JSON;
            }
        }

        //setter turns TRUE FALSE into 0 and 1 because database has no real bools (TRUE and FALSE only for test?).
        if ($value === FALSE || $value === TRUE || $value === '0' || $value === '1' || $value === 0 || $value === 1) {
            return self::C_DATATYPE_BOOL;
        }

        if (is_float($value)) return self::C_DATATYPE_DOUBLE;

        if (!self::startsWithZeros($value)) {

            if (is_numeric($value) && (floor($value) == $value) && $value >= 0 && $value <= 4294967295) {
                return self::C_DATATYPE_UINT32;
            }

            if (is_numeric($value)) {
                return self::C_DATATYPE_DOUBLE;
            }
        }

        if (mb_strlen($value, 'UTF-8') <= 191) {
            return self::C_DATATYPE_TEXT7;
        }

        if (mb_strlen($value, 'UTF-8') <= 255) {
            return self::C_DATATYPE_TEXT8;
        }

        if (mb_strlen($value, 'UTF-8') <= 65535) {
            return self::C_DATATYPE_TEXT16;
        }

        return self::C_DATATYPE_TEXT32;
    }

    private static function isJSON($value): bool
    {
        return (is_string($value) &&
            is_array(json_decode($value, TRUE)) &&
            (json_last_error() == JSON_ERROR_NONE));
    }

    private static function startsWithZeros($value): bool
    {
        $value = strval($value);

        return strlen($value) > 1 && strpos($value, '0') === 0 && strpos($value, '0.') !== 0;
    }
}
