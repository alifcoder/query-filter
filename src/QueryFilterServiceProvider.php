<?php

namespace Alif\QueryFilter; // Laravel package integration lives at the package root.

use Alif\QueryFilter\Console\MakeQueryFilterCommand; // Generate a concrete filter for an application model.
use Illuminate\Support\ServiceProvider; // Integrate with Laravel package discovery and bootstrapping.

/** Register the concrete model-filter generator through Laravel package discovery. */
class QueryFilterServiceProvider extends ServiceProvider
{
    /** Expose the generator in Artisan without adding work to ordinary HTTP requests. */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) { // Avoid command registration during ordinary HTTP requests.
            $this->commands([ // Register commands through Laravel's normal console lifecycle.
                MakeQueryFilterCommand::class, // Create documented model-specific filter classes.
            ]);
        }
    }
}
