<?php

namespace Alif\QueryFilter\Tests; // Keep test fixtures separate from production classes.

use Alif\QueryFilter\Enums\FilterOperator; // Use FilterOperator in this test fixture.
use Alif\QueryFilter\Enums\JsonType; // Supply an unrelated enum to verify operator policies reject it.
use Alif\QueryFilter\Field; // Use Field in this test fixture.
use Alif\QueryFilter\FilterLimits; // Use FilterLimits in this test fixture.
use Alif\QueryFilter\Traits\Filterable; // Enable model filter resolution for this fixture.
use Illuminate\Auth\Access\AuthorizationException; // Use AuthorizationException in this test fixture.
use Illuminate\Config\Repository; // Use Repository in this test fixture.
use Illuminate\Container\Container; // Use Container in this test fixture.
use Illuminate\Database\Capsule\Manager; // Use Manager in this test fixture.
use Illuminate\Database\Eloquent\Builder; // Use Builder in this test fixture.
use Illuminate\Database\Eloquent\Model; // Use Model in this test fixture.
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Use BelongsTo in this test fixture.
use Illuminate\Database\Eloquent\Relations\BelongsToMany; // Use BelongsToMany in this test fixture.
use Illuminate\Database\Eloquent\Relations\HasMany; // Use HasMany in this test fixture.
use Illuminate\Database\Eloquent\SoftDeletes; // Exercise soft-delete scopes on the fixture model.
use Illuminate\Database\Schema\Blueprint; // Use Blueprint in this test fixture.
use Illuminate\Support\Facades\Facade; // Use Facade in this test fixture.
use Illuminate\Translation\ArrayLoader; // Use ArrayLoader in this test fixture.
use Illuminate\Translation\Translator; // Use Translator in this test fixture.
use Illuminate\Validation\Factory; // Use Factory in this test fixture.
use Illuminate\Validation\ValidationException; // Use ValidationException in this test fixture.
use InvalidArgumentException; // Use InvalidArgumentException in this test fixture.
use LogicException; // Use LogicException in this test fixture.
use PHPUnit\Framework\Attributes\DataProvider; // Use DataProvider in this test fixture.
use PHPUnit\Framework\TestCase; // Use TestCase in this test fixture.

final class AdvancedModelFilterTest extends TestCase // Group regression coverage for advanced model filter.
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
        $this->createFixtures(); // Prepare the fixture operation needed by this regression.
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

    /** Verify that nested boolean groups stay inside existing access restrictions. */
    public function test_nested_boolean_groups_stay_inside_existing_access_restrictions(): void // Verify that nested boolean groups stay inside existing access restrictions.
    {
        $query = AdvancedProduct::query()->where('tenant_id', 1); // Add the predicate needed by this fixture scenario.
        $filter = new TestModelFilter([ // Use a concrete test filter with explicit fields.
            'filter' => ['score' => ['gte' => 10]], // Provide named filter operands for this request.
            'where' => ['or' => [ // Exercise the explicit boolean condition tree.
                ['field' => 'name', 'operator' => 'eq', 'value' => 'Alpha'], // Compare name with eq inside the boolean tree.
                ['and' => [ // Require all of these child conditions within the alternative branch.
                    ['field' => 'score', 'operator' => 'gte', 'value' => 10], // Compare score with gte inside the boolean tree.
                    ['field' => 'score', 'operator' => 'lte', 'value' => 20], // Compare score with lte inside the boolean tree.
                ]],
            ]],
            'sort' => 'id', // Declare the request ordering for this scenario.
        ], ['id', 'name', 'score']); // Declare only the public fields required by this request.

        $filter->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([2, 3], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that boolean leaves preserve zero false and null. */
    public function test_boolean_leaves_preserve_zero_false_and_null(): void // Verify that boolean leaves preserve zero false and null.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'where' => ['and' => [ // Exercise the explicit boolean condition tree.
                ['field' => 'score', 'operator' => 'eq', 'value' => 0], // Compare score with eq inside the boolean tree.
                ['field' => 'enabled', 'operator' => 'eq', 'value' => false], // Compare enabled with eq inside the boolean tree.
                ['field' => 'tag', 'operator' => 'eq', 'value' => null], // Compare tag with eq inside the boolean tree.
            ]],
        ], ['score', 'enabled', 'tag']); // Declare only the public fields required by this request.

        self::assertSame([1], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that malformed boolean trees are rejected without changing the query. */
    #[DataProvider('invalidTrees')] // Run this test against its declared scenario provider.
    public function test_malformed_boolean_trees_are_rejected_without_changing_the_query(mixed $where): void // Verify that malformed boolean trees are rejected without changing the query.
    {
        $this->assertRejectedWithoutMutation( // Check the fixture behavior required by this regression.
            new TestModelFilter(['where' => $where], ['id', 'name', 'score']), // Use a concrete test filter with explicit fields.
        );
    }

    /** Supply invalid trees scenarios for the parameterized test. */
    public static function invalidTrees(): iterable // Supply invalid trees scenarios for the parameterized test.
    {
        yield 'scalar group' => ['or']; // Exercise scalar group.
        yield 'empty or' => [['or' => []]]; // Exercise empty or.
        yield 'empty and' => [['and' => []]]; // Exercise empty and.
        yield 'both boolean keys' => [[ // Exercise both boolean keys.
            'and' => [['field' => 'id', 'operator' => 'eq', 'value' => 1]], // Require every nested condition in this fixture.
            'or' => [['field' => 'id', 'operator' => 'eq', 'value' => 2]], // Allow any nested condition while preserving outer restrictions.
        ]];
        yield 'missing value' => [['field' => 'id', 'operator' => 'eq']]; // Exercise missing value.
        yield 'unknown field' => [['field' => 'private_column', 'operator' => 'eq', 'value' => 1]]; // Exercise unknown field.
        yield 'unknown operator' => [['field' => 'id', 'operator' => 'raw', 'value' => '1 = 1']]; // Exercise unknown operator.
        yield 'keyed group children' => [['or' => [ // Exercise keyed group children.
            'first' => ['field' => 'id', 'operator' => 'eq', 'value' => 1], // Define the first fixture value or field mapping.
        ]]];
    }

    /** Verify that field permissions apply to filters search sort and trees. */
    #[DataProvider('forbiddenFieldOperations')] // Run this test against its declared scenario provider.
    public function test_field_permissions_apply_to_filters_search_sort_and_trees(array $parameters): void // Verify that field permissions apply to filters search sort and trees.
    {
        $field = Field::make('score')->operators([FilterOperator::Equal])->searchable(false)->sortable(false); // Declare the SQL-backed field and its permitted operations.

        $this->assertRejectedWithoutMutation(new TestModelFilter($parameters, ['score' => $field])); // Check the fixture behavior required by this regression.
    }

    /** Supply forbidden field operations scenarios for the parameterized test. */
    public static function forbiddenFieldOperations(): iterable // Supply forbidden field operations scenarios for the parameterized test.
    {
        yield 'filter operator' => [['filter' => ['score' => ['gt' => 0]]]]; // Exercise filter operator.
        yield 'search permission' => [['search' => ['score' => '0']]]; // Exercise search permission.
        yield 'sort permission' => [['sort' => 'score']]; // Exercise sort permission.
        yield 'tree operator' => [['where' => ['field' => 'score', 'operator' => 'gte', 'value' => 0]]]; // Exercise tree operator.
    }

    /** Verify that field definitions can be reused without mutating the original. */
    public function test_field_definitions_can_be_reused_without_mutating_the_original(): void // Verify that field definitions can be reused without mutating the original.
    {
        $original = Field::make('score'); // Declare the SQL-backed field and its permitted operations.
        $restricted = $original->operators([FilterOperator::Equal])->searchable(false)->sortable(false); // Prepare restricted for this regression scenario.
        self::assertNotSame($original, $restricted); // Verify fluent configuration returned an independent instance.

        $query = $this->query(['filter' => ['score' => ['gte' => 20]], 'sort' => 'score'], [ // Prepare query for this regression scenario.
            'score' => $original, // Define the score fixture value or field mapping.
        ]);

        self::assertSame([3, 5], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        $this->assertRejectedWithoutMutation(new TestModelFilter(['sort' => 'score'], ['score' => $restricted])); // Check the fixture behavior required by this regression.
    }

    /** Verify enum policies accept normal request operator strings without mutating definitions. */
    #[DataProvider('operatorPolicies')] // Run this test against its declared scenario provider.
    public function test_enum_operator_policies_accept_request_strings_without_mutating_definitions(array $operators): void // Keep PHP policies enum-based while request operators remain strings.
    {
        $field = Field::make('score')->operators($operators); // Declare the SQL-backed field and its permitted operations.
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['score' => ['gte' => 10]], // Provide named filter operands for this request.
            'where' => ['field' => 'score', 'operator' => 'lte', 'value' => 20], // Exercise the explicit boolean condition tree.
            'sort' => 'id', // Declare the request ordering for this scenario.
        ], ['id', 'score' => $field]); // Attach the public field policy used by this request.

        self::assertSame([2, 3], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame($operators, $field->allowedOperators); // Compare the expected value and PHP type for this scenario.
    }

    /** Supply operator policies scenarios for the parameterized test. */
    public static function operatorPolicies(): iterable // Supply operator policies scenarios for the parameterized test.
    {
        yield 'enum cases' => [[FilterOperator::GreaterThanOrEqual, FilterOperator::LessThanOrEqual]]; // Exercise enum cases.
    }

    /** Verify that invalid operator definitions are rejected before query mutation. */
    #[DataProvider('invalidOperatorPolicies')] // Run this test against its declared scenario provider.
    public function test_invalid_operator_definitions_are_rejected_before_query_mutation(array $operators): void // Reject strings and unrelated values in developer-owned operator policies.
    {
        $exception = $this->assertRejectedWithoutMutation(new TestModelFilter(['filter' => ['score' => 10]], [ // Verify rejection leaves existing SQL, bindings, scopes, and results unchanged.
            'score' => Field::make('score')->operators($operators), // Supply the invalid PHP policy independently of the valid request value.
        ]), InvalidArgumentException::class); // Use the explicit fixture constant, enum, or framework operation.
        self::assertStringContainsString('FilterOperator cases', $exception->getMessage()); // Explain the required enum-based replacement to the application developer.
    }

    /** Supply invalid operator policies scenarios for the parameterized test. */
    public static function invalidOperatorPolicies(): iterable // Supply invalid operator policies scenarios for the parameterized test.
    {
        yield 'unknown name' => [['raw']]; // Reject arbitrary SQL-like operator names in PHP definitions.
        yield 'request operator string' => [['eq']]; // Require enum cases even when a request string names a valid operator.
        yield 'request operator alias' => [['neq']]; // Keep request aliases out of developer-owned enum policies.
        yield 'mixed definitions' => [[FilterOperator::Equal, 'ne']]; // Do not silently normalize a string mixed with valid enum cases.
        yield 'wrong enum' => [[JsonType::Integer]]; // Reject unrelated enum families despite their typed PHP values.
        yield 'integer' => [[1]]; // Reject numeric values that cannot represent an operator case.
        yield 'null' => [[null]]; // Reject an invalid entry while keeping a null whole policy meaningful.
    }

    /** Verify that empty operator policy still rejects all filter operations. */
    public function test_empty_operator_policy_still_rejects_all_filter_operations(): void // Verify that empty operator policy still rejects all filter operations.
    {
        $this->assertRejectedWithoutMutation(new TestModelFilter(['filter' => ['score' => 10]], [ // Check the fixture behavior required by this regression.
            'score' => Field::make('score')->operators([]), // Declare the SQL-backed field and its permitted operations.
        ]));
    }

    /** Verify that transformations run before field value validation. */
    public function test_transformations_run_before_field_value_validation(): void // Verify that transformations run before field value validation.
    {
        $field = Field::make('score') // Declare the SQL-backed field and its permitted operations.
            ->transform(fn ($value) => is_string($value) ? trim($value) : $value) // Apply transform to the current fixture definition.
            ->rules(['integer', 'min:0']); // Apply rules to the current fixture definition.

        $query = $this->query(['filter' => ['score' => ['eq' => [' 0 ', ' 10 ']]], 'sort' => 'id'], [ // Prepare query for this regression scenario.
            'id', // Supply the literal fixture argument used by this scenario.
            'score' => $field, // Define the score fixture value or field mapping.
        ]);

        self::assertSame([1, 2], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that field rules validate each value and tree leaf. */
    #[DataProvider('invalidFieldValues')] // Run this test against its declared scenario provider.
    public function test_field_rules_validate_each_value_and_tree_leaf(array $parameters): void // Verify that field rules validate each value and tree leaf.
    {
        $this->assertRejectedWithoutMutation(new TestModelFilter($parameters, [ // Check the fixture behavior required by this regression.
            'score' => Field::make('score')->rules(['integer', 'min:0']), // Declare the SQL-backed field and its permitted operations.
        ]));
    }

    /** Supply invalid field values scenarios for the parameterized test. */
    public static function invalidFieldValues(): iterable // Supply invalid field values scenarios for the parameterized test.
    {
        yield 'negative scalar' => [['filter' => ['score' => -1]]]; // Exercise negative scalar.
        yield 'invalid member of list' => [['filter' => ['score' => ['eq' => [0, 'invalid']]]]]; // Exercise invalid member of list.
        yield 'invalid tree value' => [['where' => ['field' => 'score', 'operator' => 'eq', 'value' => -1]]]; // Exercise invalid tree value.
    }

    /** Verify that null checks do not apply the columns integer rules to the boolean. */
    public function test_null_checks_do_not_apply_the_columns_integer_rules_to_the_boolean(): void // Verify that null checks do not apply the columns integer rules to the boolean.
    {
        $query = $this->query(['filter' => ['score' => ['is_null' => false]], 'sort' => 'id'], [ // Prepare query for this regression scenario.
            'id', // Supply the literal fixture argument used by this scenario.
            'score' => Field::make('score')->rules(['integer', 'min:0']), // Declare the SQL-backed field and its permitted operations.
        ]);

        self::assertSame([1, 2, 3, 4, 5], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that filter authorization runs before any query change. */
    public function test_filter_authorization_runs_before_any_query_change(): void // Verify that filter authorization runs before any query change.
    {
        $filter = new class(['filter' => ['score' => 0]], ['score']) extends TestModelFilter { // Prepare filter for this regression scenario.
            /** Set the authorization outcome used by this regression scenario. */
            protected function authorize(): bool // Set the authorization outcome used by this regression scenario.
            {
                return false; // Use the authorization outcome required by this scenario.
            }
        };

        $this->assertRejectedWithoutMutation($filter, AuthorizationException::class); // Check the fixture behavior required by this regression.
    }

    /** Verify that a requested unauthorized field is rejected. */
    public function test_a_requested_unauthorized_field_is_rejected(): void // Verify that a requested unauthorized field is rejected.
    {
        $this->assertRejectedWithoutMutation(new TestModelFilter(['filter' => ['score' => 0]], [ // Check the fixture behavior required by this regression.
            'score' => Field::make('score')->authorize(fn () => false), // Declare the SQL-backed field and its permitted operations.
        ]), AuthorizationException::class); // Use the explicit fixture constant, enum, or framework operation.
    }

    /** Verify that unused unauthorized fields do not block an allowed request. */
    public function test_unused_unauthorized_fields_do_not_block_an_allowed_request(): void // Verify that unused unauthorized fields do not block an allowed request.
    {
        $query = $this->query(['filter' => ['id' => 1]], [ // Prepare query for this regression scenario.
            'id', // Supply the literal fixture argument used by this scenario.
            'score' => Field::make('score')->authorize(fn () => false), // Declare the SQL-backed field and its permitted operations.
        ]);

        self::assertSame([1], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that prepare and default sort hooks support a reusable filter. */
    public function test_prepare_and_default_sort_hooks_support_a_reusable_filter(): void // Verify that prepare and default sort hooks support a reusable filter.
    {
        $filter = new class(['filter' => ['name' => ' alpha ']], ['id', 'name']) extends TestModelFilter { // Prepare filter for this regression scenario.
            /** Normalize fixture request input before field validation and compilation. */
            protected function prepare(array $parameters): array // Normalize fixture request input before field validation and compilation.
            {
                $parameters['filter']['name'] = ucfirst(trim($parameters['filter']['name'])); // Prepare the fixture operation needed by this regression.

                return $parameters; // Pass normalized request input to the shared parser.
            }

            /** Provide deterministic fallback ordering for fixture requests. */
            protected function defaultSort(): array // Provide deterministic fallback ordering for fixture requests.
            {
                return ['-id']; // Return the fixture definition used by the surrounding test.
            }
        };

        foreach ([AdvancedProduct::query(), AdvancedProduct::query()] as $query) { // Run the same assertions for each relevant fixture case.
            $filter->apply($query); // Compile the filter into the caller-owned fixture query.
            self::assertSame([4, 1], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        }
    }

    /** Verify that explicit sort overrides the default sort. */
    public function test_explicit_sort_overrides_the_default_sort(): void // Verify that explicit sort overrides the default sort.
    {
        $filter = new class(['sort' => 'id'], ['id']) extends TestModelFilter { // Prepare filter for this regression scenario.
            /** Provide deterministic fallback ordering for fixture requests. */
            protected function defaultSort(): array // Provide deterministic fallback ordering for fixture requests.
            {
                return ['-id']; // Return the fixture definition used by the surrounding test.
            }
        };
        $query = AdvancedProduct::query(); // Prepare query for this regression scenario.

        $filter->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([1, 2, 3, 4, 5], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that request rules validate before mutating the builder. */
    public function test_request_rules_validate_before_mutating_the_builder(): void // Verify that request rules validate before mutating the builder.
    {
        $filter = new class(['filter' => ['score' => -1]], ['score']) extends TestModelFilter { // Prepare filter for this regression scenario.
            /** Declare validation rules enforced before the fixture query can change. */
            protected function rules(): array // Declare validation rules enforced before the fixture query can change.
            {
                return ['filter.score' => ['integer', 'min:0']]; // Return the fixture definition used by the surrounding test.
            }
        };

        $this->assertRejectedWithoutMutation($filter); // Check the fixture behavior required by this regression.
    }

    /** Verify that custom callback receives scalar value and operator and cannot escape base predicates. */
    public function test_custom_callback_receives_scalar_value_and_operator_and_cannot_escape_base_predicates(): void // Verify that custom callback receives scalar value and operator and cannot escape base predicates.
    {
        $calls = []; // Prepare calls for this regression scenario.
        $field = Field::custom(function (Builder $query, mixed $value, string $operator) use (&$calls): void { // Provide a developer-owned predicate for this fixture field.
            $calls[] = [$value, $operator]; // Prepare the fixture operation needed by this regression.
            $query->orWhere('name', $value)->orWhere('score', 0); // Add the predicate needed by this fixture scenario.
        });
        $query = AdvancedProduct::query()->where('tenant_id', 1); // Add the predicate needed by this fixture scenario.

        (new TestModelFilter(['filter' => ['matching' => 'Alpha']], ['matching' => $field]))->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([['Alpha', 'eq']], $calls); // Compare the expected value and PHP type for this scenario.
        self::assertSame([1], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that custom fields default to equality filtering only. */
    #[DataProvider('forbiddenCustomOperations')] // Run this test against its declared scenario provider.
    public function test_custom_fields_default_to_equality_filtering_only(array $parameters): void // Verify that custom fields default to equality filtering only.
    {
        $this->assertRejectedWithoutMutation(new TestModelFilter($parameters, [ // Check the fixture behavior required by this regression.
            'matching' => Field::custom(fn (Builder $query, mixed $value) => $query->where('name', $value)), // Provide a developer-owned predicate for this fixture field.
        ]));
    }

    /** Supply forbidden custom operations scenarios for the parameterized test. */
    public static function forbiddenCustomOperations(): iterable // Supply forbidden custom operations scenarios for the parameterized test.
    {
        yield 'non-equality filter' => [['filter' => ['matching' => ['ne' => 'Alpha']]]]; // Exercise non-equality filter.
        yield 'search' => [['search' => ['matching' => 'Alpha']]]; // Exercise search.
        yield 'sort' => [['sort' => 'matching']]; // Exercise sort.
    }

    /** Verify that failure in a custom callback does not leave a partial query. */
    public function test_failure_in_a_custom_callback_does_not_leave_a_partial_query(): void // Verify that failure in a custom callback does not leave a partial query.
    {
        $this->assertRejectedWithoutMutation(new TestModelFilter(['filter' => ['id' => 1, 'matching' => 'Alpha']], [ // Check the fixture behavior required by this regression.
            'id', // Supply the literal fixture argument used by this scenario.
            'matching' => Field::custom(function (Builder $query): void { // Provide a developer-owned predicate for this fixture field.
                $query->where('score', 10); // Add the predicate needed by this fixture scenario.
                throw ValidationException::withMessages(['matching' => 'Rejected by domain validation.']); // Simulate the failure that must prevent staged query changes.
            }),
        ]));
    }

    /** Verify that custom callbacks cannot remove a root global scope. */
    public function test_custom_callbacks_cannot_remove_a_root_global_scope(): void // Verify that custom callbacks cannot remove a root global scope.
    {
        $query = AdvancedNote::query()->where('id', 17); // Add the predicate needed by this fixture scenario.
        $filter = new TestModelFilter(['filter' => ['deleted' => true]], [ // Use a concrete test filter with explicit fields.
            'deleted' => Field::custom(fn (Builder $query) => $query->withTrashed()), // Provide a developer-owned predicate for this fixture field.
        ]);

        $this->assertRejectedWithoutMutation($filter, LogicException::class, $query, []); // Check the fixture behavior required by this regression.
        self::assertStringContainsString('"advanced_notes"."deleted_at" is null', $query->toSql()); // Check the expected SQL or diagnostic text.
    }

    /** Verify that custom callbacks cannot change eager loading. */
    public function test_custom_callbacks_cannot_change_eager_loading(): void // Verify that custom callbacks cannot change eager loading.
    {
        $filter = new TestModelFilter(['filter' => ['include_notes' => true]], [ // Use a concrete test filter with explicit fields.
            'include_notes' => Field::custom(fn (Builder $query) => $query->with('notes')), // Provide a developer-owned predicate for this fixture field.
        ]);

        $this->assertRejectedWithoutMutation($filter, LogicException::class); // Check the fixture behavior required by this regression.
    }

    /** Verify that custom callbacks reject non predicate query changes. */
    #[DataProvider('nonPredicateCallbackOperations')] // Run this test against its declared scenario provider.
    public function test_custom_callbacks_reject_non_predicate_query_changes(string $operation): void // Verify that custom callbacks reject non predicate query changes.
    {
        $field = Field::custom(function (Builder $query) use ($operation): void { // Provide a developer-owned predicate for this fixture field.
            $query->where('score', 0); // Add the predicate needed by this fixture scenario.
            match ($operation) { // Choose the scenario-specific query mutation.
                'limit' => $query->limit(1), // Set the requested maximum result count.
                'order' => $query->orderBy('score'), // Make fixture result ordering deterministic.
                'select' => $query->select('id'), // Choose the caller-owned selection that filtering must preserve.
                'join' => $query->join('advanced_authors', 'advanced_authors.id', '=', 'advanced_products.id'), // Exercise the explicit join needed by this scenario.
            };
        });

        $this->assertRejectedWithoutMutation(new TestModelFilter(['filter' => ['custom' => true]], [ // Check the fixture behavior required by this regression.
            'custom' => $field, // Define the custom fixture value or field mapping.
        ]), LogicException::class); // Use the explicit fixture constant, enum, or framework operation.
    }

    /** Supply non predicate callback operations scenarios for the parameterized test. */
    public static function nonPredicateCallbackOperations(): iterable // Supply non predicate callback operations scenarios for the parameterized test.
    {
        yield 'limit' => ['limit']; // Exercise limit.
        yield 'ordering' => ['order']; // Exercise ordering.
        yield 'selection' => ['select']; // Exercise selection.
        yield 'join' => ['join']; // Exercise join.
    }

    /** Verify that custom where has callbacks keep related scope changes inside the subquery. */
    public function test_custom_where_has_callbacks_keep_related_scope_changes_inside_the_subquery(): void // Verify that custom where has callbacks keep related scope changes inside the subquery.
    {
        $query = AdvancedProduct::query()->where('tenant_id', 1); // Add the predicate needed by this fixture scenario.
        $filter = new TestModelFilter(['filter' => ['deleted_note' => 'deleted']], [ // Use a concrete test filter with explicit fields.
            'deleted_note' => Field::custom(function (Builder $query, mixed $value): void { // Provide a developer-owned predicate for this fixture field.
                $query->whereHas('notes', fn (Builder $notes) => $notes->withTrashed()->where('body', $value)); // Add the predicate needed by this fixture scenario.
            }),
        ]);

        $filter->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([3], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([], $query->removedScopes()); // Compare the expected value and PHP type for this scenario.
    }

    /** Verify that collection filters use exists and do not duplicate root rows. */
    public function test_collection_filters_use_exists_and_do_not_duplicate_root_rows(): void // Verify that collection filters use exists and do not duplicate root rows.
    {
        $query = $this->query(['filter' => ['note' => 'red'], 'sort' => 'id'], [ // Prepare query for this regression scenario.
            'id', // Supply the literal fixture argument used by this scenario.
            'note' => Field::related('notes.body'), // Exercise a relation predicate without duplicating parent rows.
        ]);

        self::assertSame([1, 2, 4], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertEmpty($query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
        self::assertStringContainsString('exists', strtolower($query->toSql())); // Check the expected SQL or diagnostic text.
    }

    /** Verify that collection filter honors global scopes and soft deletes. */
    public function test_collection_filter_honors_global_scopes_and_soft_deletes(): void // Verify that collection filter honors global scopes and soft deletes.
    {
        $query = $this->query(['filter' => ['note' => ['eq' => ['hidden', 'deleted']]]], [ // Prepare query for this regression scenario.
            'note' => Field::related('notes.body'), // Exercise a relation predicate without duplicating parent rows.
        ]);

        self::assertSame([], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that belongs to many filters honor pivot constraints without duplicate roots. */
    public function test_belongs_to_many_filters_honor_pivot_constraints_without_duplicate_roots(): void // Verify that belongs to many filters honor pivot constraints without duplicate roots.
    {
        $query = $this->query(['filter' => ['label' => ['in' => ['featured', 'common']]], 'sort' => 'id'], [ // Prepare query for this regression scenario.
            'id', // Supply the literal fixture argument used by this scenario.
            'label' => Field::related('labels.name'), // Exercise a relation predicate without duplicating parent rows.
        ]);

        self::assertSame([1, 2, 3, 4], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertEmpty($query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
        self::assertSame([1, 2, 4], $this->query(['filter' => ['label' => 'featured'], 'sort' => 'id'], [ // Compare the expected value and PHP type for this scenario.
            'id', // Supply the literal fixture argument used by this scenario.
            'label' => Field::related('labels.name'), // Exercise a relation predicate without duplicating parent rows.
        ])->get()->modelKeys()); // Compare the matching model identifiers with the expected fixture rows.
    }

    /** Verify that belongs to many search preserves related global scopes. */
    public function test_belongs_to_many_search_preserves_related_global_scopes(): void // Verify that belongs to many search preserves related global scopes.
    {
        $fields = ['id', 'label' => Field::related('labels.name')]; // Exercise a relation predicate without duplicating parent rows.

        self::assertSame([1, 2, 4], $this->query(['search' => ['label' => 'feat'], 'sort' => 'id'], $fields)->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([], $this->query(['search' => ['label' => 'archived']], $fields)->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that nested collection paths honor scopes on every relation. */
    public function test_nested_collection_paths_honor_scopes_on_every_relation(): void // Verify that nested collection paths honor scopes on every relation.
    {
        $query = $this->query(['filter' => ['author' => 'Hidden']], [ // Prepare query for this regression scenario.
            'author' => Field::related('notes.author.name'), // Exercise a relation predicate without duplicating parent rows.
        ]);

        self::assertSame([], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame([1, 2, 4], $this->query(['filter' => ['author' => 'Alice'], 'sort' => 'id'], [ // Compare the expected value and PHP type for this scenario.
            'id', // Supply the literal fixture argument used by this scenario.
            'author' => Field::related('notes.author.name'), // Exercise a relation predicate without duplicating parent rows.
        ])->get()->modelKeys()); // Compare the matching model identifiers with the expected fixture rows.
    }

    /** Verify that operator map must match one related record. */
    public function test_operator_map_must_match_one_related_record(): void // Verify that operator map must match one related record.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['note_score' => ['gte' => 10, 'lte' => 20]], // Provide named filter operands for this request.
            'sort' => 'id', // Declare the request ordering for this scenario.
        ], ['id', 'note_score' => Field::related('notes.score')]); // Exercise a relation predicate without duplicating parent rows.

        // Product 1 has scores 5 and 25: neither note is within the range.
        self::assertSame([2, 4], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that collection search can be ored with a base field. */
    public function test_collection_search_can_be_ored_with_a_base_field(): void // Verify that collection search can be ored with a base field.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'search' => ['note' => 'blue', 'name' => 'Gamma'], // Provide search terms using public field names.
            'sort' => 'id', // Declare the request ordering for this scenario.
        ], ['id', 'name', 'note' => Field::related('notes.body')]); // Exercise a relation predicate without duplicating parent rows.

        self::assertSame([2, 3], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that collection sorting is rejected by default. */
    public function test_collection_sorting_is_rejected_by_default(): void // Verify that collection sorting is rejected by default.
    {
        $this->assertRejectedWithoutMutation(new TestModelFilter(['sort' => 'note'], [ // Check the fixture behavior required by this regression.
            'note' => Field::related('notes.body'), // Exercise a relation predicate without duplicating parent rows.
        ]));
    }

    /** Verify that extended operators keep their documented comparison semantics. */
    #[DataProvider('extendedOperators')] // Run this test against its declared scenario provider.
    public function test_extended_operators_keep_their_documented_comparison_semantics( // Verify that extended operators keep their documented comparison semantics.
        string $field, // Prepare field for this regression scenario.
        string $operator, // Prepare operator for this regression scenario.
        mixed $value, // Prepare value for this regression scenario.
        array $expected, // Prepare expected for this regression scenario.
    ): void { // Accept the parameterized inputs for this test scenario.
        $query = $this->query(['filter' => [$field => [$operator => $value]], 'sort' => 'id'], [ // Prepare query for this regression scenario.
            'id', 'name', 'score', 'tag', // Supply the literal fixture argument used by this scenario.
        ]);

        self::assertSame($expected, $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.

    }

    /** Supply extended operators scenarios for the parameterized test. */
    public static function extendedOperators(): iterable // Supply extended operators scenarios for the parameterized test.
    {
        yield 'in' => ['id', 'in', [1, 3], [1, 3]]; // Exercise in.
        yield 'not in' => ['id', 'nin', [1, 3], [2, 4, 5]]; // Exercise not in.
        yield 'in including null' => ['tag', 'in', [null, 'b'], [1, 2]]; // Exercise in including null.
        yield 'not in including null' => ['tag', 'nin', [null, 'b'], [3, 4, 5]]; // Exercise not in including null.
        yield 'inclusive between' => ['score', 'between', [0, 10], [1, 2, 4]]; // Exercise inclusive between.
        yield 'not between' => ['score', 'nbetween', [0, 10], [3, 5]]; // Exercise not between.
        yield 'like pattern' => ['name', 'like', 'Al%', [1, 4]]; // Exercise like pattern.
        yield 'not like pattern' => ['name', 'nlike', 'Al%', [2, 3, 5]]; // Exercise not like pattern.
        yield 'literal contains' => ['name', 'contains', '%_!', [5]]; // Exercise literal contains.
        yield 'literal prefix' => ['name', 'starts_with', 'Literal %_', [5]]; // Exercise literal prefix.
        yield 'literal suffix' => ['name', 'ends_with', '%_!', [5]]; // Exercise literal suffix.
        yield 'ordinary prefix' => ['name', 'starts_with', 'Al', [1, 4]]; // Exercise ordinary prefix.
        yield 'ordinary suffix' => ['name', 'ends_with', 'pha', [1, 4]]; // Exercise ordinary suffix.
        yield 'not in alias' => ['id', 'not_in', [1, 3], [2, 4, 5]]; // Exercise not in alias.
        yield 'not between alias' => ['score', 'not_between', [0, 10], [3, 5]]; // Exercise not between alias.
        yield 'not like alias' => ['name', 'not_like', 'Al%', [2, 3, 5]]; // Exercise not like alias.
        yield 'not equal alias' => ['id', 'neq', 1, [2, 3, 4, 5]]; // Exercise not equal alias.
    }

    /** Verify that empty checks validate flags without transforming them as field values. */
    public function test_empty_checks_validate_flags_without_transforming_them_as_field_values(): void // Verify that empty checks validate flags without transforming them as field values.
    {
        $query = $this->query(['filter' => ['score' => ['is_empty' => false]]], [ // Prepare query for this regression scenario.
            'score' => Field::make('score') // Declare the SQL-backed field and its permitted operations.
                ->rules(['integer', 'min:1000']) // Apply rules to the current fixture definition.
                ->transform(fn () => throw new \LogicException('Flags must not be transformed.')), // Apply transform to the current fixture definition.
        ]);

        self::assertSame([1, 2, 3, 4, 5], $query->orderBy('id')->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that empty checks count towards the binding budget. */
    public function test_empty_checks_count_towards_the_binding_budget(): void // Verify that empty checks count towards the binding budget.
    {
        $this->assertRejectedWithoutMutation(new TestModelFilter([ // Check the fixture behavior required by this regression.
            'filter' => ['name' => ['is_empty' => false], 'tag' => ['is_empty' => true]], // Provide named filter operands for this request.
        ], ['name', 'tag'], new FilterLimits(maxBindings: 1))); // Set the explicit request complexity budget exercised by this case.
    }

    /** Verify that operator specific shapes are validated. */
    #[DataProvider('invalidOperatorValues')] // Run this test against its declared scenario provider.
    public function test_operator_specific_shapes_are_validated(string $operator, mixed $value): void // Verify that operator specific shapes are validated.
    {
        $this->assertRejectedWithoutMutation(new TestModelFilter(['filter' => ['score' => [$operator => $value]]], ['score'])); // Check the fixture behavior required by this regression.
    }

    /** Supply invalid operator values scenarios for the parameterized test. */
    public static function invalidOperatorValues(): iterable // Supply invalid operator values scenarios for the parameterized test.
    {
        yield 'in requires list' => ['in', 1]; // Exercise in requires list.
        yield 'not in requires list' => ['nin', 1]; // Exercise not in requires list.
        yield 'short between' => ['between', [0]]; // Exercise short between.
        yield 'long between' => ['between', [0, 10, 20]]; // Exercise long between.
        yield 'null between endpoint' => ['between', [null, 10]]; // Exercise null between endpoint.
        yield 'range rejects bool' => ['gte', false]; // Exercise range rejects bool.
        yield 'like rejects list' => ['like', ['value']]; // Exercise like rejects list.
        yield 'contains rejects null' => ['contains', null]; // Exercise contains rejects null.
        yield 'infinity' => ['eq', INF]; // Exercise infinity.
        yield 'not a number' => ['eq', NAN]; // Exercise not a number.
    }

    /** Verify that operator aliases cannot bypass a fields operator policy. */
    public function test_operator_aliases_cannot_bypass_a_fields_operator_policy(): void // Verify that operator aliases cannot bypass a fields operator policy.
    {
        $this->assertRejectedWithoutMutation(new TestModelFilter(['filter' => ['id' => ['not_in' => [1]]]], [ // Check the fixture behavior required by this regression.
            'id' => Field::make('id')->operators([FilterOperator::Equal]), // Declare the SQL-backed field and its permitted operations.
        ]));
        $query = $this->query(['filter' => ['id' => ['not_in' => [1, 2, 3, 4]]]], [ // Prepare query for this regression scenario.
            'id' => Field::make('id')->operators([FilterOperator::NotIn]), // Declare the SQL-backed field and its permitted operations.
        ]);

        self::assertSame([5], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that collection filter preserves an existing application join. */
    public function test_collection_filter_preserves_an_existing_application_join(): void // Verify that collection filter preserves an existing application join.
    {
        $query = AdvancedProduct::query() // Prepare query for this regression scenario.
            ->leftJoin('advanced_authors as owner', 'owner.id', '=', 'advanced_products.id') // Exercise the explicit join needed by this scenario.
            ->select('advanced_products.*') // Choose the caller-owned selection that filtering must preserve.
            ->where('advanced_products.tenant_id', 1); // Add the predicate needed by this fixture scenario.
        (new TestModelFilter(['filter' => ['note' => 'red']], [ // Use a concrete test filter with explicit fields.
            'note' => Field::related('notes.body'), // Exercise a relation predicate without duplicating parent rows.
        ]))->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([1, 2], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertCount(1, $query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
        self::assertSame('advanced_authors as owner', $query->getQuery()->joins[0]->table); // Compare the expected value and PHP type for this scenario.
    }

    /** Verify that collection filter clearly rejects an aliased root table without mutation. */
    public function test_collection_filter_clearly_rejects_an_aliased_root_table_without_mutation(): void // Verify that collection filter clearly rejects an aliased root table without mutation.
    {
        $query = AdvancedProduct::query()->from('advanced_products as p')->where('p.tenant_id', 1)->orderBy('p.id'); // Add the predicate needed by this fixture scenario.
        $filter = new TestModelFilter(['filter' => ['note' => 'red']], [ // Use a concrete test filter with explicit fields.
            'note' => Field::related('notes.body'), // Exercise a relation predicate without duplicating parent rows.
        ]);

        $this->assertRejectedWithoutMutation($filter, InvalidArgumentException::class, $query, [1, 2, 3, 5]); // Check the fixture behavior required by this regression.
    }

    /** Verify that explicit null containers are rejected instead of disabling filtering. */
    #[DataProvider('nullContainers')] // Run this test against its declared scenario provider.
    public function test_explicit_null_containers_are_rejected_instead_of_disabling_filtering(string $parameter): void // Verify that explicit null containers are rejected instead of disabling filtering.
    {
        $this->assertRejectedWithoutMutation(new TestModelFilter([$parameter => null], ['id', 'name'])); // Check the fixture behavior required by this regression.
    }

    /** Supply null containers scenarios for the parameterized test. */
    public static function nullContainers(): iterable // Supply null containers scenarios for the parameterized test.
    {
        yield 'filter' => ['filter']; // Exercise filter.
        yield 'search' => ['search']; // Exercise search.
        yield 'sort' => ['sort']; // Exercise sort.
        yield 'search type' => ['search_type']; // Exercise search type.
        yield 'where' => ['where']; // Exercise where.
    }

    /** Verify that empty set comparisons keep their boolean value inside or groups. */
    #[DataProvider('emptySetOperators')] // Run this test against its declared scenario provider.
    public function test_empty_set_comparisons_keep_their_boolean_value_inside_or_groups( // Verify that empty set comparisons keep their boolean value inside or groups.
        string $operator, // Prepare operator for this regression scenario.
        array $expected, // Prepare expected for this regression scenario.
    ): void { // Accept the parameterized inputs for this test scenario.
        $query = AdvancedProduct::query()->where('tenant_id', 1)->orderBy('id'); // Add the predicate needed by this fixture scenario.
        (new TestModelFilter(['where' => ['or' => [ // Use a concrete test filter with explicit fields.
            ['field' => 'id', 'operator' => $operator, 'value' => []], // Compare id with the scenario operator inside the boolean tree.
            ['field' => 'id', 'operator' => 'eq', 'value' => 1], // Compare id with eq inside the boolean tree.
        ]]], ['id']))->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame($expected, $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Supply empty set operators scenarios for the parameterized test. */
    public static function emptySetOperators(): iterable // Supply empty set operators scenarios for the parameterized test.
    {
        yield 'equal empty is false' => ['eq', [1]]; // Exercise equal empty is false.
        yield 'in empty is false' => ['in', [1]]; // Exercise in empty is false.
        yield 'not equal empty is true' => ['ne', [1, 2, 3, 5]]; // Exercise not equal empty is true.
        yield 'not in empty is true' => ['nin', [1, 2, 3, 5]]; // Exercise not in empty is true.
    }

    /** Verify that request budgets reject excessive work before mutation. */
    #[DataProvider('overBudgetRequests')] // Run this test against its declared scenario provider.
    public function test_request_budgets_reject_excessive_work_before_mutation(array $parameters, array $limits): void // Verify that request budgets reject excessive work before mutation.
    {
        $this->assertRejectedWithoutMutation(new TestModelFilter( // Check the fixture behavior required by this regression.
            $parameters, // Prepare parameters for this regression scenario.
            ['id', 'name', 'score'], // Declare only the public fields required by this request.
            new FilterLimits(...$limits), // Set the explicit request complexity budget exercised by this case.
        ));
    }

    /** Supply over budget requests scenarios for the parameterized test. */
    public static function overBudgetRequests(): iterable // Supply over budget requests scenarios for the parameterized test.
    {
        yield 'condition count' => [ // Exercise condition count.
            ['filter' => ['score' => ['gte' => 0, 'lte' => 20]]], // Provide comparison input that exercises the configured validation boundary.
            ['maxConditions' => 1], // Set the deliberately small complexity budget for the rejection case.
        ];
        yield 'value list size' => [ // Exercise value list size.
            ['filter' => ['id' => ['eq' => [1, 2, 3]]]], // Provide comparison input that exercises the configured validation boundary.
            ['maxValues' => 2], // Set the deliberately small complexity budget for the rejection case.
        ];
        yield 'sort count' => [['sort' => 'id,name'], ['maxSorts' => 1]]; // Exercise sort count.
        yield 'result limit' => [['limit' => 11], ['maxLimit' => 10]]; // Exercise result limit.
        yield 'search term length' => [['search' => ['name' => 'Alpha']], ['maxTermLength' => 4]]; // Exercise search term length.
        yield 'combined bindings' => [ // Exercise combined bindings.
            ['filter' => ['id' => ['eq' => [1, 2]], 'score' => ['eq' => [0, 10]]]], // Provide comparison input that exercises the configured validation boundary.
            ['maxBindings' => 3], // Set the deliberately small complexity budget for the rejection case.
        ];

        $tree = ['field' => 'id', 'operator' => 'eq', 'value' => 1]; // Prepare tree for this regression scenario.
        for ($depth = 0; $depth < 4; $depth++) { // Build a boolean tree deeper than the configured recursion budget.
            $tree = ['and' => [$tree]]; // Prepare tree for this regression scenario.
        }
        yield 'group depth' => [['where' => $tree], ['maxDepth' => 2]]; // Exercise group depth.
    }

    /** Verify that budget boundaries are inclusive. */
    public function test_budget_boundaries_are_inclusive(): void // Verify that budget boundaries are inclusive.
    {
        $query = AdvancedProduct::query(); // Prepare query for this regression scenario.
        (new TestModelFilter([ // Use a concrete test filter with explicit fields.
            'filter' => ['id' => ['eq' => [1, 2]]], // Provide named filter operands for this request.
            'sort' => 'id', // Declare the request ordering for this scenario.
            'limit' => 2, // Set the requested maximum result count.
        ], ['id'], new FilterLimits(maxConditions: 1, maxValues: 2, maxSorts: 1, maxLimit: 2, maxBindings: 2)))->apply($query); // Compile the filter into the caller-owned fixture query.

        self::assertSame([1, 2], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that applying a filter builds a query without executing it. */
    public function test_applying_a_filter_builds_a_query_without_executing_it(): void // Verify that applying a filter builds a query without executing it.
    {
        $connection = $this->database->getConnection(); // Prepare connection for this regression scenario.
        $connection->enableQueryLog(); // Observe whether compilation attempts to execute SQL.
        $connection->flushQueryLog(); // Discard setup queries before measuring compilation.

        $query = $this->query(['filter' => ['note' => 'red']], [ // Prepare query for this regression scenario.
            'note' => Field::related('notes.body'), // Exercise a relation predicate without duplicating parent rows.
        ]);

        self::assertSame([], $connection->getQueryLog()); // Verify compilation performed no database reads or writes.
        self::assertSame([1, 2, 4], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertCount(1, $connection->getQueryLog()); // Verify compilation performed no database reads or writes.
    }

    /** Verify that request values in boolean groups are bound as data. */
    public function test_request_values_in_boolean_groups_are_bound_as_data(): void // Verify that request values in boolean groups are bound as data.
    {
        $value = "' OR 1 = 1 --"; // Prepare value for this regression scenario.
        $query = $this->query(['where' => [ // Prepare query for this regression scenario.
            'field' => 'name', 'operator' => 'eq', 'value' => $value, // Define the field fixture value or field mapping.
        ]], ['name']); // Declare only the public fields required by this request.

        self::assertStringNotContainsString($value, $query->toSql()); // Check the expected SQL or diagnostic text.
        self::assertContains($value, $query->getBindings()); // Check parameter values and binding order after compilation.
        self::assertSame([], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Build and filter an isolated fixture query for result assertions. */
    private function query(array $parameters, array $fields): Builder // Build and filter an isolated fixture query for result assertions.
    {
        return AdvancedProduct::query()->filter(new TestModelFilter($parameters, $fields)); // Pass normalized request input to the shared parser.
    }

    /** @param class-string<\Throwable> $exceptionClass */
    private function assertRejectedWithoutMutation( // Verify that a rejected filter leaves caller SQL and bindings unchanged.
        TestModelFilter $filter, // Prepare filter for this regression scenario.
        string $exceptionClass = ValidationException::class, // Prepare exception class for this regression scenario.
        ?Builder $query = null, // Prepare query for this regression scenario.
        array $expected = [1, 2, 3], // Prepare expected for this regression scenario.
    ): \Throwable { // Return the rejection so a caller can also assert its actionable diagnostic.
        $query ??= AdvancedProduct::query()->where('tenant_id', 1)->orderBy('id')->limit(3); // Add the predicate needed by this fixture scenario.
        $originalSql = $query->toSql(); // Snapshot generated SQL for later mutation assertions.
        $originalBindings = $query->getBindings(); // Snapshot placeholder values before the operation under test.
        $originalScopes = $query->removedScopes(); // Prepare original scopes for this regression scenario.
        $originalEagerLoads = $query->getEagerLoads(); // Prepare original eager loads for this regression scenario.

        try { // Exercise the failure path without hiding later state assertions.
            $filter->apply($query); // Compile the filter into the caller-owned fixture query.
            self::fail('Expected the request to be rejected.'); // Fail explicitly if the expected rejection did not occur.
        } catch (\Throwable $exception) { // Inspect the expected failure and preserve caller-state assertions.
            self::assertInstanceOf($exceptionClass, $exception); // Check the fixture behavior required by this regression.
        }

        self::assertSame($originalSql, $query->toSql()); // Check the expected SQL or diagnostic text.
        self::assertSame($originalBindings, $query->getBindings()); // Check parameter values and binding order after compilation.
        self::assertSame($originalScopes, $query->removedScopes()); // Compare the expected value and PHP type for this scenario.
        self::assertSame($originalEagerLoads, $query->getEagerLoads()); // Compare the expected value and PHP type for this scenario.
        self::assertSame($expected, $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.

        return $exception; // Expose the same failure after all caller-state assertions pass.
    }

    /** Create and populate fixtures for the filter behavior under test. */
    private function createFixtures(): void // Create and populate fixtures for the filter behavior under test.
    {
        $schema = $this->database->schema(); // Prepare schema for this regression scenario.
        $schema->create('advanced_products', function (Blueprint $table): void { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->integer('tenant_id'); // Define the fixture tenant_id column.
            $table->string('name'); // Define the fixture name column.
            $table->integer('score'); // Define the fixture score column.
            $table->boolean('enabled'); // Define the fixture enabled column.
            $table->string('tag')->nullable(); // Define the fixture tag column.
        });
        $schema->create('advanced_authors', function (Blueprint $table): void { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->string('name'); // Define the fixture name column.
            $table->boolean('active'); // Define the fixture active column.
        });
        $schema->create('advanced_notes', function (Blueprint $table): void { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->integer('product_id'); // Define the fixture product_id column.
            $table->integer('author_id'); // Define the fixture author_id column.
            $table->string('body'); // Define the fixture body column.
            $table->integer('score'); // Define the fixture score column.
            $table->boolean('visible'); // Define the fixture visible column.
            $table->softDeletes(); // Define the fixture table schema.
        });
        $schema->create('advanced_labels', function (Blueprint $table): void { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->string('name'); // Define the fixture name column.
            $table->boolean('active'); // Define the fixture active column.
        });
        $schema->create('advanced_product_label', function (Blueprint $table): void { // Create the fixture table needed by these assertions.
            $table->integer('product_id'); // Define the fixture product_id column.
            $table->integer('label_id'); // Define the fixture label_id column.
            $table->boolean('approved'); // Define the fixture approved column.
            $table->primary(['product_id', 'label_id']); // Define the fixture table schema.
        });

        $this->database->table('advanced_products')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 1, 'tenant_id' => 1, 'name' => 'Alpha', 'score' => 0, 'enabled' => false, 'tag' => null], // Seed fixture record 1 with deliberately contrasting values.
            ['id' => 2, 'tenant_id' => 1, 'name' => 'Beta', 'score' => 10, 'enabled' => true, 'tag' => 'b'], // Seed fixture record 2 with deliberately contrasting values.
            ['id' => 3, 'tenant_id' => 1, 'name' => 'Gamma', 'score' => 20, 'enabled' => false, 'tag' => 'c'], // Seed fixture record 3 with deliberately contrasting values.
            ['id' => 4, 'tenant_id' => 2, 'name' => 'Alpha', 'score' => 5, 'enabled' => true, 'tag' => 'd'], // Seed fixture record 4 with deliberately contrasting values.
            ['id' => 5, 'tenant_id' => 1, 'name' => 'Literal %_!', 'score' => 30, 'enabled' => true, 'tag' => 'e'], // Seed fixture record 5 with deliberately contrasting values.
        ]);
        $this->database->table('advanced_authors')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 1, 'name' => 'Alice', 'active' => true], // Seed fixture record 1 with deliberately contrasting values.
            ['id' => 2, 'name' => 'Bob', 'active' => true], // Seed fixture record 2 with deliberately contrasting values.
            ['id' => 3, 'name' => 'Hidden', 'active' => false], // Seed fixture record 3 with deliberately contrasting values.
        ]);
        $this->database->table('advanced_labels')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 1, 'name' => 'featured', 'active' => true], // Seed fixture record 1 with deliberately contrasting values.
            ['id' => 2, 'name' => 'archived', 'active' => false], // Seed fixture record 2 with deliberately contrasting values.
            ['id' => 3, 'name' => 'common', 'active' => true], // Seed fixture record 3 with deliberately contrasting values.
        ]);
        $this->database->table('advanced_product_label')->insert([ // Load contrasting rows for the result-set assertions.
            ['product_id' => 1, 'label_id' => 1, 'approved' => true], // Seed pivot ownership and approval state for relation-scope assertions.
            ['product_id' => 1, 'label_id' => 3, 'approved' => true], // Seed pivot ownership and approval state for relation-scope assertions.
            ['product_id' => 2, 'label_id' => 1, 'approved' => true], // Seed pivot ownership and approval state for relation-scope assertions.
            ['product_id' => 2, 'label_id' => 2, 'approved' => true], // Seed pivot ownership and approval state for relation-scope assertions.
            ['product_id' => 3, 'label_id' => 1, 'approved' => false], // Seed pivot ownership and approval state for relation-scope assertions.
            ['product_id' => 3, 'label_id' => 3, 'approved' => true], // Seed pivot ownership and approval state for relation-scope assertions.
            ['product_id' => 4, 'label_id' => 1, 'approved' => true], // Seed pivot ownership and approval state for relation-scope assertions.
            ['product_id' => 5, 'label_id' => 2, 'approved' => true], // Seed pivot ownership and approval state for relation-scope assertions.
        ]);
        $this->database->table('advanced_notes')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 11, 'product_id' => 1, 'author_id' => 1, 'body' => 'red', 'score' => 5, 'visible' => true, 'deleted_at' => null], // Seed fixture record 11 with deliberately contrasting values.
            ['id' => 12, 'product_id' => 1, 'author_id' => 1, 'body' => 'red', 'score' => 25, 'visible' => true, 'deleted_at' => null], // Seed fixture record 12 with deliberately contrasting values.
            ['id' => 13, 'product_id' => 2, 'author_id' => 2, 'body' => 'blue', 'score' => 15, 'visible' => true, 'deleted_at' => null], // Seed fixture record 13 with deliberately contrasting values.
            ['id' => 14, 'product_id' => 2, 'author_id' => 1, 'body' => 'red', 'score' => 17, 'visible' => true, 'deleted_at' => null], // Seed fixture record 14 with deliberately contrasting values.
            ['id' => 15, 'product_id' => 3, 'author_id' => 1, 'body' => 'hidden', 'score' => 15, 'visible' => false, 'deleted_at' => null], // Seed fixture record 15 with deliberately contrasting values.
            ['id' => 16, 'product_id' => 4, 'author_id' => 1, 'body' => 'red', 'score' => 15, 'visible' => true, 'deleted_at' => null], // Seed fixture record 16 with deliberately contrasting values.
            ['id' => 17, 'product_id' => 3, 'author_id' => 1, 'body' => 'deleted', 'score' => 15, 'visible' => true, 'deleted_at' => '2026-01-01 00:00:00'], // Seed fixture record 17 with deliberately contrasting values.
            ['id' => 18, 'product_id' => 5, 'author_id' => 2, 'body' => 'Literal %_!', 'score' => 0, 'visible' => true, 'deleted_at' => null], // Seed fixture record 18 with deliberately contrasting values.
            ['id' => 19, 'product_id' => 3, 'author_id' => 3, 'body' => 'hidden author', 'score' => 50, 'visible' => true, 'deleted_at' => null], // Seed fixture record 19 with deliberately contrasting values.
        ]);
    }
}

class AdvancedProduct extends Model // Provide the advanced product fixture.
{
    use Filterable; // Enable model filter resolution for this fixture.

    protected $table = 'advanced_products'; // Point the model at its isolated fixture table.

    public $timestamps = false; // Keep fixture queries independent of timestamp columns.

    /** Define the notes relation used by fixture queries. */
    public function notes(): HasMany // Define the notes relation used by fixture queries.
    {
        return $this->hasMany(AdvancedNote::class, 'product_id'); // Expose the related fixture records to Eloquent.
    }

    /** Define the labels relation used by fixture queries. */
    public function labels(): BelongsToMany // Define the labels relation used by fixture queries.
    {
        return $this->belongsToMany(AdvancedLabel::class, 'advanced_product_label', 'product_id', 'label_id') // Declare the owner and foreign keys exercised by relation queries.
            ->wherePivot('approved', true); // Add the predicate needed by this fixture scenario.
    }
}

class AdvancedNote extends Model // Provide the advanced note fixture.
{
    use SoftDeletes; // Exercise soft-delete scopes on the fixture model.

    protected $table = 'advanced_notes'; // Point the model at its isolated fixture table.

    public $timestamps = false; // Keep fixture queries independent of timestamp columns.

    /** Register fixture scopes that relation compilation must preserve. */
    protected static function booted(): void // Register fixture scopes that relation compilation must preserve.
    {
        static::addGlobalScope('visible', fn (Builder $query) => $query->where('advanced_notes.visible', true)); // Add the predicate needed by this fixture scenario.
    }

    /** Define the author relation used by fixture queries. */
    public function author(): BelongsTo // Define the author relation used by fixture queries.
    {
        return $this->belongsTo(AdvancedAuthor::class, 'author_id'); // Declare the owner and foreign keys exercised by relation queries.
    }
}

class AdvancedAuthor extends Model // Provide the advanced author fixture.
{
    protected $table = 'advanced_authors'; // Point the model at its isolated fixture table.

    public $timestamps = false; // Keep fixture queries independent of timestamp columns.

    /** Register fixture scopes that relation compilation must preserve. */
    protected static function booted(): void // Register fixture scopes that relation compilation must preserve.
    {
        static::addGlobalScope('active', fn (Builder $query) => $query->where('advanced_authors.active', true)); // Add the predicate needed by this fixture scenario.
    }
}

class AdvancedLabel extends Model // Provide the advanced label fixture.
{
    protected $table = 'advanced_labels'; // Point the model at its isolated fixture table.

    public $timestamps = false; // Keep fixture queries independent of timestamp columns.

    /** Register fixture scopes that relation compilation must preserve. */
    protected static function booted(): void // Register fixture scopes that relation compilation must preserve.
    {
        static::addGlobalScope('active', fn (Builder $query) => $query->where('advanced_labels.active', true)); // Add the predicate needed by this fixture scenario.
    }
}
