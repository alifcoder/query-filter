<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 11:52 AM
 */

namespace Alif\QueryFilter\Abstracts;

use Alif\QueryFilter\DTO\JoinInfoDTO;
use Alif\QueryFilter\Enums\JoinEnum;
use Alif\QueryFilter\Enums\OperationEnum;
use Alif\QueryFilter\Interfaces\EBFilterInterface;
use Alif\QueryFilter\Interfaces\Searchable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class BaseEBFilter implements EBFilterInterface
{
    protected string $table;
    /**
     * @var array
     */
    private array $queryParams;

    /**
     * @param array $queryParams
     *
     * @psalm-api
     */
    public function __construct(array $queryParams)
    {
        $this->queryParams = $queryParams;
    }

    public function apply(Builder $builder): void
    {
        $this->before($builder);

        foreach ($this->getCallback() + $this->getCallbackDefault() as $name => $callback) {
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

    /**
     * @param Builder $builder
     * @param array $queryParams
     *
     * @psalm-suppress PossiblyUnusedParam
     *
     * @return void
     */
    protected function before(Builder $builder): void
    {
    }

    abstract protected function getCallback(): array;

    protected function getReverseCallback(): array
    {
        return [];
    }

    /**
     * @param Builder $builder
     * @param array $queryParams
     *
     * @psalm-suppress PossiblyUnusedParam
     *
     * @return void
     */
    protected function after(Builder $builder): void
    {
    }

    /**
     * @param string $key
     * @param mixed|null $default
     *
     * @return mixed|null
     */
    public function getQueryParams(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * Filter callbacks automatically merged into getCallback(), driven by
     * config('query-filter.default_field_filters'). A key returned by the
     * filter's own getCallback() always takes precedence over the same key
     * here.
     */
    private function getCallbackDefault(): array
    {
        $callbacks = [];

        foreach (config('query-filter.default_field_filters', []) as $key => $definition) {
            $callbacks[$key] = function (Builder $builder, mixed $value, bool $match_all) use ($definition, $key) {
                $this->applyFieldFilter($builder, $definition, $key, $value, $match_all);
            };
        }

        return $callbacks;
    }

    private function applyFieldFilter(Builder $builder, array $definition, string $key, mixed $value, bool $match_all): void
    {
        match ($definition['type']) {
            'operation' => $this->applyOperationFilter(
                    $builder,
                    $this->table . '.' . $this->columnName($definition['column'] ?? $key),
                    (array)$value,
                    $match_all),
            'boolean'   => $builder->where($this->table . '.' . $this->columnName($definition['column'] ?? $key), (bool)$value),
            'toggle'    => $builder->{$definition['scope']}((bool)$value),
            'limit'     => $builder->limit((int)$value),
        };
    }

    private function applyOperationFilter(Builder $builder, string $column, array $value, bool $match_all): void
    {
        $builder->where(function (Builder $query) use ($value, $match_all, $column) {
            foreach ($value as $key => $item) {
                $item      = empty($item) ? null : Arr::wrap($item);
                $operation = OperationEnum::tryFrom($key);
                $operation?->sql($query, $column, $item, $match_all);
            }
        });
    }

    /**
     * Resolve the configured database column name for a logical filter key.
     * Falls back to the key itself when no override is configured.
     */
    protected function columnName(string $key): string
    {
        return config("query-filter.columns.$key", $key);
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
            return;
        }

        // get join info
        $relation = explode('.', $key)[0];
        /** @var JoinInfoDTO $info_dto */
        $info_dto = $this->getJoinTables()[$relation] ?? null;

        // if join info not exists
        if ($info_dto === null) {
            return;
        }

        // check already joined or not
        $available_joins = $builder->getQuery()->joins;
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
        $searchFields = $this->searchFields($search) + $this->searchFieldsDefault($search);

        return $searchFields[$key] ?? throw new \Exception(
                message: __('query.field_does_not_exists', ['field' => $key]),
                code:    400);
    }

    private function searchFieldsDefault(string $search): array
    {
        $fields = [];

        foreach (config('query-filter.default_search_fields', []) as $key) {
            $fields[$key] = $this->resolveDefaultField($key, $search);
        }

        return array_filter($fields, fn (mixed $field) => $field !== null);
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
        $searchFields = $this->sortFields() + $this->sortFieldsDefault();

        return $searchFields[$column] ?? throw new \Exception(
                message: __('query.field_does_not_exists', ['field' => $column]),
                code:    400);
    }

    private function getJoinTables(): array
    {
        return $this->joinTables() + $this->joinTablesDefault();
    }

    abstract protected function sortFields(): array;

    abstract protected function joinTables(): array;

    private function sortFieldsDefault(): array
    {
        $fields = [];

        foreach (config('query-filter.default_sort_fields', []) as $key) {
            $fields[$key] = $this->resolveDefaultField($key);
        }

        return array_filter($fields, fn (mixed $field) => $field !== null);
    }

    /**
     * Resolve a default search/sort field descriptor for the given key.
     * Pass $search to build a search-context field (whereILike closure);
     * omit it to build a sort-context field (orderByRaw closure).
     */
    private function resolveDefaultField(string $key, ?string $search = null): mixed
    {
        if ($key === 'prefix-index' || $key === 'index') {
            return $this->concatColumns($this->columnName('prefix'), $this->columnName('index'));
        }

        if (Str::endsWith($key, '.name')) {
            return $this->relationNameField(Str::beforeLast($key, '.name'), $search);
        }

        return $this->table . '.' . $this->columnName($key);
    }

    private function concatColumns(string ...$columns): \Illuminate\Database\Query\Expression
    {
        $qualified = array_map(fn (string $column) => $this->table . '.' . $column, $columns);

        return DB::raw("concat(" . implode(", '-', ", $qualified) . ")");
    }

    private function relationNameField(string $relation, ?string $search): ?Closure
    {
        $join = config("query-filter.default_joins.$relation");
        if ($join === null) {
            return null;
        }

        $alias   = $join['alias'] ?? $relation;
        $columns = $join['name_columns'] ?? ['first_name', 'last_name'];
        $raw     = "concat_ws(' ', " . implode(', ', array_map(fn (string $c) => "$alias.$c", $columns)) . ")";

        if ($search === null) {
            return function (Builder $builder, string $direction) use ($raw) {
                $builder->orderByRaw($raw . ' ' . $direction);
            };
        }

        return function (Builder $builder) use ($raw, $search) {
            $builder->whereILike(DB::raw($raw), $search);
        };
    }

    private function joinTablesDefault(): array
    {
        $joins = [];

        foreach (config('query-filter.default_joins', []) as $relation => $join) {
            $joins[$relation] = new JoinInfoDTO(
                    table:  $join['table'],
                    first:  $join['first'],
                    second: str_replace('{table}', $this->table, $join['second']),
            );
        }

        return $joins;
    }

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