<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 11:55 AM
 */

namespace Alif\QueryFilter\Enums;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

enum OperationEnum: string
{
    case Equal = 'eq';
    case NotEqual = 'ne';
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';

    public function sql(Builder|QueryBuilder $builder, string $column, ?array $value, bool $match_all): void
    {
        switch ($this) {
            case (self::Equal):
            {
                if ($value === null) {
                    $builder->{$match_all ? 'whereNull' : 'orWhereNull'}($column);
                } else {
                    $builder->{$match_all ? 'whereIn' : 'orWhereIn'}($column, $value);
                }
                break;
            }
            case (self::NotEqual):
            {
                if ($value === null) {
                    $builder->{$match_all ? 'whereNotNull' : 'orWhereNotNull'}($column);
                } else {
                    if (str_contains($column, '_id')) {
                        $builder->{$match_all ? 'where' : 'orWhere'}(function (Builder $query) use ($column, $value) {
                            $query->whereNotIn($column, $value)->whereNull($column);
                        });
                    } else {
                        $builder->{$match_all ? 'whereNotIn' : 'orWhereNotIn'}($column, $value);
                    }
                }
                break;
            }
            case (self::GreaterThan):
            {
                $builder->{$match_all ? 'where' : 'orWhere'}($column, '>', $value[0]);
                break;
            }
            case (self::GreaterThanOrEqual):
            {
                $builder->{$match_all ? 'where' : 'orWhere'}($column, '>=', $value[0]);
                break;
            }
            case (self::LessThan):
            {
                $builder->{$match_all ? 'where' : 'orWhere'}($column, '<', $value[0]);
                break;
            }
            case (self::LessThanOrEqual):
            {
                $builder->{$match_all ? 'where' : 'orWhere'}($column, '<=', $value[0]);
                break;
            }
        }
    }

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
