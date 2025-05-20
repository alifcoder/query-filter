<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 4:02 PM
 */

namespace Alif\QueryFilter\Console;

use Illuminate\Console\Command;

class UninstallQueryFilterCommand extends Command
{
    protected $signature = 'query-filter:uninstall';

    protected $description = 'Remove config, migrations, and data related to QueryFilter package';

    public function handle(): void
    {
        // Delete published config
        $configPath = config_path('query-filter.php');
        if (file_exists($configPath)) {
            unlink($configPath);
            $this->info('⚠️ Removed config/query-filter.php');
        }

        // delete language files
        $langPath = resource_path('lang/vendor/query-filter');
        if (is_dir($langPath)) {
            // delete all query.php files in the directory
            $files = glob($langPath . '/*/query.php');
            foreach ($files as $file) {
                unlink($file);
                $this->info('⚠️ Removed ' . $file);
            }
            // delete the directory
            rmdir($langPath);
        }

        $this->info('✅  Permission package uninstalled successfully.');
    }
}
