<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Access\RoleManager;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Reports\Models\SavedReport;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Building blocks for Reports & Analytics tests: a workspace entitled to the app (and to the
 * modules whose reports the test needs), members holding exact permissions with scopes, and saved
 * reports.
 *
 * @mixin TestCase
 */
trait InteractsWithReports
{
    /** A Dhaka workspace entitled to `reports` and any given modules. */
    protected function reportsTenant(string $slug = 'reports-ws', string ...$modules): Tenant
    {
        $tenant = $this->createTenant($slug, ['timezone' => 'Asia/Dhaka', 'currency' => 'BDT', 'name' => 'Reports Workspace']);
        $this->entitle($tenant, 'reports', ...$modules);

        return $tenant;
    }

    protected function reportsUrl(Tenant $tenant, string $path = ''): string
    {
        return $this->tenantUrl($tenant, '/reports'.$path);
    }

    /**
     * A member holding exactly the given permissions (scopes: permission => own|department|branch|all).
     *
     * @param  list<string>  $permissions
     * @param  array<string, string>  $scopes
     * @return array{0: User, 1: TenantMembership}
     */
    protected function reportsMember(Tenant $tenant, array $permissions, array $scopes = []): array
    {
        $owner = $this->ownerOf($tenant);
        $role = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
            $owner,
            'Role '.bin2hex(random_bytes(4)),
            null,
            $permissions,
            $scopes,
        ));
        $user = $this->addMember($tenant, $role);

        return [$user, $this->membershipOf($tenant, $user)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function savedReport(Tenant $tenant, TenantMembership $owner, array $attributes = []): SavedReport
    {
        return $this->inTenant($tenant, fn (): SavedReport => SavedReport::query()->create([
            'owner_membership_id' => $owner->id,
            'name' => 'Report '.bin2hex(random_bytes(3)),
            'provider_key' => 'reports.fixture',
            'range_mode' => 'last_days',
            'range_days' => 30,
            'visibility' => 'private',
            ...$attributes,
        ]));
    }

    /** Sets the clock to a local Dhaka date and time. */
    protected function reportsAt(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, 'Asia/Dhaka'));
    }
}
