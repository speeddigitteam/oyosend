<?php

declare(strict_types=1);

namespace App\Core\AppStore\Models;

use App\Core\AppStore\Enums\InstallationDeactivationReason;
use App\Core\AppStore\Enums\InstallationStatus;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Installation state only; uninstalling this record never deletes a module's business data.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $app_id
 * @property string|null $app_version_id
 * @property InstallationStatus $status
 * @property InstallationDeactivationReason|null $deactivation_reason
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $installed_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $uninstalled_at
 * @property-read CatalogApp $app
 * @property-read AppVersion|null $version
 */
class AppInstallation extends Model
{
    use BelongsToTenant, UsesCentralConnection {
        UsesCentralConnection::getConnectionName insteadof BelongsToTenant;
    }
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'app_id', 'app_version_id', 'status', 'deactivation_reason', 'settings', 'installed_at', 'activated_at',
        'deactivated_at', 'uninstalled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => InstallationStatus::class,
            'deactivation_reason' => InstallationDeactivationReason::class,
            'settings' => 'encrypted:array',
            'installed_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CatalogApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(CatalogApp::class, 'app_id');
    }

    /** @return BelongsTo<AppVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AppVersion::class, 'app_version_id');
    }

    /** @return HasMany<AppEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(AppEntitlement::class, 'installation_id');
    }

    /** @return HasMany<SubscriptionItem, $this> */
    public function subscriptionItems(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class, 'installation_id');
    }

    public function auditLabel(): string
    {
        return $this->relationLoaded('app') ? $this->app->name : (string) $this->app()->value('name');
    }
}
