<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 11:36 AM
 */

namespace Alif\QueryFilter\Traits;

use Alif\QueryFilter\Interfaces\EBFilterInterface;
use Illuminate\Database\Eloquent\Builder;


trait Filterable
{
    /**
     * @param Builder $builder
     * @param EBFilterInterface $filter
     *
     * @psalm-api
     *
     * @return Builder
     */
    public function scopeFilter(Builder $builder, EBFilterInterface $filter): Builder
    {
        $filter->apply($builder);

        return $builder;
    }
}