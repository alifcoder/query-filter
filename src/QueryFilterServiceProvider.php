<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 11:39 AM
 */

namespace Alif\QueryFilter;

use Alif\QueryFilter\Console\UninstallQueryFilterCommand;
use Alif\QueryFilter\Macros\DeletedMacro;
use Illuminate\Support\ServiceProvider;

class QueryFilterServiceProvider extends ServiceProvider
{

    public function register(): void
    {
        // Merge the package config with the application config
        $this->mergeConfigFrom(
                __DIR__ . '/../config/query-filter.php',
                'query-filter'
        );

        // Register the console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                                    UninstallQueryFilterCommand::class,
                            ]);
        }
    }

    public function boot(): void
    {
        // register macros
        DeletedMacro::register();

        // Publish the config file
        $this->publishes([__DIR__ . '/../resources/lang'          => resource_path('lang/vendor/query-filter'),
                          __DIR__ . '/../config/query-filter.php' => config_path('query-filter.php')],
                         'query-filter');

        // register lang
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'query-filter');
    }
}