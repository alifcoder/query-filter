<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 11:35 AM
 */

return [
        'default_filters'          => [
                'active'       => 'bool',
                'active_from'  => 'date',
                'active_to'    => 'date',
                'end_date'     => 'date',
                'export'       => 'bool',
                'is_active'    => 'bool',
                'limit'        => 'integer',
                'only_active'  => 'bool',
                'only_deleted' => 'bool',
                'page'         => 'int',
                'paginate'     => 'boolean',
                'per_page'     => 'int',
                'search'       => 'nullable|array',
                'search.*'     => 'string|nullable',
                'search_type'  => 'string|in:and,or',
                'sequence'     => 'string',
                'short'        => 'boolean',
                'sort'         => 'string',
                'start_date'   => 'date',
                'with_deleted' => 'bool',
                'with_total'   => 'bool',
        ],
];