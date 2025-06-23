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
    public const string PREFIX       = 'prefix';
    public const string INDEX        = 'index';
    public const string IS_ACTIVE    = 'is_active';
    public const string DELETED_AT   = 'deleted_at';
    public const string CREATED_AT   = 'created_at';
    public const string UPDATED_AT   = 'updated_at';
    public const string CREATED_BY   = 'created_by';
    public const string UPDATED_BY   = 'updated_by';
    public const string SORT         = 'sort';
    public const string LIMIT        = 'limit';
    public const string ONLY_DELETED = 'only_deleted';
    public const string WITH_DELETED = 'with_deleted';


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

    public function prefixIndex(Builder $builder, array $value): void
    {
        $builder->whereRaw("concat(prefix, '-', index) where ilike '%?%'", [$value]);
    }

    public function withDeleted(Builder $builder, bool $value = false): void
    {
        $builder->withDeleted($value);
    }

    public function onlyDeleted(Builder $builder, bool $value): void
    {
        $builder->onlyDeleted($value);
    }

    public function limit(Builder $builder, int $value): void
    {
        $builder->limit($value);
    }

    public function isActive(Builder $builder, bool $value): void
    {
        $builder->where($this->table . '.is_active', $value);
    }

    public function prefix(Builder $builder, array $value, bool $match_all): void
    {
        $builder->where(function (Builder $query) use ($value, $match_all) {
            foreach ($value as $key => $item) {
                $item      = empty($item) ? null : Arr::wrap($item);
                $operation = OperationEnum::tryFrom($key);
                $operation?->sql($query, $this->table . '.prefix', $item, $match_all);
            }
        });
    }

    public function index(Builder $builder, array $value, bool $match_all): void
    {
        $builder->where(function (Builder $query) use ($value, $match_all) {
            foreach ($value as $key => $item) {
                $item      = empty($item) ? null : Arr::wrap($item);
                $operation = OperationEnum::tryFrom($key);
                $operation?->sql($query, $this->table . '.index', $item, $match_all);
            }
        });
    }

    public function deletedAt(Builder $builder, array $value, bool $match_all): void
    {
        $builder->where(function (Builder $query) use ($value, $match_all) {
            foreach ($value as $key => $item) {
                $item      = empty($item) ? null : Arr::wrap($item);
                $operation = OperationEnum::tryFrom($key);
                $operation?->sql($query, $this->table . '.deleted_at', $item, $match_all);
            }
        });
    }

    public function createdAt(Builder $builder, array $value, bool $match_all): void
    {
        $builder->where(function (Builder $query) use ($value, $match_all) {
            foreach ($value as $key => $item) {
                $item      = empty($item) ? null : Arr::wrap($item);
                $operation = OperationEnum::tryFrom($key);
                $operation?->sql($query, $this->table . '.created_at', $item, $match_all);
            }
        });
    }

    public function updatedAt(Builder $builder, array $value, bool $match_all): void
    {
        $builder->where(function (Builder $query) use ($value, $match_all) {
            foreach ($value as $key => $item) {
                $item      = empty($item) ? null : Arr::wrap($item);
                $operation = OperationEnum::tryFrom($key);
                $operation?->sql($query, $this->table . '.updated_at', $item, $match_all);
            }
        });
    }

    public function createdBy(Builder $builder, array $value, bool $match_all): void
    {
        $builder->where(function (Builder $query) use ($value, $match_all) {
            foreach ($value as $key => $item) {
                $item      = empty($item) ? null : Arr::wrap($item);
                $operation = OperationEnum::tryFrom($key);
                $operation?->sql($query, $this->table . '.created_by', $item, $match_all);
            }
        });
    }

    public function updatedBy(Builder $builder, array $value, bool $match_all): void
    {
        $builder->where(function (Builder $query) use ($value, $match_all) {
            foreach ($value as $key => $item) {
                $item      = empty($item) ? null : Arr::wrap($item);
                $operation = OperationEnum::tryFrom($key);
                $operation?->sql($query, $this->table . '.updated_by', $item, $match_all);
            }
        });
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
        return [
                'prefix-index'    => DB::raw("concat(" . $this->table . ".prefix, '-', " . $this->table . ".index)"),
                'prefix'          => $this->table . '.prefix',
                'index'           => DB::raw("concat(" . $this->table . ".prefix, '-', " . $this->table . ".index)"),
                'active'          => $this->table . '.active',
                'action'          => $this->table . '.action',
                'is_active'       => $this->table . '.is_active',
                'created_by.name' => function (Builder $builder) use ($search) {
                    $builder->whereILike(\DB::raw("concat_ws(' ', created_by.first_name, created_by.last_name)"), $search);
                },
                'updated_by.name' => function (Builder $builder) use ($search) {
                    $builder->whereILike(\DB::raw("concat_ws(' ', updated_by.first_name, updated_by.last_name)"), $search);
                },
                'created_at'      => $this->table . '.created_at',
                'updated_at'      => $this->table . '.updated_at',
                'deleted_at'      => $this->table . '.deleted_at',
                'document_date'   => $this->table . '.document_date',
        ];
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
        return [
                'prefix-index'    => DB::raw("concat(" . $this->table . ".prefix, '-', " . $this->table . ".index)"),
                'index'           => DB::raw("concat(" . $this->table . ".prefix, '-', " . $this->table . ".index)"),
                'prefix'          => $this->table . '.prefix',
                'active'          => $this->table . '.active',
                'is_active'       => $this->table . '.is_active',
                'created_by.name' => function (Builder $builder, string $direction) {
                    $builder->orderByRaw("concat_ws(' ', created_by.first_name, created_by.last_name) " . $direction);
                },
                'updated_by.name' => function (Builder $builder, string $direction) {
                    $builder->orderByRaw("concat_ws(' ', updated_by.first_name, updated_by.last_name) " . $direction);
                },
                'created_at'      => $this->table . '.created_at',
                'updated_at'      => $this->table . '.updated_at',
                'deleted_at'      => $this->table . '.deleted_at',
                'document_date'   => $this->table . '.document_date',
        ];
    }

    private function joinTablesDefault(): array
    {
        return [
                'created_by' => new JoinInfoDTO(
                        table:  'users as updated_by',
                        first:  'created_by.id',
                        second: $this->table . '.created_by'),
                'updated_by' => new JoinInfoDTO(
                        table:  'users as updated_by',
                        first:  'updated_by.id',
                        second: $this->table . '.updated_by'),
        ];
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