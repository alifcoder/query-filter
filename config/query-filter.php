<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 11:35 AM
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filters
    |--------------------------------------------------------------------------
    |
    | Validation rules applied to the built-in query string parameters that
    | every filter request understands out of the box (pagination, sorting,
    | search, soft-delete toggles, etc).
    |
    */
    'default_filters'           => [
            'active'       => 'bool',
            'active_from'  => 'date',
            'active_to'    => 'date',
            'end_date'     => 'date',
            'export'       => 'bool',
            'is_active'    => 'bool',
            'limit'        => 'integer',
            'only_active'  => 'bool',
            'only_deleted' => 'bool',
            'page'         => 'int',
            'paginate'     => 'boolean',
            'per_page'     => 'int',
            'search'       => 'nullable|array',
            'search.*'     => 'string|nullable',
            'search_type'  => 'string|in:and,or',
            'sequence'     => 'string',
            'short'        => 'boolean',
            'sort'         => 'string',
            'start_date'   => 'date',
            'with_deleted' => 'bool',
            'with_total'   => 'bool',
    ],

    /*
    |--------------------------------------------------------------------------
    | Columns
    |--------------------------------------------------------------------------
    |
    | Logical field names mapped to the actual database column names used by
    | BaseEBFilter's default filter methods (prefix(), index(), isActive(),
    | deletedAt(), createdAt(), updatedAt(), createdBy(), updatedBy()) and by
    | its default search/sort fields below. Override any value here if your
    | tables use different column names — no need to touch the base class.
    |
    */
    'columns'                   => [
            'prefix'        => 'prefix',
            'index'         => 'index',
            'is_active'     => 'is_active',
            'active'        => 'active',
            'action'        => 'action',
            'deleted_at'    => 'deleted_at',
            'created_at'    => 'created_at',
            'updated_at'    => 'updated_at',
            'created_by_id' => 'created_by_id',
            'updated_by_id' => 'updated_by_id',
            'document_date' => 'document_date',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Search Fields
    |--------------------------------------------------------------------------
    |
    | Field keys automatically registered as searchable for every filter, in
    | addition to whatever the filter's own searchFields() returns. Both
    | "prefix-index" and "index" resolve to a concat of the "prefix" and
    | "index" columns above (searching/sorting by "index" alone matches the
    | full prefix-index identifier); any key ending in ".name" (e.g.
    | "created_by.name") resolves against the matching relation in
    | "default_joins" below. Remove a key to stop exposing it as a default
    | search field.
    |
    */
    'default_search_fields'     => [
            'prefix-index',
            'prefix',
            'index',
            'active',
            'action',
            'is_active',
            'created_by.name',
            'updated_by.name',
            'created_at',
            'updated_at',
            'deleted_at',
            'document_date',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Sort Fields
    |--------------------------------------------------------------------------
    |
    | Same idea as "default_search_fields" above, applied to sort().
    |
    */
    'default_sort_fields'       => [
            'prefix-index',
            'index',
            'prefix',
            'active',
            'is_active',
            'created_by.name',
            'updated_by.name',
            'created_at',
            'updated_at',
            'deleted_at',
            'document_date',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Field Filters (BaseEBFilter)
    |--------------------------------------------------------------------------
    |
    | Query parameter => filter definition, automatically merged into every
    | BaseEBFilter's getCallback() (a key returned by the filter's own
    | getCallback() always takes precedence over the same key here).
    | Supported "type" values:
    |   - operation: applies OperationEnum comparisons (eq/ne/gt/gte/lt/lte)
    |                against `column` (resolved through "columns" above,
    |                falls back to the array key itself).
    |   - boolean:   simple `where(column, (bool) $value)`.
    |   - toggle:    calls the given `scope` method on the builder, e.g.
    |                the withDeleted()/onlyDeleted() macros.
    |   - limit:     `limit((int) $value)`.
    |
    | Remove a key (or override it via the filter's own getCallback()) to
    | disable/replace that default filter.
    |
    */
    'default_field_filters'     => [
            'is_active'     => ['type' => 'boolean', 'column' => 'is_active'],
            'prefix'        => ['type' => 'operation', 'column' => 'prefix'],
            'index'         => ['type' => 'operation', 'column' => 'index'],
            'deleted_at'    => ['type' => 'operation', 'column' => 'deleted_at'],
            'created_at'    => ['type' => 'operation', 'column' => 'created_at'],
            'updated_at'    => ['type' => 'operation', 'column' => 'updated_at'],
            'created_by_id' => ['type' => 'operation', 'column' => 'created_by'],
            'updated_by_id' => ['type' => 'operation', 'column' => 'updated_by'],
            'with_deleted'  => ['type' => 'toggle', 'scope' => 'withDeleted'],
            'only_deleted'  => ['type' => 'toggle', 'scope' => 'onlyDeleted'],
            'limit'         => ['type' => 'limit'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Field Filters (BaseQBFilter)
    |--------------------------------------------------------------------------
    |
    | Same idea as "default_field_filters" above, automatically merged into
    | every BaseQBFilter's getCallback(). Supported "type" values: `limit`
    | and `paginate` (reads `per_page`/`page` from the request via
    | getQueryParams()).
    |
    */
    'default_qb_filters'        => [
            'limit'    => ['type' => 'limit'],
            'paginate' => ['type' => 'paginate'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Joins
    |--------------------------------------------------------------------------
    |
    | Relations resolved automatically by checkJoin() and used to build the
    | "created_by.name" / "updated_by.name" default search & sort fields.
    | `{table}` is replaced with the filter's own $this->table at runtime.
    |
    */
    'default_joins'             => [
            'created_by' => [
                    'table'        => 'users as created_by',
                    'first'        => 'created_by.id',
                    'second'       => '{table}.created_by_id',
                    'alias'        => 'created_by',
                    'name_columns' => ['first_name', 'last_name'],
            ],
            'updated_by' => [
                    'table'        => 'users as updated_by',
                    'first'        => 'updated_by.id',
                    'second'       => '{table}.updated_by_id',
                    'alias'        => 'updated_by',
                    'name_columns' => ['first_name', 'last_name'],
            ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Validation Fields
    |--------------------------------------------------------------------------
    |
    | Extra ValidationRuleDTO definitions merged into every FormRequest that
    | uses FilterPrepareForRequestTrait::getFields(). `operations` accepts an
    | array of OperationEnum values (e.g. ['eq', 'ne']) or the string 'all'
    | for every case.
    |
    */
    'default_validation_fields' => [
            ['field' => 'prefix', 'rules' => ['string'], 'operations' => ['eq', 'ne']],
            ['field' => 'index', 'rules' => ['string'], 'operations' => 'all'],
            ['field' => 'deleted_at', 'rules' => ['date'], 'operations' => 'all'],
            ['field' => 'created_at', 'rules' => ['date'], 'operations' => 'all'],
            ['field' => 'updated_at', 'rules' => ['date'], 'operations' => 'all'],
            ['field' => 'created_by_id', 'rules' => ['uuid'], 'operations' => ['eq', 'ne']],
            ['field' => 'updated_by_id', 'rules' => ['uuid'], 'operations' => ['eq', 'ne']],
    ],
];
