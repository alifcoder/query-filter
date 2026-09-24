<?php

namespace Alif\QueryFilter\Tests; // Keep test fixtures separate from production classes.

use Alif\QueryFilter\Traits\Filterable; // Enable model filter resolution for this fixture.
use Illuminate\Config\Repository; // Use Repository in this test fixture.
use Illuminate\Container\Container; // Use Container in this test fixture.
use Illuminate\Database\Capsule\Manager as Capsule; // Use Capsule in this test fixture.
use Illuminate\Database\Eloquent\Builder; // Use Builder in this test fixture.
use Illuminate\Database\Eloquent\Model; // Use Model in this test fixture.
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Use BelongsTo in this test fixture.
use Illuminate\Database\Eloquent\Relations\HasMany; // Use HasMany in this test fixture.
use Illuminate\Database\Eloquent\Relations\HasOne; // Use HasOne in this test fixture.
use Illuminate\Database\Eloquent\SoftDeletes; // Exercise soft-delete scopes on the fixture model.
use Illuminate\Database\Schema\Blueprint; // Use Blueprint in this test fixture.
use Illuminate\Support\Facades\Facade; // Use Facade in this test fixture.
use Illuminate\Translation\ArrayLoader; // Use ArrayLoader in this test fixture.
use Illuminate\Translation\Translator; // Use Translator in this test fixture.
use Illuminate\Validation\Factory; // Use Factory in this test fixture.
use Illuminate\Validation\ValidationException; // Use ValidationException in this test fixture.
use PHPUnit\Framework\Attributes\DataProvider; // Use DataProvider in this test fixture.
use PHPUnit\Framework\TestCase; // Use TestCase in this test fixture.

class ModelFilterTest extends TestCase // Group regression coverage for model filter.
{
    private const array FIELDS = [ // Retain fixture configuration for this test fixture.
        'id', 'name', 'code', 'enabled', 'score', // Supply the literal fixture argument used by this scenario.
        'created_by.id', 'created_by.name', // Supply the literal fixture argument used by this scenario.
        'created_by.address.region.id', 'created_by.address.region.name', // Supply the literal fixture argument used by this scenario.
        'updated_by.address.region.id', 'updated_by.address.region.name', // Supply the literal fixture argument used by this scenario.
        'profile.title', // Supply the literal fixture argument used by this scenario.
        'label' => 'name', // Define the label fixture value or field mapping.
        'creator_region' => 'createdBy.address.region.name', // Define the creator_region fixture value or field mapping.
        'named_created_by.name', // Supply the literal fixture argument used by this scenario.
        'notes.body', // Supply the literal fixture argument used by this scenario.
        'latest_revision.body', // Supply the literal fixture argument used by this scenario.
        'parent_brand.name', 'parent_brand.parent_brand.name', // Supply the literal fixture argument used by this scenario.
    ];

    private Capsule $database; // Retain database for this test fixture.

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

        $this->database = new Capsule($container); // Create the database manager for isolated fixture queries.
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']); // Configure the isolated database connection for this scenario.
        $this->database->setAsGlobal(); // Connect fixture models to the isolated database manager.
        $this->database->bootEloquent(); // Connect fixture models to the isolated database manager.
        $this->createSchema(); // Prepare the fixture operation needed by this regression.
        $this->seedRecords(); // Prepare the fixture operation needed by this regression.
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

    /** Verify that filters searches and sorts three levels without mixing creator and updater. */
    public function test_filters_searches_and_sorts_three_levels_without_mixing_creator_and_updater(): void // Verify that filters searches and sorts three levels without mixing creator and updater.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['created_by.address.region.id' => ['eq' => 401]], // Provide named filter operands for this request.
            'search' => ['updated_by.address.region.name' => 'Samar'], // Provide search terms using public field names.
            'sort' => ['-created_by.address.region.name', 'updated_by.address.region.name'], // Declare the request ordering for this scenario.
        ]);

        $this->assertSame([101], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        $this->assertCount(6, $query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
        $this->assertSame('Alpha', $query->first()->name); // Compare the expected value and PHP type for this scenario.
    }

    /** Verify that reuses shared path joins for filter search and sort. */
    public function test_reuses_shared_path_joins_for_filter_search_and_sort(): void // Verify that reuses shared path joins for filter search and sort.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['created_by.address.region.id' => ['eq' => 401]], // Provide named filter operands for this request.
            'search' => ['created_by.address.region.name' => 'Tosh'], // Provide search terms using public field names.
            'sort' => ['created_by.name', '-created_by.address.region.name'], // Declare the request ordering for this scenario.
        ]);

        $this->assertSame([101], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        $this->assertCount(3, $query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
    }

    /** Verify that or search retains a base match with a missing relation. */
    public function test_or_search_retains_a_base_match_with_a_missing_relation(): void // Verify that or search retains a base match with a missing relation.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'search' => ['created_by.address.region.name' => 'Samarqand', 'name' => 'Gamma'], // Provide search terms using public field names.
            'sort' => 'id', // Declare the request ordering for this scenario.
        ]);

        $this->assertSame([102, 103], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that or search is grouped under other filters. */
    public function test_or_search_is_grouped_under_other_filters(): void // Verify that or search is grouped under other filters.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['score' => ['gte' => 20]], // Provide named filter operands for this request.
            'search' => ['created_by.address.region.name' => 'Samarqand', 'name' => 'Gamma'], // Provide search terms using public field names.
        ]);

        $this->assertSame([103], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that search can require all fields and is case insensitive. */
    public function test_search_can_require_all_fields_and_is_case_insensitive(): void // Verify that search can require all fields and is case insensitive.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'search' => ['created_by.address.region.name' => 'toshkent', 'name' => 'ALPHA'], // Provide search terms using public field names.
            'search_type' => 'and', // Choose how the search predicates combine.
        ]);

        $this->assertSame([101], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that search boolean modes are case insensitive. */
    #[DataProvider('searchBooleanModes')] // Run this test against its declared scenario provider.
    public function test_search_boolean_modes_are_case_insensitive(string $type, array $expected): void // Verify that search boolean modes are case insensitive.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'search' => ['created_by.address.region.name' => 'Toshkent', 'name' => 'Gamma'], // Provide search terms using public field names.
            'search_type' => $type, // Choose how the search predicates combine.
            'sort' => 'id', // Declare the request ordering for this scenario.
        ]);

        $this->assertSame($expected, $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Supply search boolean modes scenarios for the parameterized test. */
    public static function searchBooleanModes(): iterable // Supply search boolean modes scenarios for the parameterized test.
    {
        yield 'and' => ['and', []]; // Exercise and.
        yield 'AND' => ['AND', []]; // Exercise AND.
        yield 'or' => ['or', [101, 103]]; // Exercise or.
        yield 'OR' => ['OR', [101, 103]]; // Exercise OR.
    }

    /** Verify that joined models honor global scopes and soft deletes. */
    public function test_joined_models_honor_global_scopes_and_soft_deletes(): void // Verify that joined models honor global scopes and soft deletes.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['created_by.address.region.name' => ['is_null' => false]], // Provide named filter operands for this request.
            'sort' => 'id', // Declare the request ordering for this scenario.
        ]);

        $this->assertSame([101, 102], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that missing and scoped out relations can be filtered as null. */
    public function test_missing_and_scoped_out_relations_can_be_filtered_as_null(): void // Verify that missing and scoped out relations can be filtered as null.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['created_by.id' => ['is_null' => '1']], // Provide named filter operands for this request.
            'sort' => 'id', // Declare the request ordering for this scenario.
        ]);

        $this->assertSame([103, 106, 107], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that relation specific constraints are preserved. */
    public function test_relation_specific_constraints_are_preserved(): void // Verify that relation specific constraints are preserved.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['named_created_by.name' => ['is_null' => false]], // Provide named filter operands for this request.
        ]);

        $this->assertSame([101], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that has one joins use custom foreign and local keys. */
    public function test_has_one_joins_use_custom_foreign_and_local_keys(): void // Verify that has one joins use custom foreign and local keys.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'search' => ['profile.title' => 'featured'], // Provide search terms using public field names.
            'sort' => ['-profile.title'], // Declare the request ordering for this scenario.
        ]);

        $this->assertSame([102], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        $this->assertCount(1, $query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
    }

    /** Verify that joined select preserves base model identity. */
    public function test_joined_select_preserves_base_model_identity(): void // Verify that joined select preserves base model identity.
    {
        $model = $this->query([ // Prepare model for this regression scenario.
            'filter' => ['id' => 101], // Provide named filter operands for this request.
            'sort' => '-created_by.address.region.name', // Declare the request ordering for this scenario.
        ])->firstOrFail(); // Read the surviving model to verify its original identity.

        $this->assertSame(101, $model->id); // Compare the expected value and PHP type for this scenario.
        $this->assertSame('Alpha', $model->name); // Compare the expected value and PHP type for this scenario.
        $this->assertArrayNotHasKey('address_id', $model->getAttributes()); // Check that only the expected validation or configuration keys exist.
    }

    /** Verify that preserves an explicit select. */
    public function test_preserves_an_explicit_select(): void // Verify that preserves an explicit select.
    {
        $query = Brand::query()->select('brands.name')->where('brands.id', 101); // Add the predicate needed by this fixture scenario.
        (new TestModelFilter(['sort' => 'created_by.address.region.name'], self::FIELDS))->apply($query); // Compile the filter into the caller-owned fixture query.

        $this->assertSame(['name' => 'Alpha'], $query->firstOrFail()->getAttributes()); // Compare the expected value and PHP type for this scenario.
    }

    /** Verify that supports public field aliases and explicit eloquent paths. */
    public function test_supports_public_field_aliases_and_explicit_eloquent_paths(): void // Verify that supports public field aliases and explicit eloquent paths.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['label' => 'Alpha'], // Provide named filter operands for this request.
            'search' => ['creator_region' => 'Toshkent'], // Provide search terms using public field names.
            'sort' => '-label', // Declare the request ordering for this scenario.
        ]);

        $this->assertSame([101], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that filter object can be reused with independent builders. */
    public function test_filter_object_can_be_reused_with_independent_builders(): void // Verify that filter object can be reused with independent builders.
    {
        $filter = new TestModelFilter([ // Use a concrete test filter with explicit fields.
            'search' => ['created_by.address.region.name' => 'Toshkent'], // Provide search terms using public field names.
        ], self::FIELDS); // Use the explicit fixture constant, enum, or framework operation.

        foreach ([Brand::query(), Brand::query()] as $query) { // Run the same assertions for each relevant fixture case.
            $filter->apply($query); // Compile the filter into the caller-owned fixture query.
            $this->assertSame([101], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
            $this->assertCount(3, $query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
        }
    }

    /** Verify that reapplying a filter to the same builder reuses its joins. */
    public function test_reapplying_a_filter_to_the_same_builder_reuses_its_joins(): void // Verify that reapplying a filter to the same builder reuses its joins.
    {
        $filter = new TestModelFilter([ // Use a concrete test filter with explicit fields.
            'filter' => ['created_by.address.region.id' => 401], // Provide named filter operands for this request.
        ], self::FIELDS); // Use the explicit fixture constant, enum, or framework operation.
        $query = Brand::query(); // Prepare query for this regression scenario.

        $filter->apply($query); // Compile the filter into the caller-owned fixture query.
        $filter->apply($query); // Compile the filter into the caller-owned fixture query.

        $this->assertSame([101], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        $this->assertCount(3, $query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
    }

    /** Verify that two filters on the same builder share overlapping joins. */
    public function test_two_filters_on_the_same_builder_share_overlapping_joins(): void // Verify that two filters on the same builder share overlapping joins.
    {
        $query = $this->query(['filter' => ['created_by.address.region.id' => 401]]); // Prepare query for this regression scenario.
        $query->filter(new TestModelFilter([ // Use a concrete test filter with explicit fields.
            'search' => ['updated_by.address.region.name' => 'Samarqand'], // Provide search terms using public field names.
            'sort' => ['created_by.address.region.name', 'updated_by.address.region.name'], // Declare the request ordering for this scenario.
        ], self::FIELDS)); // Use the explicit fixture constant, enum, or framework operation.

        $this->assertSame([101], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        $this->assertCount(6, $query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
    }

    /** Verify that latest of many has one filters only the selected related record. */
    public function test_latest_of_many_has_one_filters_only_the_selected_related_record(): void // Verify that latest of many has one filters only the selected related record.
    {
        $matchingLatest = $this->query([ // Prepare matching latest for this regression scenario.
            'filter' => ['latest_revision.body' => 'Current'], // Provide named filter operands for this request.
            'sort' => '-latest_revision.body', // Declare the request ordering for this scenario.
        ]);
        $matchingOlder = $this->query(['filter' => ['latest_revision.body' => 'Previous']]); // Prepare matching older for this regression scenario.

        $this->assertSame([101], $matchingLatest->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        $this->assertSame([], $matchingOlder->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        $this->assertSame([101, 102, 103, 104, 105, 106, 107, 108], $this->query([ // Compare the expected value and PHP type for this scenario.
            'sort' => 'id', // Declare the request ordering for this scenario.
            'filter' => ['latest_revision.body' => ['ne' => []]], // Provide named filter operands for this request.
        ])->get()->modelKeys()); // Compare the matching model identifiers with the expected fixture rows.
    }

    /** Verify that nested self relations keep each table path distinct. */
    public function test_nested_self_relations_keep_each_table_path_distinct(): void // Verify that nested self relations keep each table path distinct.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['parent_brand.name' => 'Beta'], // Provide named filter operands for this request.
            'search' => ['parent_brand.parent_brand.name' => 'Zero'], // Provide search terms using public field names.
            'sort' => ['parent_brand.name', 'parent_brand.parent_brand.name'], // Declare the request ordering for this scenario.
        ]);

        $this->assertSame([101], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        $this->assertCount(2, $query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
    }

    /** Verify that bracket query parameters preserve nested resource paths. */
    public function test_bracket_query_parameters_preserve_nested_resource_paths(): void // Verify that bracket query parameters preserve nested resource paths.
    {
        parse_str( // Parse bracket-encoded request input exactly as PHP does.
            'filter[created_by.address.region.id][eq]=401&search[created_by.address.region.name]=Tosh&sort[]=-created_by.address.region.name', // Supply the literal fixture argument used by this scenario.
            $parameters, // Prepare parameters for this regression scenario.
        );

        $this->assertSame('401', $parameters['filter']['created_by.address.region.id']['eq']); // Compare the expected value and PHP type for this scenario.
        $this->assertSame([101], $this->query($parameters)->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that nested queries compile for supported sql grammars. */
    #[DataProvider('sqlDrivers')] // Run this test against its declared scenario provider.
    public function test_nested_queries_compile_for_supported_sql_grammars(string $driver): void // Verify that nested queries compile for supported sql grammars.
    {
        $this->database->addConnection(['driver' => $driver, 'database' => 'query_filter'], $driver); // Configure the isolated database connection for this scenario.
        $query = Brand::on($driver)->filter(new TestModelFilter([ // Use a concrete test filter with explicit fields.
            'filter' => ['created_by.address.region.id' => 401], // Provide named filter operands for this request.
            'search' => ['id' => 101, 'updated_by.address.region.name' => 'Samarqand'], // Provide search terms using public field names.
            'sort' => '-created_by.address.region.name', // Declare the request ordering for this scenario.
        ], self::FIELDS)); // Use the explicit fixture constant, enum, or framework operation.

        $sql = $query->toSql(); // Snapshot generated SQL for later mutation assertions.
        $quote = $driver === 'pgsql' ? '"' : '`'; // Prepare quote for this regression scenario.
        $this->assertStringContainsString('select ' . $quote . 'brands' . $quote . '.*', $sql); // Check the expected SQL or diagnostic text.
        $this->assertStringContainsString('left join (select', $sql); // Check the expected SQL or diagnostic text.
        $this->assertStringContainsString($driver === 'pgsql' ? '::text ilike ?' : ' like ?', $sql); // Check the expected SQL or diagnostic text.
        $this->assertContains('%101%', $query->getBindings()); // Check parameter values and binding order after compilation.
        $this->assertContains('%Samarqand%', $query->getBindings()); // Check parameter values and binding order after compilation.
        $this->assertContains(401, $query->getBindings()); // Check parameter values and binding order after compilation.
    }

    /** Supply sql drivers scenarios for the parameterized test. */
    public static function sqlDrivers(): iterable // Supply sql drivers scenarios for the parameterized test.
    {
        yield 'PostgreSQL' => ['pgsql']; // Exercise PostgreSQL.
        yield 'MySQL' => ['mysql']; // Exercise MySQL.
    }

    /** Verify that zero and false are valid filter values. */
    public function test_zero_and_false_are_valid_filter_values(): void // Verify that zero and false are valid filter values.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['score' => ['eq' => 0], 'enabled' => false], // Provide named filter operands for this request.
            'sort' => 'id', // Declare the request ordering for this scenario.
        ]);

        $this->assertSame([101, 108], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that false is a valid search value and null entries are ignored. */
    public function test_false_is_a_valid_search_value_and_null_entries_are_ignored(): void // Verify that false is a valid search value and null entries are ignored.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'search' => ['enabled' => false, 'created_by.address.region.name' => null], // Provide search terms using public field names.
            'sort' => 'id', // Declare the request ordering for this scenario.
        ]);

        $this->assertSame([101, 103, 108], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        $this->assertEmpty($query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
    }

    /** Verify that numeric search values are accepted. */
    public function test_numeric_search_values_are_accepted(): void // Verify that numeric search values are accepted.
    {
        $this->assertSame([101], $this->query(['search' => ['id' => 101]])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that scalar null uses null comparison. */
    public function test_scalar_null_uses_null_comparison(): void // Verify that scalar null uses null comparison.
    {
        $query = $this->query(['filter' => ['created_by.id' => null], 'sort' => 'id']); // Prepare query for this regression scenario.

        $this->assertSame([103, 106, 107], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that eq and ne lists include explicit null semantics. */
    public function test_eq_and_ne_lists_include_explicit_null_semantics(): void // Verify that eq and ne lists include explicit null semantics.
    {
        $equal = $this->query([ // Prepare equal for this regression scenario.
            'filter' => ['created_by.id' => ['eq' => [201, null]]], // Provide named filter operands for this request.
            'sort' => 'id', // Declare the request ordering for this scenario.
        ]);
        $notEqual = $this->query([ // Prepare not equal for this regression scenario.
            'filter' => ['created_by.id' => ['ne' => [201, null]]], // Provide named filter operands for this request.
            'sort' => 'id', // Declare the request ordering for this scenario.
        ]);

        $this->assertSame([101, 103, 106, 107], $equal->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        $this->assertSame([102, 104, 105, 108], $notEqual->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that eq and ne support multiple values. */
    public function test_eq_and_ne_support_multiple_values(): void // Verify that eq and ne support multiple values.
    {
        $equal = $this->query(['filter' => ['score' => ['eq' => [0, 10]]], 'sort' => 'id']); // Prepare equal for this regression scenario.
        $notEqual = $this->query(['filter' => ['score' => ['ne' => [0, 10]]], 'sort' => 'id']); // Prepare not equal for this regression scenario.

        $this->assertSame([101, 102, 108], $equal->get()->modelKeys()); // Check the exact fixture rows visible through this query.
        $this->assertSame([103, 104, 105, 106, 107], $notEqual->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that empty eq and ne lists follow set semantics. */
    public function test_empty_eq_and_ne_lists_follow_set_semantics(): void // Verify that empty eq and ne lists follow set semantics.
    {
        $this->assertCount(0, $this->query(['filter' => ['id' => ['eq' => []]]])->get()); // Check the expected number of generated clauses or fixture results.
        $this->assertCount(8, $this->query(['filter' => ['id' => ['ne' => []]]])->get()); // Check the expected number of generated clauses or fixture results.
    }

    /** Verify that range operators are combined with and. */
    public function test_range_operators_are_combined_with_and(): void // Verify that range operators are combined with and.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['score' => ['gte' => 10, 'gt' => 0, 'lte' => 30, 'lt' => 40]], // Provide named filter operands for this request.
            'sort' => '-score,id', // Declare the request ordering for this scenario.
        ]);

        $this->assertSame([104, 103, 102], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that range operators preserve inclusive and exclusive boundaries. */
    #[DataProvider('rangeBoundaries')] // Run this test against its declared scenario provider.
    public function test_range_operators_preserve_inclusive_and_exclusive_boundaries(string $operator, array $expected): void // Verify that range operators preserve inclusive and exclusive boundaries.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['score' => [$operator => 20]], // Provide named filter operands for this request.
            'sort' => 'id', // Declare the request ordering for this scenario.
        ]);

        $this->assertSame($expected, $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Supply range boundaries scenarios for the parameterized test. */
    public static function rangeBoundaries(): iterable // Supply range boundaries scenarios for the parameterized test.
    {
        yield 'greater than' => ['gt', [104, 105, 106, 107]]; // Exercise greater than.
        yield 'greater than or equal' => ['gte', [103, 104, 105, 106, 107]]; // Exercise greater than or equal.
        yield 'less than' => ['lt', [101, 102, 108]]; // Exercise less than.
        yield 'less than or equal' => ['lte', [101, 102, 103, 108]]; // Exercise less than or equal.
    }

    /** Verify that sort array can mix related and base fields. */
    public function test_sort_array_can_mix_related_and_base_fields(): void // Verify that sort array can mix related and base fields.
    {
        $query = $this->query([ // Prepare query for this regression scenario.
            'filter' => ['id' => ['eq' => [101, 102]]], // Provide named filter operands for this request.
            'sort' => ['-created_by.address.region.name', 'id'], // Declare the request ordering for this scenario.
        ]);

        $this->assertSame([101, 102], $query->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that positive string limit is applied. */
    public function test_positive_string_limit_is_applied(): void // Verify that positive string limit is applied.
    {
        $this->assertSame([101, 102], $this->query(['limit' => '2', 'sort' => 'id'])->get()->modelKeys()); // Check the exact fixture rows visible through this query.
    }

    /** Verify that parameter values are bound instead of interpolated. */
    public function test_parameter_values_are_bound_instead_of_interpolated(): void // Verify that parameter values are bound instead of interpolated.
    {
        $value = "' OR 1 = 1 --"; // Prepare value for this regression scenario.
        $query = $this->query(['filter' => ['name' => $value]]); // Prepare query for this regression scenario.

        $this->assertStringNotContainsString($value, $query->toSql()); // Check the expected SQL or diagnostic text.
        $this->assertContains($value, $query->getBindings()); // Check parameter values and binding order after compilation.
        $this->assertCount(0, $query->get()); // Check the expected number of generated clauses or fixture results.
    }

    /** Verify that without params no joins or restrictions are added. */
    public function test_without_params_no_joins_or_restrictions_are_added(): void // Verify that without params no joins or restrictions are added.
    {
        $query = $this->query([]); // Prepare query for this regression scenario.

        $this->assertCount(8, $query->get()); // Check the expected number of generated clauses or fixture results.
        $this->assertEmpty($query->getQuery()->joins); // Check the expected number of generated clauses or fixture results.
    }

    /** Verify that invalid requests report validation errors. */
    #[DataProvider('invalidRequests')] // Run this test against its declared scenario provider.
    public function test_invalid_requests_report_validation_errors(array $params): void // Verify that invalid requests report validation errors.
    {
        try { // Exercise the failure path without hiding later state assertions.
            $this->query($params)->get(); // Prepare the fixture operation needed by this regression.
            $this->fail('Expected a validation error for invalid request parameters.'); // Fail explicitly if the expected rejection did not occur.
        } catch (ValidationException $exception) { // Inspect the expected failure and preserve caller-state assertions.
            $this->assertSame(422, $exception->status); // Compare the expected value and PHP type for this scenario.
            $this->assertNotEmpty($exception->errors()); // Check the fixture behavior required by this regression.
        }
    }

    /** Supply invalid requests scenarios for the parameterized test. */
    public static function invalidRequests(): iterable // Supply invalid requests scenarios for the parameterized test.
    {
        yield 'unknown filter field' => [['filter' => ['password' => 'secret']]]; // Exercise unknown filter field.
        yield 'unknown search field' => [['search' => ['password' => 'secret']]]; // Exercise unknown search field.
        yield 'unknown sort field' => [['sort' => 'password']]; // Exercise unknown sort field.
        yield 'injected field' => [['filter' => ['id) OR 1=1 --' => 1]]]; // Exercise injected field.
        yield 'injected sort' => [['sort' => 'name desc, (select 1)']]; // Exercise injected sort.
        yield 'unknown operator' => [['filter' => ['name' => ['raw' => '1=1']]]]; // Exercise unknown operator.
        yield 'integer operator key' => [['filter' => ['name' => [1 => 'Alpha']]]]; // Exercise integer operator key.
        yield 'filter scalar' => [['filter' => 'name']]; // Exercise filter scalar.
        yield 'filter list' => [['filter' => ['name']]]; // Exercise filter list.
        yield 'search scalar' => [['search' => 'Alpha']]; // Exercise search scalar.
        yield 'search list' => [['search' => ['Alpha']]]; // Exercise search list.
        yield 'search array value' => [['search' => ['name' => ['Alpha']]]]; // Exercise search array value.
        yield 'search type' => [['search' => ['name' => 'Alpha'], 'search_type' => 'xor']]; // Exercise search type.
        yield 'integer search type' => [['search' => ['name' => 'Alpha'], 'search_type' => 1]]; // Exercise integer search type.
        yield 'array search type' => [['search' => ['name' => 'Alpha'], 'search_type' => ['or']]]; // Exercise array search type.
        yield 'boolean search type' => [['search' => ['name' => 'Alpha'], 'search_type' => false]]; // Exercise boolean search type.
        yield 'sort integer' => [['sort' => 123]]; // Exercise sort integer.
        yield 'sort nested array' => [['sort' => [['name']]]]; // Exercise sort nested array.
        yield 'sort mapping' => [['sort' => ['name' => 'desc']]]; // Exercise sort mapping.
        yield 'range list' => [['filter' => ['score' => ['gt' => [10, 20]]]]]; // Exercise range list.
        yield 'range null' => [['filter' => ['score' => ['gt' => null]]]]; // Exercise range null.
        yield 'nested equality value' => [['filter' => ['name' => ['eq' => [['Alpha']]]]]]; // Exercise nested equality value.
        yield 'is null invalid string' => [['filter' => ['created_by.id' => ['is_null' => 'maybe']]]]; // Exercise is null invalid string.
        yield 'is null array' => [['filter' => ['created_by.id' => ['is_null' => [true]]]]]; // Exercise is null array.
        yield 'limit zero' => [['limit' => 0]]; // Exercise limit zero.
        yield 'limit negative' => [['limit' => -1]]; // Exercise limit negative.
        yield 'limit fractional' => [['limit' => 1.5]]; // Exercise limit fractional.
        yield 'limit invalid string' => [['limit' => '2 OR 1=1']]; // Exercise limit invalid string.
        yield 'to many filter' => [['filter' => ['notes.body' => 'hello']]]; // Exercise to many filter.
        yield 'to many search' => [['search' => ['notes.body' => 'hello']]]; // Exercise to many search.
        yield 'to many sort' => [['sort' => 'notes.body']]; // Exercise to many sort.
    }

    /** Build and filter an isolated fixture query for result assertions. */
    private function query(array $params): Builder // Build and filter an isolated fixture query for result assertions.
    {
        return Brand::query()->filter(new TestModelFilter($params, self::FIELDS)); // Build the fixture object requested by this helper.
    }

    /** Create the tables required by relation and filtering scenarios. */
    private function createSchema(): void // Create the tables required by relation and filtering scenarios.
    {
        $schema = $this->database->schema(); // Prepare schema for this regression scenario.
        $schema->create('regions', function (Blueprint $table) { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->string('name'); // Define the fixture name column.
            $table->boolean('active')->default(true); // Define the fixture active column.
            $table->softDeletes(); // Define the fixture table schema.
        });
        $schema->create('addresses', function (Blueprint $table) { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->integer('region_id')->nullable(); // Define the fixture region_id column.
        });
        $schema->create('users', function (Blueprint $table) { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->string('reference')->unique(); // Define the fixture reference column.
            $table->string('name'); // Define the fixture name column.
            $table->integer('address_id')->nullable(); // Define the fixture address_id column.
            $table->boolean('active')->default(true); // Define the fixture active column.
            $table->softDeletes(); // Define the fixture table schema.
        });
        $schema->create('brands', function (Blueprint $table) { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->string('code')->unique(); // Define the fixture code column.
            $table->string('name'); // Define the fixture name column.
            $table->string('creator_ref')->nullable(); // Define the fixture creator_ref column.
            $table->string('updater_ref')->nullable(); // Define the fixture updater_ref column.
            $table->string('parent_code')->nullable(); // Define the fixture parent_code column.
            $table->boolean('enabled'); // Define the fixture enabled column.
            $table->integer('score'); // Define the fixture score column.
        });
        $schema->create('profiles', function (Blueprint $table) { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->string('brand_code')->unique(); // Define the fixture brand_code column.
            $table->string('title'); // Define the fixture title column.
        });
        $schema->create('notes', function (Blueprint $table) { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->integer('brand_id'); // Define the fixture brand_id column.
            $table->string('body'); // Define the fixture body column.
        });
        $schema->create('revisions', function (Blueprint $table) { // Create the fixture table needed by these assertions.
            $table->integer('id')->primary(); // Define the fixture id column.
            $table->integer('brand_id'); // Define the fixture brand_id column.
            $table->string('body'); // Define the fixture body column.
        });
    }

    /** Populate contrasting fixture records for relation and scalar comparisons. */
    private function seedRecords(): void // Populate contrasting fixture records for relation and scalar comparisons.
    {
        $this->database->table('regions')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 401, 'name' => 'Toshkent', 'active' => true, 'deleted_at' => null], // Seed fixture record 401 with deliberately contrasting values.
            ['id' => 402, 'name' => 'Samarqand', 'active' => true, 'deleted_at' => null], // Seed fixture record 402 with deliberately contrasting values.
            ['id' => 403, 'name' => 'Nukus', 'active' => false, 'deleted_at' => null], // Seed fixture record 403 with deliberately contrasting values.
            ['id' => 404, 'name' => 'Andijon', 'active' => true, 'deleted_at' => '2026-01-01 00:00:00'], // Seed fixture record 404 with deliberately contrasting values.
        ]);
        $this->database->table('addresses')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 301, 'region_id' => 401], // Seed fixture record 301 with deliberately contrasting values.
            ['id' => 302, 'region_id' => 402], // Seed fixture record 302 with deliberately contrasting values.
            ['id' => 303, 'region_id' => 403], // Seed fixture record 303 with deliberately contrasting values.
            ['id' => 304, 'region_id' => 404], // Seed fixture record 304 with deliberately contrasting values.
        ]);
        $this->database->table('users')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 201, 'reference' => 'alice', 'name' => 'Alice', 'address_id' => 301, 'active' => true, 'deleted_at' => null], // Seed fixture record 201 with deliberately contrasting values.
            ['id' => 202, 'reference' => 'bob', 'name' => 'Bob', 'address_id' => 302, 'active' => true, 'deleted_at' => null], // Seed fixture record 202 with deliberately contrasting values.
            ['id' => 203, 'reference' => 'hidden-region', 'name' => 'Hidden region author', 'address_id' => 303, 'active' => true, 'deleted_at' => null], // Seed fixture record 203 with deliberately contrasting values.
            ['id' => 204, 'reference' => 'deleted-region', 'name' => 'Deleted region author', 'address_id' => 304, 'active' => true, 'deleted_at' => null], // Seed fixture record 204 with deliberately contrasting values.
            ['id' => 205, 'reference' => 'deleted-user', 'name' => 'Deleted author', 'address_id' => 301, 'active' => true, 'deleted_at' => '2026-01-01 00:00:00'], // Seed fixture record 205 with deliberately contrasting values.
            ['id' => 206, 'reference' => 'inactive-user', 'name' => 'Inactive author', 'address_id' => 302, 'active' => false, 'deleted_at' => null], // Seed fixture record 206 with deliberately contrasting values.
            ['id' => 207, 'reference' => 'zero', 'name' => 'Zero author', 'address_id' => null, 'active' => true, 'deleted_at' => null], // Seed fixture record 207 with deliberately contrasting values.
        ]);
        $this->database->table('brands')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 101, 'code' => 'A', 'name' => 'Alpha', 'creator_ref' => 'alice', 'updater_ref' => 'bob', 'enabled' => false, 'score' => 0], // Seed fixture record 101 with deliberately contrasting values.
            ['id' => 102, 'code' => 'B', 'name' => 'Beta', 'creator_ref' => 'bob', 'updater_ref' => 'alice', 'enabled' => true, 'score' => 10], // Seed fixture record 102 with deliberately contrasting values.
            ['id' => 103, 'code' => 'C', 'name' => 'Gamma', 'creator_ref' => null, 'updater_ref' => 'alice', 'enabled' => false, 'score' => 20], // Seed fixture record 103 with deliberately contrasting values.
            ['id' => 104, 'code' => 'D', 'name' => 'Hidden Region', 'creator_ref' => 'hidden-region', 'updater_ref' => null, 'enabled' => true, 'score' => 30], // Seed fixture record 104 with deliberately contrasting values.
            ['id' => 105, 'code' => 'E', 'name' => 'Deleted Region', 'creator_ref' => 'deleted-region', 'updater_ref' => null, 'enabled' => true, 'score' => 40], // Seed fixture record 105 with deliberately contrasting values.
            ['id' => 106, 'code' => 'F', 'name' => 'Deleted Author', 'creator_ref' => 'deleted-user', 'updater_ref' => null, 'enabled' => true, 'score' => 50], // Seed fixture record 106 with deliberately contrasting values.
            ['id' => 107, 'code' => 'G', 'name' => 'Disabled Author', 'creator_ref' => 'inactive-user', 'updater_ref' => null, 'enabled' => true, 'score' => 60], // Seed fixture record 107 with deliberately contrasting values.
            ['id' => 108, 'code' => 'H', 'name' => 'Zero', 'creator_ref' => 'zero', 'updater_ref' => null, 'enabled' => false, 'score' => 0], // Seed fixture record 108 with deliberately contrasting values.
        ]);
        $this->database->table('profiles')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 501, 'brand_code' => 'A', 'title' => 'Standard'], // Seed fixture record 501 with deliberately contrasting values.
            ['id' => 502, 'brand_code' => 'B', 'title' => 'Featured'], // Seed fixture record 502 with deliberately contrasting values.
        ]);
        $this->database->table('brands')->where('id', 101)->update(['parent_code' => 'B']); // Add the predicate needed by this fixture scenario.
        $this->database->table('brands')->where('id', 102)->update(['parent_code' => 'H']); // Add the predicate needed by this fixture scenario.
        $this->database->table('revisions')->insert([ // Load contrasting rows for the result-set assertions.
            ['id' => 601, 'brand_id' => 101, 'body' => 'Previous'], // Seed fixture record 601 with deliberately contrasting values.
            ['id' => 602, 'brand_id' => 101, 'body' => 'Current'], // Seed fixture record 602 with deliberately contrasting values.
            ['id' => 603, 'brand_id' => 102, 'body' => 'Beta revision'], // Seed fixture record 603 with deliberately contrasting values.
        ]);
    }
}

class Brand extends Model // Provide the brand fixture.
{
    use Filterable; // Enable model filter resolution for this fixture.

    protected $table = 'brands'; // Point the model at its isolated fixture table.
    public $timestamps = false; // Keep fixture queries independent of timestamp columns.

    /** Define the created by relation used by fixture queries. */
    public function createdBy(): BelongsTo // Define the created by relation used by fixture queries.
    {
        return $this->belongsTo(User::class, 'creator_ref', 'reference'); // Declare the owner and foreign keys exercised by relation queries.
    }

    /** Define the updated by relation used by fixture queries. */
    public function updatedBy(): BelongsTo // Define the updated by relation used by fixture queries.
    {
        return $this->belongsTo(User::class, 'updater_ref', 'reference'); // Declare the owner and foreign keys exercised by relation queries.
    }

    /** Define the named created by relation used by fixture queries. */
    public function namedCreatedBy(): BelongsTo // Define the named created by relation used by fixture queries.
    {
        return $this->createdBy()->where('users.name', 'Alice'); // Return the result required by the surrounding fixture.
    }

    /** Define the profile relation used by fixture queries. */
    public function profile(): HasOne // Define the profile relation used by fixture queries.
    {
        return $this->hasOne(Profile::class, 'brand_code', 'code'); // Expose the related fixture records to Eloquent.
    }

    /** Define the notes relation used by fixture queries. */
    public function notes(): HasMany // Define the notes relation used by fixture queries.
    {
        return $this->hasMany(Note::class, 'brand_id'); // Expose the related fixture records to Eloquent.
    }

    /** Define the latest revision relation used by fixture queries. */
    public function latestRevision(): HasOne // Define the latest revision relation used by fixture queries.
    {
        return $this->hasOne(Revision::class, 'brand_id')->latestOfMany(); // Expose the related fixture records to Eloquent.
    }

    /** Define the parent brand relation used by fixture queries. */
    public function parentBrand(): BelongsTo // Define the parent brand relation used by fixture queries.
    {
        return $this->belongsTo(self::class, 'parent_code', 'code'); // Declare the owner and foreign keys exercised by relation queries.
    }
}

class User extends Model // Provide the user fixture.
{
    use SoftDeletes; // Exercise soft-delete scopes on the fixture model.

    protected $table = 'users'; // Point the model at its isolated fixture table.
    public $timestamps = false; // Keep fixture queries independent of timestamp columns.

    /** Register fixture scopes that relation compilation must preserve. */
    protected static function booted(): void // Register fixture scopes that relation compilation must preserve.
    {
        static::addGlobalScope('active', fn (Builder $query) => $query->where('users.active', true)); // Add the predicate needed by this fixture scenario.
    }

    /** Define the address relation used by fixture queries. */
    public function address(): BelongsTo // Define the address relation used by fixture queries.
    {
        return $this->belongsTo(Address::class); // Declare the owner and foreign keys exercised by relation queries.
    }
}

class Address extends Model // Provide the address fixture.
{
    protected $table = 'addresses'; // Point the model at its isolated fixture table.
    public $timestamps = false; // Keep fixture queries independent of timestamp columns.

    /** Define the region relation used by fixture queries. */
    public function region(): BelongsTo // Define the region relation used by fixture queries.
    {
        return $this->belongsTo(Region::class); // Declare the owner and foreign keys exercised by relation queries.
    }
}

class Region extends Model // Provide the region fixture.
{
    use SoftDeletes; // Exercise soft-delete scopes on the fixture model.

    protected $table = 'regions'; // Point the model at its isolated fixture table.
    public $timestamps = false; // Keep fixture queries independent of timestamp columns.

    /** Register fixture scopes that relation compilation must preserve. */
    protected static function booted(): void // Register fixture scopes that relation compilation must preserve.
    {
        static::addGlobalScope('active', fn (Builder $query) => $query->where('regions.active', true)); // Add the predicate needed by this fixture scenario.
    }
}

class Profile extends Model // Provide the profile fixture.
{
    protected $table = 'profiles'; // Point the model at its isolated fixture table.
    public $timestamps = false; // Keep fixture queries independent of timestamp columns.
}

class Note extends Model // Provide the note fixture.
{
    protected $table = 'notes'; // Point the model at its isolated fixture table.
    public $timestamps = false; // Keep fixture queries independent of timestamp columns.
}

class Revision extends Model // Provide the revision fixture.
{
    protected $table = 'revisions'; // Point the model at its isolated fixture table.
    public $timestamps = false; // Keep fixture queries independent of timestamp columns.
}
