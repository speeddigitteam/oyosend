<?php

declare(strict_types=1);

namespace App\Core\AppStore\Console;

use App\Core\AppStore\AppCatalogSynchronizer;
use Illuminate\Console\Command;

final class SyncAppsCommand extends Command
{
    protected $signature = 'apps:sync {--prune : Unpublish catalogue entries whose module is no longer compiled}';

    protected $description = 'Synchronise first-party module manifests into the App Store catalogue';

    public function handle(AppCatalogSynchronizer $synchronizer): int
    {
        $result = $synchronizer->sync((bool) $this->option('prune'));

        $this->components->info(
            "Synced {$result['apps']} app(s), published {$result['versions']} new version(s), unpublished {$result['unpublished']}.",
        );

        return self::SUCCESS;
    }
}
