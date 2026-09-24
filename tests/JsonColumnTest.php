<?php

namespace Alif\QueryFilter\Tests; // Keep test fixtures separate from production classes.

use Alif\QueryFilter\Enums\JsonType; // Use JsonType in this test fixture.
use Alif\QueryFilter\JsonColumn; // Use JsonColumn in this test fixture.
use Illuminate\Database\Connection; // Use Connection in this test fixture.
use Illuminate\Database\MariaDbConnection; // Use MariaDbConnection in this test fixture.
use Illuminate\Database\MySqlConnection; // Use MySqlConnection in this test fixture.
use Illuminate\Database\PostgresConnection; // Use PostgresConnection in this test fixture.
use Illuminate\Database\SQLiteConnection; // Use SQLiteConnection in this test fixture.
use Illuminate\Database\Schema\Blueprint; // Use Blueprint in this test fixture.
use InvalidArgumentException; // Use InvalidArgumentException in this test fixture.
use PDO; // Use PDO in this test fixture.
use PHPUnit\Framework\Attributes\DataProvider; // Use DataProvider in this test fixture.
use PHPUnit\Framework\TestCase; // Use TestCase in this test fixture.

final class JsonColumnTest extends TestCase // Group regression coverage for json column.
{
    private SQLiteConnection $database; // Retain database for this test fixture.

    /** Create isolated framework services and database fixtures for each test. */
    protected function setUp(): void // Create isolated framework services and database fixtures for each test.
    {
        $this->database = new SQLiteConnection(new PDO('sqlite::memory:'), '', '', ['driver' => 'sqlite']); // Use the explicit fixture constant, enum, or framework operation.
        $this->database->getSchemaBuilder()->create('json_records', function (Blueprint $table) { // Create the fixture table needed by these assertions.
            $table->integer('id'); // Define the fixture id column.
            $table->json('assignments'); // Define the fixture assignments column.
        });
        $this->database->table('json_records')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 1, 'assignments' => '{"brand_id":"10","details":{"amount":"12.75"},"active":true,"label":"null"}'], // Seed fixture record 1 with deliberately contrasting values.
            ['id' => 2, 'assignments' => '{"brand_id":"2","details":{"amount":"2.5"},"active":false,"label":null}'], // Seed fixture record 2 with deliberately contrasting values.
            ['id' => 3, 'assignments' => '{"brand_id":null,"active":null}'], // Seed fixture record 3 with deliberately contrasting values.
            ['id' => 4, 'assignments' => '{}'], // Seed fixture record 4 with deliberately contrasting values.
        ]);
    }

    /** Release database and framework state so fixtures cannot affect later tests. */
    protected function tearDown(): void // Release database and framework state so fixtures cannot affect later tests.
    {
        $this->database->disconnect(); // Release the fixture database connection.
    }

    /** Verify that integer and decimal values compare and sort numerically using an alias. */
    public function test_integer_and_decimal_values_compare_and_sort_numerically_using_an_alias(): void // Verify that integer and decimal values compare and sort numerically using an alias.
    {
        $brand = (new JsonColumn('assignments->brand_id', JsonType::Integer))->expression($this->database, 'p'); // Declare a typed JSON scalar used by the fixture.
        $amount = (new JsonColumn('assignments->details->amount', JsonType::Decimal))->expression($this->database, 'p'); // Declare a typed JSON scalar used by the fixture.

        self::assertSame([2, 1], $this->database->table('json_records as p')->whereNotNull($brand)->orderBy($brand)->pluck('id')->all()); // Check the exact fixture rows visible through this query.
        self::assertSame([1], $this->database->table('json_records as p')->where($amount, '>', 10)->pluck('id')->all()); // Check the exact fixture rows visible through this query.
        self::assertSame([3, 4], $this->database->table('json_records as p')->whereNull($brand)->orderBy('id')->pluck('id')->all()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that false missing null and the literal string null remain distinct. */
    public function test_false_missing_null_and_the_literal_string_null_remain_distinct(): void // Verify that false missing null and the literal string null remain distinct.
    {
        $active = (new JsonColumn('assignments->active', JsonType::Boolean))->expression($this->database, 'json_records'); // Declare a typed JSON scalar used by the fixture.
        $label = (new JsonColumn('assignments->label'))->expression($this->database, 'json_records'); // Declare a typed JSON scalar used by the fixture.

        self::assertSame([2], $this->database->table('json_records')->where($active, false)->pluck('id')->all()); // Check the exact fixture rows visible through this query.
        self::assertSame([1], $this->database->table('json_records')->where($active, true)->pluck('id')->all()); // Check the exact fixture rows visible through this query.
        self::assertSame([3, 4], $this->database->table('json_records')->whereNull($active)->orderBy('id')->pluck('id')->all()); // Check the exact fixture rows visible through this query.
        self::assertSame([1], $this->database->table('json_records')->where($label, 'null')->pluck('id')->all()); // Check the exact fixture rows visible through this query.
        self::assertSame([2, 3, 4], $this->database->table('json_records')->whereNull($label)->orderBy('id')->pluck('id')->all()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that postgres casts json foreign key without casting the indexed owner column. */
    public function test_postgres_casts_json_foreign_key_without_casting_the_indexed_owner_column(): void // Verify that postgres casts json foreign key without casting the indexed owner column.
    {
        $connection = new PostgresConnection(null, '', '', ['driver' => 'pgsql']); // Prepare connection for this regression scenario.
        $foreignKey = (new JsonColumn('assignments->brand_id', JsonType::Integer))->expression($connection, 'p'); // Declare a typed JSON scalar used by the fixture.
        $query = $connection->table('products as p')->leftJoin('brands as b', 'b.id', '=', $foreignKey); // Exercise the explicit join needed by this scenario.

        self::assertSame('select * from "products" as "p" left join "brands" as "b" on "b"."id" = (CAST("p"."assignments"->>\'brand_id\' AS BIGINT))', $query->toSql()); // Check the expected SQL or diagnostic text.
        self::assertSame([], $query->getBindings()); // Check parameter values and binding order after compilation.
        self::assertSame([], $connection->getQueryLog()); // Verify compilation performed no database reads or writes.
    }

    /** Verify that postgres uuid and nested text extraction use the current alias. */
    public function test_postgres_uuid_and_nested_text_extraction_use_the_current_alias(): void // Verify that postgres uuid and nested text extraction use the current alias.
    {
        $connection = new PostgresConnection(null, '', '', ['driver' => 'pgsql']); // Prepare connection for this regression scenario.
        $uuid = (new JsonColumn('assignments->brand_uuid', JsonType::Uuid))->expression($connection, 'qf_related'); // Declare a typed JSON scalar used by the fixture.
        $label = (new JsonColumn('assignments->details->label'))->expression($connection, 'qf_related'); // Declare a typed JSON scalar used by the fixture.

        self::assertSame('(CAST("qf_related"."assignments"->>\'brand_uuid\' AS UUID))', $uuid->getValue($connection->getQueryGrammar())); // Compare the expected value and PHP type for this scenario.
        self::assertSame('("qf_related"."assignments"->\'details\'->>\'label\')', $label->getValue($connection->getQueryGrammar())); // Compare the expected value and PHP type for this scenario.
    }

    /** Verify that mysql json null is checked by type instead of its text value. */
    #[DataProvider('mysqlDrivers')] // Run this test against its declared scenario provider.
    public function test_mysql_json_null_is_checked_by_type_instead_of_its_text_value(string $driver): void // Verify that mysql json null is checked by type instead of its text value.
    {
        $connection = $driver === 'mariadb' // Prepare connection for this regression scenario.
            ? new MariaDbConnection(null, '', '', ['driver' => $driver]) // Compile using MariaDB grammar without opening a database connection.
            : new MySqlConnection(null, '', '', ['driver' => $driver]); // Compile using MySQL grammar without opening a database connection.
        $label = (new JsonColumn('assignments->details->label'))->expression($connection, 'p'); // Declare a typed JSON scalar used by the fixture.
        $sql = $connection->table('products as p')->where($label, 'null')->toSql(); // Add the predicate needed by this fixture scenario.

        self::assertStringContainsString('JSON_TYPE(JSON_EXTRACT(`p`.`assignments`, \'$."details"."label"\')) = \'NULL\' THEN NULL', $sql); // Check the expected SQL or diagnostic text.
        $extraction = $connection->getQueryGrammar()->wrap('p.assignments->details->label'); // Prepare extraction for this regression scenario.
        self::assertStringContainsString("ELSE $extraction END) = ?", $sql); // Check the expected SQL or diagnostic text.
        self::assertStringNotContainsString('NULLIF', $sql); // Check the expected SQL or diagnostic text.

        $active = (new JsonColumn('assignments->active', JsonType::Boolean))->expression($connection, 'p'); // Declare a typed JSON scalar used by the fixture.
        $activeSql = $connection->table('products as p')->where($active, false)->toSql(); // Add the predicate needed by this fixture scenario.
        self::assertStringContainsString("WHEN 'true' THEN 1 WHEN 'false' THEN 0", $activeSql); // Check the expected SQL or diagnostic text.
        self::assertStringContainsString('END AS SIGNED)', $activeSql); // Check the expected SQL or diagnostic text.
    }

    /** Supply mysql drivers scenarios for the parameterized test. */
    public static function mysqlDrivers(): iterable // Supply mysql drivers scenarios for the parameterized test.
    {
        yield 'mysql' => ['mysql']; // Exercise mysql.
        yield 'mariadb' => ['mariadb']; // Exercise mariadb.
    }

    /** Verify that json paths accept only explicit identifier segments. */
    #[DataProvider('invalidPaths')] // Run this test against its declared scenario provider.
    public function test_json_paths_accept_only_explicit_identifier_segments(string $path): void // Verify that json paths accept only explicit identifier segments.
    {
        $this->expectException(InvalidArgumentException::class); // Require the documented exception type for this invalid case.
        new JsonColumn($path); // Declare a typed JSON scalar used by the fixture.
    }

    /** Supply invalid paths scenarios for the parameterized test. */
    public static function invalidPaths(): iterable // Supply invalid paths scenarios for the parameterized test.
    {
        yield ['assignments']; // Exercise this data-provider case.
        yield ['products.assignments->brand_id']; // Exercise this data-provider case.
        yield ['assignments->details.label']; // Exercise this data-provider case.
        yield ['assignments->']; // Exercise this data-provider case.
        yield ['assignments->0']; // Exercise this data-provider case.
        yield ['assignments->brands[0]']; // Exercise this data-provider case.
        yield ['assignments->brand_id DESC']; // Exercise this data-provider case.
        yield ["assignments->brand_id' OR 1=1 --"]; // Exercise this data-provider case.
        yield ["assignments->brand_id\n"]; // Exercise this data-provider case.
    }

    /** Verify that unsupported database drivers fail before query execution. */
    public function test_unsupported_database_drivers_fail_before_query_execution(): void // Verify that unsupported database drivers fail before query execution.
    {
        $connection = new Connection(null, '', '', ['driver' => 'sqlsrv']); // Prepare connection for this regression scenario.

        $this->expectException(InvalidArgumentException::class); // Require the documented exception type for this invalid case.
        $this->expectExceptionMessage('[sqlsrv]'); // Require an actionable error message for the rejected configuration.
        (new JsonColumn('assignments->brand_id'))->expression($connection, 'products'); // Declare a typed JSON scalar used by the fixture.
    }
}
