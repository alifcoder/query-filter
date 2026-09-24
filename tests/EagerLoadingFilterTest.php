<?php

namespace Alif\QueryFilter\Tests; // Keep eager-loading fixtures separate from application classes.

use Alif\QueryFilter\Abstracts\BaseEBFilter; // Exercise the public model filter contract.
use Alif\QueryFilter\Abstracts\BaseQBFilter; // Keep eager loading exclusive to Eloquent filters.
use Alif\QueryFilter\Traits\Filterable; // Apply filters through the normal model scope.
use Illuminate\Config\Repository; // Supply isolated framework configuration.
use Illuminate\Container\Container; // Isolate services between test cases.
use Illuminate\Database\Capsule\Manager as Capsule; // Run real Eloquent queries against SQLite.
use Illuminate\Database\Eloquent\Builder; // Type the model-specific access hook.
use Illuminate\Database\Eloquent\Model; // Define lightweight relational fixtures.
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Model the product and brand owner relations.
use Illuminate\Database\Eloquent\Relations\HasMany; // Exercise constrained collection eager loading.
use Illuminate\Database\Schema\Blueprint; // Build only the columns required by these tests.
use Illuminate\Support\Facades\Facade; // Reset global framework services after each test.
use Illuminate\Translation\ArrayLoader; // Supply validation messages without external files.
use Illuminate\Translation\Translator; // Build the isolated validation service.
use Illuminate\Validation\Factory; // Report invalid filter input using Laravel exceptions.
use Illuminate\Validation\ValidationException; // Check atomic failure before query compilation.
use PHPUnit\Framework\TestCase; // Group the eager-loading integration regressions.
use RuntimeException; // Simulate an application access-service failure.

class EagerLoadingFilterTest extends TestCase // Verify eager loading with actual hydrated models.
{
    private Capsule $database; // Retain the isolated database for query-count assertions.

    /** Create related rows and the Laravel services required by normal filter application. */
    protected function setUp(): void
    {
        parent::setUp(); // Initialize PHPUnit before creating framework fixtures.
        $container = new Container(); // Keep fixture service state independent from other tests.
        Container::setInstance($container); // Direct application helpers to this container.
        $container->instance('config', new Repository()); // Supply database manager configuration.
        $container->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $container)); // Enable normal input-validation errors.
        Facade::clearResolvedInstances(); // Remove services retained by previous tests.
        Facade::setFacadeApplication($container); // Bind Laravel facades to this fixture.
        $this->database = new Capsule($container); // Build the fixture database manager.
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']); // Keep every case isolated in memory.
        $this->database->setAsGlobal(); // Connect fixture models to this database.
        $this->database->bootEloquent(); // Enable model relationships and hydration.
        $this->database->schema()->create('eager_countries', function (Blueprint $table): void { // Store each brand's nested owner.
            $table->id(); // Provide the relationship owner key.
            $table->string('name'); // Distinguish hydrated country records.
        });
        $this->database->schema()->create('eager_brands', function (Blueprint $table): void { // Store the first-level product relation.
            $table->id(); // Provide the brand owner key.
            $table->string('name'); // Distinguish brands in filters and assertions.
            $table->unsignedBigInteger('country_id'); // Connect brands to their nested countries.
        });
        $this->database->schema()->create('eager_products', function (Blueprint $table): void { // Store products with conventional foreign keys.
            $table->id(); // Identify each returned product.
            $table->string('name'); // Allow ordinary request filtering.
            $table->unsignedBigInteger('brand_id'); // Exercise standard belongs-to eager loading.
        });
        $this->database->schema()->create('eager_reviews', function (Blueprint $table): void { // Store a collection relation with constrainable rows.
            $table->id(); // Identify each hydrated review.
            $table->unsignedBigInteger('product_id'); // Match reviews to their products.
            $table->boolean('approved'); // Distinguish retained and excluded review rows.
        });
        $this->database->table('eager_countries')->insert([['id' => 1, 'name' => 'Uzbekistan']]); // Share one nested owner between brands.
        $this->database->table('eager_brands')->insert([ // Seed contrasting brands for eager-load constraints.
            ['id' => 1, 'name' => 'Acme', 'country_id' => 1], // Supply the permitted brand in constrained cases.
            ['id' => 2, 'name' => 'Bravo', 'country_id' => 1], // Supply the excluded brand in constrained cases.
        ]);
        $this->database->table('eager_products')->insert([ // Give both products conventional foreign keys.
            ['id' => 1, 'name' => 'Alpha', 'brand_id' => 1], // Match the first product to Acme.
            ['id' => 2, 'name' => 'Beta', 'brand_id' => 2], // Match the second product to Bravo.
        ]);
        $this->database->table('eager_reviews')->insert([ // Include rows that distinguish collection constraints.
            ['id' => 1, 'product_id' => 1, 'approved' => true], // Retain this row for an approved-only eager load.
            ['id' => 2, 'product_id' => 1, 'approved' => false], // Exclude this row for an approved-only eager load.
            ['id' => 3, 'product_id' => 2, 'approved' => true], // Give the second product an approved review.
        ]);
        $this->database->getConnection()->enableQueryLog(); // Count only queries made after fixture creation.
    }

    /** Release the database and static model/container state after every fixture. */
    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect(); // Release the in-memory database.
        Model::clearBootedModels(); // Prevent fixture model boot state from reaching later cases.
        Model::unsetConnectionResolver(); // Release the database resolver retained by fixture models.
        Facade::clearResolvedInstances(); // Release facade instances owned by the test.
        Facade::setFacadeApplication(null); // Remove the fixture facade container.
        Container::setInstance(null); // Remove the fixture global container.
        parent::tearDown(); // Complete PHPUnit cleanup after releasing framework state.
    }

    /** Load static nested defaults in a fixed number of queries without SQL during compilation. */
    public function test_static_defaults_load_nested_relations_without_n_plus_one_queries(): void
    {
        $query = EagerProduct::filter(new EagerProductFilter()); // Compile default eager loads through the public scope.
        self::assertSame([], $this->database->getConnection()->getQueryLog()); // Compilation must not inspect the database.
        $products = $query->orderBy('id')->get(); // Fetch products and their two eager relation levels.
        self::assertCount(3, $this->database->getConnection()->getQueryLog()); // Use one query per relation level, regardless of product count.
        foreach ($products as $product) { // Verify both parent rows received fully loaded relations.
            self::assertTrue($product->relationLoaded('brand')); // Avoid silently falling back to lazy brand loading.
            self::assertTrue($product->brand->relationLoaded('country')); // Avoid silently falling back to lazy country loading.
            self::assertSame('Uzbekistan', $product->brand->country->name); // Read the hydrated nested relation.
        }
        self::assertCount(3, $this->database->getConnection()->getQueryLog()); // Reading all nested relations must execute no extra SQL.
    }

    /** Keep fluent overrides local to one instance and preserve independently declared class defaults. */
    public function test_setter_is_fluent_and_does_not_change_instance_or_class_defaults(): void
    {
        $filter = new EagerProductFilter(); // Start with the product filter's static defaults.
        self::assertSame($filter, $filter->setWith(['reviews'])); // Keep setter chaining on the same instance.
        self::assertSame(['reviews'], $filter->getWith()); // Return the declaration configured for this instance.
        self::assertSame(['brand.country'], (new EagerProductFilter())->getWith()); // Preserve fresh instances of the same class.
        self::assertSame(['reviews'], (new EagerReviewFilter())->getWith()); // Preserve a sibling class's independent static defaults.
        self::assertSame([], (new TestModelFilter())->getWith()); // Leave the shared base class empty by default.
        $copy = $filter->getWith(); // Receive the ordinary array value without a mutable reference.
        $copy[] = 'brand'; // Change only the caller's local array.
        self::assertSame(['reviews'], $filter->getWith()); // Keep the stored override independent of returned copies.
        $constrained = $filter->beforeUsing(fn (Builder $query) => $query->where('id', 1)); // Clone the instance while adding a trusted access restriction.
        self::assertSame(['reviews'], $constrained->getWith()); // Carry the instance override into the constrained clone.
        $constrained->setWith(['brand']); // Reconfigure only the constrained clone's relation declaration.
        self::assertSame(['reviews'], $filter->getWith()); // Preserve the original instance's override after clone configuration.
        self::assertSame(['brand'], $constrained->getWith()); // Keep the clone's new declaration independent.
        self::assertFalse(method_exists(BaseQBFilter::class, 'setWith')); // SQL-only filters must not advertise Eloquent eager loading.
        self::assertFalse(method_exists(BaseQBFilter::class, 'getWith')); // SQL-only filters must not advertise Eloquent eager-loading state.
    }

    /** Accept Laravel's column-selection, nested-array and closure relation declarations. */
    public function test_setter_supports_native_laravel_relation_declarations(): void
    {
        $filter = (new EagerProductFilter())->setWith([ // Replace the filter defaults with native Eloquent syntax.
            'brand:id,name,country_id' => ['country:id,name'], // Keep linking keys while selecting nested relation columns.
            'reviews' => fn ($query) => $query->where('approved', true), // Apply a trusted application constraint to the eager collection.
        ]);
        $products = EagerProduct::filter($filter)->orderBy('id')->get(); // Hydrate both configured relation families.
        self::assertSame(['id', 'name', 'country_id'], array_keys($products[0]->brand->getAttributes())); // Respect the explicit brand projection.
        self::assertSame('Uzbekistan', $products[0]->brand->country->name); // Respect nested array relation declarations.
        self::assertSame([1], $products[0]->reviews->modelKeys()); // Exclude the unapproved review using the configured closure.
        self::assertSame([3], $products[1]->reviews->modelKeys()); // Apply the same closure to every eagerly matched parent.
        self::assertCount(4, $this->database->getConnection()->getQueryLog()); // Execute one query for products and each declared relation.
    }

    /** Preserve existing caller constraints when static defaults also include the same relation. */
    public function test_caller_relation_constraints_take_precedence_and_nested_defaults_are_added(): void
    {
        $query = EagerProduct::query()->with(['brand' => fn ($query) => $query->where('id', 1)]); // Restrict the caller-owned eager relation.
        $existing = $query->getEagerLoads(); // Retain the exact closure that protects the caller's relation.
        (new EagerProductFilter())->apply($query); // Add a nested default that also introduces a brand entry.
        self::assertSame($existing['brand'], $query->getEagerLoads()['brand']); // Preserve the caller's exact relation constraint.
        self::assertArrayHasKey('brand.country', $query->getEagerLoads()); // Merge the additional nested relation.
        $products = $query->orderBy('id')->get(); // Execute the combined eager-loading declaration.
        self::assertSame('Uzbekistan', $products[0]->brand->country->name); // Load nested relations for the permitted brand.
        self::assertTrue($products[1]->relationLoaded('brand')); // Mark the excluded relation loaded rather than triggering lazy loading.
        self::assertNull($products[1]->brand); // Do not discard the caller's relation restriction.
    }

    /** Retain model defaults and caller relations when an empty setter disables only filter defaults. */
    public function test_empty_override_disables_only_filter_defaults(): void
    {
        $filter = (new EagerProductFilter())->setWith([]); // Explicitly disable this filter's static relation list.
        $query = EagerDefaultProduct::query()->with('brand'); // Combine a model default with a caller-owned eager relation.
        $existing = $query->getEagerLoads(); // Snapshot both preexisting relation declarations.
        $filter->apply($query); // Apply ordinary filtering without adding filter relations.
        self::assertSame([], $filter->getWith()); // Keep the explicit empty override distinct from unset state.
        self::assertSame($existing, $query->getEagerLoads()); // Preserve all caller and model eager-load closures.
        $products = $query->get(); // Hydrate the original eager-load set.
        self::assertTrue($products[0]->relationLoaded('reviews')); // Retain the model's collection default.
        self::assertTrue($products[0]->relationLoaded('brand')); // Retain the caller's belongs-to declaration.
        self::assertFalse($products[0]->brand->relationLoaded('country')); // Omit the disabled filter's nested default.
        $plain = EagerProduct::filter($filter)->first(); // Apply the same empty override to a model without defaults.
        self::assertFalse($plain->relationLoaded('brand')); // Avoid restoring a disabled default during filter reuse.
    }

    /** Preserve model-level eager-load declarations when a filter provides the same exact key. */
    public function test_model_default_relation_declarations_take_precedence(): void
    {
        $query = EagerDefaultProduct::query(); // Start with the model's unconstrained reviews default.
        $existing = $query->getEagerLoads(); // Snapshot the model-owned relation callback.
        $filter = (new EagerProductFilter())->setWith(['reviews' => fn ($query) => $query->where('approved', true)]); // Supply an overlapping filter relation.
        $filter->apply($query); // Merge filter declarations with the already configured model builder.
        self::assertSame($existing['reviews'], $query->getEagerLoads()['reviews']); // Preserve the earlier model declaration exactly.
        self::assertSame([1, 2], $query->findOrFail(1)->reviews->modelKeys()); // Keep the model default's complete review collection.
    }

    /** Leave SQL, bindings and eager-load callbacks unchanged when validation or access hooks fail. */
    public function test_failures_leave_the_original_query_and_eager_loads_unchanged(): void
    {
        foreach ([new EagerProductFilter(['filter' => ['unknown' => 1]]), new EagerFailingFilter()] as $filter) { // Cover failures before and after query staging.
            $query = EagerProduct::query()->where('id', '>', 0)->with('reviews'); // Create meaningful caller state to protect.
            $sql = $query->toSql(); // Retain the original query structure.
            $bindings = $query->getBindings(); // Retain the original bound values.
            $eagerLoads = $query->getEagerLoads(); // Retain the original relation callbacks.
            try { // Inspect the failure while still checking state afterward.
                $filter->apply($query); // Attempt to apply invalid input or a failing access hook.
                self::fail('The invalid filter must throw before committing query state.'); // Reject unexpected successful application.
            } catch (ValidationException|RuntimeException $exception) { // Accept only the intended validation or access-service failure.
                self::assertNotSame('', $exception->getMessage()); // Require an actionable application failure.
            }
            self::assertSame($sql, $query->toSql()); // Preserve the caller's SQL after failure.
            self::assertSame($bindings, $query->getBindings()); // Preserve the caller's bindings after failure.
            self::assertSame($eagerLoads, $query->getEagerLoads()); // Preserve the caller's eager-loading callbacks after failure.
        }
        self::assertSame([], $this->database->getConnection()->getQueryLog()); // Error handling must not execute a database query.
    }

    /** Keep already compiled builders independent when the same filter instance is reconfigured. */
    public function test_reusing_a_filter_does_not_share_eager_load_state_between_builders(): void
    {
        $filter = new EagerProductFilter(); // Start with the nested brand defaults.
        $first = EagerProduct::filter($filter); // Compile the first builder before changing the filter.
        $filter->setWith(['reviews']); // Reconfigure only subsequent filter applications.
        $second = EagerProduct::filter($filter); // Compile a second builder with the new override.
        self::assertSame(['brand', 'brand.country'], array_keys($first->getEagerLoads())); // Keep the first builder's committed declaration intact.
        self::assertSame(['reviews'], array_keys($second->getEagerLoads())); // Apply the new declaration only to the second builder.
        $first->with('reviews'); // Allow the caller to configure the first builder independently.
        self::assertSame(['reviews'], array_keys($second->getEagerLoads())); // Avoid cross-builder mutation through shared relation maps.
    }
}

class EagerProductFilter extends BaseEBFilter // Provide a model filter with static nested defaults.
{
    protected static array $with = ['brand.country']; // Eagerly load both declared relation levels by default.

    /** Expose the fixture columns accepted by ordinary request filtering. */
    protected function fields(): array
    {
        return ['id', 'name']; // Keep request-driven fields explicitly allowlisted.
    }
}

class EagerReviewFilter extends EagerProductFilter // Exercise independent static defaults in a derived filter.
{
    protected static array $with = ['reviews']; // Override class defaults without changing the product filter.
}

class EagerFailingFilter extends EagerProductFilter // Simulate access-policy failure after modifying a staged query.
{
    /** Fail after adding an access restriction so partial query state cannot be committed. */
    protected function before(Builder $builder): void
    {
        $builder->where('id', 1); // Modify only the isolated access query.
        throw new RuntimeException('The access service is unavailable.'); // Abort before publishing SQL or eager-load changes.
    }
}

class EagerProduct extends Model // Provide conventional product relations for eager-loading assertions.
{
    use Filterable; // Exercise the package's ordinary model scope.

    protected $table = 'eager_products'; // Point all fixture variants at the same product rows.

    /** Define the brand relation for conventional foreign-key fixtures. */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(EagerBrand::class, 'brand_id'); // Use the standard Eloquent belongs-to relation.
    }

    /** Define the collection relation used by constrained eager-loading tests. */
    public function reviews(): HasMany
    {
        return $this->hasMany(EagerReview::class, 'product_id'); // Match collection rows through the product primary key.
    }
}

class EagerDefaultProduct extends EagerProduct // Exercise eager loads declared by the model itself.
{
    protected $with = ['reviews']; // Preconfigure a model-owned eager-load entry before filter application.
}

class EagerBrand extends Model // Provide the first-level relation fixture.
{
    protected $table = 'eager_brands'; // Resolve brand queries against the fixture table.

    /** Define the nested owner loaded by dotted and nested-array declarations. */
    public function country(): BelongsTo
    {
        return $this->belongsTo(EagerCountry::class, 'country_id'); // Match brands to their ordinary country foreign key.
    }
}

class EagerCountry extends Model // Provide the terminal nested eager-load fixture.
{
    protected $table = 'eager_countries'; // Resolve country queries against the fixture table.
}

class EagerReview extends Model // Provide the has-many eager-load fixture.
{
    protected $table = 'eager_reviews'; // Resolve review queries against the fixture table.
}
