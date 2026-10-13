<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\MetaAds\Enums\ObjectLevel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A campaign, ad set or ad as last read from Meta. Read-only data: it changes only by a sync or by an
 * applied, receipted change.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property ObjectLevel $level
 * @property string $external_id
 * @property string|null $parent_external_id
 * @property string|null $campaign_external_id
 * @property string $name
 * @property string|null $status
 * @property string|null $effective_status
 * @property string|null $objective
 * @property int|null $daily_budget
 * @property int|null $lifetime_budget
 * @property int|null $budget_remaining
 * @property string $currency
 * @property Carbon|null $start_at
 * @property Carbon|null $end_at
 * @property Carbon|null $meta_updated_at
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $missing_at
 */
class AdObject extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'meta_ads_objects';

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'level', 'external_id', 'parent_external_id', 'campaign_external_id', 'name', 'status',
        'effective_status', 'objective', 'daily_budget', 'lifetime_budget', 'budget_remaining', 'currency',
        'start_at', 'end_at', 'meta_updated_at', 'first_seen_at', 'last_seen_at', 'missing_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'level' => ObjectLevel::class,
            'daily_budget' => 'integer',
            'lifetime_budget' => 'integer',
            'budget_remaining' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'meta_updated_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AdAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'account_id');
    }

    public function hasBudget(): bool
    {
        return $this->daily_budget !== null || $this->lifetime_budget !== null;
    }
}
