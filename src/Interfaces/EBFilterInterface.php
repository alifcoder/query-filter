<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 11:36 AM
 */

namespace Alif\QueryFilter\Interfaces; // Contracts accepted by the model filtering scope.

use Illuminate\Database\Eloquent\Builder; // Bind this contract to Laravel's model-aware query builder.

/** Allow model filters and existing integrations to modify an Eloquent query. */
interface EBFilterInterface
{
    /** Add filter constraints while leaving query execution to the caller. */
    public function apply(Builder $builder): void;
}
