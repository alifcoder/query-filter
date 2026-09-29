# Alif Query Filter

Explicit, validated filtering for Laravel Eloquent and Query Builder. Keep
controllers small and use the normal builder for pagination and retrieval.

Inspired by [Kettasoft Filterable](https://github.com/kettasoft/filterable), with
one parser and query compiler, bounded input, and no automatic result caching.
See the [architecture and reference review](docs/architecture.md).

**v2.0.0 is a major release.** Applications using v1.x must follow the
[upgrade guide](UPGRADING.md) before adopting this API.

## Install

Requires **PHP 8.3 or higher** and **Laravel 11 or higher**. Each Laravel
release's own PHP requirement also applies; Laravel 13 requires PHP 8.3+.

```bash
composer require alifcoder/query-filter
php artisan query-filter:make ProductFilter
```

Laravel discovers the service provider and its model-filter generator automatically.

## Define the public fields

Create one concrete filter for each model, such as `ProductFilter`. Both base
classes are abstract; public fields belong in the model filter's `fields()` method.

```php
namespace App\Filters;

use Alif\QueryFilter\Abstracts\BaseEBFilter;
use Alif\QueryFilter\Enums\FilterOperator;
use Alif\QueryFilter\Field;

class ProductFilter extends BaseEBFilter
{
    protected static array $with = ['category']; // Eager load the category with each product result.

    /** Expose only the product fields that clients may query. */
    protected function fields(): array
    {
        return [
            'id' => Field::make('id')
                ->operators([FilterOperator::Equal, FilterOperator::In])
                ->searchable(false),
            'name' => Field::make('name')
                ->operators([
                    FilterOperator::Equal,
                    FilterOperator::Contains,
                    FilterOperator::StartsWith,
                    FilterOperator::IsEmpty,
                ]),
            'price' => Field::make('price')
                ->operators([
                    FilterOperator::Equal,
                    FilterOperator::GreaterThanOrEqual,
                    FilterOperator::LessThanOrEqual,
                    FilterOperator::Between,
                ])
                ->rules(['numeric', 'min:0'])
                ->searchable(false),
            'category' => Field::related('category.name')->operators([FilterOperator::Equal]),
            'tag' => Field::related('tags.name')->operators([FilterOperator::Equal, FilterOperator::In]),
        ];
    }

    /** Keep product results stable when the request omits sorting. */
    protected function defaultSort(): array
    {
        return ['-id'];
    }
}
```

`Field::related()` uses `EXISTS`, including for collections. It cannot duplicate
parent rows and is not sortable. `Field::make('category.name')` instead uses a
reusable join for a `BelongsTo` or `HasOne` relation and supports related sorting.

## Apply it

```php
use Alif\QueryFilter\Traits\Filterable;
use App\Filters\ProductFilter;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use Filterable;

    protected $filterClass = ProductFilter::class;
}

// Uses the current HTTP query parameters and the model's filter class.
$products = Product::filter()->paginate(25);

// Explicit input works in HTTP handlers, jobs, commands, and tests.
$products = Product::filter(ProductFilter::class, [
    'filter' => ['price' => ['between' => [10, 100]], 'tag' => 'featured'],
    'sort' => '-price,id',
])->get();
```

The trait is optional:

```php
$query = Product::query()->where('products.tenant_id', $tenantId);
(new ProductFilter($request->query()))->apply($query);
return $query->paginate(25);
```

## Default relations

Declare `protected static array $with` in a model filter to eager load relations
when results are retrieved. Override these defaults for one filter instance:

```php
$filter = new ProductFilter($request->query()); // Use ProductFilter's static defaults initially.
$filter->setWith(['category.parent', 'brand']); // Replace defaults for this instance only.
$relations = $filter->getWith(); // Read the configured relation array.
$products = Product::filter($filter)->paginate(25); // Eloquent loads the requested relations in batches.
```

`setWith([])` disables only the filter's defaults. Caller and model eager loads
remain, and their constraints take precedence for matching relation names.
Laravel's nested relation, selected-column and closure array syntax is supported.
Keep relation declarations in application code; request input cannot choose
relations automatically. See [eager loading](docs/api.md#eager-loading).

## Restrict record access

Add trusted access conditions before request filters. On an authenticated route,
take the branch from the current user, not a query parameter:

```php
use Illuminate\Database\Eloquent\Builder;

class DocumentFilter extends BaseEBFilter
{
    /** Declare the document fields available to clients. */
    protected function fields(): array
    {
        return ['title', 'status'];
    }
}

$branchId = $request->user()->branch_id;
$filter = (new DocumentFilter($request->query()))->beforeUsing(
    fn (Builder $query) => $query->where('documents.branch_id', $branchId),
);

return Document::filter($filter)->paginate(25);
```

For a rule shared by every use of a filter, override its protected `before()`
method. Access predicates run even with empty input and remain ANDed with caller
and request conditions. Hooks accept WHERE predicates only; configure joins and
ordering on the caller's builder, and eager loading there or through `$with`.
See the
[access hook examples](docs/api.md#record-access-before-request-filters).

## Query Builder

`BaseQBFilter` uses the same fields, operators, validation and access hooks:

```php
use Alif\QueryFilter\Abstracts\BaseQBFilter;
use Illuminate\Support\Facades\DB;

class ProductQueryFilter extends BaseQBFilter
{
    /** Map public product fields to the caller's joined SQL columns. */
    protected function fields(): array
    {
        return ['name' => 'products.name', 'brand' => 'brands.name'];
    }
}

$query = DB::table('products')
    ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
    ->select('products.*');

$filter = new ProductQueryFilter($request->query());
$filter->apply($query);

return $query->paginate(25);
```

Query Builder maps SQL columns explicitly; Eloquent relation discovery belongs
to `BaseEBFilter`. Both bases share the same parser and query compiler.

## Request format

```text
filter[price][gte]=10
filter[name][starts_with]=Coffee
filter[tag]=featured
filter[name][is_empty]=false
sort=-price,id
```

Dotted public names go inside brackets: `filter[created_by.name][eq]=Ali`.
String values are preserved; URLs and timestamps are never split on colons.

Use JSON for grouped conditions:

```json
{
  "filter": { "price": { "lte": 100 } },
  "where": {
    "or": [
      { "field": "name", "operator": "starts_with", "value": "Coffee" },
      { "field": "tag", "operator": "eq", "value": "featured" }
    ]
  },
  "sort": "-price,id"
}
```

Pass JSON explicitly with `new ProductFilter($request->json()->all())`.
`filter`, `where`, and search groups are ANDed with existing query constraints.
Unknown fields, disallowed operators, malformed values, and excessive requests
raise Laravel `ValidationException` (422 for JSON requests). Authorization
failures raise `AuthorizationException` (403).

## Capabilities

- Explicit aliases, per-field operators, separate filter/search/sort permissions.
- Equality, sets, ranges, null/empty checks, literal text matching, and SQL patterns.
- Nested AND/OR groups, relation scopes, collection filtering, multi-column sort.
- Typed JSON/JSONB attributes and JSON-backed BelongsTo keys for filter/search/sort.
- Laravel validation, value transformations, field authorization, custom callbacks.
- Access hooks that constrain records before request filtering.
- Per-filter eager-loading defaults with instance-level overrides.
- Reusable definitions, configurable request limits, no SQL during built-in compilation.

`is_empty=true` matches SQL NULL or an exact empty string; `false` matches values
that are neither. Zero, false, whitespace, empty JSON arrays and empty JSON objects
are values. This rebuild removes the old APIs and requires application updates;
see the [v2.0 upgrade guide](UPGRADING.md).

Read the [API guide](docs/api.md) for all operators, hooks, limits, relation
semantics, migration steps, and performance guidance.
See the [examples and recipes](docs/examples.md) for a complete filter and
copyable examples covering every feature, including advanced composition.
See [JSON columns and relations](docs/json-columns.md) for a `brand_id` accessor
backed by `assignments->brand_id`, including PostgreSQL indexes.

## Development

```bash
composer install
composer lint
composer test
composer benchmark
```

Tests execute against SQLite, with PostgreSQL JSONB integration tests enabled
in a dedicated CI job. MySQL/MariaDB coverage checks SQL compilation only. The CI
matrix covers compatible PHP 8.3–8.5 and Laravel 12–13 combinations. See the
[PostgreSQL test instructions](docs/json-columns.md#run-postgresql-integration-tests)
to run those cases locally. Benchmark results are local measurements, not
database performance guarantees.

Laravel 11 is permitted by the package requirements, but fresh verification is
currently blocked by dependency security advisories. Future major versions are
permitted by Composer and require compatibility verification when released.

The lockfile reflects the development runtime. For a different PHP/Laravel
combination, resolve dependencies with `composer update`, as the CI matrix does.

## License

MIT © [Shukhratjon Yuldashev](https://t.me/alif_coder)
