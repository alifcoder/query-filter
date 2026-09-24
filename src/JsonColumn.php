<?php

namespace Alif\QueryFilter; // Model-owned mappings from virtual attributes to stored JSON values.

use Alif\QueryFilter\Enums\JsonType; // Require an explicit SQL scalar type when text semantics are unsuitable.
use Illuminate\Database\Connection; // Resolve quoting and casts for the actual query connection.
use Illuminate\Database\Query\Expression; // Pass the trusted extraction expression through Laravel's builder.
use InvalidArgumentException; // Reject unsupported paths and database drivers before query execution.

/** A developer-owned JSON path and its stored scalar type. */
final readonly class JsonColumn
{
    /** Validate a developer-defined JSON object path and retain its scalar type. */
    public function __construct(
        public string $path, // Use a column->key path rather than an accessor's PHP implementation.
        public JsonType $type = JsonType::Text, // Default to text when no numeric or typed comparison is needed.
    ) {
        if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z_][A-Za-z0-9_]*)+\z/', $path)) { // Permit simple object keys while excluding arbitrary SQL.
            throw new InvalidArgumentException('JSON columns require a column->key path using identifier names only.'); // Explain the supported mapping syntax.
        }
    }

    /** Build a quoted extraction expression with the selected driver's scalar cast. */
    public function expression(Connection $connection, string $table): Expression
    {
        $driver = $connection->getDriverName(); // Choose supported JSON extraction and conversion behavior.
        if (! in_array($driver, ['pgsql', 'sqlite', 'mysql', 'mariadb'], true)) { // Fail explicitly instead of guessing another dialect.
            throw new InvalidArgumentException("JSON columns do not support the [$driver] database driver."); // Identify the unsupported connection type.
        }

        $grammar = $connection->getQueryGrammar(); // Reuse Laravel's driver-specific identifier quoting.
        $value = $grammar->wrap($table . '.' . $this->path); // Qualify the JSON column before extracting its nested value.

        if ($driver === 'mysql' || $driver === 'mariadb') { // Normalize MySQL-family extraction differences.
            // JSON null is different from the JSON string "null" in these drivers.
            $segments = explode('->', $this->path); // Separate the physical column from JSON object keys.
            $column = $grammar->wrap($table . '.' . array_shift($segments)); // Quote the physical column with its table prefix.
            $path = '$."' . implode('"."', $segments) . '"'; // Build a JSON path from already-validated object identifiers.
            $value = "CASE WHEN JSON_TYPE(JSON_EXTRACT($column, '$path')) = 'NULL' THEN NULL ELSE $value END"; // Keep JSON null distinct from the literal string "null".

            if ($this->type === JsonType::Boolean) { // MySQL extracts booleans as text before numeric conversion.
                $value = "CASE $value WHEN 'true' THEN 1 WHEN 'false' THEN 0 ELSE $value END"; // Convert boolean text without collapsing true into zero.
            }
        }

        $cast = match ($driver) { // Use scalar cast names supported by the active SQL dialect.
            'pgsql' => match ($this->type) { // PostgreSQL supports native numeric, boolean, and UUID casts.
                JsonType::Text => null, // PostgreSQL's text extraction already has the required type.
                JsonType::Integer => 'BIGINT', // Match ordinary bigint foreign keys and integer ordering.
                JsonType::Decimal => 'NUMERIC', // Keep decimal comparisons in PostgreSQL's numeric domain.
                JsonType::Boolean => 'BOOLEAN', // Compare JSON booleans with native PostgreSQL booleans.
                JsonType::Uuid => 'UUID', // Match native UUID owner keys without casting the indexed owner column.
            },
            'sqlite' => match ($this->type) { // SQLite uses affinities instead of dedicated UUID and boolean types.
                JsonType::Text, JsonType::Uuid => 'TEXT', // Keep extracted text and UUID values consistently textual.
                JsonType::Integer, JsonType::Boolean => 'INTEGER', // Compare whole numbers and zero/one boolean values numerically.
                JsonType::Decimal => 'NUMERIC', // Request numeric affinity for decimal operands.
            },
            'mysql', 'mariadb' => match ($this->type) { // Use casts accepted by both MySQL-family drivers.
                JsonType::Text => null, // The grammar already provides unquoted JSON text.
                JsonType::Integer, JsonType::Boolean => 'SIGNED', // Compare numeric IDs and normalized booleans as integers.
                JsonType::Decimal => 'DECIMAL(65,20)', // Keep the mapping's documented decimal precision.
                JsonType::Uuid => 'CHAR(36)', // Represent UUID keys with their ordinary string length.
            },
        };

        $sql = $cast === null ? $value : "CAST($value AS $cast)"; // Avoid an unnecessary cast when extraction already returns text.

        return new Expression("($sql)"); // Preserve expression grouping when Laravel composes comparisons or joins.
    }
}
