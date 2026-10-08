<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use App\Core\Tenancy\Models\Tenant;
use Closure;
use Stancl\Tenancy\Tenancy;

/**
 * The application's single entry point for "which tenant is this code running
 * for". Everything tenant-aware (the row scope, storage, jobs, authorization)
 * reads it instead of talking to the tenancy package directly.
 */
final class TenantContext
{
    private int $scopeBypassDepth = 0;

    public function __construct(private readonly Tenancy $tenancy) {}

    public function check(): bool
    {
        return $this->tenancy->initialized;
    }

    public function tenant(): ?Tenant
    {
        $tenant = $this->tenancy->tenant;

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public function id(): ?string
    {
        $key = $this->tenant()?->getTenantKey();

        return $key === null ? null : (string) $key;
    }

    /**
     * @throws MissingTenantContextException
     */
    public function require(): Tenant
    {
        return $this->tenant() ?? throw new MissingTenantContextException;
    }

    /**
     * @throws MissingTenantContextException
     */
    public function requireId(): string
    {
        return (string) $this->require()->getTenantKey();
    }

    /**
     * Run a callback as the given tenant, then restore the previous context.
     *
     * @template T
     *
     * @param  Closure(Tenant): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        return $tenant->run($callback);
    }

    /**
     * Run a callback with no tenant at all (the central context), then restore.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function central(Closure $callback): mixed
    {
        return $this->tenancy->central(fn () => $callback());
    }

    /**
     * Run a callback with the tenant row scope switched off.
     *
     * Reserved for platform (Super Admin) code and maintenance commands that
     * must look across tenants on purpose. Every call site is a deliberate
     * cross-tenant read and should be easy to find in review.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutScope(Closure $callback): mixed
    {
        $this->scopeBypassDepth++;

        try {
            return $callback();
        } finally {
            $this->scopeBypassDepth--;
        }
    }

    public function scopeBypassed(): bool
    {
        return $this->scopeBypassDepth > 0;
    }
}
