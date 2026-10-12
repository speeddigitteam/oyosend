<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Social\Enums\TargetStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The per-account variant of a post: its own optional text, its own publishing state and
 * the platform's id for the published item.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $post_id
 * @property string $account_id
 * @property string|null $body_override
 * @property TargetStatus $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $published_at
 * @property string|null $external_id
 * @property string|null $external_url
 * @property string|null $last_error
 * @property string|null $last_error_code
 * @property bool $needs_manual_check
 * @property string $idempotency_key
 * @property Carbon|null $metrics_synced_at
 * @property-read Post $post
 * @property-read SocialAccount $account
 */
class PostTarget extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_post_targets';

    /** @var list<string> */
    protected $fillable = [
        'post_id', 'account_id', 'body_override', 'status', 'attempts', 'next_attempt_at', 'claimed_at', 'published_at',
        'external_id', 'external_url', 'last_error', 'last_error_code', 'needs_manual_check', 'idempotency_key',
        'metrics_synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TargetStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'published_at' => 'datetime',
            'needs_manual_check' => 'boolean',
            'metrics_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'account_id');
    }

    /** @return HasMany<PublishAttempt, $this> */
    public function attemptLog(): HasMany
    {
        return $this->hasMany(PublishAttempt::class, 'target_id')->orderBy('created_at');
    }

    /** @return HasMany<PostMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(PostMetric::class, 'target_id');
    }
}
