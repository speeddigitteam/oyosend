<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A snapshot of the figures a platform returned for one published target. A column is null
 * when the platform did not return it; analytics never turns that into zero.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $target_id
 * @property Carbon $captured_at
 * @property int|null $impressions
 * @property int|null $reach
 * @property int|null $likes
 * @property int|null $comments
 * @property int|null $shares
 * @property int|null $clicks
 */
class PostMetric extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_post_metrics';

    /** @var list<string> */
    protected $fillable = ['target_id', 'captured_at', 'impressions', 'reach', 'likes', 'comments', 'shares', 'clicks'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['captured_at' => 'datetime'];
    }
}
