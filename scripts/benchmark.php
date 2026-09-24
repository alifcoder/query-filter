<?php

use Alif\QueryFilter\Abstracts\BaseEBFilter; // Import BaseEBFilter for this component.
use Alif\QueryFilter\Field; // Import Field for this component.
use Composer\InstalledVersions; // Import InstalledVersions for this component.
use Illuminate\Database\Capsule\Manager as Capsule; // Import Manager as Capsule for this component.
use Illuminate\Database\Eloquent\Builder; // Import Builder for this component.
use Illuminate\Database\Eloquent\Model; // Import Model for this component.
use Illuminate\Database\Eloquent\Relations\HasMany; // Import HasMany for this component.
use Illuminate\Database\Schema\Blueprint; // Import Blueprint for this component.

require dirname(__DIR__) . '/vendor/autoload.php'; // Load the package dependencies before running this command.

$iterations = filter_var($argv[1] ?? '1000', FILTER_VALIDATE_INT, [ // Validate the optional CLI iteration count before allocating fixtures.
    'options' => ['min_range' => 1, 'max_range' => 10000], // Keep local benchmark runs between one and ten thousand iterations.
]);
if ($iterations === false || count($argv) > 2) { // Reject invalid counts and unsupported extra arguments.
    fwrite(STDERR, "Usage: php scripts/benchmark.php [iterations: 1-10000; default: 1000]\n"); // Report the command result or measurement to the caller.
    exit(1); // Return a failing process status to the calling command.
}

/** Concrete model filter used by the benchmark scenarios. */
class BenchmarkParentFilter extends BaseEBFilter // Keep this concrete fixture local to the benchmark.
{
    /** Store the scenario's explicit fields outside the production constructor contract. */
    public function __construct(array $parameters, private array $scenarioFields) // Bind the scenario input and its explicit field map.
    {
        parent::__construct($parameters); // Pass explicit scenario input to the shared model-filter lifecycle.
    }

    /** Return only the columns required by the current benchmark scenario. */
    protected function fields(): array // Implement the model or filter contract described above.
    {
        return $this->scenarioFields; // Expose only this scenario's developer-selected fields.
    }
}

class BenchmarkParent extends Model // Keep this concrete fixture local to the benchmark.
{
    protected $table = 'benchmark_parents'; // Store the base table name.
    public $timestamps = false; // Avoid timestamp columns in the benchmark fixture schema.

    /** Expose the benchmark collection used to measure EXISTS filtering. */
    public function notes(): HasMany // Implement the model or filter contract described above.
    {
        return $this->hasMany(BenchmarkNote::class, 'parent_id'); // Use the parent foreign key for the related EXISTS scenario.
    }
}

class BenchmarkNote extends Model // Keep this concrete fixture local to the benchmark.
{
    protected $table = 'benchmark_notes'; // Store the base table name.
    public $timestamps = false; // Avoid timestamp columns in the benchmark fixture schema.
}

/** Return the middle sample after sorting the timing measurements numerically. */
function median(array $samples): float // Define the median contract.
{
    sort($samples, SORT_NUMERIC); // Sort timing samples numerically before selecting their median.

    return $samples[intdiv(count($samples), 2)]; // Select the middle sample to reduce the effect of timing outliers.
}

$database = new Capsule(); // Create a separate in-memory database for repeatable local measurements.
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]); // Use SQLite with fixture foreign-key checks enabled.
$database->setAsGlobal(); // Expose the fixture connection to the benchmark models.
$database->bootEloquent(); // Initialize Eloquent relationship handling for the fixture.
$connection = $database->getConnection(); // Use the same connection for fixture setup and query-count assertions.
$database->schema()->create('benchmark_parents', function (Blueprint $table) { // Define indexed parent fields used by equality and range filters.
    $table->integer('id')->primary(); // Assign stable primary keys to the deterministic fixture rows.
    $table->string('name'); // Store a display name on each benchmark parent.
    $table->string('status')->index(); // Index the column used by the simple equality scenario.
    $table->integer('score')->index(); // Index the range and ordering column.
});
$database->schema()->create('benchmark_notes', function (Blueprint $table) { // Define the collection used by related-field filtering.
    $table->integer('id')->primary(); // Assign stable primary keys to the deterministic fixture rows.
    $table->integer('parent_id'); // Connect each note to its parent record.
    $table->string('body'); // Store the text compared by the EXISTS scenario.
    $table->foreign('parent_id')->references('id')->on('benchmark_parents'); // Enforce valid parent links in the test data.
    $table->index(['parent_id', 'body']); // Support the correlated parent-and-body lookup.
});

// Deterministic data, inserted before any measurements. Batches stay below 999 bindings.
$connection->transaction(function () use ($database) { // Load fixture batches outside the measured sections in one transaction.
    for ($start = 1; $start <= 10000; $start += 200) { // Insert a bounded batch of two hundred parent records at a time.
        $parents = []; // Start the next parent insert batch.
        $notes = []; // Start the matching batch of related notes.
        for ($id = $start; $id < $start + 200; $id++) { // Generate reproducible values for each parent in the batch.
            $parents[] = ['id' => $id, 'name' => "Record $id", 'status' => $id % 2 ? 'active' : 'archived', 'score' => $id % 100]; // Alternate statuses and distribute scores across a fixed hundred-value range.
            $notes[] = ['id' => $id * 2 - 1, 'parent_id' => $id, 'body' => $id % 5 ? 'ordinary' : 'priority']; // Give every fifth parent a priority note for the EXISTS comparison.
            $notes[] = ['id' => $id * 2, 'parent_id' => $id, 'body' => 'follow-up']; // Add a second note so related filtering exercises collection semantics.
        }
        $database->table('benchmark_parents')->insert($parents); // Insert the completed parent batch.
        foreach (array_chunk($notes, 200) as $batch) { // Keep related-row insert batches below SQLite's binding budget.
            $database->table('benchmark_notes')->insert($batch); // Insert this bounded related-row batch.
        }
    }
});

$scenarios = [ // Pair representative request shapes with their explicit field definitions.
    'Simple filter' => [ // Measure the common equality-filter path.
        ['filter' => ['status' => 'active'], 'limit' => 100], // Match active parents and cap the hydrated result size.
        ['status'], // Expose only the status field in this scenario.
    ],
    'Nested tree + sort' => [ // Measure nested boolean parsing plus stable multi-column ordering.
        ['where' => ['and' => [ // Require the status comparison and one of the score ranges.
            ['field' => 'status', 'operator' => 'eq', 'value' => 'active'], // Keep only active parent records.
            ['or' => [ // Match either the high-score or low-score range.
                ['field' => 'score', 'operator' => 'gte', 'value' => 80], // Include the upper end of the fixture score range.
                ['field' => 'score', 'operator' => 'lte', 'value' => 10], // Include the lower end of the fixture score range.
            ]],
        ]], 'sort' => ['-score', 'id'], 'limit' => 100], // Order by score and primary key while keeping result size comparable.
        ['id', 'status', 'score'], // Expose the fields used by this request and its ordering.
    ],
    'Related EXISTS' => [ // Measure a to-many predicate without duplicating parent records.
        ['filter' => ['note' => 'priority'], 'limit' => 100], // Select parents with a priority note and cap result hydration.
        ['note' => Field::related('notes.body')], // Resolve the public note alias through a HasMany EXISTS predicate.
    ],
];

$sqlite = $connection->selectOne('select sqlite_version() as version')->version; // Record the database runtime used for these measurements.
$databaseVersion = InstalledVersions::getPrettyVersion('illuminate/database') // Prefer the standalone Illuminate package version when installed.
    ?? InstalledVersions::getPrettyVersion('laravel/framework'); // Fall back to the framework package that replaces Illuminate components.
printf("PHP %s | Illuminate database %s | SQLite %s | %s %s\n", PHP_VERSION, $databaseVersion, $sqlite, PHP_OS_FAMILY, php_uname('m')); // Report the command result or measurement to the caller.
printf("CLI OPcache: %s | JIT: %s | Xdebug: %s\n", ini_get('opcache.enable_cli') ?: 'off', ini_get('opcache.jit') ?: 'off', extension_loaded('xdebug') ? 'on' : 'off'); // Report the command result or measurement to the caller.
printf("Data: 10,000 parents, 20,000 notes. Compilation: median of 5 batches x %d iterations.\n", $iterations); // Report the command result or measurement to the caller.
echo "Execution: median of 25 warm get() calls, including model hydration, limited to 100 rows.\n\n"; // Report the command result or measurement to the caller.
printf("%-22s %15s %12s %13s %12s %8s\n", 'Scenario', 'Compile us/op', 'SQL queries', 'Execute ms', 'SQL queries', 'Rows'); // Report the command result or measurement to the caller.

$connection->enableQueryLog(); // Count executed statements independently from compilation timings.
foreach ($scenarios as $name => [$parameters, $fields]) { // Measure every scenario using the same fixture and sampling procedure.
    $makeQuery = static function () use ($parameters, $fields): Builder { // Build a new query and filter for each compilation sample.
        $query = BenchmarkParent::query(); // Start from a fresh model builder with no retained compiler state.
        (new BenchmarkParentFilter($parameters, $fields))->apply($query); // Apply the scenario through the same concrete-filter API used by applications.

        return $query; // Return the compiled builder without executing SQL.
    };

    for ($warmup = 0; $warmup < 25; $warmup++) { // Warm PHP and query-compilation paths before recording samples.
        $makeQuery()->toSql(); // Warm query compilation before collecting timing samples.
    }
    $connection->flushQueryLog(); // Exclude previously executed setup or warm-up statements from query counts.
    $compileSamples = []; // Collect independent compilation batches for median reporting.
    for ($batch = 0; $batch < 5; $batch++) { // Use five compilation batches to reduce short-lived timing noise.
        $started = hrtime(true); // Capture a monotonic nanosecond start time.
        for ($iteration = 0; $iteration < $iterations; $iteration++) { // Compile the configured number of fresh queries in this batch.
            $query = $makeQuery(); // Create an independent filter/query pair for this measurement.
            $query->toSql(); // Include SQL grammar compilation in the measured cost.
            $query->getBindings(); // Include binding flattening in the measured cost.
        }
        $compileSamples[] = (hrtime(true) - $started) / $iterations / 1000; // Normalize the elapsed batch time to microseconds per compilation.
    }
    $compileQueries = count($connection->getQueryLog()); // Check that filtering and SQL compilation executed no statements.
    if ($compileQueries !== 0) { // Treat unexpected database access during compilation as a benchmark failure.
        throw new RuntimeException('Filter compilation unexpectedly executed SQL.'); // Fail explicitly instead of producing an invalid or unrestricted query.
    }

    $query = $makeQuery(); // Create an independent filter/query pair for this measurement.
    (clone $query)->get(); // Warm execution without changing the measured query.
    $connection->flushQueryLog(); // Exclude previously executed setup or warm-up statements from query counts.
    $executeSamples = []; // Collect execution timings separately from compilation cost.
    for ($iteration = 0; $iteration < 25; $iteration++) { // Use twenty-five warm executions for the execution median.
        $started = hrtime(true); // Capture a monotonic nanosecond start time.
        $records = (clone $query)->get(); // Measure actual execution and model hydration on a clone.
        $executeSamples[] = (hrtime(true) - $started) / 1000000; // Convert the execution duration from nanoseconds to milliseconds.
    }
    printf("%-22s %15.2f %12d %13.3f %12d %8d\n", $name, median($compileSamples), $compileQueries, median($executeSamples), count($connection->getQueryLog()), $records->count()); // Report the command result or measurement to the caller.
}

printf("\nPeak PHP memory: %.1f MiB. Times describe this local in-memory fixture only.\n", memory_get_peak_usage(true) / 1048576); // Report the command result or measurement to the caller.
$connection->disconnect(); // Release the isolated benchmark database after reporting results.
