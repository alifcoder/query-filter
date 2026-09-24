# Upgrade from v1.1.6 to v2.0.0

Version 2 is a **major release with breaking changes**. The callback-based API
has been replaced by explicit field definitions, with no compatibility layer.
This guide compares the released `v1.1.6` tag with the v2 API.

## 1. Update the runtime and dependency

Use PHP **8.3 or higher** and Laravel **11 or higher**. The package now declares
PHP 8.3 as its minimum because typed class constants, such as
`public const string SEARCH = 'search';`, require PHP 8.3. Update web servers,
CLI tools, queue workers, CI, and any Composer `config.platform.php` override.
Your chosen Laravel version may require a newer PHP version.

Update the application dependency in a development branch:

```sh
composer require 'alifcoder/query-filter:^2.0' --with-all-dependencies
composer check-platform-reqs
```

Commit the resulting application lockfile after completing the migration and
tests below. No package database migration is required.

## 2. Replace callback methods with a model filter

`Alif\QueryFilter\Abstracts\BaseEBFilter` and `BaseQBFilter` retain their names,
but now require `protected function fields(): array`. Create a concrete filter
for each model or table. Do not instantiate either abstract base directly.

```php
namespace App\Filters; // Keep application filters together.

use Alif\QueryFilter\Abstracts\BaseEBFilter; // Use the Eloquent filter base.
use Alif\QueryFilter\Enums\FilterOperator; // Declare PHP operator policies with enum cases.
use Alif\QueryFilter\Field; // Describe each allowed public field.

class ProductFilter extends BaseEBFilter
{
    protected static array $with = ['brand']; // Load this relation when products are retrieved.

    /** Declare the columns and operations clients may request. */
    protected function fields(): array
    {
        return [ // Public names are the request allowlist.
            'id' => Field::make('id')->operators([FilterOperator::Equal, FilterOperator::In]), // Permit exact IDs and ID lists.
            'name', // Permit filtering, searching, and sorting this column.
            'status' => Field::make('status')->operators([FilterOperator::Equal, FilterOperator::NotEqual])->rules(['string']), // Validate each status operand.
            'brand.name', // Resolve the declared Eloquent relation for related queries.
            'created_at', // Allow an explicit stable sort with id.
        ];
    }
}
```

A string definition enables filtering, searching, and sorting. Use
`Field::searchable(false)`, `sortable(false)`, or `filterable(false)` to preserve
the narrower permissions of an existing endpoint. An omitted operator policy
allows all built-in operators; an empty policy allows none. PHP policies must
contain `FilterOperator` cases, rather than strings such as `['eq', 'ne']`.
HTTP and JSON operator names remain strings.

| v1.1.6 integration | v2 replacement |
| --- | --- |
| `getCallback()` and configured default callbacks | `fields()`; use `Field::custom()` for application predicates. |
| `Searchable`, `searchFields()`, `sortFields()` | One field map with explicit search/sort permissions. |
| `joinTables()`, `JoinInfoDTO`, `JoinEnum`, `checkJoin()` | Eloquent relation paths, or caller-owned Query Builder joins. |
| `OperationEnum` | `Enums\FilterOperator`. Use `cases()` or each case's `value` when building application metadata. |
| `ValidationRuleDTO`, `FilterPrepareForRequestTrait` | Field `rules()`/`transform()`, normal FormRequest rules, and optional filter `prepare()`/`rules()` hooks. |
| `$table`, `columnName()`, package column configuration | Explicit field paths; model-owned `queryFilterColumns()` for JSON attributes. |
| `getQueryParams()`, `hasKeyParams()`, `hasAnyKeyParams()` | Use `prepare(array $parameters)` for input normalization or inject explicit application context. |
| `getReverseCallback()` | No equivalent hook; declare the intended operators and boolean groups explicitly. |
| `after()` | Configure the caller's builder, or declare filter eager loads as described below. |

The constructor is now `__construct(array $parameters = [], ?FilterLimits $limits = null)`.
Positional `new ProductFilter($input)` calls still work after migrating the class.
Change named `queryParams:` arguments to `parameters:`. If a subclass adds
dependencies, accept `$parameters` and pass it to `parent::__construct()`.

Existing instance-based model calls remain supported:

```php
// The model continues to use Alif\QueryFilter\Traits\Filterable.
$filter = new ProductFilter($request->query()); // Pass query input explicitly.
$products = Product::filter($filter)->paginate(25); // Retrieve only after applying the filter.
```

Alternatively, set `protected $filterClass = ProductFilter::class` on the model
and use `Product::filter()`. Class resolution uses Laravel's container and HTTP
query parameters. `Product::filter(ProductFilter::class, [])` explicitly supplies
empty input; JSON bodies are never merged implicitly. For a JSON endpoint, pass
its validated payload explicitly.

## 3. Migrate client request shapes

Move top-level field operations into `filter`. Keep scalar comparison operands
as scalars; remove the old request-wrapping trait. The supported forms include:

| Old request | New request |
| --- | --- |
| `status[eq]=draft` | `filter[status][eq]=draft` |
| `id[eq][]=1&id[eq][]=2` | `filter[id][in][]=1&filter[id][in][]=2` |
| `created_at[gte][]=2026-01-01` | `filter[created_at][gte]=2026-01-01` |
| `is_active=1` | Declare `is_active`, then send `filter[is_active][eq]=1`. |
| `search[name]=coffee` | Same shape, with `name` explicitly searchable. |
| `sort=-created_at,id` | Same shape, with both fields explicitly sortable. |

Old `-field` keys selected the OR form of callback operations; they were **not
negation operators**. They have no meaning in the new request API. Translate
the intended boolean logic to the explicit `where` tree. For example:

```json
{
  "where": {
    "or": [
      {"field": "status", "operator": "eq", "value": "draft"},
      {"field": "status", "operator": "eq", "value": "review"}
    ]
  }
}
```

Use `ne` or `nin` for exclusions. Multiple operators in one `filter` field map
are ANDed; `filter`, `where`, and `search` are also ANDed together. Unknown fields
or operators inside these structures fail validation. Old top-level field keys
are no longer filtering instructions, so migrate clients together with the API.

Review these value changes in endpoint tests:

- Zero, false, empty text, and null retain distinct meanings. In v1.1.6 the
  built-in operation callback treated raw empty scalar operands as null. The
  old FormRequest trait could preserve these values by wrapping them in a
  nonempty list first. Test the actual input path used by your application.
- An empty `eq`/`in` list matches no rows. An empty `ne`/`nin` list adds a true
  predicate. They no longer stand for null checks.
- `eq: null` means SQL NULL; `ne: null` means NOT NULL. `eq: [1, null]` means
  1 OR NULL; `ne: [1, null]` excludes both. JSON null is distinct from the string
  `"null"`. Query-string clients can use `filter[field][is_null]=true`.
- Inequality now follows the same rules for every column. v1.1.6's special
  `_id` branch combined `NOT IN` **AND** `IS NULL`, often matching no rows.
  That behavior is removed. `ne: [1]` excludes SQL NULL under ordinary SQL
  semantics; explicitly OR with `is_null: true` if null rows should be included.
- `is_empty: true` matches SQL NULL or an exact empty string. `false` requires
  neither. Whitespace, zero, false, and JSON arrays/objects are not empty.
  Add `FilterOperator::IsEmpty` when restricting that field's operators.

Search still accepts field maps and `search_type=and|or`; null/blank terms are
skipped. Search uses wildcard matching. Use `contains`, `starts_with`, or
`ends_with` for literal text. Requests are now bounded by `FilterLimits`; see
the [API guide](docs/api.md#search-sort-and-limits) before raising a budget.

## 4. Move access rules into trusted hooks

Retain mandatory branch or tenant restrictions in `before()`. Pass identity and
access context from the authenticated application, never from filter input.
An example method inside a filter with an injected `$branchId` is:

```php
/** Restrict every request, including empty input, to the authenticated branch. */
protected function before(\Illuminate\Database\Eloquent\Builder $query): void
{
    $query->where('documents.branch_id', $this->branchId); // Bind server-owned access context.
}
```

Use `beforeUsing()` for an additional per-call restriction. It returns a clone,
so keep the returned filter. The subclass hook and additional callbacks are
ANDed separately from client conditions. A client's OR group cannot widen them.

Hooks and `Field::custom()` callbacks may add WHERE predicates only. Move joins,
selection, sorting, and global-scope changes onto the caller's query. Configure
eager loads there or through `$with`/`setWith()`; non-WHERE mutations inside hooks
now throw `LogicException`. A custom field callback
receives `(Builder $query, mixed $value, string $operator)`, replacing the old
callback's boolean mode argument. Preserve model global scopes when restrictions
must also apply outside this package. See the [complete access example](docs/api.md#record-access-before-request-filters).

## 5. Configure relation loading and JSON columns

The Eloquent filter's `protected static array $with` declares default eager loads.
The instance methods support per-use configuration:

```php
$filter = new ProductFilter($request->query()); // Use the class's default ['brand'].
$defaults = $filter->getWith(); // Read this instance's declared eager loads.
$filter->setWith(['brand.country']); // Replace only this instance's configuration.
$products = Product::filter($filter)->get(); // Eager loading happens during retrieval.
```

`setWith()` returns the same instance and never changes the static defaults for
other instances or requests. `setWith([])` disables this filter's defaults;
it does not remove eager loads already configured on the model or query.
Existing builder eager-load constraints are preserved. Relation names and
callbacks are trusted application configuration, not request input. This API is
Eloquent-only; `BaseQBFilter` does not hydrate models or load relations.

For a `brand_id` accessor backed by `assignments['brand_id']`, keep the ordinary
`brand(): BelongsTo` relation and add a model `queryFilterColumns()` mapping to
`new JsonColumn('assignments->brand_id', JsonType::Integer)`. Then expose
`brand_id` and `brand.name` in `fields()` for normal filtering, searching, and
sorting. Keep the `assignments` column in selections needed by eager loading.
Follow the [JSON/JSONB migration example](docs/json-columns.md) for complete
model code, supported relation keys, null behavior, and expression indexes.

For `BaseQBFilter`, migrate to the same `fields()` API but declare explicit SQL
paths such as `products.id` or `b.name`. Add joins before calling `apply()`.
There is no automatic Eloquent relation discovery or model JSON accessor map
on a Query Builder query; see the [Query Builder example](docs/api.md#query-builder).

## 6. Remove published defaults and verify behavior

Package configuration, translations, the `query-filter` publishing tag, and
`query-filter:uninstall` are removed. Migrate any customized defaults into the
appropriate model filter before removing your old `config/query-filter.php`
and published package language files. Remove references to `query-filter.*`
configuration and `query-filter::query.*` translations from application code.

Replace `withDeleted()`/`onlyDeleted()` with Laravel's `withTrashed()`/`onlyTrashed()`
on an authorized caller query. Remove package `paginate`, `page`, and `per_page`
callbacks; validate page size in the application and call `paginate()` normally.
The request `limit` caps a subsequent `get()` and does not set pagination size.
Old automatic fields and concatenated search/sort expressions are not registered;
define the intended public behavior explicitly.

Before deployment:

1. Run application tests for every migrated filter, including search and sort
   permissions, default behavior, and invalid input responses.
2. Verify branch/tenant isolation with empty input, OR groups, related fields,
   and custom callbacks. Union builders are rejected to prevent bypassing access
   restrictions through another query branch.
3. Check zero/false/null/empty-list cases and the `_id` inequality change against
   real fixtures. Compare related sorting and JSON queries on your database.
4. Verify relation loading, bounded page sizes, and representative query plans.
   Client requests must migrate with the endpoint that consumes them.
5. Rebuild application configuration caches and restart long-running workers
   through your normal deployment process after removing obsolete package files.

Validation uses Laravel's `ValidationException`; authorization uses
`AuthorizationException`. Failed parsing or query compilation leaves the caller's
builder unchanged. Consult the [API guide](docs/api.md) for the complete new contract.
