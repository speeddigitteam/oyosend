<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Scopes;

use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant-owned model to the active tenant.
 *
 * It fails closed: with no active tenant the query throws instead of
 * silently returning every tenant's rows.
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->scopeBypassed()) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->requireId());
    }
}
