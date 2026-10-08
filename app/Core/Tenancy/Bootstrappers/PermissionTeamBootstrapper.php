<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Bootstrappers;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Points the permission package's "team" at the active tenant, so role
 * assignments read and written through it stay inside that tenant.
 */
final class PermissionTeamBootstrapper implements TenancyBootstrapper
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->registrar->setPermissionsTeamId($tenant->getTenantKey());
    }

    public function revert(): void
    {
        $this->registrar->setPermissionsTeamId(null);
    }
}
