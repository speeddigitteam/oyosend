<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A declarative keyword rule: data only, never code or a pattern. See docs/SOCIAL.md and ADR 0025.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property bool $is_enabled
 * @property string $match_mode
 * @property string $match_type
 * @property list<string> $keywords
 * @property string|null $account_id
 * @property string|null $post_id
 * @property string|null $campaign_id
 * @property list<array<string, mixed>> $actions
 * @property list<string>|null $reply_templates
 * @property int $hourly_cap
 * @property int $cooldown_minutes
 * @property string|null $quiet_start
 * @property string|null $quiet_end
 * @property int $priority
 * @property string|null $actor_id
 * @property string|null $created_by
 * @property int $matched_count
 * @property Carbon|null $last_matched_at
 * @property-read TenantMembership|null $actor
 */
class CommentRule extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_comment_rules';

    /** @var list<string> */
    protected $fillable = [
        'name', 'is_enabled', 'match_mode', 'match_type', 'keywords', 'account_id', 'post_id', 'campaign_id', 'actions',
        'reply_templates', 'hourly_cap', 'cooldown_minutes', 'quiet_start', 'quiet_end', 'priority', 'actor_id',
        'created_by', 'matched_count', 'last_matched_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'keywords' => 'array',
            'actions' => 'array',
            'reply_templates' => 'array',
            'hourly_cap' => 'integer',
            'cooldown_minutes' => 'integer',
            'priority' => 'integer',
            'matched_count' => 'integer',
            'last_matched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'actor_id');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
