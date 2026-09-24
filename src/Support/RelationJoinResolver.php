<?php

namespace Alif\QueryFilter\Support; // Internal compilation helpers for approved relation paths.

use Illuminate\Database\Eloquent\Builder; // Carries the root model and staged query.
use Illuminate\Database\Eloquent\Model; // Documents the model stored with each resolved alias.
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Identifies inverse relations with one related row.
use Illuminate\Database\Eloquent\Relations\HasOne; // Supports direct relations declared to have one related row.
use Illuminate\Database\Eloquent\Relations\MorphTo; // Rejects paths whose target table varies by row.
use Illuminate\Database\Eloquent\Relations\Relation; // Creates relations without unloaded-model constraints.
use Illuminate\Contracts\Database\Query\Expression; // Handles JSON expressions and derived join tables.
use Illuminate\Support\Str; // Resolves public snake-case segments to camel-case relation methods.
use Illuminate\Validation\ValidationException; // Associates invalid relation paths with the requested field.
use InvalidArgumentException; // Reports unsupported application-owned query shapes.

/** Resolves only developer-approved paths; never discovers fields from request input. */
final class RelationJoinResolver
{
    /** @var array<string, array{Model, string}> Resolved paths within this compilation only. */
    private array $relations = []; // Reuse each resolved relation path within this compilation.

    /** Attach this resolver to one staged root query; aliases never outlive that query. */
    public function __construct(private Builder $builder)
    {
    }

    /** Resolve a declared field and add each required, scoped singular-relation join once. */
    public function column(string $path, string $parameter): string|Expression
    {
        $columns = new ColumnResolver(); // Resolves physical and explicitly mapped JSON attributes.
        $segments = explode('.', $path); // Separate relation names from the final attribute.
        $column = array_pop($segments); // The last segment is the field on the resolved model.
        $model = $this->builder->getModel(); // Start traversal from the root model.
        $parent = $this->builder->getQuery()->from; // Keep the caller's root table or alias.
        if (! is_string($parent)) { // Raw FROM expressions cannot supply a reliable model qualifier.
            throw new InvalidArgumentException('API fields require a table name or table alias, not a raw FROM expression.'); // Fail without guessing a table name.
        }
        $parent = preg_split('/\s+as\s+/i', $parent); // Recognize the caller's optional AS alias.
        $parent = end($parent); // Use the alias when present, otherwise the table.
        $resolved = []; // Accumulate the full relation path for stable join aliases.

        foreach ($segments as $segment) { // Resolve one singular relation at a time.
            $method = $model->isRelation($segment) ? $segment : Str::camel($segment); // Accept exact names before trying camel case.
            $resolved[] = $method; // Distinguish the same relation name under different parents.
            $key = implode('.', $resolved); // Identify this join within the current compilation.

            if (isset($this->relations[$key])) { // A prior requested field may already use this path.
                [$model, $parent] = $this->relations[$key]; // Reuse the resolved model and SQL alias.
                continue; // Avoid rebuilding the relation and adding a duplicate join.
            }

            if (! $model->isRelation($method)) { // Only declared Eloquent relations can be traversed.
                $this->invalid($parameter, "Unknown relation in field [$path]."); // Report the full requested path.
            }

            $relation = Relation::noConstraints(fn () => $model->{$method}()); // Ignore constraints tied to an unloaded model instance.

            if ($relation instanceof MorphTo || ! ($relation instanceof BelongsTo || $relation instanceof HasOne)) { // Joined fields require one concrete related row.
                $this->invalid($parameter, "Field [$path] must use BelongsTo or HasOne relations."); // Collection fields should use related EXISTS definitions.
            }
            $relation = $columns->relation($relation); // Adapt declared JSON foreign keys before comparing them.

            $related = $relation->getRelated(); // Read the target model and its key metadata.
            // The whole path distinguishes createdBy.address from updatedBy.address.
            // A short hash also keeps aliases below PostgreSQL's identifier limit.
            $alias = 'qf_' . substr(hash('sha256', $this->builder->getQuery()->from . '.' . $key), 0, 24); // Keep aliases stable across repeated filter applications.

            if ($relation instanceof BelongsTo) { // The parent holds the inverse relation's foreign key.
                $first = $columns->column($related, $relation->getOwnerKeyName(), $alias); // Preserve the related owner-key column for indexing.
                $second = $columns->column($model, $relation->getForeignKeyName(), $parent); // Extract the parent key from JSON when configured.
            } else { // HasOne stores its foreign key on the related model.
                $first = $columns->column($related, $relation->getForeignKeyName(), $alias); // Qualify the related foreign key with its join alias.
                $second = $columns->column($model, $relation->getLocalKeyName(), $parent); // Compare it to the current parent's local key.
            }

            // Keep scope predicates inside the subquery so absent/hidden relations
            // remain NULL and cannot exclude a different branch of an OR search.
            if (! $this->alreadyJoined($alias, $first, $second)) { // Reapplying filters must not duplicate an existing relation join.
                $query = clone $relation->getQuery(); // Preserve relation scopes without mutating its source query.
                $query->select($related->qualifyColumn('*')); // Expose the related fields needed by later filters and sorts.
                $this->builder->leftJoinSub($query, $alias, $first, '=', $second); // Keep absent or scoped-out relations as NULL.
            }

            $this->relations[$key] = [$related, $alias]; // Reuse this path for subsequent fields in this compilation.
            $model = $related; // Resolve the next relation on the target model.
            $parent = $alias; // The next join starts from the current derived table.
        }

        if ($segments !== []) { // Joining relations must not overwrite root-model attributes during hydration.
            $query = $this->builder->getQuery(); // Modify only the staged query's select list.
            $table = preg_split('/\s+as\s+/i', $query->from); // Resolve the root table qualifier again for selection.
            $table = end($table); // Prefer an explicit root alias.
            $query->columns = array_map( // Preserve custom projections while narrowing bare stars to the root.
                fn ($selection) => $selection === '*' ? $table . '.*' : $selection, // Replace only an unqualified wildcard.
                $query->columns ?? ['*'], // Match Laravel's default projection when none was set.
            );
        }

        return $columns->column($model, $column, $parent); // Return the final qualified column or typed JSON expression.
    }

    /** Recognize an existing generated join by its alias and both key expressions. */
    private function alreadyJoined(string $alias, string|Expression $first, string|Expression $second): bool
    {
        $query = $this->builder->getQuery(); // Inspect joins already present on the staged root query.
        foreach ($query->joins ?? [] as $join) { // Stop at the first equivalent generated join.
            if ($join->table instanceof Expression && // Generated derived tables are stored as SQL expressions.
                str_ends_with($join->table->getValue($query->getGrammar()), ' as ' . $query->getGrammar()->wrapTable($alias)) && // Verify the complete wrapped alias.
                $query->getGrammar()->getValue($join->wheres[0]['first'] ?? null) === $query->getGrammar()->getValue($first) && // Match the related-key expression.
                $query->getGrammar()->getValue($join->wheres[0]['second'] ?? null) === $query->getGrammar()->getValue($second)) { // Match the parent-key expression too.
                return true; // Reuse the existing join and its bindings.
            }
        }

        return false; // This relation still needs a derived-table join.
    }

    /** Return a field-specific validation error for an unsupported relation path. */
    private function invalid(string $parameter, string $message): never
    {
        throw ValidationException::withMessages([$parameter => $message]); // Keep errors attached to the public request parameter.
    }
}
