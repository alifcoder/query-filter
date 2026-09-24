<?php

namespace Alif\QueryFilter\Enums; // Boolean combination policies for requested search fields.

/** Choose whether all or any declared search fields must match. */
enum SearchTypeEnum: string
{
    case All = 'and'; // Require every nonempty search term to match its field.
    case Any = 'or'; // Allow any nonempty search term to satisfy the search group.
}
