<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 11:52 AM
 */

namespace Alif\QueryFilter\Abstracts;

use Alif\QueryFilter\DTO\JoinInfoDTO;
use Alif\QueryFilter\Enums\JoinEnum;
use Alif\QueryFilter\Interfaces\QBFilterInterface;
use Alif\QueryFilter\Interfaces\Searchable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

abstract class BaseQBFilter implements QBFilterInterface
{
    public const string SORT     = 'sort';
    public const string LIMIT    = 'limit';
    public const string PAGINATE = 'paginate';


    protected string $table;

    private array $queryParams;

    public function __construct(array $queryParams)
    {
        $this->queryParams = $queryParams;
    }

    public function apply(Builder $builder): void
    {
        $this->before($builder);

        foreach ($this->getCallback() as $name => $callback) {
            if (isset($this->queryParams[$name])) {
                call_user_func($callback, $builder, $this->queryParams[$name], true);
            }

            $match_any_key = '-' . $name;
            if (isset($this->queryParams[$match_any_key])) {
                call_user_func($callback, $builder, $this->queryParams[$match_any_key], false);
            }
        }

        $this->after($builder);
    }

    protected function before(Builder $builder): void
    {
    }

    abstract protected function getCallback(): array;

    protected function getReverseCallback(): array
    {
        return [];
    }

    protected function after(Builder $builder): void
    {
    }

    public function getQueryParams(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function paginate(Builder $builder, bool $value): void
    {
        if ($value) {
            $per_page = $this->getQueryParams('per_page', config('app.per_page'));
            $page     = $this->getQueryParams('page', config('app.page'));
            $builder->limit($per_page)->offset(($page - 1) * $per_page);
        }
    }

    public function limit(Builder $builder, int $value): void
    {
        $builder->limit($value);
    }

    public function search(Builder $builder, array $value): void
    {
        // check joins with tables
        foreach ($value as $key => $search) {
            if (!isset($search)) {
                continue;
            }
            $this->checkJoin($builder, $key, JoinEnum::INNER);
        }

        // search
        $builder->when($this instanceof Searchable,
                function (Builder $query) use ($value) {
                    $searchType = (string)$this->getQueryParams(Searchable::SEARCH_TYPE, 'OR');
                    $whereLike  = $searchType === 'and' ? 'where' : 'orWhere';
                    $query->where(function (Builder $q) use ($value, $whereLike, $searchType) {
                        foreach ($value as $key => $search) {
                            if (!isset($search)) {
                                continue;
                            }
                            $search = trim($search);
                            $field  = $this->getSearchField($key, $search);
                            $q->when(is_callable($field),
                                    function (Builder $q) use ($field, $searchType) {
                                        $q->where(column: $field,
                                                boolean:  $searchType);
                                    }, function (Builder $q) use ($field, $search, $whereLike) {
                                        $q->{$whereLike}($field, 'ilike', "%$search%");
                                    });
                        }
                    });
                });
    }

    public function checkJoin(Builder $builder, string $key, JoinEnum $join_type): void
    {
        // check $key is relation
        if (str_contains($key, '.') === false) {
            $relation = explode('.', $key)[0];
        } else {
            $relation = $key;
        }

        /** @var JoinInfoDTO $info_dto */
        $info_dto = $this->getJoinTables()[$relation] ?? null;

        // if join info not exists
        if ($info_dto === null) {
            return;
        }

        // check already joined or not
        $available_joins = $builder->joins;
        if (empty($available_joins)) {
            $builder->{$join_type->value}($info_dto->table, $info_dto->first, '=', $info_dto->second);
        } else {
            $table_name  = Str::contains($info_dto->table, ' as') ? explode(' as', $info_dto->table)[1] : $info_dto->table;
            $join_exists = false;
            foreach ($available_joins as $join) {
                if ($join->table === $table_name &&
                        (
                                ($join->wheres[0]['first'] === $info_dto->first && $join->wheres[0]['second'] === $info_dto->second) ||
                                ($join->wheres[0]['first'] === $info_dto->second && $join->wheres[0]['second'] === $info_dto->first)
                        )
                ) {
                    $join_exists = true;
                    break;
                }
            }
            if (!$join_exists) {
                $builder->{$join_type->value}($info_dto->table, $info_dto->first, '=', $info_dto->second);
            }
        }
    }

    /**
     * @param string $key
     * @param string $search
     *
     * @psalm-suppress UndefinedMethod
     *
     * @return mixed
     * @throws \Exception
     */
    private function getSearchField(string $key, string $search): mixed
    {
        $searchFields = $this->searchFields($search);

        return $searchFields[$key] ?? throw new \Exception(__('messages.field_does_not_exists', ['field' => $key]), 400);
    }

    public function sort(Builder $builder, string $value): void
    {
        foreach (explode(',', $value) as $column) {
            // check joins with tables
            $this->checkJoin($builder, str_replace('-', '', $column), JoinEnum::LEFT);

            // Sort direction
            $direction = Str::startsWith($column, '-') ? 'desc' : 'asc';
            $column    = Str::startsWith($column, '-') ? Str::after($column, '-') : $column;
            $sortField = $this->getSortField($column);

            if ($sortField instanceof Closure) {
                // If the sort field is a callable, execute it
                $sortField($builder, $direction);
            } else {
                // Otherwise, apply the orderBy to the builder
                $builder->orderBy($sortField, $direction);
            }
        }
    }

    private function getSortField(string $column): mixed
    {
        $searchFields = $this->sortFields();

        return $searchFields[$column] ?? throw new \Exception(__('messages.field_does_not_exists', ['field' => $column]), 400);
    }

    private function getJoinTables(): array
    {
        return $this->joinTables();
    }

    abstract protected function sortFields(): array;

    abstract protected function joinTables(): array;

    /**
     * @param string $key
     *
     * @return bool
     * @psalm-suppress UndefinedMethod
     */
    public function hasKeyParams(string $key): bool
    {
        return array_key_exists($key, $this->queryParams);
    }

    /**
     * @param string[] $keys
     *
     * @psalm-suppress UndefinedMethod
     */
    public function hasAnyKeyParams(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if ($this->hasKeyParams($key)) {
                return true;
            }
        }

        return false;
    }
}