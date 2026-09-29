<?php

namespace Alif\QueryFilter\Support; // Internal request validation and normalization.

use Alif\QueryFilter\Enums\FilterOperator; // Resolves public operators and validates their operands.
use Alif\QueryFilter\Enums\SearchTypeEnum; // Restricts search grouping to AND or OR.
use Alif\QueryFilter\Field; // Describes application-approved columns and capabilities.
use Alif\QueryFilter\FilterLimits; // Bounds request depth, size, and SQL bindings.
use Illuminate\Auth\Access\AuthorizationException; // Separates denied field access from invalid input.
use Illuminate\Support\Facades\Validator; // Applies optional Laravel rules to individual operands.
use Illuminate\Validation\ValidationException; // Returns errors keyed by the public request parameter.
use InvalidArgumentException; // Reports malformed developer-owned field definitions.

/** Validates the entire request before the query compiler sees it. */
final class FilterParser
{
    /** @var array<string, Field> Validated definitions for this parser instance only. */
    public readonly array $fields; // Expose normalized definitions to the query compiler.
    private array $authorized = []; // Evaluate each requested field's authorization once per request.
    private int $conditions = 0; // Count filter nodes and nonempty searches across the whole request.
    private int $bindings = 0; // Track the combined operand budget before compiling SQL.

    /** Validate and isolate developer-owned definitions before inspecting client input. */
    public function __construct(array $definitions, private FilterLimits $limits)
    {
        $fields = []; // Build a public-name lookup containing only valid Field objects.
        foreach ($definitions as $name => $definition) { // Accept concise string paths and explicit field definitions.
            $field = is_string($definition) ? Field::make($definition) : $definition; // Expand shorthand paths into their default capabilities.
            if (! $field instanceof Field) { // Reject arbitrary configuration values before parsing requests.
                throw new InvalidArgumentException('Fields must be column paths or Field definitions.'); // State the accepted definition types.
            }
            $field = clone $field; // Keep mutable field definitions isolated from application reuse.
            $name = is_int($name) ? $field->path : $name; // Unkeyed entries expose their path as the public field name.
            $this->assertPath($name); // Public names use the same safe identifier syntax as column paths.
            if ($field->callback === null) { // Custom fields have no SQL path to validate.
                $this->assertPath($field->path); // Validate every declared physical or relation-backed path.
            }
            if ($field->usesExists && (! str_contains($field->path, '.') || $field->canSort)) { // EXISTS fields need a related attribute and cannot define one scalar sort value.
                throw new InvalidArgumentException('Related fields require a relation path and cannot be sorted.'); // Reject ambiguous related-field definitions.
            }
            if ($field->callback !== null && ($field->canSearch || $field->canSort)) { // Custom callbacks define predicates rather than searchable or sortable columns.
                throw new InvalidArgumentException('Custom fields support filtering only.'); // Prevent later compilation without a column target.
            }
            foreach ($field->allowedOperators ?? [] as $operator) { // PHP definitions declare operator permissions with enum cases only.
                if (! $operator instanceof FilterOperator) { // Definition errors must not change which operations clients can request.
                    throw new InvalidArgumentException('Field operator policies must contain FilterOperator cases.'); // Require the typed policy contract.
                }
            }
            $fields[$name] = $field; // Index the isolated definition by its public request name.
        }
        $this->fields = $fields; // Publish the validated map for the compiler.
    }

    /** Validate filter maps, boolean trees, searches, sorts, and limits into one SQL-free plan. */
    public function parse(array $parameters, array $defaultSort): array
    {
        $conditions = []; // Root conditions will be ANDed by the compiler.
        foreach ($this->map($parameters, 'filter') as $name => $values) { // Read only a bounded object keyed by public field.
            $field = $this->field($name, 'filter'); // Enforce filter permission and field-specific authorization.
            $values = is_array($values) ? $values : [FilterOperator::Equal->value => $values]; // Treat scalar shorthand as equality without losing null or false.
            if ($values === [] || array_is_list($values)) { // Lists need an explicit operator to avoid ambiguous request shapes.
                $this->invalid("filter.$name", 'Use an operator such as eq for a list of values.'); // Point the client to the correct field syntax.
            }
            if (count($values) > $this->limits->maxConditions) { // Bound a single field's operator map before traversing it.
                $this->invalid("filter.$name", 'Too many filter operators.'); // Reject oversized maps early.
            }
            $operations = []; // Preserve each validated operation for this field.
            foreach ($values as $operator => $value) { // Multiple operators on a field are combined with AND.
                $operations[] = $this->operation($field, $operator, $value, "filter.$name.$operator"); // Normalize operands and enforce request-wide budgets.
            }
            $conditions[] = ['field' => $name, 'operations' => $operations]; // Add one compiler leaf containing the field's operations.
        }
        if (array_key_exists('where', $parameters)) { // An explicit null tree is invalid rather than absent.
            $conditions[] = $this->tree($parameters['where'], 'where', 1); // Validate the recursive tree starting at its root depth.
        }

        $searchType = array_key_exists('search_type', $parameters) ? $parameters['search_type'] : SearchTypeEnum::Any->value; // Default multiple search fields to OR.
        $searchType = is_string($searchType) ? SearchTypeEnum::tryFrom(strtolower($searchType)) : null; // Accept only the two enum-backed grouping choices.
        if ($searchType === null) { // Reject missing-type coercions and unknown boolean operators.
            $this->invalid('search_type', 'Search type must be "and" or "or".'); // Report the allowed public values.
        }
        $searches = []; // Keep only nonempty, authorized search terms.
        foreach ($this->map($parameters, 'search') as $name => $value) { // Search input follows the same bounded field-map shape.
            $this->field($name, 'search'); // Enforce search permission even when the term is blank.
            if ($value === null) { // Null search terms do not create predicates.
                continue; // Preserve empty-search behavior without counting unused SQL bindings.
            }
            $value = FilterOperator::Like->normalize($value, "search.$name", $this->limits); // Require a bounded scalar search term.
            $value = trim($value); // Ignore surrounding search whitespace while retaining zero and false as text.
            if ($value !== '') { // Only an exact empty normalized term is omitted.
                $this->countCondition("search.$name"); // Include searches in the overall condition budget.
                $this->countBindings(1, "search.$name"); // Each search emits one bound pattern.
                $searches[$name] = $value; // Retain the normalized text for compilation.
            }
        }

        $sorts = array_key_exists('sort', $parameters) ? $parameters['sort'] : $defaultSort; // Explicit client sorting replaces application defaults.
        $sorts = is_string($sorts) ? explode(',', $sorts) : $sorts; // Accept a comma-separated shorthand or a list.
        if (! is_array($sorts) || ! array_is_list($sorts) || count($sorts) > $this->limits->maxSorts) { // Bound sorting and reject keyed or scalar alternatives.
            $this->invalid('sort', 'Sort must be a bounded list or comma-separated string.'); // Explain the accepted sort containers.
        }
        $orders = []; // Map public sort fields to validated SQL directions.
        foreach ($sorts as $name) { // Preserve each field's first position in the requested ordering.
            if (! is_string($name) || trim($name) === '') { // Empty entries and nonstrings cannot identify a field.
                $this->invalid('sort', 'Sort fields must be non-empty strings.'); // Reject malformed entries before interpreting their prefix.
            }
            $name = trim($name); // Normalize harmless spacing around a sort entry.
            $descending = str_starts_with($name, '-'); // A leading minus selects descending order.
            $name = $descending ? substr($name, 1) : $name; // Resolve permission using the public field without its direction marker.
            $this->field($name, 'sort'); // Verify the field has a sortable scalar SQL target.
            $orders[$name] = $descending ? 'desc' : 'asc'; // Never pass an arbitrary request direction to SQL.
        }

        $limit = null; // Absence leaves the caller's existing query limit unchanged.
        if (array_key_exists('limit', $parameters)) { // Validate explicit nulls and booleans instead of treating them as omitted.
            $limit = $parameters['limit']; // Keep the original type for strict validation.
            if ((! is_int($limit) && ! is_string($limit)) || filter_var($limit, FILTER_VALIDATE_INT, [ // Accept integer input or its valid query-string representation.
                'options' => ['min_range' => 1, 'max_range' => $this->limits->maxLimit], // Bound result counts to the application's configured maximum.
            ]) === false) { // Treat validation failure as an error rather than coercing it to zero.
                $this->invalid('limit', "Limit must be an integer between 1 and {$this->limits->maxLimit}."); // Report the actual allowed range.
            }
            $limit = (int) $limit; // Store the validated limit with a consistent integer type.
        }

        return ['conditions' => $conditions, 'searches' => $searches, 'searchType' => $searchType->value, // Return only validated filter and search data.
            'sorts' => $orders, 'limit' => $limit]; // Include normalized ordering and the optional row limit.
    }

    /** Validate one boolean-tree node, recursively enforcing shape and depth limits. */
    private function tree(mixed $node, string $parameter, int $depth): array
    {
        if ($depth > $this->limits->maxDepth || ! is_array($node) || $node === [] || array_is_list($node)) { // Every node must be a nonempty object within the recursion budget.
            $this->invalid($parameter, 'Expected a filter object within the configured depth limit.'); // Stop before traversing malformed or excessively deep input.
        }
        foreach (['and', 'or'] as $boolean) { // Recognize only supported boolean group names.
            if (array_key_exists($boolean, $node)) { // A boolean group contains children rather than a field operation.
                $this->countCondition($parameter); // Count groups too, so empty structural overhead cannot evade limits.
                $children = $node[$boolean]; // Inspect the group's immediate child list.
                if (count($node) !== 1 || ! is_array($children) || ! array_is_list($children) || // Disallow mixing group types or adding leaf properties.
                    $children === [] || count($children) > $this->limits->maxConditions) { // Require at least one child within the width budget.
                    $this->invalid($parameter, 'A group must contain only and or or with a non-empty bounded list.'); // Explain the required boolean-node shape.
                }
                $parsed = []; // Build a separate validated child list.
                foreach ($children as $index => $child) { // Include the child index in any validation error path.
                    $parsed[] = $this->tree($child, "$parameter.$boolean.$index", $depth + 1); // Recurse with an explicitly incremented depth.
                }

                return [$boolean => $parsed]; // Preserve the group's AND or OR structure for SQL compilation.
            }
        }
        if (count($node) !== 3 || ! isset($node['field'], $node['operator']) || ! array_key_exists('value', $node) || // Require exactly the leaf's three properties while allowing a null operand.
            ! is_string($node['field'])) { // Public field names must be strings.
            $this->invalid($parameter, 'A condition requires exactly field, operator and value.'); // Reject extra keys and incomplete leaves.
        }
        $field = $this->field($node['field'], 'filter', $parameter); // Apply the same permissions as the shorthand filter map.

        return ['field' => $node['field'], 'operations' => [ // Convert a tree leaf into the compiler's common field-operation shape.
            $this->operation($field, $node['operator'], $node['value'], "$parameter.value"), // Validate and normalize the leaf's single operation.
        ]];
    }

    /** Normalize one permitted operation, apply operand rules, and account for its SQL budget. */
    private function operation(Field $field, mixed $name, mixed $value, string $parameter): array
    {
        $this->countCondition($parameter); // Every operation consumes the request-wide condition budget.
        $operator = is_string($name) ? FilterOperator::resolve($name) : null; // Resolve only supported operator names and aliases.
        if ($operator === null) { // Unknown names must never reach SQL compilation.
            $this->invalid($parameter, 'Unknown filter operator.'); // Attach the error to the requested operation.
        }
        if ($field->allowedOperators !== null && ! in_array($operator, $field->allowedOperators, true)) { // Respect an explicit field-level operator allowlist.
            $this->invalid($parameter, "Operator [$name] is not allowed for this field."); // Distinguish unsupported policy from unknown syntax.
        }

        $wasList = is_array($value); // Preserve scalar custom-callback input after equality normalizes to a list.
        $value = $operator->normalize($value, $parameter, $this->limits); // Validate shape, scalar types, and per-operation limits first.
        if (! in_array($operator, [FilterOperator::IsNull, FilterOperator::IsEmpty], true)) { // Boolean flags do not use the field's scalar transformation or rules.
            if ($field->normalizer !== null || $field->validationRules !== []) { // Skip closure creation and array traversal for ordinary fields.
                $transform = function (mixed $item) use ($field, $parameter): mixed { // Apply developer configuration to each operand independently.
                    $item = $field->normalizer === null ? $item : ($field->normalizer)($item); // Transform before checking field-specific validation rules.
                    if ($field->validationRules !== []) { // Invoke Laravel validation only when the field declared rules.
                        $validator = Validator::make(['value' => $item], ['value' => $field->validationRules]); // Validate this operand under a stable internal attribute.
                        if ($validator->fails()) { // Convert rule failures into the public request's error location.
                            throw ValidationException::withMessages([$parameter => $validator->errors()->all()]); // Preserve the rule messages while exposing the public parameter.
                        }
                    }

                    return $item; // Return the transformed and validated operand.
                };
                $value = is_array($value) ? array_map($transform, $value) : $transform($value); // Preserve list shape while processing every operand.
                if ($field->normalizer !== null) { // Only transformations can change already-normalized types or sizes.
                    $value = $operator->normalize($value, $parameter, $this->limits); // Reject transformed nested values, unsupported types, and oversized terms.
                }
            }
            $this->countBindings(is_array($value) ? count($value) : 1, $parameter); // Charge the request for the normalized operand count.
        }
        if ($operator === FilterOperator::IsEmpty) { // Empty checks bind an empty string or zero-length comparison value.
            $this->countBindings(1, $parameter); // Unlike IS NULL, this flag consumes one SQL binding.
        }

        if ($field->callback !== null && ! $wasList && is_array($value)) { // Custom callbacks retain their documented scalar-or-list input shape.
            $value = $value[0]; // Unwrap equality's normalized singleton list when the client sent a scalar.
        }

        return [$operator, $value]; // Pair the canonical enum with its safe operand for compilation.
    }

    /** Resolve a public field, enforce its requested capability, and authorize it once. */
    private function field(int|string $name, string $purpose, ?string $parameter = null): Field
    {
        $parameter ??= "$purpose.$name"; // Tree callers may provide a more precise nested error location.
        $field = $this->fields[$name] ?? null; // Never infer fields from request input or database metadata.
        $allowed = $field !== null && match ($purpose) { // Check the capability requested by this part of the input.
            'filter' => $field->canFilter, // Predicate use requires filtering permission.
            'search' => $field->canSearch, // Pattern search requires searching permission.
            'sort' => $field->canSort, // Ordering requires a sortable scalar field.
        };
        if (! $allowed) { // Unknown fields and disabled capabilities share a validation failure.
            $this->invalid($parameter, "Field [$name] is not allowed for $purpose."); // Do not expose undeclared model columns.
        }
        if (! isset($this->authorized[$name])) { // Repeated use of a field shares only this request's authorization result.
            if ($field->authorization !== null && ! ($field->authorization)()) { // Evaluate the application's access rule only when the field is requested.
                throw new AuthorizationException("You are not allowed to query field [$name]."); // Denied access aborts the whole filter before SQL mutation.
            }
            $this->authorized[$name] = true; // Avoid repeating the same access callback within this parse.
        }

        return $field; // Return a validated and authorized field definition.
    }

    /** Read a bounded public-field object while distinguishing absent input from invalid nulls. */
    private function map(array $parameters, string $name): array
    {
        $value = array_key_exists($name, $parameters) ? $parameters[$name] : []; // An absent map means no operations of that kind.
        if (! is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > $this->limits->maxConditions) { // Require object-like keys and a bounded number of fields.
            $this->invalid($name, "$name must be a bounded object keyed by public field."); // Reject malformed containers before traversing their values.
        }

        return $value; // Return the original keyed map for purpose-specific validation.
    }

    /** Charge one node or search against the request-wide condition limit. */
    private function countCondition(string $parameter): void
    {
        if (++$this->conditions > $this->limits->maxConditions) { // Count across all filter maps, boolean groups, and searches.
            $this->invalid($parameter, 'Too many filter conditions.'); // Stop at the operation that exceeds the budget.
        }
    }

    /** Charge normalized operand bindings against the combined request limit. */
    private function countBindings(int $count, string $parameter): void
    {
        $this->bindings += $count; // Include list operands, searches, and empty-check comparisons.
        if ($this->bindings > $this->limits->maxBindings) { // Many individually valid lists still share one total SQL budget.
            $this->invalid($parameter, 'Too many filter values across this request.'); // Reject the request before constructing an oversized query.
        }
    }

    /** Require identifier-only public names and dot-separated SQL or relation paths. */
    private function assertPath(string $path): void
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $path)) { // Exclude SQL syntax, empty segments, and nonidentifier characters.
            throw new InvalidArgumentException('Field names must be column or relation paths.'); // Invalid application mappings fail before client input is interpreted.
        }
    }

    /** Raise a Laravel validation error at the caller-visible parameter path. */
    private function invalid(string $parameter, string $message): never
    {
        throw ValidationException::withMessages([$parameter => $message]); // Keep error formatting consistent for every parser rejection.
    }
}
