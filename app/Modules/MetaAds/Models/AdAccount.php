<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Enums\SyncState;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An ad account the authorising person can reach. `currency` is Meta's own; every amount stored for the
 * account is in that currency's minor units and is never combined with another account's currency.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $connection_id
 * @property string $external_id
 * @property string $name
 * @property string|null $business_name
 * @property string $currency
 * @property string|null $timezone_name
 * @property int|null $meta_status
 * @property int|null $meta_disable_reason
 * @property AdAccountStatus $status
 * @property int|null $daily_budget_ceiling
 * @property int|null $lifetime_budget_ceiling
 * @property Carbon|null $structure_synced_at
 * @property Carbon|null $insights_through
 * @property Carbon|null $last_synced_at
 * @property SyncState|null $sync_state
 * @property string|null $sync_error_code
 * @property string|null $sync_error
 * @property Carbon|null $throttled_until
 * @property int|null $usage_percent
 * @property Carbon|null $last_manual_sync_at
 */
class AdAccount extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Meta account_status 1 is "active"; 2 disabled, 3 unsettled, 7 risk review, 9 grace period, 100/101 closing/closed. */
    public const int META_ACTIVE = 1;

    protected $table = 'meta_ads_accounts';

    /** @var list<string> */
    protected $fillable = [
        'connection_id', 'external_id', 'name', 'business_name', 'currency', 'timezone_name', 'meta_status',
        'meta_disable_reason', 'status', 'daily_budget_ceiling', 'lifetime_budget_ceiling', 'structure_synced_at',
        'insights_through', 'last_synced_at', 'sync_state', 'sync_error_code', 'sync_error', 'throttled_until',
        'usage_percent', 'last_manual_sync_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AdAccountStatus::class,
            'sync_state' => SyncState::class,
            'meta_status' => 'integer',
            'meta_disable_reason' => 'integer',
            'daily_budget_ceiling' => 'integer',
            'lifetime_budget_ceiling' => 'integer',
            'usage_percent' => 'integer',
            'structure_synced_at' => 'datetime',
            'insights_through' => 'date',
            'last_synced_at' => 'datetime',
            'throttled_until' => 'datetime',
            'last_manual_sync_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Connection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    public function isThrottled(): bool
    {
        return $this->throttled_until !== null && $this->throttled_until->isFuture();
    }
}
