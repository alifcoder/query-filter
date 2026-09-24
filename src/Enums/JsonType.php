<?php

namespace Alif\QueryFilter\Enums; // Explicit storage types for mapped JSON scalars.

/** The SQL scalar type of a value stored in a JSON document. */
enum JsonType: string
{
    case Text = 'text'; // Compare the extracted JSON value using text semantics.
    case Integer = 'integer'; // Cast numeric keys and ranges to whole numbers.
    case Decimal = 'decimal'; // Preserve decimal comparison and sorting semantics.
    case Boolean = 'boolean'; // Normalize JSON true and false to the driver's boolean representation.
    case Uuid = 'uuid'; // Match UUID owner keys using the database's supported type.
}
