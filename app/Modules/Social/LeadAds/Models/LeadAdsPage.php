<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Lead capture for one connected Facebook Page of the workspace.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property string $page_id
 * @property string $state off | subscribed | failed
 * @property list<string>|null $granted_scopes
 * @property list<string>|null $missing_scopes
 * @property Carbon|null $permissions_checked_at
 * @property Carbon|null $subscribed_at
 * @property string|null $last_error_code
 * @property string|null $last_error
 * @property Carbon|null $forms_synced_at
 * @property string|null $forms_sync_error
 * @property Carbon|null $last_backfill_at
 */
class LeadAdsPage extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const string OFF = 'off';

    public const string SUBSCRIBED = 'subscribed';

    public const string FAILED = 'failed';

    protected $table = 'social_lead_ad_pages';

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'page_id', 'state', 'granted_scopes', 'missing_scopes', 'permissions_checked_at', 'subscribed_at',
        'last_error_code', 'last_error', 'forms_synced_at', 'forms_sync_error', 'last_backfill_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'granted_scopes' => 'array',
            'missing_scopes' => 'array',
            'permissions_checked_at' => 'datetime',
            'subscribed_at' => 'datetime',
            'forms_synced_at' => 'datetime',
            'last_backfill_at' => 'datetime',
        ];
    }

    public function isSubscribed(): bool
    {
        return $this->state === self::SUBSCRIBED;
    }
}
