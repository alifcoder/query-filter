<?php

/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 2:10 PM
 */

namespace Alif\QueryFilter\Macros;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class DeletedMacro
{
    public static function register(): void
    {
        EloquentBuilder::macro('onlyDeleted', $onlyDeleted = function (bool $trashed = false) {
            return $this->when($trashed, fn($q) => $q->onlyTrashed());
        });


        EloquentBuilder::macro('withDeleted', $withDeleted = function (bool $trashed = false) {
            return $this->when($trashed, fn($q) => $q->withTrashed());
        });
    }
}