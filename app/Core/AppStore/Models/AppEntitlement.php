<?php

declare(strict_types=1);

namespace App\Core\AppStore\Models;

use App\Core\AppStore\Enums\EntitlementSource;
use App\Core\AppStore\Enums\EntitlementStatus;
use App\Core\AppStore\Enums\InstallationStatus;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A server-verifiable licence. The browser never decides whether it is valid.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $app_id
 * @property string $installation_id
 * @property string|null $subscription_item_id
 * @property EntitlementStatus $status
 * @property EntitlementSource $source
 * @property string $license_id
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until
 * @property Carbon|null $revoked_at
 */
class AppEntitlement extends Model
{
    use BelongsToTenant, UsesCentralConnection {
        UsesCentralConnection::getConnectionName insteadof BelongsToTenant;
    }
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'app_id', 'installation_id', 'subscription_item_id', 'status', 'source', 'license_id',
        'valid_from', 'valid_until', 'revoked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EntitlementStatus::class,
            'source' => EntitlementSource::class,
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @param Builder<$this> $query */
    public function scopeEffective(Builder $query): void
    {
        $query->where('status', EntitlementStatus::Active)
            ->where('valid_from', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->whereHas('installation', fn (Builder $query) => $query->where('status', InstallationStatus::Active));
    }

    /** @return BelongsTo<CatalogApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(CatalogApp::class, 'app_id');
    }

    /** @return BelongsTo<AppInstallation, $this> */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(AppInstallation::class, 'installation_id');
    }

    /** @return BelongsTo<SubscriptionItem, $this> */
    public function subscriptionItem(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'subscription_item_id');
    }
}
