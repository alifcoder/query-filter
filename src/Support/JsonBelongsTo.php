<?php

namespace Alif\QueryFilter\Support; // Internal adapters used during query compilation.

use Alif\QueryFilter\JsonColumn; // Holds the mapped JSON foreign key and scalar type.
use Illuminate\Contracts\Database\Query\Expression; // Lets Laravel compare a compiled JSON expression.
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Retains Laravel's native inverse-relation query logic.

/** Query-only adapter; the application's accessor and relation remain unchanged. */
final class JsonBelongsTo extends BelongsTo
{
    /** Copy an existing relation while replacing only its SQL foreign-key expression. */
    public function __construct(BelongsTo $relation, private JsonColumn $source)
    {
        parent::__construct( // Reuse Laravel's relation metadata and any declared constraints.
            clone $relation->getQuery(), // Isolate compilation from the application's original relation query.
            $relation->getChild(), // Keep the model that owns the JSON document.
            $relation->getForeignKeyName(), // Preserve the application's virtual foreign-key attribute name.
            $relation->getOwnerKeyName(), // Keep the physical related key available for database indexes.
            $relation->getRelationName(), // Preserve relation naming for Laravel's existence-query handling.
        );
    }

    /** Expose the mapped JSON scalar wherever Laravel needs a qualified foreign key. */
    public function getQualifiedForeignKeyName(): Expression
    {
        return $this->source->expression($this->child->getConnection(), $this->child->getTable()); // Use the current table name, including self-relation aliases.
    }
}
