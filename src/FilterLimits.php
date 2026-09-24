<?php

namespace Alif\QueryFilter; // Shared request budgets for model-specific filters.

use InvalidArgumentException; // Reject invalid application configuration immediately.

/** Bound request complexity before adding conditions to a query. */
final readonly class FilterLimits
{
    /** Configure positive request budgets before parsing any client input. */
    public function __construct(
        public int $maxConditions = 100, // Bound total predicate, group, and search work.
        public int $maxDepth = 5, // Limit recursive boolean-group nesting.
        public int $maxValues = 100, // Bound each comparison list independently.
        public int $maxSorts = 5, // Limit sorting expressions requested by a client.
        public int $maxLimit = 1000, // Cap the explicit result limit accepted from input.
        public int $maxTermLength = 2000, // Bound string operands by byte length.
        public int $maxBindings = 500, // Bound the request's combined SQL parameter cost.
    ) {
        foreach (get_object_vars($this) as $name => $value) { // Apply the same invariant to every configured budget.
            if ($value < 1) { // Zero and negative values are configuration errors, not unlimited modes.
                throw new InvalidArgumentException("$name must be a positive integer."); // Identify the invalid option for the developer.
            }
        }
    }
}
