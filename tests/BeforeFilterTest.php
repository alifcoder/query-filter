<?php

namespace Alif\QueryFilter\Tests; // Keep test fixtures separate from production classes.

use Alif\QueryFilter\Abstracts\BaseEBFilter; // Use BaseEBFilter in this test fixture.
use Alif\QueryFilter\Abstracts\BaseQBFilter; // Use BaseQBFilter in this test fixture.
use Alif\QueryFilter\Field; // Use Field in this test fixture.
use Illuminate\Config\Repository; // Use Repository in this test fixture.
use Illuminate\Container\Container; // Use Container in this test fixture.
use Illuminate\Database\Capsule\Manager; // Use Manager in this test fixture.
use Illuminate\Database\Eloquent\Builder; // Use Builder in this test fixture.
use Illuminate\Database\Eloquent\Model; // Use Model in this test fixture.
use Illuminate\Database\Eloquent\SoftDeletes; // Exercise soft-delete scopes on the fixture model.
use Illuminate\Database\Query\Builder as QueryBuilder; // Use QueryBuilder in this test fixture.
use Illuminate\Database\Schema\Blueprint; // Use Blueprint in this test fixture.
use Illuminate\Support\Facades\Facade; // Use Facade in this test fixture.
use Illuminate\Translation\ArrayLoader; // Use ArrayLoader in this test fixture.
use Illuminate\Translation\Translator; // Use Translator in this test fixture.
use Illuminate\Validation\Factory; // Use Factory in this test fixture.
use LogicException; // Use LogicException in this test fixture.
use PHPUnit\Framework\Attributes\DataProvider; // Use DataProvider in this test fixture.
use PHPUnit\Framework\TestCase; // Use TestCase in this test fixture.
use RuntimeException; // Use RuntimeException in this test fixture.

final class BeforeFilterTest extends TestCase // Group regression coverage for before filter.
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
        $this->database->bootEloquent(); // Connect fixture models to the isolated database manager.
        $this->database->schema()->create('access_documents', function (Blueprint $table): void { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->integer('branch_id'); // Define the fixture branch_id column.
            $table->integer('owner_id'); // Define the fixture owner_id column.
            $table->string('title'); // Define the fixture title column.
            $table->softDeletes(); // Define the fixture table schema.
        });
        $this->database->table('access_documents')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 1, 'branch_id' => 1, 'owner_id' => 7, 'title' => 'Alpha', 'deleted_at' => null], // Seed fixture record 1 with deliberately contrasting values.
            ['id' => 2, 'branch_id' => 1, 'owner_id' => 8, 'title' => 'Beta', 'deleted_at' => null], // Seed fixture record 2 with deliberately contrasting values.
            ['id' => 3, 'branch_id' => 2, 'owner_id' => 7, 'title' => 'Alpha', 'deleted_at' => null], // Seed fixture record 3 with deliberately contrasting values.
            ['id' => 4, 'branch_id' => 2, 'owner_id' => 8, 'title' => 'Beta', 'deleted_at' => null], // Seed fixture record 4 with deliberately contrasting values.
            ['id' => 5, 'branch_id' => 1, 'owner_id' => 8, 'title' => 'Gamma', 'deleted_at' => null], // Seed fixture record 5 with deliberately contrasting values.
            ['id' => 6, 'branch_id' => 3, 'owner_id' => 7, 'title' => 'Alpha', 'deleted_at' => null], // Seed fixture record 6 with deliberately contrasting values.
            ['id' => 7, 'branch_id' => 1, 'owner_id' => 7, 'title' => 'Deleted', 'deleted_at' => '2026-01-01'], // Seed fixture record 7 with deliberately contrasting values.
        ]);
    }

    /** Release database and framework state so fixtures cannot affect later tests. */
    protected function tearDown(): void // Release database and framework state so fixtures cannot affect later tests.
    {
        $this->database->getConnection()->disconnect(); // Release the fixture database connection.
        Model::clearBootedModels(); // Remove model state retained by previous fixture queries.
        Model::unsetConnectionResolver(); // Remove model state retained by previous fixture queries.
        Facade::clearResolvedInstances(); // Prevent facade instances from leaking between tests.
        Facade::setFacadeApplication(null); // Detach the isolated framework container during cleanup.
        Container::setInstance(null); // Detach the isolated framework container during cleanup.

        parent::tearDown(); // Complete PHPUnit cleanup after releasing framework state.
    }

    /** Verify that before hook restricts empty requests without executing sql. */
    #[DataProvider('builders')] // Run this test against its declared scenario provider.
    public function test_before_hook_restricts_empty_requests_without_executing_sql(string $type): void // Verify that before hook restricts empty requests without executing sql.
    {
        $query = $this->query($type); // Prepare query for this regression scenario.
        $this->database->getConnection()->enableQueryLog(); // Observe whether compilation attempts to execute SQL.

        $this->branchFilter($type)->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([], $this->database->getConnection()->getQueryLog()); // Verify compilation performed no database reads or writes.
        self::assertSame([1, 2, 5], $this->ids($query)); // Check the exact fixture rows visible through this query.
    }

    /** Verify that caller hook request and custom ors cannot escape access restrictions. */
    #[DataProvider('builders')] // Run this test against its declared scenario provider.
    public function test_caller_hook_request_and_custom_ors_cannot_escape_access_restrictions(string $type): void // Verify that caller hook request and custom ors cannot escape access restrictions.
    {
        $query = $this->query($type) // Prepare query for this regression scenario.
            ->where('id', 3) // Add the predicate needed by this fixture scenario.
            ->orWhere('id', 4) // Add the predicate needed by this fixture scenario.
            ->orWhere('id', 1) // Add the predicate needed by this fixture scenario.
            ->orWhere('id', 6); // Add the predicate needed by this fixture scenario.
        $filter = $this->plainFilter($type, [ // Prepare filter for this regression scenario.
            'where' => ['or' => [ // Exercise the explicit boolean condition tree.
                ['field' => 'id', 'operator' => 'eq', 'value' => 1], // Compare id with eq inside the boolean tree.
                ['field' => 'id', 'operator' => 'eq', 'value' => 3], // Compare id with eq inside the boolean tree.
                ['field' => 'id', 'operator' => 'eq', 'value' => 4], // Compare id with eq inside the boolean tree.
            ]],
            'filter' => ['matching' => true], // Provide named filter operands for this request.
            'search' => ['title' => 'Alpha', 'id' => 4], // Provide search terms using public field names.
        ], [
            'id', // Supply the literal fixture argument used by this scenario.
            'title', // Supply the literal fixture argument used by this scenario.
            'matching' => Field::custom(fn (Builder|QueryBuilder $nested) => $nested // Provide a developer-owned predicate for this fixture field.
                ->orWhere('owner_id', 7)->orWhere('id', 4)), // Add the predicate needed by this fixture scenario.
        ])->beforeUsing(fn (Builder|QueryBuilder $nested) => $nested // Attach trusted application restrictions before request filtering.
            ->where('branch_id', 1)->orWhere('branch_id', 3)); // Add the predicate needed by this fixture scenario.

        $filter->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([1], $this->ids($query)); // Check the exact fixture rows visible through this query.
    }

    /** Keep OR expressions inside caller-owned raw SQL beneath trusted branch restrictions. */
    #[DataProvider('builders')] // Exercise the same access boundary for both supported builders.
    public function test_raw_caller_or_expressions_cannot_bypass_access_restrictions(string $type): void // Cover OR logic hidden inside a Raw node marked with an AND connector.
    {
        $query = $this->query($type)->whereRaw('id = ? OR id = ?', [3, 1]); // Offer one row from branch B and one from the allowed branch A.
        self::assertSame([3, 1], $query->getBindings()); // Record caller binding order before adding access predicates.
        $filter = $this->plainFilter($type)->beforeUsing( // Apply access restrictions even though request parameters are empty.
            fn (Builder|QueryBuilder $nested) => $nested->where('branch_id', 1), // Limit every raw SQL alternative to the authenticated branch.
        );

        $filter->apply($query); // Group the complete caller condition before appending trusted access.

        self::assertSame([3, 1, 1], $query->getBindings()); // Preserve caller bindings and append exactly one branch restriction value.
        self::assertSame([1], $this->ids($query)); // Reject branch B despite its earlier raw OR alternative.
    }

    /** Verify that before using is immutable additive and reusable for different access contexts. */
    #[DataProvider('builders')] // Run this test against its declared scenario provider.
    public function test_before_using_is_immutable_additive_and_reusable_for_different_access_contexts(string $type): void // Verify that before using is immutable additive and reusable for different access contexts.
    {
        $base = $this->plainFilter($type); // Prepare base for this regression scenario.
        $branchA = $base->beforeUsing(fn (Builder|QueryBuilder $query) => $query->where('branch_id', 1)); // Attach trusted application restrictions before request filtering.
        $branchB = $base->beforeUsing(fn (Builder|QueryBuilder $query) => $query->where('branch_id', 2)); // Attach trusted application restrictions before request filtering.
        $owned = $branchA->beforeUsing(fn (Builder|QueryBuilder $query) => $query // Attach trusted application restrictions before request filtering.
            ->orWhere('owner_id', 7)->orWhere('id', 3)); // Add the predicate needed by this fixture scenario.

        self::assertNotSame($base, $branchA); // Verify fluent configuration returned an independent instance.
        self::assertNotSame($branchA, $owned); // Verify fluent configuration returned an independent instance.
        foreach ([[$branchA, [1, 2, 5]], [$branchB, [3, 4]], [$owned, [1]], [$base, [1, 2, 3, 4, 5, 6]], [$branchA, [1, 2, 5]]] as [$filter, $expected]) { // Run the same assertions for each relevant fixture case.
            $query = $this->query($type); // Prepare query for this regression scenario.
            $filter->apply($query); // Compile the filter into the caller-owned fixture query.
            self::assertSame($expected, $this->ids($query)); // Check the exact fixture rows visible through this query.
        }
    }

    /** Verify that before using adds to the subclass hook. */
    #[DataProvider('builders')] // Run this test against its declared scenario provider.
    public function test_before_using_adds_to_the_subclass_hook(string $type): void // Verify that before using adds to the subclass hook.
    {
        $filter = $this->branchFilter($type)->beforeUsing( // Attach trusted application restrictions before request filtering.
            fn (Builder|QueryBuilder $query) => $query->orWhere('owner_id', 7)->orWhere('id', 4), // Add the predicate needed by this fixture scenario.
        );
        $query = $this->query($type); // Prepare query for this regression scenario.

        $filter->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([1], $this->ids($query)); // Check the exact fixture rows visible through this query.
    }

    /** Verify that no accessible branches returns nothing even for an or request. */
    #[DataProvider('builders')] // Run this test against its declared scenario provider.
    public function test_no_accessible_branches_returns_nothing_even_for_an_or_request(string $type): void // Verify that no accessible branches returns nothing even for an or request.
    {
        $filter = $this->plainFilter($type, ['where' => ['or' => [ // Prepare filter for this regression scenario.
            ['field' => 'id', 'operator' => 'eq', 'value' => 1], // Compare id with eq inside the boolean tree.
            ['field' => 'id', 'operator' => 'eq', 'value' => 3], // Compare id with eq inside the boolean tree.
        ]]], ['id'])->beforeUsing(fn (Builder|QueryBuilder $query) => $query->whereIn('branch_id', [])); // Attach trusted application restrictions before request filtering.
        $query = $this->query($type); // Prepare query for this regression scenario.

        $filter->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([], $this->ids($query)); // Check the exact fixture rows visible through this query.
    }

    /** Verify that a failing hook leaves the callers query unchanged. */
    #[DataProvider('builders')] // Run this test against its declared scenario provider.
    public function test_a_failing_hook_leaves_the_callers_query_unchanged(string $type): void // Verify that a failing hook leaves the callers query unchanged.
    {
        $query = $this->query($type)->where('id', 3)->orWhere('id', 1)->orderBy('id', 'desc'); // Add the predicate needed by this fixture scenario.
        $sql = $query->toSql(); // Snapshot generated SQL for later mutation assertions.
        $bindings = $query->getBindings(); // Snapshot placeholder values before the operation under test.
        $filter = $this->branchFilter($type)->beforeUsing(function (Builder|QueryBuilder $nested): void { // Attach trusted application restrictions before request filtering.
            $nested->where('owner_id', 7); // Add the predicate needed by this fixture scenario.
            throw new RuntimeException('Access service failed.'); // Simulate the failure that must prevent staged query changes.
        });

        try { // Exercise the failure path without hiding later state assertions.
            $filter->apply($query); // Compile the filter into the caller-owned fixture query.
            self::fail('A failing access hook must abort the filter.'); // Fail explicitly if the expected rejection did not occur.
        } catch (RuntimeException $exception) { // Inspect the expected failure and preserve caller-state assertions.
            self::assertSame('Access service failed.', $exception->getMessage()); // Compare the expected value and PHP type for this scenario.
        }

        self::assertSame($sql, $query->toSql()); // Check the expected SQL or diagnostic text.
        self::assertSame($bindings, $query->getBindings()); // Check parameter values and binding order after compilation.
        self::assertSame([3, 1], $query->pluck('id')->all()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that before callbacks reject non predicate changes atomically. */
    #[DataProvider('nonPredicateChanges')] // Run this test against its declared scenario provider.
    public function test_before_callbacks_reject_non_predicate_changes_atomically(string $type, string $change): void // Verify that before callbacks reject non predicate changes atomically.
    {
        $query = $this->query($type)->where('id', 3)->orWhere('id', 1); // Add the predicate needed by this fixture scenario.
        $sql = $query->toSql(); // Snapshot generated SQL for later mutation assertions.
        $bindings = $query->getBindings(); // Snapshot placeholder values before the operation under test.
        $filter = $this->branchFilter($type)->beforeUsing(function (Builder|QueryBuilder $nested) use ($change): void { // Attach trusted application restrictions before request filtering.
            $nested->where('owner_id', 7); // Add the predicate needed by this fixture scenario.
            match ($change) { // Choose the scenario-specific query mutation.
                'select' => $nested->select('id'), // Choose the caller-owned selection that filtering must preserve.
                'join' => $nested->join('access_documents as other', 'other.id', '=', 'access_documents.id'), // Exercise the explicit join needed by this scenario.
                'order' => $nested->orderBy('id'), // Make fixture result ordering deterministic.
                'limit' => $nested->limit(1), // Set the requested maximum result count.
                'after_query' => $nested->afterQuery(fn ($result) => $result), // Define the after_query fixture value or field mapping.
            };
        });

        try { // Exercise the failure path without hiding later state assertions.
            $filter->apply($query); // Compile the filter into the caller-owned fixture query.
            self::fail('An access hook must not silently discard non-predicate changes.'); // Fail explicitly if the expected rejection did not occur.
        } catch (LogicException) { // Inspect the expected failure and preserve caller-state assertions.
            self::assertSame($sql, $query->toSql()); // Check the expected SQL or diagnostic text.
            self::assertSame($bindings, $query->getBindings()); // Check parameter values and binding order after compilation.
        }
    }

    /** Verify that before cannot remove root scopes or change eager loading. */
    #[DataProvider('eloquentStateChanges')] // Run this test against its declared scenario provider.
    public function test_before_cannot_remove_root_scopes_or_change_eager_loading(string $change): void // Verify that before cannot remove root scopes or change eager loading.
    {
        $query = AccessDocument::query()->where('branch_id', 1); // Add the predicate needed by this fixture scenario.
        $sql = $query->toSql(); // Snapshot generated SQL for later mutation assertions.
        $filter = $this->branchFilter('eloquent')->beforeUsing(function (Builder $nested) use ($change): void { // Attach trusted application restrictions before request filtering.
            match ($change) { // Choose the scenario-specific query mutation.
                'scopes' => $nested->withTrashed(), // Exercise an Eloquent configuration change relevant to this scenario.
                'added_scope' => $nested->withGlobalScope('unexpected', fn (Builder $query) => $query->where('id', 3)), // Add the predicate needed by this fixture scenario.
                'eager' => $nested->with('unusedRelation'), // Exercise an Eloquent configuration change relevant to this scenario.
            };
        });

        try { // Exercise the failure path without hiding later state assertions.
            $filter->apply($query); // Compile the filter into the caller-owned fixture query.
            self::fail('Before hooks must preserve root scope and eager loading configuration.'); // Fail explicitly if the expected rejection did not occur.
        } catch (LogicException) { // Inspect the expected failure and preserve caller-state assertions.
            self::assertSame($sql, $query->toSql()); // Check the expected SQL or diagnostic text.
            self::assertSame([], $query->removedScopes()); // Compare the expected value and PHP type for this scenario.
            self::assertSame([], $query->getEagerLoads()); // Compare the expected value and PHP type for this scenario.
            self::assertSame([1, 2, 5], $this->ids($query)); // Check the exact fixture rows visible through this query.
        }
    }

    /** Verify that unions are rejected before they can bypass access. */
    #[DataProvider('builders')] // Run this test against its declared scenario provider.
    public function test_unions_are_rejected_before_they_can_bypass_access(string $type): void // Verify that unions are rejected before they can bypass access.
    {
        $query = $this->query($type)->where('branch_id', 1); // Add the predicate needed by this fixture scenario.
        $query->unionAll($this->query($type)->where('branch_id', 2)); // Add the predicate needed by this fixture scenario.
        $sql = $query->toSql(); // Snapshot generated SQL for later mutation assertions.
        $bindings = $query->getBindings(); // Snapshot placeholder values before the operation under test.

        try { // Exercise the failure path without hiding later state assertions.
            $this->branchFilter($type)->apply($query); // Compile the filter into the caller-owned fixture query.
            self::fail('A root WHERE cannot constrain every union branch.'); // Fail explicitly if the expected rejection did not occur.
        } catch (LogicException) { // Inspect the expected failure and preserve caller-state assertions.
            self::assertSame($sql, $query->toSql()); // Check the expected SQL or diagnostic text.
            self::assertSame($bindings, $query->getBindings()); // Check parameter values and binding order after compilation.
        }
    }

    /** Supply builders scenarios for the parameterized test. */
    public static function builders(): iterable // Supply builders scenarios for the parameterized test.
    {
        yield 'Eloquent' => ['eloquent']; // Exercise Eloquent.
        yield 'Query Builder' => ['query']; // Exercise Query Builder.
    }

    /** Supply non predicate changes scenarios for the parameterized test. */
    public static function nonPredicateChanges(): iterable // Supply non predicate changes scenarios for the parameterized test.
    {
        foreach (['eloquent', 'query'] as $type) { // Run the same assertions for each relevant fixture case.
            foreach (['select', 'join', 'order', 'limit', 'after_query'] as $change) { // Run the same assertions for each relevant fixture case.
                yield "$type $change" => [$type, $change]; // Exercise each builder each mutation.
            }
        }
    }

    /** Supply eloquent state changes scenarios for the parameterized test. */
    public static function eloquentStateChanges(): iterable // Supply eloquent state changes scenarios for the parameterized test.
    {
        yield 'root scopes' => ['scopes']; // Exercise root scopes.
        yield 'added global scope' => ['added_scope']; // Exercise added global scope.
        yield 'eager loading' => ['eager']; // Exercise eager loading.
    }

    /** Build and filter an isolated fixture query for result assertions. */
    private function query(string $type): Builder|QueryBuilder // Build and filter an isolated fixture query for result assertions.
    {
        return $type === 'eloquent' // Return the result required by the surrounding fixture.
            ? AccessDocument::query() // Use the explicit fixture constant, enum, or framework operation.
            : $this->database->table('access_documents')->whereNull('deleted_at'); // Add the predicate needed by this fixture scenario.
    }

    /** Read matching fixture identifiers in deterministic order. */
    private function ids(Builder|QueryBuilder $query): array // Read matching fixture identifiers in deterministic order.
    {
        return $query->orderBy('id')->pluck('id')->all(); // Return the result required by the surrounding fixture.
    }

    /** Select the concrete fixture filter for the requested builder type. */
    private function plainFilter(string $type, array $parameters = [], array $fields = []): BaseEBFilter|BaseQBFilter // Select the concrete fixture filter for the requested builder type.
    {
        return $type === 'eloquent' // Return the result required by the surrounding fixture.
            ? new TestModelFilter($parameters, $fields) // Use a concrete test filter with explicit fields.
            : new TestQueryFilter($parameters, $fields); // Use a concrete test filter with explicit fields.
    }

    /** Create a fixture filter restricted to the first branch. */
    private function branchFilter(string $type): BaseEBFilter|BaseQBFilter // Create a fixture filter restricted to the first branch.
    {
        return $type === 'eloquent' // Return the result required by the surrounding fixture.
            ? new class extends TestModelFilter { // Use the Eloquent fixture with its typed access hook.
                /** Restrict fixture rows using trusted application access context. */
                protected function before(Builder $builder): void // Restrict fixture rows using trusted application access context.
                {
                    $builder->where('branch_id', 1); // Add the predicate needed by this fixture scenario.
                }
            }
            : new class extends TestQueryFilter { // Use the Query Builder fixture with its typed access hook.
                /** Restrict fixture rows using trusted application access context. */
                protected function before(QueryBuilder $builder): void // Restrict fixture rows using trusted application access context.
                {
                    $builder->where('branch_id', 1); // Add the predicate needed by this fixture scenario.
                }
            };
    }
}

class AccessDocument extends Model // Provide the access document fixture.
{
    use SoftDeletes; // Exercise soft-delete scopes on the fixture model.

    protected $table = 'access_documents'; // Point the model at its isolated fixture table.
    public $timestamps = false; // Keep fixture queries independent of timestamp columns.
}
