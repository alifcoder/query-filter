# JSON columns and relations

Use ordinary field names for values stored in JSON or PostgreSQL JSONB. Declare
their SQL location once on the model, then filter, search, and sort them through
its concrete `ProductFilter` subclass of `BaseEBFilter`. The same mapping works when an Eloquent relation's foreign key is a
PHP accessor backed by a JSON value. JSON relation keys are supported for the
foreign-key side of `BelongsTo` relations.

For example, a product might store:

```json
{
  "brand_id": 1,
  "priority": 10,
  "caption": "Summer collection"
}
```

in its `assignments` column. A PHP accessor exposes `brand_id` after loading a
product, but cannot describe a SQL join by itself. The mapping supplies that
missing information without executing queries or loading products.

## Map the model's virtual columns

```php
namespace App\Models;

use Alif\QueryFilter\Enums\JsonType;
use Alif\QueryFilter\JsonColumn;
use Alif\QueryFilter\Traits\Filterable;
use App\Filters\ProductFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use Filterable;

    protected $filterClass = ProductFilter::class;

    protected $casts = ['assignments' => 'array'];

    /** Read the stored foreign key when Eloquent loads this product. */
    public function getBrandIdAttribute(): ?int
    {
        return $this->assignments['brand_id'] ?? null;
    }

    /** Keep the normal accessor-backed relation for model loading. */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /** Describe SQL locations and types for virtual product attributes. */
    public function queryFilterColumns(): array
    {
        return [
            'brand_id' => new JsonColumn('assignments->brand_id', JsonType::Integer),
            'priority' => new JsonColumn('assignments->priority', JsonType::Integer),
            'caption' => new JsonColumn('assignments->caption'),
        ];
    }
}
```

Keep your existing accessor and relation. The second argument to
[`belongsTo()`](https://laravel.com/docs/12.x/eloquent-relationships#one-to-many-inverse)
is the virtual foreign key name. Choose its `JsonType` to match the related owner
key: usually `Integer` for an integer `brands.id`, or `Uuid` for a PostgreSQL UUID.
The related owner key must be a real database column. Mapped JSON owner keys
and mapped JSON relation keys in inverse `HasOne`/`HasMany` relations are rejected.
Normal collection relations and their mapped JSON attributes remain supported;
this restriction concerns the columns connecting the two models.

`queryFilterColumns()` describes storage; it does not expose fields to requests.
Declare the public fields separately, as with ordinary database columns.

## Declare the usual fields

```php
namespace App\Filters;

use Alif\QueryFilter\Abstracts\BaseEBFilter;
use Alif\QueryFilter\Enums\FilterOperator;
use Alif\QueryFilter\Field;

class ProductFilter extends BaseEBFilter
{
    /** Allow clients to query only the declared product fields. */
    protected function fields(): array
    {
        return [
            'id',
            'brand_id' => Field::make('brand_id')
                ->operators([FilterOperator::Equal, FilterOperator::In])
                ->rules(['integer'])
                ->searchable(false),
            'brand.name',
            'priority' => Field::make('priority')
                ->rules(['integer'])
                ->searchable(false),
            'caption',
        ];
    }
}
```

No JSON selector appears in the public request:

```php
$products = Product::filter(ProductFilter::class, [
    'filter' => [
        'brand_id' => ['in' => [1, 2]],
        'priority' => ['gte' => 5],
    ],
    'search' => ['brand.name' => 'Acme', 'caption' => 'Summer'],
    'search_type' => 'and',
    'sort' => 'brand.name,-priority,id',
])->with('brand')->paginate(25);
```

Equivalent query parameters include `filter[brand_id][eq]=1`,
`filter[brand.name][eq]=Acme`, `search[brand.name]=Acme`, and
`sort=brand.name,-priority,id`. Existing operator permissions, validation,
authorization, nested conditions, and request limits still apply.

The integer mapping makes priority `2` sort before `10`. Without an explicit
type, `JsonColumn` uses text semantics. Search retains its usual wildcard and
driver behavior; use equality filters for booleans, as described in the
[API guide](api.md#search-sort-and-limits).

Public aliases also work: `'brand' => 'brand.name'` or
`'rank' => Field::make('priority')`. For filtering/searching without sorting,
declare `'brand_name' => Field::related('brand.name')` to use an `EXISTS` query.
The mapped JSON foreign key works with both relation strategies. Related model
global scopes and soft-delete constraints continue to apply.

## Nested values and related models

Nested object paths use `->` between keys:

```php
'weight' => new JsonColumn('assignments->shipping->weight', JsonType::Decimal),
```

Each model owns its own map. If `Brand` maps `label` to
`new JsonColumn('details->label')`, a product filter can declare `'brand.label'`
normally. Nested relation paths resolve each model's mapped fields and keys.
Each JSON relation key in that path must still be a `BelongsTo` foreign key.

The mapping is used by this package's query compiler. It does not change native
Eloquent `whereHas()`, `orderBy()`, or custom SQL outside the compiler. Existing
accessor-based lazy/eager loading still uses your normal Eloquent relation;
include the JSON column when selecting model attributes needed by that accessor.

To load the brand by default, add `protected static array $with = ['brand'];`
to `ProductFilter`. A filter instance can use `setWith(['brand:id,name'])` or
read its declaration with `getWith()`. Select `products.assignments` when limiting
product columns so Laravel can read the accessor's foreign key. These defaults
use normal eager loading; the JSON mapping continues to handle SQL filtering,
searching and sorting. See [eager loading](api.md#eager-loading) for overrides
and existing-query precedence.

## Types and null values

| Enum case | Stored value | PostgreSQL expression type |
| --- | --- | --- |
| `JsonType::Text` | Text or a scalar to compare as text | Text; the default |
| `JsonType::Integer` | Integer | `BIGINT` |
| `JsonType::Decimal` | Decimal number | `NUMERIC` |
| `JsonType::Boolean` | Boolean | `BOOLEAN` |
| `JsonType::Uuid` | UUID string | `UUID` |

Missing keys and JSON `null` become SQL `NULL`. Use the ordinary `is_null`
operator; a JSON string `"null"` remains a string. PostgreSQL extraction also
returns SQL `NULL` when a path cannot match the document structure. See
[PostgreSQL JSON operators](https://www.postgresql.org/docs/current/functions-json.html).

`is_empty=true` matches a missing key, JSON null, or an exact empty string in a
text mapping. `is_empty=false` excludes those values. Whitespace, zero, false,
empty arrays and empty objects are not empty. Typed integer/boolean mappings also
support the operator: zero and false remain values. Both probe operators skip
the field's operand transformations and rules, but still require a boolean flag.

Types describe valid stored data; they are not a data-cleaning mechanism. Validate
JSON values when writing them. PostgreSQL rejects invalid non-null casts, such
as `"unknown"` for an integer key. Other drivers may coerce malformed values
differently. Field rules validate filter operands, not existing database rows.

The compiler supports PostgreSQL, MySQL/MariaDB, and SQLite JSON expressions.
Numeric precision, UUID comparison, and collation follow the selected driver.
Choose types consistent with the stored scalar values and relation owner keys.

Paths accept simple identifiers only: `column->key` or
`column->key->nested_key`, with letters, digits, and underscores; each segment
must begin with a letter or underscore. Array indexes, dotted or hyphenated JSON
keys, and arrays of related IDs are not supported by this scalar mapping. Use
an explicit custom predicate for those shapes. Paths and maps belong in
application code, never in request input.

## PostgreSQL performance

For a mapped integer foreign key, the relation compares the extracted value as
an integer:

```sql
CAST(products.assignments->>'brand_id' AS BIGINT) = brands.id
```

The cast is on the JSON value, leaving the related primary key as an ordinary
indexed column. Add an expression index for frequently filtered JSON values:

```sql
CREATE INDEX products_brand_id_json_idx
    ON products ((CAST(assignments->>'brand_id' AS BIGINT)));

CREATE INDEX products_priority_json_idx
    ON products ((CAST(assignments->>'priority' AS BIGINT)));
```

Match the query's extraction expression and type when creating an index.
PostgreSQL can use [indexes on expressions](https://www.postgresql.org/docs/current/indexes-expressional.html)
for matching expressions, with extra maintenance cost on writes. Check actual
plans with `EXPLAIN (ANALYZE, BUFFERS)` and representative data; an index does not
guarantee a particular plan. A general JSONB GIN index is not a replacement for
an index on these scalar equality/range/sort expressions.

Only requested fields introduce joins or predicates. Query compilation does not
execute SQL or inspect the database schema. For relational keys that need normal
foreign-key constraints, consider a real or generated column in the application
schema; this mapping does not create constraints or synchronize stored values.

## Run PostgreSQL integration tests

The regular suite runs JSON integration tests on SQLite. Set
`QUERY_FILTER_PG_PORT` to run the PostgreSQL variants too:

```bash
QUERY_FILTER_PG_PORT=5432 composer test -- --filter JsonRelationFilterTest
```

Optional connection variables are `QUERY_FILTER_PG_HOST` (default `127.0.0.1`),
`QUERY_FILTER_PG_DATABASE` (default `postgres`), `QUERY_FILTER_PG_USER` (default
the current PHP script owner's username), and `QUERY_FILTER_PG_PASSWORD` (default
empty). PHP needs the PDO PostgreSQL extension. Use a test database where the
test user can create schemas. Each test creates a uniquely named schema and
removes it during teardown.
