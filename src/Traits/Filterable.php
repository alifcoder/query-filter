<?php

namespace Alif\QueryFilter\Traits; // Model integration shared by application models.

use Alif\QueryFilter\Abstracts\BaseEBFilter; // Require the model-specific Eloquent base.
use Alif\QueryFilter\Interfaces\EBFilterInterface; // Also accept explicitly constructed integrations.
use Alif\QueryFilter\JsonColumn; // Describe virtual attributes stored in JSON.
use Illuminate\Database\Eloquent\Builder; // Preserve the caller's normal Eloquent builder.
use InvalidArgumentException; // Report invalid model configuration before container resolution.
use ReflectionClass; // Reject abstract filter classes without constructing them.

/** Connect a model to its concrete filter and optional JSON column mappings. */
trait Filterable
{
    /**
     * Map this model's virtual attributes to their explicit JSON storage locations.
     *
     * @return array<string, JsonColumn> Virtual model attributes mapped to JSON scalars.
     */
    public function queryFilterColumns(): array
    {
        return []; // Models opt into JSON mappings by overriding this method.
    }

    /**
     * Apply an existing filter or resolve a concrete model filter using query parameters.
     * Define a protected $filterClass on the model to call Model::filter() directly.
     *
     * @param EBFilterInterface|class-string<BaseEBFilter>|null $filter
     */
    public function scopeFilter(
        Builder $builder, // Laravel passes the current model query into its scope.
        EBFilterInterface|string|null $filter = null, // Use an instance, explicit class, or model default.
        ?array $parameters = null, // An explicit empty array must override the HTTP request.
    ): Builder {
        if (! $filter instanceof EBFilterInterface) { // Existing instances already own their construction.
            $class = $filter ?? $this->filterClass ?? null; // Read only application-owned class configuration.

            if (! is_string($class) || ! is_a($class, BaseEBFilter::class, true)) { // Reject unrelated classes before autowiring.
                throw new InvalidArgumentException( // Stop before the container can construct an unrelated class.
                    'Pass a filter instance or concrete BaseEBFilter subclass, or define one in the model $filterClass property.' // Explain the supported configuration.
                );
            }

            if ((new ReflectionClass($class))->isAbstract()) { // Abstract bases cannot define a usable model filter.
                throw new InvalidArgumentException("Filter [$class] is abstract; configure a concrete BaseEBFilter subclass that declares fields()."); // Name the invalid class.
            }

            $filter = app()->makeWith($class, [ // Let Laravel inject additional application dependencies.
                'parameters' => $parameters ?? request()->query(), // Read query parameters only when no array was supplied.
            ]);
        }

        $filter->apply($builder); // Add validated predicates without retrieving records.

        return $builder; // Keep pagination, eager loading, and retrieval chainable.
    }
}
