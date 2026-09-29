<?php

namespace Alif\QueryFilter\Support; // Internal compilation of validated filter plans.

use Alif\QueryFilter\Enums\FilterOperator; // Applies normalized operators using bound values.
use Alif\QueryFilter\Field; // Supplies developer-owned field capabilities and callbacks.
use Illuminate\Contracts\Database\Query\Expression; // Represents typed JSON column expressions.
use Illuminate\Database\Eloquent\Builder; // Enables model relations and mapped attributes.
use Illuminate\Database\Query\Builder as QueryBuilder; // Supports the same plan on plain SQL queries.
use Illuminate\Database\Eloquent\Relations\MorphTo; // Rejects existence paths without a concrete related model.
use Illuminate\Database\Eloquent\Relations\Relation; // Builds relation queries without unloaded-model constraints.
use Illuminate\Support\Str; // Converts public snake-case relation segments to method names.
use Illuminate\Validation\ValidationException; // Reports invalid relation paths against their public field.
use InvalidArgumentException; // Reports unsupported builder and mapping combinations.

/** Compiles validated conditions using bound values and isolated boolean groups. */
final class FilterQuery
{
    private array $columns = []; // Resolved SQL columns used only during this compilation.
    private array $relations = []; // Validated EXISTS paths used only during this compilation.

    /**
     * Compile only the field definitions validated for this application request.
     *
     * @param array<string, Field> $fields
     */
    public function __construct(private array $fields)
    {
    }

    /** Resolve requested fields, then append grouped filters, searches, sorting, and limit. */
    public function apply(Builder|QueryBuilder $builder, array $plan): void
    {
        $names = array_merge($this->conditionFields($plan['conditions']), array_keys($plan['searches']), array_keys($plan['sorts'])); // Collect every field whose SQL target is needed.
        $resolver = $builder instanceof Builder ? new RelationJoinResolver($builder) : null; // Plain queries use explicit SQL column mappings instead.
        foreach (array_unique($names) as $name) { // Resolve repeated fields once for this plan.
            $field = $this->fields[$name]; // Definitions were already authorized and validated by the parser.
            if ($field->usesExists) { // Collection-style fields filter through related existence queries.
                if (! $builder instanceof Builder) { // Relation traversal requires model metadata.
                    throw new InvalidArgumentException('Related fields require an Eloquent builder. Map joined SQL columns for BaseQBFilter.'); // Explain the plain-query alternative.
                }
                $this->relations[$name] = $this->relationPath($builder, $field->path, $name); // Store the concrete relation methods and terminal attribute.
            } elseif ($field->callback === null) { // Custom callbacks provide their own predicates and need no column.
                $this->columns[$name] = $resolver !== null // Choose model-aware resolution only for Eloquent.
                    ? $resolver->column($field->path, $name) // Add any required singular-relation joins or JSON expressions.
                    : $this->queryColumn($builder, $field->path); // Qualify plain SQL fields against the caller's table.
            }
        }

        // Each condition supplies its own AND group, including custom ORs.
        foreach ($plan['conditions'] as $condition) { // Avoid an extra nested builder around already-isolated root conditions.
            $this->condition($builder, $condition); // Append each root condition with AND.
        }

        if ($plan['searches'] !== []) { // Empty searches must not add a query group.
            $builder->where(function (Builder|QueryBuilder $query) use ($plan) { // Keep search ORs inside an AND boundary protecting caller constraints.
                foreach ($plan['searches'] as $name => $value) { // Apply each normalized search term to its approved field.
                    $query->where(function (Builder|QueryBuilder $nested) use ($name, $value) { // Isolate each field, including related EXISTS searches.
                        $this->onField($nested, $name, function (Builder|QueryBuilder $target, string|Expression $column) use ($value) { // Select the field's direct or related query target.
                            FilterOperator::Like->apply($target, $column, '%' . $value . '%'); // Bind the wildcard search term using the driver's pattern operator.
                        });
                    }, null, null, $plan['searchType']); // Combine searchable fields using the validated AND or OR choice.
                }
            });
        }

        foreach ($plan['sorts'] as $name => $direction) { // Preserve the client's declared sort priority.
            $builder->orderBy($this->columns[$name], $direction); // Column and direction were both validated before compilation.
        }
        if ($plan['limit'] !== null) { // Leave the caller's limit intact unless one was explicitly requested.
            $builder->limit($plan['limit']); // Apply the positive bounded row limit.
        }
    }

    /** Append one filter leaf or recursively grouped boolean node without escaping its parent. */
    private function condition(Builder|QueryBuilder $builder, array $node, string $boolean = 'and'): void
    {
        $builder->where(function (Builder|QueryBuilder $query) use ($node) { // Every node owns a SQL group, including leaves with multiple operators.
            foreach (['and', 'or'] as $group) { // The parser guarantees at most one boolean group per node.
                if (isset($node[$group])) { // A group delegates its behavior to its child nodes.
                    foreach ($node[$group] as $child) { // Preserve the validated child order and nesting.
                        $this->condition($query, $child, $group); // Combine siblings using their group's boolean operator.
                    }

                    return; // Group nodes contain no field operations of their own.
                }
            }

            $field = $this->fields[$node['field']]; // Resolve the authorized leaf definition.
            if ($field->callback !== null) { // Application-owned fields build their own WHERE predicates.
                foreach ($node['operations'] as [$operator, $value]) { // Each custom operation remains an AND constraint within the leaf.
                    QueryConstraints::add($query, fn (Builder|QueryBuilder $nested) => ($field->callback)($nested, $value, $operator->value)); // Isolate custom ORs and reject discarded non-WHERE changes.
                }

                return; // Custom fields do not have a compiled column target.
            }

            $this->onField($query, $node['field'], function (Builder|QueryBuilder $target, string|Expression $column) use ($node) { // Keep all operators on a related field in the same EXISTS query.
                foreach ($node['operations'] as [$operator, $value]) { // Apply already-normalized operands in declaration order.
                    $operator->apply($target, $column, $value); // The enum emits bound SQL with its own null semantics.
                }
            });
        }, null, null, $boolean); // Attach the complete node to its parent with the requested boolean.
    }

    /** Run a column callback directly or within the field's validated EXISTS relation path. */
    private function onField(Builder|QueryBuilder $builder, string $name, callable $callback): void
    {
        if (isset($this->relations[$name])) { // Related fields use EXISTS to avoid duplicating parent rows.
            [$relation, $column] = $this->relations[$name]; // Retrieve the validated method path and terminal attribute.
            $this->whereRelated($builder, $relation, $column, $callback); // Compile predicates against the related model's query.
        } else { // Direct and singular-join fields already have resolved SQL columns.
            $callback($builder, $this->columns[$name]); // Apply the predicate to the current query.
        }
    }

    /** Traverse a concrete relation path while preserving Laravel scopes and JSON key adapters. */
    private function whereRelated(Builder $builder, array $methods, string $column, callable $callback): void
    {
        $method = array_shift($methods); // Consume the next validated relation method.
        $relation = Relation::noConstraints(fn () => $builder->getModel()->{$method}()); // Avoid predicates based on an unloaded parent instance.
        $columns = new ColumnResolver(); // Recognize declared JSON keys and related scalar attributes.
        $builder->whereHas($columns->relation($relation), function (Builder|QueryBuilder $query) use ($methods, $column, $callback, $columns) { // Let Laravel build scoped, correlated EXISTS SQL.
            if ($methods !== []) { // Nested relation paths require another correlated existence check.
                $this->whereRelated($query, $methods, $column, $callback); // Continue from the current related model.
            } else { // The final relation owns the requested scalar attribute.
                $callback($query, $columns->column($query->getModel(), $column)); // Apply the predicate using a physical column or JSON expression.
            }
        });
    }

    /** Qualify a plain-query field, honoring explicit SQL mappings and safe FROM aliases. */
    private function queryColumn(QueryBuilder $builder, string $path): string
    {
        // Qualified mappings belong to the application, which also owns the joins.
        if (str_contains($path, '.')) { // Qualified developer mappings already identify their source table.
            return $path; // Preserve caller-owned join aliases exactly.
        }
        if (! is_string($builder->from) || ! preg_match( // Infer qualifiers only from a simple table name and optional AS alias.
            '/\A([a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*)(?:\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*))?\z/i', // Permit schema-qualified identifiers, not raw SQL expressions.
            $builder->from, // Inspect the caller's root table declaration.
            $matches, // Capture the optional alias separately from the table.
        )) {
            throw new InvalidArgumentException('Unqualified fields require a table name or FROM alias. Map qualified SQL columns for raw FROM expressions.'); // Request explicit mappings when the qualifier is ambiguous.
        }

        return ($matches[2] ?? $matches[1]) . '.' . $path; // Prefer the alias and qualify the approved field name.
    }

    /** Collect leaf field names from a validated boolean tree before any SQL is appended. */
    private function conditionFields(array $nodes): array
    {
        $fields = []; // Accumulate names without resolving the same field during recursion.
        foreach ($nodes as $node) { // Inspect each root or nested condition.
            if (isset($node['field'])) { // Leaf nodes refer to a public field.
                $fields[] = $node['field']; // Keep duplicates until the single resolution pass in apply().
            } else { // Boolean groups contribute their children's fields.
                array_push($fields, ...$this->conditionFields($node['and'] ?? $node['or'])); // Flatten the validated tree without altering it.
            }
        }

        return $fields; // Return all requested leaf names for column preparation.
    }

    /** Validate an EXISTS path and return its concrete method names and final attribute. */
    private function relationPath(Builder $builder, string $path, string $name): array
    {
        if ($builder->getQuery()->from !== $builder->getModel()->getTable()) { // Laravel correlates EXISTS queries against the model's original table.
            throw new InvalidArgumentException('Related EXISTS fields require the original model table without a FROM alias.'); // Reject aliases that would break that correlation.
        }
        $segments = explode('.', $path); // Split relation traversal from the terminal attribute.
        $column = array_pop($segments); // The final segment is the related model's scalar field.
        $model = $builder->getModel(); // Begin relation validation at the root model.
        $methods = []; // Store resolved method names rather than user spelling.
        foreach ($segments as $segment) { // Validate the complete path before emitting query predicates.
            $method = $model->isRelation($segment) ? $segment : Str::camel($segment); // Support exact names and snake-case public paths.
            if (! $model->isRelation($method)) { // Never invoke arbitrary model methods from a field path.
                throw ValidationException::withMessages([$name => "Unknown relation in field [$path]."]); // Attribute the invalid traversal to its public field.
            }
            $relation = Relation::noConstraints(fn () => $model->{$method}()); // Read relation metadata without unloaded-model key constraints.
            if (! $relation instanceof Relation || $relation instanceof MorphTo) { // Every traversal step must identify one concrete related model.
                throw ValidationException::withMessages([$name => 'Related fields require a concrete Eloquent relation.']); // Explain why polymorphic targets need an explicit application filter.
            }
            $methods[] = $method; // Record the method that existence compilation will invoke.
            $model = $relation->getRelated(); // Validate the next segment on the current target model.
        }

        return [$methods, $column]; // Keep relation traversal separate from terminal column resolution.
    }
}
