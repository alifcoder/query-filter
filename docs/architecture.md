# Architecture and design decisions

Query Filter turns model-specific public fields into bounded, validated queries. Each model gets a concrete filter such as `ProductFilter`, extending the abstract `Abstracts\BaseEBFilter`. A concrete `BaseQBFilter` subclass exposes the same API for Query Builder. PHP 8.3 is the minimum runtime, including support for typed class constants.

## Reference review

The design was informed by a source review of [kettasoft/filterable at commit `556e4a605a14efc0a28952bd2e2bac83a704cc12`](https://github.com/kettasoft/filterable/tree/556e4a605a14efc0a28952bd2e2bac83a704cc12). This project implements its own API; it does not promise compatibility or feature parity with Filterable.

Useful ideas from the reference include explicit fields, field-specific operators, public aliases, relationship filtering, nested boolean groups, validation and model integration. The review also identified behaviors worth avoiding in this implementation:

| Observation in the reviewed commit | Design decision here |
| --- | --- |
| [`TreeNode::parse()`](https://github.com/kettasoft/filterable/blob/556e4a605a14efc0a28952bd2e2bac83a704cc12/src/Support/TreeNode.php#L38) recurses without a depth or node budget and accepts a node containing both `and` and `or`. | Reject ambiguous shapes and enforce configurable complexity limits before query application. |
| [`Dissector`](https://github.com/kettasoft/filterable/blob/556e4a605a14efc0a28952bd2e2bac83a704cc12/src/Engines/Foundation/Parsers/Dissector.php) treats every colon-containing string as an operator expression, including URLs and timestamps. | Operators are explicit object keys or explicit tree properties. Ordinary scalar values remain literal. |
| [`PayloadFactory`](https://github.com/kettasoft/filterable/blob/556e4a605a14efc0a28952bd2e2bac83a704cc12/src/Engines/Foundation/PayloadFactory.php#L75) can fall back to a default operator after an invalid operator in permissive mode. | Unknown or disallowed operators produce validation errors; their meaning never changes silently. |
| [`DefaultHandler`](https://github.com/kettasoft/filterable/blob/556e4a605a14efc0a28952bd2e2bac83a704cc12/src/Exceptions/Handlers/DefaultHandler.php#L35) can suppress unexpected exceptions unless global exception strictness is enabled. | Authorization, validation and programming errors propagate through Laravel's normal exception handling. |
| [`InteractsWithRelationsFiltering`](https://github.com/kettasoft/filterable/blob/556e4a605a14efc0a28952bd2e2bac83a704cc12/src/Traits/InteractsWithRelationsFiltering.php#L39) supports a root relation permission that permits all descendant fields. | Each public field maps to an explicit column or relationship path. Request input cannot discover arbitrary relation methods. |
| [`Sorter`](https://github.com/kettasoft/filterable/blob/556e4a605a14efc0a28952bd2e2bac83a704cc12/src/Foundation/Sorting/Sorter.php#L286) appends its default sort even when a request supplies sorting. | Default sorting is a fallback. Supplied sorts use the same public field definitions and permissions. |
| [`Invoker`](https://github.com/kettasoft/filterable/blob/556e4a605a14efc0a28952bd2e2bac83a704cc12/src/Foundation/Invoker.php#L383) uses a previously generated query cache key for terminal calls. Later query changes need special care. | Return the normal Eloquent builder. Applications own result caching, tenant identity and invalidation. |
| [`Profiler`](https://github.com/kettasoft/filterable/blob/556e4a605a14efc0a28952bd2e2bac83a704cc12/src/Foundation/Profiler/Profiler.php) attaches a global query listener, rescans prior queries and exports request data. | Filtering installs no automatic profiler or request logger. Use Laravel's existing query diagnostics. |

The parser observations were also checked directly: the reviewed source accepted 100 nested groups, chose `and` when both group keys were present, and split `https://example.com` into an operator and a truncated value. These observations describe the pinned commit, not every upstream version.

## Small, explicit responsibilities

| Component | Responsibility |
| --- | --- |
| `Abstracts\BaseFilter` | Share authorization, validation, access hooks and atomic compilation across both builders. |
| `Abstracts\BaseEBFilter` / `Abstracts\BaseQBFilter` | Abstract builder-specific contracts; application subclasses declare fields and access hooks. |
| `Field` | Hold an application-owned field path, operator policy, capabilities and optional validation, transformation, authorization or callback. Fluent configuration methods clone the definition. |
| `JsonColumn` / `Enums\JsonType` | Describe a developer-owned JSON scalar path and compile its typed, driver-specific expression. |
| `FilterLimits` | Bound conditions, nesting, list sizes, sort count, requested limits, string length and binding count. |
| `Support\FilterParser` | Validate request shape, field permissions and values, producing normalized conditions before query application. |
| `Support\FilterQuery` | Compile normalized conditions and boolean groups into a staged query; resolve model relationships only for Eloquent. |
| `Support\QueryConstraints` | Group trusted access predicates and reject non-predicate callback changes. |
| `Enums\FilterOperator` | Normalize operands and implement the supported SQL comparisons with bound values. |
| `Support\RelationJoinResolver` | Resolve and reuse explicit paths through supported to-one Eloquent relationships. |
| `Support\ColumnResolver` | Resolve model-declared JSON attributes and adapt mapped BelongsTo foreign keys for query compilation. |
| `Support\JsonBelongsTo` | Supply a JSON foreign-key expression to Laravel's existence-query logic without changing the application's relation. |
| `Traits\Filterable` | Apply an existing filter instance or resolve an explicitly configured `BaseEBFilter` class. |

The package does not need a separate engine for each request form. Flat `filter` parameters and nested `where` groups share field definitions, validation and operator behavior. Custom domain logic is an explicit `Field::custom()` callback, rather than automatic invocation of methods named by the request. Public fields live in each model filter's required `fields()` method, never constructor input. The constructor accepts parameters and optional limits. Each model filter inherits the shared lifecycle directly.

## Request boundary

The application declares public fields. Incoming field names select from that allowlist; they do not become free-form SQL identifiers. Field mapping occurs after that lookup. Operators come from a fixed enum and may be restricted further per field.

Models can declare a `queryFilterColumns()` map from virtual attributes to typed `JsonColumn` definitions. After field validation, column resolution applies that map to root or related fields and supported relation keys. The map describes storage, not field permissions. SQL locations are explicit; the compiler does not inspect PHP accessor bodies or infer SQL from request paths.

The shared pipeline checks class authorization, prepares input, applies optional request rules, and parses the full request. Trusted access hooks then run before request predicates are compiled. Compilation uses a clone of the caller's builder; the resulting query is copied back only after success. Invalid input raises `ValidationException`; denied field access raises Laravel's `AuthorizationException`. Transformation callbacks are application code and should be deterministic. Unexpected callback exceptions are not treated as empty filters.

Custom field callbacks and access hooks add predicates to nested queries. Their contract permits WHERE changes only; configure joins, selections and global scopes on the caller's builder. Eager loads belong on that builder or in the filter's `$with`/`setWith()` configuration. A staged query protects the original builder from compilation failures, but it cannot undo external side effects performed by application callbacks.

`before()` supplies mandatory access predicates, such as a branch ID injected from the authenticated user. `beforeUsing()` clones the filter and adds a trusted callback without replacing that hook. These predicates run even for empty input. Caller conditions, access predicates and request groups are ANDed inside separate SQL parentheses; an OR in one group cannot bypass another. Union queries are rejected to avoid a second branch bypassing the access rule. Access values come from server context, not request fields. Use model global scopes for rules that must also cover queries outside the filter.

`null`, `false`, `0`, `"0"` and an empty string have distinct meanings. Equality lists can include `null`; null and empty checks have explicit boolean operands. `is_empty=true` means SQL NULL or an exact empty string, and `false` means neither. Whitespace and empty JSON arrays/objects are values. `like` accepts a SQL pattern, while literal pattern operators escape wildcard characters. Colons in URLs or timestamps require no special handling.

Only the model's application-defined `$filterClass` or an explicitly passed class name controls class construction. `Model::filter()` reads request query parameters; explicit arrays work in HTTP controllers, commands and jobs. The request body does not override query parameters in this convenience path. Pass a validated body array explicitly when using JSON input.

## Relationships and query cost

Query Builder uses explicit SQL column mappings and joins supplied by the caller. It shares the parser, operators and access-hook behavior with Eloquent, but does not infer model relationships or accessor mappings. The relationship features below belong to the Eloquent base.

An Eloquent filter may declare a protected static `$with` array. `setWith()` replaces those defaults for one instance; `getWith()` reads that instance's declaration. Static state is never mutated by the setter. Laravel parses the relation array on the staged builder, preserving existing caller/model constraints for matching relation names. SQL and eager-load configuration are committed only after compilation succeeds. Retrieval still uses Eloquent's normal batched loading, without executing extra queries during filter application.

To-one field paths use reusable joins so filtering, searching and sorting can share the same resolved relation. Eloquent relation constraints and model scopes are retained by the resolver. Aliases identify the complete relationship path, allowing creator and updater paths to reach the same tables without being mixed together.

`Field::related()` uses relationship existence constraints for filtering and searching, including to-many relations. It does not allow sorting, because a collection of related values does not define a single sort key. This avoids multiplying parent rows merely to test whether a matching child exists.

Mapped JSON foreign keys work in ordinary `BelongsTo` joins and EXISTS queries. A small query-only relation adapter retains Laravel's correlation and self-relation behavior. The owner key stays a real database column; mapped owner keys and inverse `HasOne`/`HasMany` JSON relation keys are rejected explicitly. The application's accessors, lazy/eager loading, and native Eloquent query methods are unchanged. See the [JSON guide](json-columns.md) for supported types and indexing examples.

The package does not infer database indexes, fetch schema metadata for every field, execute the filtered query during parsing, or cache application results. Developers should index commonly filtered foreign keys and comparison columns and inspect actual database query plans. Leading-wildcard matching may still require scans; parser speed cannot remove that database cost.

## Laravel integration

The service provider registers the model-filter generator when Laravel runs in the console. Composer package discovery loads the provider in Laravel applications. Field definitions and request limits belong to concrete application filters.

Run `php artisan query-filter:make MemberFilter` to create `App\Filters\MemberFilter`. Nested names such as `Admin/MemberFilter` use the normal Laravel namespace convention. The generated class exposes only `id`, with `eq` and `in` operators, until the developer adds more fields. Existing files are preserved.

The model scope accepts either an `EBFilterInterface` instance or a concrete `BaseEBFilter` subclass. It rejects abstract classes before container resolution. Valid class names use Laravel's container so constructor dependencies can be injected. Existing custom filter instances keep their established construction and application behavior.

The v2.0.0 major release keeps one field-based filtering pipeline. Upgrading earlier integrations requires rewriting field definitions, callbacks and request shapes as described in the [upgrade guide](../UPGRADING.md). Mandatory access predicates belong in trusted hooks, and custom domain predicates belong in `Field::custom()`.

## Verification boundaries

Database-backed tests use SQLite in memory to check returned records, joins, boolean grouping, validation, repeated use and integration. PostgreSQL JSONB integration tests execute mapped scalar and relation queries, including scopes, nulls and sorting; a dedicated CI job enables these cases. MySQL/MariaDB coverage checks SQL generation only and does not establish runtime parity. Compatibility claims should follow the tested PHP and Laravel matrix.

Performance measurements must distinguish filter construction from database execution. A query-building benchmark measures PHP overhead; production query plans and data distribution determine database latency. Neither a fast benchmark nor result caching is a substitute for bounded requests and appropriate indexes.
