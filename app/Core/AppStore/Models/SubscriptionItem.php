<?php

declare(strict_types=1);

namespace App\Core\AppStore\Models;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\SubscriptionItemStatus;
use App\Core\Billing\Models\Invoice;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A separately billed app licence, independent of the workspace's base plan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $app_id
 * @property string $installation_id
 * @property string $offer_key
 * @property AppBillingType $billing_type
 * @property SubscriptionItemStatus $status
 * @property string|null $currency
 * @property int $amount
 * @property int $quantity
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $grace_ends_at
 * @property bool $cancel_at_period_end
 * @property Carbon|null $canceled_at
 * @property Carbon|null $ended_at
 * @property-read CatalogApp $app
 * @property-read AppInstallation $installation
 */
class SubscriptionItem extends Model
{
    use BelongsToTenant, UsesCentralConnection {
        UsesCentralConnection::getConnectionName insteadof BelongsToTenant;
    }
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'app_id', 'installation_id', 'offer_key', 'billing_type', 'status', 'currency', 'amount',
        'quantity', 'trial_ends_at', 'current_period_start', 'current_period_end', 'grace_ends_at',
        'cancel_at_period_end', 'canceled_at', 'ended_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_type' => AppBillingType::class,
            'status' => SubscriptionItemStatus::class,
            'amount' => 'integer',
            'quantity' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
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

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'subscription_item_id');
    }

    public function auditLabel(): string
    {
        return $this->relationLoaded('app') ? $this->app->name : (string) $this->app()->value('name');
    }
}
