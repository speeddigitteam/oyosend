<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Concerns;

use App\Core\Tenancy\Exceptions\CrossTenantAccessException;
use App\Core\Tenancy\Support\TenantConnectionResolver;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks a model as tenant-owned.
 *
 *  - reads are scoped to the active tenant and throw when there is none;
 *  - new records are stamped with the active tenant;
 *  - a record can never be written into, or moved to, another tenant;
 *  - the connection is resolved per tenant, so a tenant can later be moved
 *    to a dedicated database without touching the model.
 *
 * The table must have an indexed `tenant_id`, and every unique index on it
 * must lead with `tenant_id`.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait BelongsToTenant
{
    use ScopedToTenant;

    public static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $context->requireId());

                return;
            }

            if ($context->check() && $model->getAttribute('tenant_id') !== $context->id()) {
                throw CrossTenantAccessException::writing($model);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw CrossTenantAccessException::reassigning($model);
            }
        });
    }

    public function getConnectionName(): ?string
    {
        return app(TenantConnectionResolver::class)->resolve() ?? parent::getConnectionName();
    }
}
