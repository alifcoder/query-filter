<?php

namespace Alif\QueryFilter\Tests; // Keep test fixtures separate from production classes.

use Alif\QueryFilter\Enums\JsonType; // Use JsonType in this test fixture.
use Alif\QueryFilter\Field; // Use Field in this test fixture.
use Alif\QueryFilter\JsonColumn; // Use JsonColumn in this test fixture.
use Alif\QueryFilter\Traits\Filterable; // Enable model filter resolution for this fixture.
use Illuminate\Config\Repository; // Use Repository in this test fixture.
use Illuminate\Container\Container; // Use Container in this test fixture.
use Illuminate\Database\Capsule\Manager; // Use Manager in this test fixture.
use Illuminate\Database\Eloquent\Builder; // Use Builder in this test fixture.
use Illuminate\Database\Eloquent\Model; // Use Model in this test fixture.
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Use BelongsTo in this test fixture.
use Illuminate\Database\Eloquent\Relations\HasOne; // Use HasOne in this test fixture.
use Illuminate\Database\Eloquent\SoftDeletes; // Exercise soft-delete scopes on the fixture model.
use Illuminate\Database\Schema\Blueprint; // Use Blueprint in this test fixture.
use Illuminate\Support\Facades\Facade; // Use Facade in this test fixture.
use Illuminate\Translation\ArrayLoader; // Use ArrayLoader in this test fixture.
use Illuminate\Translation\Translator; // Use Translator in this test fixture.
use Illuminate\Validation\Factory; // Use Factory in this test fixture.
use Illuminate\Validation\ValidationException; // Use ValidationException in this test fixture.
use InvalidArgumentException; // Use InvalidArgumentException in this test fixture.
use PHPUnit\Framework\Attributes\DataProvider; // Use DataProvider in this test fixture.
use PHPUnit\Framework\TestCase; // Use TestCase in this test fixture.

/** The optional PostgreSQL run owns a temporary schema, never application tables. */
final class JsonRelationFilterTest extends TestCase // Group regression coverage for json relation filter.
{
    private ?Manager $database = null; // Retain database for this test fixture.
    private ?string $schema = null; // Retain schema for this test fixture.

    /** Create isolated framework services and database fixtures for each test. */
    protected function setUp(): void // Create isolated framework services and database fixtures for each test.
    {
        parent::setUp(); // Initialize PHPUnit before creating isolated fixtures.

        $driver = $this->providedData()[0]; // Prepare driver for this regression scenario.
        if ($driver === 'pgsql' && ! getenv('QUERY_FILTER_PG_PORT')) { // Handle the fixture-specific condition before continuing.
            self::markTestSkipped('Set QUERY_FILTER_PG_PORT to run PostgreSQL JSONB integration tests.'); // Use the explicit fixture constant, enum, or framework operation.
        }

        $container = new Container(); // Create framework services isolated from other tests.
        Container::setInstance($container); // Point framework lookups at this test container.
        $container->instance('config', new Repository()); // Register the framework dependency required by this fixture.
        $container->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $container)); // Register the framework dependency required by this fixture.
        Facade::clearResolvedInstances(); // Prevent facade instances from leaking between tests.
        Facade::setFacadeApplication($container); // Point framework lookups at this test container.

        $this->database = new Manager($container); // Create the database manager for isolated fixture queries.
        $this->database->addConnection($driver === 'sqlite' // Configure the isolated database connection for this scenario.
            ? ['driver' => 'sqlite', 'database' => ':memory:'] // Use isolated SQLite when the optional PostgreSQL case is not selected.
            : [ // Use an isolated in-memory database for the SQLite provider.
                'driver' => 'pgsql', // Select the database grammar used by this fixture.
                'host' => getenv('QUERY_FILTER_PG_HOST') ?: '127.0.0.1', // Read the optional PostgreSQL test connection host.
                'port' => getenv('QUERY_FILTER_PG_PORT'), // Read the optional PostgreSQL test connection port.
                'database' => getenv('QUERY_FILTER_PG_DATABASE') ?: 'postgres', // Select this fixture connection database.
                'username' => getenv('QUERY_FILTER_PG_USER') ?: get_current_user(), // Read the optional PostgreSQL test connection identity.
                'password' => getenv('QUERY_FILTER_PG_PASSWORD') ?: '', // Read the optional PostgreSQL test connection credential.
            ]);
        $this->database->setAsGlobal(); // Connect fixture models to the isolated database manager.
        $this->database->bootEloquent(); // Connect fixture models to the isolated database manager.

        if ($driver === 'pgsql') { // Handle the fixture-specific condition before continuing.
            $this->schema = 'qf_json_tests_' . bin2hex(random_bytes(8)); // Prepare the fixture operation needed by this regression.
            $this->database->getConnection()->statement('CREATE SCHEMA "' . $this->schema . '"'); // Prepare the fixture operation needed by this regression.
            $this->database->getConnection()->statement('SET search_path TO "' . $this->schema . '"'); // Prepare the fixture operation needed by this regression.
        }

        $this->createFixtures(); // Prepare the fixture operation needed by this regression.
    }

    /** Release database and framework state so fixtures cannot affect later tests. */
    protected function tearDown(): void // Release database and framework state so fixtures cannot affect later tests.
    {
        if ($this->database !== null) { // Handle the fixture-specific condition before continuing.
            if ($this->schema !== null) { // Handle the fixture-specific condition before continuing.
                $this->database->getConnection()->statement('DROP SCHEMA "' . $this->schema . '" CASCADE'); // Prepare the fixture operation needed by this regression.
            }
            $this->database->getConnection()->disconnect(); // Release the fixture database connection.
        }
        Model::clearBootedModels(); // Remove model state retained by previous fixture queries.
        Model::unsetConnectionResolver(); // Remove model state retained by previous fixture queries.
        Facade::clearResolvedInstances(); // Prevent facade instances from leaking between tests.
        Facade::setFacadeApplication(null); // Detach the isolated framework container during cleanup.
        Container::setInstance(null); // Detach the isolated framework container during cleanup.

        parent::tearDown(); // Complete PHPUnit cleanup after releasing framework state.
    }

    /** Supply drivers scenarios for the parameterized test. */
    public static function drivers(): iterable // Supply drivers scenarios for the parameterized test.
    {
        yield 'sqlite' => ['sqlite']; // Exercise sqlite.
        yield 'postgresql' => ['pgsql']; // Exercise postgresql.
    }

    /** Verify that unsupported json relation key directions fail before query mutation. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_unsupported_json_relation_key_directions_fail_before_query_mutation(string $driver): void // Verify that unsupported json relation key directions fail before query mutation.
    {
        foreach ([JsonMappedOwnerProduct::class, JsonInverseBrand::class] as $model) { // Run the same assertions for each relevant fixture case.
            foreach ([Field::make('unsupported.name'), Field::related('unsupported.name')] as $field) { // Run the same assertions for each relevant fixture case.
                $query = $model::query(); // Prepare query for this regression scenario.
                $sql = $query->toSql(); // Snapshot generated SQL for later mutation assertions.
                $bindings = $query->getBindings(); // Snapshot placeholder values before the operation under test.
                $exception = null; // Prepare exception for this regression scenario.
                try { // Exercise the failure path without hiding later state assertions.
                    (new TestModelFilter(['filter' => ['name' => 'Acme']], ['name' => $field]))->apply($query); // Compile the filter into the caller-owned fixture query.
                } catch (InvalidArgumentException $error) { // Inspect the expected failure and preserve caller-state assertions.
                    $exception = $error; // Prepare exception for this regression scenario.
                }
                self::assertInstanceOf(InvalidArgumentException::class, $exception); // Check the fixture behavior required by this regression.
                self::assertSame($sql, $query->toSql()); // Check the expected SQL or diagnostic text.
                self::assertSame($bindings, $query->getBindings()); // Check parameter values and binding order after compilation.
            }
        }
    }

    /** Verify that json foreign key filters use the public attribute name. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_json_foreign_key_filters_use_the_public_attribute_name(string $driver): void // Verify that json foreign key filters use the public attribute name.
    {
        self::assertSame([1], $this->query(['filter' => ['brand_id' => 1]])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([2, 4, 5, 8], $this->query(['filter' => ['brand_id' => ['ne' => 1]]])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([1, 3, 7], $this->query(['filter' => ['brand_id' => ['in' => [1, null]]]])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that json null and missing keys behave like nullable columns. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_json_null_and_missing_keys_behave_like_nullable_columns(string $driver): void // Verify that json null and missing keys behave like nullable columns.
    {
        self::assertSame([3, 7], $this->query(['filter' => ['brand_id' => null]])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([3, 7], $this->query(['filter' => ['caption' => ['is_null' => true]]])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([1, 2, 4, 5, 8], $this->query(['filter' => ['brand_id' => ['is_null' => false]]])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that json empty checks distinguish missing null and exact empty text from other values. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_json_empty_checks_distinguish_missing_null_and_exact_empty_text_from_other_values(string $driver): void // Verify that json empty checks distinguish missing null and exact empty text from other values.
    {
        foreach (['', ' ', 0, false, [], (object) [], 'Present'] as $offset => $caption) { // Run the same assertions for each relevant fixture case.
            $this->database->table('json_products')->insert([ // Load contrasting rows for the result-set assertions.
                'id' => 9 + $offset, // Define the id fixture value or field mapping.
                'name' => 'Empty check ' . $offset, // Define the name fixture value or field mapping.
                'tenant_id' => 1, // Define the tenant_id fixture value or field mapping.
                'assignments' => json_encode(['caption' => $caption], JSON_THROW_ON_ERROR), // Define the assignments fixture value or field mapping.
            ]);
        }

        // Fixture 3 stores JSON null; fixture 7 omits the key; fixture 9 stores "".
        self::assertSame([3, 7, 9], $this->query([ // Compare the expected value and PHP type for this scenario.
            'filter' => ['caption' => ['is_empty' => true]], // Provide named filter operands for this request.
        ])->get()->modelKeys()); // Compare the matching model identifiers with the expected fixture rows.
        self::assertSame([1, 2, 4, 5, 8, 10, 11, 12, 13, 14, 15], $this->query([ // Compare the expected value and PHP type for this scenario.
            'filter' => ['caption' => ['is_empty' => false]], // Provide named filter operands for this request.
        ])->get()->modelKeys()); // Compare the matching model identifiers with the expected fixture rows.
    }

    /** Verify that typed json zero and false are not empty. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_typed_json_zero_and_false_are_not_empty(string $driver): void // Verify that typed json zero and false are not empty.
    {
        foreach ([9 => ['priority' => 0, 'active' => false], 10 => ['priority' => null, 'active' => null]] as $id => $assignments) { // Run the same assertions for each relevant fixture case.
            $this->database->table('json_products')->insert([ // Load contrasting rows for the result-set assertions.
                'id' => $id, // Define the id fixture value or field mapping.
                'name' => 'Typed empty check ' . $id, // Define the name fixture value or field mapping.
                'tenant_id' => 1, // Define the tenant_id fixture value or field mapping.
                'assignments' => json_encode($assignments, JSON_THROW_ON_ERROR), // Define the assignments fixture value or field mapping.
            ]);
        }

        foreach (['priority', 'active'] as $field) { // Run the same assertions for each relevant fixture case.
            self::assertSame([7, 10], $this->query([ // Compare the expected value and PHP type for this scenario.
                'filter' => [$field => ['is_empty' => true]], // Provide named filter operands for this request.
            ])->get()->modelKeys(), $field); // Compare the matching model identifiers with the expected fixture rows.
            self::assertSame([1, 2, 3, 4, 5, 8, 9], $this->query([ // Compare the expected value and PHP type for this scenario.
                'filter' => [$field => ['is_empty' => false]], // Provide named filter operands for this request.
            ])->get()->modelKeys(), $field); // Compare the matching model identifiers with the expected fixture rows.
        }
    }

    /** Verify that integer json ranges and sorting are numeric. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_integer_json_ranges_and_sorting_are_numeric(string $driver): void // Verify that integer json ranges and sorting are numeric.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['priority' => ['gte' => 3, 'lte' => 20]], // Provide named filter operands for this request.
            'sort' => 'priority', // Declare the request ordering for this scenario.
        ]);

        self::assertSame([5, 2, 8, 4], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([1, 5, 2, 8, 4, 3], $this->query([ // Compare the expected value and PHP type for this scenario.
            'filter' => ['priority' => ['is_null' => false]], // Provide named filter operands for this request.
            'sort' => 'priority', // Declare the request ordering for this scenario.
        ])->get()->modelKeys()); // Compare the matching model identifiers with the expected fixture rows.
    }

    /** Verify that json search handles text and numeric attributes. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_json_search_handles_text_and_numeric_attributes(string $driver): void // Verify that json search handles text and numeric attributes.
    {
        self::assertSame([1], $this->query(['search' => ['caption' => 'RED 1']])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([2, 3], $this->query(['search' => ['priority' => '10']])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([1], $this->query(['filter' => ['caption' => ['contains' => '%']]])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that json boolean and uuid types accept bound values. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_json_boolean_and_uuid_types_accept_bound_values(string $driver): void // Verify that json boolean and uuid types accept bound values.
    {
        self::assertSame([2, 4], $this->query(['filter' => ['active' => false]])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([1, 3, 5, 8], $this->query(['filter' => ['active' => true]])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([2], $this->query([ // Compare the expected value and PHP type for this scenario.
            'filter' => ['owner_uuid' => '22222222-2222-4222-8222-222222222222'], // Provide named filter operands for this request.
        ])->get()->modelKeys()); // Compare the matching model identifiers with the expected fixture rows.
    }

    /** Verify that accessor belongs to supports filter search and sort. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_accessor_belongs_to_supports_filter_search_and_sort(string $driver): void // Verify that accessor belongs to supports filter search and sort.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['brand.name' => ['in' => ['Acme', 'Bravo']]], // Provide named filter operands for this request.
            'search' => ['brand.name' => 'a'], // Provide search terms using public field names.
            'sort' => '-brand.priority', // Declare the request ordering for this scenario.
        ]);

        self::assertSame([2, 1], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertCount(1, $query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
        self::assertSame([2], $this->query(['filter' => ['brand.priority' => ['gt' => 2]]])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that joined relations preserve tenant scopes soft deletes and nulls. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_joined_relations_preserve_tenant_scopes_soft_deletes_and_nulls(string $driver): void // Verify that joined relations preserve tenant scopes soft deletes and nulls.
    {
        self::assertSame([], $this->query(['filter' => ['brand.name' => 'Hidden']])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([], $this->query(['filter' => ['brand.name' => 'Retired']])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([3, 4, 5, 7, 8], $this->query([ // Compare the expected value and PHP type for this scenario.
            'filter' => ['brand.name' => ['is_null' => true]], // Provide named filter operands for this request.
        ])->get()->modelKeys()); // Compare the matching model identifiers with the expected fixture rows.
        self::assertCount(7, $this->query(['sort' => 'brand.name'])->get()); // Check the expected number of generated clauses or fixture results.
    }

    /** Verify that nested json relations and snake case methods work. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_nested_json_relations_and_snake_case_methods_work(string $driver): void // Verify that nested json relations and snake case methods work.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['brand.country.name' => 'France'], // Provide named filter operands for this request.
            'search' => ['brand.country.name' => 'fr'], // Provide search terms using public field names.
            'sort' => 'brand.country.name', // Declare the request ordering for this scenario.
        ]);

        self::assertSame([2], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertCount(2, $query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
        self::assertSame([1], $this->query([ // Compare the expected value and PHP type for this scenario.
            'filter' => ['brand.country_of_origin.name' => 'Uzbekistan'], // Provide named filter operands for this request.
        ])->get()->modelKeys()); // Compare the matching model identifiers with the expected fixture rows.
    }

    /** Verify that json relation joins are reused across filter applications. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_json_relation_joins_are_reused_across_filter_applications(string $driver): void // Verify that json relation joins are reused across filter applications.
    {
        $query = $this->query(['filter' => ['brand.name' => 'Acme']]); // Prepare query for this regression scenario.
        (new TestModelFilter(['sort' => 'brand.priority', 'search' => ['brand.name' => 'AC']], [ // Use a concrete test filter with explicit fields.
            'brand.priority', 'brand.name', // Supply the literal fixture argument used by this scenario.
        ]))->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertCount(1, $query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
        self::assertSame([1], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that or groups and search cannot escape the callers tenant. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_or_groups_and_search_cannot_escape_the_callers_tenant(string $driver): void // Verify that or groups and search cannot escape the callers tenant.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'where' => ['or' => [ // Exercise the explicit boolean condition tree.
                ['field' => 'brand.name', 'operator' => 'eq', 'value' => 'Acme'], // Compare brand.name with eq inside the boolean tree.
                ['field' => 'name', 'operator' => 'eq', 'value' => 'Gamma'], // Compare name with eq inside the boolean tree.
            ]],
            'search' => ['brand.name' => 'Acme', 'caption' => 'Red', 'name' => 'Gamma'], // Provide search terms using public field names.
        ]);

        self::assertSame([1, 3], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that access hooks constrain or filters and search through json relations. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_access_hooks_constrain_or_filters_and_search_through_json_relations(string $driver): void // Verify that access hooks constrain or filters and search through json relations.
    {
        $parameters = [ // Prepare parameters for this regression scenario.
            'where' => ['or' => [ // Exercise the explicit boolean condition tree.
                ['field' => 'brand', 'operator' => 'eq', 'value' => 'Acme'], // Compare brand with eq inside the boolean tree.
                ['field' => 'name', 'operator' => 'eq', 'value' => 'Gamma'], // Compare name with eq inside the boolean tree.
            ]],
            'search' => ['brand' => 'Acme', 'name' => 'Gamma'], // Provide search terms using public field names.
        ];

        foreach ([Field::make('brand.name'), Field::related('brand.name')] as $brand) { // Run the same assertions for each relevant fixture case.
            $fields = ['brand' => $brand, 'name']; // Prepare fields for this regression scenario.
            $unrestricted = JsonProduct::query()->orderBy('json_products.id'); // Make fixture result ordering deterministic.
            (new TestModelFilter($parameters, $fields))->apply($unrestricted); // Compile the filter into the caller-owned fixture query.
            self::assertSame([1, 3, 6], $unrestricted->get()->modelKeys()); // Check the exact fixture rows visible through this query.

            $filter = new class($parameters, $fields) extends TestModelFilter { // Prepare filter for this regression scenario.
                /** Restrict fixture rows using trusted application access context. */
                protected function before(Builder $query): void // Restrict fixture rows using trusted application access context.
                {
                    $query->where('json_products.tenant_id', 1); // Add the predicate needed by this fixture scenario.
                }
            };
            $filter = $filter->beforeUsing( // Attach trusted application restrictions before request filtering.
                fn (Builder $query) => $query->whereIn('json_products.id', [1, 2]), // Add the predicate needed by this fixture scenario.
            );
            $query = JsonProduct::query()->orderBy('json_products.id'); // Make fixture result ordering deterministic.
            $filter->apply($query); // Compile the filter into the caller-owned fixture query.

            self::assertSame([1], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        }
    }

    /** Verify that exists relations use json keys and keep related scopes. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_exists_relations_use_json_keys_and_keep_related_scopes(string $driver): void // Verify that exists relations use json keys and keep related scopes.
    {
        $fields = ['brand' => Field::related('brand.name'), 'priority' => Field::related('brand.priority')]; // Exercise a relation predicate without duplicating parent rows.
        $query = $this->query(['filter' => ['brand' => 'Acme']], $fields); // Prepare query for this regression scenario.

        self::assertSame([1], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertEmpty($query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
        self::assertSame([2], $this->query(['search' => ['brand' => 'BR']], $fields)->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([], $this->query(['filter' => ['brand' => ['in' => ['Hidden', 'Retired']]]], $fields)->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([2], $this->query(['filter' => ['priority' => ['gt' => 2]]], $fields)->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that nested exists relations use every json foreign key. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_nested_exists_relations_use_every_json_foreign_key(string $driver): void // Verify that nested exists relations use every json foreign key.
    {
        $fields = ['country' => Field::related('brand.country_of_origin.name')]; // Exercise a relation predicate without duplicating parent rows.
        $query = $this->query(['filter' => ['country' => 'Uzbekistan']], $fields); // Prepare query for this regression scenario.

        self::assertSame([1], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertEmpty($query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
    }

    /** Verify that self relation joins and exists keep parent paths distinct. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_self_relation_joins_and_exists_keep_parent_paths_distinct(string $driver): void // Verify that self relation joins and exists keep parent paths distinct.
    {
        self::assertSame([2, 4], $this->query([ // Compare the expected value and PHP type for this scenario.
            'filter' => ['parent_product.name' => 'Alpha'], // Provide named filter operands for this request.
        ])->get()->modelKeys()); // Compare the matching model identifiers with the expected fixture rows.
        self::assertSame([3], $this->query([ // Compare the expected value and PHP type for this scenario.
            'filter' => ['parent_product.parent_product.name' => 'Alpha'], // Provide named filter operands for this request.
        ])->get()->modelKeys()); // Compare the matching model identifiers with the expected fixture rows.
        self::assertSame([2, 4], $this->query([ // Compare the expected value and PHP type for this scenario.
            'filter' => ['parent' => 'Alpha'], // Provide named filter operands for this request.
        ], ['parent' => Field::related('parent_product.name')])->get()->modelKeys()); // Exercise a relation predicate without duplicating parent rows.
        self::assertSame([3], $this->query([ // Compare the expected value and PHP type for this scenario.
            'filter' => ['parent' => 'Alpha'], // Provide named filter operands for this request.
        ], ['parent' => Field::related('parent_product.parent_product.name')])->get()->modelKeys()); // Exercise a relation predicate without duplicating parent rows.
    }

    /** Verify that compilation executes no sql or accessor on an empty model. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_compilation_executes_no_sql_or_accessor_on_an_empty_model(string $driver): void // Verify that compilation executes no sql or accessor on an empty model.
    {
        $connection = $this->database->getConnection(); // Prepare connection for this regression scenario.
        $connection->enableQueryLog(); // Observe whether compilation attempts to execute SQL.
        $connection->flushQueryLog(); // Discard setup queries before measuring compilation.
        JsonProduct::$emptyAccessorCalls = 0; // Use the explicit fixture constant, enum, or framework operation.

        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['brand_id' => 1, 'brand.country.name' => 'Uzbekistan'], // Provide named filter operands for this request.
            'search' => ['caption' => 'Red', 'brand.name' => 'Acme'], // Provide search terms using public field names.
            'sort' => ['priority', 'brand.priority'], // Declare the request ordering for this scenario.
        ]);
        $query->toSql(); // Snapshot generated SQL for later mutation assertions.

        self::assertSame([], $connection->getQueryLog()); // Verify compilation performed no database reads or writes.
        self::assertSame(0, JsonProduct::$emptyAccessorCalls); // Compare the expected value and PHP type for this scenario.
        self::assertSame([1], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that existing lazy and eager belongs to loading still uses the accessor. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_existing_lazy_and_eager_belongs_to_loading_still_uses_the_accessor(string $driver): void // Verify that existing lazy and eager belongs to loading still uses the accessor.
    {
        $product = JsonProduct::query()->findOrFail(1); // Prepare product for this regression scenario.
        self::assertSame(1, $product->brand_id); // Compare the expected value and PHP type for this scenario.
        self::assertSame('Acme', $product->brand->name); // Compare the expected value and PHP type for this scenario.

        $products = $this->query(['filter' => ['brand_id' => ['in' => [1, 2]]]]) // Prepare products for this regression scenario.
            ->with('brand.country')->get(); // Exercise an Eloquent configuration change relevant to this scenario.

        self::assertSame(['Acme', 'Bravo'], $products->map(fn (JsonProduct $product) => $product->brand->name)->all()); // Compare the expected value and PHP type for this scenario.
        self::assertSame(['Uzbekistan', 'France'], $products->map(fn (JsonProduct $product) => $product->brand->country->name)->all()); // Compare the expected value and PHP type for this scenario.
        self::assertTrue($products->every(fn (JsonProduct $product) => $product->relationLoaded('brand'))); // Check the intended validation or capability outcome.
    }

    /** Load JSON-backed relations from filter defaults while filtering, searching and sorting them. */
    #[DataProvider('drivers')] // Exercise both SQLite JSON and real PostgreSQL JSONB storage.
    public function test_filter_defaults_eager_load_json_relations_after_related_queries(string $driver): void
    {
        $filter = new class([ // Keep the public request separate from trusted relation declarations.
            'filter' => ['brand_id' => ['in' => [1, 2]]], // Filter through the mapped JSON foreign key.
            'search' => ['brand.name' => 'a'], // Search the joined brand using the normal request shape.
            'sort' => 'brand.name', // Sort by the same related field without creating another join.
        ], ['brand_id', 'brand.name']) extends TestModelFilter {
            protected static array $with = ['brand.country']; // Load two levels of JSON-backed BelongsTo relations.
        };
        $query = JsonProduct::query()->where('json_products.tenant_id', 1) // Preserve the application's access restriction.
            ->select(['json_products.id', 'json_products.assignments']); // Retain JSON foreign keys when selecting columns.
        $connection = $this->database->getConnection(); // Observe only this isolated fixture's queries.
        $connection->flushQueryLog(); // Exclude schema creation and fixture insertion.
        $connection->enableQueryLog(); // Count compilation and retrieval separately.

        $filter->apply($query); // Register predicates, ordering and relation defaults without fetching rows.
        self::assertSame([], $connection->getQueryLog()); // Compilation must not query either model table.
        $products = $query->get(); // Fetch products and eager-load both relation levels in batches.

        self::assertSame([1, 2], $products->modelKeys()); // Verify JSON filtering, related search and related ordering together.
        self::assertTrue($products->every(fn (JsonProduct $product) => $product->relationLoaded('brand'))); // Prevent lazy brand loading from hiding a broken default.
        self::assertTrue($products->every(fn (JsonProduct $product) => $product->brand->relationLoaded('country'))); // Require the nested relation to be loaded too.
        self::assertSame(['Acme', 'Bravo'], $products->map(fn (JsonProduct $product) => $product->brand->name)->all()); // Match brands using accessor-backed JSON keys.
        self::assertSame(['Uzbekistan', 'France'], $products->map(fn (JsonProduct $product) => $product->brand->country->name)->all()); // Match nested JSON-backed relation keys.
        self::assertCount(3, $connection->getQueryLog()); // Fetch parents, brands and countries once each without N+1 queries.
    }

    /** Verify that public field aliases and root table aliases resolve json attributes. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_public_field_aliases_and_root_table_aliases_resolve_json_attributes(string $driver): void // Verify that public field aliases and root table aliases resolve json attributes.
    {
        $query = JsonProduct::query()->from('json_products as p')->where('p.tenant_id', 1); // Add the predicate needed by this fixture scenario.
        (new TestModelFilter([ // Use a concrete test filter with explicit fields.
            'filter' => ['rank' => ['gte' => 2], 'brand_label' => 'Acme'], // Provide named filter operands for this request.
            'sort' => 'rank', // Declare the request ordering for this scenario.
        ], ['rank' => 'priority', 'brand_label' => 'brand.name']))->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([1], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame('Alpha', $query->first()->name); // Compare the expected value and PHP type for this scenario.
    }

    /** Verify that json mappings do not expose unlisted fields to request input. */
    #[DataProvider('drivers')] // Run this test against its declared scenario provider.
    public function test_json_mappings_do_not_expose_unlisted_fields_to_request_input(string $driver): void // Verify that json mappings do not expose unlisted fields to request input.
    {
        foreach (['owner_uuid', 'assignments->brand_id', 'assignments.brand_id', "assignments->brand_id') OR 1=1 --"] as $field) { // Run the same assertions for each relevant fixture case.
            $query = JsonProduct::query()->where('tenant_id', 1); // Add the predicate needed by this fixture scenario.
            $sql = $query->toSql(); // Snapshot generated SQL for later mutation assertions.
            $bindings = $query->getBindings(); // Snapshot placeholder values before the operation under test.

            try { // Exercise the failure path without hiding later state assertions.
                (new TestModelFilter(['filter' => [$field => 1]], ['brand_id']))->apply($query); // Compile the filter into the caller-owned fixture query.
                self::fail('Undeclared JSON fields must not be accepted.'); // Fail explicitly if the expected rejection did not occur.
            } catch (ValidationException) { // Inspect the expected failure and preserve caller-state assertions.
                self::assertSame($sql, $query->toSql()); // Check the expected SQL or diagnostic text.
                self::assertSame($bindings, $query->getBindings()); // Check parameter values and binding order after compilation.
            }
        }
    }

    /** Build and filter an isolated fixture query for result assertions. */
    private function query(array $parameters, ?array $fields = null): Builder // Build and filter an isolated fixture query for result assertions.
    {
        $fields ??= [ // Prepare the fixture operation needed by this regression.
            'id', 'name', 'brand_id', 'priority', 'caption', 'active', 'owner_uuid', // Supply the literal fixture argument used by this scenario.
            'brand.id', 'brand.name', 'brand.priority', 'brand.country.name', // Supply the literal fixture argument used by this scenario.
            'brand.country_of_origin.name', 'parent_product.name', 'parent_product.parent_product.name', // Supply the literal fixture argument used by this scenario.
        ];
        $query = JsonProduct::query()->where('json_products.tenant_id', 1); // Add the predicate needed by this fixture scenario.
        $query->filter(new TestModelFilter($parameters, $fields)); // Use a concrete test filter with explicit fields.

        if (! isset($parameters['sort'])) { // Handle the fixture-specific condition before continuing.
            $query->orderBy('json_products.id'); // Make fixture result ordering deterministic.
        }

        return $query; // Return the result required by the surrounding fixture.
    }

    /** Create and populate fixtures for the filter behavior under test. */
    private function createFixtures(): void // Create and populate fixtures for the filter behavior under test.
    {
        $schema = $this->database->schema(); // Prepare schema for this regression scenario.
        $schema->create('json_countries', function (Blueprint $table): void { // Create the fixture table needed by these assertions.
            $table->id(); // Define the fixture table schema.
            $table->string('name'); // Define the fixture name column.
        });
        $schema->create('json_brands', function (Blueprint $table): void { // Create the fixture table needed by these assertions.
            $table->id(); // Define the fixture table schema.
            $table->string('name'); // Define the fixture name column.
            $table->unsignedBigInteger('tenant_id'); // Define the fixture tenant_id column.
            $table->jsonb('assignments'); // Define the fixture assignments column.
            $table->softDeletes(); // Define the fixture table schema.
        });
        $schema->create('json_products', function (Blueprint $table): void { // Create the fixture table needed by these assertions.
            $table->id(); // Define the fixture table schema.
            $table->string('name'); // Define the fixture name column.
            $table->unsignedBigInteger('tenant_id'); // Define the fixture tenant_id column.
            $table->jsonb('assignments'); // Define the fixture assignments column.
        });

        $this->database->table('json_countries')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 1, 'name' => 'Uzbekistan'], // Seed fixture record 1 with deliberately contrasting values.
            ['id' => 2, 'name' => 'France'], // Seed fixture record 2 with deliberately contrasting values.
        ]);
        foreach ([ // Run the same assertions for each relevant fixture case.
            [1, 'Acme', 1, 1, 2, null], // Seed the Acme fixture with its intended scope and JSON values.
            [2, 'Bravo', 1, 2, 10, null], // Seed the Bravo fixture with its intended scope and JSON values.
            [3, 'Hidden', 2, 1, 1, null], // Seed the Hidden fixture with its intended scope and JSON values.
            [4, 'Retired', 1, 1, 3, '2026-01-01 00:00:00'], // Seed the Retired fixture with its intended scope and JSON values.
        ] as [$id, $name, $tenant, $country, $priority, $deleted]) { // Unpack the fixture values for this iteration.
            $this->database->table('json_brands')->insert([ // Load contrasting rows for the result-set assertions.
                'id' => $id, 'name' => $name, 'tenant_id' => $tenant, // Define the id fixture value or field mapping.
                'assignments' => json_encode(['country_id' => $country, 'priority' => $priority], JSON_THROW_ON_ERROR), // Define the assignments fixture value or field mapping.
                'deleted_at' => $deleted, // Define the deleted_at fixture value or field mapping.
            ]);
        }
        foreach ([ // Run the same assertions for each relevant fixture case.
            [1, 'Alpha', 1, 1, 2, 'Red 100%', true, null], // Seed the Alpha fixture with its intended scope and JSON values.
            [2, 'Beta', 1, 2, 10, 'Blue', false, 1], // Seed the Beta fixture with its intended scope and JSON values.
            [3, 'Gamma', 1, null, 100, null, true, 2], // Seed the Gamma fixture with its intended scope and JSON values.
            [4, 'Hidden brand', 1, 3, 20, 'Hidden', false, 1], // Seed the Hidden brand fixture with its intended scope and JSON values.
            [5, 'Retired brand', 1, 4, 3, 'Retired', true, null], // Seed the Retired brand fixture with its intended scope and JSON values.
            [6, 'Foreign', 2, 1, 1, 'Red', true, 1], // Seed the Foreign fixture with its intended scope and JSON values.
            [8, 'Dangling', 1, 999, 11, 'Orphan', true, 999], // Seed the Dangling fixture with its intended scope and JSON values.
        ] as [$id, $name, $tenant, $brand, $priority, $caption, $active, $parent]) { // Unpack the fixture values for this iteration.
            $this->database->table('json_products')->insert([ // Load contrasting rows for the result-set assertions.
                'id' => $id, 'name' => $name, 'tenant_id' => $tenant, // Define the id fixture value or field mapping.
                'assignments' => json_encode([ // Define the assignments fixture value or field mapping.
                    'brand_id' => $brand, 'priority' => $priority, 'caption' => $caption, // Define the brand_id fixture value or field mapping.
                    'active' => $active, 'parent_id' => $parent, // Define the active fixture value or field mapping.
                    'owner_uuid' => $id === 2 ? '22222222-2222-4222-8222-222222222222' : null, // Define the owner_uuid fixture value or field mapping.
                ], JSON_THROW_ON_ERROR), // Fail explicitly if fixture JSON cannot be encoded.
            ]);
        }
        $this->database->table('json_products')->insert([ // Load contrasting rows for the result-set assertions.
            'id' => 7, 'name' => 'Missing keys', 'tenant_id' => 1, 'assignments' => '{}', // Define the id fixture value or field mapping.
        ]);
    }
}

class JsonProduct extends Model // Provide the json product fixture.
{
    use Filterable; // Enable model filter resolution for this fixture.

    public static int $emptyAccessorCalls = 0; // Retain empty accessor calls for this test fixture.
    protected $table = 'json_products'; // Point the model at its isolated fixture table.
    protected $casts = ['assignments' => 'array']; // Retain casts for this test fixture.

    /** Map virtual fixture attributes to their typed JSON source columns. */
    public function queryFilterColumns(): array // Map virtual fixture attributes to their typed JSON source columns.
    {
        return [ // Return the fixture definition used by the surrounding test.
            'brand_id' => new JsonColumn('assignments->brand_id', JsonType::Integer), // Declare a typed JSON scalar used by the fixture.
            'priority' => new JsonColumn('assignments->priority', JsonType::Integer), // Declare a typed JSON scalar used by the fixture.
            'caption' => new JsonColumn('assignments->caption'), // Declare a typed JSON scalar used by the fixture.
            'active' => new JsonColumn('assignments->active', JsonType::Boolean), // Declare a typed JSON scalar used by the fixture.
            'owner_uuid' => new JsonColumn('assignments->owner_uuid', JsonType::Uuid), // Declare a typed JSON scalar used by the fixture.
            'parent_id' => new JsonColumn('assignments->parent_id', JsonType::Integer), // Declare a typed JSON scalar used by the fixture.
        ];
    }

    /** Expose the JSON foreign key through the fixture model accessor. */
    public function getBrandIdAttribute(): ?int // Expose the JSON foreign key through the fixture model accessor.
    {
        if ($this->getAttributes() === []) { // Handle the fixture-specific condition before continuing.
            self::$emptyAccessorCalls++; // Use the explicit fixture constant, enum, or framework operation.
        }

        return $this->assignments['brand_id'] ?? null; // Return the fixture definition used by the surrounding test.
    }

    /** Provide get parent id attribute fixture behavior for regression coverage. */
    public function getParentIdAttribute(): ?int // Provide get parent id attribute fixture behavior for regression coverage.
    {
        return $this->assignments['parent_id'] ?? null; // Return the fixture definition used by the surrounding test.
    }

    /** Define the brand relation used by fixture queries. */
    public function brand(): BelongsTo // Define the brand relation used by fixture queries.
    {
        return $this->belongsTo(JsonBrand::class, 'brand_id'); // Declare the owner and foreign keys exercised by relation queries.
    }

    /** Define the parent product relation used by fixture queries. */
    public function parentProduct(): BelongsTo // Define the parent product relation used by fixture queries.
    {
        return $this->belongsTo(self::class, 'parent_id'); // Declare the owner and foreign keys exercised by relation queries.
    }
}

class JsonBrand extends Model // Provide the json brand fixture.
{
    use Filterable; // Enable model filter resolution for this fixture.
    use SoftDeletes; // Exercise soft-delete scopes on the fixture model.

    protected $table = 'json_brands'; // Point the model at its isolated fixture table.
    protected $casts = ['assignments' => 'array']; // Retain casts for this test fixture.

    /** Register fixture scopes that relation compilation must preserve. */
    protected static function booted(): void // Register fixture scopes that relation compilation must preserve.
    {
        static::addGlobalScope('tenant', fn (Builder $query) => $query->where($query->qualifyColumn('tenant_id'), 1)); // Add the predicate needed by this fixture scenario.
    }

    /** Map virtual fixture attributes to their typed JSON source columns. */
    public function queryFilterColumns(): array // Map virtual fixture attributes to their typed JSON source columns.
    {
        return [ // Return the fixture definition used by the surrounding test.
            'country_id' => new JsonColumn('assignments->country_id', JsonType::Integer), // Declare a typed JSON scalar used by the fixture.
            'priority' => new JsonColumn('assignments->priority', JsonType::Integer), // Declare a typed JSON scalar used by the fixture.
        ];
    }

    /** Provide get country id attribute fixture behavior for regression coverage. */
    public function getCountryIdAttribute(): ?int // Provide get country id attribute fixture behavior for regression coverage.
    {
        return $this->assignments['country_id'] ?? null; // Return the fixture definition used by the surrounding test.
    }

    /** Define the country relation used by fixture queries. */
    public function country(): BelongsTo // Define the country relation used by fixture queries.
    {
        return $this->belongsTo(JsonCountry::class, 'country_id'); // Declare the owner and foreign keys exercised by relation queries.
    }

    /** Define the country of origin relation used by fixture queries. */
    public function countryOfOrigin(): BelongsTo // Define the country of origin relation used by fixture queries.
    {
        return $this->country(); // Return the result required by the surrounding fixture.
    }
}

class JsonCountry extends Model // Provide the json country fixture.
{
    protected $table = 'json_countries'; // Point the model at its isolated fixture table.
}

class JsonMappedOwnerProduct extends JsonProduct // Provide the json mapped owner product fixture.
{
    /** Define the unsupported relation used by fixture queries. */
    public function unsupported(): BelongsTo // Define the unsupported relation used by fixture queries.
    {
        return $this->belongsTo(JsonBrand::class, 'brand_id', 'priority'); // Declare the owner and foreign keys exercised by relation queries.
    }
}

class JsonInverseBrand extends JsonBrand // Provide the json inverse brand fixture.
{
    /** Define the unsupported relation used by fixture queries. */
    public function unsupported(): HasOne // Define the unsupported relation used by fixture queries.
    {
        return $this->hasOne(JsonProduct::class, 'brand_id'); // Expose the related fixture records to Eloquent.
    }
}
