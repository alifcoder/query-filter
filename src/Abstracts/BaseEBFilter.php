<?php

namespace Alif\QueryFilter\Abstracts; // Application model filters extend this namespace's base.

use Alif\QueryFilter\Interfaces\EBFilterInterface; // Preserve the model scope's filter contract.
use Illuminate\Database\Eloquent\Builder; // Keep hook arguments model-aware.

/** Base for one model's explicit fields, relations, JSON mappings and access policy. */
abstract class BaseEBFilter extends BaseFilter implements EBFilterInterface
{
    /** @var array Relations to eager load by default, using Laravel's with() array syntax. */
    protected static array $with = []; // Let each model filter declare its relation defaults.
    private ?array $withOverride = null; // Keep request-specific overrides off shared static state.

    /** Replace this instance's relation defaults; an empty array disables only those defaults. */
    public function setWith(array $relations): static
    {
        $this->withOverride = $relations; // Preserve defaults used by other instances and subclasses.

        return $this; // Allow eager-loading configuration before applying this filter.
    }

    /** Return this instance's override, or the defaults declared by its concrete filter class. */
    public function getWith(): array
    {
        return $this->withOverride ?? static::$with; // Retain an explicitly empty override.
    }

    /** Apply model predicates and eager-load defaults without executing the query. */
    public function apply(Builder $builder): void
    {
        $this->applyTo($builder, fn (Builder $query) => $this->before($query), $this->getWith()); // Stage predicates and relations together.
    }

    /** Add trusted record-access predicates before request predicates, including for empty input. */
    protected function before(Builder $builder): void
    {
        // Override in the model filter when records require a server-owned access constraint.
    }
}
