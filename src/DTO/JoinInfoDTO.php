<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 12:03 PM
 */

namespace Alif\QueryFilter\DTO;

readonly class JoinInfoDTO
{
    public function __construct(
            public mixed $table,
            public string $first,
            public string $second,
            public ?string $as = null,
    )
    {
    }
}