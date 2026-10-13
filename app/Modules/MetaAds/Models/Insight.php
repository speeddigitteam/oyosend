<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One day of Marketing API insights for an ad account or a campaign, in the account currency and the
 * ad account's own calendar day. Null means Meta did not report the figure.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property string $level
 * @property string $object_external_id
 * @property Carbon $metric_date
 * @property string $currency
 * @property int|null $spend
 * @property int|null $impressions
 * @property int|null $reach
 * @property int|null $clicks
 * @property int|null $leads
 * @property array<string, int>|null $actions
 * @property Carbon $synced_at
 */
class Insight extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const string LEVEL_ACCOUNT = 'account';

    public const string LEVEL_CAMPAIGN = 'campaign';

    protected $table = 'meta_ads_insights';

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'level', 'object_external_id', 'metric_date', 'currency', 'spend', 'impressions', 'reach',
        'clicks', 'leads', 'actions', 'synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'spend' => 'integer',
            'impressions' => 'integer',
            'reach' => 'integer',
            'clicks' => 'integer',
            'leads' => 'integer',
            'actions' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
