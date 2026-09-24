<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 11:36 AM
 */

namespace Alif\QueryFilter\Interfaces; // Contracts for table-level filter integrations.


use Illuminate\Database\Query\Builder; // Use the SQL builder without requiring an Eloquent model.

/** Allow a concrete table filter to modify a Laravel Query Builder instance. */
interface QBFilterInterface
{
    /** Add filter constraints without retrieving or caching query results. */
    public function apply(Builder $builder): void;
}
