<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 12:02 PM
 */

namespace Alif\QueryFilter\Enums;

enum JoinEnum: string
{
    case INNER = 'join';
    case LEFT = 'leftJoin';
    case RIGHT = 'rightJoin';
    case CROSS = 'crossJoin';
    case OUTER = 'outerJoin';

    public static function getValues(): array
    {
        $arr = [];
        foreach (static::cases() as $case) {
            $arr[] = $case->value;
        }

        return $arr;
    }

    public static function getValuesAsString(): string
    {
        return implode(',', self::getValues());
    }
}
