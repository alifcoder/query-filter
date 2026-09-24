<?php

namespace Alif\QueryFilter\Abstracts; // Keep the shared lifecycle separate from application model filters.

use Alif\QueryFilter\Field; // Describe the public fields allowed by each model filter.
use Alif\QueryFilter\FilterLimits; // Bound work requested by external input.
use Alif\QueryFilter\Support\FilterParser; // Validate input before changing a query.
use Alif\QueryFilter\Support\FilterQuery; // Compile the validated plan for either builder.
use Alif\QueryFilter\Support\QueryConstraints; // Isolate trusted access predicates.
use Closure; // Accept application-owned access callbacks.
use Illuminate\Auth\Access\AuthorizationException; // Use Laravel's normal forbidden-response exception.
use Illuminate\Database\Eloquent\Builder; // Recognize model-aware builders when committing.
use Illuminate\Database\Query\Builder as QueryBuilder; // Also support SQL-only builders.
use Illuminate\Support\Facades\Validator; // Apply application request rules.

/** Share authorization, validation and atomic compilation between model and SQL filters. */
abstract class BaseFilter
{
    public const string SEARCH = 'search'; // Name the public search parameter.
    public const string SEARCH_TYPE = 'search_type'; // Name the search boolean-mode parameter.
    public const string SORT = 'sort'; // Name the public ordering parameter.

    /** @var list<Closure> Additional trusted constraints, preserved when the filter is cloned. */
    private array $beforeCallbacks = []; // Keep access context on this filter instance only.
    private readonly FilterLimits $filterLimits; // Reuse immutable limits across applications.

    /** Store explicit input and allocate the default limits once per filter instance. */
    public function __construct(
        private readonly array $parameters = [], // Keep request input independent of HTTP globals.
        ?FilterLimits $limits = null, // Allow the caller to override the default request budget.
    ) {
        $this->filterLimits = $limits ?? new FilterLimits(); // Share no mutable request state.
    }

    /** Add an access constraint to a clone, leaving the original access context unchanged. */
    public function beforeUsing(Closure $callback): static
    {
        $filter = clone $this; // Isolate access context when a definition is reused.
        $filter->beforeCallbacks[] = $callback; // Preserve previously registered restrictions.

        return $filter; // The caller must retain this constrained instance.
    }

    /**
     * Declare the fields explicitly for the model or SQL query this filter serves.
     *
     * @return array<int|string, string|Field> Public names mapped to SQL-backed fields.
     */
    abstract protected function fields(): array;

    /** Decide whether the current application context may use this filter at all. */
    protected function authorize(): bool
    {
        return true; // Subclasses may impose an application-level permission check.
    }

    /** Normalize request input before either request-level or field-level validation. */
    protected function prepare(array $parameters): array
    {
        return $parameters; // Preserve false, zero and null unless a subclass changes them.
    }

    /** Supply Laravel rules for the request; Field::rules() validates individual operands. */
    protected function rules(): array
    {
        return []; // Field parsing remains mandatory even without additional request rules.
    }

    /** Supply a fallback order only when the request omits the sort parameter. */
    protected function defaultSort(): array
    {
        return []; // Leave caller ordering unchanged by default.
    }

    /** Return the immutable limits used to bound parsing and operand validation. */
    protected function limits(): FilterLimits
    {
        return $this->filterLimits; // Avoid rebuilding the default budget on each application.
    }

    /** Validate, constrain and compile on a clone, then publish only a successful query. */
    protected function applyTo(Builder|QueryBuilder $builder, Closure $before, array $with = []): void
    {
        if (! $this->authorize()) { // Authorize before parsing or invoking application hooks.
            throw new AuthorizationException('You are not allowed to use this filter.'); // Stop without mutating the caller.
        }
        $parameters = $this->prepare($this->parameters); // Normalize a local copy of the input.
        if (($rules = $this->rules()) !== []) { // Skip creating a validator when no extra rules exist.
            Validator::make($parameters, $rules)->validate(); // Propagate Laravel validation failures.
        }
        $parser = new FilterParser($this->fields(), $this->limits()); // Resolve developer-owned policies once.
        $plan = $parser->parse($parameters, $this->defaultSort()); // Validate the entire request before compilation.

        $staged = clone $builder; // Keep failures from changing the caller's query.
        QueryConstraints::groupExisting($staged); // Isolate caller OR clauses before adding access rules.
        QueryConstraints::add($staged, $before); // Run the subclass restriction even for empty input.
        foreach ($this->beforeCallbacks as $callback) { // Apply additional restrictions in registration order.
            QueryConstraints::add($staged, $callback); // Each callback gets its own AND group.
        }
        (new FilterQuery($parser->fields))->apply($staged, $plan); // Compile only the validated public fields.

        if ($builder instanceof Builder) { // Eloquent owns its underlying SQL builder.
            if ($with !== []) { // Parse relation defaults only when the model filter declares them.
                $existing = $staged->getEagerLoads(); // Preserve caller and model relation constraints.
                $staged->with($with); // Use Laravel's parser for nested relations and constrained loads.
                $staged->setEagerLoads($existing + $staged->getEagerLoads()); // Existing constraints win on matching relation names.
            }
            $builder->setQuery($staged->getQuery()); // Commit SQL after all compilation steps succeed.
            $builder->setEagerLoads($staged->getEagerLoads()); // Commit relation defaults while retaining model and scope state.
        } else { // SQL-only compilation can change just these four public query properties.
            foreach (['wheres', 'bindings', 'orders', 'limit'] as $property) { // Avoid copying unrelated builder state.
                $builder->{$property} = $staged->{$property}; // Publish the successful predicate/order/limit changes.
            }
        }
    }
}
