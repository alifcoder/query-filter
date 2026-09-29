# API guide

## Field definitions

Extend `Abstracts\BaseEBFilter` with a concrete filter for each model. Declare
public fields in its required `fields()` method. The constructor accepts request
parameters and optional `FilterLimits`; fields cannot come from constructor input.

```php
use Alif\QueryFilter\Abstracts\BaseEBFilter;
use Alif\QueryFilter\Enums\FilterOperator;
use Alif\QueryFilter\Field;

class ProductFilter extends BaseEBFilter
{
    /** Define the product columns clients may query. */
    protected function fields(): array
    {
        return [
            'id',
            'name',
            'label' => 'name',
            'creator_name' => 'createdBy.name',
            'score' => Field::make('score')
                ->operators([FilterOperator::GreaterThanOrEqual, FilterOperator::LessThanOrEqual])
                ->searchable(false),
        ];
    }
}

$filter = new ProductFilter($request->query());
$filter->apply($query);
```

A string definition permits filtering, searching, and sorting. Use `Field` to
restrict each purpose with `filterable(false)`, `searchable(false)`, and
`sortable(false)`. `operators([])` disables all filter operators for that field.
An absent operator policy permits all built-in operators. PHP `operators()`
declarations require `FilterOperator` enum cases. HTTP and JSON requests use
string operator names such as `eq` and `gte`, including the documented aliases.

Fluent `Field` methods return clones, so a shared definition can be specialized
without changing the original. Define reusable fields by overriding
`protected function fields(): array` in a `BaseEBFilter` subclass. Definitions
are developer-owned; do not build them from request input or resource output.
Field names and SQL paths accept letters, digits, underscores and dots; each
segment must start with a letter or underscore.

Public aliases can map to different database names, including nested relations:
`'created_by.s_code' => 'createdBy.staff_code'`. Snake case relation segments
resolve to camel case Eloquent methods. The final segment names a SQL column
or a virtual column declared in the model's `queryFilterColumns()` map using
`JsonColumn` and `Enums\JsonType`. This supports JSON/JSONB attributes
and relation keys without changing the request format. See
[JSON columns and relations](json-columns.md). JSON expressions and arbitrary SQL
identifiers are not inferred from field names or PHP accessors.

A `JsonResource` can conditionally hide fields and calculate values. It cannot
safely determine the SQL allowlist. Map SQL-backed fields explicitly and use a
custom predicate for computed values.

## Operators

| Operator | Value | Meaning |
| --- | --- | --- |
| `eq`, `ne` | scalar, null, or a list | Equality / inequality; a scalar is shorthand for one value. |
| `in`, `nin` | a list | Include / exclude values. |
| `gt`, `gte`, `lt`, `lte` | one non-null scalar, except booleans | Ordered comparisons. |
| `between`, `nbetween` | exactly two non-null scalars, except booleans | Inclusive range / outside the range. |
| `is_null` | boolean | Null when true, non-null when false. |
| `is_empty` | boolean | SQL NULL or an exact empty string when true; neither when false. |
| `contains` | scalar text | Literal substring. |
| `starts_with`, `ends_with` | scalar text | Literal prefix / suffix. |
| `like`, `nlike` | scalar pattern | SQL LIKE / NOT LIKE patterns. |

Request aliases: `neq` = `ne`, `not_in` = `nin`, `not_between` = `nbetween`,
`not_like` = `nlike`. PHP field policies use `Enums\FilterOperator` cases.

`filter[name]=Alice` means equality. Multiple operators in one field's map are
ANDed. Bare lists require an explicit operator: `filter[id][in][]=1`.
Use JSON for actual nulls; the string `"null"` is a string. `is_null` and `is_empty` accept
`true`, `false`, `0`, `1`, `"true"`, `"false"`, `"0"`, and `"1"`.

Use `FilterOperator::IsEmpty` in a field's operator policy and
`filter[name][is_empty]=true` in requests. Empty checks do not trim values:
whitespace, zero, false, `[]` and `{}` are not empty. For mapped JSON scalar
fields, the check applies to the resolved scalar expression, including SQL NULL
for a missing JSON key. Column-value rules and transformations do not run on the
boolean flag.

`eq: [1, null]` means 1 OR NULL; `ne: [1, null]` means not 1 AND not NULL.
An empty include list matches no rows; an empty exclude list adds a true
predicate. SQL null behavior otherwise applies: `ne: [1]` does not match NULL.
Zero, false, empty strings, and null are distinct; none are dropped by truthiness.

Literal text operators escape `%`, `_`, and the escape character. `like` and
`nlike` deliberately interpret wildcards. PostgreSQL uses text casts and ILIKE;
other database case sensitivity follows their collation. There is no LOWER()
wrapper around indexed columns. Non-finite numeric values and nested value arrays
are rejected. BETWEEN endpoints are sent in the provided order.

## Boolean groups

The optional `where` parameter is an explicit tree. A group contains exactly
one `and` or `or` key with a non-empty list. A leaf contains exactly `field`,
`operator`, and `value`, including `value: null` for a null operand.

```php
$params = ['where' => ['and' => [
    ['field' => 'enabled', 'operator' => 'eq', 'value' => true],
    ['or' => [
        ['field' => 'score', 'operator' => 'gte', 'value' => 90],
        ['field' => 'name', 'operator' => 'starts_with', 'value' => 'A'],
    ]],
]]];
```

`filter`, `where`, and `search` are ANDed together. Each OR and custom predicate
is grouped, retaining conditions already on the builder, such as a tenant ID.
An invalid sibling invalidates the complete request. No permissive mode silently
turns invalid input into a different query.

## Search, sort, and limits

The existing `search` format remains available:

```php
['search' => ['name' => 'coffee', 'created_by.name' => 'Ali'],
 'search_type' => 'or', 'sort' => ['-created_by.name', 'id'], 'limit' => 50]
```

Search combines fields with OR by default or AND with `search_type=and`.
The mode is case-insensitive. Null and whitespace-only terms are skipped.
Search surrounds each term with `%` and allows SQL wildcards.
Prefer `filter[name][contains]` for literal user text. Use equality
filters for booleans: SQL text representations of booleans vary between drivers.
Field value transformations/rules apply to filter operands, not search terms.
Search permission is independent of the field's operator policy.

Sort accepts a list or comma-separated string. `-` means descending; repeated
fields keep their first position and final direction. Existing builder orders
are preserved. `defaultSort()` supplies ordering only when no `sort` parameter was supplied;
`sort: []` suppresses the default. Include a unique final column for stable
pagination. The application may call `reorder()` before applying the filter.

`limit` bounds a subsequent `get()`. It is not a pagination page size, and an
absent limit does not cap result count. Validate and cap `per_page` separately
when passing it to Eloquent. Filtering does not execute or cache results.

Defaults bound the work requested from the compiler:

| `FilterLimits` option | Default |
| --- | ---: |
| `maxConditions` | 100 |
| `maxDepth` | 5 |
| `maxValues` | 100 |
| `maxSorts` | 5 |
| `maxLimit` | 1000 |
| `maxTermLength` | 2000 bytes |
| `maxBindings` | 500 |

Condition count includes operators, tree groups, and non-empty search terms.
The tree root is depth 1. `maxValues` applies to each list; `maxBindings` bounds
the aggregate operand count, including null list entries, and search terms.
Each `is_empty` check also counts one binding; `is_null` needs none.
Limits exclude preexisting builder bindings and SQL added by trusted callbacks.
All configured limits must be positive integers.

```php
use Alif\QueryFilter\FilterLimits;

new ProductFilter($params, new FilterLimits(maxValues: 50, maxLimit: 250));
```

A reusable filter can override `protected function limits(): FilterLimits`.
These are input budgets, not query timeouts. Use appropriate indexes and database
resource controls for the application's workload.

## Eager loading

Eloquent filters can declare relation defaults independently of their public
query fields. These defaults use Laravel's ordinary `with()` array syntax:

```php
class ProductFilter extends BaseEBFilter
{
    protected static array $with = ['brand', 'category.parent']; // Load these relations with each result page.

    /** Declare the fields clients may filter, search and sort. */
    protected function fields(): array
    {
        return ['id', 'name', 'brand.name']; // Eager loading does not expose additional query fields.
    }
}

$filter = new ProductFilter($request->query()); // Start with this class's relation defaults.
$defaults = $filter->getWith(); // Returns ['brand', 'category.parent'].
$filter->setWith(['brand:id,name']); // Replace defaults only for this filter instance.
$products = Product::filter($filter)->get(); // Eloquent retrieves the products and batches relation queries.
```

`setWith(array $relations): static` changes the current instance and returns it
for chaining. `getWith(): array` returns that instance's override or the class's
static default array, without normalizing the declaration. Neither method changes
static state. New filter instances retain their defaults, including in queue
workers and long-running application servers. `beforeUsing()` clones retain
their own copy of the override.

Nested relation names, nested arrays, selected columns and constraint closures
are supported. Use `setWith()` for closures that need runtime context:

```php
$filter->setWith([
    'brand' => fn ($relation) => $relation->where('active', true), // Restrict the loaded brand records.
    'category.parent', // Also retrieve each category's parent.
]);
```

These constraints affect loaded relations; they do not filter parent products.
Use `Field::related()` or a trusted access predicate when parent visibility
must depend on related records. Mandatory relation access rules belong in
model scopes, not optional eager-loading defaults.

Existing caller/model eager loads take precedence on matching relation names,
preserving their constraint closures and column selections. This includes parent
entries Laravel adds for nested relations: `with('brand.country')` already
configures `brand`. Other filter relations are added. Calling the normal builder's
`with()` after applying the filter follows Laravel's usual replacement behavior.
`setWith([])` disables only filter defaults;
use the builder's `without()` or `withOnly()` to change its other eager loads.

Include primary/foreign keys in selected columns so Laravel can match relations.
For a JSON-backed accessor foreign key, select the JSON storage column too
(for example, `products.assignments`). Relation loading uses your normal Eloquent
relation and accessor; see [JSON relations](json-columns.md).

`apply()` registers eager loads without executing SQL. Query and eager-load changes
are staged together; compilation failures leave the caller unchanged. Laravel
resolves relation names and runs eager-load callbacks when retrieving records,
so errors at retrieval are handled normally by Laravel. Eager loading belongs to
`BaseEBFilter`; `BaseQBFilter` has no model relations or `with` API.

Relation declarations are trusted application configuration. Do not pass arbitrary
request input into `setWith()`; a request's top-level `with` key is not an instruction
to load a relation.

## Query Builder

`Abstracts\BaseQBFilter` shares the constructor, `Field` definitions, request
format, validation, limits and access hooks with `BaseEBFilter`. It accepts
`Illuminate\Database\Query\Builder`. Extend the abstract base for each queried
model or table:

```php
use Alif\QueryFilter\Abstracts\BaseQBFilter;
use Illuminate\Support\Facades\DB;

class DocumentQueryFilter extends BaseQBFilter
{
    /** Match the document query's explicit table aliases. */
    protected function fields(): array
    {
        return [
            'title' => 'd.title',
            'branch' => Field::make('b.name')->operators([FilterOperator::Equal]),
        ];
    }
}

$query = DB::table('documents as d')
    ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
    ->select('d.*');

$filter = new DocumentQueryFilter($request->query());
$filter->apply($query);
```

Dotted SQL paths refer to qualified columns. Add joins on the caller's query;
the Query Builder base does not infer Eloquent relations or model accessor
mappings. `Field::related()` requires `BaseEBFilter`. Custom predicates and
`before()` use the Query Builder type instead of the Eloquent type.

## Relations

Use `Field::related('notes.body')` for an EXISTS predicate. It supports concrete
Eloquent relationships, including HasMany, BelongsToMany, and nested paths.
It preserves relation/global/soft-delete scopes and does not load related models
or multiply parent records. MorphTo is rejected because it requires an explicit
model-type policy; use a custom `whereHasMorph` predicate for that case.

All operators in one field map must match **one related record**. Separate tree
leaves or separate public fields produce independent EXISTS predicates, which
may match different records. Use a callback with one `whereHas` when multiple
related columns must belong to the same child.

Related `ne`, `nin`, `is_null`, and `is_empty` mean an existing related row satisfies the
comparison. They do not mean that the relationship is absent. For absence use a
custom `whereDoesntHave` callback. Collection sorting requires an explicit
aggregation policy and is therefore unavailable on `Field::related`.

String paths and `Field::make('author.name')` retain the existing join behavior
for BelongsTo/HasOne relations, including one-of-many. Joins use separate aliases
for complete paths, reuse overlapping paths, preserve relation scopes in
subqueries, and select root model columns. Missing or scoped-out related rows
can match null checks. Enforce HasOne uniqueness or use `latestOfMany()` to avoid
duplicate roots.

When joins are used, qualify root predicates, selections and global scopes:
`where('products.id', 1)`, `select('products.name')`. Arbitrary unqualified SQL
cannot be rewritten safely. Prefer EXISTS fields when related sorting and
missing-relation null semantics are unnecessary. EXISTS fields require the
model's original FROM table; root FROM aliases and raw FROM expressions are
not supported there. Join fields support string table aliases but reject raw
FROM expressions. Cross-database relationships follow Eloquent's limitations.

## Validation and authorization

```php
'name' => Field::make('name')
    ->transform(fn ($value) => is_string($value) ? trim($value) : $value)
    ->rules(['string', 'max:100']),
'salary' => Field::make('salary')
    ->authorize(fn () => auth()->user()?->can('view-salary') ?? false),
```

Transformations run on each filter operand after shape/size checks. Laravel
rules run on each transformed value. Shape and size are checked again afterward.
Null/empty-check flags bypass column-value rules and transforms. Field authorization
runs once per requested public field, including when it is searched or sorted.
Unauthorized fields raise `AuthorizationException`; unused fields are not checked.

Subclass hooks are optional:

```php
/** Grant access to this filter before any request conditions are compiled. */
protected function authorize(): bool
{
    return auth()->user()?->can('view-reports') ?? false;
}

/** Normalize request input while retaining meaningful falsy values. */
protected function prepare(array $parameters): array
{
    // Normalize application input without dropping false, 0, or null.
    return $parameters;
}

/** Add application-specific request validation to the built-in shape checks. */
protected function rules(): array
{
    return ['filter.price.gte' => ['sometimes', 'numeric', 'min:0']];
}
```

Order: class authorization → preparation → request rules → field authorization,
operand transformation and validation → access hooks → query compilation → commit query.
For public names containing literal dots, prefer `Field::rules()` rather than
Laravel wildcard paths. Authorization grants use of a filter; record restrictions
belong in access hooks or on the caller's query.

## Record access before request filters

Override `before()` for mandatory record restrictions. Inject access information
from authenticated server context rather than accepting it from filter input:

```php
use Alif\QueryFilter\Abstracts\BaseEBFilter;
use Illuminate\Database\Eloquent\Builder;

class DocumentFilter extends BaseEBFilter
{
    /** Receive request input separately from authenticated access context. */
    public function __construct(array $parameters, private int $branchId)
    {
        parent::__construct($parameters);
    }

    /** Declare the public document fields. */
    protected function fields(): array
    {
        return ['title', 'status', 'created_at'];
    }

    /** Restrict every application to the authenticated user's branch. */
    protected function before(Builder $query): void
    {
        $query->where('documents.branch_id', $this->branchId);
    }
}

// Authenticated route: the branch is taken from the user, never request input.
$filter = new DocumentFilter($request->query(), $request->user()->branch_id);
return Document::filter($filter)->paginate(25);
```

Use `beforeUsing()` to add another trusted restriction to the model filter:

```php
$allowedDocumentIds = $accessPolicy->documentIdsFor($request->user());
$filter = (new DocumentFilter($request->query(), $request->user()->branch_id))
    ->beforeUsing(
        fn (Builder $query) => $query->whereIn('documents.id', $allowedDocumentIds),
    );
$query = Document::query();
$filter->apply($query);
```

`beforeUsing(Closure): static` returns a clone and adds a callback. Keep the
returned instance. Multiple callbacks run after `before()` in registration order,
each as an AND restriction; they do not replace the subclass hook. Hooks run
even when input is empty. Existing caller conditions, access predicates and
request groups remain separately grouped, so an OR cannot widen access.

Use only WHERE predicates (`where`, `whereIn`, `whereExists`, or Eloquent
`whereHas`) inside hooks. Configure eager loading with `$with`/`setWith()` or on
the caller; configure joins, selection, scopes and sorting on the caller's builder.
Non-WHERE changes raise `LogicException`;
union queries are rejected because a union branch could bypass record access.
Hook failures leave the caller's builder unchanged. Callback return values are
ignored. Keep a model global scope for access rules that must also apply to
queries made outside this filter.

## Custom predicates

```php
use Illuminate\Database\Eloquent\Builder;

'has_notes' => Field::custom(function (Builder $query, mixed $value) {
    filter_var($value, FILTER_VALIDATE_BOOLEAN)
        ? $query->whereHas('notes')
        : $query->whereDoesntHave('notes');
})->rules(['boolean']),
```

A callback receives `(Builder $query, mixed $value, string $operator)` and defaults
to the `eq` operator. Scalar input stays scalar; list input stays a list.
Custom fields are not searchable or sortable. Use `operators()` to opt into other
operators. Each callback is isolated in a WHERE group; internal ORs cannot escape
caller constraints. Use bound values, including in any custom raw expressions.

Callbacks must add predicates using `where`, `whereHas`, `whereExists`, and
related methods. Their return value is ignored. Configure selection, joins,
ordering and global scopes on the caller's builder, and eager loading there or
through `$with`/`setWith()`. Changes to non-WHERE query clauses, eager loading or
scope removal inside callbacks raise `LogicException`.
Register global scopes on the model, never inside a predicate callback.
Application callbacks and rules remain trusted code and can execute SQL if they
explicitly do so; built-in compilation never does.

The compiler stages changes on a cloned builder. Validation, relationship errors,
and callback exceptions leave the original query unchanged. Unexpected exceptions
are propagated. A filter can be reused on independent builders without carrying
join state or request caches between applications. Reapplying to one builder
adds predicates again while reusing its existing relation joins.

## Existing integrations and migration

The next release is **v2.0.0**, a major update with breaking changes from v1.1.6.
It requires PHP 8.3 or higher and Laravel 11 or higher. There is no compatibility
layer for the previous callback API.

Follow the [v1.x to v2.0 upgrade guide](../UPGRADING.md) for dependency changes,
old-to-new filter definitions, client request updates, null and boolean semantics,
access hooks, eager loading, JSON relations and removal of published files.

## Performance

Only requested related fields create joins/EXISTS clauses. No model discovery,
schema lookups, reflection-driven dispatch, database listeners, or result caching
run automatically. Request budgets bound built-in parser work. Index foreign
keys and frequent equality/range/sort columns. Prefix search may use an index
depending on collation and driver; contains/suffix patterns usually require scans.
Use application full-text search for large text workloads.

Run `composer benchmark` for separate query-building and SQLite execution timings
on a deterministic indexed fixture. Compare execution plans on your production
database before drawing conclusions. Tests execute SQLite queries and include
opt-in PostgreSQL JSONB integration tests, also enabled in a dedicated CI job.
MySQL/MariaDB tests check SQL compilation, not execution on those servers. See
the [PostgreSQL test instructions](json-columns.md#run-postgresql-integration-tests).
