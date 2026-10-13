<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One day of Page-level figures a platform returned for one account. A column is null when the
 * platform did not return that figure; reports never turn that into zero.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property Carbon $metric_date
 * @property int|null $followers
 * @property int|null $fans
 * @property int|null $new_followers
 * @property int|null $unfollows
 * @property int|null $impressions
 * @property int|null $reach
 * @property int|null $engaged_users
 * @property int|null $post_engagements
 * @property int|null $page_views
 * @property int|null $reactions
 * @property int|null $video_views
 * @property array<string, string>|null $sources
 * @property Carbon $captured_at
 */
class PageInsight extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** @var list<string> The figure columns, in display order. */
    public const array FIGURES = [
        'followers', 'fans', 'new_followers', 'unfollows', 'impressions', 'reach',
        'engaged_users', 'post_engagements', 'page_views', 'reactions', 'video_views',
    ];

    protected $table = 'social_page_insights';

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'metric_date', 'followers', 'fans', 'new_followers', 'unfollows', 'impressions', 'reach',
        'engaged_users', 'post_engagements', 'page_views', 'reactions', 'video_views', 'sources', 'captured_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metric_date' => 'date:Y-m-d', 'sources' => 'array', 'captured_at' => 'datetime'];
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'account_id');
    }
}
