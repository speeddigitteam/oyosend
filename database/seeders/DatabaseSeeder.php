<?php

namespace Database\Seeders;

use App\Core\Access\PermissionSynchronizer;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(PermissionSynchronizer $permissions): void
    {
        // Reference data every environment needs.
        $permissions->sync();

        // Demo accounts and workspaces: local development only.
        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
