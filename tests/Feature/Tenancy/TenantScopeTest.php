<?php

declare(strict_types=1);

use App\Core\Audit\AuditLogger;
use App\Core\Audit\Models\AuditLog;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Exceptions\CrossTenantAccessException;
use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use App\Core\Tenancy\Models\Tenant;

/*
 * The reusable helper, pointed at every tenant-owned model that exists in
 * Phase 1. A new module adds one line like these per model.
 */
it('isolates invitations between tenants', function () {
    $this->assertTenantIsolated(Invitation::class, fn (Tenant $tenant) => Invitation::factory()->create([
        'role_id' => $this->roleOf($tenant, 'Employee')->id,
    ]));
});

it('isolates memberships between tenants', function () {
    $this->assertTenantIsolated(TenantMembership::class, fn () => TenantMembership::factory()->create());
});

it('isolates audit log entries between tenants', function () {
    $this->assertTenantIsolated(AuditLog::class, fn () => app(AuditLogger::class)->record('test.happened'));
});

/*
 * The same guarantees spelled out one by one, so a regression names the rule it broke.
 */
describe('the tenant row scope', function () {
    beforeEach(function () {
        $this->acme = $this->createTenant('acme');
        $this->globex = $this->createTenant('globex');

        $this->invite = fn (Tenant $tenant, array $attributes = []): Invitation => $this->inTenant(
            $tenant,
            fn () => Invitation::factory()->create(['role_id' => $this->roleOf($tenant, 'Employee')->id, ...$attributes]),
        );
    });

    it('fails closed when no tenant is active', function () {
        ($this->invite)($this->acme);

        expect(fn () => Invitation::query()->get())->toThrow(MissingTenantContextException::class);
        expect(fn () => Invitation::query()->count())->toThrow(MissingTenantContextException::class);
        expect(fn () => Invitation::query()->first())->toThrow(MissingTenantContextException::class);
        expect(fn () => Invitation::all())->toThrow(MissingTenantContextException::class);
        expect(fn () => TenantMembership::query()->where('is_owner', true)->exists())->toThrow(MissingTenantContextException::class);
        expect(fn () => AuditLog::query()->latest('created_at')->get())->toThrow(MissingTenantContextException::class);
    });

    it('stamps new records with the active tenant', function () {
        $invitation = ($this->invite)($this->acme);

        expect($invitation->tenant_id)->toBe($this->acme->id)
            ->and($invitation->tenant->is($this->acme))->toBeTrue();
    });

    it('refuses to create a record without a tenant', function () {
        $role = $this->roleOf($this->acme, 'Employee');

        expect(fn () => Invitation::factory()->create(['role_id' => $role->id]))
            ->toThrow(MissingTenantContextException::class);
    });

    it('refuses to create a record in a tenant other than the active one', function () {
        $role = $this->roleOf($this->acme, 'Employee');

        $this->inTenant($this->globex, function () use ($role): void {
            expect(fn () => Invitation::factory()->create(['tenant_id' => $this->acme->id, 'role_id' => $role->id]))
                ->toThrow(CrossTenantAccessException::class);

            expect(fn () => TenantMembership::query()->create(['tenant_id' => $this->acme->id, 'user_id' => User::factory()->create()->id]))
                ->toThrow(CrossTenantAccessException::class);
        });

        expect(Invitation::withoutTenancy()->count())->toBe(0);
    });

    it('accepts an explicit tenant that matches the active one', function () {
        $role = $this->roleOf($this->acme, 'Employee');

        $invitation = $this->inTenant($this->acme, fn () => Invitation::factory()->create([
            'tenant_id' => $this->acme->id,
            'role_id' => $role->id,
        ]));

        expect($invitation->tenant_id)->toBe($this->acme->id);
    });

    it('refuses to move a record to another tenant', function () {
        $invitation = ($this->invite)($this->acme);

        $this->inTenant($this->acme, function () use ($invitation): void {
            expect(fn () => $invitation->update(['tenant_id' => $this->globex->id]))
                ->toThrow(CrossTenantAccessException::class);

            $invitation->tenant_id = $this->globex->id;

            expect(fn () => $invitation->save())->toThrow(CrossTenantAccessException::class);
        });

        // Not from the central context either.
        $stored = Invitation::withoutTenancy()->findOrFail($invitation->id);

        expect(fn () => $stored->forceFill(['tenant_id' => $this->globex->id])->save())
            ->toThrow(CrossTenantAccessException::class);

        expect(Invitation::withoutTenancy()->findOrFail($invitation->id)->tenant_id)->toBe($this->acme->id);
    });

    it('hides another tenant\'s records from every kind of read', function () {
        $mine = ($this->invite)($this->acme, ['email' => 'shared@example.test']);
        $theirs = ($this->invite)($this->globex, ['email' => 'shared@example.test']);

        $this->inTenant($this->acme, function () use ($mine, $theirs): void {
            expect(Invitation::query()->pluck('id')->all())->toBe([$mine->id])
                ->and(Invitation::query()->find($theirs->id))->toBeNull()
                ->and(Invitation::query()->where('email', 'shared@example.test')->count())->toBe(1)
                ->and(Invitation::query()->whereKey($theirs->id)->exists())->toBeFalse()
                ->and(Invitation::query()->where('token_hash', $theirs->token_hash)->first())->toBeNull()
                // An OR in the caller's own conditions cannot widen the scope.
                ->and(Invitation::query()->where('id', $mine->id)->orWhere('id', $theirs->id)->pluck('id')->all())->toBe([$mine->id])
                ->and(Invitation::query()->where('email', 'nobody@example.test')->orWhere('id', $theirs->id)->count())->toBe(0)
                ->and((new Invitation)->resolveRouteBinding($theirs->id))->toBeNull()
                ->and((new Invitation)->resolveRouteBinding($mine->id)?->is($mine))->toBeTrue();
        });
    });

    it('offers explicit, named ways across tenants for platform code', function () {
        $mine = ($this->invite)($this->acme);
        $theirs = ($this->invite)($this->globex);

        expect(Invitation::withoutTenancy()->pluck('id')->sort()->values()->all())
            ->toBe(collect([$mine->id, $theirs->id])->sort()->values()->all());

        expect(Invitation::forTenant($this->acme)->pluck('id')->all())->toBe([$mine->id])
            ->and(Invitation::forTenant($this->globex->id)->pluck('id')->all())->toBe([$theirs->id]);

        // forTenant() names its tenant even while another one is active.
        expect($this->inTenant($this->acme, fn () => Invitation::forTenant($this->globex)->pluck('id')->all()))
            ->toBe([$theirs->id]);
    });

    it('keeps the memberships of a tenant and of a user reachable from the central context', function () {
        $shared = $this->addMember($this->acme, 'Manager');
        $this->addMember($this->globex, 'Employee', $shared);

        expect($this->acme->memberships()->count())->toBe(2)
            ->and($this->globex->memberships()->count())->toBe(2)
            ->and($shared->memberships()->pluck('tenant_id')->sort()->values()->all())
            ->toBe(collect([$this->acme->id, $this->globex->id])->sort()->values()->all());

        // The user-keyed relation is the same from inside either workspace.
        expect($this->inTenant($this->acme, fn (): int => $shared->activeMemberships()->count()))->toBe(2);
    });
});
