<?php

namespace Alif\QueryFilter\Tests; // Keep test fixtures separate from production classes.

use Alif\QueryFilter\Enums\FilterOperator; // Use FilterOperator in this test fixture.
use Alif\QueryFilter\Enums\JsonType; // Use JsonType in this test fixture.
use Alif\QueryFilter\FilterLimits; // Use FilterLimits in this test fixture.
use Alif\QueryFilter\JsonColumn; // Use JsonColumn in this test fixture.
use Illuminate\Container\Container; // Use Container in this test fixture.
use Illuminate\Database\Capsule\Manager as Capsule; // Use Capsule in this test fixture.
use Illuminate\Database\Eloquent\Model; // Use Model in this test fixture.
use Illuminate\Database\Schema\Blueprint; // Use Blueprint in this test fixture.
use Illuminate\Support\Facades\Facade; // Use Facade in this test fixture.
use Illuminate\Translation\ArrayLoader; // Use ArrayLoader in this test fixture.
use Illuminate\Translation\Translator; // Use Translator in this test fixture.
use Illuminate\Validation\Factory; // Use Factory in this test fixture.
use Illuminate\Validation\ValidationException; // Use ValidationException in this test fixture.
use InvalidArgumentException; // Use InvalidArgumentException in this test fixture.
use PHPUnit\Framework\Attributes\DataProvider; // Use DataProvider in this test fixture.
use PHPUnit\Framework\TestCase; // Use TestCase in this test fixture.

class FilterOperatorTest extends TestCase // Group regression coverage for filter operator.
{
    private Capsule $database; // Retain database for this test fixture.

    /** Create isolated framework services and database fixtures for each test. */
    protected function setUp(): void // Create isolated framework services and database fixtures for each test.
    {
        parent::setUp(); // Initialize PHPUnit before creating isolated fixtures.
        $container = new Container(); // Create framework services isolated from other tests.
        Container::setInstance($container); // Point framework lookups at this test container.
        $container->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $container)); // Register the framework dependency required by this fixture.
        Facade::clearResolvedInstances(); // Prevent facade instances from leaking between tests.
        Facade::setFacadeApplication($container); // Point framework lookups at this test container.

        $this->database = new Capsule($container); // Create the database manager for isolated fixture queries.
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']); // Configure the isolated database connection for this scenario.
        $this->database->setAsGlobal(); // Connect fixture models to the isolated database manager.
        $this->database->bootEloquent(); // Connect fixture models to the isolated database manager.
        $this->database->schema()->create('operator_records', function (Blueprint $table) { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->string('value')->nullable(); // Define the fixture value column.
        });
        $this->database->table('operator_records')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 1, 'value' => 'a'], // Seed fixture record 1 with deliberately contrasting values.
            ['id' => 2, 'value' => 'b'], // Seed fixture record 2 with deliberately contrasting values.
            ['id' => 3, 'value' => null], // Seed fixture record 3 with deliberately contrasting values.
            ['id' => 4, 'value' => '100%_!'], // Seed fixture record 4 with deliberately contrasting values.
            ['id' => 5, 'value' => '100xyz!'], // Seed fixture record 5 with deliberately contrasting values.
        ]);
    }

    /** Release database and framework state so fixtures cannot affect later tests. */
    protected function tearDown(): void // Release database and framework state so fixtures cannot affect later tests.
    {
        $this->database->getConnection()->disconnect(); // Release the fixture database connection.
        Model::clearBootedModels(); // Remove model state retained by previous fixture queries.
        Facade::clearResolvedInstances(); // Prevent facade instances from leaking between tests.
        Facade::setFacadeApplication(null); // Detach the isolated framework container during cleanup.
        Container::setInstance(null); // Detach the isolated framework container during cleanup.
        parent::tearDown(); // Complete PHPUnit cleanup after releasing framework state.
    }

    /** Verify that nullable set semantics. */
    #[DataProvider('setComparisons')] // Run this test against its declared scenario provider.
    public function test_nullable_set_semantics(FilterOperator $operator, mixed $value, array $expected): void // Verify that nullable set semantics.
    {
        $query = OperatorRecord::query(); // Prepare query for this regression scenario.
        $operator->apply($query, 'value', $operator->normalize($value, 'filter.value', new FilterLimits())); // Compile the filter into the caller-owned fixture query.

        $this->assertSame($expected, $query->orderBy('id')->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Supply set comparisons scenarios for the parameterized test. */
    public static function setComparisons(): iterable // Supply set comparisons scenarios for the parameterized test.
    {
        yield 'scalar equality' => [FilterOperator::Equal, 'a', [1]]; // Exercise scalar equality.
        yield 'scalar null' => [FilterOperator::Equal, null, [3]]; // Exercise scalar null.
        yield 'equal nullable set' => [FilterOperator::Equal, ['a', null], [1, 3]]; // Exercise equal nullable set.
        yield 'not equal nullable set' => [FilterOperator::NotEqual, ['a', null], [2, 4, 5]]; // Exercise not equal nullable set.
        yield 'in nullable set' => [FilterOperator::In, ['a', null], [1, 3]]; // Exercise in nullable set.
        yield 'not in nullable set' => [FilterOperator::NotIn, ['a', null], [2, 4, 5]]; // Exercise not in nullable set.
        yield 'empty in' => [FilterOperator::In, [], []]; // Exercise empty in.
        yield 'empty not in' => [FilterOperator::NotIn, [], [1, 2, 3, 4, 5]]; // Exercise empty not in.
        yield 'null test' => [FilterOperator::IsNull, 'true', [3]]; // Exercise null test.
        yield 'not null test' => [FilterOperator::IsNull, '0', [1, 2, 4, 5]]; // Exercise not null test.
    }

    /** Verify that empty matches only null or exact empty strings and preserves existing constraints. */
    public function test_empty_matches_only_null_or_exact_empty_strings_and_preserves_existing_constraints(): void // Verify that empty matches only null or exact empty strings and preserves existing constraints.
    {
        $this->database->table('operator_records')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 6, 'value' => null], // Seed fixture record 6 with deliberately contrasting values.
            ['id' => 7, 'value' => ''], // Seed fixture record 7 with deliberately contrasting values.
            ['id' => 8, 'value' => 0], // Seed fixture record 8 with deliberately contrasting values.
            ['id' => 9, 'value' => false], // Seed fixture record 9 with deliberately contrasting values.
            ['id' => 10, 'value' => '0'], // Seed fixture record 10 with deliberately contrasting values.
            ['id' => 11, 'value' => ' '], // Seed fixture record 11 with deliberately contrasting values.
            ['id' => 12, 'value' => '[]'], // Seed fixture record 12 with deliberately contrasting values.
            ['id' => 13, 'value' => '{}'], // Seed fixture record 13 with deliberately contrasting values.
        ]);

        $empty = OperatorRecord::query()->where('id', '>', 3); // Add the predicate needed by this fixture scenario.
        FilterOperator::IsEmpty->apply($empty, 'operator_records.value', true); // Compile the filter into the caller-owned fixture query.
        $this->assertSame([6, 7], $empty->orderBy('id')->get()->modelKeys()); // Check the exact fixture rows visible through this query.

        $notEmpty = OperatorRecord::query()->where('id', '>', 3); // Add the predicate needed by this fixture scenario.
        FilterOperator::IsEmpty->apply($notEmpty, 'operator_records.value', false); // Compile the filter into the caller-owned fixture query.
        $this->assertSame([4, 5, 8, 9, 10, 11, 12, 13], $notEmpty->orderBy('id')->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that empty supports json scalar expressions without treating arrays or objects as empty. */
    public function test_empty_supports_json_scalar_expressions_without_treating_arrays_or_objects_as_empty(): void // Verify that empty supports json scalar expressions without treating arrays or objects as empty.
    {
        $this->database->schema()->table('operator_records', function (Blueprint $table) { // Prepare the fixture operation needed by this regression.
            $table->json('assignments')->nullable(); // Define the fixture assignments column.
        });
        $values = [null, '', 0, false, '0', ' ', [], (object) [], true]; // Prepare values for this regression scenario.
        foreach ($values as $index => $value) { // Run the same assertions for each relevant fixture case.
            $this->database->table('operator_records')->insert([ // Load contrasting rows for the result-set assertions.
                'id' => $index + 6, // Define the id fixture value or field mapping.
                'assignments' => json_encode(['value' => $value], JSON_THROW_ON_ERROR), // Define the assignments fixture value or field mapping.
            ]);
        }
        $this->database->table('operator_records')->insert(['id' => 15, 'assignments' => '{}']); // Load contrasting rows for the result-set assertions.
        $column = (new JsonColumn('assignments->value'))->expression($this->database->getConnection(), 'operator_records'); // Declare a typed JSON scalar used by the fixture.

        $empty = OperatorRecord::query()->whereNotNull('assignments'); // Add the predicate needed by this fixture scenario.
        FilterOperator::IsEmpty->apply($empty, $column, true); // Compile the filter into the caller-owned fixture query.
        $this->assertSame([6, 7, 15], $empty->orderBy('id')->get()->modelKeys()); // Check the exact fixture rows visible through this query.

        $notEmpty = OperatorRecord::query()->whereNotNull('assignments'); // Add the predicate needed by this fixture scenario.
        FilterOperator::IsEmpty->apply($notEmpty, $column, false); // Compile the filter into the caller-owned fixture query.
        $this->assertSame([8, 9, 10, 11, 12, 13, 14], $notEmpty->orderBy('id')->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that empty uses driver safe string comparisons. */
    #[DataProvider('emptySqlDrivers')] // Run this test against its declared scenario provider.
    public function test_empty_uses_driver_safe_string_comparisons(string $driver, string $comparison, mixed $binding): void // Verify that empty uses driver safe string comparisons.
    {
        $this->database->addConnection(['driver' => $driver, 'database' => 'unused'], 'empty_test'); // Configure the isolated database connection for this scenario.
        foreach ([true, false] as $flag) { // Run the same assertions for each relevant fixture case.
            $query = (new OperatorRecord())->setConnection('empty_test')->newQuery(); // Prepare query for this regression scenario.
            FilterOperator::IsEmpty->apply($query, 'operator_records.value', $flag); // Compile the filter into the caller-owned fixture query.
            $operator = $flag ? '=' : '<>'; // Prepare operator for this regression scenario.

            $this->assertStringContainsString("$comparison $operator ?", $query->toSql()); // Check the expected SQL or diagnostic text.
            $this->assertStringContainsString($flag ? ' is null or ' : ' is not null and ', $query->toSql()); // Check the expected SQL or diagnostic text.
            $this->assertSame([$binding], $query->getBindings()); // Check parameter values and binding order after compilation.
        }
    }

    /** Supply empty sql drivers scenarios for the parameterized test. */
    public static function emptySqlDrivers(): iterable // Supply empty sql drivers scenarios for the parameterized test.
    {
        yield 'sqlite' => ['sqlite', 'CAST("operator_records"."value" AS TEXT)', '']; // Exercise sqlite.
        yield 'postgres' => ['pgsql', 'CAST("operator_records"."value" AS TEXT)', '']; // Exercise postgres.
        yield 'mysql' => ['mysql', 'CHAR_LENGTH(CAST(`operator_records`.`value` AS CHAR))', 0]; // Exercise mysql.
        yield 'mariadb' => ['mariadb', 'CHAR_LENGTH(CAST(`operator_records`.`value` AS CHAR))', 0]; // Exercise mariadb.
        yield 'sql server' => ['sqlsrv', 'DATALENGTH(CAST([operator_records].[value] AS NVARCHAR(MAX)))', 0]; // Exercise sql server.
    }

    /** Verify that empty casts typed postgres json expressions to text before comparison. */
    public function test_empty_casts_typed_postgres_json_expressions_to_text_before_comparison(): void // Verify that empty casts typed postgres json expressions to text before comparison.
    {
        $this->database->addConnection(['driver' => 'pgsql', 'database' => 'unused'], 'postgres'); // Configure the isolated database connection for this scenario.
        foreach ([JsonType::Integer, JsonType::Decimal, JsonType::Boolean, JsonType::Uuid] as $type) { // Run the same assertions for each relevant fixture case.
            $query = (new OperatorRecord())->setConnection('postgres')->newQuery(); // Prepare query for this regression scenario.
            $column = (new JsonColumn('assignments->value', $type))->expression($query->getConnection(), 'operator_records'); // Declare a typed JSON scalar used by the fixture.
            $expression = $column->getValue($query->getConnection()->getQueryGrammar()); // Prepare expression for this regression scenario.
            FilterOperator::IsEmpty->apply($query, $column, true); // Compile the filter into the caller-owned fixture query.

            $this->assertStringContainsString("$expression is null or CAST($expression AS TEXT) = ?", $query->toSql()); // Check the expected SQL or diagnostic text.
            $this->assertSame([''], $query->getBindings()); // Check parameter values and binding order after compilation.
        }
    }

    /** Verify that empty normalizes the same strict flags as null checks. */
    #[DataProvider('booleanFlags')] // Run this test against its declared scenario provider.
    public function test_empty_normalizes_the_same_strict_flags_as_null_checks(mixed $value, bool $expected): void // Verify that empty normalizes the same strict flags as null checks.
    {
        foreach ([FilterOperator::IsNull, FilterOperator::IsEmpty] as $operator) { // Run the same assertions for each relevant fixture case.
            $this->assertSame($expected, $operator->normalize($value, 'filter.value', new FilterLimits())); // Compare the expected value and PHP type for this scenario.
        }
    }

    /** Supply boolean flags scenarios for the parameterized test. */
    public static function booleanFlags(): iterable // Supply boolean flags scenarios for the parameterized test.
    {
        foreach ([true, 1, '1', 'true'] as $value) { // Run the same assertions for each relevant fixture case.
            yield [$value, true]; // Exercise this data-provider case.
        }
        foreach ([false, 0, '0', 'false'] as $value) { // Run the same assertions for each relevant fixture case.
            yield [$value, false]; // Exercise this data-provider case.
        }
    }

    /** Verify that empty rejects invalid flags with an operator specific message. */
    public function test_empty_rejects_invalid_flags_with_an_operator_specific_message(): void // Verify that empty rejects invalid flags with an operator specific message.
    {
        foreach (['yes', 'TRUE', '', null, [], [true], 1.0, 2] as $value) { // Run the same assertions for each relevant fixture case.
            try { // Exercise the failure path without hiding later state assertions.
                FilterOperator::IsEmpty->normalize($value, 'filter.value.is_empty', new FilterLimits()); // Use the explicit fixture constant, enum, or framework operation.
                $this->fail('Expected invalid is_empty flag to be rejected.'); // Fail explicitly if the expected rejection did not occur.
            } catch (ValidationException $exception) { // Inspect the expected failure and preserve caller-state assertions.
                $this->assertSame(['filter.value.is_empty' => ['is_empty must be a boolean.']], $exception->errors()); // Compare the expected value and PHP type for this scenario.
            }
        }
    }

    /** Verify that literal patterns escape wildcards. */
    #[DataProvider('patterns')] // Run this test against its declared scenario provider.
    public function test_literal_patterns_escape_wildcards(FilterOperator $operator, string $term): void // Verify that literal patterns escape wildcards.
    {
        $query = OperatorRecord::query(); // Prepare query for this regression scenario.
        $operator->apply($query, 'operator_records.value', $operator->normalize($term, 'filter.value', new FilterLimits())); // Compile the filter into the caller-owned fixture query.

        $this->assertSame([4], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        $this->assertStringContainsString("ESCAPE '!'", $query->toSql()); // Check the expected SQL or diagnostic text.
        $this->assertStringNotContainsString($term, $query->toSql()); // Check the expected SQL or diagnostic text.
    }

    /** Supply patterns scenarios for the parameterized test. */
    public static function patterns(): iterable // Supply patterns scenarios for the parameterized test.
    {
        yield 'contains' => [FilterOperator::Contains, '%_']; // Exercise contains.
        yield 'starts with' => [FilterOperator::StartsWith, '100%']; // Exercise starts with.
        yield 'ends with' => [FilterOperator::EndsWith, '_!']; // Exercise ends with.
    }

    /** Verify that postgres patterns cast fields and bind the term. */
    public function test_postgres_patterns_cast_fields_and_bind_the_term(): void // Verify that postgres patterns cast fields and bind the term.
    {
        $this->database->addConnection(['driver' => 'pgsql', 'database' => 'unused'], 'postgres'); // Configure the isolated database connection for this scenario.
        $query = (new OperatorRecord())->setConnection('postgres')->newQuery(); // Prepare query for this regression scenario.
        FilterOperator::Contains->apply($query, 'operator_records.value', "x%' OR 1=1 --"); // Compile the filter into the caller-owned fixture query.

        $this->assertStringContainsString('"operator_records"."value"::text ILIKE ?', $query->toSql()); // Check the expected SQL or diagnostic text.
        $this->assertSame(["%x!%' OR 1=1 --%"], $query->getBindings()); // Check parameter values and binding order after compilation.
    }

    /** Verify that range and sql pattern operators. */
    public function test_range_and_sql_pattern_operators(): void // Verify that range and sql pattern operators.
    {
        $between = OperatorRecord::query(); // Prepare between for this regression scenario.
        FilterOperator::Between->apply($between, 'id', [2, 4]); // Compile the filter into the caller-owned fixture query.
        $this->assertSame([2, 3, 4], $between->get()->modelKeys()); // Check the exact fixture rows visible through this query.

        $outside = OperatorRecord::query(); // Prepare outside for this regression scenario.
        FilterOperator::NotBetween->apply($outside, 'id', [2, 4]); // Compile the filter into the caller-owned fixture query.
        $this->assertSame([1, 5], $outside->get()->modelKeys()); // Check the exact fixture rows visible through this query.

        $like = OperatorRecord::query(); // Prepare like for this regression scenario.
        FilterOperator::Like->apply($like, 'value', '100%'); // Compile the filter into the caller-owned fixture query.
        $this->assertSame([4, 5], $like->get()->modelKeys()); // Check the exact fixture rows visible through this query.

        $notLike = OperatorRecord::query(); // Prepare not like for this regression scenario.
        FilterOperator::NotLike->apply($notLike, 'value', '100%'); // Compile the filter into the caller-owned fixture query.
        $this->assertSame([1, 2], $notLike->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that aliases and boolean patterns. */
    public function test_aliases_and_boolean_patterns(): void // Verify that aliases and boolean patterns.
    {
        foreach (['neq' => 'ne', 'not_in' => 'nin', 'not_between' => 'nbetween', 'not_like' => 'nlike'] as $alias => $canonical) { // Run the same assertions for each relevant fixture case.
            $this->assertSame(FilterOperator::from($canonical), FilterOperator::resolve($alias)); // Compare the expected value and PHP type for this scenario.
        }
        $this->assertNull(FilterOperator::resolve('null')); // Check the fixture behavior required by this regression.
        $this->assertNull(FilterOperator::resolve('notnull')); // Check the fixture behavior required by this regression.
        $this->assertSame('0', FilterOperator::Contains->normalize(false, 'filter.value', new FilterLimits())); // Compare the expected value and PHP type for this scenario.
    }

    /** Verify that invalid values produce validation errors. */
    #[DataProvider('invalidValues')] // Run this test against its declared scenario provider.
    public function test_invalid_values_produce_validation_errors(FilterOperator $operator, mixed $value, ?FilterLimits $limits = null): void // Verify that invalid values produce validation errors.
    {
        try { // Exercise the failure path without hiding later state assertions.
            $operator->normalize($value, 'filter.value', $limits ?? new FilterLimits()); // Prepare the fixture operation needed by this regression.
            $this->fail('Expected validation to reject the value.'); // Fail explicitly if the expected rejection did not occur.
        } catch (ValidationException $exception) { // Inspect the expected failure and preserve caller-state assertions.
            $this->assertArrayHasKey('filter.value', $exception->errors()); // Check that only the expected validation or configuration keys exist.
        }
    }

    /** Supply invalid values scenarios for the parameterized test. */
    public static function invalidValues(): iterable // Supply invalid values scenarios for the parameterized test.
    {
        yield 'scalar in' => [FilterOperator::In, 'a']; // Exercise scalar in.
        yield 'associative list' => [FilterOperator::Equal, ['key' => 'value']]; // Exercise associative list.
        yield 'nested list' => [FilterOperator::Equal, [['a']]]; // Exercise nested list.
        yield 'nonfinite list' => [FilterOperator::In, [INF]]; // Exercise nonfinite list.
        yield 'nonfinite range' => [FilterOperator::GreaterThan, NAN]; // Exercise nonfinite range.
        yield 'boolean range' => [FilterOperator::GreaterThan, true]; // Exercise boolean range.
        yield 'null range' => [FilterOperator::LessThan, null]; // Exercise null range.
        yield 'three between values' => [FilterOperator::Between, [1, 2, 3]]; // Exercise three between values.
        yield 'null between value' => [FilterOperator::NotBetween, [1, null]]; // Exercise null between value.
        yield 'boolean between value' => [FilterOperator::Between, [false, true]]; // Exercise boolean between value.
        yield 'null string pattern' => [FilterOperator::Contains, null]; // Exercise null string pattern.
        yield 'invalid null boolean' => [FilterOperator::IsNull, 'yes']; // Exercise invalid null boolean.
        yield 'float null boolean' => [FilterOperator::IsNull, 1.0]; // Exercise float null boolean.
        yield 'too many values' => [FilterOperator::Equal, [1, 2], new FilterLimits(maxValues: 1)]; // Exercise too many values.
        yield 'long equality string' => [FilterOperator::Equal, 'abcd', new FilterLimits(maxTermLength: 3)]; // Exercise long equality string.
        yield 'long range string' => [FilterOperator::GreaterThan, 'abcd', new FilterLimits(maxTermLength: 3)]; // Exercise long range string.
        yield 'long list string' => [FilterOperator::In, ['abcd'], new FilterLimits(maxTermLength: 3)]; // Exercise long list string.
        yield 'long numeric pattern' => [FilterOperator::Contains, 1234, new FilterLimits(maxTermLength: 3)]; // Exercise long numeric pattern.
    }

    /** Verify that all limits must be positive. */
    public function test_all_limits_must_be_positive(): void // Verify that all limits must be positive.
    {
        foreach (array_keys(get_object_vars(new FilterLimits())) as $property) { // Run the same assertions for each relevant fixture case.
            try { // Exercise the failure path without hiding later state assertions.
                new FilterLimits(...[$property => 0]); // Set the explicit request complexity budget exercised by this case.
                $this->fail("Expected $property to reject zero."); // Fail explicitly if the expected rejection did not occur.
            } catch (InvalidArgumentException $exception) { // Inspect the expected failure and preserve caller-state assertions.
                $this->assertStringContainsString($property, $exception->getMessage()); // Check the expected SQL or diagnostic text.
            }
        }
    }
}

class OperatorRecord extends Model // Provide the operator record fixture.
{
    protected $table = 'operator_records'; // Point the model at its isolated fixture table.
    public $timestamps = false; // Keep fixture queries independent of timestamp columns.
}
