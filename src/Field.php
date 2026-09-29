<?php

namespace Alif\QueryFilter; // Public definitions shared by both builder APIs.

use Alif\QueryFilter\Enums\FilterOperator; // Declare operations without repeating request strings.
use Closure; // Store application-provided validation and predicate callbacks.

/** A developer-owned field definition. Fluent methods return a new definition. */
final class Field
{
    /** @var list<FilterOperator>|null Explicit PHP policies use enum cases only. */
    public ?array $allowedOperators = null; // Null permits every built-in operator; an empty list permits none.
    public bool $canFilter = true; // Permit filter operands unless the definition disables them.
    public bool $canSearch = true; // Keep search permission independent from filtering.
    public bool $canSort = true; // Keep sorting permission independent from filtering.
    public bool $usesExists = false; // Ordinary fields resolve columns instead of existence queries.
    public array $validationRules = []; // Apply these Laravel rules to each ordinary filter operand.
    public ?Closure $normalizer = null; // Normalize operands before applying their validation rules.
    public ?Closure $authorization = null; // Check access only when this public field is requested.
    public ?Closure $callback = null; // Optional application predicate replaces built-in column comparisons.

    /** Store the application-owned SQL or relation path without resolving a query. */
    private function __construct(public readonly string $path)
    {
    }

    /** Declare a root column or supported to-one relation path. */
    public static function make(string $path): self
    {
        return new self($path); // Defer database-specific resolution until the field is requested.
    }

    /** Match an existing related record, without joining or duplicating parent rows. */
    public static function related(string $path): self
    {
        $field = new self($path); // Retain the full Eloquent relationship path.
        $field->usesExists = true; // Compile matching children through EXISTS instead of joins.
        $field->canSort = false; // A collection has no implicit single sorting value.

        return $field; // Return the fully configured relationship definition.
    }

    /**
     * Define an isolated application predicate with equality enabled by default.
     * @param Closure(\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder, mixed, string): mixed $callback
     */
    public static function custom(Closure $callback): self
    {
        $field = new self(''); // Callback fields do not resolve a SQL column path.
        $field->callback = $callback; // Invoke trusted application code when the field is filtered.
        $field->allowedOperators = [FilterOperator::Equal]; // Require explicit opt-in for additional operations.
        $field->canSearch = false; // A predicate has no automatic text-search representation.
        $field->canSort = false; // A predicate has no automatic sortable expression.

        return $field; // Keep the callback definition reusable across independent queries.
    }

    /**
     * Restrict allowed operations without changing an existing shared definition.
     * @param list<FilterOperator> $operators Allowed operations declared with enum cases.
     */
    public function operators(array $operators): self
    {
        $field = clone $this; // Preserve the original field's operator policy.
        $field->allowedOperators = $operators; // Keep the enum policy for strict validation during parsing.

        return $field; // Let fluent calls specialize this copy further.
    }

    /** Enable or disable filter predicates while preserving other capabilities. */
    public function filterable(bool $allowed = true): self
    {
        $field = clone $this; // Leave any shared definition unchanged.
        $field->canFilter = $allowed; // Apply only the filtering permission.

        return $field; // Return the specialized permission policy.
    }

    /** Enable or disable search terms independently from filtering and sorting. */
    public function searchable(bool $allowed = true): self
    {
        $field = clone $this; // Leave any shared definition unchanged.
        $field->canSearch = $allowed; // Apply only the search permission.

        return $field; // Return the specialized permission policy.
    }

    /** Enable or disable client sorting independently from filtering and search. */
    public function sortable(bool $allowed = true): self
    {
        $field = clone $this; // Leave any shared definition unchanged.
        $field->canSort = $allowed; // Apply only the sorting permission.

        return $field; // Return the specialized permission policy.
    }

    /** Laravel rules applied to each operand except is_null/is_empty flags after transformation. */
    public function rules(array $rules): self
    {
        $field = clone $this; // Preserve rules belonging to a shared definition.
        $field->validationRules = $rules; // Store Laravel's scalar operand validation rules.

        return $field; // Return the definition with its own validation policy.
    }

    /**
     * Attach deterministic operand normalization before field validation.
     * @param Closure(mixed): mixed $normalizer Applied to each operand.
     */
    public function transform(Closure $normalizer): self
    {
        $field = clone $this; // Keep normalization isolated to this fluent branch.
        $field->normalizer = $normalizer; // Store the callback without evaluating request input here.

        return $field; // The parser invokes normalization only for requested operands.
    }

    /**
     * Restrict use of the public field across filter, search, and sorting purposes.
     * @param Closure(): bool $authorization Evaluated only when this field is requested.
     */
    public function authorize(Closure $authorization): self
    {
        $field = clone $this; // Keep access policy changes local to this definition.
        $field->authorization = $authorization; // Evaluate access during request parsing, not construction.

        return $field; // Return the field with its application-owned authorization policy.
    }
}
