<?php

namespace Alif\QueryFilter\Tests; // Keep test fixtures separate from production classes.

use Alif\QueryFilter\Abstracts\BaseQBFilter; // Use BaseQBFilter in this test fixture.
use Alif\QueryFilter\Enums\FilterOperator; // Use FilterOperator in this test fixture.
use Alif\QueryFilter\Field; // Use Field in this test fixture.
use Illuminate\Auth\Access\AuthorizationException; // Use AuthorizationException in this test fixture.
use Illuminate\Config\Repository; // Use Repository in this test fixture.
use Illuminate\Container\Container; // Use Container in this test fixture.
use Illuminate\Database\Capsule\Manager; // Use Manager in this test fixture.
use Illuminate\Database\Query\Builder; // Use Builder in this test fixture.
use Illuminate\Database\Schema\Blueprint; // Use Blueprint in this test fixture.
use Illuminate\Support\Facades\Facade; // Use Facade in this test fixture.
use Illuminate\Translation\ArrayLoader; // Use ArrayLoader in this test fixture.
use Illuminate\Translation\Translator; // Use Translator in this test fixture.
use Illuminate\Validation\Factory; // Use Factory in this test fixture.
use Illuminate\Validation\ValidationException; // Use ValidationException in this test fixture.
use InvalidArgumentException; // Use InvalidArgumentException in this test fixture.
use PHPUnit\Framework\Attributes\DataProvider; // Use DataProvider in this test fixture.
use PHPUnit\Framework\TestCase; // Use TestCase in this test fixture.

final class QueryBuilderFilterTest extends TestCase // Group regression coverage for query builder filter.
{
    private Manager $database; // Retain database for this test fixture.

    /** Create isolated framework services and database fixtures for each test. */
    protected function setUp(): void // Create isolated framework services and database fixtures for each test.
    {
        parent::setUp(); // Initialize PHPUnit before creating isolated fixtures.

        $container = new Container(); // Create framework services isolated from other tests.
        Container::setInstance($container); // Point framework lookups at this test container.
        $container->instance('config', new Repository()); // Register the framework dependency required by this fixture.
        $container->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $container)); // Register the framework dependency required by this fixture.
        Facade::clearResolvedInstances(); // Prevent facade instances from leaking between tests.
        Facade::setFacadeApplication($container); // Point framework lookups at this test container.

        $this->database = new Manager($container); // Create the database manager for isolated fixture queries.
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']); // Configure the isolated database connection for this scenario.
        $this->database->setAsGlobal(); // Connect fixture models to the isolated database manager.
        $this->database->schema()->create('qb_products', function (Blueprint $table): void { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->integer('brand_id')->nullable(); // Define the fixture brand_id column.
            $table->string('name'); // Define the fixture name column.
            $table->integer('score'); // Define the fixture score column.
            $table->string('tag')->nullable(); // Define the fixture tag column.
            $table->boolean('enabled'); // Define the fixture enabled column.
        });
        $this->database->schema()->create('qb_brands', function (Blueprint $table): void { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->string('name'); // Define the fixture name column.
        });
        $this->database->table('qb_products')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 1, 'brand_id' => 1, 'name' => 'Alpha', 'score' => 0, 'tag' => null, 'enabled' => false], // Seed fixture record 1 with deliberately contrasting values.
            ['id' => 2, 'brand_id' => 2, 'name' => 'Beta', 'score' => 10, 'tag' => '', 'enabled' => true], // Seed fixture record 2 with deliberately contrasting values.
            ['id' => 3, 'brand_id' => 1, 'name' => 'Gamma', 'score' => 20, 'tag' => '0', 'enabled' => true], // Seed fixture record 3 with deliberately contrasting values.
            ['id' => 4, 'brand_id' => null, 'name' => 'Alpha', 'score' => 30, 'tag' => 'featured', 'enabled' => false], // Seed fixture record 4 with deliberately contrasting values.
        ]);
        $this->database->table('qb_brands')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 1, 'name' => 'Acme'], // Seed fixture record 1 with deliberately contrasting values.
            ['id' => 2, 'name' => 'Zenith'], // Seed fixture record 2 with deliberately contrasting values.
        ]);
    }

    /** Release database and framework state so fixtures cannot affect later tests. */
    protected function tearDown(): void // Release database and framework state so fixtures cannot affect later tests.
    {
        $this->database->getConnection()->disconnect(); // Release the fixture database connection.
        Facade::clearResolvedInstances(); // Prevent facade instances from leaking between tests.
        Facade::setFacadeApplication(null); // Detach the isolated framework container during cleanup.
        Container::setInstance(null); // Detach the isolated framework container during cleanup.

        parent::tearDown(); // Complete PHPUnit cleanup after releasing framework state.
    }

    /** Verify that one pipeline combines filters groups search sort and limit. */
    public function test_one_pipeline_combines_filters_groups_search_sort_and_limit(): void // Verify that one pipeline combines filters groups search sort and limit.
    {
        $query = $this->database->table('qb_products'); // Prepare query for this regression scenario.
        $filter = $this->filter([ // Prepare filter for this regression scenario.
            'filter' => ['score' => ['gte' => 10]], // Provide named filter operands for this request.
            'where' => ['or' => [ // Exercise the explicit boolean condition tree.
                ['field' => 'name', 'operator' => 'eq', 'value' => 'Beta'], // Compare name with eq inside the boolean tree.
                ['field' => 'score', 'operator' => 'between', 'value' => [20, 30]], // Compare score with between inside the boolean tree.
            ]],
            'search' => ['name' => 'a'], // Provide search terms using public field names.
            'sort' => '-score,id', // Declare the request ordering for this scenario.
            'limit' => 2, // Set the requested maximum result count.
        ], ['id', 'name', 'score']); // Declare only the public fields required by this request.
        $this->database->getConnection()->enableQueryLog(); // Observe whether compilation attempts to execute SQL.

        $filter->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([], $this->database->getConnection()->getQueryLog()); // Verify compilation performed no database reads or writes.
        self::assertSame([4, 3], $query->pluck('id')->all()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that qualified columns filter search and sort application owned joins. */
    public function test_qualified_columns_filter_search_and_sort_application_owned_joins(): void // Verify that qualified columns filter search and sort application owned joins.
    {
        $query = $this->database->table('qb_products as p') // Prepare query for this regression scenario.
            ->leftJoin('qb_brands as b', 'b.id', '=', 'p.brand_id') // Exercise the explicit join needed by this scenario.
            ->select('p.*'); // Choose the caller-owned selection that filtering must preserve.
        $filter = $this->filter([ // Prepare filter for this regression scenario.
            'filter' => ['brand' => 'Acme'], // Provide named filter operands for this request.
            'search' => ['brand' => 'cm'], // Provide search terms using public field names.
            'sort' => '-brand,-score', // Declare the request ordering for this scenario.
        ], ['brand' => Field::make('b.name'), 'score' => Field::make('p.score')]); // Declare the SQL-backed field and its permitted operations.

        $filter->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([3, 1], $query->pluck('p.id')->all()); // Check the exact fixture rows visible through this query.
        self::assertCount(1, $query->joins); // Check the expected number of generated clauses or fixture results.
        self::assertSame(['p.*'], $query->columns); // Compare the expected value and PHP type for this scenario.
    }

    /** Verify that operators preserve null empty and falsy values. */
    #[DataProvider('operatorCases')] // Run this test against its declared scenario provider.
    public function test_operators_preserve_null_empty_and_falsy_values(array $parameters, array $expected): void // Verify that operators preserve null empty and falsy values.
    {
        $query = $this->database->table('qb_products'); // Prepare query for this regression scenario.

        $this->filter($parameters + ['sort' => 'id'], ['id', 'score', 'enabled', 'tag'])->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame($expected, $query->pluck('id')->all()); // Check the exact fixture rows visible through this query.
    }

    /** Supply operator cases scenarios for the parameterized test. */
    public static function operatorCases(): iterable // Supply operator cases scenarios for the parameterized test.
    {
        yield 'zero and false' => [['filter' => ['score' => 0, 'enabled' => false]], [1]]; // Exercise zero and false.
        yield 'not equal preserves SQL null semantics' => [['filter' => ['tag' => ['ne' => 'featured']]], [2, 3]]; // Exercise not equal preserves SQL null semantics.
        yield 'empty equality set' => [['filter' => ['id' => ['in' => []]]], []]; // Exercise empty equality set.
        yield 'nonempty equality set' => [['filter' => ['id' => ['in' => [1, 3]]]], [1, 3]]; // Exercise nonempty equality set.
        yield 'empty values' => [['filter' => ['tag' => ['is_empty' => true]]], [1, 2]]; // Exercise empty values.
        yield 'nonempty values' => [['filter' => ['tag' => ['is_empty' => false]]], [3, 4]]; // Exercise nonempty values.
        yield 'null checks' => [['filter' => ['tag' => ['is_null' => true]]], [1]]; // Exercise null checks.
    }

    /** Verify that custom field receives query builder and cannot escape caller predicates. */
    public function test_custom_field_receives_query_builder_and_cannot_escape_caller_predicates(): void // Verify that custom field receives query builder and cannot escape caller predicates.
    {
        $calls = []; // Prepare calls for this regression scenario.
        $query = $this->database->table('qb_products')->where('brand_id', 1); // Add the predicate needed by this fixture scenario.
        $field = Field::custom(function (Builder $query, mixed $value, string $operator) use (&$calls): void { // Provide a developer-owned predicate for this fixture field.
            $calls[] = [$value, $operator]; // Prepare the fixture operation needed by this regression.
            $query->orWhere('score', $value)->orWhere('id', 4); // Add the predicate needed by this fixture scenario.
        })->operators([FilterOperator::Equal]); // Use the explicit fixture constant, enum, or framework operation.

        $this->filter(['filter' => ['matching' => 0]], ['matching' => $field])->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([[0, 'eq']], $calls); // Compare the expected value and PHP type for this scenario.
        self::assertSame([1], $query->pluck('id')->all()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that prepare rules fields and default sort hooks are shared. */
    public function test_prepare_rules_fields_and_default_sort_hooks_are_shared(): void // Verify that prepare rules fields and default sort hooks are shared.
    {
        $filter = new class(['filter' => ['score' => ' 10 ']]) extends BaseQBFilter { // Prepare filter for this regression scenario.
            /** Declare the public fields available to this fixture filter. */
            protected function fields(): array // Declare the public fields available to this fixture filter.
            {
                return ['id', 'score' => Field::make('score')->rules(['integer', 'min:0'])]; // Return the fixture definition used by the surrounding test.
            }

            /** Normalize fixture request input before field validation and compilation. */
            protected function prepare(array $parameters): array // Normalize fixture request input before field validation and compilation.
            {
                $parameters['filter']['score'] = trim($parameters['filter']['score']); // Prepare the fixture operation needed by this regression.

                return $parameters; // Pass normalized request input to the shared parser.
            }

            /** Declare validation rules enforced before the fixture query can change. */
            protected function rules(): array // Declare validation rules enforced before the fixture query can change.
            {
                return ['filter.score' => ['required', 'integer']]; // Return the fixture definition used by the surrounding test.
            }

            /** Provide deterministic fallback ordering for fixture requests. */
            protected function defaultSort(): array // Provide deterministic fallback ordering for fixture requests.
            {
                return ['-id']; // Return the fixture definition used by the surrounding test.
            }
        };
        $query = $this->database->table('qb_products'); // Prepare query for this regression scenario.

        $filter->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([2], $query->pluck('id')->all()); // Check the exact fixture rows visible through this query.
        self::assertSame('desc', $query->orders[0]['direction']); // Compare the expected value and PHP type for this scenario.
    }

    /** Verify that authorization prevents access hooks and query mutation. */
    public function test_authorization_prevents_access_hooks_and_query_mutation(): void // Verify that authorization prevents access hooks and query mutation.
    {
        $calls = 0; // Prepare calls for this regression scenario.
        $filter = (new class extends TestQueryFilter { // Prepare filter for this regression scenario.
            /** Set the authorization outcome used by this regression scenario. */
            protected function authorize(): bool // Set the authorization outcome used by this regression scenario.
            {
                return false; // Use the authorization outcome required by this scenario.
            }
        })->beforeUsing(function (Builder $query) use (&$calls): void { // Attach trusted application restrictions before request filtering.
            $calls++; // Prepare the fixture operation needed by this regression.
            $query->where('brand_id', 1); // Add the predicate needed by this fixture scenario.
        });
        $query = $this->database->table('qb_products')->where('id', 2); // Add the predicate needed by this fixture scenario.
        $sql = $query->toSql(); // Snapshot generated SQL for later mutation assertions.
        $bindings = $query->getBindings(); // Snapshot placeholder values before the operation under test.

        try { // Exercise the failure path without hiding later state assertions.
            $filter->apply($query); // Compile the filter into the caller-owned fixture query.
            self::fail('Unauthorized filters must fail before changing the query.'); // Fail explicitly if the expected rejection did not occur.
        } catch (AuthorizationException) { // Inspect the expected failure and preserve caller-state assertions.
            self::assertSame(0, $calls); // Compare the expected value and PHP type for this scenario.
            self::assertSame($sql, $query->toSql()); // Check the expected SQL or diagnostic text.
            self::assertSame($bindings, $query->getBindings()); // Check parameter values and binding order after compilation.
        }
    }

    /** Verify that invalid values leave the query unchanged. */
    public function test_invalid_values_leave_the_query_unchanged(): void // Verify that invalid values leave the query unchanged.
    {
        $query = $this->database->table('qb_products')->where('id', 1)->orWhere('id', 2)->limit(3); // Add the predicate needed by this fixture scenario.
        $sql = $query->toSql(); // Snapshot generated SQL for later mutation assertions.
        $bindings = $query->getBindings(); // Snapshot placeholder values before the operation under test.

        try { // Exercise the failure path without hiding later state assertions.
            $this->filter(['filter' => ['score' => -1]], [ // Prepare the fixture operation needed by this regression.
                'score' => Field::make('score')->rules(['integer', 'min:0']), // Declare the SQL-backed field and its permitted operations.
            ])->apply($query); // Compile the filter into the caller-owned fixture query.
            self::fail('Field rules must be enforced for Query Builder.'); // Fail explicitly if the expected rejection did not occur.
        } catch (ValidationException) { // Inspect the expected failure and preserve caller-state assertions.
            self::assertSame($sql, $query->toSql()); // Check the expected SQL or diagnostic text.
            self::assertSame($bindings, $query->getBindings()); // Check parameter values and binding order after compilation.
        }
    }

    /** Verify that a failing custom field does not commit partial conditions. */
    public function test_a_failing_custom_field_does_not_commit_partial_conditions(): void // Verify that a failing custom field does not commit partial conditions.
    {
        $query = $this->database->table('qb_products')->where('id', 2); // Add the predicate needed by this fixture scenario.
        $sql = $query->toSql(); // Snapshot generated SQL for later mutation assertions.
        $bindings = $query->getBindings(); // Snapshot placeholder values before the operation under test.
        $filter = $this->filter(['filter' => ['score' => 10, 'custom' => true]], [ // Prepare filter for this regression scenario.
            'score', // Supply the literal fixture argument used by this scenario.
            'custom' => Field::custom(function (Builder $query): void { // Provide a developer-owned predicate for this fixture field.
                $query->where('enabled', false); // Add the predicate needed by this fixture scenario.
                throw ValidationException::withMessages(['custom' => 'Unavailable.']); // Simulate the failure that must prevent staged query changes.
            }),
        ]);

        try { // Exercise the failure path without hiding later state assertions.
            $filter->apply($query); // Compile the filter into the caller-owned fixture query.
            self::fail('A callback exception must discard staged changes.'); // Fail explicitly if the expected rejection did not occur.
        } catch (ValidationException) { // Inspect the expected failure and preserve caller-state assertions.
            self::assertSame($sql, $query->toSql()); // Check the expected SQL or diagnostic text.
            self::assertSame($bindings, $query->getBindings()); // Check parameter values and binding order after compilation.
            self::assertSame([2], $query->pluck('id')->all()); // Check the exact fixture rows visible through this query.
        }
    }

    /** Verify that eloquent relation fields are rejected without mutation. */
    public function test_eloquent_relation_fields_are_rejected_without_mutation(): void // Verify that eloquent relation fields are rejected without mutation.
    {
        $query = $this->database->table('qb_products')->where('id', 1); // Add the predicate needed by this fixture scenario.
        $sql = $query->toSql(); // Snapshot generated SQL for later mutation assertions.
        $bindings = $query->getBindings(); // Snapshot placeholder values before the operation under test.

        try { // Exercise the failure path without hiding later state assertions.
            $this->filter(['filter' => ['brand' => 'Acme']], [ // Prepare the fixture operation needed by this regression.
                'brand' => Field::related('brand.name'), // Exercise a relation predicate without duplicating parent rows.
            ])->apply($query); // Compile the filter into the caller-owned fixture query.
            self::fail('Relation resolution requires an Eloquent model.'); // Fail explicitly if the expected rejection did not occur.
        } catch (InvalidArgumentException) { // Inspect the expected failure and preserve caller-state assertions.
            self::assertSame($sql, $query->toSql()); // Check the expected SQL or diagnostic text.
            self::assertSame($bindings, $query->getBindings()); // Check parameter values and binding order after compilation.
        }
    }

    /** Verify that reusing a filter preserves each callers selection and bindings. */
    public function test_reusing_a_filter_preserves_each_callers_selection_and_bindings(): void // Verify that reusing a filter preserves each callers selection and bindings.
    {
        $filter = $this->filter(['filter' => ['score' => ['gte' => 10]], 'sort' => '-score'], ['score']); // Prepare filter for this regression scenario.

        foreach ([[1, [3]], [2, [2]]] as [$brand, $expected]) { // Run the same assertions for each relevant fixture case.
            $query = $this->database->table('qb_products')->select('id', 'name')->where('brand_id', $brand); // Add the predicate needed by this fixture scenario.
            $filter->apply($query); // Compile the filter into the caller-owned fixture query.

            self::assertSame($expected, $query->pluck('id')->all()); // Check the exact fixture rows visible through this query.
            self::assertSame(['id', 'name'], $query->columns); // Compare the expected value and PHP type for this scenario.
            self::assertSame([$brand, 10], $query->getBindings()); // Check parameter values and binding order after compilation.
        }
    }

    /** Create a concrete Query Builder fixture with an explicit field map. */
    private function filter(array $parameters, array $fields): BaseQBFilter // Create a concrete Query Builder fixture with an explicit field map.
    {
        return new TestQueryFilter($parameters, $fields); // Pass normalized request input to the shared parser.
    }
}
