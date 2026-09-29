# Examples and recipes

This guide is a practical companion to the [API guide](api.md). It uses a
catalogue-style `Product` model, but every example applies to any Eloquent
model or SQL query. The filter never executes a query by itself: it validates
the request, adds predicates to the builder, and leaves retrieval to Laravel.

## 1. A complete product filter

Start with a filter that demonstrates the most common field definitions:

```php
namespace App\Filters;

use Alif\QueryFilter\Abstracts\BaseEBFilter;
use Alif\QueryFilter\Enums\FilterOperator;
use Alif\QueryFilter\Field;
use Alif\QueryFilter\FilterLimits;
use Illuminate\Database\Eloquent\Builder;

final class ProductFilter extends BaseEBFilter
{
    protected static array $with = ['category', 'brand'];

    protected function fields(): array
    {
        return [
            // A string is shorthand for Field::make($sameName).
            'id' => Field::make('id')
                ->operators([FilterOperator::Equal, FilterOperator::In])
                ->searchable(false),

            'name' => Field::make('name')
                ->operators([
                    FilterOperator::Equal,
                    FilterOperator::Contains,
                    FilterOperator::StartsWith,
                    FilterOperator::EndsWith,
                    FilterOperator::Like,
                    FilterOperator::IsEmpty,
                ])
                ->rules(['string', 'max:150'])
                ->transform(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value),

            'price' => Field::make('price')
                ->operators([
                    FilterOperator::GreaterThanOrEqual,
                    FilterOperator::LessThanOrEqual,
                    FilterOperator::Between,
                    FilterOperator::NotBetween,
                ])
                ->rules(['numeric', 'min:0'])
                ->searchable(false),

            // Public alias -> physical column.
            'published_at' => Field::make('products.published_at')
                ->sortable(true),

            // A to-one relation is joinable and can be sorted.
            'brand_name' => Field::make('brand.name')
                ->operators([FilterOperator::Equal, FilterOperator::Contains]),

            // A collection is compiled as EXISTS and cannot be sorted.
            'tag' => Field::related('tags.name')
                ->operators([FilterOperator::Equal, FilterOperator::In]),

            // Capabilities can be independent.
            'internal_score' => Field::make('score')
                ->filterable(false)
                ->searchable(false)
                ->sortable(true),

            // This field is implemented by trusted application code.
            'has_reviews' => Field::custom(
                function (Builder $query, mixed $value, string $operator): void {
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                        $query->whereHas('reviews');
                    } else {
                        $query->whereDoesntHave('reviews');
                    }
                },
            )->rules(['boolean']),
        ];
    }

    protected function defaultSort(): array
    {
        return ['-published_at', 'id'];
    }

    protected function limits(): FilterLimits
    {
        return new FilterLimits(maxValues: 50, maxLimit: 250);
    }
}
```

Use the filter from a model by opting into `Filterable`:

```php
use Alif\QueryFilter\Traits\Filterable;

final class Product extends Model
{
    use Filterable;

    protected $filterClass = ProductFilter::class;
}

// The scope reads the current request query when no array is supplied.
$products = Product::filter()->paginate(25);

// Explicit input is useful for jobs, commands, tests, and non-HTTP handlers.
$products = Product::filter(ProductFilter::class, [
    'filter' => ['price' => ['between' => [10, 100]]],
    'sort' => '-price,id',
])->get();
```

The explicit form is also useful when the filter needs trusted context:

```php
$filter = new ProductFilter($request->query());
$filter->apply(Product::query());
```

## 2. Request formats

The same request can arrive as a query string or as an array. Public names are
the names on the left side of `fields()`; callers never send raw SQL columns.

### Query-string format

```text
filter[id][in][]=10
filter[id][in][]=20
filter[name][contains]=coffee
filter[price][gte]=10
filter[price][lte]=100
filter[tag]=featured
search[name]=espresso
search[brand_name]=acme
search_type=and
sort=-price,id
limit=50
```

`filter[name]=Alice` is equality shorthand. Lists require an explicit `in`,
`nin`, `eq`, or `ne` operator. Use JSON or an explicit PHP array when the
operand must be actual `null`, `false`, or a nested boolean tree.

### Array/JSON format

```php
$parameters = [
    'filter' => [
        'id' => ['in' => [10, 20]],
        'name' => [
            'contains' => 'coffee',
            'is_empty' => false,
        ],
        'price' => ['between' => [10, 100]],
        'tag' => 'featured', // Equality shorthand.
    ],
    'search' => ['name' => 'espresso', 'brand_name' => 'acme'],
    'search_type' => 'and',
    'sort' => ['-price', 'id'],
    'limit' => 50,
];

$products = Product::filter(ProductFilter::class, $parameters)->get();
```

When sending JSON through Laravel, pass the decoded payload explicitly:

```php
$filter = new ProductFilter($request->json()->all());
$products = Product::filter($filter)->paginate(25);
```

## 3. Operators, nulls, and text matching

The complete operator set is available through enum cases:

```php
use Alif\QueryFilter\Enums\FilterOperator;

$operators = [
    FilterOperator::Equal,          // eq
    FilterOperator::NotEqual,       // ne; neq is an HTTP alias
    FilterOperator::GreaterThan,     // gt
    FilterOperator::GreaterThanOrEqual, // gte
    FilterOperator::LessThan,        // lt
    FilterOperator::LessThanOrEqual, // lte
    FilterOperator::In,              // in
    FilterOperator::NotIn,           // nin; not_in is an alias
    FilterOperator::Between,          // between
    FilterOperator::NotBetween,       // nbetween; not_between is an alias
    FilterOperator::IsNull,           // is_null
    FilterOperator::IsEmpty,          // is_empty
    FilterOperator::Contains,         // contains; literal substring
    FilterOperator::StartsWith,       // starts_with; literal prefix
    FilterOperator::EndsWith,         // ends_with; literal suffix
    FilterOperator::Like,             // like; SQL wildcards are interpreted
    FilterOperator::NotLike,          // nlike; not_like is an alias
];
```

Examples:

```php
$parameters = [
    'filter' => [
        'price' => [
            'gt' => 0,
            'lte' => 500,
        ], // Multiple operators for one field are ANDed.
        'id' => ['in' => [1, 2, 3]],
        'name' => ['starts_with' => 'Pro'],
        'published_at' => ['is_null' => false],
        'description' => ['is_empty' => true],
        'sku' => ['like' => 'PRO_%'], // % and _ are SQL wildcards here.
    ],
];
```

`contains`, `starts_with`, and `ends_with` escape `%`, `_`, and the escape
character, so they are safe literal text searches. Use `like` only when the
client is intentionally allowed to provide a SQL pattern.

Nulls are explicit and are not discarded:

```php
[
    'filter' => [
        'deleted_at' => ['eq' => null],
        'status' => ['eq' => ['active', null]], // active OR SQL NULL
        'category_id' => ['ne' => [10, null]], // not 10 AND non-null
    ],
]
```

`is_empty=true` means SQL `NULL` or the exact empty string. Whitespace, `0`,
`false`, empty JSON arrays, and empty JSON objects are values, not empty text.

## 4. Boolean groups with `where`

Use `where` for nested logic that cannot be expressed as one field map. Every
group has exactly one `and` or `or` key; every leaf has exactly `field`,
`operator`, and `value`.

```php
$parameters = [
    'filter' => ['published_at' => ['is_null' => false]],
    'where' => [
        'and' => [
            ['field' => 'price', 'operator' => 'gte', 'value' => 10],
            ['or' => [
                ['field' => 'name', 'operator' => 'starts_with', 'value' => 'Pro'],
                ['field' => 'tag', 'operator' => 'eq', 'value' => 'featured'],
                ['and' => [
                    ['field' => 'price', 'operator' => 'lte', 'value' => 100],
                    ['field' => 'has_reviews', 'operator' => 'eq', 'value' => true],
                ]],
            ]],
        ],
    ],
];
```

The filter map, `where` tree, and search group are ANDed together. Existing
caller predicates and access hooks are also kept in their own AND groups, so a
requested OR cannot bypass a tenant or authorization restriction.

## 5. Search, sorting, pagination, and limits

Search terms are wildcard searches and are combined with OR by default:

```php
$parameters = [
    'search' => [
        'name' => 'coffee',
        'brand_name' => 'roast',
    ],
    'search_type' => 'and', // Both non-empty terms must match.
    'sort' => '-brand_name,price,id',
    'limit' => 100,
];

$query = Product::filter(ProductFilter::class, $parameters);
$products = $query->paginate(25); // per_page is pagination, not filter limit.
```

An explicit `sort` replaces `defaultSort()`. `sort: []` intentionally disables
the default. Add a unique final sort column such as `id` for stable pagination.
`limit` bounds a later `get()`/retrieval; it is not a substitute for validating
the request's `per_page` value.

For a smaller endpoint-specific budget:

```php
use Alif\QueryFilter\FilterLimits;

$filter = new ProductFilter(
    $request->query(),
    new FilterLimits(
        maxConditions: 20,
        maxValues: 25,
        maxSorts: 3,
        maxLimit: 200,
    ),
);
```

The limits cover conditions, tree depth, values per list, sort expressions,
result limit, term length, and aggregate SQL bindings. They bound input work;
use database indexes and infrastructure controls for query execution time.

## 6. Relations: EXISTS versus joins

Use `Field::related()` when the question is whether a related row exists:

```php
protected function fields(): array
{
    return [
        'has_featured_tag' => Field::related('tags.name')
            ->operators([FilterOperator::Equal]),
        'author_name' => Field::related('author.name')
            ->operators([FilterOperator::Contains]),
    ];
}
```

This compiles to `EXISTS`/`whereHas`, supports collection and nested concrete
relations, preserves relation scopes, and does not duplicate parent rows. It
cannot sort because a collection has no single value.

Use `Field::make('author.name')` when a to-one relation must also be sortable:

```php
protected function fields(): array
{
    return [
        'author' => Field::make('author.name')
            ->operators([FilterOperator::Equal, FilterOperator::Contains]),
    ];
}
```

Join-backed relation fields require qualified root predicates and selections:

```php
$query = Product::query()
    ->select('products.*')
    ->where('products.tenant_id', $tenantId);
(new ProductFilter(['sort' => 'author']))->apply($query);
```

All operators for one `Field::related()` map must match one related row. If two
conditions may match different children, use separate leaves. If both columns
must belong to the same child, use `Field::custom()` with one `whereHas()`.

## 7. Eager loading defaults and overrides

Eager loading is application configuration, not request input:

```php
final class ProductFilter extends BaseEBFilter
{
    protected static array $with = ['brand', 'category.parent'];

    protected function fields(): array
    {
        return ['name', 'brand.name', 'category.name'];
    }
}

$filter = new ProductFilter($request->query());
$filter->setWith([
    'brand:id,name',
    'category' => fn ($query) => $query->where('active', true),
]);

$products = Product::filter($filter)->get();
```

`setWith([])` disables only this filter's defaults. Caller/model eager loads
remain, and existing constraints win for the same relation name. The Query
Builder filter has no eager-loading API.

## 8. Validation, normalization, and authorization

Field rules apply to each ordinary filter operand after transformation:

```php
'sku' => Field::make('sku')
    ->transform(fn (mixed $value): mixed => is_string($value) ? strtoupper(trim($value)) : $value)
    ->rules(['string', 'regex:/^[A-Z0-9-]+$/', 'max:40']),

'stock' => Field::make('stock')
    ->rules(['integer', 'min:0']),
```

Request-wide Laravel rules belong in the filter class:

```php
protected function rules(): array
{
    return [
        'filter.price.gte' => ['sometimes', 'numeric', 'min:0'],
        'filter.price.lte' => ['sometimes', 'numeric', 'gte:filter.price.gte'],
    ];
}
```

Authorize a filter as a whole or one public field:

```php
protected function authorize(): bool
{
    return auth()->user()?->can('view-products') ?? false;
}

protected function fields(): array
{
    return [
        'name',
        'cost' => Field::make('cost')
            ->authorize(fn (): bool => auth()->user()?->can('view-costs') ?? false),
    ];
}
```

Class authorization runs before parsing. Field authorization runs only when the
field is requested, including through search or sorting. Unauthorized requests
raise `AuthorizationException`; malformed requests raise Laravel's
`ValidationException`.

## 9. Tenant and record access restrictions

Record access must come from trusted server context, never from a public filter
field:

```php
final class DocumentFilter extends BaseEBFilter
{
    public function __construct(
        array $parameters,
        private readonly int $branchId,
    ) {
        parent::__construct($parameters);
    }

    protected function fields(): array
    {
        return ['title', 'status', 'created_at'];
    }

    protected function before(Builder $query): void
    {
        $query->where('documents.branch_id', $this->branchId);
    }
}

$filter = new DocumentFilter($request->query(), $request->user()->branch_id);
$query = Document::query();
$filter->apply($query);
```

Add a second trusted restriction for one use with `beforeUsing()`. It returns
a clone, so retain the returned object:

```php
$filter = (new DocumentFilter($request->query(), $branchId))
    ->beforeUsing(fn (Builder $query) => $query->whereIn('id', $allowedIds));
```

Hooks run even when input is empty and accept WHERE predicates only. Configure
joins, selection, ordering, eager loading, and scopes on the caller or through
the dedicated filter APIs. Existing caller conditions remain protected from
request OR groups.

## 10. Custom predicates

Custom fields are useful for domain concepts that are not one SQL column:

```php
'availability' => Field::custom(
    function (Builder $query, mixed $value, string $operator): void {
        if ($value === 'in_stock') {
            $query->where('stock', '>', 0);
        } else {
            $query->where('stock', '=', 0);
        }
    },
)->operators([FilterOperator::Equal])
  ->rules(['in:in_stock,out_of_stock']),
```

The callback receives `(builder, value, operator)`, keeps scalar/list shape,
and its return value is ignored. Custom fields are not searchable or sortable.
Use bound values and WHERE methods (`where`, `whereHas`, `whereExists`, and
related methods); attempts to change joins, ordering, selection, eager loads,
or scopes are rejected.

## 11. Query Builder filters

Use `BaseQBFilter` when the query is not an Eloquent model query. Supply joins
and explicit qualified columns yourself:

```php
final class SalesQueryFilter extends BaseQBFilter
{
    public function __construct(
        array $parameters,
        private readonly int $accountId,
    ) {
        parent::__construct($parameters);
    }

    protected function fields(): array
    {
        return [
            'order_number' => Field::make('o.number'),
            'customer' => Field::make('c.name'),
            'total' => Field::make('o.total')
                ->operators([FilterOperator::GreaterThanOrEqual, FilterOperator::LessThanOrEqual])
                ->rules(['numeric']),
        ];
    }

    protected function before(\Illuminate\Database\Query\Builder $query): void
    {
        $query->where('o.account_id', $this->accountId);
    }
}

$query = DB::table('orders as o')
    ->join('customers as c', 'c.id', '=', 'o.customer_id')
    ->select('o.*');

(new SalesQueryFilter($request->query(), $request->user()->account_id))->apply($query);
$rows = $query->paginate(25);
```

The Query Builder variant shares parsing, validation, operators, limits, and
access hooks, but it does not discover relations, JSON mappings, or eager loads.

## 12. JSON/JSONB-backed fields

Map storage once on the model and expose a normal public field:

```php
use Alif\QueryFilter\Enums\JsonType;
use Alif\QueryFilter\JsonColumn;

final class Product extends Model
{
    use Filterable;

    protected $casts = ['assignments' => 'array'];

    public function queryFilterColumns(): array
    {
        return [
            'brand_id' => new JsonColumn('assignments->brand_id', JsonType::Integer),
            'priority' => new JsonColumn('assignments->priority', JsonType::Integer),
            'caption' => new JsonColumn('assignments->caption', JsonType::Text),
        ];
    }
}
```

Then query it like any other field:

```php
protected function fields(): array
{
    return [
        'brand_id' => Field::make('brand_id')
            ->operators([FilterOperator::Equal, FilterOperator::In])
            ->rules(['integer'])
            ->searchable(false),
        'priority' => Field::make('priority')
            ->operators([FilterOperator::GreaterThanOrEqual, FilterOperator::LessThanOrEqual])
            ->rules(['integer'])
            ->searchable(false),
        'caption',
    ];
}

$products = Product::filter(ProductFilter::class, [
    'filter' => [
        'brand_id' => ['in' => [1, 2]],
        'priority' => ['gte' => 5],
    ],
    'sort' => '-priority,id',
])->get();
```

Supported scalar types are `Text`, `Integer`, `Decimal`, `Boolean`, and `Uuid`.
Missing keys and JSON `null` are SQL `NULL`; use `is_null` for that distinction.
For a JSON-backed `BelongsTo` key, keep the normal accessor and relation, map
the key with `JsonColumn`, and select the JSON storage column when eager loading.
See the [JSON reference](json-columns.md) for PostgreSQL expression indexes.

## 13. Reuse and safe composition

Apply one filter to a pre-constrained builder:

```php
$query = Product::query()
    ->where('products.tenant_id', $tenantId)
    ->withCount('reviews');

(new ProductFilter($request->query()))->apply($query);

return $query->paginate(25);
```

A filter instance can be applied to independent builders. It carries request
parameters, limits, access callbacks, and eager-load configuration, but not
query joins or result data. Applying it twice to the same builder intentionally
adds its predicates twice; use a fresh builder when composing independent
queries.

## 14. Production checklist

Before exposing a filter endpoint:

- Declare every public field explicitly; never derive the allowlist from a
  request, resource, or model attribute dump.
- Restrict operators and capabilities with `Field` where the endpoint does not
  need the full built-in set.
- Keep tenant, branch, ownership, and policy predicates in trusted scopes,
  `before()`, or `beforeUsing()` callbacks.
- Validate and cap `per_page` separately from the filter's `limit`.
- Add a unique final sort key for stable pagination.
- Prefer `Field::related()` for collection matching and `Field::make()` for
  sortable to-one relations.
- Index frequent equality/range/sort columns, relation foreign keys, and JSON
  expressions used in production queries.
- Use `contains` for literal user text; expose `like` only for intentional SQL
  wildcard input.
- Test generated SQL and bindings on each supported database driver, and run
  the PostgreSQL JSON integration tests when using JSONB.

For upgrade-sensitive behavior, read [UPGRADING.md](../UPGRADING.md), then use
the [API reference](api.md) for exact validation and relation semantics.
