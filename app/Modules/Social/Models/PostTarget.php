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
 * @property array<string, mixed>|null $options Publishing options chosen for this account (TikTok privacy, interactions, disclosure); null = none chosen
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
 * @property string|null $remote_id
 * @property string|null $remote_state
 * @property int $upload_offset
 * @property array<string, mixed>|null $remote_meta
 * @property Carbon|null $remote_started_at
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
        'post_id', 'account_id', 'body_override', 'options', 'status', 'attempts', 'next_attempt_at', 'claimed_at', 'published_at',
        'external_id', 'external_url', 'last_error', 'last_error_code', 'needs_manual_check', 'idempotency_key',
        'metrics_synced_at', 'remote_id', 'remote_state', 'upload_offset', 'remote_meta', 'remote_started_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TargetStatus::class,
            'options' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'published_at' => 'datetime',
            'needs_manual_check' => 'boolean',
            'metrics_synced_at' => 'datetime',
            'upload_offset' => 'integer',
            'remote_meta' => 'array',
            'remote_started_at' => 'datetime',
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
