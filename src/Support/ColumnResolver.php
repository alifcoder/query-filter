<?php

namespace Alif\QueryFilter\Support; // Internal column and relation compilation helpers.

use Alif\QueryFilter\JsonColumn; // Developer-declared JSON path and scalar type.
use Illuminate\Contracts\Database\Query\Expression; // Grammar-aware SQL expressions for mapped columns.
use Illuminate\Database\Eloquent\Model; // Supplies the table, connection, and optional column map.
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Supports JSON-backed foreign keys on the parent.
use Illuminate\Database\Eloquent\Relations\HasOneOrMany; // Detects unsupported JSON keys on collection relations.
use Illuminate\Database\Eloquent\Relations\MorphTo; // Excludes relations without a single concrete target.
use Illuminate\Database\Eloquent\Relations\Relation; // Preserves Laravel's native relation behavior.
use InvalidArgumentException; // Reports invalid application-owned mapping definitions.

/** Map declared virtual attributes to SQL without inspecting accessors or the schema. */
final class ColumnResolver
{
    /** Resolve a physical column or mapped JSON attribute against the chosen table. */
    public function column(Model $model, string $attribute, ?string $table = null): string|Expression
    {
        $table ??= $model->getTable(); // Honor a join alias when one is provided.
        $source = $this->jsonColumn($model, $attribute); // Read the model's explicit mapping only.

        return $source === null // Ordinary columns retain their native database type.
            ? $table . '.' . $attribute // Qualify physical columns to avoid ambiguous joins.
            : $source->expression($model->getConnection(), $table); // Compile driver-specific JSON extraction and casting.
    }

    /** Use Laravel's own existence-query logic, including its self-relation aliases. */
    public function relation(Relation $relation): Relation
    {
        if ($relation instanceof BelongsTo && ! $relation instanceof MorphTo) { // Only concrete inverse relations can adapt their foreign key.
            if ($this->jsonColumn($relation->getRelated(), $relation->getOwnerKeyName()) !== null) { // The related identifier must stay a physical column.
                throw new InvalidArgumentException('JSON-backed BelongsTo relations require a real owner-key column.'); // Fail before compiling an unsupported comparison.
            }
            $source = $this->jsonColumn($relation->getChild(), $relation->getForeignKeyName()); // Look for the foreign key in the declaring model's map.
            if ($source !== null) { // Adapt only relations whose foreign key comes from JSON.
                return Relation::noConstraints(fn () => new JsonBelongsTo($relation, $source)); // Avoid constraints based on an unloaded model instance.
            }
        }
        if ($relation instanceof HasOneOrMany && ( // Other relation directions retain Laravel's physical-key requirements.
            $this->jsonColumn($relation->getRelated(), $relation->getForeignKeyName()) !== null || // Reject JSON-backed child keys.
            $this->jsonColumn($relation->getParent(), $relation->getLocalKeyName()) !== null // Reject JSON-backed parent keys.
        )) {
            throw new InvalidArgumentException('Mapped JSON relation keys are supported on BelongsTo foreign keys only.'); // Explain the supported mapping boundary.
        }

        return $relation; // Physical-key relations need no adapter.
    }

    /** Read and validate one optional mapping without invoking accessors or querying the schema. */
    private function jsonColumn(Model $model, string $attribute): ?JsonColumn
    {
        $columns = method_exists($model, 'queryFilterColumns') ? $model->queryFilterColumns() : []; // Models opt in by declaring mappings.
        if (! is_array($columns)) { // Reject malformed developer configuration early.
            throw new InvalidArgumentException('queryFilterColumns() must return an array of JsonColumn definitions.'); // State the required mapping container.
        }
        if (! array_key_exists($attribute, $columns)) { // Unmapped attributes remain ordinary SQL columns.
            return null; // Signal that no JSON expression is needed.
        }
        if (! $columns[$attribute] instanceof JsonColumn) { // Explicit null or arbitrary values are configuration errors.
            throw new InvalidArgumentException("Mapped attribute [$attribute] must use a JsonColumn definition."); // Identify the invalid attribute.
        }

        return $columns[$attribute]; // Return the validated path and scalar type.
    }
}
