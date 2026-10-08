<?php

declare(strict_types=1);

use App\Core\Identity\Models\Invitation;
use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use App\Core\Tenancy\Support\TenantConnectionResolver;
use App\Core\Tenancy\TenantContext;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->context = app(TenantContext::class);
});

it('reports no tenant in the central context', function () {
    expect($this->context->check())->toBeFalse()
        ->and($this->context->tenant())->toBeNull()
        ->and($this->context->id())->toBeNull();

    expect(fn () => $this->context->require())->toThrow(MissingTenantContextException::class);
    expect(fn () => $this->context->requireId())->toThrow(MissingTenantContextException::class);
});

it('runs a callback as a tenant and restores the previous context', function () {
    $acme = $this->createTenant('acme');
    $globex = $this->createTenant('globex');

    $seen = $this->context->run($acme, function () use ($globex): array {
        $outer = $this->context->id();
        $inner = $this->context->run($globex, fn (): ?string => $this->context->id());

        return [$outer, $inner, $this->context->id()];
    });

    expect($seen)->toBe([$acme->id, $globex->id, $acme->id])
        ->and($this->context->check())->toBeFalse();
});

it('restores the previous context when the callback throws', function () {
    $acme = $this->createTenant('acme');
    $globex = $this->createTenant('globex');

    expect(fn () => $this->context->run($acme, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    // Not left inside the tenant whose work failed.
    expect($this->context->check())->toBeFalse();

    $this->context->run($globex, function () use ($acme, $globex): void {
        try {
            $this->context->run($acme, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        expect($this->context->id())->toBe($globex->id);
    });
});

it('runs a callback centrally and restores the tenant, even when the callback throws', function () {
    $acme = $this->createTenant('acme');

    $this->context->run($acme, function () use ($acme): void {
        expect($this->context->central(fn (): bool => $this->context->check()))->toBeFalse()
            ->and($this->context->id())->toBe($acme->id);

        try {
            $this->context->central(fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        expect($this->context->id())->toBe($acme->id);
    });
});

it('switches the row scope off only for the duration of withoutScope()', function () {
    $acme = $this->createTenant('acme');
    $globex = $this->createTenant('globex');

    foreach ([$acme, $globex] as $tenant) {
        $this->inTenant($tenant, fn () => Invitation::factory()->create([
            'role_id' => $this->roleOf($tenant, 'Employee')->id,
        ]));
    }

    expect($this->context->scopeBypassed())->toBeFalse();

    $counts = $this->context->withoutScope(fn (): array => [
        Invitation::query()->count(),
        // Nested bypasses do not end the outer one early.
        $this->context->withoutScope(fn (): int => Invitation::query()->count()),
        Invitation::query()->count(),
    ]);

    expect($counts)->toBe([2, 2, 2])
        ->and($this->context->scopeBypassed())->toBeFalse();

    expect(fn () => Invitation::query()->count())->toThrow(MissingTenantContextException::class);

    // The bypass ends with the callback even when it throws.
    try {
        $this->context->withoutScope(fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // expected
    }

    expect($this->context->scopeBypassed())->toBeFalse();
    expect($this->inTenant($acme, fn (): int => Invitation::query()->count()))->toBe(1);
});

it('points generated workspace URLs at the active tenant and nowhere once it ends', function () {
    $acme = $this->createTenant('acme');
    $globex = $this->createTenant('globex');

    expect($this->inTenant($acme, fn (): string => route('auth.login')))->toBe('http://acme.localhost/login')
        ->and($this->inTenant($globex, fn (): string => route('core.members.index')))->toBe('http://globex.localhost/team/members');

    // Routes of the other two areas never depend on a tenant.
    expect($this->inTenant($acme, fn (): string => route('platform.login')))->toBe('http://admin.localhost/login')
        ->and(route('central.home'))->toBe('http://localhost');

    // After the context ends the previous tenant's subdomain must not linger as a default.
    expect(fn () => route('auth.login'))->toThrow(UrlGenerationException::class);
    expect(route('auth.login', ['tenant' => 'acme']))->toBe('http://acme.localhost/login');
});

it('points the permission package at the active tenant', function () {
    $acme = $this->createTenant('acme');
    $globex = $this->createTenant('globex');
    $registrar = app(PermissionRegistrar::class);

    expect($registrar->getPermissionsTeamId())->toBeNull()
        ->and($this->inTenant($acme, fn () => $registrar->getPermissionsTeamId()))->toBe($acme->id)
        ->and($this->inTenant($globex, fn () => $registrar->getPermissionsTeamId()))->toBe($globex->id)
        ->and($registrar->getPermissionsTeamId())->toBeNull();
});

it('routes a tenant to a dedicated connection only when that connection is configured', function () {
    $acme = $this->createTenant('acme');
    $resolver = fn (): ?string => app(TenantConnectionResolver::class)->resolve();

    expect($resolver())->toBeNull()
        ->and($this->inTenant($acme, $resolver))->toBeNull();

    // A typo must never fall through to some other database.
    $acme->forceFill(['db_connection' => 'tenant_acme'])->save();
    expect($this->inTenant($acme, $resolver))->toBeNull();

    config(['database.connections.tenant_acme' => config('database.connections.mysql')]);
    expect($this->inTenant($acme, $resolver))->toBe('tenant_acme')
        ->and($this->inTenant($acme, fn (): ?string => (new Invitation)->getConnectionName()))->toBe('tenant_acme');
});
