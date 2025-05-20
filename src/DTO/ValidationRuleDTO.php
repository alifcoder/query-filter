<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 2:29 PM
 */

namespace Alif\QueryFilter\DTO;

class ValidationRuleDTO
{
    public function __construct(
            public string $field,
            public array $rules,
            public ?array $operations = null,
    ) {
    }
}