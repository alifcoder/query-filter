<?php

namespace Alif\QueryFilter\Tests; // Keep test fixtures separate from production classes.

use Alif\QueryFilter\Abstracts\BaseEBFilter; // Use BaseEBFilter in this test fixture.
use Alif\QueryFilter\Abstracts\BaseQBFilter; // Use BaseQBFilter in this test fixture.
use Alif\QueryFilter\Console\MakeQueryFilterCommand; // Use MakeQueryFilterCommand in this test fixture.
use Alif\QueryFilter\Interfaces\EBFilterInterface; // Use EBFilterInterface in this test fixture.
use Alif\QueryFilter\QueryFilterServiceProvider; // Use QueryFilterServiceProvider in this test fixture.
use Alif\QueryFilter\Traits\Filterable; // Enable model filter resolution for this fixture.
use Illuminate\Config\Repository; // Use Repository in this test fixture.
use Illuminate\Console\Application as ConsoleApplication; // Use ConsoleApplication in this test fixture.
use Illuminate\Container\Container; // Use Container in this test fixture.
use Illuminate\Database\Capsule\Manager as Capsule; // Use Capsule in this test fixture.
use Illuminate\Database\Eloquent\Builder; // Use Builder in this test fixture.
use Illuminate\Database\Eloquent\Model; // Use Model in this test fixture.
use Illuminate\Database\Schema\Blueprint; // Use Blueprint in this test fixture.
use Illuminate\Filesystem\Filesystem; // Use Filesystem in this test fixture.
use Illuminate\Foundation\Application; // Use Application in this test fixture.
use Illuminate\Http\Request; // Use Request in this test fixture.
use Illuminate\Support\Facades\Facade; // Use Facade in this test fixture.
use Illuminate\Translation\ArrayLoader; // Use ArrayLoader in this test fixture.
use Illuminate\Translation\Translator; // Use Translator in this test fixture.
use Illuminate\Validation\Factory; // Use Factory in this test fixture.
use InvalidArgumentException; // Use InvalidArgumentException in this test fixture.
use PHPUnit\Framework\TestCase; // Use TestCase in this test fixture.
use ReflectionClass; // Use ReflectionClass in this test fixture.
use Symfony\Component\Console\Tester\CommandTester; // Use CommandTester in this test fixture.

class FilterIntegrationTest extends TestCase // Group regression coverage for filter integration.
{
    private Container $container; // Retain container for this test fixture.
    private Capsule $database; // Retain database for this test fixture.
    private ?string $temporaryDirectory = null; // Retain temporary directory for this test fixture.

    /** Create isolated framework services and database fixtures for each test. */
    protected function setUp(): void // Create isolated framework services and database fixtures for each test.
    {
        parent::setUp(); // Initialize PHPUnit before creating isolated fixtures.

        $this->container = new Container(); // Create framework services isolated from other tests.
        Container::setInstance($this->container); // Point framework lookups at this test container.
        $this->container->instance('config', new Repository()); // Register the framework dependency required by this fixture.
        $this->container->instance('request', Request::create('/')); // Register the framework dependency required by this fixture.
        $this->container->instance('validator', new Factory( // Register the framework dependency required by this fixture.
            new Translator(new ArrayLoader(), 'en'), // Provide validation messages without loading external language resources.
            $this->container, // Prepare the fixture operation needed by this regression.
        ));
        Facade::clearResolvedInstances(); // Prevent facade instances from leaking between tests.
        Facade::setFacadeApplication($this->container); // Point framework lookups at this test container.

        $this->database = new Capsule($this->container); // Create the database manager for isolated fixture queries.
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']); // Configure the isolated database connection for this scenario.
        $this->database->setAsGlobal(); // Connect fixture models to the isolated database manager.
        $this->database->bootEloquent(); // Connect fixture models to the isolated database manager.
        $this->database->schema()->create('filter_integration_records', function (Blueprint $table): void { // Create the fixture table needed by these assertions.
            $table->id(); // Define the fixture table schema.
            $table->string('name'); // Define the fixture name column.
        });
        $this->database->table('filter_integration_records')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 1, 'name' => 'Red'], // Seed fixture record 1 with deliberately contrasting values.
            ['id' => 2, 'name' => 'Blue'], // Seed fixture record 2 with deliberately contrasting values.
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

        if ($this->temporaryDirectory !== null) { // Handle the fixture-specific condition before continuing.
            (new Filesystem())->deleteDirectory($this->temporaryDirectory); // Remove temporary files created by this test.
        }

        parent::tearDown(); // Complete PHPUnit cleanup after releasing framework state.
    }

    /** Verify that model default filter reads query parameters only. */
    public function test_model_default_filter_reads_query_parameters_only(): void // Verify that model default filter reads query parameters only.
    {
        $this->container->instance('request', Request::create( // Register the framework dependency required by this fixture.
            '/records?filter[name]=Red', // Supply the literal fixture argument used by this scenario.
            'POST', // Supply the literal fixture argument used by this scenario.
            ['filter' => ['name' => 'Blue']], // Provide comparison input that exercises the configured validation boundary.
        ));

        self::assertSame([1], IntegrationRecord::filter()->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that explicit filter class accepts parameters and returns a chainable builder. */
    public function test_explicit_filter_class_accepts_parameters_and_returns_a_chainable_builder(): void // Verify that explicit filter class accepts parameters and returns a chainable builder.
    {
        $query = IntegrationUnconfiguredRecord::query()->where('id', '>', 0); // Add the predicate needed by this fixture scenario.
        $filtered = $query->filter(IntegrationModelFilter::class, ['filter' => ['name' => 'Blue']]); // Prepare filtered for this regression scenario.

        self::assertSame($query, $filtered); // Compare the expected value and PHP type for this scenario.
        self::assertSame([2], $filtered->orderBy('id')->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that an explicit empty parameter array does not fall back to the request. */
    public function test_an_explicit_empty_parameter_array_does_not_fall_back_to_the_request(): void // Verify that an explicit empty parameter array does not fall back to the request.
    {
        $this->container->instance('request', Request::create('/records?filter[name]=Red')); // Register the framework dependency required by this fixture.

        self::assertSame([1, 2], IntegrationRecord::filter(null, [])->orderBy('id')->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that filter classes are resolved through the container. */
    public function test_filter_classes_are_resolved_through_the_container(): void // Verify that filter classes are resolved through the container.
    {
        $this->container->instance(IntegrationDependency::class, new IntegrationDependency('Red')); // Register the framework dependency required by this fixture.

        $query = IntegrationRecord::filter(IntegrationInjectedFilter::class, []); // Prepare query for this regression scenario.

        self::assertSame([1], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that custom filter interface instances are applied without container resolution. */
    public function test_custom_filter_interface_instances_are_applied_without_container_resolution(): void // Verify that custom filter interface instances are applied without container resolution.
    {
        $filter = new class implements EBFilterInterface { // Prepare filter for this regression scenario.
            public int $applications = 0; // Retain applications for this test fixture.

            /** Apply the fixture filter and record its invocation for integration assertions. */
            public function apply(Builder $builder): void // Apply the fixture filter and record its invocation for integration assertions.
            {
                $this->applications++; // Prepare the fixture operation needed by this regression.
                $builder->where('name', 'Blue'); // Add the predicate needed by this fixture scenario.
            }
        };

        self::assertSame([2], IntegrationRecord::filter($filter)->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        self::assertSame(1, $filter->applications); // Compare the expected value and PHP type for this scenario.
    }

    /** Verify that missing model default has an actionable error. */
    public function test_missing_model_default_has_an_actionable_error(): void // Verify that missing model default has an actionable error.
    {
        $this->expectException(InvalidArgumentException::class); // Require the documented exception type for this invalid case.
        $this->expectExceptionMessage('$filterClass'); // Require an actionable error message for the rejected configuration.

        IntegrationUnconfiguredRecord::filter(); // Use the explicit fixture constant, enum, or framework operation.
    }

    /** Verify that arbitrary class names cannot be constructed as filters. */
    public function test_arbitrary_class_names_cannot_be_constructed_as_filters(): void // Verify that arbitrary class names cannot be constructed as filters.
    {
        $this->expectException(InvalidArgumentException::class); // Require the documented exception type for this invalid case.
        $this->expectExceptionMessage('BaseEBFilter'); // Require an actionable error message for the rejected configuration.

        IntegrationRecord::filter(\stdClass::class, []); // Use the explicit fixture constant, enum, or framework operation.
    }

    /** Verify that filter bases require concrete model or query field definitions. */
    public function test_filter_bases_require_concrete_model_or_query_field_definitions(): void // Verify that filter bases require concrete model or query field definitions.
    {
        foreach ([BaseEBFilter::class, BaseQBFilter::class] as $class) { // Run the same assertions for each relevant fixture case.
            $reflection = new ReflectionClass($class); // Inspect the public inheritance and constructor contract.

            self::assertTrue($reflection->isAbstract()); // Enforce the abstract field-definition contract.
            self::assertTrue($reflection->getMethod('fields')->isAbstract()); // Enforce the abstract field-definition contract.
            self::assertTrue($reflection->getMethod('fields')->isProtected()); // Enforce the abstract field-definition contract.
            self::assertSame(['parameters', 'limits'], array_map( // Compare the expected value and PHP type for this scenario.
                fn ($parameter) => $parameter->getName(), // Map this fixture value without adding mutable test state.
                $reflection->getConstructor()->getParameters(), // Prepare the fixture operation needed by this regression.
            ));
        }
    }

    /** Verify that abstract filter classes report a configuration error before container resolution. */
    public function test_abstract_filter_classes_report_a_configuration_error_before_container_resolution(): void // Verify that abstract filter classes report a configuration error before container resolution.
    {
        foreach ([BaseEBFilter::class, IntegrationAbstractFilter::class] as $class) { // Run the same assertions for each relevant fixture case.
            try { // Exercise the failure path without hiding later state assertions.
                IntegrationRecord::filter($class, []); // Use the explicit fixture constant, enum, or framework operation.
                self::fail('A model must name a concrete filter class.'); // Fail explicitly if the expected rejection did not occur.
            } catch (InvalidArgumentException $exception) { // Inspect the expected failure and preserve caller-state assertions.
                self::assertStringContainsString('concrete', $exception->getMessage()); // Check the expected SQL or diagnostic text.
            }
        }
    }

    /** Verify that query builder filter classes cannot be used as model filters. */
    public function test_query_builder_filter_classes_cannot_be_used_as_model_filters(): void // Verify that query builder filter classes cannot be used as model filters.
    {
        $this->expectException(InvalidArgumentException::class); // Require the documented exception type for this invalid case.
        $this->expectExceptionMessage('BaseEBFilter'); // Require an actionable error message for the rejected configuration.

        IntegrationRecord::filter(TestQueryFilter::class, []); // Use the explicit fixture constant, enum, or framework operation.
    }

    /** Verify that request parameters cannot choose the filter class. */
    public function test_request_parameters_cannot_choose_the_filter_class(): void // Verify that request parameters cannot choose the filter class.
    {
        $this->container->instance('request', Request::create('/records', 'GET', [ // Register the framework dependency required by this fixture.
            'filterClass' => \stdClass::class, // Define the filterClass fixture value or field mapping.
            'filter' => ['name' => 'Red'], // Provide named filter operands for this request.
        ]));

        self::assertSame([1], IntegrationRecord::filter()->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that generator creates a namespaced filter with explicit operators. */
    public function test_generator_creates_a_namespaced_filter_with_explicit_operators(): void // Verify that generator creates a namespaced filter with explicit operators.
    {
        $tester = $this->generator(); // Prepare tester for this regression scenario.

        self::assertSame(0, $tester->execute(['name' => 'Admin/MemberFilter'])); // Compare the expected value and PHP type for this scenario.

        $path = $this->temporaryDirectory . '/app/Filters/Admin/MemberFilter.php'; // Prepare path for this regression scenario.
        self::assertFileExists($path); // Check the fixture behavior required by this regression.
        $source = file_get_contents($path); // Inspect the generated fixture source.
        self::assertStringContainsString('namespace App\\Filters\\Admin;', $source); // Check the expected SQL or diagnostic text.
        self::assertStringContainsString('class MemberFilter extends BaseEBFilter', $source); // Check the expected SQL or diagnostic text.
        self::assertStringContainsString('use Alif\\QueryFilter\\Enums\\FilterOperator;', $source); // Check the expected SQL or diagnostic text.
        self::assertStringContainsString( // Check the expected SQL or diagnostic text.
            "'id' => Field::make('id')->operators([FilterOperator::Equal, FilterOperator::In])", // Declare the SQL-backed field and its permitted operations.
            $source, // Prepare source for this regression scenario.
        );
        self::assertNotEmpty(token_get_all($source, TOKEN_PARSE)); // Check the fixture behavior required by this regression.

        require $path; // Load Composer dependencies before executing tests.
        $filter = new \App\Filters\Admin\MemberFilter(['filter' => ['id' => ['in' => [2]]]]); // Prepare filter for this regression scenario.
        self::assertSame([], $filter->getWith()); // A generated filter leaves relation loading opt-in.
        self::assertSame([2], IntegrationRecord::filter($filter)->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that generator preserves an existing filter. */
    public function test_generator_preserves_an_existing_filter(): void // Verify that generator preserves an existing filter.
    {
        $tester = $this->generator(); // Prepare tester for this regression scenario.
        $tester->execute(['name' => 'MemberFilter']); // Run the generator with the requested fixture arguments.
        $path = $this->temporaryDirectory . '/app/Filters/MemberFilter.php'; // Prepare path for this regression scenario.
        $source = file_get_contents($path) . "\n// Application customization.\n"; // Inspect the generated fixture source.
        file_put_contents($path, $source); // Write the temporary application or configuration fixture.

        $tester->execute(['name' => 'MemberFilter']); // Run the generator with the requested fixture arguments.

        self::assertSame($source, file_get_contents($path)); // Compare the expected value and PHP type for this scenario.
        self::assertStringContainsString('already exists', $tester->getDisplay()); // Check the expected SQL or diagnostic text.
    }

    /** Verify that the provider registers the generator while preserving application configuration. */
    public function test_provider_registers_the_generator_and_preserves_application_configuration(): void // Verify that the provider registers the generator while preserving application configuration.
    {
        $this->generator(); // Prepare the fixture operation needed by this regression.
        $app = Container::getInstance(); // Prepare app for this regression scenario.
        $app['config']->set('app.name', 'Existing application'); // Represent configuration already owned by the consuming application.
        $configuration = $app['config']->all(); // Snapshot caller configuration before the provider registers its generator.

        $app->register(QueryFilterServiceProvider::class); // Use the explicit fixture constant, enum, or framework operation.
        $app->boot(); // Prepare the fixture operation needed by this regression.
        $console = new ConsoleApplication($app, $app['events'], 'test'); // Prepare console for this regression scenario.

        self::assertInstanceOf(MakeQueryFilterCommand::class, $console->find('query-filter:make')); // Check the fixture behavior required by this regression.
        self::assertSame($configuration, $app['config']->all()); // Registering the generator must preserve all existing application configuration.
    }

    /** Create a temporary Laravel application and attach the generator command. */
    private function generator(): CommandTester // Create a temporary Laravel application and attach the generator command.
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/query-filter-generator-' . bin2hex(random_bytes(8)); // Prepare the fixture operation needed by this regression.
        $files = new Filesystem(); // Prepare files for this regression scenario.
        $files->makeDirectory($this->temporaryDirectory . '/app', 0777, true); // Prepare the fixture operation needed by this regression.
        $files->put($this->temporaryDirectory . '/composer.json', json_encode([ // Write the temporary application or configuration fixture.
            'autoload' => ['psr-4' => ['App\\' => 'app/']], // Configure autoload metadata for the temporary application.
        ], JSON_THROW_ON_ERROR)); // Fail explicitly if fixture JSON cannot be encoded.

        $app = new Application($this->temporaryDirectory); // Prepare app for this regression scenario.
        $app->instance('config', new Repository()); // Register the framework dependency required by this fixture.
        $app->instance('files', $files); // Register the framework dependency required by this fixture.
        $command = new MakeQueryFilterCommand($files); // Prepare command for this regression scenario.
        $command->setLaravel($app); // Prepare the fixture operation needed by this regression.

        return new CommandTester($command); // Build the fixture object requested by this helper.
    }
}

class IntegrationRecord extends Model // Provide the integration record fixture.
{
    use Filterable; // Enable model filter resolution for this fixture.

    protected $table = 'filter_integration_records'; // Point the model at its isolated fixture table.
    protected $filterClass = IntegrationModelFilter::class; // Bind this model to its concrete field-definition class.
}

class IntegrationUnconfiguredRecord extends Model // Provide the integration unconfigured record fixture.
{
    use Filterable; // Enable model filter resolution for this fixture.

    protected $table = 'filter_integration_records'; // Point the model at its isolated fixture table.
}

class IntegrationModelFilter extends BaseEBFilter // Provide the integration model filter fixture.
{
    /** Declare the public fields available to this fixture filter. */
    protected function fields(): array // Declare the public fields available to this fixture filter.
    {
        return ['id', 'name']; // Return the fixture definition used by the surrounding test.
    }
}

abstract class IntegrationAbstractFilter extends BaseEBFilter // Represent a deliberately incomplete filter configuration.
{
}

class IntegrationDependency // Provide the integration dependency fixture.
{
    /** Supply fixture dependencies while preserving the production filter contract. */
    public function __construct(public string $name) // Supply fixture dependencies while preserving the production filter contract.
    {
    }
}

class IntegrationInjectedFilter extends IntegrationModelFilter // Provide the integration injected filter fixture.
{
    /** Supply fixture dependencies while preserving the production filter contract. */
    public function __construct(array $parameters, IntegrationDependency $dependency) // Supply fixture dependencies while preserving the production filter contract.
    {
        parent::__construct($parameters + ['filter' => ['name' => $dependency->name]]); // Initialize request parameters and limits through the production base.
    }
}
