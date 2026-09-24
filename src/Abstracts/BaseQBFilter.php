<?php

namespace Alif\QueryFilter\Abstracts; // Application SQL filters extend this namespace's base.

use Alif\QueryFilter\Interfaces\QBFilterInterface; // Preserve the SQL builder's filter contract.
use Illuminate\Database\Query\Builder; // Use the SQL-only builder type in hooks.

/** Base for a specific SQL query; application code supplies joins and explicit column mappings. */
abstract class BaseQBFilter extends BaseFilter implements QBFilterInterface
{
    /** Apply this SQL filter through the same validation and access pipeline as model filters. */
    public function apply(Builder $builder): void
    {
        $this->applyTo($builder, fn (Builder $query) => $this->before($query)); // Keep builder-specific typing at the boundary.
    }

    /** Add trusted record-access predicates before request predicates, including for empty input. */
    protected function before(Builder $builder): void
    {
        // Override in the concrete SQL filter when the query requires an access constraint.
    }
}
