<?php

namespace Alif\QueryFilter\Support; // Keep predicate isolation internal to the compiler.

use Closure; // Accept trusted application callbacks.
use Illuminate\Database\Eloquent\Builder; // Preserve model-aware predicate methods.
use Illuminate\Database\Query\Builder as QueryBuilder; // Support the same checks for SQL builders.
use LogicException; // Report callbacks that violate the predicate-only contract.

/** Keep trusted access constraints and custom predicates inside independent AND groups. */
final class QueryConstraints
{
    /** Group the entire caller expression so raw SQL and custom predicates cannot bypass access rules. */
    public static function groupExisting(Builder|QueryBuilder $builder): void
    {
        $query = $builder instanceof Builder ? $builder->getQuery() : $builder; // Work with the underlying WHERE tree.
        if ($query->unions) { // A WHERE on one union branch cannot protect the remaining branches.
            throw new LogicException('Filter each UNION query separately before combining them.'); // Require access constraints on every branch.
        }
        if (! $query->wheres) { // An empty caller expression needs no nested builder.
            return; // Access and request predicates will provide their own groups.
        }

        $nested = $query->forNestedWhere(); // Reuse the caller's connection, grammar and FROM context.
        $nested->wheres = $query->wheres; // Move the entire existing boolean expression into one group.
        $nested->setBindings($query->getRawBindings()['where'], 'where'); // Preserve placeholder order.
        $query->wheres = []; // Replace the old outer expression with its isolated group.
        $query->setBindings([], 'where'); // Clear the bindings that addNestedWhereQuery will restore.
        $query->addNestedWhereQuery($nested); // AND the grouped caller expression with later restrictions.
    }

    /** Apply one callback in an AND group and reject every non-predicate query mutation. */
    public static function add(Builder|QueryBuilder $builder, Closure $callback): void
    {
        $base = $builder instanceof Builder ? $builder->getQuery() : $builder; // Capture the actual caller FROM context.
        /** Collect only this callback's predicates before Laravel attaches the group. */
        $builder->where(function (Builder|QueryBuilder $nested) use ($callback, $base): void { // Isolate callback-owned OR conditions.
            $query = $nested instanceof Builder ? $nested->getQuery() : $nested; // Inspect the nested SQL builder.
            $query->from = $base->from; // Preserve caller table aliases in Eloquent's fresh nested builder.
            $before = self::withoutPredicates($nested); // Record the state the callback must leave unchanged.
            $callback($nested); // Invoke trusted application code with the matching builder type.
            if ($before !== self::withoutPredicates($nested)) { // Compare protected scope and callback state too.
                throw new LogicException('Filter callbacks may change WHERE predicates only. Configure scopes, eager loads, joins and other clauses on the caller query.'); // Prevent silently discarded or unsafe changes.
            }
        });
    }

    /** Snapshot non-predicate query state, including protected Eloquent scope and callback properties. */
    private static function withoutPredicates(Builder|QueryBuilder $builder): array
    {
        $query = $builder instanceof Builder ? $builder->getQuery() : $builder; // Separate SQL state from Eloquent state.
        $state = (array) $query; // Include protected callbacks as well as public query clauses.
        unset($state['wheres'], $state['bindings']['where']); // Allow only predicates and their bound values to change.

        $eloquent = $builder instanceof Builder ? (array) $builder : []; // Also snapshot scopes, eager loads and model callbacks.
        foreach ($eloquent as $property => $value) { // Exclude the SQL object already represented by its own snapshot.
            if ($value === $query) { // Match the object without depending on Laravel's protected property name.
                unset($eloquent[$property]); // Avoid retaining a mutable query reference in the comparison.
            }
        }

        return [$state, $eloquent]; // Compare both layers after the trusted callback completes.
    }
}
