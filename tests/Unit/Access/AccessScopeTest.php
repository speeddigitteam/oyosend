<?php

declare(strict_types=1);

use App\Core\Access\Enums\AccessScope;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Platform\Enums\PlatformRole;

it('orders scopes from own to all', function () {
    $ordered = [AccessScope::Own, AccessScope::Department, AccessScope::Branch, AccessScope::All];

    expect(array_map(fn (AccessScope $scope): int => $scope->rank(), $ordered))->toBe([1, 2, 3, 4]);

    foreach ($ordered as $i => $narrow) {
        foreach ($ordered as $j => $wide) {
            expect($wide->covers($narrow))->toBe($j >= $i)
                ->and($narrow->widest($wide))->toBe($j >= $i ? $wide : $narrow);
        }
    }
});

it('stores scopes under stable values', function () {
    expect(array_column(AccessScope::cases(), 'value'))->toBe(['own', 'department', 'branch', 'all'])
        ->and(AccessScope::tryFrom('everything'))->toBeNull();
});

it('lets a super admin do anything on the platform and a support admin only read', function () {
    expect(PlatformRole::SuperAdmin->allows('platform.dashboard.view'))->toBeTrue()
        ->and(PlatformRole::SuperAdmin->allows('platform.anything.at.all'))->toBeTrue()
        ->and(PlatformRole::SupportAdmin->allows('platform.dashboard.view'))->toBeTrue()
        ->and(PlatformRole::SupportAdmin->allows('platform.tenants.view'))->toBeTrue()
        ->and(PlatformRole::SupportAdmin->allows('platform.tenants.manage'))->toBeFalse()
        ->and(PlatformRole::SupportAdmin->allows('platform.tenants.view.all'))->toBeFalse()
        ->and(PlatformRole::SupportAdmin->allows(''))->toBeFalse();
});

it('only treats an active workspace as accessible', function () {
    expect(TenantStatus::Active->allowsAccess())->toBeTrue()
        ->and(TenantStatus::Pending->allowsAccess())->toBeFalse()
        ->and(TenantStatus::Suspended->allowsAccess())->toBeFalse()
        ->and(array_column(MembershipStatus::cases(), 'value'))->toBe(['active', 'suspended']);
});
