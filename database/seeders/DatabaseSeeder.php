<?php

namespace Database\Seeders;

use App\Core\Access\PermissionSynchronizer;
use App\Core\AppStore\AppCatalogSynchronizer;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(PermissionSynchronizer $permissions, AppCatalogSynchronizer $apps): void
    {
        // Reference data every environment needs.
        $permissions->sync();
        $apps->sync();

        // Demo accounts and workspaces: local development only.
        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
