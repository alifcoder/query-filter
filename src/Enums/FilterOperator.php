<?php

namespace Alif\QueryFilter\Enums; // Fixed operations accepted by the field-based request parser.

use Alif\QueryFilter\FilterLimits; // Bound operand size before SQL compilation.
use Illuminate\Contracts\Database\Query\Expression; // Accept trusted mapped JSON expressions alongside column names.
use Illuminate\Database\Eloquent\Builder; // Support model-aware relation filtering.
use Illuminate\Database\Query\Builder as QueryBuilder; // Reuse comparison logic for ordinary SQL builders.
use Illuminate\Validation\ValidationException; // Report invalid request operands through Laravel validation.

/** Validated operators for public API fields. Values are always SQL bindings. */
enum FilterOperator: string
{
    case Equal = 'eq'; // Match one value or any value in an equality list.
    case NotEqual = 'ne'; // Exclude the supplied values with explicit null handling.
    case GreaterThan = 'gt'; // Require a value strictly above the supplied boundary.
    case GreaterThanOrEqual = 'gte'; // Include the supplied lower boundary.
    case LessThan = 'lt'; // Require a value strictly below the supplied boundary.
    case LessThanOrEqual = 'lte'; // Include the supplied upper boundary.
    case In = 'in'; // Require an explicit inclusion list.
    case NotIn = 'nin'; // Require an explicit exclusion list.
    case Between = 'between'; // Include both endpoints of a two-value range.
    case NotBetween = 'nbetween'; // Select values outside the inclusive range.
    case Like = 'like'; // Interpret SQL wildcard characters in the supplied pattern.
    case NotLike = 'nlike'; // Exclude matches to the supplied SQL wildcard pattern.
    case Contains = 'contains'; // Treat wildcard characters as literal substring text.
    case StartsWith = 'starts_with'; // Match a literal prefix.
    case EndsWith = 'ends_with'; // Match a literal suffix.
    case IsNull = 'is_null'; // Select null or non-null values with an explicit boolean flag.
    case IsEmpty = 'is_empty'; // Select SQL null or exact empty text, or their complement.

    /** Resolve supported aliases without silently accepting unknown operations. */
    public static function resolve(string $operator): ?self
    {
        return self::tryFrom(match ($operator) { // Normalize aliases before looking up a backed enum case.
            'neq' => 'ne', // Preserve the common inequality alias.
            'not_in' => 'nin', // Normalize the long exclusion-list name.
            'not_between' => 'nbetween', // Normalize the long outside-range name.
            'not_like' => 'nlike', // Normalize the long negative-pattern name.
            default => $operator, // Unknown names remain invalid after enum lookup.
        });
    }

    /** Validate an operation's operand shape and return its normalized value. */
    public function normalize(mixed $value, string $parameter, FilterLimits $limits): mixed
    {
        if (is_array($value) && count($value) > $limits->maxValues) { // Reject oversized lists before validating individual entries.
            $this->invalid($parameter, "At most {$limits->maxValues} comparison values are allowed."); // Report the configured list budget at the request path.
        }

        if (in_array($this, [self::Equal, self::NotEqual, self::In, self::NotIn], true)) { // Equality and set operators share a normalized list representation.
            if (! is_array($value) && in_array($this, [self::Equal, self::NotEqual], true)) { // Only equality supports scalar shorthand.
                $value = [$value]; // Preserve scalar null, zero, false, and empty text as real operands.
            }
            if (! is_array($value) || ! array_is_list($value)) { // Associative objects are not comparison sets.
                $this->invalid($parameter, 'Comparison values must be a list.'); // Require an unambiguous ordered JSON/list shape.
            }
            foreach ($value as $item) { // Validate all entries before compiling any predicate.
                $this->validateScalar($item, $parameter, $limits, nullable: true, boolean: true); // Equality permits null and boolean operands.
            }

            return $value; // Keep list order and explicit null entries for SQL compilation.
        }

        if (in_array($this, [self::Between, self::NotBetween], true)) { // Ranges require exactly two ordinary scalar endpoints.
            if (! is_array($value) || ! array_is_list($value) || count($value) !== 2) { // Reject missing, extra, or named endpoints.
                $this->invalid($parameter, 'Between comparisons require exactly two values.'); // Explain the fixed range operand shape.
            }
            foreach ($value as $item) { // Check both endpoints without silently sorting them.
                $this->validateScalar($item, $parameter, $limits); // Null and boolean values cannot define ordered boundaries.
            }

            return $value; // Preserve the application-supplied endpoint order.
        }

        if (in_array($this, [self::IsNull, self::IsEmpty], true)) { // Probe operations validate boolean flags rather than column values.
            return match ($value) { // Accept only documented HTTP and JSON boolean representations.
                true, 1, '1', 'true' => true, // Select the requested null or empty set.
                false, 0, '0', 'false' => false, // Select values outside the requested set.
                default => $this->invalid($parameter, "$this->value must be a boolean."), // Reject ambiguous flag values.
            };
        }

        $pattern = in_array($this, [self::Like, self::NotLike, self::Contains, self::StartsWith, self::EndsWith], true); // Identify operations that normalize scalar operands to text.
        $this->validateScalar($value, $parameter, $limits, boolean: $pattern); // Ordered comparisons still reject booleans.

        if ($pattern) { // Convert valid scalar patterns only after checking their original shape.
            $value = is_bool($value) ? (string) (int) $value : (string) $value; // Represent false as "0" instead of losing it as empty text.
            $this->validateScalar($value, $parameter, $limits); // Recheck the final pattern's byte length.
        }

        return $value; // Return the scalar comparison or normalized text pattern.
    }

    /** Apply a normalized value to an already authorized column. */
    public function apply(Builder|QueryBuilder $builder, string|Expression $column, mixed $value): void
    {
        if (in_array($this, [self::Equal, self::NotEqual, self::In, self::NotIn], true)) { // Compile normalized equality/set operands through one SQL path.
            $not = in_array($this, [self::NotEqual, self::NotIn], true); // Choose inclusion or exclusion semantics.
            $values = array_values(array_filter($value, static fn ($item) => $item !== null)); // SQL IN lists handle ordinary values separately from null.
            $hasNull = count($values) !== count($value); // Remember whether the request explicitly included null.

            $builder->where(function (Builder|QueryBuilder $query) use ($column, $values, $hasNull, $not) { // Keep null alternatives grouped with their comparison list.
                if ($values !== [] || ! $hasNull) { // Preserve explicit empty-list semantics while skipping a null-only list.
                    $query->whereIn($column, $values, 'and', $not); // Bind the ordinary values using IN or NOT IN.
                }
                if ($hasNull) { // Handle the explicit null operand using SQL's dedicated null predicate.
                    $query->whereNull($column, $not ? 'and' : 'or', $not); // Inclusion permits null; exclusion requires non-null.
                }
            });

            return; // The grouped list predicate fully represents this operation.
        }

        if ($this === self::IsNull) { // Null probes do not allocate a comparison binding.
            $builder->whereNull($column, 'and', ! $value); // A false flag selects non-null values.

            return; // No ordinary comparison is required for a null probe.
        }

        if ($this === self::IsEmpty) { // Exact-empty semantics require driver-aware text handling.
            $this->applyEmpty($builder, $column, $value); // Keep zero and whitespace distinct from an empty string.

            return; // The grouped null/text probe fully represents this operation.
        }

        if (in_array($this, [self::Between, self::NotBetween], true)) { // Both range operators share bound endpoint handling.
            $builder->whereBetween($column, $value, 'and', $this === self::NotBetween); // Invert the range only for the explicit outside-range operator.

            return; // The range clause replaces any ordinary comparison.
        }

        if (in_array($this, [self::Contains, self::StartsWith, self::EndsWith], true)) { // Literal text matching must escape SQL wildcard syntax.
            $this->applyLiteralPattern($builder, $column, $value); // Add only the wildcard positions required by this operator.

            return; // The escaped pattern clause completes literal text matching.
        }

        $like = $builder->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like'; // Match PostgreSQL text case-insensitively and retain other drivers' collation behavior.
        $operator = match ($this) { // Compile the remaining scalar and wildcard operations.
            self::GreaterThan => '>', // Exclude the lower boundary.
            self::GreaterThanOrEqual => '>=', // Include the lower boundary.
            self::LessThan => '<', // Exclude the upper boundary.
            self::LessThanOrEqual => '<=', // Include the upper boundary.
            self::Like => $like, // Preserve deliberate SQL wildcard interpretation.
            self::NotLike => "not $like", // Negate the driver's pattern comparison.
        };
        $builder->where($column, $operator, $value); // Bind the normalized scalar value through Laravel.
    }

    /** Empty means SQL NULL or an exact empty string; zero and whitespace are values. */
    private function applyEmpty(Builder|QueryBuilder $builder, string|Expression $column, bool $empty): void
    {
        $connection = $builder->getConnection(); // Use the builder's actual SQL dialect.
        $driver = $connection->getDriverName(); // Choose a compatible text cast and exact-empty comparison.
        $wrapped = $connection->getQueryGrammar()->wrap($column); // Quote column identifiers while retaining trusted expressions.
        $type = match ($driver) { // Numeric and boolean columns must be compared as text, not coerced to zero.
            'mysql', 'mariadb' => 'CHAR', // MySQL-family casts use CHAR for textual values.
            'sqlsrv' => 'NVARCHAR(MAX)', // Preserve Unicode text for SQL Server values.
            default => 'TEXT', // PostgreSQL and SQLite both support a TEXT cast.
        };
        $text = "CAST($wrapped AS $type)"; // Share one explicit text representation across empty checks.

        // These drivers can ignore trailing spaces when comparing strings.
        $length = match ($driver) { // Avoid comparisons whose collation treats trailing spaces as insignificant.
            'mysql', 'mariadb' => "CHAR_LENGTH($text)", // Only a zero-character value is exactly empty.
            'sqlsrv' => "DATALENGTH($text)", // Unlike LEN, byte length preserves trailing whitespace.
            default => null, // PostgreSQL and SQLite can compare exact text directly.
        };
        $comparison = $empty ? '=' : '<>'; // A false flag requires a nonempty text representation.
        $sql = $length === null ? "$text $comparison ?" : "$length $comparison ?"; // Select direct text or length comparison for the driver.
        $bindings = [$length === null ? '' : 0]; // Keep the empty text or zero-length probe as a binding.

        $builder->where(function (Builder|QueryBuilder $query) use ($column, $empty, $sql, $bindings) { // Isolate the null/text combination from surrounding access predicates.
            $query->whereNull($column, 'and', ! $empty); // Empty accepts null; nonempty first requires non-null.
            $query->whereRaw($sql, $bindings, $empty ? 'or' : 'and'); // Empty uses a union of cases; nonempty requires both complements.
        });
    }

    /** Escape literal text and add only the wildcard positions required by this operation. */
    private function applyLiteralPattern(Builder|QueryBuilder $builder, string|Expression $column, string $value): void
    {
        $driver = $builder->getConnection()->getDriverName(); // Account for dialect-specific wildcard syntax.
        $escapes = ['!' => '!!', '%' => '!%', '_' => '!_']; // Escape the chosen escape marker and ordinary SQL wildcards.
        if ($driver === 'sqlsrv') { // SQL Server also treats an opening bracket as pattern syntax.
            $escapes['['] = '!['; // Keep bracket characters literal in user search text.
        }
        $pattern = strtr($value, $escapes); // Escape user content before adding operator-owned wildcard positions.
        $pattern = match ($this) { // Express the literal match position without changing the escaped value.
            self::Contains => "%$pattern%", // Allow characters on either side of the literal term.
            self::StartsWith => "$pattern%", // Allow characters only after the literal prefix.
            self::EndsWith => "%$pattern", // Allow characters only before the literal suffix.
        };

        $column = $builder->getConnection()->getQueryGrammar()->wrap($column); // Quote the authorized column or retain its mapped expression.
        $comparison = $driver === 'pgsql' ? '::text ILIKE' : ' LIKE'; // PostgreSQL needs an explicit text representation for scalar matching.
        $builder->whereRaw("$column$comparison ? ESCAPE '!'", [$pattern]); // Bind the escaped pattern while declaring its escape character.
    }

    /** Enforce finite scalar operands and byte limits before they become SQL bindings. */
    private function validateScalar(
        mixed $value, // Inspect the original operand rather than relying on truthiness.
        string $parameter, // Associate validation failures with the public request path.
        FilterLimits $limits, // Reuse the request's configured string budget.
        bool $nullable = false, // Equality/set operations explicitly permit null operands.
        bool $boolean = false, // Only supported comparisons and pattern normalization permit booleans.
    ): void {
        if ($nullable && $value === null) { // Preserve a permitted explicit null without coercion.
            return; // Null has no numeric or string length to validate.
        }
        if (! is_scalar($value) || (! $boolean && is_bool($value))) { // Reject nested arrays, objects, and unsupported boolean comparisons.
            $this->invalid($parameter, 'This comparison requires scalar values of the supported type.'); // Explain the expected operand type.
        }
        if (is_float($value) && ! is_finite($value)) { // NaN and infinities have no portable bound SQL comparison.
            $this->invalid($parameter, 'Comparison numbers must be finite.'); // Reject non-finite values before reaching the driver.
        }
        if (is_string($value) && strlen($value) > $limits->maxTermLength) { // Bound string processing by bytes without requiring multibyte decoding.
            $this->invalid($parameter, "Comparison strings must not exceed {$limits->maxTermLength} bytes."); // Report the configured term limit at the operand path.
        }
    }

    /** Raise a Laravel validation error for the exact invalid request operand. */
    private function invalid(string $parameter, string $message): never
    {
        throw ValidationException::withMessages([$parameter => $message]); // Preserve Laravel's normal JSON validation response behavior.
    }
}
