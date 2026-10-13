<?php

namespace Alif\QueryFilter\Enums; // Declared value types of public fields.

/** The kind of value a field holds. Metadata for consumers; it never changes how the field is queried. */
enum FieldType: string
{
    case String = 'string'; // Free text.
    case Integer = 'integer'; // Whole numbers.
    case Decimal = 'decimal'; // Numbers with a fractional part.
    case Boolean = 'boolean'; // True or false.
    case Date = 'date'; // A calendar day.
    case DateTime = 'datetime'; // A day and a time.
    case Time = 'time'; // A time of day.
    case Uuid = 'uuid'; // A UUID key.
}
