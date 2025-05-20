<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 11:36 AM
 */

namespace Alif\QueryFilter\Interfaces;

use Illuminate\Database\Eloquent\Builder;

interface EBFilterInterface
{
    public function apply(Builder $builder): void;
}