<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Concerns;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-side tenant isolation: the global scope plus the two explicit ways out
 * of it. Most models want {@see BelongsToTenant}, which adds the write guards.
 *
 * @phpstan-require-extends Model
 */
trait ScopedToTenant
{
    public static function bootScopedToTenant(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * A query across every tenant. Platform code only.
     *
     * @return Builder<static>
     */
    public static function withoutTenancy(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }

    /**
     * A query for one explicitly named tenant, regardless of the active one.
     *
     * @return Builder<static>
     */
    public static function forTenant(Tenant|string $tenant): Builder
    {
        $query = static::withoutTenancy();

        return $query->where(
            $query->getModel()->qualifyColumn('tenant_id'),
            $tenant instanceof Tenant ? $tenant->getTenantKey() : $tenant,
        );
    }
}
