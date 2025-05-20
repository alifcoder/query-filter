<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 11:45 AM
 */

namespace Alif\QueryFilter\Interfaces;

interface Searchable
{
    /**
     * key for search
     */
    public const string SEARCH = 'search';
    /**
     * key for search type
     * e.x: and, or
     */
    public const string SEARCH_TYPE = 'search_type';

    /**
     * fields for search
     *
     * @param string $search
     *
     * @return array
     */
    public function searchFields(string $search): array;
}