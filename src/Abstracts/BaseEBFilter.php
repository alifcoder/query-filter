<?php

namespace Alif\QueryFilter\Abstracts; // Application model filters extend this namespace's base.

use Alif\QueryFilter\Interfaces\EBFilterInterface; // Preserve the model scope's filter contract.
use Illuminate\Database\Eloquent\Builder; // Keep hook arguments model-aware.
use Illuminate\Validation\ValidationException; // Report an invalid short flag like other request errors.

/** Base for one model's explicit fields, relations, JSON mappings and access policy. */
abstract class BaseEBFilter extends BaseFilter implements EBFilterInterface
{
    public const string SHORT = 'short'; // Name the parameter that selects the short relation set.

    /** @var array Relations to eager load by default, using Laravel's with() array syntax. */
    protected static array $with = []; // Let each model filter declare its relation defaults.
    /** @var array|null Relations for `short=1` lists, using the same syntax; null loads $with. */
    protected static ?array $withShort = null; // Let compact list responses load fewer relations.
    private ?array $withOverride = null; // Keep request-specific overrides off shared static state.

    /** Return the class's declared relations, e.g. to load one record the way its list does. */
    public static function relations(bool $short = false): array
    {
        return $short ? (static::$withShort ?? static::$with) : static::$with; // Short lists fall back to the full set.
    }

    /** Whether the request asks for the short relation set with `short=1`. */
    public function isShort(): bool
    {
        return match ($this->parameter(self::SHORT, false)) { // Accept the same boolean spellings as is_null and is_empty.
            true, 1, '1', 'true' => true, // Select the short relation set.
            false, 0, '0', 'false', null, '' => false, // Keep the full relation set.
            default => throw ValidationException::withMessages([self::SHORT => 'short must be a boolean.']), // Reject ambiguous values before any query change.
        };
    }

    /** Replace this instance's relation defaults; an empty array disables only those defaults. */
    public function setWith(array $relations): static
    {
        $this->withOverride = $relations; // Preserve defaults used by other instances and subclasses.

        return $this; // Allow eager-loading configuration before applying this filter.
    }

    /** Return this instance's override, or the class defaults for the requested full or short list. */
    public function getWith(): array
    {
        return $this->withOverride ?? static::relations($this->isShort()); // Retain an explicitly empty override.
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
