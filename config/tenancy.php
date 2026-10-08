<?php

declare(strict_types=1);

use App\Core\Tenancy\Bootstrappers\CachePrefixBootstrapper;
use App\Core\Tenancy\Bootstrappers\PermissionTeamBootstrapper;
use App\Core\Tenancy\Bootstrappers\UrlDefaultsBootstrapper;
use App\Core\Tenancy\Models\Domain;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\UlidGenerator;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;

/*
|--------------------------------------------------------------------------
| stancl/tenancy - single-database mode
|--------------------------------------------------------------------------
|
| We use the package for the tenant/domain models, the tenancy manager and
| its lifecycle events, and for carrying tenant context through the queue.
| Row-level isolation is our own (App\Core\Tenancy\Concerns\BelongsToTenant)
| because it has to fail closed. See docs/adr/0001-tenancy.md.
|
*/

$domain = env('APP_DOMAIN', 'localhost');

return [
    'tenant_model' => Tenant::class,
    'id_generator' => UlidGenerator::class,

    'domain_model' => Domain::class,

    /*
    | Hosts that never resolve to a tenant.
    */
    'central_domains' => array_values(array_unique([
        $domain,
        env('ADMIN_SUBDOMAIN', 'admin').'.'.$domain,
        '127.0.0.1',
    ])),

    /*
    | Executed, in order, every time a tenant context starts and reverted when
    | it ends - in HTTP requests, console commands and queued jobs alike.
    */
    'bootstrappers' => [
        CachePrefixBootstrapper::class,
        PermissionTeamBootstrapper::class,
        UrlDefaultsBootstrapper::class,
        QueueTenancyBootstrapper::class,
    ],

    'database' => [
        // Platform tables (tenants, users, plans, ...) always live here.
        'central_connection' => env('DB_CONNECTION', 'mysql'),

        // Unused in single-database mode; kept for the dedicated-database path.
        'template_tenant_connection' => null,
        'prefix' => 'tenant',
        'suffix' => '',
        'managers' => [
            'mysql' => Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager::class,
        ],
    ],

    'cache' => [
        // Stores whose keys are prefixed per tenant by CachePrefixBootstrapper.
        'stores' => array_values(array_unique(array_filter([
            env('CACHE_STORE', 'database'),
        ]))),
        'prefix_base' => 'tenant_',
    ],

    'filesystem' => [
        // Private disk that holds tenant files, under tenants/{tenant_id}/...
        'disk' => env('TENANT_FILESYSTEM_DISK', 'local'),
        'root' => 'tenants',
    ],

    'features' => [],

    // The package's asset route is not used.
    'routes' => false,

    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder',
    ],
];
