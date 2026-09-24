<?php

namespace Alif\QueryFilter\Console; // Artisan commands provided by this package.

use Illuminate\Console\GeneratorCommand; // Reuse Laravel's naming, namespaces, and overwrite protection.

/** Generate a filter with an explicit public field allowlist. */
class MakeQueryFilterCommand extends GeneratorCommand
{
    protected $signature = 'query-filter:make {name : The filter class name}'; // Accept a concrete model-specific filter name.

    protected $description = 'Create a model query filter'; // Show the generated class's purpose in Artisan help.

    protected $type = 'Query filter'; // Label Laravel's generation messages.

    /** Select the documented model filter template. */
    protected function getStub(): string
    {
        return __DIR__ . '/../../stubs/filter.stub'; // Resolve the template relative to the installed package.
    }

    /** Place generated classes under the application's Filters namespace. */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\\Filters'; // Preserve the application's configured root namespace.
    }
}
